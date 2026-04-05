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
$ID_SUBDIS   = isset($_SESSION['id_subdis']) && $_SESSION['id_subdis'] !== '' ? (int)$_SESSION['id_subdis'] : null;

/* =========================================================
   PERMISOS
========================================================= */
$ROLES_PERMITIDOS = [
    'Admin', 'Administrador', 'Auditor', 'Logistica', 'GerenteZona',
    'Gerente', 'Supervisor'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para ver auditorías.');
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
    return $ts ? date('d/m/Y H:i', $ts) : $f;
}

function colorDiff(?int $diff): string {
    if ($diff === null) return '#6b7280';
    if ($diff > 0) return '#065f46';
    if ($diff < 0) return '#991b1b';
    return '#1f2937';
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

/* =========================================================
   RESTRICCIÓN BÁSICA POR SUCURSAL
========================================================= */
if (in_array($ROL, ['Gerente', 'Supervisor'], true) && (int)$auditoria['id_sucursal'] !== $ID_SUCURSAL) {
    http_response_code(403);
    exit('No puedes consultar una auditoría de otra sucursal.');
}

$errores = [];
$ok = '';
$estaCerrada = (($auditoria['estatus'] ?? '') === 'Cerrada');

/* =========================================================
   GUARDAR CONTEOS POR CANTIDAD
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_cantidades'])) {

    if ($estaCerrada) {
        $errores[] = 'La auditoría ya está cerrada. No es posible modificar los conteos.';
    } else {
        try {
            $conn->begin_transaction();

            $conteos = $_POST['conteos'] ?? [];
            $observaciones = $_POST['observaciones'] ?? [];

            if (!is_array($conteos)) $conteos = [];
            if (!is_array($observaciones)) $observaciones = [];

            $stmtUpd = $conn->prepare("
                UPDATE auditorias_snapshot_cantidades
                SET 
                    cantidad_contada = ?,
                    diferencia = ?,
                    estatus_revision = ?,
                    observaciones = ?,
                    contado_por = ?,
                    fecha_conteo = NOW()
                WHERE id = ?
                  AND id_auditoria = ?
            ");

            foreach ($conteos as $idFila => $cantidadCapturada) {
                $idFila = (int)$idFila;
                if ($idFila <= 0) continue;

                $stmtFila = $conn->prepare("
                    SELECT id, cantidad_sistema
                    FROM auditorias_snapshot_cantidades
                    WHERE id = ?
                      AND id_auditoria = ?
                    LIMIT 1
                ");
                $stmtFila->bind_param("ii", $idFila, $id_auditoria);
                $stmtFila->execute();
                $fila = $stmtFila->get_result()->fetch_assoc();

                if (!$fila) continue;

                $cantidadSistema = (int)$fila['cantidad_sistema'];
                $cantidadContada = ($cantidadCapturada === '' || $cantidadCapturada === null) ? null : (int)$cantidadCapturada;

                $diff = null;
                $estatusRevision = 'Pendiente';

                if ($cantidadContada !== null) {
                    $diff = $cantidadContada - $cantidadSistema;
                    if ($diff === 0) {
                        $estatusRevision = 'Contado';
                    } else {
                        $estatusRevision = 'Con diferencia';
                    }
                }

                $obs = trim((string)($observaciones[$idFila] ?? ''));

                $stmtUpd->bind_param(
                    "iisiiii",
                    $cantidadContada,
                    $diff,
                    $estatusRevision,
                    $obs,
                    $ID_USUARIO,
                    $idFila,
                    $id_auditoria
                );
                $stmtUpd->execute();
            }

            /* ============================================
               RECALCULAR TOTALES DE CABECERA
            ============================================ */
            $sqlTot = "
                SELECT
                    COUNT(*) AS total_lineas,
                    SUM(CASE WHEN cantidad_contada IS NOT NULL THEN 1 ELSE 0 END) AS total_contadas,
                    SUM(CASE WHEN diferencia IS NOT NULL AND diferencia <> 0 THEN 1 ELSE 0 END) AS total_con_diferencia
                FROM auditorias_snapshot_cantidades
                WHERE id_auditoria = ?
            ";
            $stmtTot = $conn->prepare($sqlTot);
            $stmtTot->bind_param("i", $id_auditoria);
            $stmtTot->execute();
            $tot = $stmtTot->get_result()->fetch_assoc();

            $totalLineas = (int)($tot['total_lineas'] ?? 0);
            $totalContadas = (int)($tot['total_contadas'] ?? 0);
            $totalConDiferencia = (int)($tot['total_con_diferencia'] ?? 0);

            $stmtCab = $conn->prepare("
                UPDATE auditorias
                SET total_lineas_accesorios = ?,
                    total_accesorios_contados = ?,
                    total_accesorios_con_diferencia = ?
                WHERE id = ?
            ");
            $stmtCab->bind_param("iiii", $totalLineas, $totalContadas, $totalConDiferencia, $id_auditoria);
            $stmtCab->execute();

            /* ============================================
               BITÁCORA
            ============================================ */
            $accion = 'Guardar conteos cantidades';
            $detalle = 'Se actualizaron conteos de productos auditados por cantidad.';
            $datos_extra = json_encode([
                'total_lineas' => $totalLineas,
                'total_contadas' => $totalContadas,
                'total_con_diferencia' => $totalConDiferencia
            ], JSON_UNESCAPED_UNICODE);

            $stmtBit = $conn->prepare("
                INSERT INTO auditorias_bitacora (
                    id_auditoria, accion, detalle, datos_extra, realizado_por, fecha_evento
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmtBit->bind_param("isssi", $id_auditoria, $accion, $detalle, $datos_extra, $ID_USUARIO);
            $stmtBit->execute();

            $conn->commit();
            $ok = 'Conteos por cantidad guardados correctamente.';

        } catch (Throwable $e) {
            $conn->rollback();
            $errores[] = 'Error al guardar los conteos: ' . $e->getMessage();
        }

        $stmtAud->execute();
        $auditoria = $stmtAud->get_result()->fetch_assoc();
        $estaCerrada = (($auditoria['estatus'] ?? '') === 'Cerrada');
    }
}

/* =========================================================
   KPIs NO SERIALIZADOS
========================================================= */
$stmtCantKpi = $conn->prepare("
    SELECT
        SUM(COALESCE(cantidad_sistema, 0)) AS cantidades_esperadas,
        SUM(COALESCE(cantidad_contada, 0)) AS cantidades_contadas,
        SUM(GREATEST(COALESCE(cantidad_sistema, 0) - COALESCE(cantidad_contada, 0), 0)) AS cantidades_faltantes
    FROM auditorias_snapshot_cantidades
    WHERE id_auditoria = ?
");
$stmtCantKpi->bind_param("i", $id_auditoria);
$stmtCantKpi->execute();
$kpiCant = $stmtCantKpi->get_result()->fetch_assoc() ?: [
    'cantidades_esperadas' => 0,
    'cantidades_contadas' => 0,
    'cantidades_faltantes' => 0,
];

/* =========================================================
   TABLA DE CANTIDADES
========================================================= */
$stmtListaCant = $conn->prepare("
    SELECT *
    FROM auditorias_snapshot_cantidades
    WHERE id_auditoria = ?
    ORDER BY marca ASC, modelo ASC, color ASC, capacidad ASC
");
$stmtListaCant->bind_param("i", $id_auditoria);
$stmtListaCant->execute();
$listaCantidades = $stmtListaCant->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   BITÁCORA
========================================================= */
$stmtBitList = $conn->prepare("
    SELECT b.*, u.nombre AS usuario_nombre
    FROM auditorias_bitacora b
    LEFT JOIN usuarios u ON u.id = b.realizado_por
    WHERE b.id_auditoria = ?
    ORDER BY b.id DESC
    LIMIT 10
");
$stmtBitList->bind_param("i", $id_auditoria);
$stmtBitList->execute();
$bitacora = $stmtBitList->get_result()->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Captura de Auditoría</title>
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
            gap:20px;
            flex-wrap:wrap;
            margin-bottom:20px;
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
        .badge.red{ background:#fef2f2; color:#991b1b; }
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
            font-size:28px;
            font-weight:800;
            color:#111827;
        }
        .section-title{
            margin:0 0 14px;
            font-size:20px;
            font-weight:800;
        }
        .table-wrap{
            overflow:auto;
            border:1px solid #e5e7eb;
            border-radius:16px;
        }
        table{
            width:100%;
            border-collapse:collapse;
            min-width:1100px;
            background:#fff;
        }
        thead th{
            background:#111827;
            color:#fff;
            font-size:13px;
            text-align:left;
            padding:12px 10px;
            position:sticky;
            top:0;
            z-index:1;
            white-space:nowrap;
        }
        tbody td{
            border-bottom:1px solid #eef2f7;
            padding:10px;
            font-size:13px;
            vertical-align:middle;
        }
        tbody tr:hover{
            background:#f9fafb;
        }
        .input-mini, .textarea-mini{
            width:100%;
            box-sizing:border-box;
            border:1px solid #d1d5db;
            border-radius:10px;
            padding:9px 10px;
            font-size:13px;
            background:#fff;
        }
        .textarea-mini{
            min-height:52px;
            resize:vertical;
        }
        .readonly-input{
            background:#f3f4f6;
            color:#6b7280;
            cursor:not-allowed;
        }
        .diff{
            font-weight:800;
        }
        .pill-status{
            display:inline-block;
            padding:5px 10px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            white-space:nowrap;
        }
        .status-pendiente{ background:#f3f4f6; color:#374151; }
        .status-contado{ background:#ecfdf5; color:#065f46; }
        .status-diferencia{ background:#fef2f2; color:#991b1b; }
        .actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-top:16px;
        }
        .btn{
            border:none;
            border-radius:12px;
            padding:12px 18px;
            font-weight:700;
            font-size:14px;
            cursor:pointer;
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
        .info-list{
            display:grid;
            grid-template-columns:repeat(2, 1fr);
            gap:14px 22px;
        }
        .info-item{
            padding:12px 14px;
            border:1px solid #e5e7eb;
            border-radius:14px;
            background:#fafafa;
        }
        .info-label{
            font-size:12px;
            color:#6b7280;
            margin-bottom:4px;
        }
        .info-value{
            font-size:15px;
            font-weight:700;
            color:#111827;
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
        .alert-success{
            background:#ecfdf5;
            border:1px solid #a7f3d0;
            color:#065f46;
        }
        .alert-warn{
            background:#fff7ed;
            border:1px solid #fdba74;
            color:#9a3412;
        }
        .bit-item{
            padding:12px 0;
            border-bottom:1px dashed #e5e7eb;
        }
        .bit-item:last-child{ border-bottom:none; }
        .bit-title{
            font-weight:700;
            margin-bottom:4px;
        }
        .bit-meta{
            color:#6b7280;
            font-size:12px;
        }
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
        @media (max-width: 1100px){
            .grid{ grid-template-columns:repeat(2,1fr); }
            .info-list{ grid-template-columns:1fr; }
        }
        @media (max-width: 700px){
            .grid{ grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">

    <div class="header">
        <div>
            <h1 class="title">Captura de auditoría</h1>
            <div class="subtitle">
                Folio <strong><?= h($auditoria['folio']) ?></strong> · Sucursal <strong><?= h($auditoria['sucursal_nombre']) ?></strong>
            </div>
            <div class="badges">
                <span class="badge <?= $estaCerrada ? 'green' : '' ?>"><?= h($auditoria['estatus']) ?></span>
                <span class="badge gray">Inicio: <?= h(fmtFecha($auditoria['fecha_inicio'])) ?></span>
                <span class="badge gray">Auditor: <?= h($auditoria['auditor_nombre']) ?></span>
                <?php if (!empty($auditoria['gerente_nombre'])): ?>
                    <span class="badge gray">Gerente: <?= h($auditoria['gerente_nombre']) ?></span>
                <?php endif; ?>
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
            Esta auditoría ya fue cerrada, por lo tanto los conteos y capturas ya no se pueden modificar. Puedes volver al detalle de la auditoría o consultar el acta final.
        </div>
    <?php endif; ?>

    <?php if ($errores): ?>
        <div class="alert alert-danger">
            <strong>No se pudo completar la operación:</strong>
            <ul style="margin:8px 0 0 18px;">
                <?php foreach ($errores as $err): ?>
                    <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($ok): ?>
        <div class="alert alert-success">
            <strong><?= h($ok) ?></strong>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2 class="section-title">Datos generales</h2>
        <div class="info-list">
            <div class="info-item">
                <div class="info-label">Sucursal</div>
                <div class="info-value"><?= h($auditoria['sucursal_nombre']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Zona</div>
                <div class="info-value"><?= h($auditoria['sucursal_zona'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Tipo sucursal</div>
                <div class="info-value"><?= h($auditoria['tipo_sucursal'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Propiedad</div>
                <div class="info-value"><?= h($auditoria['propiedad'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Auditor</div>
                <div class="info-value"><?= h($auditoria['auditor_nombre']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Gerente / Encargado</div>
                <div class="info-value"><?= h($auditoria['gerente_nombre'] ?? '-') ?></div>
            </div>
            <div class="info-item" style="grid-column:1 / -1;">
                <div class="info-label">Observaciones iniciales</div>
                <div class="info-value"><?= nl2br(h($auditoria['observaciones_inicio'] ?? '-')) ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Resumen de no serializados</h2>
        <div class="grid">
            <div class="kpi">
                <div class="kpi-label">Cantidades esperadas</div>
                <div class="kpi-value"><?= (int)$kpiCant['cantidades_esperadas'] ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Cantidades contadas</div>
                <div class="kpi-value"><?= (int)$kpiCant['cantidades_contadas'] ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Faltantes</div>
                <div class="kpi-value"><?= (int)$kpiCant['cantidades_faltantes'] ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Incidencias</div>
                <div class="kpi-value"><?= (int)($auditoria['total_incidencias'] ?? 0) ?></div>
            </div>
        </div>

        <div class="notice">
            Esta vista es exclusiva para productos no serializados. El escaneo de productos serializados se realiza desde su vista correspondiente.
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Conteo de productos no serializados</h2>

        <form method="POST" action="">
            <input type="hidden" name="id_auditoria" value="<?= (int)$id_auditoria ?>">

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Código</th>
                            <th>Marca</th>
                            <th>Modelo</th>
                            <th>Color</th>
                            <th>RAM</th>
                            <th>Capacidad</th>
                            <th>Tipo</th>
                            <th>Cantidad esperada</th>
                            <th>Cantidad contada</th>
                            <th>Diferencia</th>
                            <th>Estatus</th>
                            <th>Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$listaCantidades): ?>
                        <tr>
                            <td colspan="13" style="text-align:center; padding:24px;">
                                No hay productos no serializados en esta auditoría.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $n = 1; foreach ($listaCantidades as $row): ?>
                            <?php
                                $diff = ($row['diferencia'] !== null) ? (int)$row['diferencia'] : null;
                                $estatusCss = 'status-pendiente';
                                if (($row['estatus_revision'] ?? '') === 'Contado') $estatusCss = 'status-contado';
                                if (($row['estatus_revision'] ?? '') === 'Con diferencia') $estatusCss = 'status-diferencia';
                            ?>
                            <tr>
                                <td><?= $n++ ?></td>
                                <td><?= h($row['codigo_producto']) ?></td>
                                <td><?= h($row['marca']) ?></td>
                                <td><?= h($row['modelo']) ?></td>
                                <td><?= h($row['color']) ?></td>
                                <td><?= h($row['ram']) ?></td>
                                <td><?= h($row['capacidad']) ?></td>
                                <td><?= h($row['tipo_producto']) ?></td>
                                <td><strong><?= (int)$row['cantidad_sistema'] ?></strong></td>
                                <td style="min-width:110px;">
                                    <input
                                        type="number"
                                        min="0"
                                        name="conteos[<?= (int)$row['id'] ?>]"
                                        value="<?= ($row['cantidad_contada'] !== null ? (int)$row['cantidad_contada'] : '') ?>"
                                        class="input-mini <?= $estaCerrada ? 'readonly-input' : '' ?>"
                                        <?= $estaCerrada ? 'disabled' : '' ?>
                                    >
                                </td>
                                <td>
                                    <span class="diff" style="color:<?= h(colorDiff($diff)) ?>;">
                                        <?= ($diff !== null ? ($diff > 0 ? '+' . $diff : (string)$diff) : '-') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="pill-status <?= h($estatusCss) ?>">
                                        <?= h($row['estatus_revision']) ?>
                                    </span>
                                </td>
                                <td style="min-width:240px;">
                                    <textarea
                                        name="observaciones[<?= (int)$row['id'] ?>]"
                                        class="textarea-mini <?= $estaCerrada ? 'readonly-input' : '' ?>"
                                        placeholder="Observaciones de diferencia o conteo..."
                                        <?= $estaCerrada ? 'disabled' : '' ?>
                                    ><?= h($row['observaciones'] ?? '') ?></textarea>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$estaCerrada): ?>
                <div class="actions">
                    <button type="submit" name="guardar_cantidades" value="1" class="btn btn-primary">
                        Guardar conteos de no serializados
                    </button>
                </div>
            <?php else: ?>
                <div class="notice">
                    Los conteos quedaron congelados porque la auditoría ya fue cerrada.
                </div>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h2 class="section-title">Bitácora reciente</h2>
        <?php if (!$bitacora): ?>
            <div class="notice">Todavía no hay eventos registrados en esta auditoría.</div>
        <?php else: ?>
            <?php foreach ($bitacora as $b): ?>
                <div class="bit-item">
                    <div class="bit-title"><?= h($b['accion']) ?></div>
                    <div><?= h($b['detalle'] ?? '') ?></div>
                    <div class="bit-meta">
                        <?= h(fmtFecha($b['fecha_evento'])) ?>
                        <?php if (!empty($b['usuario_nombre'])): ?>
                            · <?= h($b['usuario_nombre']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
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
