<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

$miRol = trim((string)($_SESSION['rol'] ?? ''));
if (!in_array($miRol, ['Admin', 'Gerente'], true)) {
    http_response_code(403);
    exit('Sin permiso para acceder al panel de vacaciones.');
}

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/navbar.php')) {
    require_once __DIR__ . '/navbar.php';
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");
date_default_timezone_set('America/Mexico_City');

/* =========================================================
   Helpers
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function empty_date($v): bool {
    return empty($v) || $v === '0000-00-00';
}

function fmt_date($v): string {
    if (empty_date($v)) return '—';
    $ts = strtotime((string)$v);
    return $ts ? date('d/m/Y', $ts) : '—';
}

function fmt_datetime($v): string {
    if (empty_date($v)) return '—';
    $ts = strtotime((string)$v);
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}

function dias_rango(?string $inicio, ?string $fin): int {
    if (empty_date($inicio) || empty_date($fin)) return 0;
    try {
        $a = new DateTime($inicio);
        $b = new DateTime($fin);
        return (int)$a->diff($b)->days + 1;
    } catch (Throwable $e) {
        return 0;
    }
}

function badge_status_class(string $status): string {
    $s = mb_strtolower(trim($status));
    return match ($s) {
        'aprobado'  => 'success',
        'rechazado' => 'danger',
        default     => 'warning',
    };
}

function table_exists(mysqli $conn, string $table): bool {
    $t = $conn->real_escape_string($table);
    $rs = $conn->query("SHOW TABLES LIKE '{$t}'");
    return $rs && $rs->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $rs = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return $rs && $rs->num_rows > 0;
}

/* =========================================================
   Validaciones base
========================================================= */
if (!table_exists($conn, 'vacaciones_solicitudes')) {
    exit('No existe la tabla vacaciones_solicitudes.');
}

/* =========================================================
   Compatibilidad de columnas
========================================================= */
$statusAdminCol = column_exists($conn, 'vacaciones_solicitudes', 'status_admin') ? 'status_admin' : 'status';
$statusJefeCol  = column_exists($conn, 'vacaciones_solicitudes', 'status_jefe') ? 'status_jefe' : null;

$comentarioCol = column_exists($conn, 'vacaciones_solicitudes', 'comentario')
    ? 'comentario'
    : (column_exists($conn, 'vacaciones_solicitudes', 'motivo') ? 'motivo' : null);

$creadoEnCol = column_exists($conn, 'vacaciones_solicitudes', 'creado_en')
    ? 'creado_en'
    : (column_exists($conn, 'vacaciones_solicitudes', 'created_at') ? 'created_at' : null);

$aprobadoPorCol = column_exists($conn, 'vacaciones_solicitudes', 'aprobado_admin_por')
    ? 'aprobado_admin_por'
    : (column_exists($conn, 'vacaciones_solicitudes', 'resuelto_por')
        ? 'resuelto_por'
        : (column_exists($conn, 'vacaciones_solicitudes', 'aprobado_por') ? 'aprobado_por' : null));

$aprobadoEnCol = column_exists($conn, 'vacaciones_solicitudes', 'aprobado_admin_en')
    ? 'aprobado_admin_en'
    : (column_exists($conn, 'vacaciones_solicitudes', 'resuelto_en')
        ? 'resuelto_en'
        : (column_exists($conn, 'vacaciones_solicitudes', 'aprobado_en') ? 'aprobado_en' : null));

$comentResCol = column_exists($conn, 'vacaciones_solicitudes', 'comentario_resolucion')
    ? 'comentario_resolucion'
    : (column_exists($conn, 'vacaciones_solicitudes', 'comentario_admin')
        ? 'comentario_admin'
        : null);

$idSucursalCol = column_exists($conn, 'vacaciones_solicitudes', 'id_sucursal') ? 'id_sucursal' : null;

/* =========================================================
   Flash
========================================================= */
$flashOk = trim((string)($_GET['ok'] ?? ''));
$flashError = trim((string)($_GET['err'] ?? ''));

/* =========================================================
   Filtros
========================================================= */
$q            = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'Pendiente'));
$sucursalId   = (int)($_GET['sucursal_id'] ?? 0);
$fechaDesde   = trim((string)($_GET['fecha_desde'] ?? ''));
$fechaHasta   = trim((string)($_GET['fecha_hasta'] ?? ''));

$statusesValidos = ['Todos', 'Pendiente', 'Aprobado', 'Rechazado'];
if (!in_array($statusFilter, $statusesValidos, true)) {
    $statusFilter = 'Pendiente';
}

if ($fechaDesde !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaDesde)) {
    $fechaDesde = '';
}
if ($fechaHasta !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaHasta)) {
    $fechaHasta = '';
}

/* =========================================================
   Sucursales
========================================================= */
$sucursales = [];
$rsSuc = $conn->query("
    SELECT id, nombre
    FROM sucursales
    WHERE (subtipo IS NULL OR subtipo NOT IN ('Subdistribuidor','Master Admin'))
    ORDER BY nombre ASC
");
while ($r = $rsSuc->fetch_assoc()) {
    $sucursales[] = $r;
}

/* =========================================================
   KPIs globales
========================================================= */
$kpis = [
    'pendientes'      => 0,
    'aprobadas'       => 0,
    'rechazadas'      => 0,
    'dias_aprobados'  => 0,
    'proximas_inicio' => 0,
];

$sqlKPIs = "
    SELECT
        SUM(CASE WHEN vs.`{$statusAdminCol}` = 'Pendiente' THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN vs.`{$statusAdminCol}` = 'Aprobado' THEN 1 ELSE 0 END) AS aprobadas,
        SUM(CASE WHEN vs.`{$statusAdminCol}` = 'Rechazado' THEN 1 ELSE 0 END) AS rechazadas
    FROM vacaciones_solicitudes vs
";
$rowK = $conn->query($sqlKPIs)->fetch_assoc();
if ($rowK) {
    $kpis['pendientes'] = (int)($rowK['pendientes'] ?? 0);
    $kpis['aprobadas'] = (int)($rowK['aprobadas'] ?? 0);
    $kpis['rechazadas'] = (int)($rowK['rechazadas'] ?? 0);
}

$sqlDiasAprob = "
    SELECT fecha_inicio, fecha_fin
    FROM vacaciones_solicitudes
    WHERE `{$statusAdminCol}` = 'Aprobado'
";
$rsDias = $conn->query($sqlDiasAprob);
while ($r = $rsDias->fetch_assoc()) {
    $kpis['dias_aprobados'] += dias_rango($r['fecha_inicio'] ?? null, $r['fecha_fin'] ?? null);
}

$hoy = date('Y-m-d');
$sqlProx = "
    SELECT COUNT(*) AS total
    FROM vacaciones_solicitudes
    WHERE `{$statusAdminCol}` = 'Aprobado'
      AND fecha_inicio >= '{$conn->real_escape_string($hoy)}'
";
$rowPx = $conn->query($sqlProx)->fetch_assoc();
$kpis['proximas_inicio'] = (int)($rowPx['total'] ?? 0);

/* =========================================================
   Consulta principal con nombre del aprobador/resolvedor
========================================================= */
$params = [];
$types  = '';

$sql = "
    SELECT
        vs.id,
        vs.id_usuario,
        ".($idSucursalCol ? "vs.`{$idSucursalCol}`" : "u.id_sucursal")." AS id_sucursal,
        vs.fecha_inicio,
        vs.fecha_fin,
        vs.dias,
        ".($comentarioCol ? "vs.`{$comentarioCol}`" : "NULL")." AS comentario,
        vs.`{$statusAdminCol}` AS status_admin,
        ".($statusJefeCol ? "vs.`{$statusJefeCol}`" : "NULL")." AS status_jefe,
        ".($creadoEnCol ? "vs.`{$creadoEnCol}`" : "NULL")." AS creado_en,
        ".($aprobadoPorCol ? "vs.`{$aprobadoPorCol}`" : "NULL")." AS aprobado_por,
        ".($aprobadoEnCol ? "vs.`{$aprobadoEnCol}`" : "NULL")." AS aprobado_en,
        ".($comentResCol ? "vs.`{$comentResCol}`" : "NULL")." AS comentario_resolucion,

        u.nombre AS usuario_nombre,
        u.usuario,
        u.correo,
        u.rol,
        u.activo,

        s.nombre AS sucursal_nombre,
        s.zona AS sucursal_zona,

        ur.nombre AS resuelto_por_nombre,
        ur.usuario AS resuelto_por_usuario
    FROM vacaciones_solicitudes vs
    INNER JOIN usuarios u ON u.id = vs.id_usuario
    LEFT JOIN sucursales s ON s.id = ".($idSucursalCol ? "vs.`{$idSucursalCol}`" : "u.id_sucursal")."
    ".($aprobadoPorCol ? "LEFT JOIN usuarios ur ON ur.id = vs.`{$aprobadoPorCol}`" : "LEFT JOIN usuarios ur ON 1=0")."
    WHERE 1=1
";

if ($statusFilter !== 'Todos') {
    $sql .= " AND vs.`{$statusAdminCol}` = ? ";
    $types .= 's';
    $params[] = $statusFilter;
}

if ($sucursalId > 0) {
    $sql .= " AND ".($idSucursalCol ? "vs.`{$idSucursalCol}`" : "u.id_sucursal")." = ? ";
    $types .= 'i';
    $params[] = $sucursalId;
}

if ($fechaDesde !== '') {
    $sql .= " AND vs.fecha_inicio >= ? ";
    $types .= 's';
    $params[] = $fechaDesde;
}

if ($fechaHasta !== '') {
    $sql .= " AND vs.fecha_fin <= ? ";
    $types .= 's';
    $params[] = $fechaHasta;
}

if ($q !== '') {
    $like = '%' . $q . '%';
    $sql .= " AND (
        u.nombre LIKE ?
        OR u.usuario LIKE ?
        OR u.correo LIKE ?
        OR u.rol LIKE ?
        OR s.nombre LIKE ?
    ) ";
    $types .= 'sssss';
    array_push($params, $like, $like, $like, $like, $like);
}

$sql .= " ORDER BY
            CASE
                WHEN vs.`{$statusAdminCol}` = 'Pendiente' THEN 1
                WHEN vs.`{$statusAdminCol}` = 'Aprobado' THEN 2
                WHEN vs.`{$statusAdminCol}` = 'Rechazado' THEN 3
                ELSE 4
            END,
            vs.fecha_inicio ASC,
            vs.id DESC
";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rs = $stmt->get_result();

$solicitudes = [];
while ($r = $rs->fetch_assoc()) {
    $r['dias_calc'] = !empty($r['dias']) ? (int)$r['dias'] : dias_rango($r['fecha_inicio'] ?? null, $r['fecha_fin'] ?? null);
    $solicitudes[] = $r;
}
$stmt->close();

/* =========================================================
   Separación por bloques
========================================================= */
$pendientes = [];
$historico = [];

foreach ($solicitudes as $s) {
    if (($s['status_admin'] ?? '') === 'Pendiente') {
        $pendientes[] = $s;
    } else {
        $historico[] = $s;
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Panel de Vacaciones</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    :root{
        --bg:#f5f7fb;
        --card:#ffffff;
        --line:#e6ebf2;
        --text:#18212b;
        --muted:#6b7280;
        --primary:#2563eb;
        --primary-soft:#dbeafe;
        --success:#15803d;
        --success-soft:#dcfce7;
        --warning:#b45309;
        --warning-soft:#fef3c7;
        --danger:#b91c1c;
        --danger-soft:#fee2e2;
        --radius:20px;
        --shadow:0 10px 28px rgba(15,23,42,.06);
    }

    *{box-sizing:border-box}

    body{
        margin:0;
        font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
        background:linear-gradient(180deg,#f8fbff 0%, #f3f6fb 100%);
        color:var(--text);
    }

    .wrap{
        max-width:1400px;
        margin:18px auto 40px;
        padding:0 14px;
    }

    .hero{
        background:linear-gradient(135deg,#f8fafc 0%, #eef2ff 55%, #e0e7ff 100%);
        color:#111827;
        border:1px solid #dbeafe;
        border-radius:24px;
        padding:24px;
        box-shadow:var(--shadow);
        margin-bottom:18px;
    }

    .hero-grid{
        display:grid;
        grid-template-columns:1.2fr .8fr;
        gap:18px;
        align-items:center;
    }

    .hero h1{
        margin:0 0 8px;
        font-size:30px;
        line-height:1.05;
    }

    .hero .meta{
        color:#4b5563;
        font-size:14px;
        margin-bottom:12px;
        max-width:820px;
    }

    .pill{
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:6px 10px;
        border-radius:999px;
        font-size:12px;
        font-weight:700;
        border:1px solid transparent;
        margin:2px 6px 2px 0;
        white-space:nowrap;
    }

    .pill.light{
        background:#ffffff;
        color:#1f2937;
        border-color:#d1d5db;
    }

    .pill.success{
        color:var(--success);
        background:var(--success-soft);
        border-color:#bbf7d0;
    }

    .pill.warning{
        color:var(--warning);
        background:var(--warning-soft);
        border-color:#fde68a;
    }

    .pill.danger{
        color:var(--danger);
        background:var(--danger-soft);
        border-color:#fecaca;
    }

    .pill.primary{
        color:#1d4ed8;
        background:var(--primary-soft);
        border-color:#bfdbfe;
    }

    .hero-side{
        background:rgba(255,255,255,.75);
        border:1px solid #dbeafe;
        border-radius:20px;
        padding:18px;
    }

    .hero-side .big{
        font-size:34px;
        line-height:1;
        font-weight:900;
        color:#111827;
        margin-bottom:6px;
    }

    .progress{
        width:100%;
        height:14px;
        background:#dbeafe;
        border-radius:999px;
        overflow:hidden;
        margin:8px 0 10px;
    }

    .progress > span{
        display:block;
        height:100%;
        background:linear-gradient(90deg,#3b82f6,#60a5fa);
        border-radius:999px;
    }

    .hero-kpis{
        display:grid;
        grid-template-columns:repeat(3,minmax(0,1fr));
        gap:10px;
        margin-top:14px;
    }

    .mini-kpi{
        background:rgba(255,255,255,.8);
        border:1px solid #dbeafe;
        border-radius:16px;
        padding:12px;
    }

    .mini-kpi .label{
        font-size:12px;
        color:#6b7280;
        margin-bottom:5px;
    }

    .mini-kpi .value{
        font-size:22px;
        font-weight:800;
        color:#111827;
    }

    .toolbar{
        display:flex;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
        margin-bottom:18px;
    }

    .grid-kpi{
        display:grid;
        grid-template-columns:repeat(5,minmax(0,1fr));
        gap:14px;
        margin-bottom:18px;
    }

    .card{
        background:var(--card);
        border:1px solid var(--line);
        border-radius:var(--radius);
        box-shadow:var(--shadow);
        overflow:hidden;
        margin-bottom:18px;
    }

    .kpi{
        padding:18px;
    }

    .kpi .label{
        font-size:13px;
        color:var(--muted);
        margin-bottom:10px;
    }

    .kpi .value{
        font-size:30px;
        font-weight:800;
        letter-spacing:-.02em;
        margin-bottom:8px;
    }

    .kpi .sub{
        font-size:12px;
        color:var(--muted);
    }

    .section{
        padding:18px;
    }

    .section h2{
        margin:0 0 14px;
        font-size:18px;
    }

    .filters-grid{
        display:grid;
        grid-template-columns:2fr 1fr 1fr 1fr 1fr auto;
        gap:12px;
        align-items:end;
    }

    .field label{
        display:block;
        font-size:12px;
        color:var(--muted);
        margin-bottom:6px;
        font-weight:600;
    }

    .field input,
    .field select,
    .field textarea{
        width:100%;
        border:1px solid #d9e1ea;
        background:#fff;
        border-radius:14px;
        padding:11px 12px;
        font-size:14px;
        outline:none;
    }

    .field input:focus,
    .field select:focus,
    .field textarea:focus{
        border-color:#93c5fd;
        box-shadow:0 0 0 4px rgba(59,130,246,.12);
    }

    .btn{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        min-height:42px;
        padding:0 16px;
        border-radius:14px;
        border:1px solid transparent;
        cursor:pointer;
        text-decoration:none;
        font-weight:700;
        font-size:14px;
        transition:.18s ease;
        background:#fff;
        color:#111827;
    }

    .btn:hover{
        background:#f8fafc;
    }

    .btn-primary{
        background:var(--primary);
        color:#fff;
        border-color:var(--primary);
    }

    .btn-primary:hover{
        filter:brightness(.98);
        transform:translateY(-1px);
    }

    .btn-success{
        background:var(--success);
        border-color:var(--success);
        color:#fff;
    }

    .btn-success:hover{
        filter:brightness(.98);
    }

    .btn-danger{
        background:var(--danger);
        border-color:var(--danger);
        color:#fff;
    }

    .btn-danger:hover{
        filter:brightness(.98);
    }

    .btn-light{
        background:#fff;
        border-color:#d8e1ec;
        color:#111827;
    }

    .btn-secondary{
        background:#475569;
        color:#fff;
        border-color:#475569;
    }

    .alert{
        border-radius:16px;
        padding:14px 16px;
        margin-bottom:14px;
        border:1px solid transparent;
        font-size:14px;
    }

    .alert.ok{
        background:#ecfdf5;
        border-color:#bbf7d0;
        color:#166534;
    }

    .alert.error{
        background:#fef2f2;
        border-color:#fecaca;
        color:#991b1b;
    }

    .table-wrap{
        overflow:auto;
        border-radius:18px;
    }

    table{
        width:100%;
        border-collapse:separate;
        border-spacing:0;
    }

    th, td{
        padding:14px 12px;
        border-bottom:1px solid #eef2f7;
        vertical-align:top;
        text-align:left;
    }

    th{
        font-size:12px;
        text-transform:uppercase;
        letter-spacing:.04em;
        color:#64748b;
        background:#eef2ff;
        position:sticky;
        top:0;
        z-index:1;
    }

    tr:hover td{
        background:#fbfdff;
    }

    .user-cell{
        display:flex;
        flex-direction:column;
        gap:2px;
    }

    .user-name{
        font-weight:800;
    }

    .muted{
        color:var(--muted);
        font-size:12px;
    }

    .actions{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
    }

    .actions .btn{
        min-height:38px;
        padding:0 12px;
        font-size:13px;
        border-radius:12px;
    }

    .empty{
        padding:24px;
        text-align:center;
        color:var(--muted);
    }

    .modal{
        position:fixed;
        inset:0;
        background:rgba(15,23,42,.45);
        display:none;
        align-items:center;
        justify-content:center;
        padding:18px;
        z-index:9999;
    }

    .modal.show{
        display:flex;
    }

    .modal-card{
        width:min(720px, 100%);
        background:#fff;
        border:1px solid #e5e7eb;
        border-radius:22px;
        box-shadow:0 20px 40px rgba(15,23,42,.18);
        overflow:hidden;
    }

    .modal-head{
        padding:18px 20px;
        border-bottom:1px solid #eef2f7;
        background:#fbfdff;
        display:flex;
        justify-content:space-between;
        gap:10px;
        align-items:center;
    }

    .modal-head h3{
        margin:0;
        font-size:18px;
    }

    .modal-close{
        border:0;
        background:#fff;
        width:38px;
        height:38px;
        border-radius:12px;
        cursor:pointer;
        font-size:18px;
    }

    .modal-body{
        padding:20px;
    }

    .modal-foot{
        padding:18px 20px;
        border-top:1px solid #eef2f7;
        display:flex;
        justify-content:flex-end;
        gap:10px;
        flex-wrap:wrap;
        background:#fbfdff;
    }

    .detail-grid{
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:12px;
        margin-bottom:16px;
    }

    .detail-item{
        border:1px solid #edf2f7;
        border-radius:16px;
        padding:12px 14px;
        background:#fcfdff;
    }

    .detail-item .label{
        font-size:12px;
        color:#6b7280;
        margin-bottom:4px;
    }

    .detail-item .value{
        font-size:15px;
        font-weight:700;
        color:#111827;
        word-break:break-word;
    }

    @media (max-width:1180px){
        .hero-grid,
        .grid-kpi{
            grid-template-columns:1fr 1fr;
        }
        .filters-grid{
            grid-template-columns:1fr 1fr;
        }
    }

    @media (max-width:760px){
        .hero-grid,
        .grid-kpi,
        .filters-grid,
        .hero-kpis,
        .detail-grid{
            grid-template-columns:1fr;
        }
        .hero h1{
            font-size:24px;
        }
    }
</style>
</head>
<body>
<div class="wrap">

    <section class="hero">
        <div class="hero-grid">
            <div>
                <h1>Panel de Vacaciones</h1>
                <div class="meta">
                    Vista central para revisar solicitudes, autorizar vacaciones, consultar histórico y mantener el control operativo sin cargar el módulo de asistencias.
                </div>
                <div>
                    <span class="pill light">Rol actual: <?= h($miRol) ?></span>
                    <span class="pill light">Solicitudes cargadas: <?= count($solicitudes) ?></span>
                    <span class="pill light">Pendientes: <?= count($pendientes) ?></span>
                </div>
            </div>

            <div class="hero-side">
                <div style="font-size:13px;color:#6b7280;">Solicitudes pendientes vs total visible</div>
                <?php
                    $totalVisible = max(count($solicitudes), 1);
                    $pctPend = (int)floor((count($pendientes) / $totalVisible) * 100);
                ?>
                <div class="big"><?= $pctPend ?>%</div>
                <div class="progress"><span style="width: <?= $pctPend ?>%"></span></div>
                <div style="font-size:13px;color:#6b7280;">
                    <?= count($pendientes) ?> pendiente(s) de <?= count($solicitudes) ?> solicitud(es) visibles
                </div>

                <div class="hero-kpis">
                    <div class="mini-kpi">
                        <div class="label">Pendientes</div>
                        <div class="value"><?= count($pendientes) ?></div>
                    </div>
                    <div class="mini-kpi">
                        <div class="label">Histórico</div>
                        <div class="value"><?= count($historico) ?></div>
                    </div>
                    <div class="mini-kpi">
                        <div class="label">Días aprobados</div>
                        <div class="value"><?= (int)$kpis['dias_aprobados'] ?></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="toolbar">
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="admin_asistencias.php" class="btn btn-light">← Volver a Panel de Asistencia</a>
        </div>

        
    </div>

    <?php if ($flashOk !== ''): ?>
        <div class="alert ok"><?= h($flashOk) ?></div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
        <div class="alert error"><?= h($flashError) ?></div>
    <?php endif; ?>

    <section class="grid-kpi">
        <article class="card kpi">
            <div class="label">Pendientes</div>
            <div class="value"><?= (int)$kpis['pendientes'] ?></div>
            <div class="sub">Solicitudes por resolver</div>
        </article>

        <article class="card kpi">
            <div class="label">Aprobadas</div>
            <div class="value"><?= (int)$kpis['aprobadas'] ?></div>
            <div class="sub">Vacaciones autorizadas</div>
        </article>

        <article class="card kpi">
            <div class="label">Rechazadas</div>
            <div class="value"><?= (int)$kpis['rechazadas'] ?></div>
            <div class="sub">Solicitudes no autorizadas</div>
        </article>

        <article class="card kpi">
            <div class="label">Días aprobados</div>
            <div class="value"><?= (int)$kpis['dias_aprobados'] ?></div>
            <div class="sub">Suma de días autorizados</div>
        </article>

        <article class="card kpi">
            <div class="label">Próximas a iniciar</div>
            <div class="value"><?= (int)$kpis['proximas_inicio'] ?></div>
            <div class="sub">Con inicio desde hoy</div>
        </article>
    </section>

    <section class="card section">
        <h2>Filtros</h2>
        <form method="get" class="filters-grid">
            <div class="field">
                <label>Buscar</label>
                <input type="text" name="q" value="<?= h($q) ?>" placeholder="Nombre, usuario, correo, rol o sucursal">
            </div>

            <div class="field">
                <label>Estatus</label>
                <select name="status">
                    <?php foreach ($statusesValidos as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= $statusFilter === $opt ? 'selected' : '' ?>>
                            <?= h($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Sucursal</label>
                <select name="sucursal_id">
                    <option value="0">Todas</option>
                    <?php foreach ($sucursales as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $sucursalId === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= h($s['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Fecha desde</label>
                <input type="date" name="fecha_desde" value="<?= h($fechaDesde) ?>">
            </div>

            <div class="field">
                <label>Fecha hasta</label>
                <input type="date" name="fecha_hasta" value="<?= h($fechaHasta) ?>">
            </div>

            <div class="field">
                <label>&nbsp;</label>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">Aplicar</button>
                    <a href="vacaciones_panel.php" class="btn btn-light">Limpiar</a>
                </div>
            </div>
        </form>
    </section>

    <section class="card section">
        <h2>Solicitudes pendientes</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="min-width:220px;">Colaborador</th>
                        <th style="min-width:150px;">Sucursal</th>
                        <th style="min-width:170px;">Rango</th>
                        <th style="min-width:100px;">Días</th>
                        <th style="min-width:180px;">Creada</th>
                        <th style="min-width:220px;">Comentario</th>
                        <th style="min-width:240px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$pendientes): ?>
                    <tr>
                        <td colspan="7" class="empty">No hay solicitudes pendientes con los filtros actuales.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pendientes as $s): ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="user-name"><?= h($s['usuario_nombre']) ?></div>
                                    <div class="muted">@<?= h($s['usuario']) ?> · <?= h($s['correo'] ?: 'Sin correo') ?></div>
                                    <div style="margin-top:4px;">
                                        <span class="pill primary"><?= h($s['rol'] ?: '—') ?></span>
                                        <?php if (($s['activo'] ?? 0) == 1): ?>
                                            <span class="pill success">Activo</span>
                                        <?php else: ?>
                                            <span class="pill danger">Inactivo</span>
                                        <?php endif; ?>
                                        <?php if (!empty($s['status_jefe'])): ?>
                                            <span class="pill warning">Jefe: <?= h($s['status_jefe']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <strong><?= h($s['sucursal_nombre'] ?: '—') ?></strong><br>
                                <span class="muted"><?= h($s['sucursal_zona'] ?: 'Sin zona') ?></span>
                            </td>

                            <td>
                                <strong><?= h(fmt_date($s['fecha_inicio'])) ?></strong><br>
                                <span class="muted">al <?= h(fmt_date($s['fecha_fin'])) ?></span>
                            </td>

                            <td>
                                <span class="pill primary"><?= (int)$s['dias_calc'] ?> día(s)</span>
                            </td>

                            <td>
                                <?= h(fmt_date($s['creado_en'] ?? null)) ?><br>
                                <span class="muted"><?= h(fmt_datetime($s['creado_en'] ?? null)) ?></span>
                            </td>

                            <td>
                                <?= h($s['comentario'] ?: '—') ?>
                            </td>

                            <td>
                                <div class="actions">
                                    <button
                                        type="button"
                                        class="btn btn-success js-open-aprobar"
                                        data-id="<?= (int)$s['id'] ?>"
                                        data-usuario="<?= h($s['usuario_nombre']) ?>"
                                        data-sucursal="<?= h($s['sucursal_nombre'] ?: '—') ?>"
                                        data-rango="<?= h(fmt_date($s['fecha_inicio'])) ?> al <?= h(fmt_date($s['fecha_fin'])) ?>"
                                        data-dias="<?= (int)$s['dias_calc'] ?>"
                                        data-comentario="<?= h($s['comentario'] ?: '—') ?>"
                                    >
                                        Aprobar
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-danger js-open-rechazar"
                                        data-id="<?= (int)$s['id'] ?>"
                                        data-usuario="<?= h($s['usuario_nombre']) ?>"
                                        data-sucursal="<?= h($s['sucursal_nombre'] ?: '—') ?>"
                                        data-rango="<?= h(fmt_date($s['fecha_inicio'])) ?> al <?= h(fmt_date($s['fecha_fin'])) ?>"
                                        data-dias="<?= (int)$s['dias_calc'] ?>"
                                        data-comentario="<?= h($s['comentario'] ?: '—') ?>"
                                    >
                                        Rechazar
                                    </button>

                                    <a class="btn btn-light" target="_blank" href="generar_formato_vacaciones.php?usuario_id=<?= (int)$s['id_usuario'] ?>">
                                        Formato PDF
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card section">
        <h2>Histórico</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="min-width:220px;">Colaborador</th>
                        <th style="min-width:150px;">Sucursal</th>
                        <th style="min-width:170px;">Rango</th>
                        <th style="min-width:100px;">Días</th>
                        <th style="min-width:120px;">Estatus</th>
                        <th style="min-width:220px;">Resuelto por</th>
                        <th style="min-width:180px;">Fecha resolución</th>
                        <th style="min-width:240px;">Observación</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$historico): ?>
                    <tr>
                        <td colspan="8" class="empty">No hay histórico con los filtros actuales.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($historico as $s): ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="user-name"><?= h($s['usuario_nombre']) ?></div>
                                    <div class="muted">@<?= h($s['usuario']) ?> · <?= h($s['correo'] ?: 'Sin correo') ?></div>
                                    <div style="margin-top:4px;">
                                        <span class="pill primary"><?= h($s['rol'] ?: '—') ?></span>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <strong><?= h($s['sucursal_nombre'] ?: '—') ?></strong><br>
                                <span class="muted"><?= h($s['sucursal_zona'] ?: 'Sin zona') ?></span>
                            </td>

                            <td>
                                <strong><?= h(fmt_date($s['fecha_inicio'])) ?></strong><br>
                                <span class="muted">al <?= h(fmt_date($s['fecha_fin'])) ?></span>
                            </td>

                            <td>
                                <span class="pill primary"><?= (int)$s['dias_calc'] ?> día(s)</span>
                            </td>

                            <td>
                                <span class="pill <?= h(badge_status_class((string)$s['status_admin'])) ?>">
                                    <?= h($s['status_admin']) ?>
                                </span>
                            </td>

                            <td>
                                <strong><?= h($s['resuelto_por_nombre'] ?: '—') ?></strong><br>
                                <span class="muted">
                                    <?= !empty($s['resuelto_por_usuario']) ? '@' . h($s['resuelto_por_usuario']) : (!empty($s['aprobado_por']) ? 'ID '.$s['aprobado_por'] : '—') ?>
                                </span>
                            </td>

                            <td>
                                <?= h(fmt_date($s['aprobado_en'] ?? null)) ?><br>
                                <span class="muted"><?= h(fmt_datetime($s['aprobado_en'] ?? null)) ?></span>
                            </td>

                            <td>
                                <?= h($s['comentario_resolucion'] ?: '—') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</div>

<!-- MODAL APROBAR -->
<div class="modal" id="modalAprobar" aria-hidden="true">
    <div class="modal-card">
        <div class="modal-head">
            <h3>Aprobar solicitud</h3>
            <button type="button" class="modal-close" data-close-modal="modalAprobar">✕</button>
        </div>

        <form action="vacaciones_aprobar.php" method="post">
            <div class="modal-body">
                <input type="hidden" name="id" id="aprobar_id">

                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="label">Colaborador</div>
                        <div class="value" id="aprobar_usuario">—</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Sucursal</div>
                        <div class="value" id="aprobar_sucursal">—</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Rango</div>
                        <div class="value" id="aprobar_rango">—</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Días</div>
                        <div class="value" id="aprobar_dias">—</div>
                    </div>
                </div>

                <div class="field">
                    <label>Comentario de la solicitud</label>
                    <textarea id="aprobar_comentario_solicitud" readonly></textarea>
                </div>

                <div class="field">
                    <label>Comentario de aprobación</label>
                    <textarea name="comentario" placeholder="Ej. Aprobado por cobertura operativa, validado con sucursal, etc."></textarea>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-light" data-close-modal="modalAprobar">Cancelar</button>
                <button type="submit" class="btn btn-success">Confirmar aprobación</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL RECHAZAR -->
<div class="modal" id="modalRechazar" aria-hidden="true">
    <div class="modal-card">
        <div class="modal-head">
            <h3>Rechazar solicitud</h3>
            <button type="button" class="modal-close" data-close-modal="modalRechazar">✕</button>
        </div>

        <form action="vacaciones_rechazar.php" method="post">
            <div class="modal-body">
                <input type="hidden" name="id" id="rechazar_id">

                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="label">Colaborador</div>
                        <div class="value" id="rechazar_usuario">—</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Sucursal</div>
                        <div class="value" id="rechazar_sucursal">—</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Rango</div>
                        <div class="value" id="rechazar_rango">—</div>
                    </div>
                    <div class="detail-item">
                        <div class="label">Días</div>
                        <div class="value" id="rechazar_dias">—</div>
                    </div>
                </div>

                <div class="field">
                    <label>Comentario de la solicitud</label>
                    <textarea id="rechazar_comentario_solicitud" readonly></textarea>
                </div>

                <div class="field">
                    <label>Motivo / comentario de rechazo</label>
                    <textarea name="comentario" placeholder="Ej. No hay cobertura operativa en esas fechas, coincide con otra ausencia, falta validación, etc."></textarea>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn btn-light" data-close-modal="modalRechazar">Cancelar</button>
                <button type="submit" class="btn btn-danger">Confirmar rechazo</button>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    document.querySelectorAll('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', function() {
            closeModal(this.getAttribute('data-close-modal'));
        });
    });

    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeModal(modal.id);
            }
        });
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(m => closeModal(m.id));
        }
    });

    document.querySelectorAll('.js-open-aprobar').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('aprobar_id').value = this.dataset.id || '';
            document.getElementById('aprobar_usuario').textContent = this.dataset.usuario || '—';
            document.getElementById('aprobar_sucursal').textContent = this.dataset.sucursal || '—';
            document.getElementById('aprobar_rango').textContent = this.dataset.rango || '—';
            document.getElementById('aprobar_dias').textContent = (this.dataset.dias || '0') + ' día(s)';
            document.getElementById('aprobar_comentario_solicitud').value = this.dataset.comentario || '—';
            openModal('modalAprobar');
        });
    });

    document.querySelectorAll('.js-open-rechazar').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('rechazar_id').value = this.dataset.id || '';
            document.getElementById('rechazar_usuario').textContent = this.dataset.usuario || '—';
            document.getElementById('rechazar_sucursal').textContent = this.dataset.sucursal || '—';
            document.getElementById('rechazar_rango').textContent = this.dataset.rango || '—';
            document.getElementById('rechazar_dias').textContent = (this.dataset.dias || '0') + ' día(s)';
            document.getElementById('rechazar_comentario_solicitud').value = this.dataset.comentario || '—';
            openModal('modalRechazar');
        });
    });
})();
</script>
</body>
</html>