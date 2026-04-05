<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

$mi_rol = $_SESSION['rol'] ?? 'Ejecutivo';
if (!in_array($mi_rol, ['Admin', 'Gerente','Logistica'], true)) {
    http_response_code(403);
    echo "Sin permiso.";
    exit;
}

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/includes/docs_lib.php')) {
    require_once __DIR__ . '/includes/docs_lib.php';
} elseif (file_exists(__DIR__ . '/docs_lib.php')) {
    require_once __DIR__ . '/docs_lib.php';
}
if (file_exists(__DIR__ . '/navbar.php')) {
    require_once __DIR__ . '/navbar.php';
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

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

function calc_antiguedad(?string $fechaIngreso, ?int $yearsGuardados = null, ?int $mesesGuardados = null): string {
    if (!empty_date($fechaIngreso)) {
        try {
            $inicio = new DateTime($fechaIngreso);
            $hoy    = new DateTime();
            $diff   = $inicio->diff($hoy);
            $years  = (int)$diff->y;
            $months = (int)$diff->m;
            return "{$years} año(s), {$months} mes(es)";
        } catch (Throwable $e) {
            // fallback a guardados
        }
    }

    $years  = (int)($yearsGuardados ?? 0);
    $months = (int)($mesesGuardados ?? 0);

    if ($years === 0 && $months === 0) return '—';
    return "{$years} año(s), {$months} mes(es)";
}

function get_field_label(string $field): string {
    $labels = [
        'tel_contacto'          => 'Teléfono',
        'fecha_nacimiento'      => 'Fecha nacimiento',
        'fecha_ingreso'         => 'Fecha ingreso',
        'curp'                  => 'CURP',
        'nss'                   => 'NSS',
        'rfc'                   => 'RFC',
        'genero'                => 'Género',
        'contacto_emergencia'   => 'Contacto emergencia',
        'tel_emergencia'        => 'Tel. emergencia',
        'clabe'                 => 'CLABE',
        'contrato_status'       => 'Contrato',
        'registro_patronal'     => 'Registro patronal',
        'fecha_alta_imss'       => 'Fecha alta IMSS',
        'talla_uniforme'        => 'Talla uniforme',
        'payjoy_status'         => 'PayJoy',
        'krediya_status'        => 'Krediya',
        'lespago_status'        => 'LesPago',
        'innovm_status'         => 'InnovM',
        'central_status'        => 'Central',
    ];
    return $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
}

function is_field_filled(string $field, array $exp): bool {
    $val = $exp[$field] ?? null;

    if (in_array($field, ['fecha_nacimiento', 'fecha_ingreso', 'fecha_alta_imss'], true)) {
        return !empty_date((string)$val);
    }

    return trim((string)$val) !== '';
}

function calc_progress_blocks(array $exp, array $docsForUser, array $requiredDocTypeIds): array {
    $bloques = [
        'personales' => [
            'fields' => ['tel_contacto', 'fecha_nacimiento', 'curp', 'nss', 'rfc', 'genero'],
            'done'   => 0,
            'total'  => 0,
            'pct'    => 0,
        ],
        'laborales' => [
            'fields' => ['fecha_ingreso', 'contrato_status', 'registro_patronal', 'fecha_alta_imss', 'clabe', 'talla_uniforme'],
            'done'   => 0,
            'total'  => 0,
            'pct'    => 0,
        ],
        'emergencia' => [
            'fields' => ['contacto_emergencia', 'tel_emergencia'],
            'done'   => 0,
            'total'  => 0,
            'pct'    => 0,
        ],
        'plataformas' => [
            'fields' => ['payjoy_status', 'krediya_status', 'lespago_status', 'innovm_status', 'central_status'],
            'done'   => 0,
            'total'  => 0,
            'pct'    => 0,
        ],
        'documentos' => [
            'fields' => [],
            'done'   => 0,
            'total'  => count($requiredDocTypeIds),
            'pct'    => 0,
        ],
    ];

    foreach ($bloques as $key => &$bloque) {
        if ($key === 'documentos') {
            $docsDone = 0;
            foreach ($requiredDocTypeIds as $tid) {
                if (!empty($docsForUser[$tid])) $docsDone++;
            }
            $bloque['done'] = $docsDone;
            $bloque['pct']  = $bloque['total'] > 0 ? (int)floor(($docsDone / $bloque['total']) * 100) : 100;
            continue;
        }

        $done = 0;
        $total = count($bloque['fields']);
        foreach ($bloque['fields'] as $field) {
            if (is_field_filled($field, $exp)) $done++;
        }
        $bloque['done'] = $done;
        $bloque['total'] = $total;
        $bloque['pct'] = $total > 0 ? (int)floor(($done / $total) * 100) : 100;
    }
    unset($bloque);

    $globalDone = 0;
    $globalTotal = 0;
    foreach ($bloques as $bloque) {
        $globalDone += (int)$bloque['done'];
        $globalTotal += (int)$bloque['total'];
    }

    $globalPct = $globalTotal > 0 ? (int)floor(($globalDone / $globalTotal) * 100) : 0;

    return [
        'blocks' => $bloques,
        'done'   => $globalDone,
        'total'  => $globalTotal,
        'pct'    => $globalPct,
    ];
}

function status_badge_class(int $pct): string {
    if ($pct >= 100) return 'success';
    if ($pct >= 70) return 'warning';
    return 'danger';
}

/* =========================================================
   Filtros
========================================================= */
$search         = trim($_GET['q'] ?? '');
$roleFilter     = trim($_GET['rol'] ?? 'Todos');
$statusFilter   = trim($_GET['status'] ?? 'Todos');
$sucursalFilter = (int)($_GET['sucursal'] ?? 0);
$activoFilter   = trim($_GET['activo'] ?? '1');

/* =========================================================
   Campos requeridos del expediente
========================================================= */
$requiredFieldsAll = [
    'tel_contacto',
    'fecha_nacimiento',
    'fecha_ingreso',
    'curp',
    'nss',
    'rfc',
    'genero',
    'contacto_emergencia',
    'tel_emergencia',
    'clabe',
    'contrato_status',
    'registro_patronal',
    'fecha_alta_imss',
    'talla_uniforme',
    'payjoy_status',
    'krediya_status',
    'lespago_status',
    'innovm_status',
    'central_status',
];

/* =========================================================
   Catálogo de sucursales
========================================================= */
$sucursales = [];
$sqlSuc = "SELECT id, nombre
           FROM sucursales
           WHERE (subtipo IS NULL OR subtipo NOT IN ('Subdistribuidor','Master Admin'))
           ORDER BY nombre ASC";
$rsSuc = $conn->query($sqlSuc);
while ($row = $rsSuc->fetch_assoc()) {
    $sucursales[] = $row;
}

/* =========================================================
   Tipos de documento requeridos
========================================================= */
$docTypesReq = [];
$docTypeIdsReq = [];
$sqlDocsReq = "SELECT id, codigo, nombre
               FROM doc_tipos
               WHERE requerido = 1
               ORDER BY nombre ASC";
$rsDocsReq = $conn->query($sqlDocsReq);
while ($row = $rsDocsReq->fetch_assoc()) {
    $tid = (int)$row['id'];
    $docTypesReq[$tid] = $row;
    $docTypeIdsReq[] = $tid;
}

/* =========================================================
   Consulta base de usuarios + expediente
========================================================= */
$sql = "
    SELECT
        u.id,
        u.nombre,
        u.usuario,
        u.correo,
        u.rol,
        u.activo,
        u.id_sucursal,
        s.nombre AS sucursal_nombre,
        s.zona   AS sucursal_zona,
        s.subtipo AS sucursal_subtipo,

        ue.usuario_id,
        ue.tel_contacto,
        ue.fecha_nacimiento,
        ue.fecha_ingreso,
        ue.fecha_baja,
        ue.motivo_baja,
        ue.curp,
        ue.nss,
        ue.rfc,
        ue.genero,
        ue.foto,
        ue.contacto_emergencia,
        ue.tel_emergencia,
        ue.clabe,
        ue.banco,
        ue.edad_years,
        ue.antiguedad_meses,
        ue.antiguedad_years,
        ue.contrato_status,
        ue.registro_patronal,
        ue.fecha_alta_imss,
        ue.talla_uniforme,
        ue.payjoy_status,
        ue.krediya_status,
        ue.lespago_status,
        ue.innovm_status,
        ue.central_status
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN usuarios_expediente ue ON ue.usuario_id = u.id
    WHERE 1=1
      AND (s.subtipo IS NULL OR s.subtipo NOT IN ('Subdistribuidor','Master Admin'))
";

$params = [];
$types  = '';

if ($roleFilter !== '' && $roleFilter !== 'Todos') {
    $sql .= " AND u.rol = ? ";
    $params[] = $roleFilter;
    $types .= 's';
}

if ($sucursalFilter > 0) {
    $sql .= " AND u.id_sucursal = ? ";
    $params[] = $sucursalFilter;
    $types .= 'i';
}

if ($activoFilter !== 'todos') {
    $activoVal = ($activoFilter === '0') ? 0 : 1;
    $sql .= " AND u.activo = ? ";
    $params[] = $activoVal;
    $types .= 'i';
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $sql .= " AND (
        u.nombre LIKE ?
        OR u.usuario LIKE ?
        OR u.correo LIKE ?
        OR s.nombre LIKE ?
        OR u.rol LIKE ?
    ) ";
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}

$sql .= " ORDER BY s.nombre IS NULL, s.nombre ASC, u.nombre ASC ";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$users = [];
$userIds = [];
while ($row = $res->fetch_assoc()) {
    $users[] = $row;
    $userIds[] = (int)$row['id'];
}
$stmt->close();

/* =========================================================
   Documentos vigentes requeridos por usuario
========================================================= */
$userDocs = [];
if (!empty($userIds) && !empty($docTypeIdsReq)) {
    $inU = implode(',', array_map('intval', $userIds));
    $inT = implode(',', array_map('intval', $docTypeIdsReq));

    $sqlUserDocs = "
        SELECT ud.id, ud.usuario_id, ud.doc_tipo_id
        FROM usuario_documentos ud
        WHERE ud.vigente = 1
          AND ud.usuario_id IN ($inU)
          AND ud.doc_tipo_id IN ($inT)
    ";
    $rsUserDocs = $conn->query($sqlUserDocs);
    while ($row = $rsUserDocs->fetch_assoc()) {
        $uid = (int)$row['usuario_id'];
        $tid = (int)$row['doc_tipo_id'];
        $userDocs[$uid][$tid] = (int)$row['id'];
    }
}

/* =========================================================
   Armado de filas + progreso
========================================================= */
$rows = [];
$totalUsers = 0;
$totalComplete = 0;
$totalPending = 0;
$totalAvgAccumulator = 0;
$totalMissingDocs = 0;

foreach ($users as $u) {
    $uid = (int)$u['id'];

    $exp = $u;
    $docsForUser = $userDocs[$uid] ?? [];

    $progress = calc_progress_blocks($exp, $docsForUser, $docTypeIdsReq);

    $missingFields = [];
    foreach ($requiredFieldsAll as $field) {
        if (!is_field_filled($field, $exp)) {
            $missingFields[] = get_field_label($field);
        }
    }

    $missingDocs = [];
    foreach ($docTypesReq as $tid => $dt) {
        if (empty($docsForUser[$tid])) {
            $missingDocs[] = $dt['nombre'];
        }
    }

    $pct = (int)$progress['pct'];
    $docDone = $progress['blocks']['documentos']['done'];
    $docTotal = $progress['blocks']['documentos']['total'];

    $rows[] = [
        'user'           => $u,
        'exp'            => $exp,
        'progress'       => $progress,
        'pct'            => $pct,
        'missingFields'  => $missingFields,
        'missingDocs'    => $missingDocs,
        'docDone'        => $docDone,
        'docTotal'       => $docTotal,
        'docs'           => $docsForUser,
    ];
}

if ($statusFilter !== 'Todos') {
    $rows = array_values(array_filter($rows, function ($r) use ($statusFilter) {
        if ($statusFilter === 'Completos') return (int)$r['pct'] === 100;
        if ($statusFilter === 'Pendientes') return (int)$r['pct'] < 100;
        return true;
    }));
}

/* =========================================================
   KPIs con rows filtradas
========================================================= */
$bySucursal = [];
foreach ($rows as $r) {
    $totalUsers++;
    $pct = (int)$r['pct'];
    $totalAvgAccumulator += $pct;

    if ($pct === 100) $totalComplete++;
    else $totalPending++;

    $totalMissingDocs += count($r['missingDocs']);

    $u = $r['user'];
    $sid = (int)($u['id_sucursal'] ?? 0);
    $sname = $u['sucursal_nombre'] ?: '—';
    $zona  = $u['sucursal_zona'] ?: '—';

    if (!isset($bySucursal[$sid])) {
        $bySucursal[$sid] = [
            'name'     => $sname,
            'zona'     => $zona,
            'users'    => 0,
            'sum_pct'  => 0,
            'complete' => 0,
        ];
    }

    $bySucursal[$sid]['users']++;
    $bySucursal[$sid]['sum_pct'] += $pct;
    if ($pct === 100) {
        $bySucursal[$sid]['complete']++;
    }
}

foreach ($bySucursal as &$s) {
    $s['avg'] = $s['users'] > 0 ? round($s['sum_pct'] / $s['users'], 1) : 0;
}
unset($s);

uasort($bySucursal, function ($a, $b) {
    if ($a['avg'] == $b['avg']) {
        return strcmp($a['name'], $b['name']);
    }
    return ($a['avg'] < $b['avg']) ? -1 : 1;
});

$avgGlobal = $totalUsers > 0 ? round($totalAvgAccumulator / $totalUsers, 1) : 0.0;
$maxMB = defined('DOCS_MAX_SIZE') ? (int)(DOCS_MAX_SIZE / 1024 / 1024) : 10;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Expedientes RH</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    :root{
        --bg:#f4f7fb;
        --card:#ffffff;
        --text:#16202a;
        --muted:#6b7280;
        --line:#e5e7eb;
        --primary:#1d4ed8;
        --primary-soft:#dbeafe;
        --success:#15803d;
        --success-soft:#dcfce7;
        --warning:#b45309;
        --warning-soft:#fef3c7;
        --danger:#b91c1c;
        --danger-soft:#fee2e2;
        --shadow:0 8px 24px rgba(15,23,42,.06);
        --radius:18px;
    }

    * { box-sizing:border-box; }

    body{
        margin:0;
        background:linear-gradient(180deg,#f8fbff 0%, #f3f6fa 100%);
        color:var(--text);
        font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
    }

    .wrap{
        max-width:1380px;
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

    .hero-top{
        display:flex;
        justify-content:space-between;
        gap:18px;
        align-items:flex-start;
        flex-wrap:wrap;
    }

    .hero h1{
        margin:0 0 8px;
        font-size:28px;
        line-height:1.1;
    }

    .hero p{
        margin:0;
        color:#4b5563;
        max-width:780px;
    }

    .hero-badges{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        margin-top:14px;
    }

    .hero-badge{
        background:#ffffff;
        border:1px solid #dbeafe;
        color:#1f2937;
        padding:8px 12px;
        border-radius:999px;
        font-size:13px;
    }

    .grid-kpi{
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:14px;
        margin-bottom:18px;
    }

    .card{
        background:var(--card);
        border:1px solid #ebeff5;
        border-radius:var(--radius);
        box-shadow:var(--shadow);
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
        margin-bottom:18px;
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
    .field select{
        width:100%;
        border:1px solid #d9e1ea;
        background:#fff;
        border-radius:14px;
        padding:11px 12px;
        font-size:14px;
        outline:none;
    }

    .field input:focus,
    .field select:focus{
        border-color:#93c5fd;
        box-shadow:0 0 0 4px rgba(59,130,246,.12);
    }

    .btn{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        min-height:44px;
        padding:0 16px;
        border-radius:14px;
        border:1px solid transparent;
        cursor:pointer;
        text-decoration:none;
        font-weight:700;
        font-size:14px;
        transition:.18s ease;
    }

    .btn-primary{
        background:var(--primary);
        color:#fff;
    }

    .btn-primary:hover{
        filter:brightness(.98);
        transform:translateY(-1px);
    }

    .btn-light{
        background:#fff;
        border-color:#d9e1ea;
        color:#111827;
    }

    .btn-light:hover{
        background:#f8fafc;
    }

    .summary-grid{
        display:grid;
        grid-template-columns:1.4fr .9fr;
        gap:18px;
        margin-bottom:18px;
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
        gap:12px;
        align-items:flex-start;
    }

    .avatar{
        width:46px;
        height:46px;
        border-radius:14px;
        background:linear-gradient(135deg,#dbeafe,#bfdbfe);
        color:#1d4ed8;
        display:flex;
        align-items:center;
        justify-content:center;
        font-weight:800;
        flex:0 0 46px;
        overflow:hidden;
        border:1px solid #cfe0ff;
    }

    .avatar img{
        width:100%;
        height:100%;
        object-fit:cover;
    }

    .user-name{
        font-weight:800;
        margin-bottom:2px;
    }

    .muted{
        color:var(--muted);
        font-size:12px;
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
        margin:2px 4px 2px 0;
        white-space:nowrap;
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

    .progress{
        width:100%;
        height:12px;
        background:#dbeafe;
        border-radius:999px;
        overflow:hidden;
        margin-bottom:8px;
    }

    .progress > span{
        display:block;
        height:100%;
        border-radius:999px;
        background:linear-gradient(90deg,#3b82f6,#60a5fa);
    }

    .mini-bars{
        display:grid;
        gap:8px;
        margin-top:10px;
    }

    .mini-item{
        display:grid;
        grid-template-columns:110px 1fr 46px;
        gap:8px;
        align-items:center;
        font-size:12px;
    }

    .mini-track{
        height:8px;
        background:#dbeafe;
        border-radius:999px;
        overflow:hidden;
    }

    .mini-track span{
        display:block;
        height:100%;
        background:linear-gradient(90deg,#60a5fa,#2563eb);
    }

    .doc-metrics{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
    }

    .doc-count{
        font-weight:800;
        font-size:14px;
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

    details{
        border:1px dashed #d8e2ee;
        border-radius:14px;
        padding:10px 12px;
        background:#fcfdff;
    }

    details summary{
        cursor:pointer;
        list-style:none;
        font-weight:700;
        color:#334155;
    }

    details summary::-webkit-details-marker{
        display:none;
    }

    .chips{
        margin-top:10px;
    }

    .chips .chip{
        display:inline-block;
        padding:5px 9px;
        margin:0 6px 6px 0;
        border-radius:999px;
        background:#f1f5f9;
        color:#334155;
        font-size:12px;
        border:1px solid #e2e8f0;
    }

    .rank-table td, .rank-table th{
        padding:10px 8px;
    }

    .empty{
        padding:24px;
        text-align:center;
        color:var(--muted);
    }

    @media (max-width:1180px){
        .grid-kpi{ grid-template-columns:repeat(2,minmax(0,1fr)); }
        .summary-grid{ grid-template-columns:1fr; }
        .filters-grid{ grid-template-columns:1fr 1fr; }
    }

    @media (max-width:720px){
        .grid-kpi{ grid-template-columns:1fr; }
        .filters-grid{ grid-template-columns:1fr; }
        .hero h1{ font-size:24px; }
        th, td{ padding:12px 10px; }
    }
</style>
</head>
<body>
<div class="wrap">

    <section class="hero">
        <div class="hero-top">
            <div>
                <h1>Expedientes RH</h1>
                <p>
                    Panel central de expedientes del personal de LUGA. Aquí puedes revisar avance,
                    documentos obligatorios, datos laborales y entrar al expediente individual de cada colaborador.
                </p>
                <div class="hero-badges">
                    <span class="hero-badge">Módulo base para vacaciones y autorizaciones</span>
                    <span class="hero-badge">Documentos vigentes versionados</span>
                    <span class="hero-badge">Límite de archivo: <?= (int)$maxMB ?> MB</span>
                </div>
            </div>
            <div class="hero-badges">
                <span class="hero-badge">Rol actual: <?= h($mi_rol) ?></span>
                <span class="hero-badge">Usuarios visibles: <?= (int)$totalUsers ?></span>
            </div>
        </div>
    </section>

    <section class="grid-kpi">
        <article class="card kpi">
            <div class="label">Empleados listados</div>
            <div class="value"><?= (int)$totalUsers ?></div>
            <div class="sub">Según filtros actuales</div>
        </article>

        <article class="card kpi">
            <div class="label">Expedientes completos</div>
            <div class="value"><?= (int)$totalComplete ?></div>
            <div class="sub">Con avance global del 100%</div>
        </article>

        <article class="card kpi">
            <div class="label">Expedientes pendientes</div>
            <div class="value"><?= (int)$totalPending ?></div>
            <div class="sub">Con campos o documentos faltantes</div>
        </article>

        <article class="card kpi">
            <div class="label">Promedio general</div>
            <div class="value"><?= number_format($avgGlobal, 1) ?>%</div>
            <div class="sub">Salud global del expediente</div>
        </article>
    </section>

    <section class="card section">
        <h2>Filtros</h2>
        <form method="get" class="filters-grid">
            <div class="field">
                <label>Buscar</label>
                <input type="text" name="q" value="<?= h($search) ?>" placeholder="Nombre, usuario, correo, sucursal o rol">
            </div>

            <div class="field">
                <label>Rol</label>
                <select name="rol">
                    <?php foreach (['Todos','Ejecutivo','Gerente','Admin','Supervisor','Logistica','Sistemas'] as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= $roleFilter === $opt ? 'selected' : '' ?>>
                            <?= h($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Sucursal</label>
                <select name="sucursal">
                    <option value="0">Todas</option>
                    <?php foreach ($sucursales as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= $sucursalFilter === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= h($s['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Estatus expediente</label>
                <select name="status">
                    <?php foreach (['Todos','Completos','Pendientes'] as $opt): ?>
                        <option value="<?= h($opt) ?>" <?= $statusFilter === $opt ? 'selected' : '' ?>>
                            <?= h($opt) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Activo</label>
                <select name="activo">
                    <option value="1" <?= $activoFilter === '1' ? 'selected' : '' ?>>Activos</option>
                    <option value="0" <?= $activoFilter === '0' ? 'selected' : '' ?>>Inactivos</option>
                    <option value="todos" <?= $activoFilter === 'todos' ? 'selected' : '' ?>>Todos</option>
                </select>
            </div>

            <div class="field">
                <label>&nbsp;</label>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="submit" class="btn btn-primary">Aplicar</button>
                    <a href="admin_expedientes.php" class="btn btn-light">Limpiar</a>
                    <a href="exportar_expedientes_activos_excel.php" class="btn btn-light">Exportar Excel</a>
                </div>
            </div>
        </form>
    </section>

    <section class="summary-grid">
        <article class="card section">
            <h2>Sucursales con menor avance</h2>
            <div class="table-wrap">
                <table class="rank-table">
                    <thead>
                        <tr>
                            <th>Sucursal</th>
                            <th>Zona</th>
                            <th>Usuarios</th>
                            <th>Completos</th>
                            <th>Promedio</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$bySucursal): ?>
                        <tr><td colspan="5" class="empty">Sin datos con los filtros actuales.</td></tr>
                    <?php else: ?>
                        <?php foreach ($bySucursal as $s): ?>
                            <tr>
                                <td><?= h($s['name']) ?></td>
                                <td><?= h($s['zona']) ?></td>
                                <td><?= (int)$s['users'] ?></td>
                                <td><?= (int)$s['complete'] ?></td>
                                <td>
                                    <span class="pill <?= h(status_badge_class((int)round($s['avg']))) ?>">
                                        <?= number_format((float)$s['avg'], 1) ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="card section">
            <h2>Radar rápido</h2>
            <div class="muted" style="margin-bottom:12px;">
                Vista compacta para ubicar dónde duele más el expediente en este corte.
            </div>

            <div style="display:grid; gap:12px;">
                <div>
                    <div class="muted">Documentos faltantes acumulados</div>
                    <div style="font-size:28px;font-weight:800;"><?= (int)$totalMissingDocs ?></div>
                </div>

                <div>
                    <div class="muted">Expedientes completos</div>
                    <div class="progress"><span style="width: <?= $totalUsers > 0 ? (int)floor(($totalComplete / $totalUsers) * 100) : 0 ?>%"></span></div>
                    <div class="muted"><?= (int)$totalComplete ?> de <?= (int)$totalUsers ?></div>
                </div>

                <div>
                    <div class="muted">Expedientes pendientes</div>
                    <div class="progress"><span style="width: <?= $totalUsers > 0 ? (int)floor(($totalPending / $totalUsers) * 100) : 0 ?>%; background:linear-gradient(90deg,#f59e0b,#d97706)"></span></div>
                    <div class="muted"><?= (int)$totalPending ?> de <?= (int)$totalUsers ?></div>
                </div>
            </div>
        </article>
    </section>

    <section class="card section">
        <h2>Listado general</h2>
        <div class="table-wrap">
            <table id="tablaExpedientes">
                <thead>
                    <tr>
                        <th style="min-width:280px;">Empleado</th>
                        <th style="min-width:160px;">Sucursal</th>
                        <th style="min-width:130px;">Puesto</th>
                        <th style="min-width:260px;">Avance</th>
                        <th style="min-width:170px;">Documentos</th>
                        <th style="min-width:160px;">Antigüedad</th>
                        <th style="min-width:220px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="7" class="empty">No se encontraron empleados con los filtros actuales.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($rows as $r):
                    $u   = $r['user'];
                    $exp = $r['exp'];
                    $uid = (int)$u['id'];
                    $pct = (int)$r['pct'];
                    $badgeClass = status_badge_class($pct);
                    $foto = trim((string)($exp['foto'] ?? ''));
                    $antiguedad = calc_antiguedad(
                        $exp['fecha_ingreso'] ?? null,
                        isset($exp['antiguedad_years']) ? (int)$exp['antiguedad_years'] : null,
                        isset($exp['antiguedad_meses']) ? (int)$exp['antiguedad_meses'] : null
                    );
                    $ini = strtoupper(mb_substr(trim((string)$u['nombre']), 0, 1));
                ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <div class="avatar">
                                    <?php if ($foto !== ''): ?>
                                        <img src="<?= h($foto) ?>" alt="Foto de <?= h($u['nombre']) ?>">
                                    <?php else: ?>
                                        <?= h($ini !== '' ? $ini : 'U') ?>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="user-name"><?= h($u['nombre']) ?></div>
                                    <div class="muted">Empleado #<?= (int)$u['id'] ?> · @<?= h($u['usuario']) ?></div>
                                    <div class="muted"><?= h($u['correo'] ?: 'Sin correo') ?></div>
                                    <div style="margin-top:6px;">
                                        <?php if ((int)$u['activo'] === 1): ?>
                                            <span class="pill success">Activo</span>
                                        <?php else: ?>
                                            <span class="pill danger">Inactivo</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>

                        <td>
                            <strong><?= h($u['sucursal_nombre'] ?: '—') ?></strong><br>
                            <span class="muted"><?= h($u['sucursal_zona'] ?: 'Sin zona') ?></span>
                        </td>

                        <td>
                            <span class="pill primary"><?= h($u['rol']) ?></span>
                        </td>

                        <td>
                            <div class="progress">
                                <span style="width: <?= $pct ?>%"></span>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <span class="pill <?= h($badgeClass) ?>"><?= $pct ?>%</span>
                                <?php if ($pct === 100): ?>
                                    <span class="muted">Expediente completo</span>
                                <?php else: ?>
                                    <span class="muted">Faltan <?= count($r['missingFields']) + count($r['missingDocs']) ?> elemento(s)</span>
                                <?php endif; ?>
                            </div>

                            <div class="mini-bars">
                                <?php
                                $blockLabels = [
                                    'personales'  => 'Personales',
                                    'laborales'   => 'Laborales',
                                    'emergencia'  => 'Emergencia',
                                    'plataformas' => 'Plataformas',
                                    'documentos'  => 'Docs',
                                ];
                                foreach ($r['progress']['blocks'] as $bKey => $b):
                                ?>
                                    <div class="mini-item">
                                        <div><?= h($blockLabels[$bKey] ?? $bKey) ?></div>
                                        <div class="mini-track">
                                            <span style="width: <?= (int)$b['pct'] ?>%"></span>
                                        </div>
                                        <div><?= (int)$b['pct'] ?>%</div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <details style="margin-top:10px;">
                                <summary>Ver faltantes</summary>

                                <div class="chips">
                                    <div class="muted" style="margin-bottom:6px;"><strong>Datos pendientes</strong></div>
                                    <?php if (empty($r['missingFields'])): ?>
                                        <span class="chip">Sin faltantes</span>
                                    <?php else: ?>
                                        <?php foreach ($r['missingFields'] as $item): ?>
                                            <span class="chip"><?= h($item) ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <div class="chips" style="margin-top:10px;">
                                    <div class="muted" style="margin-bottom:6px;"><strong>Documentos pendientes</strong></div>
                                    <?php if (empty($r['missingDocs'])): ?>
                                        <span class="chip">Todos los requeridos cargados</span>
                                    <?php else: ?>
                                        <?php foreach ($r['missingDocs'] as $item): ?>
                                            <span class="chip"><?= h($item) ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </td>

                        <td>
                            <div class="doc-metrics">
                                <span class="pill <?= ($r['docDone'] === $r['docTotal'] && $r['docTotal'] > 0) ? 'success' : 'warning' ?>">
                                    <?= (int)$r['docDone'] ?>/<?= (int)$r['docTotal'] ?> requeridos
                                </span>
                            </div>

                            <?php if (!empty($r['docs'])): ?>
                                <div class="muted" style="margin-top:8px;">
                                    Docs vigentes localizados.
                                </div>
                            <?php else: ?>
                                <div class="muted" style="margin-top:8px;">
                                    Sin documentos requeridos cargados.
                                </div>
                            <?php endif; ?>
                        </td>

                        <td>
                            <strong><?= h($antiguedad) ?></strong><br>
                            <span class="muted">Ingreso: <?= h(fmt_date($exp['fecha_ingreso'] ?? null)) ?></span>
                        </td>

                        <td>
                            <div class="actions">
                                <a class="btn btn-light" href="expediente_usuario.php?usuario_id=<?= $uid ?>">Ver expediente</a>
                                <a class="btn btn-light" href="documentos_historial.php?usuario_id=<?= $uid ?>">Documentos</a>
                                <a class="btn btn-primary" href="generar_caratula_expediente.php?usuario_id=<?= $uid ?>" target="_blank">Carátula PDF</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>
const inputSearch = document.querySelector('input[name="q"]');
const tabla = document.getElementById('tablaExpedientes');

if (inputSearch && tabla) {
    inputSearch.addEventListener('input', function() {
        const term = this.value.toLowerCase().trim();
        const rows = tabla.querySelectorAll('tbody tr');

        rows.forEach(tr => {
            const txt = tr.innerText.toLowerCase();
            tr.style.display = (!term || txt.includes(term)) ? '' : 'none';
        });
    });
}
</script>
</body>
</html>