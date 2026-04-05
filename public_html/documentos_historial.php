<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

/* ==== Includes con fallback ==== */
if (file_exists(__DIR__ . '/includes/docs_lib.php')) {
    require_once __DIR__ . '/includes/docs_lib.php';
} else {
    require_once __DIR__ . '/docs_lib.php';
}
if (file_exists(__DIR__ . '/navbar.php')) {
    require_once __DIR__ . '/navbar.php';
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

/* =========================================================
   Contexto
========================================================= */
$mi_id  = (int)($_SESSION['id_usuario'] ?? 0);
$mi_rol = trim((string)($_SESSION['rol'] ?? 'Ejecutivo'));

$usuario_id = isset($_GET['usuario_id']) ? (int)$_GET['usuario_id'] : $mi_id;
if ($usuario_id <= 0) {
    $usuario_id = $mi_id;
}

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
            return "{$diff->y} año(s), {$diff->m} mes(es)";
        } catch (Throwable $e) {}
    }

    $years  = (int)($yearsGuardados ?? 0);
    $months = (int)($mesesGuardados ?? 0);

    if ($years === 0 && $months === 0) return '—';
    return "{$years} año(s), {$months} mes(es)";
}

function puede_subir(string $rol, int $mi_id, int $usuario_id): bool {
    return in_array($rol, ['Admin', 'Gerente', 'Logistica'], true) || ($mi_id === $usuario_id);
}

function puede_ver_terceros(string $rol): bool {
    return in_array($rol, ['Admin', 'Gerente', 'Logistica'], true);
}

/* =========================================================
   Blindaje básico
========================================================= */
if ($usuario_id !== $mi_id && !puede_ver_terceros($mi_rol)) {
    http_response_code(403);
    exit('Sin permiso para ver documentos de otro usuario.');
}

$esAdmin = in_array($mi_rol, ['Admin', 'Supervisor', 'Super', 'Logistica'], true);
$puedeSubir = puede_subir($mi_rol, $mi_id, $usuario_id);

/* =========================================================
   Datos del usuario objetivo
========================================================= */
$usuario = null;
$stmt = $conn->prepare("
    SELECT
        u.id,
        u.nombre,
        u.usuario,
        u.correo,
        u.rol,
        u.activo,
        u.id_sucursal,
        s.nombre AS sucursal_nombre,
        s.zona AS sucursal_zona,
        ue.foto,
        ue.fecha_ingreso,
        ue.antiguedad_years,
        ue.antiguedad_meses
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN usuarios_expediente ue ON ue.usuario_id = u.id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $usuario_id);
$stmt->execute();
$rs = $stmt->get_result();
if ($rs) {
    $usuario = $rs->fetch_assoc();
}
$stmt->close();

if (!$usuario) {
    exit('No se encontró el usuario.');
}

$usuario_nombre = (string)($usuario['nombre'] ?? '');
$foto_actual    = $usuario['foto'] ?? null;
$placeholder    = 'https://ui-avatars.com/api/?name=' . urlencode($usuario_nombre ?: 'Usuario') . '&background=random&bold=true';
$antiguedad     = calc_antiguedad(
    $usuario['fecha_ingreso'] ?? null,
    isset($usuario['antiguedad_years']) ? (int)$usuario['antiguedad_years'] : null,
    isset($usuario['antiguedad_meses']) ? (int)$usuario['antiguedad_meses'] : null
);

$ini = strtoupper(mb_substr(trim($usuario_nombre), 0, 1));

/* =========================================================
   Tipos / documentos
========================================================= */
$tipos = list_doc_types_with_status($conn, $usuario_id);
$maxMB = defined('DOCS_MAX_SIZE') ? (int)(DOCS_MAX_SIZE / 1024 / 1024) : 10;
$maxFotoMB = 5;

/* =========================================================
   KPIs
========================================================= */
$totalReq = 0;
$uploadedReq = 0;
$totalDocs = 0;
$pendientes = 0;

foreach ($tipos as $t) {
    $tiene = !empty($t['doc_id_vigente']);
    if ((int)$t['requerido'] === 1) {
        $totalReq++;
        if ($tiene) $uploadedReq++;
        else $pendientes++;
    }
    if ($tiene) $totalDocs++;
}

$pct = $totalReq ? (int)floor(($uploadedReq / $totalReq) * 100) : 0;
$fotoCargada = !empty($foto_actual);

/* =========================================================
   Mensajes
========================================================= */
$ok       = !empty($_GET['ok']) || !empty($_GET['ok_doc']);
$err      = !empty($_GET['err']) ? (string)$_GET['err'] : '';
$errDoc   = !empty($_GET['err_doc']) ? (string)$_GET['err_doc'] : '';
$ok_foto  = !empty($_GET['ok_foto']);
$err_foto = !empty($_GET['err_foto']) ? (string)$_GET['err_foto'] : '';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Documentos del expediente</title>
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
        --slate:#475569;
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

    .hero-grid{
        display:grid;
        grid-template-columns:1.2fr .8fr;
        gap:18px;
        align-items:center;
    }

    .user-head{
        display:flex;
        gap:18px;
        align-items:center;
    }

    .avatar{
        width:88px;
        height:88px;
        border-radius:24px;
        background:#ffffff;
        border:1px solid #dbeafe;
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:30px;
        font-weight:800;
        overflow:hidden;
        flex:0 0 88px;
        color:#1d4ed8;
    }

    .avatar img{
        width:100%;
        height:100%;
        object-fit:cover;
    }

    .hero h1{
        margin:0 0 6px;
        font-size:30px;
        line-height:1.05;
    }

    .hero .meta{
        color:#4b5563;
        font-size:14px;
        margin-bottom:10px;
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
        backdrop-filter: blur(6px);
    }

    .progress{
        width:100%;
        height:14px;
        background:#dbeafe;
        border-radius:999px;
        overflow:hidden;
        margin:8px 0 10px;
    }

    .progress-dark{
        width:100%;
        height:14px;
        background:#dbeafe;
        border-radius:999px;
        overflow:hidden;
        margin:8px 0 10px;
    }

    .progress > span,
    .progress-dark > span{
        display:block;
        height:100%;
        border-radius:999px;
        background:linear-gradient(90deg,#3b82f6,#60a5fa);
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

    .btn:hover{ background:#f8fafc; }

    .btn-primary{
        background:var(--primary);
        border-color:var(--primary);
        color:#fff;
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

    .btn-success:hover{ filter:brightness(.98); }

    .btn-secondary{
        background:var(--slate);
        border-color:var(--slate);
        color:#fff;
    }

    .btn-danger{
        background:var(--danger);
        border-color:var(--danger);
        color:#fff;
    }

    .btn-light{
        background:#fff;
        border-color:#d8e1ec;
        color:#111827;
    }

    .btn-light:hover{ background:#f8fafc; }

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

    .grid-kpi{
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
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

    .photo-wrap{
        display:grid;
        grid-template-columns:120px 1fr;
        gap:18px;
        align-items:center;
    }

    .photo-box{
        width:120px;
        height:120px;
        border-radius:24px;
        overflow:hidden;
        border:1px solid #dbe2ea;
        background:#f8fafc;
        display:flex;
        align-items:center;
        justify-content:center;
        position:relative;
    }

    .photo-box img{
        width:100%;
        height:100%;
        object-fit:cover;
        cursor:pointer;
        transition:.18s ease;
    }

    .photo-box img:hover{
        transform:scale(1.03);
        filter:brightness(.98);
    }

    .photo-clickable{
        cursor:pointer;
        position:relative;
    }

    .photo-clickable::after{
        content:'🔍';
        position:absolute;
        right:8px;
        bottom:8px;
        width:30px;
        height:30px;
        border-radius:50%;
        display:flex;
        align-items:center;
        justify-content:center;
        background:rgba(17,24,39,.72);
        color:#fff;
        font-size:14px;
        box-shadow:0 6px 16px rgba(0,0,0,.18);
        pointer-events:none;
    }

    .photo-placeholder{
        font-size:34px;
        font-weight:900;
        color:#475569;
    }

    .uploader{
        display:flex;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
    }

    .file input[type="file"]{display:none}

    .file .file-label{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-height:42px;
        padding:0 14px;
        border:1px solid #d8e1ec;
        border-radius:14px;
        background:#fff;
        cursor:pointer;
        font-weight:700;
    }

    .file-name{
        font-size:12px;
        color:#111;
        padding:6px 12px;
        border-radius:999px;
        background:#edf2f7;
        max-width:260px;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
    }

    .doc-list{
        display:grid;
        gap:12px;
    }

    .doc{
        border:1px solid #e8eef5;
        background:#fcfdff;
        border-radius:18px;
        padding:16px;
        display:flex;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
    }

    .doc.ready{
        background:#f0fff4;
        border-color:#bbf7d0;
    }

    .doc-left{
        min-width:260px;
        flex:1;
    }

    .doc-title{
        font-weight:800;
        margin-bottom:4px;
        font-size:15px;
    }

    .chips{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        margin-top:8px;
    }

    .chip{
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:5px 9px;
        border-radius:999px;
        font-size:12px;
        border:1px solid;
        font-weight:700;
    }

    .chip.req{background:#eef2ff;border-color:#c7d2fe;color:#1e3a8a}
    .chip.opt{background:#fdf2f8;border-color:#fbcfe8;color:#9d174d}
    .chip.ok{background:#dcfce7;border-color:#bbf7d0;color:#14532d}
    .chip.pend{background:#f1f5f9;border-color:#e2e8f0;color:#334155}
    .chip.ver{background:#f0f9ff;border-color:#bae6fd;color:#075985}

    .doc-right{
        display:flex;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
        justify-content:flex-end;
        min-width:340px;
    }

    .muted{
        color:var(--muted);
        font-size:12px;
    }

    .sub{
        color:var(--muted);
        margin:0;
        font-size:13px;
    }

    /* ===== Modal foto ===== */
    .modal-photo{
        position:fixed;
        inset:0;
        background:rgba(15,23,42,.72);
        display:none;
        align-items:center;
        justify-content:center;
        padding:22px;
        z-index:9999;
        backdrop-filter: blur(4px);
    }

    .modal-photo.show{
        display:flex;
    }

    .modal-photo-dialog{
        width:min(980px, 100%);
        max-height:92vh;
        background:#fff;
        border-radius:24px;
        overflow:hidden;
        box-shadow:0 25px 70px rgba(0,0,0,.28);
        animation:modalIn .18s ease;
    }

    @keyframes modalIn{
        from{ transform:translateY(10px) scale(.985); opacity:.6; }
        to{ transform:translateY(0) scale(1); opacity:1; }
    }

    .modal-photo-header{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        padding:16px 18px;
        border-bottom:1px solid #e5e7eb;
        background:#f8fafc;
    }

    .modal-photo-title{
        font-size:16px;
        font-weight:800;
        color:#111827;
    }

    .modal-photo-close{
        border:0;
        background:#fff;
        border:1px solid #dbe2ea;
        color:#111827;
        width:40px;
        height:40px;
        border-radius:12px;
        cursor:pointer;
        font-size:20px;
        line-height:1;
        font-weight:700;
    }

    .modal-photo-body{
        padding:18px;
        display:flex;
        align-items:center;
        justify-content:center;
        background:#f8fafc;
        max-height:calc(92vh - 148px);
        overflow:auto;
    }

    .modal-photo-body img{
        max-width:100%;
        max-height:70vh;
        border-radius:18px;
        box-shadow:0 12px 30px rgba(15,23,42,.12);
        background:#fff;
    }

    .modal-photo-footer{
        display:flex;
        justify-content:flex-end;
        gap:10px;
        padding:16px 18px;
        border-top:1px solid #e5e7eb;
        background:#fff;
        flex-wrap:wrap;
    }

    @media (max-width:1180px){
        .grid-kpi{ grid-template-columns:repeat(2,minmax(0,1fr)); }
        .hero-grid{ grid-template-columns:1fr; }
        .photo-wrap{ grid-template-columns:1fr; }
    }

    @media (max-width:720px){
        .grid-kpi{ grid-template-columns:1fr; }
        .hero h1{ font-size:24px; }
        .hero-kpis{ grid-template-columns:1fr; }
        .doc-right{ min-width:0; justify-content:flex-start; }
    }
</style>
</head>
<body>
<div class="wrap">

    <section class="hero">
        <div class="hero-grid">
            <div>
                <div class="user-head">
                    <div class="avatar">
                        <?php if (!empty($foto_actual)): ?>
                            <img src="<?= h($foto_actual) ?>" alt="Foto de <?= h($usuario_nombre) ?>">
                        <?php else: ?>
                            <?= h($ini !== '' ? $ini : 'U') ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h1>Documentos del expediente</h1>
                        <div class="meta">
                            <?= h($usuario_nombre) ?> · Empleado #<?= (int)$usuario_id ?> · <?= h($usuario['correo'] ?: 'Sin correo') ?>
                        </div>
                        <div>
                            <span class="pill light"><?= h($usuario['rol'] ?: 'Sin rol') ?></span>
                            <span class="pill light"><?= h($usuario['sucursal_nombre'] ?: 'Sin sucursal') ?></span>
                            <span class="pill light"><?= h($usuario['sucursal_zona'] ?: 'Sin zona') ?></span>
                            <span class="pill light">Antigüedad: <?= h($antiguedad) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hero-side">
                <div style="font-size:13px;color:#6b7280;">Avance documental requerido</div>
                <div style="font-size:34px;font-weight:900;line-height:1; color:#111827;"><?= (int)$pct ?>%</div>
                <div class="progress-dark"><span style="width: <?= (int)$pct ?>%"></span></div>
                <div style="font-size:13px;color:#6b7280;">
                    <?= (int)$uploadedReq ?> de <?= (int)$totalReq ?> documentos requeridos cargados
                </div>

                <div class="hero-kpis">
                    <div class="mini-kpi">
                        <div class="label">Requeridos</div>
                        <div class="value"><?= (int)$uploadedReq ?>/<?= (int)$totalReq ?></div>
                    </div>
                    <div class="mini-kpi">
                        <div class="label">Pendientes</div>
                        <div class="value"><?= (int)$pendientes ?></div>
                    </div>
                    <div class="mini-kpi">
                        <div class="label">Totales</div>
                        <div class="value"><?= (int)$totalDocs ?></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="toolbar">
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="expediente_usuario.php?usuario_id=<?= (int)$usuario_id ?>" class="btn btn-light">← Volver al expediente</a>
            <a href="generar_caratula_expediente.php?usuario_id=<?= (int)$usuario_id ?>" class="btn btn-light" target="_blank">Carátula PDF</a>
            <a href="generar_formato_vacaciones.php?usuario_id=<?= (int)$usuario_id ?>" class="btn btn-primary" target="_blank">Formato vacaciones PDF</a>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <span class="pill primary">Límite docs: <?= (int)$maxMB ?> MB</span>
            <span class="pill <?= $fotoCargada ? 'success' : 'warning' ?>">Foto: <?= $fotoCargada ? 'Cargada' : 'Pendiente' ?></span>
        </div>
    </div>

    <?php if ($ok): ?>
        <div class="alert ok">Documento subido o eliminado correctamente.</div>
    <?php endif; ?>

    <?php if ($err || $errDoc): ?>
        <div class="alert error"><?= h($err ?: $errDoc) ?></div>
    <?php endif; ?>

    <?php if ($ok_foto): ?>
        <div class="alert ok">Foto actualizada correctamente.</div>
    <?php endif; ?>

    <?php if ($err_foto): ?>
        <div class="alert error"><?= h($err_foto) ?></div>
    <?php endif; ?>

    <section class="grid-kpi">
        <article class="card kpi">
            <div class="label">Documentos requeridos</div>
            <div class="value"><?= (int)$uploadedReq ?>/<?= (int)$totalReq ?></div>
            <div class="sub">Control obligatorio del expediente</div>
        </article>

        <article class="card kpi">
            <div class="label">Pendientes</div>
            <div class="value"><?= (int)$pendientes ?></div>
            <div class="sub">Requeridos sin documento vigente</div>
        </article>

        <article class="card kpi">
            <div class="label">Documentos vigentes</div>
            <div class="value"><?= (int)$totalDocs ?></div>
            <div class="sub">Tipos con archivo visible actualmente</div>
        </article>

        <article class="card kpi">
            <div class="label">Foto del expediente</div>
            <div class="value"><?= $fotoCargada ? 'Sí' : 'No' ?></div>
            <div class="sub">Identificación visual del colaborador</div>
        </article>
    </section>

    <section class="card section">
        <h2>Foto del empleado</h2>

        <div class="photo-wrap">
            <div class="photo-box <?= !empty($foto_actual) ? 'photo-clickable' : '' ?>">
                <?php if (!empty($foto_actual)): ?>
                    <img
                        id="pfp-img"
                        src="<?= h($foto_actual) ?>"
                        alt="Foto del usuario"
                        title="Clic para ampliar"
                    >
                <?php else: ?>
                    <div id="pfp-img" class="photo-placeholder"><?= h($ini !== '' ? $ini : 'U') ?></div>
                <?php endif; ?>
            </div>

            <div>
                <div style="font-weight:800; margin-bottom:6px;">Fotografía actual del expediente</div>
                <p class="sub" style="margin-bottom:12px;">
                    Formatos permitidos: JPG, PNG o WebP. Tamaño máximo: <?= (int)$maxFotoMB ?> MB.
                    <?php if (!empty($foto_actual)): ?>
                        <br>Haz clic en la imagen para verla en grande y descargarla.
                    <?php endif; ?>
                </p>

                <?php if ($puedeSubir): ?>
                    <form class="uploader" action="expediente_subir_foto.php" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="usuario_id" value="<?= (int)$usuario_id ?>">
                        <input type="hidden" name="return_to" value="<?= h($_SERVER['PHP_SELF'] . '?usuario_id=' . $usuario_id) ?>">

                        <span class="file">
                            <label class="file-label" for="foto">Elegir foto…</label>
                            <input id="foto" type="file" name="foto" accept="image/*">
                        </span>

                        <span class="file-name" id="foto-name">No se ha seleccionado archivo</span>
                        <button class="btn btn-success" id="btn-foto" type="submit" disabled>Guardar</button>
                        <button class="btn btn-secondary" id="btn-foto-clear" type="button" disabled>Quitar</button>

                        <?php if (!empty($foto_actual)): ?>
                            <a class="btn btn-light" href="<?= h($foto_actual) ?>" download target="_blank">Descargar actual</a>
                        <?php endif; ?>
                    </form>
                <?php else: ?>
                    <p class="sub">No tienes permisos para actualizar la foto de este usuario.</p>
                    <?php if (!empty($foto_actual)): ?>
                        <div style="margin-top:12px;">
                            <a class="btn btn-light" href="<?= h($foto_actual) ?>" download target="_blank">Descargar foto</a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="card section">
        <h2>Documentos del expediente</h2>
        <p class="sub" style="margin-bottom:14px;">
            Tipos permitidos: PDF, JPG, PNG. Límite máximo por archivo: <?= (int)$maxMB ?> MB.
        </p>

        <div class="doc-list">
            <?php foreach ($tipos as $t):
                $docTipoId  = (int)$t['id'];
                $docVigente = !empty($t['doc_id_vigente']) ? (int)$t['doc_id_vigente'] : null;
                $isReq      = (int)$t['requerido'] === 1;
                $puedeVer   = user_can_view_doc_type($conn, $mi_rol, $docTipoId) || ($usuario_id === $mi_id) || puede_ver_terceros($mi_rol);
                $return_to  = "documentos_historial.php?usuario_id={$usuario_id}#doc-{$docTipoId}";
            ?>
                <div class="doc" id="doc-<?= $docTipoId ?>">
                    <div class="doc-left">
                        <div class="doc-title"><?= h($t['nombre']) ?></div>
                        <div class="muted">
                            Tipo ID: <?= (int)$docTipoId ?>
                        </div>

                        <div class="chips">
                            <?= $isReq ? '<span class="chip req">Requerido</span>' : '<span class="chip opt">Opcional</span>' ?>
                            <?= $docVigente ? '<span class="chip ok">Subido</span>' : '<span class="chip pend">Pendiente</span>' ?>
                            <span class="chip ver"><?= !empty($t['version']) ? ('v' . (int)$t['version']) : 'v—' ?></span>
                        </div>
                    </div>

                    <div class="doc-right">
                        <?php if ($docVigente && $puedeVer): ?>
                            <a class="btn btn-primary" target="_blank" href="documento_descargar.php?id=<?= $docVigente ?>">Ver</a>
                        <?php endif; ?>

                        <?php if ($docVigente && $esAdmin): ?>
                            <a class="btn btn-secondary" href="documento_descargar.php?id=<?= $docVigente ?>&download=1">Descargar</a>
                        <?php endif; ?>

                        <?php if ($docVigente && $esAdmin): ?>
                            <form action="documento_borrar.php" method="post" onsubmit="return confirm('¿Seguro que deseas borrar este documento?');" style="display:inline">
                                <input type="hidden" name="doc_id" value="<?= $docVigente ?>">
                                <input type="hidden" name="return_to" value="<?= h($return_to) ?>">
                                <button type="submit" class="btn btn-danger">Borrar</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($puedeSubir): ?>
                            <form class="uploader js-upload" action="documento_subir.php" method="post" enctype="multipart/form-data">
                                <input type="hidden" name="usuario_id" value="<?= (int)$usuario_id ?>">
                                <input type="hidden" name="doc_tipo_id" value="<?= $docTipoId ?>">
                                <input type="hidden" name="return_to" value="<?= h($return_to) ?>">

                                <span class="file">
                                    <span class="file-label">Elegir archivo</span>
                                    <input type="file" name="archivo" accept=".pdf,.jpg,.jpeg,.png">
                                </span>

                                <span class="file-name" data-placeholder="No se ha seleccionado archivo">No se ha seleccionado archivo</span>
                                <button class="btn btn-secondary btn-clear" type="button" disabled>Quitar</button>
                                <button class="btn btn-success" type="submit" disabled>Subir</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <p class="sub" style="margin-top:14px;">
            <?php if ($usuario_id !== $mi_id): ?>
                Estás gestionando el expediente documental del usuario #<?= (int)$usuario_id ?>.
            <?php else: ?>
                Estás viendo tu expediente documental.
            <?php endif; ?>
        </p>
    </section>
</div>

<?php if (!empty($foto_actual)): ?>
<div class="modal-photo" id="photoModal" aria-hidden="true">
    <div class="modal-photo-dialog" role="dialog" aria-modal="true" aria-labelledby="photoModalTitle">
        <div class="modal-photo-header">
            <div class="modal-photo-title" id="photoModalTitle">
                Foto de <?= h($usuario_nombre) ?>
            </div>
            <button type="button" class="modal-photo-close" id="photoModalClose" aria-label="Cerrar">×</button>
        </div>

        <div class="modal-photo-body" id="photoModalBackdrop">
            <img src="<?= h($foto_actual) ?>" alt="Foto ampliada de <?= h($usuario_nombre) ?>">
        </div>

        <div class="modal-photo-footer">
            <a class="btn btn-light" href="<?= h($foto_actual) ?>" target="_blank">Abrir en pestaña</a>
            <a class="btn btn-primary" href="<?= h($foto_actual) ?>" download>Descargar foto</a>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
/* UX uploader documentos */
document.querySelectorAll('.js-upload').forEach(function(form){
    const row    = form.closest('.doc');
    const input  = form.querySelector('input[type="file"]');
    const nameEl = form.querySelector('.file-name');
    const btnUp  = form.querySelector('button[type="submit"]');
    const btnClr = form.querySelector('.btn-clear');
    const label  = form.querySelector('.file-label');

    label.addEventListener('click', () => input.click());

    function resetState(){
        nameEl.textContent = nameEl.dataset.placeholder || 'No se ha seleccionado archivo';
        btnUp.disabled = true;
        btnClr.disabled = true;
        row.classList.remove('ready');
    }

    input.addEventListener('change', () => {
        if (input.files && input.files.length) {
            nameEl.textContent = input.files[0].name;
            btnUp.disabled = false;
            btnClr.disabled = false;
            row.classList.add('ready');
        } else {
            resetState();
        }
    });

    btnClr.addEventListener('click', () => {
        input.value = '';
        input.dispatchEvent(new Event('change'));
    });
});

/* UX foto */
(function(){
    const file = document.getElementById('foto');
    if (!file) return;

    const imgEl = document.getElementById('pfp-img');
    const name = document.getElementById('foto-name');
    const btn  = document.getElementById('btn-foto');
    const clr  = document.getElementById('btn-foto-clear');

    function reset(){
        name.textContent = 'No se ha seleccionado archivo';
        btn.disabled = true;
        clr.disabled = true;
    }

    file.addEventListener('change', () => {
        if (file.files && file.files[0]) {
            name.textContent = file.files[0].name;

            if (imgEl && imgEl.tagName === 'IMG') {
                imgEl.src = URL.createObjectURL(file.files[0]);
            }

            btn.disabled = false;
            clr.disabled = false;
        } else {
            reset();
        }
    });

    clr?.addEventListener('click', () => {
        file.value = '';
        reset();
    });
})();

/* Modal foto */
(function(){
    const trigger = document.getElementById('pfp-img');
    const modal   = document.getElementById('photoModal');
    const close   = document.getElementById('photoModalClose');

    if (!trigger || !modal || !close || trigger.tagName !== 'IMG') return;

    function openModal(){
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(){
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    trigger.addEventListener('click', openModal);
    close.addEventListener('click', closeModal);

    modal.addEventListener('click', function(e){
        if (e.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && modal.classList.contains('show')) {
            closeModal();
        }
    });
})();
</script>
</body>
</html>