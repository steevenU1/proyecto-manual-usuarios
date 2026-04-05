<?php
ob_start();
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

/* =========================================================
   CONTEXTO
========================================================= */
$ID_USUARIO    = (int)($_SESSION['id_usuario'] ?? 0);
$ROL           = trim((string)($_SESSION['rol'] ?? ''));
$ID_SUCURSAL   = (int)($_SESSION['id_sucursal'] ?? 0);
$ID_SUBDIS     = isset($_SESSION['id_subdis']) && $_SESSION['id_subdis'] !== '' ? (int)$_SESSION['id_subdis'] : null;
$NOMBRE_USR    = trim((string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario'));
$PROPIEDAD_SES = trim((string)($_SESSION['propiedad'] ?? 'LUGA'));

/* =========================================================
   PERMISOS
========================================================= */
$ROLES_PERMITIDOS = [
    'Admin', 'Administrador', 'Auditor', 'Logistica', 'GerenteZona'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para crear auditorías.');
}

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function normalizarPropiedad(?string $p): string {
    $p = strtoupper(trim((string)$p));
    if ($p === 'SUBDISTRIBUIDOR' || $p === 'SUBDIS' || $p === 'SUBDISTRIBUIDOR ') {
        return 'SUBDISTRIBUIDOR';
    }
    return 'LUGA';
}

function generarFolioAuditoria(mysqli $conn, string $propiedad = 'LUGA'): string {
    $anio = date('Y');
    $prefijo = ($propiedad === 'SUBDISTRIBUIDOR') ? 'AUD-SUB' : 'AUD-LUGA';

    $like = $prefijo . '-' . $anio . '-%';
    $stmt = $conn->prepare("
        SELECT folio
        FROM auditorias
        WHERE folio LIKE ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $ultimo = $res->fetch_assoc()['folio'] ?? '';

    $consecutivo = 1;
    if ($ultimo && preg_match('/-(\d{4})$/', $ultimo, $m)) {
        $consecutivo = ((int)$m[1]) + 1;
    }

    return sprintf('%s-%s-%04d', $prefijo, $anio, $consecutivo);
}

function fetchAllAssoc(mysqli_stmt $stmt): array {
    $stmt->execute();
    $res = $stmt->get_result();
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function hasTable(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $res = $stmt->get_result();
    return (bool)($res && $res->fetch_row());
}

function hasColumn(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    return (bool)($res && $res->fetch_row());
}

function countTraspasosPendientes(mysqli $conn, int $idSucursal): int {
    if ($idSucursal <= 0) return 0;

    if (!hasTable($conn, 'traspasos')) {
        return 0;
    }

    if (
        !hasColumn($conn, 'traspasos', 'id_sucursal_destino') ||
        !hasColumn($conn, 'traspasos', 'estatus')
    ) {
        return 0;
    }

    $sql = "
        SELECT COUNT(*) AS total
        FROM traspasos
        WHERE id_sucursal_destino = ?
          AND estatus IN ('Pendiente', 'Parcial')
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $idSucursal);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;

    return (int)($row['total'] ?? 0);
}

function getPersonalSucursal(mysqli $conn, int $idSucursal): array {
    if ($idSucursal <= 0) return [];

    $sql = "
        SELECT id, nombre, rol, id_sucursal, activo
        FROM usuarios
        WHERE id_sucursal = ?
          AND activo = 1
          AND rol IN ('Gerente', 'Encargado', 'Supervisor', 'Administrador', 'Admin', 'Ejecutivo', 'GerenteZona')
        ORDER BY
            CASE rol
                WHEN 'Gerente' THEN 1
                WHEN 'Encargado' THEN 2
                WHEN 'Supervisor' THEN 3
                WHEN 'Administrador' THEN 4
                WHEN 'Admin' THEN 5
                WHEN 'Ejecutivo' THEN 6
                WHEN 'GerenteZona' THEN 7
                ELSE 99
            END,
            nombre ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $idSucursal);
    $stmt->execute();
    $res = $stmt->get_result();

    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function getIdEncargadoDefault(array $personal): int {
    if (!$personal) return 0;

    $prioridades = ['Gerente', 'Encargado', 'Supervisor', 'Administrador', 'Admin', 'Ejecutivo', 'GerenteZona'];

    foreach ($prioridades as $rolBuscado) {
        foreach ($personal as $p) {
            if (($p['rol'] ?? '') === $rolBuscado) {
                return (int)$p['id'];
            }
        }
    }

    return (int)($personal[0]['id'] ?? 0);
}

/* =========================================================
   ENDPOINT AJAX: VALIDAR TRASPASOS PENDIENTES
========================================================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'traspasos_pendientes') {
    header('Content-Type: application/json; charset=utf-8');

    $idSucursalAjax = (int)($_GET['id_sucursal'] ?? 0);
    $totalPendientes = countTraspasosPendientes($conn, $idSucursalAjax);

    echo json_encode([
        'ok' => true,
        'id_sucursal' => $idSucursalAjax,
        'traspasos_pendientes' => $totalPendientes,
        'requiere_confirmacion' => $totalPendientes > 0,
        'mensaje' => $totalPendientes > 0
            ? "Se detectaron {$totalPendientes} traspasos pendientes de aceptación en esta sucursal."
            : ""
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* =========================================================
   ENDPOINT AJAX: PERSONAL DE SUCURSAL
========================================================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'personal_sucursal') {
    header('Content-Type: application/json; charset=utf-8');

    $idSucursalAjax = (int)($_GET['id_sucursal'] ?? 0);
    $personal = getPersonalSucursal($conn, $idSucursalAjax);
    $idDefault = getIdEncargadoDefault($personal);

    echo json_encode([
        'ok' => true,
        'id_sucursal' => $idSucursalAjax,
        'default_id' => $idDefault,
        'rows' => array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'nombre' => (string)$r['nombre'],
                'rol' => (string)$r['rol'],
                'id_sucursal' => (int)$r['id_sucursal'],
            ];
        }, $personal)
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

/* =========================================================
   CARGA DE SUCURSALES
========================================================= */
$sucursales = [];

if (in_array($ROL, ['Admin', 'Administrador', 'Logistica', 'Auditor'], true)) {
    $sqlSuc = "
        SELECT id, nombre, propiedad, id_subdis, activo
        FROM sucursales
        WHERE activo = 1
        ORDER BY nombre ASC
    ";
    $rsSuc = $conn->query($sqlSuc);
    $sucursales = $rsSuc->fetch_all(MYSQLI_ASSOC);
} else {
    $stmtSuc = $conn->prepare("
        SELECT id, nombre, propiedad, id_subdis, activo
        FROM sucursales
        WHERE id = ?
        LIMIT 1
    ");
    $stmtSuc->bind_param("i", $ID_SUCURSAL);
    $sucursales = fetchAllAssoc($stmtSuc);
}

/* =========================================================
   POST
========================================================= */
$errores = [];
$personalSeleccionado = [];
$idGerentePost = (int)($_POST['id_gerente'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_sucursal          = (int)($_POST['id_sucursal'] ?? 0);
    $id_gerente           = !empty($_POST['id_gerente']) ? (int)$_POST['id_gerente'] : null;
    $observaciones_inicio = trim((string)($_POST['observaciones_inicio'] ?? ''));
    $confirmar_traspasos  = (int)($_POST['confirmar_traspasos'] ?? 0);

    if ($id_sucursal > 0) {
        $personalSeleccionado = getPersonalSucursal($conn, $id_sucursal);
    }

    if ($id_sucursal <= 0) {
        $errores[] = 'Selecciona una sucursal.';
    }

    $sucursal = null;
    if ($id_sucursal > 0) {
        $stmt = $conn->prepare("
            SELECT id, nombre, propiedad, id_subdis, activo
            FROM sucursales
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $id_sucursal);
        $stmt->execute();
        $res = $stmt->get_result();
        $sucursal = $res->fetch_assoc();

        if (!$sucursal) {
            $errores[] = 'La sucursal seleccionada no existe.';
        } elseif ((int)$sucursal['activo'] !== 1) {
            $errores[] = 'La sucursal seleccionada no está activa.';
        }
    }

    if (!in_array($ROL, ['Admin', 'Administrador', 'Logistica', 'Auditor'], true) && $id_sucursal !== $ID_SUCURSAL) {
        $errores[] = 'No puedes crear auditorías para otra sucursal.';
    }

    if ($id_gerente !== null) {
        $stmtValUsr = $conn->prepare("
            SELECT id, id_sucursal, activo
            FROM usuarios
            WHERE id = ?
            LIMIT 1
        ");
        $stmtValUsr->bind_param("i", $id_gerente);
        $stmtValUsr->execute();
        $resValUsr = $stmtValUsr->get_result();
        $usrSel = $resValUsr->fetch_assoc();

        if (!$usrSel) {
            $errores[] = 'La persona seleccionada no existe.';
        } elseif ((int)$usrSel['activo'] !== 1) {
            $errores[] = 'La persona seleccionada está inactiva.';
        } elseif ((int)$usrSel['id_sucursal'] !== $id_sucursal) {
            $errores[] = 'La persona seleccionada no pertenece a la sucursal elegida.';
        }
    }

    if ($id_sucursal > 0) {
        $traspasosPendientes = countTraspasosPendientes($conn, $id_sucursal);

        if ($traspasosPendientes > 0 && $confirmar_traspasos !== 1) {
            $errores[] = "La sucursal tiene {$traspasosPendientes} traspasos pendientes de aceptación. Debes confirmarlo antes de iniciar la auditoría.";
        }
    }

    if (!$errores) {
        $propiedadAud = normalizarPropiedad($sucursal['propiedad'] ?? $PROPIEDAD_SES);
        $idSubdisAud  = isset($sucursal['id_subdis']) && $sucursal['id_subdis'] !== '' ? (int)$sucursal['id_subdis'] : null;

        try {
            $conn->begin_transaction();

            $folio = generarFolioAuditoria($conn, $propiedadAud);

            $stmtInsAud = $conn->prepare("
                INSERT INTO auditorias (
                    folio,
                    id_sucursal,
                    id_auditor,
                    id_gerente,
                    propiedad,
                    id_subdis,
                    fecha_inicio,
                    estatus,
                    observaciones_inicio,
                    creada_por
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, NOW(), 'En proceso', ?, ?
                )
            ");

            $stmtInsAud->bind_param(
                "siiisssi",
                $folio,
                $id_sucursal,
                $ID_USUARIO,
                $id_gerente,
                $propiedadAud,
                $idSubdisAud,
                $observaciones_inicio,
                $ID_USUARIO
            );
            $stmtInsAud->execute();

            $id_auditoria = (int)$conn->insert_id;

            $sqlUnit = "
                INSERT INTO auditorias_snapshot (
                    id_auditoria,
                    id_inventario,
                    id_producto,
                    id_sucursal,
                    propiedad,
                    id_subdis,
                    codigo_producto,
                    marca,
                    modelo,
                    color,
                    ram,
                    capacidad,
                    tipo_producto,
                    imei1,
                    imei2,
                    estatus_inventario,
                    fecha_ingreso_inventario
                )
                SELECT
                    ? AS id_auditoria,
                    i.id,
                    p.id,
                    i.id_sucursal,
                    i.propiedad,
                    i.id_subdis,
                    p.codigo_producto,
                    p.marca,
                    p.modelo,
                    p.color,
                    p.ram,
                    p.capacidad,
                    p.tipo_producto,
                    p.imei1,
                    p.imei2,
                    i.estatus,
                    i.fecha_ingreso
                FROM inventario i
                INNER JOIN productos p ON p.id = i.id_producto
                WHERE i.id_sucursal = ?
                  AND i.estatus = 'Disponible'
                  AND p.imei1 IS NOT NULL
                  AND TRIM(p.imei1) <> ''
            ";
            $stmtUnit = $conn->prepare($sqlUnit);
            $stmtUnit->bind_param("ii", $id_auditoria, $id_sucursal);
            $stmtUnit->execute();
            $totalUnitarios = $stmtUnit->affected_rows;

            $sqlCant = "
                INSERT INTO auditorias_snapshot_cantidades (
                    id_auditoria,
                    id_producto,
                    id_sucursal,
                    propiedad,
                    id_subdis,
                    codigo_producto,
                    marca,
                    modelo,
                    color,
                    ram,
                    capacidad,
                    tipo_producto,
                    cantidad_sistema
                )
                SELECT
                    ? AS id_auditoria,
                    p.id AS id_producto,
                    i.id_sucursal,
                    i.propiedad,
                    i.id_subdis,
                    p.codigo_producto,
                    p.marca,
                    p.modelo,
                    p.color,
                    p.ram,
                    p.capacidad,
                    p.tipo_producto,
                    SUM(i.cantidad) AS cantidad_sistema
                FROM inventario i
                INNER JOIN productos p ON p.id = i.id_producto
                WHERE i.id_sucursal = ?
                  AND i.estatus = 'Disponible'
                  AND (p.imei1 IS NULL OR TRIM(p.imei1) = '')
                GROUP BY
                    p.id, i.id_sucursal, i.propiedad, i.id_subdis,
                    p.codigo_producto, p.marca, p.modelo, p.color, p.ram, p.capacidad, p.tipo_producto
            ";
            $stmtCant = $conn->prepare($sqlCant);
            $stmtCant->bind_param("ii", $id_auditoria, $id_sucursal);
            $stmtCant->execute();
            $totalLineasCantidades = $stmtCant->affected_rows;

            $stmtUpd = $conn->prepare("
                UPDATE auditorias
                SET total_snapshot = ?,
                    total_lineas_accesorios = ?
                WHERE id = ?
            ");
            $stmtUpd->bind_param("iii", $totalUnitarios, $totalLineasCantidades, $id_auditoria);
            $stmtUpd->execute();

            $accion = 'Crear auditoria';
            $detalle = 'Se creó la auditoría y se generaron snapshots iniciales.';
            $json = json_encode([
                'folio' => $folio,
                'id_sucursal' => $id_sucursal,
                'id_gerente' => $id_gerente,
                'total_unitarios' => $totalUnitarios,
                'total_lineas_cantidades' => $totalLineasCantidades,
                'confirmacion_traspasos' => $confirmar_traspasos
            ], JSON_UNESCAPED_UNICODE);

            $stmtBit = $conn->prepare("
                INSERT INTO auditorias_bitacora (
                    id_auditoria, accion, detalle, datos_extra, realizado_por, fecha_evento
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmtBit->bind_param("isssi", $id_auditoria, $accion, $detalle, $json, $ID_USUARIO);
            $stmtBit->execute();

            $conn->commit();

            $destino = "auditorias_inicio.php?id=" . $id_auditoria;

            if (!headers_sent()) {
                header("Location: " . $destino);
            }

            echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">';
            echo '<meta http-equiv="refresh" content="0;url=' . h($destino) . '">';
            echo '<script>window.location.href=' . json_encode($destino) . ';</script>';
            echo '<title>Redirigiendo...</title></head><body></body></html>';
            exit();

        } catch (Throwable $e) {
            $conn->rollback();
            $errores[] = 'Error al crear la auditoría: ' . $e->getMessage();
        }
    }
}

/* =========================================================
   CARGA PREVIA PARA POSTBACK
========================================================= */
$idSucursalActual = (int)($_POST['id_sucursal'] ?? 0);
if ($idSucursalActual > 0 && !$personalSeleccionado) {
    $personalSeleccionado = getPersonalSucursal($conn, $idSucursalActual);
}

$idGerenteDefault = 0;
if ($idSucursalActual > 0) {
    $idGerenteDefault = $idGerentePost > 0 ? $idGerentePost : getIdEncargadoDefault($personalSeleccionado);
}

require_once __DIR__ . '/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nueva auditoría</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{background:#f6f8fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;}
        .wrap{max-width:980px;margin:30px auto;padding:0 15px 40px;}
        .card{background:#fff;border-radius:18px;box-shadow:0 10px 25px rgba(0,0,0,.06);padding:24px;}
        .title{margin:0 0 8px;font-size:30px;font-weight:700;}
        .subtitle{color:#6b7280;margin-bottom:24px;font-size:15px;}
        .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:18px;}
        .field{display:flex;flex-direction:column;gap:7px;}
        .field.full{grid-column:1 / -1;}
        label{font-weight:600;font-size:14px;}
        input,select,textarea{border:1px solid #d1d5db;border-radius:12px;padding:12px 14px;font-size:14px;outline:none;transition:.18s ease;background:#fff;}
        input:focus,select:focus,textarea:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12);}
        textarea{min-height:120px;resize:vertical;}
        .actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px;}
        .btn{border:none;border-radius:12px;padding:12px 18px;cursor:pointer;font-weight:700;font-size:14px;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;}
        .btn-primary{background:#111827;color:#fff;}
        .btn-secondary{background:#e5e7eb;color:#111827;}
        .btn-warning{background:#d97706;color:#fff;}
        .alert{border-radius:12px;padding:14px 16px;margin-bottom:18px;}
        .alert-danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
        .status-box{display:none;margin-bottom:18px;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:12px;padding:14px 16px;}
        .aud-modal-backdrop{position:fixed;inset:0;background:rgba(17,24,39,.55);display:none;align-items:center;justify-content:center;padding:20px;z-index:99999;}
        .aud-modal-backdrop.show{display:flex;}
        .aud-modal-box{width:100%;max-width:620px;background:#fff;border-radius:20px;box-shadow:0 20px 50px rgba(0,0,0,.25);overflow:hidden;position:relative;z-index:100000;}
        .aud-modal-head{padding:18px 22px;background:#fff7ed;border-bottom:1px solid #fed7aa;}
        .aud-modal-head h3{margin:0;font-size:24px;color:#9a3412;}
        .aud-modal-body{padding:22px;}
        .aud-modal-body p{margin:0 0 14px;line-height:1.55;}
        .aud-modal-note{background:#fff7ed;border:1px solid #fed7aa;border-radius:14px;padding:14px;color:#9a3412;font-size:14px;}
        .aud-modal-actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:18px;}
        .chkline{display:flex;align-items:flex-start;gap:10px;margin-top:16px;padding:12px;border:1px solid #e5e7eb;border-radius:14px;background:#f9fafb;}
        .chkline input[type="checkbox"]{margin-top:2px;transform:scale(1.1);}
        @media (max-width:768px){.grid{grid-template-columns:1fr;}}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1 class="title">Nueva auditoría</h1>
        <div class="subtitle">Registra la información inicial para comenzar la auditoría de inventario.</div>

        <?php if ($errores): ?>
            <div class="alert alert-danger">
                <ul style="margin:0 0 0 18px;">
                    <?php foreach ($errores as $err): ?>
                        <li><?= h($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div id="traspasosStatus" class="status-box"></div>

        <form method="POST" action="" id="formAuditoria">
            <input type="hidden" name="confirmar_traspasos" id="confirmar_traspasos" value="<?= (int)($_POST['confirmar_traspasos'] ?? 0) ?>">

            <div class="grid">
                <div class="field">
                    <label for="id_sucursal">Sucursal *</label>
                    <select name="id_sucursal" id="id_sucursal" required>
                        <option value="">Selecciona</option>
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= ((int)($_POST['id_sucursal'] ?? 0) === (int)$s['id']) ? 'selected' : '' ?>>
                                <?= h($s['nombre']) ?><?php if (!empty($s['propiedad'])): ?> | <?= h($s['propiedad']) ?><?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Auditor responsable</label>
                    <input type="text" value="<?= h($NOMBRE_USR . ' (' . $ROL . ')') ?>" disabled>
                </div>

                <div class="field">
                    <label for="id_gerente">Responsable presente</label>
                    <select name="id_gerente" id="id_gerente">
                        <option value="">Selecciona</option>
                        <?php foreach ($personalSeleccionado as $g): ?>
                            <option value="<?= (int)$g['id'] ?>" <?= ($idGerenteDefault === (int)$g['id']) ? 'selected' : '' ?>>
                                <?= h($g['nombre']) ?> | <?= h($g['rol']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Fecha y hora de inicio</label>
                    <input type="text" value="<?= h(date('d/m/Y H:i')) ?>" disabled>
                </div>

                <div class="field full">
                    <label for="observaciones_inicio">Observaciones</label>
                    <textarea name="observaciones_inicio" id="observaciones_inicio" placeholder="Escribe aquí las observaciones iniciales..."><?= h($_POST['observaciones_inicio'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary">Iniciar auditoría</button>
                <a href="auditorias_historial.php" class="btn btn-secondary">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<div class="aud-modal-backdrop" id="modalTraspasos" aria-hidden="true">
    <div class="aud-modal-box" role="dialog" aria-modal="true" aria-labelledby="audModalTitle">
        <div class="aud-modal-head">
            <h3 id="audModalTitle">Validación requerida</h3>
        </div>
        <div class="aud-modal-body">
            <p id="modalMensaje">Se detectaron traspasos pendientes de aceptación en esta sucursal.</p>
            <div class="aud-modal-note">Antes de iniciar la auditoría, confirma con la sucursal si estos movimientos ya fueron recibidos físicamente.</div>

            <label class="chkline">
                <input type="checkbox" id="chkValidadoTraspasos">
                <span>Confirmo que esta validación ya fue realizada con la sucursal.</span>
            </label>

            <div class="aud-modal-actions">
                <button type="button" class="btn btn-secondary" id="btnCerrarModal">Cerrar</button>
                <button type="button" class="btn btn-warning" id="btnConfirmarModal" disabled>Confirmar</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const sucursalSelect = document.getElementById('id_sucursal');
    const encargadoSelect = document.getElementById('id_gerente');
    const statusBox = document.getElementById('traspasosStatus');
    const modal = document.getElementById('modalTraspasos');
    const modalMensaje = document.getElementById('modalMensaje');
    const chkValidado = document.getElementById('chkValidadoTraspasos');
    const btnConfirmar = document.getElementById('btnConfirmarModal');
    const btnCerrar = document.getElementById('btnCerrarModal');
    const inputConfirmar = document.getElementById('confirmar_traspasos');
    const form = document.getElementById('formAuditoria');

    let pendientesActuales = 0;

    function abrirModal() {
        if (!modal) return;
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function cerrarModal() {
        if (!modal) return;
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function resetConfirmacion() {
        if (chkValidado) chkValidado.checked = false;
        if (btnConfirmar) btnConfirmar.disabled = true;
        if (inputConfirmar) inputConfirmar.value = '0';
    }

    function mostrarStatus(msg) {
        if (!statusBox) return;
        if (!msg) {
            ocultarStatus();
            return;
        }
        statusBox.style.display = 'block';
        statusBox.textContent = msg;
    }

    function ocultarStatus() {
        if (!statusBox) return;
        statusBox.style.display = 'none';
        statusBox.textContent = '';
    }

    function limpiarEncargados(texto = 'Selecciona') {
        if (!encargadoSelect) return;
        encargadoSelect.innerHTML = '';
        const opt = document.createElement('option');
        opt.value = '';
        opt.textContent = texto;
        encargadoSelect.appendChild(opt);
    }

    function cargarEncargados(rows, defaultId) {
        if (!encargadoSelect) return;
        encargadoSelect.innerHTML = '';

        if (!rows || !rows.length) {
            limpiarEncargados('Sin personal disponible');
            return;
        }

        const firstOpt = document.createElement('option');
        firstOpt.value = '';
        firstOpt.textContent = 'Selecciona';
        encargadoSelect.appendChild(firstOpt);

        rows.forEach(row => {
            const opt = document.createElement('option');
            opt.value = row.id;
            opt.textContent = `${row.nombre} | ${row.rol}`;
            if (parseInt(defaultId, 10) === parseInt(row.id, 10)) {
                opt.selected = true;
            }
            encargadoSelect.appendChild(opt);
        });
    }

    function resetSucursalPorNoConfirmacion() {
        if (sucursalSelect) sucursalSelect.value = '';
        limpiarEncargados('Selecciona');
        pendientesActuales = 0;
        if (inputConfirmar) inputConfirmar.value = '0';
        ocultarStatus();
        cerrarModal();
    }

    async function validarTraspasosSucursal() {
        const idSucursal = parseInt((sucursalSelect && sucursalSelect.value) || '0', 10);
        resetConfirmacion();
        pendientesActuales = 0;

        if (!idSucursal) {
            ocultarStatus();
            return;
        }

        try {
            const resp = await fetch('auditorias_nueva.php?ajax=traspasos_pendientes&id_sucursal=' + encodeURIComponent(idSucursal), {
                credentials: 'same-origin'
            });

            const text = await resp.text();
            let data = null;

            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Respuesta AJAX no JSON:', text);
                throw new Error('Respuesta no válida');
            }

            if (!data || !data.ok) {
                mostrarStatus('No fue posible validar los traspasos de la sucursal.');
                return;
            }

            pendientesActuales = parseInt(data.traspasos_pendientes || 0, 10);

            if (pendientesActuales > 0) {
                mostrarStatus(data.mensaje);
                if (modalMensaje) modalMensaje.textContent = data.mensaje;
                abrirModal();
            } else {
                ocultarStatus();
                if (inputConfirmar) inputConfirmar.value = '1';
                cerrarModal();
            }

        } catch (e) {
            console.error(e);
            mostrarStatus('No fue posible validar los traspasos de la sucursal.');
            cerrarModal();
        }
    }

    async function cargarPersonalSucursal() {
        const idSucursal = parseInt((sucursalSelect && sucursalSelect.value) || '0', 10);

        if (!idSucursal) {
            limpiarEncargados('Selecciona');
            return;
        }

        try {
            const resp = await fetch('auditorias_nueva.php?ajax=personal_sucursal&id_sucursal=' + encodeURIComponent(idSucursal), {
                credentials: 'same-origin'
            });

            const text = await resp.text();
            let data = null;

            try {
                data = JSON.parse(text);
            } catch (e) {
                console.error('Respuesta personal_sucursal no JSON:', text);
                throw new Error('Respuesta no válida');
            }

            if (!data || !data.ok) {
                limpiarEncargados('Selecciona');
                return;
            }

            cargarEncargados(data.rows || [], data.default_id || 0);

        } catch (e) {
            console.error(e);
            limpiarEncargados('Selecciona');
        }
    }

    async function onSucursalChange() {
        await cargarPersonalSucursal();
        await validarTraspasosSucursal();
    }

    if (sucursalSelect) {
        sucursalSelect.addEventListener('change', onSucursalChange);
    }

    if (chkValidado) {
        chkValidado.addEventListener('change', function () {
            if (btnConfirmar) btnConfirmar.disabled = !this.checked;
        });
    }

    if (btnConfirmar) {
        btnConfirmar.addEventListener('click', function () {
            if (inputConfirmar) inputConfirmar.value = '1';
            ocultarStatus();
            cerrarModal();
        });
    }

    if (btnCerrar) {
        btnCerrar.addEventListener('click', function () {
            if (pendientesActuales > 0 && (!inputConfirmar || inputConfirmar.value !== '1')) {
                resetSucursalPorNoConfirmacion();
                return;
            }
            cerrarModal();
        });
    }

    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                if (pendientesActuales > 0 && (!inputConfirmar || inputConfirmar.value !== '1')) {
                    resetSucursalPorNoConfirmacion();
                    return;
                }
                cerrarModal();
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && modal.classList.contains('show')) {
            if (pendientesActuales > 0 && (!inputConfirmar || inputConfirmar.value !== '1')) {
                resetSucursalPorNoConfirmacion();
                return;
            }
            cerrarModal();
        }
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            const idSucursal = parseInt((sucursalSelect && sucursalSelect.value) || '0', 10);
            if (!idSucursal) return;

            if (pendientesActuales > 0 && (!inputConfirmar || inputConfirmar.value !== '1')) {
                e.preventDefault();
                abrirModal();
            }
        });
    }

    window.addEventListener('DOMContentLoaded', async function () {
        cerrarModal();
        if (sucursalSelect && sucursalSelect.value) {
            await onSucursalChange();
        }
    });
})();
</script>
</body>
</html>