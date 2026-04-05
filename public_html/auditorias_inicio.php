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
$ID_USUARIO   = (int)($_SESSION['id_usuario'] ?? 0);
$ROL          = trim((string)($_SESSION['rol'] ?? ''));
$ID_SUCURSAL  = (int)($_SESSION['id_sucursal'] ?? 0);
$ID_SUBDIS    = isset($_SESSION['id_subdis']) && $_SESSION['id_subdis'] !== '' ? (int)$_SESSION['id_subdis'] : null;

$ROLES_PERMITIDOS = [
    'Admin', 'Administrador', 'Auditor', 'Logistica', 'GerenteZona',
    'Gerente', 'Encargado', 'Supervisor'
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

function fmtFechaHora(?string $fecha): string {
    if (!$fecha) return '-';
    $ts = strtotime($fecha);
    return $ts ? date('d/m/Y H:i', $ts) : $fecha;
}

function fmtMoney(float $n): string {
    return '$' . number_format($n, 2);
}

function estadoClase(string $estatus): string {
    $e = function_exists('mb_strtolower') ? mb_strtolower(trim($estatus), 'UTF-8') : strtolower(trim($estatus));
    return match ($e) {
        'en proceso' => 'badge warning',
        'cerrada', 'finalizada', 'completada' => 'badge success',
        'cancelada' => 'badge danger',
        default => 'badge secondary',
    };
}

function tableExists(mysqli $conn, string $table): bool {
    $table = $conn->real_escape_string($table);
    $sql = "SELECT 1
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = '{$table}'
            LIMIT 1";
    $rs = $conn->query($sql);
    return $rs && $rs->num_rows > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $sql = "SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = '{$table}'
              AND column_name = '{$column}'
            LIMIT 1";
    $rs = $conn->query($sql);
    return $rs && $rs->num_rows > 0;
}

/* =========================================================
   INPUT
========================================================= */
$id_auditoria = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id_auditoria <= 0) {
    http_response_code(400);
    exit('ID de auditoría inválido.');
}

/* =========================================================
   CARGAR AUDITORÍA
========================================================= */
$sqlAud = "
    SELECT
        a.id,
        a.folio,
        a.id_sucursal,
        a.id_auditor,
        a.id_gerente,
        a.propiedad,
        a.id_subdis,
        a.fecha_inicio,
        a.estatus,
        a.observaciones_inicio,
        a.total_snapshot,
        a.total_lineas_accesorios,
        s.nombre AS sucursal_nombre,
        u1.nombre AS auditor_nombre,
        u1.rol AS auditor_rol,
        u2.nombre AS responsable_nombre,
        u2.rol AS responsable_rol
    FROM auditorias a
    INNER JOIN sucursales s ON s.id = a.id_sucursal
    LEFT JOIN usuarios u1 ON u1.id = a.id_auditor
    LEFT JOIN usuarios u2 ON u2.id = a.id_gerente
    WHERE a.id = ?
    LIMIT 1
";
$stmtAud = $conn->prepare($sqlAud);
$stmtAud->bind_param("i", $id_auditoria);
$stmtAud->execute();
$resAud = $stmtAud->get_result();
$auditoria = $resAud->fetch_assoc();

if (!$auditoria) {
    http_response_code(404);
    exit('La auditoría no existe.');
}

/* =========================================================
   PERMISOS POR SUCURSAL
========================================================= */
if (!in_array($ROL, ['Admin', 'Administrador', 'Auditor', 'Logistica', 'GerenteZona'], true)) {
    if ((int)$auditoria['id_sucursal'] !== $ID_SUCURSAL) {
        http_response_code(403);
        exit('No tienes permiso para ver esta auditoría.');
    }
}

/* =========================================================
   ESTADO SEMÁNTICO DE LA VISTA
========================================================= */
$estatusActual = function_exists('mb_strtolower')
    ? mb_strtolower(trim((string)($auditoria['estatus'] ?? '')), 'UTF-8')
    : strtolower(trim((string)($auditoria['estatus'] ?? '')));
$auditoriaCerrada = in_array($estatusActual, ['cerrada', 'finalizada', 'completada'], true);

/* =========================================================
   BANDERAS DE TABLAS
========================================================= */
$hasSnapshot     = tableExists($conn, 'auditorias_snapshot');
$hasSnapshotCant = tableExists($conn, 'auditorias_snapshot_cantidades');
$hasIncidencias  = tableExists($conn, 'auditorias_incidencias');
$hasProductos    = tableExists($conn, 'productos');

/* =========================================================
   CONTADORES DE AVANCE
========================================================= */
$totalSerializados = 0;
$totalNoSerializados = 0;
$totalEscaneados = 0;
$totalCapturadosCant = 0;

if ($hasSnapshot) {
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total_esperados,
            SUM(CASE WHEN escaneado = 1 THEN 1 ELSE 0 END) AS total_encontrados
        FROM auditorias_snapshot
        WHERE id_auditoria = ?
    ");
    $stmt->bind_param("i", $id_auditoria);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $totalSerializados = (int)($row['total_esperados'] ?? 0);
    $totalEscaneados   = (int)($row['total_encontrados'] ?? 0);
}

if ($hasSnapshotCant) {
    $stmt = $conn->prepare("
        SELECT
            SUM(COALESCE(cantidad_sistema, 0)) AS total_esperado_cantidad,
            SUM(COALESCE(cantidad_contada, 0)) AS total_contado_cantidad
        FROM auditorias_snapshot_cantidades
        WHERE id_auditoria = ?
    ");
    $stmt->bind_param("i", $id_auditoria);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    $totalNoSerializados = (int)($row['total_esperado_cantidad'] ?? 0);
    $totalCapturadosCant = (int)($row['total_contado_cantidad'] ?? 0);
}

/* =========================================================
   KPIS EJECUTIVOS
========================================================= */
$faltantesSerializados = 0;
$faltantesNoSerializados = 0;
$totalIncidencias = 0;
$costoFaltanteSerializados = 0.0;
$costoFaltanteNoSerializados = 0.0;
$costoFaltanteTotal = 0.0;

$tieneProductos = $hasProductos && columnExists($conn, 'productos', 'id') && columnExists($conn, 'productos', 'costo');

if ($hasSnapshot) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS faltantes
        FROM auditorias_snapshot
        WHERE id_auditoria = ?
          AND COALESCE(escaneado, 0) = 0
    ");
    $stmt->bind_param("i", $id_auditoria);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $faltantesSerializados = (int)($row['faltantes'] ?? 0);
}

if ($hasSnapshotCant) {
    $stmt = $conn->prepare("
        SELECT
            SUM(CASE WHEN COALESCE(diferencia,0) < 0 THEN ABS(diferencia) ELSE 0 END) AS faltantes_no_serial
        FROM auditorias_snapshot_cantidades
        WHERE id_auditoria = ?
    ");
    $stmt->bind_param("i", $id_auditoria);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $faltantesNoSerializados = (int)($row['faltantes_no_serial'] ?? 0);
}

if ($hasIncidencias) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM auditorias_incidencias
        WHERE id_auditoria = ?
    ");
    $stmt->bind_param("i", $id_auditoria);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $totalIncidencias = (int)($row['total'] ?? 0);
}

if ($tieneProductos && $hasSnapshot) {
    $stmt = $conn->prepare("
        SELECT SUM(COALESCE(p.costo, 0)) AS total
        FROM auditorias_snapshot s
        LEFT JOIN productos p ON p.id = s.id_producto
        WHERE s.id_auditoria = ?
          AND COALESCE(s.escaneado, 0) = 0
    ");
    $stmt->bind_param("i", $id_auditoria);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $costoFaltanteSerializados = (float)($row['total'] ?? 0);
}

if ($tieneProductos && $hasSnapshotCant) {
    $stmt = $conn->prepare("
        SELECT SUM(
            CASE
                WHEN COALESCE(sc.diferencia,0) < 0
                    THEN ABS(sc.diferencia) * COALESCE(p.costo, 0)
                ELSE 0
            END
        ) AS total
        FROM auditorias_snapshot_cantidades sc
        LEFT JOIN productos p ON p.id = sc.id_producto
        WHERE sc.id_auditoria = ?
    ");
    $stmt->bind_param("i", $id_auditoria);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $costoFaltanteNoSerializados = (float)($row['total'] ?? 0);
}

$costoFaltanteTotal = $costoFaltanteSerializados + $costoFaltanteNoSerializados;

/* =========================================================
   TEXTOS DINÁMICOS SEGÚN ESTATUS
========================================================= */
$txtBtnEscaneo = $auditoriaCerrada
    ? 'Ver escaneo'
    : ($totalEscaneados > 0 ? 'Continuar escaneo' : 'Iniciar escaneo');

$txtBtnCant = $auditoriaCerrada
    ? 'Ver captura'
    : ($totalCapturadosCant > 0 ? 'Continuar captura' : 'Iniciar captura');

$txtModuloSerial = $auditoriaCerrada
    ? 'Consulta el resultado final del escaneo de equipos serializados registrado en esta auditoría.'
    : 'Ingresa a este módulo para registrar y validar equipos controlados por IMEI o número de serie contra el inventario esperado.';

$txtModuloCant = $auditoriaCerrada
    ? 'Consulta el resultado final de la captura de productos no serializados registrado en esta auditoría.'
    : 'Ingresa a este módulo para capturar accesorios y otros productos manejados por cantidad, comparando lo esperado contra lo contado.';

/* =========================================================
   PROGRESO GENERAL
========================================================= */
$totalEsperadoGeneral = $totalSerializados + $totalNoSerializados;
$totalAvanceGeneral   = $totalEscaneados + $totalCapturadosCant;

$progresoGeneral = $totalEsperadoGeneral > 0
    ? min(100, (int)round(($totalAvanceGeneral / $totalEsperadoGeneral) * 100))
    : 0;

$progresoSerializados = $totalSerializados > 0
    ? min(100, (int)round(($totalEscaneados / $totalSerializados) * 100))
    : 0;

$progresoNoSerializados = $totalNoSerializados > 0
    ? min(100, (int)round(($totalCapturadosCant / $totalNoSerializados) * 100))
    : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalle de auditoría</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{
            background:linear-gradient(180deg,#f6f8fb 0%, #eef3f8 100%);
            font-family:Arial, Helvetica, sans-serif;
            color:#1f2937;
        }
        .wrap{
            max-width:1260px;
            margin:28px auto;
            padding:0 16px 40px;
        }
        .hero,.section-card{
            background:#fff;
            border-radius:24px;
            box-shadow:0 14px 34px rgba(15,23,42,.08);
            padding:28px;
            margin-bottom:22px;
            border:1px solid #edf2f7;
        }
        .hero-top{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:16px;
            flex-wrap:wrap;
            margin-bottom:22px;
        }
        .title{
            margin:0 0 6px;
            font-size:32px;
            font-weight:900;
            color:#0f172a;
            letter-spacing:-.02em;
        }
        .subtitle{
            margin:0;
            color:#64748b;
            font-size:15px;
        }
        .badge{
            display:inline-flex;
            align-items:center;
            padding:9px 13px;
            border-radius:999px;
            font-size:12px;
            font-weight:800;
            white-space:nowrap;
            letter-spacing:.02em;
        }
        .badge.warning{ background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; }
        .badge.success{ background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
        .badge.danger{ background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .badge.secondary{ background:#f3f4f6; color:#374151; border:1px solid #d1d5db; }

        .status-note{
            margin-top:16px;
            padding:14px 16px;
            border-radius:16px;
            font-size:14px;
            font-weight:700;
            border:1px solid #bfdbfe;
            background:#eff6ff;
            color:#1d4ed8;
        }

        .hero-grid{
            display:grid;
            grid-template-columns:1.4fr .9fr;
            gap:18px;
            align-items:stretch;
            margin-top:18px;
        }
        .info-grid{
            display:grid;
            grid-template-columns:repeat(2, 1fr);
            gap:14px;
        }
        .info-card,.kpi{
            background:#f8fafc;
            border:1px solid #e5e7eb;
            border-radius:18px;
            padding:18px;
        }
        .info-label,.kpi-label{
            font-size:11px;
            font-weight:800;
            color:#64748b;
            text-transform:uppercase;
            letter-spacing:.08em;
            margin-bottom:8px;
        }
        .info-value{
            font-size:16px;
            font-weight:800;
            color:#0f172a;
            line-height:1.4;
        }
        .info-sub{
            font-size:13px;
            font-weight:600;
            color:#64748b;
            margin-top:4px;
        }

        .summary-card{
            background:linear-gradient(180deg,#0f172a 0%, #111827 100%);
            color:#fff;
            border-radius:22px;
            padding:22px;
            display:flex;
            flex-direction:column;
            justify-content:space-between;
            min-height:100%;
        }
        .summary-label{
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:.08em;
            font-weight:800;
            color:rgba(255,255,255,.72);
            margin-bottom:6px;
        }
        .summary-value{
            font-size:40px;
            font-weight:900;
            line-height:1;
            margin-bottom:10px;
        }
        .summary-text{
            color:rgba(255,255,255,.82);
            font-size:14px;
            line-height:1.5;
            margin-bottom:16px;
        }
        .progress{
            width:100%;
            height:10px;
            background:rgba(255,255,255,.18);
            border-radius:999px;
            overflow:hidden;
        }
        .progress-bar{
            height:100%;
            background:linear-gradient(90deg,#60a5fa 0%, #22c55e 100%);
        }
        .summary-foot{
            margin-top:12px;
            font-size:13px;
            color:rgba(255,255,255,.82);
            font-weight:700;
        }

        .section-title{
            margin:0 0 6px;
            font-size:24px;
            font-weight:900;
            color:#0f172a;
            letter-spacing:-.02em;
        }
        .section-subtitle{
            margin:0 0 18px;
            color:#64748b;
            font-size:14px;
        }

        .exec-kpis{
            display:grid;
            grid-template-columns:repeat(4, 1fr);
            gap:16px;
        }
        .exec-kpi{
            background:#f8fafc;
            border:1px solid #e5e7eb;
            border-radius:20px;
            padding:18px;
            min-height:120px;
        }
        .exec-kpi .label{
            font-size:11px;
            font-weight:800;
            color:#64748b;
            text-transform:uppercase;
            letter-spacing:.08em;
            margin-bottom:10px;
        }
        .exec-kpi .value{
            font-size:34px;
            font-weight:900;
            line-height:1;
            color:#0f172a;
            margin-bottom:8px;
        }
        .exec-kpi .value.red{ color:#b91c1c; }
        .exec-kpi .value.blue{ color:#1d4ed8; }
        .exec-kpi .value.green{ color:#065f46; }
        .exec-kpi .hint{
            font-size:13px;
            color:#64748b;
            line-height:1.4;
        }

        .modules{
            display:grid;
            grid-template-columns:repeat(2, 1fr);
            gap:22px;
            margin-top:22px;
        }
        .module-card{
            background:#fff;
            border-radius:24px;
            box-shadow:0 14px 34px rgba(15,23,42,.08);
            padding:24px;
            border:1px solid #edf2f7;
            position:relative;
            overflow:hidden;
        }
        .module-card::before{
            content:"";
            position:absolute;
            left:0;
            top:0;
            width:100%;
            height:5px;
            background:linear-gradient(90deg,#0f172a 0%, #334155 100%);
        }
        .module-card.accent-blue::before{
            background:linear-gradient(90deg,#1d4ed8 0%, #60a5fa 100%);
        }
        .module-card.readonly{
            background:linear-gradient(180deg,#ffffff 0%, #fbfdff 100%);
        }
        .module-head{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:14px;
            margin-bottom:10px;
        }
        .module-title{
            margin:0;
            font-size:26px;
            font-weight:900;
            color:#0f172a;
            line-height:1.08;
            letter-spacing:-.02em;
        }
        .module-chip{
            background:#eef2ff;
            color:#3730a3;
            border:1px solid #c7d2fe;
            border-radius:999px;
            font-size:11px;
            font-weight:800;
            padding:7px 10px;
            white-space:nowrap;
        }
        .module-chip.dark{
            background:#eef2f7;
            color:#111827;
            border:1px solid #dbe2ea;
        }
        .module-chip.readonly{
            background:#f8fafc;
            color:#475569;
            border:1px solid #dbe2ea;
        }
        .module-text{
            margin:0 0 18px;
            color:#64748b;
            font-size:15px;
            line-height:1.6;
        }
        .kpis{
            display:grid;
            grid-template-columns:repeat(2, 1fr);
            gap:14px;
            margin-bottom:18px;
        }
        .kpi-value{
            font-size:30px;
            font-weight:900;
            color:#0f172a;
            line-height:1;
        }
        .kpi-value.green{ color:#065f46; }
        .kpi-value.blue{ color:#1d4ed8; }

        .mini-progress-wrap{
            margin:2px 0 18px;
        }
        .mini-progress-top{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:8px;
            gap:10px;
        }
        .mini-progress-label{
            font-size:12px;
            font-weight:800;
            color:#475569;
        }
        .mini-progress-percent{
            font-size:12px;
            font-weight:900;
            color:#0f172a;
        }
        .mini-progress{
            width:100%;
            height:9px;
            background:#e5e7eb;
            border-radius:999px;
            overflow:hidden;
        }
        .mini-progress-bar{
            height:100%;
            background:linear-gradient(90deg,#0f172a 0%, #475569 100%);
        }
        .mini-progress-bar.blue{
            background:linear-gradient(90deg,#1d4ed8 0%, #60a5fa 100%);
        }

        .actions{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
            margin-top:4px;
        }
        .btn{
            border:none;
            border-radius:14px;
            padding:13px 18px;
            cursor:pointer;
            font-weight:800;
            font-size:14px;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            transition:.18s ease;
            box-sizing:border-box;
        }
        .btn:hover{ transform:translateY(-1px); }
        .btn-primary{
            background:#111827;
            color:#fff;
            min-width:170px;
            box-shadow:0 8px 18px rgba(17,24,39,.16);
        }
        .btn-primary.blue{
            background:#1d4ed8;
            box-shadow:0 8px 18px rgba(29,78,216,.18);
        }
        .btn-soft{
            background:#e2e8f0;
            color:#0f172a;
            min-width:170px;
            box-shadow:none;
        }
        .btn-soft.blue{
            background:#dbeafe;
            color:#1d4ed8;
        }

        .back-row{
            margin-top:22px;
            display:flex;
            justify-content:flex-end;
        }
        .btn-back{
            background:#ffffff;
            color:#111827;
            border:1px solid #dbe2ea;
            padding:12px 18px;
            border-radius:14px;
            text-decoration:none;
            font-weight:800;
            box-shadow:0 10px 24px rgba(15,23,42,.06);
            transition:.18s ease;
        }
        .btn-back:hover{
            transform:translateY(-1px);
            background:#f8fafc;
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
            .exec-kpis{ grid-template-columns:repeat(2, 1fr); }
        }
        @media (max-width: 980px){
            .hero-grid{ grid-template-columns:1fr; }
            .modules{ grid-template-columns:1fr; }
        }
        @media (max-width: 700px){
            .info-grid,.kpis,.exec-kpis{ grid-template-columns:1fr; }
            .title{ font-size:28px; }
            .module-head{
                flex-direction:column;
                align-items:flex-start;
            }
            .back-row{ justify-content:stretch; }
            .btn-back{
                width:100%;
                text-align:center;
                justify-content:center;
            }
        }
    </style>
</head>
<body>
<div class="wrap">

    <div class="hero">
        <div class="hero-top">
            <div>
                <h1 class="title">Auditoría <?= h($auditoria['folio']) ?></h1>
                <p class="subtitle">Detalle general y acceso a los módulos operativos de la auditoría.</p>
            </div>
            <span class="<?= h(estadoClase((string)$auditoria['estatus'])) ?>">
                <?= h($auditoria['estatus'] ?: 'Sin estatus') ?>
            </span>
        </div>

        <?php if ($auditoriaCerrada): ?>
            <div class="status-note">
                Esta auditoría ya fue cerrada. La información se muestra en modo consulta.
            </div>
        <?php endif; ?>

        <div class="hero-grid">
            <div class="info-grid">
                <div class="info-card">
                    <div class="info-label">Sucursal</div>
                    <div class="info-value"><?= h($auditoria['sucursal_nombre']) ?></div>
                </div>

                <div class="info-card">
                    <div class="info-label">Fecha de inicio</div>
                    <div class="info-value"><?= h(fmtFechaHora($auditoria['fecha_inicio'])) ?></div>
                </div>

                <div class="info-card">
                    <div class="info-label">Auditor</div>
                    <div class="info-value">
                        <?= h($auditoria['auditor_nombre'] ?: 'No asignado') ?>
                        <?php if (!empty($auditoria['auditor_rol'])): ?>
                            <br><span class="info-sub"><?= h($auditoria['auditor_rol']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="info-card">
                    <div class="info-label">Responsable presente</div>
                    <div class="info-value">
                        <?= h($auditoria['responsable_nombre'] ?: 'No definido') ?>
                        <?php if (!empty($auditoria['responsable_rol'])): ?>
                            <br><span class="info-sub"><?= h($auditoria['responsable_rol']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="summary-card">
                <div>
                    <div class="summary-label">Avance general</div>
                    <div class="summary-value"><?= $progresoGeneral ?>%</div>
                    <div class="summary-text">
                        <?= (int)$totalAvanceGeneral ?> de <?= (int)$totalEsperadoGeneral ?> productos esperados han sido trabajados entre serializados y no serializados.
                    </div>
                    <div class="progress">
                        <div class="progress-bar" style="width: <?= $progresoGeneral ?>%;"></div>
                    </div>
                </div>
                <div class="summary-foot">
                    Serializados: <?= (int)$totalEscaneados ?>/<?= (int)$totalSerializados ?> ·
                    No serializados: <?= (int)$totalCapturadosCant ?>/<?= (int)$totalNoSerializados ?>
                </div>
            </div>
        </div>
    </div>

    <div class="section-card">
        <h2 class="section-title">Indicadores de control</h2>
        <p class="section-subtitle">Resumen ejecutivo de faltantes, diferencias y costo estimado de impacto para esta auditoría.</p>

        <div class="exec-kpis">
            <div class="exec-kpi">
                <div class="label">Faltantes serializados</div>
                <div class="value red"><?= (int)$faltantesSerializados ?></div>
                <div class="hint">Equipos esperados no encontrados en el escaneo final.</div>
            </div>

            <div class="exec-kpi">
                <div class="label">Faltantes no serializados</div>
                <div class="value blue"><?= (int)$faltantesNoSerializados ?></div>
                <div class="hint">Piezas faltantes por diferencia negativa en el conteo por cantidad.</div>
            </div>

            <div class="exec-kpi">
                <div class="label">Incidencias registradas</div>
                <div class="value"><?= (int)$totalIncidencias ?></div>
                <div class="hint">Eventos documentados durante la ejecución de la auditoría.</div>
            </div>

            <div class="exec-kpi">
                <div class="label">Costo estimado faltante</div>
                <div class="value green"><?= h(fmtMoney($costoFaltanteTotal)) ?></div>
                <div class="hint">
                    Serializados: <?= h(fmtMoney($costoFaltanteSerializados)) ?><br>
                    No serializados: <?= h(fmtMoney($costoFaltanteNoSerializados)) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="modules">
        <div class="module-card <?= $auditoriaCerrada ? 'readonly' : '' ?>">
            <div class="module-head">
                <h2 class="module-title">Escaneo de equipos serializados</h2>
                <span class="module-chip <?= $auditoriaCerrada ? 'readonly' : 'dark' ?>">
                    <?= $auditoriaCerrada ? 'Consulta final' : 'IMEI / Serie' ?>
                </span>
            </div>

            <p class="module-text"><?= h($txtModuloSerial) ?></p>

            <div class="kpis">
                <div class="kpi">
                    <div class="kpi-label">Esperados</div>
                    <div class="kpi-value"><?= (int)$totalSerializados ?></div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">Escaneados</div>
                    <div class="kpi-value green"><?= (int)$totalEscaneados ?></div>
                </div>
            </div>

            <div class="mini-progress-wrap">
                <div class="mini-progress-top">
                    <span class="mini-progress-label">
                        <?= $auditoriaCerrada ? 'Resultado registrado' : 'Progreso del módulo' ?>
                    </span>
                    <span class="mini-progress-percent"><?= $progresoSerializados ?>%</span>
                </div>
                <div class="mini-progress">
                    <div class="mini-progress-bar" style="width: <?= $progresoSerializados ?>%;"></div>
                </div>
            </div>

            <div class="actions">
                <a href="auditorias_escanear.php?id=<?= (int)$auditoria['id'] ?>"
                   class="btn <?= $auditoriaCerrada ? 'btn-soft' : 'btn-primary' ?>">
                    <?= h($txtBtnEscaneo) ?>
                </a>
            </div>
        </div>

        <div class="module-card accent-blue <?= $auditoriaCerrada ? 'readonly' : '' ?>">
            <div class="module-head">
                <h2 class="module-title">Captura de productos no serializados</h2>
                <span class="module-chip <?= $auditoriaCerrada ? 'readonly' : '' ?>">
                    <?= $auditoriaCerrada ? 'Consulta final' : 'Conteo por cantidad' ?>
                </span>
            </div>

            <p class="module-text"><?= h($txtModuloCant) ?></p>

            <div class="kpis">
                <div class="kpi">
                    <div class="kpi-label">Productos esperados</div>
                    <div class="kpi-value"><?= (int)$totalNoSerializados ?></div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">Productos contados</div>
                    <div class="kpi-value blue"><?= (int)$totalCapturadosCant ?></div>
                </div>
            </div>

            <div class="mini-progress-wrap">
                <div class="mini-progress-top">
                    <span class="mini-progress-label">
                        <?= $auditoriaCerrada ? 'Resultado registrado' : 'Progreso del módulo' ?>
                    </span>
                    <span class="mini-progress-percent"><?= $progresoNoSerializados ?>%</span>
                </div>
                <div class="mini-progress">
                    <div class="mini-progress-bar blue" style="width: <?= $progresoNoSerializados ?>%;"></div>
                </div>
            </div>

            <div class="actions">
                <a href="auditorias_captura.php?id=<?= (int)$auditoria['id'] ?>"
                   class="btn <?= $auditoriaCerrada ? 'btn-soft blue' : 'btn-primary blue' ?>">
                    <?= h($txtBtnCant) ?>
                </a>
            </div>
        </div>
    </div>

    <div class="section-card">
        <h2 class="section-title">Cierre y conciliación</h2>
        <p class="section-subtitle">Cuando ya hayas concluido escaneo y conteo, continúa a la vista de conciliación para revisar resultados y cerrar la auditoría.</p>

        <div class="actions">
            <button type="button" class="btn btn-primary" onclick="abrirModalConciliacion()">Ir a conciliación</button>
            <?php if ($auditoriaCerrada): ?>
                <a href="generar_acta_auditoria.php?id=<?= (int)$auditoria['id'] ?>" class="btn btn-soft" target="_blank">Ver acta</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="back-row">
        <a href="auditorias_historial.php" class="btn-back">← Volver al historial</a>
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
            <button type="button" class="btn btn-soft" onclick="cerrarModalConciliacion()">Cancelar</button>
            <a href="auditorias_conciliar.php?id=<?= (int)$auditoria['id'] ?>" class="btn btn-primary">Continuar a conciliación</a>
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
