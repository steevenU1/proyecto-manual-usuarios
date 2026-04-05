<?php
// retiro_sims_confirmar.php
session_start();

if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fail_and_back(string $msg): void
{
    $_SESSION['flash_err'] = $msg;
    header("Location: retiro_sims.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail_and_back("Método no permitido.");
}

$csrf = trim((string)($_POST['csrf'] ?? ''));
if ($csrf === '' || $csrf !== ($_SESSION['retiro_token'] ?? '')) {
    fail_and_back("Token inválido. Recarga la página e intenta nuevamente.");
}

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
$carrito = $_SESSION['carrito_retiro'] ?? [];

if (!is_array($carrito) || !count($carrito)) {
    fail_and_back("No hay SIMs en el carrito para retirar.");
}

$tipoRetiro = trim((string)($_POST['tipo_retiro'] ?? ''));
$motivo = trim((string)($_POST['motivo'] ?? ''));
$observaciones = trim((string)($_POST['observaciones'] ?? ''));

$tiposValidos = [
    'Merma',
    'Venta a distribuidor',
    'Vencimiento',
    'Daño',
    'Baja administrativa',
    'Otro'
];

if (!in_array($tipoRetiro, $tiposValidos, true)) {
    fail_and_back("Selecciona un tipo de retiro válido.");
}

if ($motivo === '' || mb_strlen($motivo) < 5) {
    fail_and_back("Debes capturar un motivo válido de al menos 5 caracteres.");
}

// Sanitizar IDs
$ids = array_values(array_unique(array_map('intval', $carrito)));
$ids = array_filter($ids, fn($v) => $v > 0);

if (!count($ids)) {
    fail_and_back("El carrito no contiene registros válidos.");
}

// Traer SIMs aún disponibles
$idList = implode(',', $ids);

$sql = "SELECT id, iccid, dn, operador, caja_id, lote, tipo_plan, id_sucursal, estatus
        FROM inventario_sims
        WHERE id IN ($idList)
        FOR UPDATE";

try {
    $conn->begin_transaction();

    $res = $conn->query($sql);
    $sims = $res->fetch_all(MYSQLI_ASSOC);

    if (!count($sims)) {
        throw new RuntimeException("No se encontraron SIMs válidas para procesar.");
    }

    // Verificar que todas existan y sigan disponibles
    $map = [];
    foreach ($sims as $sim) {
        $map[(int)$sim['id']] = $sim;
    }

    $faltantes = [];
    $noDisponibles = [];

    foreach ($ids as $idSim) {
        if (!isset($map[$idSim])) {
            $faltantes[] = $idSim;
            continue;
        }
        if ((string)$map[$idSim]['estatus'] !== 'Disponible') {
            $noDisponibles[] = $map[$idSim]['iccid'] ?: ('ID ' . $idSim);
        }
    }

    if (count($faltantes)) {
        throw new RuntimeException("Algunas SIMs ya no existen o no pudieron recuperarse.");
    }

    if (count($noDisponibles)) {
        throw new RuntimeException("Algunas SIMs ya no están disponibles: " . implode(', ', array_slice($noDisponibles, 0, 10)));
    }

    // Determinar modalidad
    $cajas = [];
    $lotes = [];

    foreach ($sims as $sim) {
        if (!empty($sim['caja_id'])) {
            $cajas[(string)$sim['caja_id']] = true;
        }
        if (!empty($sim['lote'])) {
            $lotes[(string)$sim['lote']] = true;
        }
    }

    $totalSims = count($sims);

    if ($totalSims === 1) {
        $modalidad = 'Unidad';
    } elseif (count($cajas) === 1 && $totalSims > 1) {
        $modalidad = 'Caja';
    } elseif (count($lotes) === 1 && $totalSims > 1) {
        $modalidad = 'Lote';
    } else {
        $modalidad = 'Mixto';
    }

    // Sucursal del retiro:
    // Si todas son de la misma sucursal, usamos esa.
    // Si vienen de varias, usamos 0 o la primera. Por ahora usamos la primera y en observaciones queda trazado.
    $sucursales = [];
    foreach ($sims as $sim) {
        $sucursales[(int)$sim['id_sucursal']] = true;
    }
    $idSucursal = (int)array_key_first($sucursales);

    // Generar folio
    $prefijo = 'RET-SIM-' . date('Ymd') . '-';

    $stmtFolio = $conn->prepare("
        SELECT folio
        FROM retiros_sims
        WHERE folio LIKE CONCAT(?, '%')
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtFolio->bind_param('s', $prefijo);
    $stmtFolio->execute();
    $ultimo = $stmtFolio->get_result()->fetch_assoc();

    $consecutivo = 1;
    if ($ultimo && !empty($ultimo['folio'])) {
        if (preg_match('/(\d+)$/', (string)$ultimo['folio'], $m)) {
            $consecutivo = ((int)$m[1]) + 1;
        }
    }
    $folio = $prefijo . str_pad((string)$consecutivo, 3, '0', STR_PAD_LEFT);

    // Si hay varias sucursales, lo anotamos también
    if (count($sucursales) > 1) {
        $extra = " [Retiro multisuccursal: " . implode(',', array_keys($sucursales)) . "]";
        $observaciones = trim($observaciones . $extra);
    }

    // Insert cabecera
    $stmtRet = $conn->prepare("
        INSERT INTO retiros_sims
        (folio, tipo_retiro, modalidad, motivo, observaciones, id_sucursal, id_usuario, total_sims)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtRet->bind_param(
        'sssssiii',
        $folio,
        $tipoRetiro,
        $modalidad,
        $motivo,
        $observaciones,
        $idSucursal,
        $idUsuario,
        $totalSims
    );
    $stmtRet->execute();
    $idRetiro = (int)$stmtRet->insert_id;

    // Insert detalle
    $stmtDet = $conn->prepare("
        INSERT INTO retiros_sims_detalle
        (id_retiro, id_sim, iccid, dn, operador, caja_id, lote, tipo_plan, id_sucursal_origen, comentario_item)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($sims as $sim) {
        $idSim = (int)$sim['id'];
        $iccid = (string)$sim['iccid'];
        $dn = $sim['dn'] !== null ? (string)$sim['dn'] : null;
        $operador = (string)$sim['operador'];
        $cajaId = $sim['caja_id'] !== null ? (string)$sim['caja_id'] : null;
        $lote = $sim['lote'] !== null ? (string)$sim['lote'] : null;
        $tipoPlan = $sim['tipo_plan'] !== null ? (string)$sim['tipo_plan'] : null;
        $idSucursalOrigen = (int)$sim['id_sucursal'];
        $comentarioItem = $motivo;

        $stmtDet->bind_param(
            'iissssssis',
            $idRetiro,
            $idSim,
            $iccid,
            $dn,
            $operador,
            $cajaId,
            $lote,
            $tipoPlan,
            $idSucursalOrigen,
            $comentarioItem
        );
        $stmtDet->execute();
    }

    // Actualizar inventario_sims
    $stmtUpd = $conn->prepare("
        UPDATE inventario_sims
        SET estatus = 'Retirado',
            id_retiro_sim = ?,
            motivo_retiro = ?,
            fecha_retiro = NOW()
        WHERE id = ?
          AND estatus = 'Disponible'
    ");

    foreach ($sims as $sim) {
        $idSim = (int)$sim['id'];
        $stmtUpd->bind_param('isi', $idRetiro, $motivo, $idSim);
        $stmtUpd->execute();

        if ($stmtUpd->affected_rows <= 0) {
            throw new RuntimeException("No se pudo actualizar la SIM ICCID " . $sim['iccid'] . ". Puede que ya no esté disponible.");
        }
    }

    $conn->commit();

    $_SESSION['carrito_retiro'] = [];
    $_SESSION['flash_ok'] = "Retiro registrado correctamente. Folio: {$folio}. SIMs retiradas: {$totalSims}.";

    header("Location: retiro_sims.php");
    exit();
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['flash_err'] = "No se pudo completar el retiro: " . $e->getMessage();
    header("Location: retiro_sims.php");
    exit();
}