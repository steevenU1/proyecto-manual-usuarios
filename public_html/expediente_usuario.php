<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

$mi_rol = $_SESSION['rol'] ?? 'Ejecutivo';
if (!in_array($mi_rol, ['Admin', 'Gerente'], true)) {
    http_response_code(403);
    echo "Sin permiso.";
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calcular_vacaciones.php';
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
            return "{$diff->y} año(s), {$diff->m} mes(es)";
        } catch (Throwable $e) {}
    }

    $y = (int)($yearsGuardados ?? 0);
    $m = (int)($mesesGuardados ?? 0);
    if ($y === 0 && $m === 0) return '—';
    return "{$y} año(s), {$m} mes(es)";
}

function calc_edad(?string $fechaNacimiento, ?int $edadGuardada = null): string {
    if (!empty_date($fechaNacimiento)) {
        try {
            $fn = new DateTime($fechaNacimiento);
            $hoy = new DateTime();
            return (string)$fn->diff($hoy)->y;
        } catch (Throwable $e) {}
    }
    return $edadGuardada !== null ? (string)(int)$edadGuardada : '—';
}

function val_post(string $key): ?string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : null;
}

function normalize_nullable(?string $value): ?string {
    if ($value === null) return null;
    $value = trim($value);
    return $value === '' ? null : $value;
}

function normalize_date(?string $value): ?string {
    $value = normalize_nullable($value);
    if ($value === null) return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
    return $value;
}

function field_filled($val): bool {
    return trim((string)$val) !== '' && !empty_date((string)$val);
}

function get_doc_icon(string $mime, string $nombre): string {
    $mime = strtolower($mime);
    $nombre = strtolower($nombre);

    if (str_contains($mime, 'pdf') || str_ends_with($nombre, '.pdf')) return 'PDF';
    if (str_contains($mime, 'image') || preg_match('/\.(jpg|jpeg|png|webp)$/', $nombre)) return 'IMG';
    if (str_contains($mime, 'word') || preg_match('/\.(doc|docx)$/', $nombre)) return 'DOC';
    return 'ARC';
}

function clase_badge_vacaciones(string $status): string {
    $s = mb_strtolower(trim($status));
    return match ($s) {
        'aprobado'  => 'success',
        'rechazado' => 'danger',
        default     => 'warning',
    };
}

/* =========================================================
   Catálogos UI
========================================================= */
$opcionesGenero = ['M', 'F', 'Otro'];
$opcionesContrato = ['Si', 'No', 'Pendiente', 'Indefinido', 'Mercantil', 'Otro'];
$opcionesPlataforma = ['Si', 'No', 'Bloqueado', 'Pendiente'];
$opcionesTalla = ['XS','S','M','L','XL','XXL','XXXL'];

$requiredFields = [
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
    'central_status'
];

/* =========================================================
   Usuario objetivo
========================================================= */
$usuarioId = (int)($_GET['usuario_id'] ?? 0);
if ($usuarioId <= 0) {
    die("Usuario inválido.");
}

/* =========================================================
   Crear expediente si no existe
========================================================= */
$stmt = $conn->prepare("SELECT id FROM usuarios_expediente WHERE usuario_id = ? LIMIT 1");
$stmt->bind_param("i", $usuarioId);
$stmt->execute();
$res = $stmt->get_result();
$expExiste = $res->fetch_assoc();
$stmt->close();

if (!$expExiste) {
    $stmt = $conn->prepare("INSERT INTO usuarios_expediente (usuario_id, created_at, updated_at) VALUES (?, NOW(), NOW())");
    $stmt->bind_param("i", $usuarioId);
    $stmt->execute();
    $stmt->close();
}

/* =========================================================
   Guardar POST
========================================================= */
$flashOk = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_expediente'])) {
    try {
        $tel_contacto         = normalize_nullable(val_post('tel_contacto'));
        $fecha_nacimiento     = normalize_date(val_post('fecha_nacimiento'));
        $fecha_ingreso        = normalize_date(val_post('fecha_ingreso'));
        $fecha_baja           = normalize_date(val_post('fecha_baja'));
        $motivo_baja          = normalize_nullable(val_post('motivo_baja'));
        $curp                 = normalize_nullable(val_post('curp'));
        $nss                  = normalize_nullable(val_post('nss'));
        $rfc                  = normalize_nullable(val_post('rfc'));
        $genero               = normalize_nullable(val_post('genero'));
        $contacto_emergencia  = normalize_nullable(val_post('contacto_emergencia'));
        $tel_emergencia       = normalize_nullable(val_post('tel_emergencia'));
        $clabe                = normalize_nullable(val_post('clabe'));
        $banco                = normalize_nullable(val_post('banco'));

        $contrato_status      = normalize_nullable(val_post('contrato_status'));
        $registro_patronal    = normalize_nullable(val_post('registro_patronal'));
        $fecha_alta_imss      = normalize_date(val_post('fecha_alta_imss'));
        $talla_uniforme       = normalize_nullable(val_post('talla_uniforme'));

        $payjoy_status        = normalize_nullable(val_post('payjoy_status'));
        $krediya_status       = normalize_nullable(val_post('krediya_status'));
        $lespago_status       = normalize_nullable(val_post('lespago_status'));
        $innovm_status        = normalize_nullable(val_post('innovm_status'));
        $central_status       = normalize_nullable(val_post('central_status'));

        $edad_years = null;
        if ($fecha_nacimiento) {
            try {
                $fn = new DateTime($fecha_nacimiento);
                $edad_years = (int)$fn->diff(new DateTime())->y;
            } catch (Throwable $e) {
                $edad_years = null;
            }
        }

        $antiguedad_years = null;
        $antiguedad_meses = null;
        if ($fecha_ingreso) {
            try {
                $fi = new DateTime($fecha_ingreso);
                $diff = $fi->diff(new DateTime());
                $antiguedad_years = (int)$diff->y;
                $antiguedad_meses = (int)$diff->m;
            } catch (Throwable $e) {
                $antiguedad_years = null;
                $antiguedad_meses = null;
            }
        }

        $sql = "UPDATE usuarios_expediente SET
                    tel_contacto = ?,
                    fecha_nacimiento = ?,
                    fecha_ingreso = ?,
                    fecha_baja = ?,
                    motivo_baja = ?,
                    curp = ?,
                    nss = ?,
                    rfc = ?,
                    genero = ?,
                    contacto_emergencia = ?,
                    tel_emergencia = ?,
                    clabe = ?,
                    banco = ?,
                    edad_years = ?,
                    antiguedad_meses = ?,
                    antiguedad_years = ?,
                    contrato_status = ?,
                    registro_patronal = ?,
                    fecha_alta_imss = ?,
                    talla_uniforme = ?,
                    payjoy_status = ?,
                    krediya_status = ?,
                    lespago_status = ?,
                    innovm_status = ?,
                    central_status = ?,
                    updated_at = NOW()
                WHERE usuario_id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "sssssssssssssiiisssssssssi",
            $tel_contacto,
            $fecha_nacimiento,
            $fecha_ingreso,
            $fecha_baja,
            $motivo_baja,
            $curp,
            $nss,
            $rfc,
            $genero,
            $contacto_emergencia,
            $tel_emergencia,
            $clabe,
            $banco,
            $edad_years,
            $antiguedad_meses,
            $antiguedad_years,
            $contrato_status,
            $registro_patronal,
            $fecha_alta_imss,
            $talla_uniforme,
            $payjoy_status,
            $krediya_status,
            $lespago_status,
            $innovm_status,
            $central_status,
            $usuarioId
        );
        $stmt->execute();
        $stmt->close();

        $flashOk = "Expediente actualizado correctamente.";
    } catch (Throwable $e) {
        $flashError = "No se pudo guardar el expediente: " . $e->getMessage();
    }
}

/* =========================================================
   Cargar usuario + expediente
========================================================= */
$sql = "SELECT
            u.id,
            u.nombre,
            u.usuario,
            u.correo,
            u.rol,
            u.activo,
            u.id_sucursal,
            s.nombre AS sucursal_nombre,
            s.zona AS sucursal_zona,

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
            ue.central_status,
            ue.created_at,
            ue.updated_at
        FROM usuarios u
        LEFT JOIN sucursales s ON s.id = u.id_sucursal
        LEFT JOIN usuarios_expediente ue ON ue.usuario_id = u.id
        WHERE u.id = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $usuarioId);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_assoc();
$stmt->close();

if (!$data) {
    die("No se encontró el usuario.");
}

/* =========================================================
   Documentos del usuario
========================================================= */
$documentos = [];
$sqlDocs = "SELECT
                ud.id,
                ud.usuario_id,
                ud.doc_tipo_id,
                ud.version,
                ud.ruta,
                ud.nombre_original,
                ud.mime,
                ud.tamano,
                ud.hash_sha256,
                ud.vigente,
                ud.subido_por,
                ud.notas,
                ud.subido_en,
                dt.codigo AS doc_codigo,
                dt.nombre AS doc_nombre,
                dt.requerido
            FROM usuario_documentos ud
            INNER JOIN doc_tipos dt ON dt.id = ud.doc_tipo_id
            WHERE ud.usuario_id = ?
            ORDER BY dt.nombre ASC, ud.vigente DESC, ud.version DESC";
$stmt = $conn->prepare($sqlDocs);
$stmt->bind_param("i", $usuarioId);
$stmt->execute();
$resDocs = $stmt->get_result();
while ($row = $resDocs->fetch_assoc()) {
    $documentos[] = $row;
}
$stmt->close();

/* =========================================================
   Docs requeridos: catálogo + vigentes
========================================================= */
$docTiposReq = [];
$rsReq = $conn->query("SELECT id, codigo, nombre FROM doc_tipos WHERE requerido = 1 ORDER BY nombre ASC");
while ($r = $rsReq->fetch_assoc()) {
    $docTiposReq[(int)$r['id']] = $r;
}

$docsVigentesPorTipo = [];
foreach ($documentos as $doc) {
    if ((int)$doc['vigente'] === 1 && !isset($docsVigentesPorTipo[(int)$doc['doc_tipo_id']])) {
        $docsVigentesPorTipo[(int)$doc['doc_tipo_id']] = $doc;
    }
}

/* =========================================================
   Progreso expediente
========================================================= */
$filledCount = 0;
$missingFields = [];
foreach ($requiredFields as $f) {
    if (field_filled($data[$f] ?? null)) $filledCount++;
    else $missingFields[] = $f;
}

$docReqTotal = count($docTiposReq);
$docReqDone = 0;
$missingDocs = [];

foreach ($docTiposReq as $tid => $dt) {
    if (isset($docsVigentesPorTipo[$tid])) $docReqDone++;
    else $missingDocs[] = $dt['nombre'];
}

$totalItems = count($requiredFields) + $docReqTotal;
$totalDone  = $filledCount + $docReqDone;
$progressPct = $totalItems > 0 ? (int)floor(($totalDone / $totalItems) * 100) : 0;

$antiguedad = calc_antiguedad(
    $data['fecha_ingreso'] ?? null,
    isset($data['antiguedad_years']) ? (int)$data['antiguedad_years'] : null,
    isset($data['antiguedad_meses']) ? (int)$data['antiguedad_meses'] : null
);

$edad = calc_edad(
    $data['fecha_nacimiento'] ?? null,
    isset($data['edad_years']) ? (int)$data['edad_years'] : null
);

$foto = trim((string)($data['foto'] ?? ''));
$inicial = strtoupper(mb_substr(trim((string)$data['nombre']), 0, 1));

/* =========================================================
   Vacaciones: resumen centralizado
========================================================= */
$vacaciones = obtener_resumen_vacaciones_usuario($conn, $usuarioId);
$vacaciones['solicitudes_recientes'] = [];

$sqlSolicitudes = "
    SELECT id, fecha_inicio, fecha_fin, dias, motivo, status_jefe, status_admin, creado_en
    FROM vacaciones_solicitudes
    WHERE id_usuario = ?
    ORDER BY creado_en DESC, id DESC
    LIMIT 5
";
$stmt = $conn->prepare($sqlSolicitudes);
$stmt->bind_param("i", $usuarioId);
$stmt->execute();
$rsSol = $stmt->get_result();
while ($row = $rsSol->fetch_assoc()) {
    $vacaciones['solicitudes_recientes'][] = $row;
}
$stmt->close();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Expediente de <?= h($data['nombre']) ?></title>
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

    .user-head{
        display:flex;
        gap:18px;
        align-items:center;
    }

    .avatar{
        width:88px;
        height:88px;
        border-radius:24px;
        background:rgba(255,255,255,.15);
        border:1px solid rgba(255,255,255,.18);
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:30px;
        font-weight:800;
        overflow:hidden;
        flex:0 0 88px;
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
    }

    .btn-primary{
        background:var(--primary);
        color:#fff;
    }

    .btn-primary:hover{ filter:brightness(.98); transform:translateY(-1px); }

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

    .grid{
        display:grid;
        grid-template-columns:1.3fr .9fr;
        gap:18px;
    }

    .card{
        background:var(--card);
        border:1px solid var(--line);
        border-radius:var(--radius);
        box-shadow:var(--shadow);
        overflow:hidden;
    }

    .card-header{
        padding:16px 18px;
        border-bottom:1px solid #edf2f7;
        background:#fbfdff;
    }

    .card-header h2{
        margin:0;
        font-size:18px;
    }

    .card-body{
        padding:18px;
    }

    .section-block{
        margin-bottom:22px;
    }

    .section-block:last-child{
        margin-bottom:0;
    }

    .section-title{
        margin:0 0 14px;
        font-size:15px;
        color:#0f172a;
        font-weight:800;
    }

    .form-grid{
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:14px;
    }

    .field label{
        display:block;
        font-size:12px;
        font-weight:700;
        color:var(--muted);
        margin-bottom:6px;
    }

    .field input,
    .field select,
    .field textarea{
        width:100%;
        border:1px solid #d8e2ec;
        background:#fff;
        border-radius:14px;
        padding:11px 12px;
        font-size:14px;
        outline:none;
    }

    .field textarea{
        min-height:96px;
        resize:vertical;
    }

    .field input:focus,
    .field select:focus,
    .field textarea:focus{
        border-color:#93c5fd;
        box-shadow:0 0 0 4px rgba(59,130,246,.12);
    }

    .span-2{ grid-column:span 2; }

    .info-grid{
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:12px;
    }

    .info-item{
        border:1px solid #edf2f7;
        border-radius:16px;
        padding:12px 14px;
        background:#fcfdff;
    }

    .info-item .label{
        font-size:12px;
        color:var(--muted);
        margin-bottom:4px;
    }

    .info-item .value{
        font-size:15px;
        font-weight:700;
        color:#111827;
        word-break:break-word;
    }

    .chips{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
    }

    .docs-list{
        display:grid;
        gap:12px;
    }

    .doc-row{
        border:1px solid #e8eef5;
        background:#fcfdff;
        border-radius:16px;
        padding:14px;
        display:flex;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
    }

    .doc-left{
        display:flex;
        gap:12px;
        align-items:flex-start;
        flex:1;
        min-width:240px;
    }

    .doc-icon{
        width:44px;
        height:44px;
        border-radius:14px;
        background:#e0ecff;
        color:#1d4ed8;
        display:flex;
        align-items:center;
        justify-content:center;
        font-size:12px;
        font-weight:800;
        flex:0 0 44px;
        border:1px solid #c9dcff;
    }

    .doc-title{
        font-weight:800;
        margin-bottom:4px;
    }

    .doc-actions{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        align-items:center;
    }

    .empty{
        text-align:center;
        color:var(--muted);
        padding:18px;
        border:1px dashed #d7e0ea;
        border-radius:16px;
        background:#fcfdff;
    }

    .missing-box{
        border:1px dashed #f5c27a;
        background:#fffaf0;
        border-radius:16px;
        padding:14px;
    }

    .missing-box h3{
        margin:0 0 10px;
        font-size:14px;
        color:#9a5b00;
    }

    .missing-list{
        display:flex;
        flex-wrap:wrap;
        gap:8px;
    }

    .missing-chip{
        display:inline-block;
        padding:6px 10px;
        border-radius:999px;
        background:#fff;
        border:1px solid #f3d19c;
        color:#8a5500;
        font-size:12px;
    }

    .vac-grid{
        display:grid;
        grid-template-columns:repeat(3,minmax(0,1fr));
        gap:10px;
        margin-bottom:14px;
    }

    .vac-kpi{
        border:1px solid #e8eef5;
        background:#fcfdff;
        border-radius:16px;
        padding:14px;
    }

    .vac-kpi .label{
        font-size:12px;
        color:var(--muted);
        margin-bottom:6px;
    }

    .vac-kpi .value{
        font-size:26px;
        font-weight:900;
        line-height:1;
    }

    .vac-meta{
        display:grid;
        gap:8px;
        margin-bottom:14px;
    }

    .vac-meta .line{
        display:flex;
        justify-content:space-between;
        gap:10px;
        padding:10px 12px;
        border:1px solid #edf2f7;
        border-radius:14px;
        background:#fcfdff;
        font-size:14px;
    }

    .vac-list{
        display:grid;
        gap:10px;
    }

    .vac-item{
        border:1px solid #e8eef5;
        background:#fcfdff;
        border-radius:16px;
        padding:12px 14px;
    }

    .vac-item-top{
        display:flex;
        justify-content:space-between;
        gap:10px;
        flex-wrap:wrap;
        margin-bottom:6px;
    }

    .muted{
        color:var(--muted);
        font-size:13px;
    }

    @media (max-width:1100px){
        .hero-grid,
        .grid{
            grid-template-columns:1fr;
        }
    }

    @media (max-width:760px){
        .form-grid,
        .info-grid{
            grid-template-columns:1fr;
        }
        .span-2{ grid-column:span 1; }
        .hero h1{ font-size:25px; }
        .hero-kpis{ grid-template-columns:1fr; }
        .vac-grid{ grid-template-columns:1fr; }
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
                        <?php if ($foto !== ''): ?>
                            <img src="<?= h($foto) ?>" alt="Foto de <?= h($data['nombre']) ?>">
                        <?php else: ?>
                            <?= h($inicial !== '' ? $inicial : 'U') ?>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h1><?= h($data['nombre']) ?></h1>
                        <div class="meta">
                            Empleado #<?= (int)$data['id'] ?> · @<?= h($data['usuario']) ?> · <?= h($data['correo'] ?: 'Sin correo') ?>
                        </div>
                        <div>
                            <span class="pill light"><?= h($data['rol']) ?></span>
                            <span class="pill light"><?= h($data['sucursal_nombre'] ?: 'Sin sucursal') ?></span>
                            <span class="pill light"><?= h($data['sucursal_zona'] ?: 'Sin zona') ?></span>
                            <?php if ((int)$data['activo'] === 1): ?>
                                <span class="pill success">Activo</span>
                            <?php else: ?>
                                <span class="pill danger">Inactivo</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="hero-side">
                <div style="font-size:13px;color:rgba(255,255,255,.8);">Avance general del expediente</div>
                <div style="font-size:34px;font-weight:900;line-height:1;"><?= (int)$progressPct ?>%</div>
                <div class="progress"><span style="width: <?= (int)$progressPct ?>%"></span></div>
                <div style="font-size:13px;color:rgba(255,255,255,.8);">
                    <?= (int)$totalDone ?> de <?= (int)$totalItems ?> elementos completos
                </div>

                <div class="hero-kpis">
                    <div class="mini-kpi">
                        <div class="label">Edad</div>
                        <div class="value"><?= h($edad) ?></div>
                    </div>
                    <div class="mini-kpi">
                        <div class="label">Antigüedad</div>
                        <div class="value" style="font-size:18px;"><?= h($antiguedad) ?></div>
                    </div>
                    <div class="mini-kpi">
                        <div class="label">Docs requeridos</div>
                        <div class="value"><?= (int)$docReqDone ?>/<?= (int)$docReqTotal ?></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="toolbar">
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="admin_expedientes.php" class="btn btn-light">← Volver al panel</a>
            <a href="documentos_historial.php?usuario_id=<?= (int)$usuarioId ?>" class="btn btn-light">Ver historial de documentos</a>
            <a href="generar_caratula_expediente.php?usuario_id=<?= (int)$usuarioId ?>" class="btn btn-light" target="_blank">Carátula PDF</a>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="solicitar_vacaciones_v2.php?usuario_id=<?= (int)$usuarioId ?>" class="btn btn-light">Solicitar vacaciones</a>
            <a href="generar_formato_vacaciones.php?usuario_id=<?= (int)$usuarioId ?>" class="btn btn-primary" target="_blank">Formato vacaciones PDF</a>
        </div>
    </div>

    <?php if ($flashOk !== ''): ?>
        <div class="alert ok"><?= h($flashOk) ?></div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
        <div class="alert error"><?= h($flashError) ?></div>
    <?php endif; ?>

    <div class="grid">

        <section class="card">
            <div class="card-header">
                <h2>Editar expediente</h2>
            </div>
            <div class="card-body">
                <form method="post">

                    <div class="section-block">
                        <h3 class="section-title">Datos personales</h3>
                        <div class="form-grid">
                            <div class="field">
                                <label>Teléfono</label>
                                <input type="text" name="tel_contacto" value="<?= h($data['tel_contacto'] ?? '') ?>">
                            </div>

                            <div class="field">
                                <label>Fecha de nacimiento</label>
                                <input type="date" name="fecha_nacimiento" value="<?= h(!empty_date($data['fecha_nacimiento'] ?? '') ? $data['fecha_nacimiento'] : '') ?>">
                            </div>

                            <div class="field">
                                <label>CURP</label>
                                <input type="text" name="curp" maxlength="18" value="<?= h($data['curp'] ?? '') ?>">
                            </div>

                            <div class="field">
                                <label>NSS</label>
                                <input type="text" name="nss" maxlength="15" value="<?= h($data['nss'] ?? '') ?>">
                            </div>

                            <div class="field">
                                <label>RFC</label>
                                <input type="text" name="rfc" maxlength="13" value="<?= h($data['rfc'] ?? '') ?>">
                            </div>

                            <div class="field">
                                <label>Género</label>
                                <select name="genero">
                                    <option value="">Selecciona</option>
                                    <?php foreach ($opcionesGenero as $opt): ?>
                                        <option value="<?= h($opt) ?>" <?= ($data['genero'] ?? '') === $opt ? 'selected' : '' ?>>
                                            <?= h($opt) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label>Talla uniforme</label>
                                <select name="talla_uniforme">
                                    <option value="">Selecciona</option>
                                    <?php foreach ($opcionesTalla as $opt): ?>
                                        <option value="<?= h($opt) ?>" <?= ($data['talla_uniforme'] ?? '') === $opt ? 'selected' : '' ?>>
                                            <?= h($opt) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label>Banco</label>
                                <input type="text" name="banco" value="<?= h($data['banco'] ?? '') ?>">
                            </div>

                            <div class="field span-2">
                                <label>CLABE</label>
                                <input type="text" name="clabe" maxlength="18" value="<?= h($data['clabe'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="section-block">
                        <h3 class="section-title">Datos laborales</h3>
                        <div class="form-grid">
                            <div class="field">
                                <label>Fecha de ingreso</label>
                                <input type="date" name="fecha_ingreso" value="<?= h(!empty_date($data['fecha_ingreso'] ?? '') ? $data['fecha_ingreso'] : '') ?>">
                            </div>

                            <div class="field">
                                <label>Fecha de alta IMSS</label>
                                <input type="date" name="fecha_alta_imss" value="<?= h(!empty_date($data['fecha_alta_imss'] ?? '') ? $data['fecha_alta_imss'] : '') ?>">
                            </div>

                            <div class="field">
                                <label>Contrato</label>
                                <select name="contrato_status">
                                    <option value="">Selecciona</option>
                                    <?php foreach ($opcionesContrato as $opt): ?>
                                        <option value="<?= h($opt) ?>" <?= ($data['contrato_status'] ?? '') === $opt ? 'selected' : '' ?>>
                                            <?= h($opt) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label>Registro patronal</label>
                                <input type="text" name="registro_patronal" value="<?= h($data['registro_patronal'] ?? '') ?>">
                            </div>

                            <div class="field">
                                <label>Fecha de baja</label>
                                <input type="date" name="fecha_baja" value="<?= h(!empty_date($data['fecha_baja'] ?? '') ? $data['fecha_baja'] : '') ?>">
                            </div>

                            <div class="field">
                                <label>Motivo de baja</label>
                                <input type="text" name="motivo_baja" value="<?= h($data['motivo_baja'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="section-block">
                        <h3 class="section-title">Contacto de emergencia</h3>
                        <div class="form-grid">
                            <div class="field">
                                <label>Nombre del contacto</label>
                                <input type="text" name="contacto_emergencia" value="<?= h($data['contacto_emergencia'] ?? '') ?>">
                            </div>

                            <div class="field">
                                <label>Teléfono del contacto</label>
                                <input type="text" name="tel_emergencia" value="<?= h($data['tel_emergencia'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="section-block">
                        <h3 class="section-title">Plataformas</h3>
                        <div class="form-grid">
                            <?php
                            $platformFields = [
                                'payjoy_status'  => 'PayJoy',
                                'krediya_status' => 'Krediya',
                                'lespago_status' => 'LesPago',
                                'innovm_status'  => 'InnovM',
                                'central_status' => 'Central',
                            ];
                            foreach ($platformFields as $name => $label):
                            ?>
                                <div class="field">
                                    <label><?= h($label) ?></label>
                                    <select name="<?= h($name) ?>">
                                        <option value="">Selecciona</option>
                                        <?php foreach ($opcionesPlataforma as $opt): ?>
                                            <option value="<?= h($opt) ?>" <?= ($data[$name] ?? '') === $opt ? 'selected' : '' ?>>
                                                <?= h($opt) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button type="submit" name="guardar_expediente" value="1" class="btn btn-primary">Guardar expediente</button>
                        <a href="documentos_historial.php?usuario_id=<?= (int)$usuarioId ?>" class="btn btn-light">Gestionar documentos</a>
                    </div>
                </form>
            </div>
        </section>

        <aside style="display:grid; gap:18px;">
            <section class="card">
                <div class="card-header">
                    <h2>Resumen del empleado</h2>
                </div>
                <div class="card-body">
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="label">Número de empleado</div>
                            <div class="value">#<?= (int)$data['id'] ?></div>
                        </div>
                        <div class="info-item">
                            <div class="label">Puesto</div>
                            <div class="value"><?= h($data['rol']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="label">Sucursal</div>
                            <div class="value"><?= h($data['sucursal_nombre'] ?: '—') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="label">Zona</div>
                            <div class="value"><?= h($data['sucursal_zona'] ?: '—') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="label">Edad</div>
                            <div class="value"><?= h($edad) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="label">Antigüedad</div>
                            <div class="value"><?= h($antiguedad) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="label">Ingreso</div>
                            <div class="value"><?= h(fmt_date($data['fecha_ingreso'] ?? null)) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="label">Actualizado</div>
                            <div class="value"><?= h(fmt_date($data['updated_at'] ?? null)) ?></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-header">
                    <h2>Vacaciones</h2>
                </div>
                <div class="card-body">
                    <?php if (!$vacaciones['ok']): ?>
                        <div class="empty">
                            <?= h($vacaciones['mensaje'] ?: 'No hay fecha de ingreso válida para calcular vacaciones.') ?>
                        </div>
                    <?php else: ?>
                        <div class="vac-grid">
                            <div class="vac-kpi">
                                <div class="label">Corresponden</div>
                                <div class="value"><?= (int)$vacaciones['dias_otorgados'] ?></div>
                            </div>
                            <div class="vac-kpi">
                                <div class="label">Tomados</div>
                                <div class="value"><?= (int)$vacaciones['dias_tomados'] ?></div>
                            </div>
                            <div class="vac-kpi">
                                <div class="label">Disponibles</div>
                                <div class="value"><?= (int)$vacaciones['dias_disponibles'] ?></div>
                            </div>
                        </div>

                        <div class="vac-meta">
                            <div class="line">
                                <strong>Periodo actual</strong>
                                <span><?= h($vacaciones['vigencia_texto']) ?></span>
                            </div>
                            <div class="line">
                                <strong>Años cumplidos al inicio del periodo</strong>
                                <span><?= (int)$vacaciones['anios_cumplidos'] ?></span>
                            </div>
                            <div class="line">
                                <strong>Próximo aniversario</strong>
                                <span><?= h(fmt_date($vacaciones['periodo']['proximo_aniversario'] ?? null)) ?></span>
                            </div>
                        </div>

                        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px;">
                            <a href="solicitar_vacaciones_v2.php?usuario_id=<?= (int)$usuarioId ?>" class="btn btn-light">
                                Solicitar vacaciones
                            </a>
                            <a href="generar_formato_vacaciones.php?usuario_id=<?= (int)$usuarioId ?>" class="btn btn-primary">
                                Generar formato PDF
                            </a>
                        </div>

                        <div class="section-title" style="margin-bottom:10px;">Solicitudes recientes</div>

                        <?php if (empty($vacaciones['solicitudes_recientes'])): ?>
                            <div class="empty">Aún no hay solicitudes de vacaciones registradas.</div>
                        <?php else: ?>
                            <div class="vac-list">
                                <?php foreach ($vacaciones['solicitudes_recientes'] as $sol): ?>
                                    <?php
                                    $statusMostrar = (string)($sol['status_admin'] ?? 'Pendiente');
                                    if ($statusMostrar === 'Pendiente' && !empty($sol['status_jefe']) && $sol['status_jefe'] !== 'Pendiente') {
                                        $statusMostrar = 'Jefe: ' . $sol['status_jefe'];
                                    }
                                    ?>
                                    <div class="vac-item">
                                        <div class="vac-item-top">
                                            <div>
                                                <strong><?= h(fmt_date($sol['fecha_inicio'])) ?></strong>
                                                <span class="muted"> al </span>
                                                <strong><?= h(fmt_date($sol['fecha_fin'])) ?></strong>
                                            </div>
                                            <span class="pill <?= h(clase_badge_vacaciones((string)($sol['status_admin'] ?? 'Pendiente'))) ?>">
                                                <?= h($statusMostrar) ?>
                                            </span>
                                        </div>

                                        <div class="muted" style="margin-bottom:4px;">
                                            <?= (int)$sol['dias'] ?> día(s)
                                            <?php if (!empty($sol['creado_en'])): ?>
                                                · Solicitado: <?= h(fmt_date($sol['creado_en'])) ?>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($sol['motivo'])): ?>
                                            <div style="font-size:14px;"><?= h($sol['motivo']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card">
                <div class="card-header">
                    <h2>Faltantes del expediente</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($missingFields) && empty($missingDocs)): ?>
                        <div class="empty">Este expediente ya tiene completos los campos y documentos requeridos.</div>
                    <?php else: ?>
                        <?php if (!empty($missingFields)): ?>
                            <div class="missing-box" style="margin-bottom:12px;">
                                <h3>Campos pendientes</h3>
                                <div class="missing-list">
                                    <?php foreach ($missingFields as $field): ?>
                                        <span class="missing-chip"><?= h($field) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($missingDocs)): ?>
                            <div class="missing-box">
                                <h3>Documentos requeridos pendientes</h3>
                                <div class="missing-list">
                                    <?php foreach ($missingDocs as $docName): ?>
                                        <span class="missing-chip"><?= h($docName) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card">
                <div class="card-header">
                    <h2>Plataformas</h2>
                </div>
                <div class="card-body">
                    <div class="chips">
                        <?php
                        $platformShow = [
                            'PayJoy'  => $data['payjoy_status'] ?? '',
                            'Krediya' => $data['krediya_status'] ?? '',
                            'LesPago' => $data['lespago_status'] ?? '',
                            'InnovM'  => $data['innovm_status'] ?? '',
                            'Central' => $data['central_status'] ?? '',
                        ];

                        foreach ($platformShow as $label => $val):
                            $cls = 'warning';
                            if ($val === 'Si') $cls = 'success';
                            elseif ($val === 'No') $cls = 'danger';
                            elseif ($val === 'Bloqueado') $cls = 'danger';
                            elseif ($val === 'Pendiente') $cls = 'warning';
                        ?>
                            <span class="pill <?= h($cls) ?>">
                                <?= h($label) ?>: <?= h($val !== '' ? $val : 'Sin definir') ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="card">
                <div class="card-header">
                    <h2>Documentos digitalizados</h2>
                </div>
                <div class="card-body">
                    <?php if (!$documentos): ?>
                        <div class="empty">Este usuario aún no tiene documentos cargados.</div>
                    <?php else: ?>
                        <div class="docs-list">
                            <?php foreach ($documentos as $doc): ?>
                                <div class="doc-row">
                                    <div class="doc-left">
                                        <div class="doc-icon">
                                            <?= h(get_doc_icon((string)($doc['mime'] ?? ''), (string)($doc['nombre_original'] ?? ''))) ?>
                                        </div>
                                        <div>
                                            <div class="doc-title"><?= h($doc['doc_nombre']) ?></div>
                                            <div class="muted">
                                                <?= h($doc['nombre_original'] ?: 'Archivo sin nombre') ?>
                                                · v<?= (int)$doc['version'] ?>
                                                · <?= (int)$doc['tamano'] > 0 ? number_format(((int)$doc['tamano']) / 1024, 1) . ' KB' : '—' ?>
                                            </div>
                                            <div class="muted" style="margin-top:4px;">
                                                Subido: <?= h(fmt_date($doc['subido_en'] ?? null)) ?>
                                                <?php if ((int)$doc['vigente'] === 1): ?>
                                                    · <span style="color:#15803d;font-weight:700;">Vigente</span>
                                                <?php endif; ?>
                                                <?php if (!empty($doc['notas'])): ?>
                                                    · <?= h($doc['notas']) ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="doc-actions">
                                        <a class="btn btn-light" target="_blank" href="documento_descargar.php?id=<?= (int)$doc['id'] ?>">Ver</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top:14px;">
                        <a href="documentos_historial.php?usuario_id=<?= (int)$usuarioId ?>" class="btn btn-light">Abrir gestor de documentos</a>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>
</body>
</html>