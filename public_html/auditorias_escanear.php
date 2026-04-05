<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

/* =========================================================
   CONTEXTO
========================================================= */
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ROL         = trim((string)($_SESSION['rol'] ?? ''));
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

/* =========================================================
   PERMISOS
========================================================= */
$ROLES_PERMITIDOS = [
    'Admin', 'Administrador', 'Auditor', 'Logistica', 'GerenteZona',
    'Gerente', 'Supervisor'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para escanear auditorías.');
}

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmtFecha(?string $f): string {
    if (!$f || $f === '0000-00-00' || $f === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($f);
    return $ts ? date('d/m/Y H:i:s', $ts) : $f;
}

function limpiarIdentificador(string $valor): string {
    $valor = trim($valor);
    $valor = preg_replace('/\s+/', '', $valor);
    return $valor;
}

/* =========================================================
   ID AUDITORIA
========================================================= */
$id_auditoria = isset($_GET['id']) ? (int)($_GET['id']) : (int)($_POST['id_auditoria'] ?? 0);
if ($id_auditoria <= 0) {
    exit('Auditoría no válida.');
}

/* =========================================================
   CARGAR AUDITORIA
========================================================= */
$stmtAud = $conn->prepare("
    SELECT 
        a.*,
        s.nombre AS sucursal_nombre,
        s.zona AS sucursal_zona,
        s.tipo_sucursal,
        u1.nombre AS auditor_nombre,
        u2.nombre AS gerente_nombre
    FROM auditorias a
    INNER JOIN sucursales s ON s.id = a.id_sucursal
    INNER JOIN usuarios u1 ON u1.id = a.id_auditor
    LEFT JOIN usuarios u2 ON u2.id = a.id_gerente
    WHERE a.id = ?
    LIMIT 1
");
$stmtAud->bind_param("i", $id_auditoria);
$stmtAud->execute();
$auditoria = $stmtAud->get_result()->fetch_assoc();

if (!$auditoria) {
    exit('La auditoría no existe.');
}

/* Restricción básica */
if (in_array($ROL, ['Gerente', 'Supervisor'], true) && (int)$auditoria['id_sucursal'] !== $ID_SUCURSAL) {
    http_response_code(403);
    exit('No puedes escanear una auditoría de otra sucursal.');
}

$errores = [];
$ultimoResultado = null;
$estaCerrada = (($auditoria['estatus'] ?? '') === 'Cerrada');

/* =========================================================
   PROCESAR ESCANEO
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_escaneo'])) {

    if ($estaCerrada) {
        $errores[] = 'La auditoría ya está cerrada. No es posible registrar más escaneos.';
    } else {
        $imei = limpiarIdentificador((string)($_POST['imei'] ?? ''));

        if ($imei === '') {
            $errores[] = 'Debes capturar un IMEI.';
        } else {
            try {
                $conn->begin_transaction();

                /* ============================================
                   1) VALIDAR DUPLICADO EXACTO EN ESCANEOS
                ============================================ */
                $stmtDup = $conn->prepare("
                    SELECT e.*, p.marca, p.modelo, p.color, p.capacidad
                    FROM auditorias_escaneos e
                    LEFT JOIN productos p ON p.id = e.id_producto
                    WHERE e.id_auditoria = ?
                      AND e.imei_escaneado = ?
                    LIMIT 1
                ");
                $stmtDup->bind_param("is", $id_auditoria, $imei);
                $stmtDup->execute();
                $dup = $stmtDup->get_result()->fetch_assoc();

                if ($dup) {
                    $stmtInsDup = $conn->prepare("
                        INSERT INTO auditorias_incidencias (
                            id_auditoria,
                            id_escaneo,
                            id_producto,
                            id_inventario,
                            imei_escaneado,
                            tipo_incidencia,
                            detalle,
                            referencia_tabla,
                            referencia_id,
                            observaciones,
                            creado_por
                        ) VALUES (
                            ?, ?, ?, ?, ?, 'Duplicado en auditoria', ?, 'auditorias_escaneos', ?, ?, ?
                        )
                    ");

                    $detalleDup = 'El identificador ya había sido escaneado previamente en esta auditoría.';
                    $obsDup = 'Escaneo duplicado detectado.';
                    $idEscaneoDup = (int)$dup['id'];
                    $idProductoDup = !empty($dup['id_producto']) ? (int)$dup['id_producto'] : null;
                    $idInventarioDup = !empty($dup['id_inventario']) ? (int)$dup['id_inventario'] : null;
                    $refIdDup = (int)$dup['id'];

                    $stmtInsDup->bind_param(
                        "iiiissisi",
                        $id_auditoria,
                        $idEscaneoDup,
                        $idProductoDup,
                        $idInventarioDup,
                        $imei,
                        $detalleDup,
                        $refIdDup,
                        $obsDup,
                        $ID_USUARIO
                    );
                    $stmtInsDup->execute();

                    $stmtBit = $conn->prepare("
                        INSERT INTO auditorias_bitacora (
                            id_auditoria, accion, detalle, datos_extra, realizado_por, fecha_evento
                        ) VALUES (?, 'Escaneo duplicado', ?, ?, ?, NOW())
                    ");
                    $detalleBit = 'Se detectó un IMEI duplicado durante el escaneo.';
                    $jsonBit = json_encode([
                        'imei' => $imei,
                        'id_escaneo_existente' => $idEscaneoDup
                    ], JSON_UNESCAPED_UNICODE);
                    $stmtBit->bind_param("issi", $id_auditoria, $detalleBit, $jsonBit, $ID_USUARIO);
                    $stmtBit->execute();

                    $conn->commit();

                    $ultimoResultado = [
                        'tipo' => 'duplicado',
                        'titulo' => 'IMEI duplicado',
                        'mensaje' => 'Este IMEI ya había sido registrado en la auditoría.',
                        'imei' => $imei,
                        'marca' => $dup['marca'] ?? '',
                        'modelo' => $dup['modelo'] ?? '',
                        'color' => $dup['color'] ?? '',
                        'capacidad' => $dup['capacidad'] ?? '',
                    ];
                } else {
                    /* ============================================
                       2) BUSCAR EN SNAPSHOT DE LA AUDITORIA
                    ============================================ */
                    $stmtSnap = $conn->prepare("
                        SELECT s.*
                        FROM auditorias_snapshot s
                        WHERE s.id_auditoria = ?
                          AND (s.imei1 = ? OR s.imei2 = ?)
                        LIMIT 1
                    ");
                    $stmtSnap->bind_param("iss", $id_auditoria, $imei, $imei);
                    $stmtSnap->execute();
                    $snap = $stmtSnap->get_result()->fetch_assoc();

                    if ($snap) {
                        $id_snapshot   = (int)$snap['id'];
                        $id_inventario = (int)$snap['id_inventario'];
                        $id_producto   = (int)$snap['id_producto'];

                        $stmtYaProd = $conn->prepare("
                            SELECT e.*
                            FROM auditorias_escaneos e
                            WHERE e.id_auditoria = ?
                              AND e.id_producto = ?
                            LIMIT 1
                        ");
                        $stmtYaProd->bind_param("ii", $id_auditoria, $id_producto);
                        $stmtYaProd->execute();
                        $yaProd = $stmtYaProd->get_result()->fetch_assoc();

                        if ($yaProd) {
                            $stmtInc = $conn->prepare("
                                INSERT INTO auditorias_incidencias (
                                    id_auditoria,
                                    id_escaneo,
                                    id_producto,
                                    id_inventario,
                                    imei_escaneado,
                                    tipo_incidencia,
                                    detalle,
                                    referencia_tabla,
                                    referencia_id,
                                    observaciones,
                                    creado_por
                                ) VALUES (
                                    ?, ?, ?, ?, ?, 'Duplicado en auditoria', ?, 'auditorias_escaneos', ?, ?, ?
                                )
                            ");
                            $detalle = 'El producto ya había sido encontrado previamente con otro IMEI/identificador.';
                            $obs = 'Intento duplicado sobre el mismo producto.';
                            $idEsc = (int)$yaProd['id'];
                            $refId = (int)$yaProd['id'];
                            $stmtInc->bind_param(
                                "iiiissisi",
                                $id_auditoria,
                                $idEsc,
                                $id_producto,
                                $id_inventario,
                                $imei,
                                $detalle,
                                $refId,
                                $obs,
                                $ID_USUARIO
                            );
                            $stmtInc->execute();

                            $stmtBit = $conn->prepare("
                                INSERT INTO auditorias_bitacora (
                                    id_auditoria, accion, detalle, datos_extra, realizado_por, fecha_evento
                                ) VALUES (?, 'Producto duplicado', ?, ?, ?, NOW())
                            ");
                            $detalleBit = 'Se intentó escanear nuevamente un producto ya encontrado.';
                            $jsonBit = json_encode([
                                'imei' => $imei,
                                'id_producto' => $id_producto,
                                'id_escaneo_existente' => $idEsc
                            ], JSON_UNESCAPED_UNICODE);
                            $stmtBit->bind_param("issi", $id_auditoria, $detalleBit, $jsonBit, $ID_USUARIO);
                            $stmtBit->execute();

                            $conn->commit();

                            $ultimoResultado = [
                                'tipo' => 'duplicado',
                                'titulo' => 'Producto ya registrado',
                                'mensaje' => 'Este producto ya había sido encontrado en la auditoría.',
                                'imei' => $imei,
                                'marca' => $snap['marca'] ?? '',
                                'modelo' => $snap['modelo'] ?? '',
                                'color' => $snap['color'] ?? '',
                                'capacidad' => $snap['capacidad'] ?? '',
                            ];
                        } else {
                            $tipoImei = 'desconocido';
                            if ((string)$snap['imei1'] === $imei) {
                                $tipoImei = 'imei1';
                            } elseif (!empty($snap['imei2']) && (string)$snap['imei2'] === $imei) {
                                $tipoImei = 'imei2';
                            }

                            $stmtIns = $conn->prepare("
                                INSERT INTO auditorias_escaneos (
                                    id_auditoria,
                                    id_snapshot,
                                    id_inventario,
                                    id_producto,
                                    imei_escaneado,
                                    tipo_imei,
                                    resultado,
                                    origen_encontrado,
                                    referencia_tabla,
                                    referencia_id,
                                    descripcion_resultado,
                                    observaciones,
                                    escaneado_por,
                                    fecha_escaneo
                                ) VALUES (
                                    ?, ?, ?, ?, ?, ?, 'OK', 'Inventario sucursal',
                                    'auditorias_snapshot', ?, ?, ?, ?, NOW()
                                )
                            ");

                            $descripcion = 'Equipo localizado correctamente en inventario de sucursal.';
                            $observaciones = null;
                            $stmtIns->bind_param(
                                "iiiississi",
                                $id_auditoria,
                                $id_snapshot,
                                $id_inventario,
                                $id_producto,
                                $imei,
                                $tipoImei,
                                $id_snapshot,
                                $descripcion,
                                $observaciones,
                                $ID_USUARIO
                            );
                            $stmtIns->execute();
                            $id_escaneo = (int)$conn->insert_id;

                            $stmtUpdSnap = $conn->prepare("
                                UPDATE auditorias_snapshot
                                SET escaneado = 1,
                                    fecha_escaneado = NOW()
                                WHERE id = ?
                            ");
                            $stmtUpdSnap->bind_param("i", $id_snapshot);
                            $stmtUpdSnap->execute();

                            $stmtTot = $conn->prepare("
                                SELECT
                                    COUNT(*) AS total_esperados,
                                    SUM(CASE WHEN escaneado = 1 THEN 1 ELSE 0 END) AS total_ok,
                                    SUM(CASE WHEN escaneado = 0 THEN 1 ELSE 0 END) AS total_faltantes
                                FROM auditorias_snapshot
                                WHERE id_auditoria = ?
                            ");
                            $stmtTot->bind_param("i", $id_auditoria);
                            $stmtTot->execute();
                            $tot = $stmtTot->get_result()->fetch_assoc();

                            $totalEsperados = (int)($tot['total_esperados'] ?? 0);
                            $totalOk = (int)($tot['total_ok'] ?? 0);
                            $totalFaltantes = (int)($tot['total_faltantes'] ?? 0);

                            $stmtCountEsc = $conn->prepare("
                                SELECT COUNT(*) AS total
                                FROM auditorias_escaneos
                                WHERE id_auditoria = ?
                            ");
                            $stmtCountEsc->bind_param("i", $id_auditoria);
                            $stmtCountEsc->execute();
                            $totalEscaneados = (int)($stmtCountEsc->get_result()->fetch_assoc()['total'] ?? 0);

                            $stmtCountInc = $conn->prepare("
                                SELECT COUNT(*) AS total
                                FROM auditorias_incidencias
                                WHERE id_auditoria = ?
                            ");
                            $stmtCountInc->bind_param("i", $id_auditoria);
                            $stmtCountInc->execute();
                            $totalIncidencias = (int)($stmtCountInc->get_result()->fetch_assoc()['total'] ?? 0);

                            $stmtUpdCab = $conn->prepare("
                                UPDATE auditorias
                                SET total_snapshot = ?,
                                    total_escaneados = ?,
                                    total_ok = ?,
                                    total_faltantes = ?,
                                    total_incidencias = ?
                                WHERE id = ?
                            ");
                            $stmtUpdCab->bind_param(
                                "iiiiii",
                                $totalEsperados,
                                $totalEscaneados,
                                $totalOk,
                                $totalFaltantes,
                                $totalIncidencias,
                                $id_auditoria
                            );
                            $stmtUpdCab->execute();

                            $stmtBit = $conn->prepare("
                                INSERT INTO auditorias_bitacora (
                                    id_auditoria, accion, detalle, datos_extra, realizado_por, fecha_evento
                                ) VALUES (?, 'Escaneo OK', ?, ?, ?, NOW())
                            ");
                            $detalleBit = 'Se registró correctamente un equipo en el escaneo.';
                            $jsonBit = json_encode([
                                'imei' => $imei,
                                'id_producto' => $id_producto,
                                'id_snapshot' => $id_snapshot,
                                'id_escaneo' => $id_escaneo
                            ], JSON_UNESCAPED_UNICODE);
                            $stmtBit->bind_param("issi", $id_auditoria, $detalleBit, $jsonBit, $ID_USUARIO);
                            $stmtBit->execute();

                            $conn->commit();

                            $ultimoResultado = [
                                'tipo' => 'ok',
                                'titulo' => 'Equipo encontrado',
                                'mensaje' => 'El equipo fue localizado correctamente en el inventario de la sucursal.',
                                'imei' => $imei,
                                'marca' => $snap['marca'] ?? '',
                                'modelo' => $snap['modelo'] ?? '',
                                'color' => $snap['color'] ?? '',
                                'capacidad' => $snap['capacidad'] ?? '',
                            ];
                        }
                    } else {
                        $origenEncontrado = 'No encontrado';
                        $detalleInc = 'El identificador no fue localizado en el snapshot de la auditoría.';
                        $refTabla = null;
                        $refId = null;
                        $idProductoGlobal = null;
                        $idInventarioGlobal = null;

                        $stmtGlobal = $conn->prepare("
                            SELECT
                                i.id AS id_inventario,
                                i.id_sucursal,
                                i.estatus,
                                p.id AS id_producto,
                                p.marca,
                                p.modelo,
                                p.color,
                                p.capacidad,
                                p.imei1,
                                p.imei2,
                                s.nombre AS sucursal_nombre
                            FROM productos p
                            INNER JOIN inventario i ON i.id_producto = p.id
                            LEFT JOIN sucursales s ON s.id = i.id_sucursal
                            WHERE p.imei1 = ? OR p.imei2 = ?
                            LIMIT 1
                        ");
                        $stmtGlobal->bind_param("ss", $imei, $imei);
                        $stmtGlobal->execute();
                        $global = $stmtGlobal->get_result()->fetch_assoc();

                        if ($global) {
                            $idProductoGlobal = (int)$global['id_producto'];
                            $idInventarioGlobal = (int)$global['id_inventario'];
                            if ((int)$global['id_sucursal'] !== (int)$auditoria['id_sucursal']) {
                                $origenEncontrado = 'Inventario otra sucursal';
                                $detalleInc = 'El identificador pertenece al inventario de otra sucursal: ' . ($global['sucursal_nombre'] ?? 'N/D');
                            } else {
                                $origenEncontrado = 'Otro';
                                $detalleInc = 'El identificador existe en inventario, pero no pertenece al snapshot de esta auditoría.';
                            }
                            $refTabla = 'inventario';
                            $refId = (int)$global['id_inventario'];
                        }

                        $stmtEscInc = $conn->prepare("
                            INSERT INTO auditorias_escaneos (
                                id_auditoria,
                                id_snapshot,
                                id_inventario,
                                id_producto,
                                imei_escaneado,
                                tipo_imei,
                                resultado,
                                origen_encontrado,
                                referencia_tabla,
                                referencia_id,
                                descripcion_resultado,
                                observaciones,
                                escaneado_por,
                                fecha_escaneo
                            ) VALUES (
                                ?, NULL, ?, ?, ?, 'desconocido', 'No localizado', ?, ?, ?, ?, ?, ?, NOW()
                            )
                        ");
                        $obsNoLoc = 'Escaneo fuera del snapshot.';
                        $stmtEscInc->bind_param(
                            "iiisssissi",
                            $id_auditoria,
                            $idInventarioGlobal,
                            $idProductoGlobal,
                            $imei,
                            $origenEncontrado,
                            $refTabla,
                            $refId,
                            $detalleInc,
                            $obsNoLoc,
                            $ID_USUARIO
                        );
                        $stmtEscInc->execute();
                        $idEscaneoInc = (int)$conn->insert_id;

                        $tipoInc = 'No localizado en sistema';
                        if ($origenEncontrado === 'Inventario otra sucursal') {
                            $tipoInc = 'Encontrado en otra sucursal';
                        }

                        $stmtInc = $conn->prepare("
                            INSERT INTO auditorias_incidencias (
                                id_auditoria,
                                id_escaneo,
                                id_producto,
                                id_inventario,
                                imei_escaneado,
                                tipo_incidencia,
                                detalle,
                                referencia_tabla,
                                referencia_id,
                                observaciones,
                                creado_por
                            ) VALUES (
                                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                            )
                        ");
                        $obsInc = 'Registrado como incidencia durante auditoría.';
                        $stmtInc->bind_param(
                            "iiiissssisi",
                            $id_auditoria,
                            $idEscaneoInc,
                            $idProductoGlobal,
                            $idInventarioGlobal,
                            $imei,
                            $tipoInc,
                            $detalleInc,
                            $refTabla,
                            $refId,
                            $obsInc,
                            $ID_USUARIO
                        );
                        $stmtInc->execute();

                        $stmtCountEsc = $conn->prepare("
                            SELECT COUNT(*) AS total
                            FROM auditorias_escaneos
                            WHERE id_auditoria = ?
                        ");
                        $stmtCountEsc->bind_param("i", $id_auditoria);
                        $stmtCountEsc->execute();
                        $totalEscaneados = (int)($stmtCountEsc->get_result()->fetch_assoc()['total'] ?? 0);

                        $stmtCountInc = $conn->prepare("
                            SELECT COUNT(*) AS total
                            FROM auditorias_incidencias
                            WHERE id_auditoria = ?
                        ");
                        $stmtCountInc->bind_param("i", $id_auditoria);
                        $stmtCountInc->execute();
                        $totalIncidencias = (int)($stmtCountInc->get_result()->fetch_assoc()['total'] ?? 0);

                        $stmtUpdCab = $conn->prepare("
                            UPDATE auditorias
                            SET total_escaneados = ?,
                                total_incidencias = ?
                            WHERE id = ?
                        ");
                        $stmtUpdCab->bind_param("iii", $totalEscaneados, $totalIncidencias, $id_auditoria);
                        $stmtUpdCab->execute();

                        $stmtBit = $conn->prepare("
                            INSERT INTO auditorias_bitacora (
                                id_auditoria, accion, detalle, datos_extra, realizado_por, fecha_evento
                            ) VALUES (?, 'Escaneo incidencia', ?, ?, ?, NOW())
                        ");
                        $detalleBit = 'Se registró un IMEI fuera del snapshot de la auditoría.';
                        $jsonBit = json_encode([
                            'imei' => $imei,
                            'origen_encontrado' => $origenEncontrado,
                            'referencia_tabla' => $refTabla,
                            'referencia_id' => $refId
                        ], JSON_UNESCAPED_UNICODE);
                        $stmtBit->bind_param("issi", $id_auditoria, $detalleBit, $jsonBit, $ID_USUARIO);
                        $stmtBit->execute();

                        $conn->commit();

                        $ultimoResultado = [
                            'tipo' => 'incidencia',
                            'titulo' => 'No pertenece al snapshot',
                            'mensaje' => $detalleInc,
                            'imei' => $imei,
                            'marca' => $global['marca'] ?? '',
                            'modelo' => $global['modelo'] ?? '',
                            'color' => $global['color'] ?? '',
                            'capacidad' => $global['capacidad'] ?? '',
                        ];
                    }
                }

            } catch (Throwable $e) {
                $conn->rollback();
                $errores[] = 'Error al registrar el escaneo: ' . $e->getMessage();
            }
        }
    }
}

/* =========================================================
   RECARGAR AUDITORIA
========================================================= */
$stmtAud->execute();
$auditoria = $stmtAud->get_result()->fetch_assoc();
$estaCerrada = (($auditoria['estatus'] ?? '') === 'Cerrada');

/* =========================================================
   KPIS
========================================================= */
$stmtKpi = $conn->prepare("
    SELECT
        COUNT(*) AS total_esperados,
        SUM(CASE WHEN escaneado = 1 THEN 1 ELSE 0 END) AS encontrados,
        SUM(CASE WHEN escaneado = 0 THEN 1 ELSE 0 END) AS pendientes
    FROM auditorias_snapshot
    WHERE id_auditoria = ?
");
$stmtKpi->bind_param("i", $id_auditoria);
$stmtKpi->execute();
$kpi = $stmtKpi->get_result()->fetch_assoc() ?: [
    'total_esperados' => 0,
    'encontrados' => 0,
    'pendientes' => 0
];

/* =========================================================
   ULTIMOS ESCANEOS
========================================================= */
$stmtUlt = $conn->prepare("
    SELECT 
        e.*,
        p.marca,
        p.modelo,
        p.color,
        p.capacidad
    FROM auditorias_escaneos e
    LEFT JOIN productos p ON p.id = e.id_producto
    WHERE e.id_auditoria = ?
    ORDER BY e.id DESC
    LIMIT 20
");
$stmtUlt->bind_param("i", $id_auditoria);
$stmtUlt->execute();
$ultimosEscaneos = $stmtUlt->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   ULTIMAS INCIDENCIAS
========================================================= */
$stmtIncList = $conn->prepare("
    SELECT *
    FROM auditorias_incidencias
    WHERE id_auditoria = ?
    ORDER BY id DESC
    LIMIT 10
");
$stmtIncList->bind_param("i", $id_auditoria);
$stmtIncList->execute();
$incidencias = $stmtIncList->get_result()->fetch_all(MYSQLI_ASSOC);

$scanFeedbackClass = '';
$scanFeedbackText  = '';
if ($ultimoResultado) {
    if ($ultimoResultado['tipo'] === 'ok') {
        $scanFeedbackClass = 'scan-ok';
        $scanFeedbackText  = '✅ ' . trim(($ultimoResultado['marca'] ?? '') . ' ' . ($ultimoResultado['modelo'] ?? ''));
        if (!empty($ultimoResultado['color'])) $scanFeedbackText .= ' · ' . $ultimoResultado['color'];
        if (!empty($ultimoResultado['capacidad'])) $scanFeedbackText .= ' · ' . $ultimoResultado['capacidad'];
        $scanFeedbackText .= ' · IMEI: ' . ($ultimoResultado['imei'] ?? '');
    } elseif ($ultimoResultado['tipo'] === 'duplicado') {
        $scanFeedbackClass = 'scan-dup';
        $scanFeedbackText  = '⚠️ ' . ($ultimoResultado['titulo'] ?? 'Registro duplicado') . ' · IMEI: ' . ($ultimoResultado['imei'] ?? '');
    } else {
        $scanFeedbackClass = 'scan-inc';
        $scanFeedbackText  = '⛔ ' . ($ultimoResultado['mensaje'] ?? 'Incidencia detectada') . ' · IMEI: ' . ($ultimoResultado['imei'] ?? '');
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Escaneo de Auditoría</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{
            margin:0;
            background:#f5f7fb;
            font-family:Arial, Helvetica, sans-serif;
            color:#1f2937;
        }
        .wrap{
            max-width:1400px;
            margin:28px auto;
            padding:0 16px 40px;
        }
        .header{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:18px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }
        .title{
            margin:0;
            font-size:30px;
            font-weight:800;
        }
        .subtitle{
            margin-top:6px;
            color:#6b7280;
            font-size:14px;
        }
        .badges{
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            margin-top:10px;
        }
        .badge{
            display:inline-block;
            padding:7px 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            background:#eef2ff;
            color:#3730a3;
        }
        .badge.gray{ background:#f3f4f6; color:#374151; }
        .badge.green{ background:#ecfdf5; color:#065f46; }
        .card{
            background:#fff;
            border-radius:18px;
            box-shadow:0 10px 24px rgba(0,0,0,.06);
            padding:20px;
            margin-bottom:18px;
        }
        .grid{
            display:grid;
            grid-template-columns:repeat(4, 1fr);
            gap:16px;
        }
        .kpi{
            background:linear-gradient(180deg,#ffffff 0%, #f9fafb 100%);
            border:1px solid #e5e7eb;
            border-radius:16px;
            padding:18px;
        }
        .kpi-label{
            font-size:13px;
            color:#6b7280;
            margin-bottom:8px;
        }
        .kpi-value{
            font-size:30px;
            font-weight:800;
            color:#111827;
        }
        .section-title{
            margin:0 0 14px;
            font-size:20px;
            font-weight:800;
        }
        .scan-box{
            display:grid;
            grid-template-columns:1fr auto;
            gap:12px;
            align-items:end;
        }
        .field{
            display:flex;
            flex-direction:column;
            gap:7px;
        }
        label{
            font-weight:700;
            font-size:14px;
        }
        .input{
            border:1px solid #d1d5db;
            border-radius:12px;
            padding:10px 14px;
            height:44px;
            font-size:16px;
            font-weight:700;
            outline:none;
            background:#fff;
            box-sizing:border-box;
        }
        .input:focus{
            border-color:#2563eb;
            box-shadow:0 0 0 3px rgba(37,99,235,.12);
        }
        .input.readonly-input{
            background:#f3f4f6;
            color:#6b7280;
            cursor:not-allowed;
        }
        .btn{
            border:none;
            border-radius:14px;
            padding:12px 18px;
            font-weight:800;
            cursor:pointer;
            font-size:14px;
        }
        .btn-primary{
            background:#111827;
            color:#fff;
        }
        .btn-primary[disabled]{
            background:#9ca3af;
            cursor:not-allowed;
        }
        .btn-secondary{
            background:#e5e7eb;
            color:#111827;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
        }
        .actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }
        .alert{
            border-radius:14px;
            padding:14px 16px;
            margin-bottom:16px;
        }
        .alert-danger{
            background:#fef2f2;
            border:1px solid #fecaca;
            color:#991b1b;
        }
        .alert-warn{
            background:#fff7ed;
            border:1px solid #fdba74;
            color:#9a3412;
        }
        .scan-feedback{
            margin-bottom:10px;
            padding:10px 14px;
            border-radius:12px;
            font-size:14px;
            font-weight:700;
            line-height:1.35;
        }
        .scan-ok{
            background:#ecfdf5;
            border:1px solid #a7f3d0;
            color:#065f46;
        }
        .scan-dup{
            background:#fff7ed;
            border:1px solid #fdba74;
            color:#9a3412;
        }
        .scan-inc{
            background:#fef2f2;
            border:1px solid #fecaca;
            color:#991b1b;
        }
        .table-wrap{
            overflow:auto;
            border:1px solid #e5e7eb;
            border-radius:16px;
        }
        table{
            width:100%;
            border-collapse:collapse;
            min-width:1000px;
            background:#fff;
        }
        thead th{
            background:#111827;
            color:#fff;
            font-size:13px;
            text-align:left;
            padding:12px 10px;
            white-space:nowrap;
        }
        tbody td{
            border-bottom:1px solid #eef2f7;
            padding:10px;
            font-size:13px;
        }
        tbody tr:hover{
            background:#f9fafb;
        }
        .pill{
            display:inline-block;
            padding:6px 10px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            white-space:nowrap;
        }
        .pill-ok{ background:#ecfdf5; color:#065f46; }
        .pill-inc{ background:#fef2f2; color:#991b1b; }
        .pill-dup{ background:#fff7ed; color:#9a3412; }
        .notice{
            margin-top:12px;
            padding:14px 16px;
            border:1px dashed #cbd5e1;
            border-radius:14px;
            background:#f8fafc;
            color:#334155;
            font-size:13px;
        }
        .modal-backdrop-custom{
            position: fixed;
            inset: 0;
            background: rgba(17, 24, 39, 0.55);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 9999;
        }
        .modal-card-custom{
            width: 100%;
            max-width: 560px;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 20px 45px rgba(0,0,0,.18);
            overflow: hidden;
            animation: modalPop .18s ease;
        }
        .modal-header-custom{
            padding: 18px 20px 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        .modal-header-custom h3{
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            color: #111827;
        }
        .modal-body-custom{
            padding: 18px 20px;
            color: #374151;
            font-size: 14px;
            line-height: 1.55;
        }
        .modal-footer-custom{
            padding: 14px 20px 20px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }
        @keyframes modalPop{
            from{
                opacity: 0;
                transform: scale(.97) translateY(8px);
            }
            to{
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }
        @media (max-width: 980px){
            .grid{ grid-template-columns:repeat(2, 1fr); }
            .scan-box{ grid-template-columns:1fr; }
        }
        @media (max-width: 640px){
            .grid{ grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <div>
            <h1 class="title">Escaneo de equipos</h1>
            <div class="subtitle">
                Folio <strong><?= h($auditoria['folio']) ?></strong> · Sucursal <strong><?= h($auditoria['sucursal_nombre']) ?></strong>
            </div>
            <div class="badges">
                <span class="badge <?= $estaCerrada ? 'green' : '' ?>"><?= h($auditoria['estatus']) ?></span>
                <span class="badge gray">Inicio: <?= h(fmtFecha($auditoria['fecha_inicio'])) ?></span>
                <span class="badge gray">Auditor: <?= h($auditoria['auditor_nombre']) ?></span>
                <?php if (!empty($auditoria['fecha_cierre'])): ?>
                    <span class="badge gray">Cierre: <?= h(fmtFecha($auditoria['fecha_cierre'])) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="actions">
            <a href="auditorias_inicio.php?id=<?= (int)$id_auditoria ?>" class="btn btn-secondary">Volver a detalles</a>
            <button type="button" class="btn btn-primary" onclick="abrirModalConciliacion()">Ir a conciliación</button>
            <?php if ($estaCerrada): ?>
                <a href="generar_acta_auditoria.php?id=<?= (int)$id_auditoria ?>" class="btn btn-secondary">Ver acta</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($estaCerrada): ?>
        <div class="alert alert-warn">
            <strong>Auditoría en solo lectura.</strong>
            Esta auditoría ya fue cerrada, por lo tanto ya no se pueden registrar más escaneos. Puedes volver al detalle de la auditoría o consultar el acta final.
        </div>
    <?php endif; ?>

    <?php if ($errores): ?>
        <div class="alert alert-danger">
            <strong>No se pudo registrar el escaneo:</strong>
            <ul style="margin:8px 0 0 18px;">
                <?php foreach ($errores as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2 class="section-title">Resumen del escaneo</h2>
        <div class="grid">
            <div class="kpi">
                <div class="kpi-label">Esperados</div>
                <div class="kpi-value"><?= (int)$kpi['total_esperados'] ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Encontrados</div>
                <div class="kpi-value"><?= (int)$kpi['encontrados'] ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Pendientes</div>
                <div class="kpi-value"><?= (int)$kpi['pendientes'] ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Incidencias</div>
                <div class="kpi-value"><?= (int)($auditoria['total_incidencias'] ?? 0) ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Capturar IMEI</h2>

        <?php if ($scanFeedbackText !== ''): ?>
            <div id="scan-feedback" class="scan-feedback <?= h($scanFeedbackClass) ?>"><?= h($scanFeedbackText) ?></div>
        <?php else: ?>
            <div id="scan-feedback" class="scan-feedback" style="display:none;"></div>
        <?php endif; ?>

        <form method="POST" action="" autocomplete="off">
            <input type="hidden" name="id_auditoria" value="<?= (int)$id_auditoria ?>">

            <div class="scan-box">
                <div class="field">
                    <label for="imei">Escanear / escribir IMEI</label>
                    <input
                        type="text"
                        name="imei"
                        id="imei"
                        class="input <?= $estaCerrada ? 'readonly-input' : '' ?>"
                        placeholder="Escanea aquí..."
                        autofocus
                        <?= $estaCerrada ? 'disabled' : '' ?>
                    >
                </div>
                <div>
                    <button type="submit" name="registrar_escaneo" value="1" class="btn btn-primary" <?= $estaCerrada ? 'disabled' : '' ?>>
                        Registrar escaneo
                    </button>
                </div>
            </div>
        </form>

        <div class="notice">
            Usa esta vista solo para el escaneo de productos serializados. Cuando termines, regresa al detalle o continúa a conciliación para revisar resultados y proceder al cierre.
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Últimos escaneos</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Fecha</th>
                        <th>IMEI</th>
                        <th>Marca</th>
                        <th>Modelo</th>
                        <th>Color</th>
                        <th>Capacidad</th>
                        <th>Resultado</th>
                        <th>Origen</th>
                        <th>Detalle</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$ultimosEscaneos): ?>
                    <tr>
                        <td colspan="10" style="text-align:center; padding:24px;">Todavía no hay escaneos registrados.</td>
                    </tr>
                <?php else: ?>
                    <?php $n=1; foreach ($ultimosEscaneos as $row): ?>
                        <?php
                            $pillClass = 'pill-ok';
                            if (($row['resultado'] ?? '') === 'Duplicado') $pillClass = 'pill-dup';
                            if (($row['resultado'] ?? '') === 'Incidencia' || ($row['resultado'] ?? '') === 'No localizado') $pillClass = 'pill-inc';
                        ?>
                        <tr>
                            <td><?= $n++ ?></td>
                            <td><?= h(fmtFecha($row['fecha_escaneo'])) ?></td>
                            <td><?= h($row['imei_escaneado']) ?></td>
                            <td><?= h($row['marca']) ?></td>
                            <td><?= h($row['modelo']) ?></td>
                            <td><?= h($row['color']) ?></td>
                            <td><?= h($row['capacidad']) ?></td>
                            <td><span class="pill <?= h($pillClass) ?>"><?= h($row['resultado']) ?></span></td>
                            <td><?= h($row['origen_encontrado']) ?></td>
                            <td><?= h($row['descripcion_resultado']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Incidencias recientes</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>IMEI</th>
                        <th>Tipo incidencia</th>
                        <th>Detalle</th>
                        <th>Referencia</th>
                        <th>Fecha</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$incidencias): ?>
                    <tr>
                        <td colspan="6" style="text-align:center; padding:24px;">No hay incidencias registradas.</td>
                    </tr>
                <?php else: ?>
                    <?php $n=1; foreach ($incidencias as $inc): ?>
                        <tr>
                            <td><?= $n++ ?></td>
                            <td><?= h($inc['imei_escaneado']) ?></td>
                            <td><?= h($inc['tipo_incidencia']) ?></td>
                            <td><?= h($inc['detalle']) ?></td>
                            <td>
                                <?= h($inc['referencia_tabla']) ?>
                                <?php if (!empty($inc['referencia_id'])): ?>
                                    #<?= (int)$inc['referencia_id'] ?>
                                <?php endif; ?>
                            </td>
                            <td><?= h(fmtFecha($inc['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<div id="modalConciliacion" class="modal-backdrop-custom">
    <div class="modal-card-custom">
        <div class="modal-header-custom">
            <h3>Confirmar salida a conciliación</h3>
        </div>
        <div class="modal-body-custom">
            En la vista de conciliación podrás revisar los resultados finales y cerrar la auditoría.
            Antes de continuar, asegúrate de haber concluido el escaneo de productos serializados
            y el conteo de productos no serializados.
        </div>
        <div class="modal-footer-custom">
            <button type="button" class="btn btn-secondary" onclick="cerrarModalConciliacion()">Cancelar</button>
            <a href="auditorias_conciliar.php?id=<?= (int)$id_auditoria ?>" class="btn btn-primary">Continuar a conciliación</a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('imei');
    if (input && !input.disabled) {
        input.focus();
        input.select();
    }

    const feedback = document.getElementById('scan-feedback');
    if (feedback && feedback.style.display !== 'none' && feedback.textContent.trim() !== '') {
        setTimeout(() => {
            feedback.style.display = 'none';
        }, 2500);
    }
});

function abrirModalConciliacion() {
    document.getElementById('modalConciliacion').style.display = 'flex';
}
function cerrarModalConciliacion() {
    document.getElementById('modalConciliacion').style.display = 'none';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModalConciliacion();
    }
});
document.getElementById('modalConciliacion').addEventListener('click', function(e) {
    if (e.target === this) {
        cerrarModalConciliacion();
    }
});
</script>
</body>
</html>
