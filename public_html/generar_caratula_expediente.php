<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

$mi_rol = $_SESSION['rol'] ?? 'Ejecutivo';
if (!in_array($mi_rol, ['Admin', 'Gerente'], true)) {
    http_response_code(403);
    exit('Sin permiso.');
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function empty_date($v): bool {
    return empty($v) || $v === '0000-00-00';
}

function fmt_date($v): string {
    if (empty_date($v)) return '-';
    $ts = strtotime((string)$v);
    return $ts ? date('d/m/Y', $ts) : '-';
}

function calc_antiguedad_detalle(?string $fechaIngreso): string {
    if (empty_date($fechaIngreso)) return '-';
    try {
        $inicio = new DateTime($fechaIngreso);
        $hoy    = new DateTime();
        $diff   = $inicio->diff($hoy);
        return "{$diff->y} año(s) {$diff->m} mes(es)";
    } catch (Throwable $e) {
        return '-';
    }
}

function calc_edad_detalle(?string $fechaNacimiento, ?int $edadGuardada = null): string {
    if (!empty_date($fechaNacimiento)) {
        try {
            $fn = new DateTime($fechaNacimiento);
            $hoy = new DateTime();
            return (string)$fn->diff($hoy)->y . ' años';
        } catch (Throwable $e) {}
    }
    return $edadGuardada !== null ? ((int)$edadGuardada . ' años') : '-';
}

function file_to_data_uri(string $path): ?string {
    if (!is_file($path)) return null;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'png'  => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        default => 'application/octet-stream'
    };
    $bin = @file_get_contents($path);
    if ($bin === false) return null;
    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

function safe_value($v): string {
    $v = trim((string)$v);
    return $v !== '' ? $v : '-';
}

function vacaciones_dias_por_anios_servicio(int $anios): int {
    if ($anios < 1) return 0;

    return match (true) {
        $anios === 1 => 12,
        $anios === 2 => 14,
        $anios === 3 => 16,
        $anios === 4 => 18,
        $anios === 5 => 20,
        $anios >= 6  => 22 + (int)floor(($anios - 6) / 5) * 2,
        default      => 0,
    };
}

function obtener_periodo_vacacional_actual(?string $fechaIngreso): ?array {
    if (empty_date($fechaIngreso)) return null;

    try {
        $ingreso = new DateTime($fechaIngreso);
        $hoy     = new DateTime();

        $anioActual = (int)$hoy->format('Y');
        $mesDiaIngreso = $ingreso->format('m-d');
        $aniversarioEsteAnio = new DateTime($anioActual . '-' . $mesDiaIngreso);

        if ($hoy >= $aniversarioEsteAnio) {
            $inicioPeriodo = clone $aniversarioEsteAnio;
        } else {
            $inicioPeriodo = (clone $aniversarioEsteAnio)->modify('-1 year');
        }

        $finPeriodo = (clone $inicioPeriodo)->modify('+1 year')->modify('-1 day');
        $aniosCumplidosAlInicio = (int)$ingreso->diff($inicioPeriodo)->y;

        return [
            'inicio' => $inicioPeriodo->format('Y-m-d'),
            'fin' => $finPeriodo->format('Y-m-d'),
            'anios_cumplidos' => $aniosCumplidosAlInicio,
            'label' => $inicioPeriodo->format('d/m/Y') . ' - ' . $finPeriodo->format('d/m/Y'),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function platform_badge_text(?string $status): string {
    $status = trim((string)$status);
    return $status !== '' ? $status : 'Sin definir';
}

/* =========================================================
   INPUT
========================================================= */
$usuarioId = (int)($_GET['usuario_id'] ?? 0);
if ($usuarioId <= 0) {
    exit('Usuario inválido.');
}

/* =========================================================
   CARGA DATOS EMPLEADO
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
$usuario = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$usuario) {
    exit('No se encontró el usuario.');
}

/* =========================================================
   DOCUMENTOS DEL USUARIO
========================================================= */
$documentos = [];
$sqlDocs = "SELECT
                ud.id,
                ud.doc_tipo_id,
                ud.version,
                ud.nombre_original,
                ud.mime,
                ud.tamano,
                ud.vigente,
                ud.notas,
                ud.subido_en,
                dt.codigo,
                dt.nombre,
                dt.requerido
            FROM usuario_documentos ud
            INNER JOIN doc_tipos dt ON dt.id = ud.doc_tipo_id
            WHERE ud.usuario_id = ?
            ORDER BY dt.nombre ASC, ud.vigente DESC, ud.version DESC";
$stmt = $conn->prepare($sqlDocs);
$stmt->bind_param("i", $usuarioId);
$stmt->execute();
$rsDocs = $stmt->get_result();
while ($row = $rsDocs->fetch_assoc()) {
    $documentos[] = $row;
}
$stmt->close();

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

$docReqTotal = count($docTiposReq);
$docReqDone = 0;
$docsPendientes = [];

foreach ($docTiposReq as $tid => $dt) {
    if (isset($docsVigentesPorTipo[$tid])) $docReqDone++;
    else $docsPendientes[] = $dt['nombre'];
}

$totalDocs = count($documentos);

/* =========================================================
   VACACIONES RESUMEN
========================================================= */
$vacaciones = [
    'periodo' => null,
    'dias_corresponden' => 0,
    'dias_tomados' => 0,
    'dias_disponibles' => 0,
];

$periodoVac = obtener_periodo_vacacional_actual($usuario['fecha_ingreso'] ?? null);
if ($periodoVac) {
    $diasDerecho = vacaciones_dias_por_anios_servicio((int)$periodoVac['anios_cumplidos']);

    $sqlTomados = "
        SELECT COALESCE(SUM(dias),0) AS dias_tomados
        FROM vacaciones_solicitudes
        WHERE id_usuario = ?
          AND LOWER(COALESCE(status_admin,'')) = 'aprobado'
          AND fecha_inicio >= ?
          AND fecha_inicio <= ?
    ";
    $stmt = $conn->prepare($sqlTomados);
    $stmt->bind_param("iss", $usuarioId, $periodoVac['inicio'], $periodoVac['fin']);
    $stmt->execute();
    $rowTom = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $diasTomados = (int)($rowTom['dias_tomados'] ?? 0);

    $vacaciones['periodo'] = $periodoVac;
    $vacaciones['dias_corresponden'] = $diasDerecho;
    $vacaciones['dias_tomados'] = $diasTomados;
    $vacaciones['dias_disponibles'] = max(0, $diasDerecho - $diasTomados);
}

/* =========================================================
   PROGRESO GENERAL EXPEDIENTE
========================================================= */
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

$camposCompletos = 0;
$camposFaltantes = [];
foreach ($requiredFields as $f) {
    $val = $usuario[$f] ?? null;
    $ok = trim((string)$val) !== '' && !empty_date((string)$val);
    if ($ok) $camposCompletos++;
    else $camposFaltantes[] = $f;
}

$totalItems = count($requiredFields) + $docReqTotal;
$totalDone  = $camposCompletos + $docReqDone;
$progressPct = $totalItems > 0 ? (int)floor(($totalDone / $totalItems) * 100) : 0;

/* =========================================================
   VISUALES
========================================================= */
$logoPath = __DIR__ . '/assets/logo_ticket.png';
$logoData = file_to_data_uri($logoPath);

$fotoData = null;
$fotoRaw = trim((string)($usuario['foto'] ?? ''));
if ($fotoRaw !== '') {
    $fotoAbs = $fotoRaw;
    if (!str_starts_with($fotoRaw, '/') && !preg_match('/^[A-Za-z]:\\\\/', $fotoRaw)) {
        $fotoAbs = __DIR__ . '/' . ltrim($fotoRaw, '/');
    }
    $fotoData = file_to_data_uri($fotoAbs);
}

$folio = 'EXP-' . str_pad((string)$usuarioId, 5, '0', STR_PAD_LEFT);
$fechaEmision = date('Y-m-d');

/* =========================================================
   HTML
========================================================= */
ob_start();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Carátula de expediente - <?= h($usuario['nombre']) ?></title>
<style>
    @page {
        margin: 18mm 14mm 16mm 14mm;
    }

    body{
        font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
        color:#1f2937;
        font-size:10.5px;
        line-height:1.32;
        margin:0;
        padding:0;
    }

    .header{
        width:100%;
        border-bottom:2px solid #1d4ed8;
        padding-bottom:10px;
        margin-bottom:12px;
    }

    .header-table{
        width:100%;
        border-collapse:collapse;
    }

    .header-table td{
        vertical-align:middle;
    }

    .logo-cell{ width:90px; }
    .logo{ width:72px; height:auto; }

    .title-wrap{
        text-align:center;
    }

    .empresa{
        font-size:13px;
        font-weight:bold;
        letter-spacing:.4px;
        color:#0f172a;
    }

    .titulo{
        font-size:18px;
        font-weight:bold;
        color:#1d4ed8;
        margin-top:2px;
    }

    .meta{
        width:160px;
        font-size:10px;
        text-align:right;
    }

    .meta-box{
        border:1px solid #cbd5e1;
        border-radius:6px;
        padding:6px 8px;
        background:#f8fafc;
    }

    .hero{
        width:100%;
        border-collapse:collapse;
        margin-bottom:12px;
    }

    .hero td{
        vertical-align:top;
    }

    .photo-col{
        width:120px;
        text-align:center;
    }

    .photo-box{
        border:1px solid #dbe2ea;
        border-radius:8px;
        padding:8px;
        background:#fcfdff;
    }

    .photo-box img{
        width:95px;
        height:110px;
        object-fit:cover;
        border-radius:8px;
        border:1px solid #cbd5e1;
    }

    .photo-placeholder{
        width:95px;
        height:110px;
        line-height:110px;
        text-align:center;
        border:1px solid #cbd5e1;
        border-radius:8px;
        background:#eef2f7;
        color:#475569;
        font-weight:bold;
        font-size:28px;
        margin:0 auto;
    }

    .hero-main{
        padding-right:12px;
    }

    .employee-name{
        font-size:20px;
        font-weight:bold;
        color:#0f172a;
        margin-bottom:4px;
    }

    .employee-sub{
        color:#475569;
        font-size:11px;
        margin-bottom:8px;
    }

    .summary-chip{
        display:inline-block;
        padding:4px 8px;
        border-radius:999px;
        font-size:10px;
        font-weight:bold;
        border:1px solid #cbd5e1;
        background:#f8fafc;
        margin-right:6px;
        margin-bottom:4px;
    }

    .section-title{
        font-size:11px;
        font-weight:bold;
        color:#0f172a;
        background:#eff6ff;
        border:1px solid #bfdbfe;
        padding:7px 9px;
        border-radius:6px;
        margin:12px 0 8px;
    }

    table.grid{
        width:100%;
        border-collapse:collapse;
        margin-bottom:8px;
    }

    table.grid td{
        border:1px solid #dbe2ea;
        padding:7px 8px;
        vertical-align:middle;
    }

    .label{
        width:24%;
        font-weight:bold;
        background:#f8fafc;
        color:#334155;
    }

    .value{
        width:26%;
    }

    .mini-note{
        border:1px solid #dbe2ea;
        background:#fcfdff;
        padding:9px 10px;
        border-radius:6px;
        margin-top:6px;
    }

    .two-cols{
        width:100%;
        border-collapse:separate;
        border-spacing:8px 0;
    }

    .two-cols td{
        width:50%;
        vertical-align:top;
    }

    .status-table{
        width:100%;
        border-collapse:collapse;
    }

    .status-table td{
        border:1px solid #dbe2ea;
        padding:7px 8px;
    }

    .status-label{
        font-weight:bold;
        background:#f8fafc;
        width:46%;
    }

    .footer{
        margin-top:12px;
        font-size:9px;
        color:#6b7280;
        text-align:right;
    }

    .list-line{
        padding:4px 0;
        border-bottom:1px dashed #e5e7eb;
    }
    .list-line:last-child{
        border-bottom:none;
    }
</style>
</head>
<body>

<div class="header">
    <table class="header-table">
        <tr>
            <td class="logo-cell">
                <?php if ($logoData): ?>
                    <img class="logo" src="<?= h($logoData) ?>" alt="Logo">
                <?php endif; ?>
            </td>
            <td class="title-wrap">
                <div class="empresa">LUGA PH S.A. DE C.V.</div>
                <div class="titulo">CARÁTULA DE EXPEDIENTE DEL EMPLEADO</div>
            </td>
            <td class="meta">
                <div class="meta-box">
                    <div><strong>Fecha:</strong> <?= h(fmt_date($fechaEmision)) ?></div>
                    <div><strong>Folio:</strong> <?= h($folio) ?></div>
                    <div><strong>Estatus expediente:</strong> <?= (int)$progressPct ?>%</div>
                </div>
            </td>
        </tr>
    </table>
</div>

<table class="hero">
    <tr>
        <td class="hero-main">
            <div class="employee-name"><?= h($usuario['nombre']) ?></div>
            <div class="employee-sub">
                Empleado #<?= (int)$usuario['id'] ?> · @<?= h($usuario['usuario']) ?> · <?= h(safe_value($usuario['correo'])) ?>
            </div>

            <div>
                <span class="summary-chip">Puesto: <?= h(safe_value($usuario['rol'])) ?></span>
                <span class="summary-chip">Sucursal: <?= h(safe_value($usuario['sucursal_nombre'])) ?></span>
                <span class="summary-chip">Zona: <?= h(safe_value($usuario['sucursal_zona'])) ?></span>
                <span class="summary-chip">Activo: <?= (int)$usuario['activo'] === 1 ? 'Sí' : 'No' ?></span>
            </div>

            <div style="margin-top:8px;">
                <span class="summary-chip">Ingreso: <?= h(fmt_date($usuario['fecha_ingreso'])) ?></span>
                <span class="summary-chip">Antigüedad: <?= h(calc_antiguedad_detalle($usuario['fecha_ingreso'])) ?></span>
                <span class="summary-chip">Edad: <?= h(calc_edad_detalle($usuario['fecha_nacimiento'] ?? null, isset($usuario['edad_years']) ? (int)$usuario['edad_years'] : null)) ?></span>
            </div>
        </td>
        <td class="photo-col">
            <div class="photo-box">
                <?php if ($fotoData): ?>
                    <img src="<?= h($fotoData) ?>" alt="Foto empleado">
                <?php else: ?>
                    <div class="photo-placeholder">
                        <?= h(strtoupper(mb_substr(trim((string)$usuario['nombre']), 0, 1)) ?: 'U') ?>
                    </div>
                <?php endif; ?>
                <div style="margin-top:6px;color:#64748b;font-size:9px;">Fotografía del empleado</div>
            </div>
        </td>
    </tr>
</table>

<div class="section-title">Datos personales</div>
<table class="grid">
    <tr>
        <td class="label">Teléfono</td>
        <td class="value"><?= h(safe_value($usuario['tel_contacto'])) ?></td>
        <td class="label">Fecha de nacimiento</td>
        <td class="value"><?= h(fmt_date($usuario['fecha_nacimiento'])) ?></td>
    </tr>
    <tr>
        <td class="label">CURP</td>
        <td class="value"><?= h(safe_value($usuario['curp'])) ?></td>
        <td class="label">RFC</td>
        <td class="value"><?= h(safe_value($usuario['rfc'])) ?></td>
    </tr>
    <tr>
        <td class="label">NSS</td>
        <td class="value"><?= h(safe_value($usuario['nss'])) ?></td>
        <td class="label">Género</td>
        <td class="value"><?= h(safe_value($usuario['genero'])) ?></td>
    </tr>
    <tr>
        <td class="label">Talla uniforme</td>
        <td class="value"><?= h(safe_value($usuario['talla_uniforme'])) ?></td>
        <td class="label">Correo electrónico</td>
        <td class="value"><?= h(safe_value($usuario['correo'])) ?></td>
    </tr>
</table>

<div class="section-title">Datos laborales</div>
<table class="grid">
    <tr>
        <td class="label">Fecha de ingreso</td>
        <td class="value"><?= h(fmt_date($usuario['fecha_ingreso'])) ?></td>
        <td class="label">Fecha alta IMSS</td>
        <td class="value"><?= h(fmt_date($usuario['fecha_alta_imss'])) ?></td>
    </tr>
    <tr>
        <td class="label">Contrato</td>
        <td class="value"><?= h(safe_value($usuario['contrato_status'])) ?></td>
        <td class="label">Registro patronal</td>
        <td class="value"><?= h(safe_value($usuario['registro_patronal'])) ?></td>
    </tr>
    <tr>
        <td class="label">Banco</td>
        <td class="value"><?= h(safe_value($usuario['banco'])) ?></td>
        <td class="label">CLABE</td>
        <td class="value"><?= h(safe_value($usuario['clabe'])) ?></td>
    </tr>
    <tr>
        <td class="label">Fecha de baja</td>
        <td class="value"><?= h(fmt_date($usuario['fecha_baja'])) ?></td>
        <td class="label">Motivo de baja</td>
        <td class="value"><?= h(safe_value($usuario['motivo_baja'])) ?></td>
    </tr>
</table>

<div class="section-title">Contacto de emergencia</div>
<table class="grid">
    <tr>
        <td class="label">Contacto</td>
        <td class="value"><?= h(safe_value($usuario['contacto_emergencia'])) ?></td>
        <td class="label">Teléfono</td>
        <td class="value"><?= h(safe_value($usuario['tel_emergencia'])) ?></td>
    </tr>
</table>

<table class="two-cols">
    <tr>
        <td>
            <div class="section-title">Plataformas</div>
            <table class="status-table">
                <tr>
                    <td class="status-label">PayJoy</td>
                    <td><?= h(platform_badge_text($usuario['payjoy_status'] ?? null)) ?></td>
                </tr>
                <tr>
                    <td class="status-label">Krediya</td>
                    <td><?= h(platform_badge_text($usuario['krediya_status'] ?? null)) ?></td>
                </tr>
                <tr>
                    <td class="status-label">LesPago</td>
                    <td><?= h(platform_badge_text($usuario['lespago_status'] ?? null)) ?></td>
                </tr>
                <tr>
                    <td class="status-label">InnovM</td>
                    <td><?= h(platform_badge_text($usuario['innovm_status'] ?? null)) ?></td>
                </tr>
                <tr>
                    <td class="status-label">Central</td>
                    <td><?= h(platform_badge_text($usuario['central_status'] ?? null)) ?></td>
                </tr>
            </table>
        </td>
        <td>
            <div class="section-title">Vacaciones</div>
            <table class="status-table">
                <tr>
                    <td class="status-label">Periodo vigente</td>
                    <td><?= h($vacaciones['periodo']['label'] ?? '-') ?></td>
                </tr>
                <tr>
                    <td class="status-label">Días con derecho</td>
                    <td><?= (int)$vacaciones['dias_corresponden'] ?></td>
                </tr>
                <tr>
                    <td class="status-label">Días tomados</td>
                    <td><?= (int)$vacaciones['dias_tomados'] ?></td>
                </tr>
                <tr>
                    <td class="status-label">Días disponibles</td>
                    <td><?= (int)$vacaciones['dias_disponibles'] ?></td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<table class="two-cols">
    <tr>
        <td>
            <div class="section-title">Resumen documental</div>
            <table class="status-table">
                <tr>
                    <td class="status-label">Documentos requeridos</td>
                    <td><?= (int)$docReqDone ?> / <?= (int)$docReqTotal ?></td>
                </tr>
                <tr>
                    <td class="status-label">Documentos totales</td>
                    <td><?= (int)$totalDocs ?></td>
                </tr>
                <tr>
                    <td class="status-label">Avance expediente</td>
                    <td><?= (int)$progressPct ?>%</td>
                </tr>
            </table>

            <div class="mini-note">
                <strong>Documentos requeridos pendientes:</strong><br>
                <?php if (empty($docsPendientes)): ?>
                    Todos los documentos requeridos se encuentran cargados.
                <?php else: ?>
                    <?php foreach ($docsPendientes as $i => $docName): ?>
                        <?= $i > 0 ? ' · ' : '' ?><?= h($docName) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </td>
        <td>
            <div class="section-title">Observaciones RH</div>
            <div class="mini-note">
                Esta carátula resume la información principal del expediente del empleado para consulta administrativa,
                control documental y seguimiento de vacaciones, plataformas y datos laborales.
            </div>

            <div class="mini-note" style="margin-top:8px;">
                <strong>Actualización del expediente:</strong><br>
                Creado: <?= h(fmt_date($usuario['created_at'] ?? null)) ?><br>
                Última actualización: <?= h(fmt_date($usuario['updated_at'] ?? null)) ?>
            </div>
        </td>
    </tr>
</table>

<div class="footer">
    Carátula generada desde La Central - LUGA
</div>

</body>
</html>
<?php
$html = ob_get_clean();

/* =========================================================
   SALIDA PDF
========================================================= */
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

$autoloadFound = null;
foreach ($autoloadPaths as $ap) {
    if (is_file($ap)) {
        $autoloadFound = $ap;
        break;
    }
}

if ($autoloadFound) {
    require_once $autoloadFound;

    if (class_exists(\Dompdf\Dompdf::class)) {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'caratula_expediente_' . $usuarioId . '.pdf';
        $dompdf->stream($filename, ['Attachment' => false]);
        exit;
    }
}

/* =========================================================
   FALLBACK HTML IMPRIMIBLE
========================================================= */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Vista previa - Carátula expediente</title>
<style>
    body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:20px}
    .topbar{max-width:900px;margin:0 auto 16px;background:#fff3cd;border:1px solid #ffe69c;color:#7c5700;padding:14px 16px;border-radius:10px}
    .wrap{max-width:900px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.06);overflow:hidden}
    .actions{padding:14px 16px;border-bottom:1px solid #e5e7eb;background:#fff}
    .actions button,.actions a{padding:10px 14px;border-radius:8px;border:1px solid #d1d5db;background:#fff;cursor:pointer;text-decoration:none;color:#111827;margin-right:8px}
    iframe{width:100%;height:85vh;border:0}
</style>
</head>
<body>
    <div class="topbar">
        No se detectó <strong>Dompdf</strong>. Se muestra una vista imprimible para guardar como PDF desde el navegador.
    </div>
    <div class="wrap">
        <div class="actions">
            <button onclick="window.print()">Imprimir / Guardar PDF</button>
            <a href="expediente_usuario.php?usuario_id=<?= (int)$usuarioId ?>">Volver al expediente</a>
        </div>
        <iframe srcdoc="<?= h($html) ?>"></iframe>
    </div>
</body>
</html>