<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calcular_vacaciones.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

/* =========================================================
   CONFIG / PERMISOS
========================================================= */
date_default_timezone_set('America/Mexico_City');

$miRol       = trim((string)($_SESSION['rol'] ?? 'Ejecutivo'));
$miUsuarioId = (int)($_SESSION['id_usuario'] ?? 0);

/*
  Ajusta estos IDs según tus usuarios RH reales
*/
$idsRhAutorizados = [6, 8, 88];

/*
  Roles que sí pueden entrar a esta vista
  Puedes ampliar o recortar esta lista si quieres.
*/
$rolesPermitidos = ['Admin', 'Gerente', 'Jefe', 'Supervisor', 'Coordinador'];

$usuarioEsRh = in_array($miUsuarioId, $idsRhAutorizados, true);
$usuarioTieneRolPermitido = in_array($miRol, $rolesPermitidos, true);

if (!$usuarioEsRh && !$usuarioTieneRolPermitido) {
    http_response_code(403);
    exit('Sin permiso.');
}

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

function fmt_date_long($v): string {
    if (empty_date($v)) return '-';
    $meses = [
        1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
        7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'
    ];
    $ts = strtotime((string)$v);
    if (!$ts) return '-';
    $d = (int)date('j', $ts);
    $m = $meses[(int)date('n', $ts)] ?? '';
    $y = date('Y', $ts);
    return "{$d} de {$m} de {$y}";
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

function firma_nombre(?string $nombre): string {
    $n = trim((string)$nombre);
    return $n !== '' ? $n : '______________________________';
}

function salir_html_error(string $mensaje): void {
    http_response_code(400);
    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Formato de vacaciones</title>
        <style>
            body{
                font-family:Arial,sans-serif;
                background:#f3f4f6;
                margin:0;
                padding:30px;
                color:#111827;
            }
            .box{
                max-width:700px;
                margin:0 auto;
                background:#fff;
                border:1px solid #e5e7eb;
                border-radius:14px;
                box-shadow:0 8px 24px rgba(0,0,0,.06);
                overflow:hidden;
            }
            .head{
                background:#eff6ff;
                color:#1d4ed8;
                padding:16px 20px;
                font-weight:bold;
                border-bottom:1px solid #dbeafe;
            }
            .body{
                padding:22px 20px;
                line-height:1.5;
            }
            .actions{
                padding:0 20px 22px;
            }
            .btn{
                display:inline-block;
                text-decoration:none;
                padding:10px 14px;
                border-radius:8px;
                border:1px solid #d1d5db;
                color:#111827;
                background:#fff;
                font-weight:600;
            }
        </style>
    </head>
    <body>
        <div class="box">
            <div class="head">No se puede generar el formato</div>
            <div class="body"><?= h($mensaje) ?></div>
            <div class="actions">
                <a class="btn" href="javascript:history.back()">Volver</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/* =========================================================
   INPUTS
========================================================= */
/*
  Soporta:
  - ?id=SOLICITUD_ID                ← el que manda tu botón
  - ?solicitud_id=SOLICITUD_ID
  - ?usuario_id=USUARIO_ID
*/
$solicitudId = (int)($_GET['id'] ?? $_GET['solicitud_id'] ?? 0);
$usuarioId   = (int)($_GET['usuario_id'] ?? 0);

/* =========================================================
   SOLICITUD DE VACACIONES
========================================================= */
$solicitud = null;

if ($solicitudId > 0) {
    $sql = "SELECT *
            FROM vacaciones_solicitudes
            WHERE id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $solicitudId);
    $stmt->execute();
    $solicitud = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$solicitud) {
        salir_html_error('No se encontró la solicitud de vacaciones.');
    }

    $usuarioId = (int)($solicitud['id_usuario'] ?? 0);
} else {
    if ($usuarioId <= 0) {
        salir_html_error('Parámetros inválidos. Debes indicar una solicitud válida.');
    }

    $sql = "SELECT *
            FROM vacaciones_solicitudes
            WHERE id_usuario = ?
            ORDER BY creado_en DESC, id DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $usuarioId);
    $stmt->execute();
    $solicitud = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$solicitud) {
        salir_html_error('No se encontró una solicitud de vacaciones para este usuario.');
    }

    $solicitudId = (int)($solicitud['id'] ?? 0);
}

if ($usuarioId <= 0) {
    salir_html_error('La solicitud no tiene un usuario válido asociado.');
}

/* =========================================================
   VALIDACIÓN CRÍTICA DE AUTORIZACIÓN RH
========================================================= */
$statusAdmin = trim((string)($solicitud['status_admin'] ?? 'Pendiente'));

if ($statusAdmin !== 'Aprobado') {
    salir_html_error('La solicitud aún no ha sido autorizada por RH. Solo se puede generar el formato cuando el estado RH esté en "Aprobado".');
}

/* =========================================================
   USUARIO + EXPEDIENTE
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
            s.zona   AS sucursal_zona,
            ue.fecha_ingreso,
            ue.fecha_baja,
            ue.motivo_baja,
            ue.tel_contacto,
            ue.curp,
            ue.nss,
            ue.rfc,
            ue.foto,
            ue.contrato_status,
            ue.registro_patronal,
            ue.fecha_alta_imss,
            ue.contacto_emergencia,
            ue.tel_emergencia
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
    salir_html_error('No se encontró el usuario.');
}

if (empty_date($usuario['fecha_ingreso'] ?? null)) {
    salir_html_error('El empleado no tiene fecha de ingreso válida en usuarios_expediente.');
}

/* =========================================================
   JEFE INMEDIATO Y FIRMAS
========================================================= */
$jefeInmediato = trim((string)($_GET['jefe'] ?? 'Jefe Inmediato'));
$rhNombre      = trim((string)($_GET['rh'] ?? 'Recursos Humanos'));
$adminNombre   = trim((string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Administrador'));

/* =========================================================
   RESUMEN VACACIONAL CENTRALIZADO
========================================================= */
$solIdTmp = (int)($solicitud['id'] ?? 0);
$resumenVac = obtener_resumen_vacaciones_usuario($conn, $usuarioId, $solIdTmp);

if (!$resumenVac['ok']) {
    salir_html_error($resumenVac['mensaje'] ?: 'No fue posible calcular el periodo vacacional.');
}

$diasSolicitud = (int)($solicitud['dias'] ?? 0);
if ($diasSolicitud <= 0) {
    $diasSolicitud = calcular_dias_solicitud_rango(
        (string)$solicitud['fecha_inicio'],
        (string)$solicitud['fecha_fin']
    );
}

$periodo = $resumenVac['periodo'];
$diasDerecho = (int)$resumenVac['dias_otorgados'];
$diasDisfrutadosPrevios = (int)$resumenVac['dias_tomados'];
$diasPendientesDespues = max(0, $diasDerecho - $diasDisfrutadosPrevios - $diasSolicitud);
$diasTotalesConEsta = $diasDisfrutadosPrevios + $diasSolicitud;

$fechaInicio = (string)$solicitud['fecha_inicio'];
$fechaFin    = (string)$solicitud['fecha_fin'];
$fechaRetorno = calcular_fecha_retorno_vacaciones($fechaFin) ?: '';
$motivo = trim((string)($solicitud['motivo'] ?? ''));

/* =========================================================
   RECURSOS VISUALES
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

$folio = 'VAC-' . str_pad((string)$usuarioId, 4, '0', STR_PAD_LEFT) . '-' . str_pad((string)$solicitud['id'], 4, '0', STR_PAD_LEFT);
$fechaEmision = date('Y-m-d');

/* =========================================================
   HTML DEL DOCUMENTO
========================================================= */
ob_start();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Constancia de vacaciones - <?= h($usuario['nombre']) ?></title>
<style>
    @page {
        margin: 22mm 16mm 18mm 16mm;
    }

    body{
        font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
        color:#1f2937;
        font-size:11px;
        line-height:1.35;
        margin:0;
        padding:0;
    }

    .page{
        page-break-after: always;
    }
    .page:last-child{
        page-break-after: auto;
    }

    .header{
        width:100%;
        border-bottom:2px solid #1d4ed8;
        padding-bottom:10px;
        margin-bottom:14px;
    }

    .header-table{
        width:100%;
        border-collapse:collapse;
    }

    .header-table td{
        vertical-align:middle;
    }

    .logo-cell{
        width:90px;
    }

    .logo{
        width:72px;
        height:auto;
    }

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

    .section-title{
        font-size:12px;
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
        width:26%;
        font-weight:bold;
        background:#f8fafc;
        color:#334155;
    }

    .value{
        width:24%;
    }

    .label-wide{
        width:30%;
        font-weight:bold;
        background:#f8fafc;
        color:#334155;
    }

    .value-wide{
        width:70%;
    }

    .note{
        border:1px solid #dbe2ea;
        background:#fafcff;
        padding:10px 12px;
        border-radius:6px;
        margin-top:8px;
        text-align:justify;
    }

    .signatures{
        width:100%;
        border-collapse:collapse;
        margin-top:26px;
    }

    .signatures td{
        width:33.33%;
        text-align:center;
        vertical-align:bottom;
        padding:0 10px;
    }

    .sign-line{
        margin:34px auto 6px;
        border-top:1px solid #111827;
        width:88%;
        height:1px;
    }

    .sign-name{
        font-size:10px;
        font-weight:bold;
        color:#111827;
    }

    .sign-role{
        font-size:10px;
        color:#4b5563;
    }

    .muted{
        color:#6b7280;
    }

    .photo-box{
        margin-top:10px;
        border:1px solid #dbe2ea;
        border-radius:6px;
        padding:8px;
        text-align:center;
        background:#fcfdff;
    }

    .photo-box img{
        width:95px;
        height:95px;
        object-fit:cover;
        border-radius:8px;
        border:1px solid #cbd5e1;
    }

    .footer-mini{
        margin-top:10px;
        font-size:9px;
        color:#6b7280;
        text-align:right;
    }
</style>
</head>
<body>

<div class="page">
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
                    <div class="titulo">CONSTANCIA DE VACACIONES</div>
                </td>
                <td class="meta">
                    <div class="meta-box">
                        <div><strong>Fecha:</strong> <?= h(fmt_date($fechaEmision)) ?></div>
                        <div><strong>Folio:</strong> <?= h($folio) ?></div>
                        <div><strong>Estatus:</strong> <?= h($statusAdmin !== '' ? $statusAdmin : 'Pendiente') ?></div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section-title">Datos del empleado</div>
    <table class="grid">
        <tr>
            <td class="label">Nombre</td>
            <td class="value"><?= h($usuario['nombre']) ?></td>
            <td class="label">No. de empleado</td>
            <td class="value"><?= (int)$usuario['id'] ?></td>
        </tr>
        <tr>
            <td class="label">Sucursal / Area</td>
            <td class="value"><?= h($usuario['sucursal_nombre'] ?: '-') ?></td>
            <td class="label">Puesto</td>
            <td class="value"><?= h($usuario['rol'] ?: '-') ?></td>
        </tr>
        <tr>
            <td class="label">Fecha de ingreso</td>
            <td class="value"><?= h(fmt_date($usuario['fecha_ingreso'])) ?></td>
            <td class="label">Antiguedad</td>
            <td class="value"><?= h(calc_antiguedad_detalle($usuario['fecha_ingreso'])) ?></td>
        </tr>
    </table>

    <?php if ($fotoData): ?>
        <div class="photo-box" style="width:120px; float:right; margin-left:12px;">
            <img src="<?= h($fotoData) ?>" alt="Foto empleado">
            <div class="muted" style="margin-top:6px;">Foto del empleado</div>
        </div>
    <?php endif; ?>

    <div class="section-title">Solicitud de vacaciones</div>
    <div class="note">
        Se hace constar que el trabajador disfrutará de su periodo vacacional conforme a las fechas autorizadas que se indican a continuación.
    </div>

    <table class="grid">
        <tr>
            <td class="label">Vacaciones a tomar del</td>
            <td class="value"><?= h(fmt_date($fechaInicio)) ?></td>
            <td class="label">Al</td>
            <td class="value"><?= h(fmt_date($fechaFin)) ?></td>
        </tr>
        <tr>
            <td class="label">No. de días</td>
            <td class="value"><?= (int)$diasSolicitud ?></td>
            <td class="label">Presentándose el</td>
            <td class="value"><?= h(fmt_date($fechaRetorno)) ?></td>
        </tr>
        <tr>
            <td class="label-wide">Motivo / observaciones</td>
            <td class="value-wide" colspan="3"><?= h($motivo !== '' ? $motivo : '-') ?></td>
        </tr>
    </table>

    <div class="section-title">Datos exclusivos de Recursos Humanos</div>
    <table class="grid">
        <tr>
            <td class="label-wide">Periodo vacacional vigente (vigencia anual)</td>
            <td class="value-wide" colspan="3"><?= h($resumenVac['vigencia_texto']) ?></td>
        </tr>
        <tr>
            <td class="label">Días con derecho</td>
            <td class="value"><?= (int)$diasDerecho ?></td>
            <td class="label">Días disfrutados</td>
            <td class="value"><?= (int)$diasDisfrutadosPrevios ?></td>
        </tr>
        <tr>
            <td class="label">Días a disfrutar</td>
            <td class="value"><?= (int)$diasSolicitud ?></td>
            <td class="label">Días pendientes</td>
            <td class="value"><?= (int)$diasPendientesDespues ?></td>
        </tr>
    </table>

    <div class="note">
        Acepto y recibo a mi más entera conformidad el goce de mis vacaciones correspondientes al periodo antes señalado,
        así como el pago de la prima vacacional que conforme a la ley corresponde.
    </div>

    <table class="signatures">
        <tr>
            <td>
                <div class="sign-line"></div>
                <div class="sign-name"><?= h(firma_nombre($usuario['nombre'])) ?></div>
                <div class="sign-role">Empleado</div>
            </td>
            <td>
                <div class="sign-line"></div>
                <div class="sign-name"><?= h(firma_nombre($jefeInmediato)) ?></div>
                <div class="sign-role">Jefe inmediato</div>
            </td>
            <td>
                <div class="sign-line"></div>
                <div class="sign-name"><?= h(firma_nombre($rhNombre)) ?></div>
                <div class="sign-role">Recursos Humanos</div>
            </td>
        </tr>
    </table>

    <div class="footer-mini">
        Documento generado desde La Central - LUGA
    </div>
</div>

<div class="page">
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
                    <div class="titulo">CONSTANCIA DE DISFRUTE DE VACACIONES</div>
                </td>
                <td class="meta">
                    <div class="meta-box">
                        <div><strong>Fecha:</strong> <?= h(fmt_date($fechaEmision)) ?></div>
                        <div><strong>Folio:</strong> <?= h($folio) ?></div>
                        <div><strong>Vigencia:</strong> 1 año</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section-title">Datos del empleado</div>
    <table class="grid">
        <tr>
            <td class="label">Nombre</td>
            <td class="value"><?= h($usuario['nombre']) ?></td>
            <td class="label">No. de empleado</td>
            <td class="value"><?= (int)$usuario['id'] ?></td>
        </tr>
        <tr>
            <td class="label">Sucursal / Area</td>
            <td class="value"><?= h($usuario['sucursal_nombre'] ?: '-') ?></td>
            <td class="label">Puesto</td>
            <td class="value"><?= h($usuario['rol'] ?: '-') ?></td>
        </tr>
        <tr>
            <td class="label">Fecha de ingreso</td>
            <td class="value"><?= h(fmt_date($usuario['fecha_ingreso'])) ?></td>
            <td class="label">Antiguedad</td>
            <td class="value"><?= h(calc_antiguedad_detalle($usuario['fecha_ingreso'])) ?></td>
        </tr>
    </table>

    <div class="section-title">Periodo disfrutado</div>
    <table class="grid">
        <tr>
            <td class="label">Vacaciones tomadas del</td>
            <td class="value"><?= h(fmt_date($fechaInicio)) ?></td>
            <td class="label">Al</td>
            <td class="value"><?= h(fmt_date($fechaFin)) ?></td>
        </tr>
        <tr>
            <td class="label">Número de días</td>
            <td class="value"><?= (int)$diasSolicitud ?></td>
            <td class="label">Fecha de reincorporación</td>
            <td class="value"><?= h(fmt_date($fechaRetorno)) ?></td>
        </tr>
    </table>

    <div class="section-title">Periodo vacacional correspondiente</div>
    <table class="grid">
        <tr>
            <td class="label-wide">Periodo vacacional vigente</td>
            <td class="value-wide" colspan="3"><?= h($resumenVac['vigencia_texto']) ?></td>
        </tr>
        <tr>
            <td class="label">Días con derecho</td>
            <td class="value"><?= (int)$diasDerecho ?></td>
            <td class="label">Días disfrutados</td>
            <td class="value"><?= (int)$diasTotalesConEsta ?></td>
        </tr>
        <tr>
            <td class="label">Días pendientes</td>
            <td class="value"><?= (int)$diasPendientesDespues ?></td>
            <td class="label">Presentación</td>
            <td class="value"><?= h(fmt_date($fechaRetorno)) ?></td>
        </tr>
    </table>

    <div class="note">
        Por medio de la presente se hace constar que el trabajador disfrutó de los días de vacaciones correspondientes
        al periodo señalado, quedando asentado el control interno de días disfrutados y pendientes dentro de su vigencia anual.
    </div>

    <table class="signatures">
        <tr>
            <td>
                <div class="sign-line"></div>
                <div class="sign-name"><?= h(firma_nombre($usuario['nombre'])) ?></div>
                <div class="sign-role">Empleado</div>
            </td>
            <td>
                <div class="sign-line"></div>
                <div class="sign-name"><?= h(firma_nombre($jefeInmediato)) ?></div>
                <div class="sign-role">Jefe inmediato</div>
            </td>
            <td>
                <div class="sign-line"></div>
                <div class="sign-name"><?= h(firma_nombre($rhNombre)) ?></div>
                <div class="sign-role">Recursos Humanos</div>
            </td>
        </tr>
    </table>

    <div class="footer-mini">
        Generado por <?= h($adminNombre) ?> desde La Central - LUGA
    </div>
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

        $filename = 'constancia_vacaciones_' . $usuarioId . '_' . (int)$solicitud['id'] . '.pdf';
        $dompdf->stream($filename, ['Attachment' => false]);
        exit;
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Vista previa - Constancia de vacaciones</title>
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