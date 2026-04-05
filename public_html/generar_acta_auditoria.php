<?php
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
   CONTEXTO / PERMISOS
========================================================= */
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ROL         = trim((string)($_SESSION['rol'] ?? ''));
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

$ROLES_PERMITIDOS = [
    'Admin',
    'Administrador',
    'Auditor',
    'Logistica',
    'GerenteZona',
    'Gerente',
    'Supervisor'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para generar actas de auditoría.');
}

/* =========================================================
   HELPERS
========================================================= */
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmtFecha(?string $f, string $format = 'd/m/Y H:i'): string
{
    if (!$f || $f === '0000-00-00' || $f === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($f);
    return $ts ? date($format, $ts) : (string)$f;
}

function clampScore(float $value): int
{
    if ($value < 0) return 0;
    if ($value > 100) return 100;
    return (int)round($value);
}

function hasColumn(mysqli $conn, string $table, string $column): bool
{
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

/* =========================================================
   ID AUDITORIA
========================================================= */
$id_auditoria = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id_auditoria <= 0) {
    exit('Auditoría no válida.');
}

/* =========================================================
   CARGAR AUDITORIA
========================================================= */
$selectFirmaDigital = [];
$selectFirmaDigital[] = hasColumn($conn, 'auditorias', 'firmado_digitalmente') ? 'a.firmado_digitalmente' : '0 AS firmado_digitalmente';
$selectFirmaDigital[] = hasColumn($conn, 'auditorias', 'token_firma') ? 'a.token_firma' : "NULL AS token_firma";
$selectFirmaDigital[] = hasColumn($conn, 'auditorias', 'fecha_firma_final') ? 'a.fecha_firma_final' : "NULL AS fecha_firma_final";
$selectFirmaDigital[] = hasColumn($conn, 'auditorias', 'id_firma_auditor') ? 'a.id_firma_auditor' : "NULL AS id_firma_auditor";
$selectFirmaDigital[] = hasColumn($conn, 'auditorias', 'id_firma_gerente') ? 'a.id_firma_gerente' : "NULL AS id_firma_gerente";

$stmtAud = $conn->prepare("
    SELECT
        a.*,
        " . implode(",\n        ", $selectFirmaDigital) . ",
        s.nombre AS sucursal_nombre,
        s.zona AS sucursal_zona,
        s.tipo_sucursal,
        u1.nombre AS auditor_nombre,
        u2.nombre AS gerente_nombre,
        u3.nombre AS cerrada_por_nombre
    FROM auditorias a
    INNER JOIN sucursales s ON s.id = a.id_sucursal
    INNER JOIN usuarios u1 ON u1.id = a.id_auditor
    LEFT JOIN usuarios u2 ON u2.id = a.id_gerente
    LEFT JOIN usuarios u3 ON u3.id = a.cerrada_por
    WHERE a.id = ?
    LIMIT 1
");
$stmtAud->bind_param("i", $id_auditoria);
$stmtAud->execute();
$auditoria = $stmtAud->get_result()->fetch_assoc();

if (!$auditoria) {
    exit('La auditoría no existe.');
}

if (in_array($ROL, ['Gerente', 'Supervisor'], true) && (int)$auditoria['id_sucursal'] !== $ID_SUCURSAL) {
    http_response_code(403);
    exit('No puedes consultar una auditoría de otra sucursal.');
}

if (($auditoria['estatus'] ?? '') !== 'Cerrada') {
    exit('La auditoría aún no está cerrada. Primero debes cerrarla para generar el acta.');
}

/* =========================================================
   FALTANTES SERIALIZADOS
========================================================= */
$stmtFalt = $conn->prepare("
    SELECT *
    FROM auditorias_faltantes
    WHERE id_auditoria = ?
    ORDER BY marca ASC, modelo ASC, color ASC, capacidad ASC, imei1 ASC
");
$stmtFalt->bind_param("i", $id_auditoria);
$stmtFalt->execute();
$faltantes = $stmtFalt->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   DIFERENCIAS EN NO SERIALIZADOS
========================================================= */
$stmtDiff = $conn->prepare("
    SELECT *
    FROM auditorias_snapshot_cantidades
    WHERE id_auditoria = ?
      AND diferencia IS NOT NULL
      AND diferencia <> 0
    ORDER BY marca ASC, modelo ASC, color ASC, capacidad ASC
");
$stmtDiff->bind_param("i", $id_auditoria);
$stmtDiff->execute();
$diferencias = $stmtDiff->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   INCIDENCIAS
========================================================= */
$stmtInc = $conn->prepare("
    SELECT *
    FROM auditorias_incidencias
    WHERE id_auditoria = ?
    ORDER BY id ASC
");
$stmtInc->bind_param("i", $id_auditoria);
$stmtInc->execute();
$incidencias = $stmtInc->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   TOTALES NO SERIALIZADOS
========================================================= */
$stmtNS = $conn->prepare("
    SELECT
        SUM(COALESCE(cantidad_sistema, 0)) AS total_esperado,
        SUM(COALESCE(cantidad_contada, 0)) AS total_contado,
        SUM(GREATEST(COALESCE(cantidad_sistema, 0) - COALESCE(cantidad_contada, 0), 0)) AS total_faltante
    FROM auditorias_snapshot_cantidades
    WHERE id_auditoria = ?
");
$stmtNS->bind_param("i", $id_auditoria);
$stmtNS->execute();
$nsResumen = $stmtNS->get_result()->fetch_assoc() ?: [];

$nsTotalEsperado = (int)($nsResumen['total_esperado'] ?? 0);
$nsTotalContado  = (int)($nsResumen['total_contado'] ?? 0);
$nsTotalFaltante = (int)($nsResumen['total_faltante'] ?? 0);

/* =========================================================
   EVALUACIÓN DE AUDITORÍA
========================================================= */
$totalFaltantesSerial = (int)($auditoria['total_faltantes'] ?? count($faltantes));
$totalFaltantesNoSerial = $nsTotalFaltante;

$incOtraSucursal = 0;
$incNoLocalizado = 0;

foreach ($incidencias as $inc) {
    $tipo = trim((string)($inc['tipo_incidencia'] ?? ''));
    if ($tipo === 'Encontrado en otra sucursal') {
        $incOtraSucursal++;
    } elseif ($tipo === 'No localizado en sistema') {
        $incNoLocalizado++;
    }
}

$scoreRaw = 100
    - ($totalFaltantesSerial * 3)
    - ($totalFaltantesNoSerial * 2)
    - ($incOtraSucursal * 5)
    - ($incNoLocalizado * 4);

$score = clampScore($scoreRaw);

$nivel = 'Crítica';
$nivelClass = 'score-critical';
if ($score >= 95) {
    $nivel = 'Excelente';
    $nivelClass = 'score-excellent';
} elseif ($score >= 85) {
    $nivel = 'Buena';
    $nivelClass = 'score-good';
} elseif ($score >= 70) {
    $nivel = 'Regular';
    $nivelClass = 'score-regular';
}

$hallazgosTexto = [];
if ($totalFaltantesSerial > 0) {
    $hallazgosTexto[] = $totalFaltantesSerial . ' faltante(s) serializado(s)';
}
if ($totalFaltantesNoSerial > 0) {
    $hallazgosTexto[] = $totalFaltantesNoSerial . ' faltante(s) no serializado(s)';
}
if ($incOtraSucursal > 0) {
    $hallazgosTexto[] = $incOtraSucursal . ' incidencia(s) de equipo localizado en otra sucursal';
}
if ($incNoLocalizado > 0) {
    $hallazgosTexto[] = $incNoLocalizado . ' incidencia(s) de equipo no localizado en sistema';
}
$resumenEvaluacion = empty($hallazgosTexto)
    ? 'Inventario conciliado sin hallazgos que afecten la calificación.'
    : 'Hallazgos considerados para la evaluación: ' . implode(', ', $hallazgosTexto) . '.';

/* =========================================================
   CONFIG LOGO
========================================================= */
$logoPathCandidates = [
    __DIR__ . '/assets/logo_nano.png',
    __DIR__ . '/assets/logo_luga.png',
];

$logoWebPath = '';
foreach ($logoPathCandidates as $absPath) {
    if (file_exists($absPath)) {
        $logoWebPath = str_replace(__DIR__, '', $absPath);
        if ($logoWebPath === '') {
            $logoWebPath = basename($absPath);
        }
        $logoWebPath = ltrim(str_replace('\\', '/', $logoWebPath), '/');
        $logoWebPath = './' . $logoWebPath;
        break;
    }
}

$firmadoDigitalmente = (int)($auditoria['firmado_digitalmente'] ?? 0) === 1;
$tokenFirma          = (string)($auditoria['token_firma'] ?? '');
$fechaFirmaFinal     = (string)($auditoria['fecha_firma_final'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acta de Auditoría - <?= h($auditoria['folio']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --ink: #1f2937;
            --muted: #6b7280;
            --line: #d1d5db;
            --soft: #f3f4f6;
            --soft2: #f9fafb;
            --danger: #991b1b;
            --success: #065f46;
            --warning: #9a3412;
            --dark: #111827;
            --primary: #2563eb;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #eef2f7;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--ink);
        }

        .toolbar {
            width: 210mm;
            margin: 14px auto 0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-action {
            appearance: none;
            border: 0;
            border-radius: 10px;
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            color: #fff;
            background: #111827;
            box-shadow: 0 6px 18px rgba(0, 0, 0, .12);
        }

        .btn-action:hover { opacity: .92; }
        .btn-action.secondary { background: #2563eb; }
        .btn-action.success { background: #059669; }
        .btn-action[disabled] { background: #9ca3af; cursor: not-allowed; opacity: 1; }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 16px auto;
            background: #fff;
            box-shadow: 0 10px 28px rgba(0, 0, 0, .10);
            padding: 14mm 14mm 16mm;
        }

        .header {
            display: grid;
            grid-template-columns: 120px 1fr 150px;
            gap: 14px;
            align-items: center;
            border-bottom: 2px solid #111827;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }

        .logo-box {
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--line);
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }

        .logo-box img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .logo-fallback {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: 1px;
        }

        .doc-title { text-align: center; }

        .doc-title h1 {
            margin: 0 0 6px;
            font-size: 24px;
            line-height: 1.15;
            letter-spacing: .2px;
        }

        .doc-title .sub {
            color: var(--muted);
            font-size: 13px;
        }

        .folio-box {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            background: var(--soft2);
        }

        .folio-label {
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .folio-value {
            font-size: 16px;
            font-weight: 800;
            word-break: break-word;
        }

        .section { margin-bottom: 18px; }

        .section-title {
            margin: 0 0 10px;
            font-size: 16px;
            font-weight: 800;
            background: #111827;
            color: #fff;
            padding: 8px 12px;
            border-radius: 8px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px 14px;
        }

        .info-item {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            background: #fff;
        }

        .info-item.full { grid-column: 1 / -1; }

        .info-label {
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 700;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 12px;
        }

        .summary-card {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
            background: linear-gradient(180deg, #fff 0%, #f9fafb 100%);
        }

        .summary-label {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .summary-value {
            font-size: 26px;
            font-weight: 900;
            line-height: 1;
        }

        .summary-caption {
            margin-top: 4px;
            font-size: 11px;
            color: var(--muted);
        }

        .score-box {
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            padding: 14px;
        }

        .score-grid {
            display: grid;
            grid-template-columns: 170px 1fr;
            gap: 14px;
            align-items: center;
        }

        .score-badge {
            border-radius: 18px;
            padding: 16px 14px;
            text-align: center;
            border: 1px solid transparent;
        }

        .score-value {
            font-size: 36px;
            font-weight: 900;
            line-height: 1;
            margin-bottom: 6px;
        }

        .score-level {
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .score-excellent { background: #ecfdf5; color: #065f46; border-color: #a7f3d0; }
        .score-good { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
        .score-regular { background: #fff7ed; color: #9a3412; border-color: #fdba74; }
        .score-critical { background: #fef2f2; color: #991b1b; border-color: #fecaca; }

        .score-details {
            font-size: 13px;
            line-height: 1.55;
            color: #374151;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            table-layout: auto;
        }

        th, td {
            border: 1px solid var(--line);
            padding: 6px 7px;
            vertical-align: top;
            word-break: normal;
            overflow-wrap: break-word;
        }

        th {
            background: var(--soft);
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .2px;
            white-space: normal;
            line-height: 1.2;
        }

        td { line-height: 1.25; }

        .w-num { width: 38px; }
        .w-code { width: 120px; }
        .w-brand { width: 78px; }
        .w-model { width: 106px; }
        .w-color { width: 76px; }
        .w-cap { width: 76px; }
        .w-imei { width: 108px; }
        .w-status { width: 72px; }
        .w-qty { width: 78px; }
        .w-obs { width: 140px; }
        .w-type { width: 132px; }
        .w-ref { width: 148px; }

        .empty {
            border: 1px dashed var(--line);
            border-radius: 10px;
            padding: 12px 14px;
            background: var(--soft2);
            font-size: 13px;
            color: var(--muted);
        }

        .text-danger { color: var(--danger); font-weight: 800; }
        .text-success { color: var(--success); font-weight: 800; }

        .obs-box {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px 14px;
            background: #fff;
            min-height: 70px;
            white-space: pre-wrap;
            font-size: 13px;
        }

        .firma-digital-box {
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 16px;
            font-size: 13px;
            line-height: 1.55;
        }

        .firma-digital-box strong {
            display: block;
            font-size: 15px;
            margin-bottom: 6px;
            color: #1d4ed8;
        }

        .footer-sign {
            margin-top: 28px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 40px;
            align-items: end;
        }

        .sign-box { text-align: center; padding-top: 28px; }
        .sign-line { border-top: 1.8px solid #111827; margin-bottom: 8px; height: 1px; }
        .sign-name { font-size: 13px; font-weight: 800; }
        .sign-role { font-size: 12px; color: var(--muted); }
        .sign-meta {
            margin-top: 5px;
            font-size: 11px;
            color: #1d4ed8;
            font-weight: 700;
        }

        .note {
            margin-top: 22px;
            font-size: 11px;
            color: var(--muted);
            text-align: justify;
            line-height: 1.45;
        }

        .modal-backdrop-custom {
            position: fixed;
            inset: 0;
            background: rgba(17, 24, 39, 0.58);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 99999;
        }

        .modal-backdrop-custom.show { display: flex; }

        .modal-card-custom {
            width: 100%;
            max-width: 560px;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 20px 45px rgba(0,0,0,.20);
            overflow: hidden;
        }

        .modal-head-custom {
            padding: 18px 20px 12px;
            border-bottom: 1px solid #e5e7eb;
        }

        .modal-head-custom h3 {
            margin: 0;
            font-size: 21px;
            font-weight: 800;
            color: #111827;
        }

        .modal-body-custom {
            padding: 18px 20px;
        }

        .modal-foot-custom {
            padding: 14px 20px 20px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 14px;
        }

        .field label {
            font-size: 13px;
            font-weight: 700;
        }

        .field input {
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 14px;
            outline: none;
        }

        .field input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,.12);
        }

        .hint-box {
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 14px;
            font-size: 13px;
            color: #374151;
            line-height: 1.45;
        }

        .alert-inline {
            display: none;
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 14px;
            font-size: 13px;
            line-height: 1.45;
        }

        .alert-inline.error {
            display: block;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .alert-inline.success {
            display: block;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }

        .step-badge {
            display: inline-block;
            margin-bottom: 10px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #111827;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
        }

        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .page {
                width: auto;
                min-height: auto;
                margin: 0;
                box-shadow: none;
                padding: 0;
            }
            @page {
                size: A4;
                margin: 12mm;
            }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <?php if (!$firmadoDigitalmente): ?>
        <button type="button" class="btn-action success" onclick="abrirModalFirma()">Firmar con contraseñas</button>
        <button type="button" class="btn-action secondary" disabled>Descargar PDF</button>
        <button type="button" class="btn-action" disabled>Imprimir</button>
    <?php else: ?>
        <button type="button" class="btn-action secondary" onclick="window.print()">Descargar PDF</button>
        <button type="button" class="btn-action" onclick="window.print()">Imprimir</button>
    <?php endif; ?>
</div>

<div class="page">
    <div class="header">
        <div class="logo-box">
            <?php if ($logoWebPath): ?>
                <img src="<?= h($logoWebPath) ?>" alt="Logo">
            <?php else: ?>
                <div class="logo-fallback">NANO</div>
            <?php endif; ?>
        </div>

        <div class="doc-title">
            <h1>ACTA DE AUDITORÍA DE INVENTARIO</h1>
            <div class="sub">Control interno de inventario físico y conciliación contra sistema</div>
        </div>

        <div class="folio-box">
            <div class="folio-label">Folio de auditoría</div>
            <div class="folio-value"><?= h($auditoria['folio']) ?></div>
        </div>
    </div>

    <?php if ($firmadoDigitalmente): ?>
        <div class="firma-digital-box">
            <strong>Firmado digitalmente con contraseña</strong>
            Token de validación: <strong style="display:inline;color:#111827;"><?= h($tokenFirma) ?></strong><br>
            Fecha de firma: <?= h(fmtFecha($fechaFirmaFinal)) ?><br>
        </div>
    <?php else: ?>
        <div class="firma-digital-box" style="border-color:#fde68a;background:#fffbeb;">
            <strong style="color:#92400e;">Pendiente de firma digital</strong>
            Antes de imprimir o descargar esta acta, el auditor y el gerente o responsable presente deben validarla mediante sus contraseñas.
        </div>
    <?php endif; ?>

    <div class="section">
        <div class="section-title">Datos generales de la auditoría</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Sucursal</div>
                <div class="info-value"><?= h($auditoria['sucursal_nombre']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Zona</div>
                <div class="info-value"><?= h($auditoria['sucursal_zona'] ?: '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Tipo de sucursal</div>
                <div class="info-value"><?= h($auditoria['tipo_sucursal'] ?: '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Propiedad</div>
                <div class="info-value"><?= h($auditoria['propiedad'] ?: '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Auditor responsable</div>
                <div class="info-value"><?= h($auditoria['auditor_nombre']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Gerente / encargado presente</div>
                <div class="info-value"><?= h($auditoria['gerente_nombre'] ?: '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Fecha y hora de inicio</div>
                <div class="info-value"><?= h(fmtFecha($auditoria['fecha_inicio'])) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Fecha y hora de cierre</div>
                <div class="info-value"><?= h(fmtFecha($auditoria['fecha_cierre'])) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Cerrada por</div>
                <div class="info-value"><?= h($auditoria['cerrada_por_nombre'] ?: '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Estatus</div>
                <div class="info-value"><?= h($auditoria['estatus']) ?></div>
            </div>
            <div class="info-item full">
                <div class="info-label">Observaciones iniciales</div>
                <div class="info-value"><?= nl2br(h($auditoria['observaciones_inicio'] ?: '-')) ?></div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Evaluación de la auditoría</div>
        <div class="score-box">
            <div class="score-grid">
                <div class="score-badge <?= h($nivelClass) ?>">
                    <div class="score-value"><?= $score ?></div>
                    <div class="score-level"><?= h($nivel) ?></div>
                </div>
                <div class="score-details">
                    <strong>Resultado de evaluación:</strong> <?= h($resumenEvaluacion) ?><br>
                    Esta calificación parte de 100 puntos y descuenta hallazgos operativos relevantes para el control del inventario.
                </div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Resumen general de resultados</div>

        <div style="font-weight:800; margin:0 0 8px;">A. Resultados de productos serializados</div>
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">Esperados</div>
                <div class="summary-value"><?= (int)$auditoria['total_snapshot'] ?></div>
                <div class="summary-caption">Snapshot inicial</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Escaneados</div>
                <div class="summary-value"><?= (int)$auditoria['total_escaneados'] ?></div>
                <div class="summary-caption">Registros de escaneo</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Encontrados OK</div>
                <div class="summary-value text-success"><?= (int)$auditoria['total_ok'] ?></div>
                <div class="summary-caption">Coincidencia correcta</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Faltantes</div>
                <div class="summary-value text-danger"><?= (int)$auditoria['total_faltantes'] ?></div>
                <div class="summary-caption">No escaneados al cierre</div>
            </div>
        </div>

        <div style="font-weight:800; margin:14px 0 8px;">B. Resultados de productos no serializados</div>
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">Cantidad esperada</div>
                <div class="summary-value"><?= $nsTotalEsperado ?></div>
                <div class="summary-caption">Registrado en sistema</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Cantidad contada</div>
                <div class="summary-value"><?= $nsTotalContado ?></div>
                <div class="summary-caption">Conteo físico</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Faltantes</div>
                <div class="summary-value <?= $nsTotalFaltante > 0 ? 'text-danger' : 'text-success' ?>">
                    <?= $nsTotalFaltante ?>
                </div>
                <div class="summary-caption">Diferencia negativa detectada</div>
            </div>
            <div class="summary-card">
                <div class="summary-label">Incidencias</div>
                <div class="summary-value"><?= (int)$auditoria['total_incidencias'] ?></div>
                <div class="summary-caption">Eventos atípicos</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">Detalle de productos serializados faltantes</div>
        <?php if (!$faltantes): ?>
            <div class="empty">No se detectaron productos serializados faltantes al momento del cierre de la auditoría.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th class="w-num">#</th>
                        <th class="w-code">Código</th>
                        <th class="w-brand">Marca</th>
                        <th class="w-model">Modelo</th>
                        <th class="w-color">Color</th>
                        <th class="w-cap">Capacidad</th>
                        <th class="w-imei">IMEI 1</th>
                        <th class="w-imei">IMEI 2</th>
                        <th class="w-status">Estatus</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $n = 1; foreach ($faltantes as $row): ?>
                        <tr>
                            <td><?= $n++ ?></td>
                            <td><?= h($row['codigo_producto']) ?></td>
                            <td><?= h($row['marca']) ?></td>
                            <td><?= h($row['modelo']) ?></td>
                            <td><?= h($row['color']) ?></td>
                            <td><?= h($row['capacidad']) ?></td>
                            <td><?= h($row['imei1']) ?></td>
                            <td><?= h($row['imei2']) ?></td>
                            <td><?= h($row['estatus']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="section-title">Detalle de productos no serializados con diferencia</div>
        <?php if (!$diferencias): ?>
            <div class="empty">No se detectaron diferencias en productos no serializados.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th class="w-num">#</th>
                        <th class="w-code">Código</th>
                        <th class="w-brand">Marca</th>
                        <th class="w-model">Modelo</th>
                        <th class="w-color">Color</th>
                        <th class="w-cap">Capacidad</th>
                        <th class="w-qty">Cantidad esperada</th>
                        <th class="w-qty">Cantidad contada</th>
                        <th class="w-qty">Faltantes</th>
                        <th class="w-obs">Observaciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $n = 1; foreach ($diferencias as $row): ?>
                        <?php
                            $cantidadEsperada = (int)($row['cantidad_sistema'] ?? 0);
                            $cantidadContada  = (int)($row['cantidad_contada'] ?? 0);
                            $faltantesLinea   = max($cantidadEsperada - $cantidadContada, 0);
                        ?>
                        <tr>
                            <td><?= $n++ ?></td>
                            <td><?= h($row['codigo_producto']) ?></td>
                            <td><?= h($row['marca']) ?></td>
                            <td><?= h($row['modelo']) ?></td>
                            <td><?= h($row['color']) ?></td>
                            <td><?= h($row['capacidad']) ?></td>
                            <td><?= $cantidadEsperada ?></td>
                            <td><?= $cantidadContada ?></td>
                            <td class="<?= $faltantesLinea > 0 ? 'text-danger' : 'text-success' ?>"><?= $faltantesLinea ?></td>
                            <td><?= nl2br(h($row['observaciones'] ?: '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="section-title">Detalle de incidencias registradas</div>
        <?php if (!$incidencias): ?>
            <div class="empty">No se registraron incidencias durante la auditoría.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th class="w-num">#</th>
                        <th class="w-imei">IMEI / Identificador</th>
                        <th class="w-type">Tipo de incidencia</th>
                        <th>Detalle</th>
                        <th class="w-ref">Referencia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $n = 1; foreach ($incidencias as $row): ?>
                        <tr>
                            <td><?= $n++ ?></td>
                            <td><?= h($row['imei_escaneado']) ?></td>
                            <td><?= h($row['tipo_incidencia']) ?></td>
                            <td><?= h($row['detalle']) ?></td>
                            <td>
                                <?= h($row['referencia_tabla'] ?: '-') ?>
                                <?php if (!empty($row['referencia_id'])): ?>
                                    #<?= (int)$row['referencia_id'] ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="section-title">Observaciones finales de cierre</div>
        <div class="obs-box"><?= nl2br(h($auditoria['observaciones_cierre'] ?: 'Sin observaciones finales registradas.')) ?></div>
    </div>

    <div class="footer-sign">
        <div class="sign-box">
            <div class="sign-line"></div>
            <div class="sign-name"><?= h($auditoria['auditor_nombre']) ?></div>
            <div class="sign-role">Auditor responsable</div>
            <?php if ($firmadoDigitalmente): ?>
                <div class="sign-meta">Firmado digitalmente con contraseña</div>
            <?php endif; ?>
        </div>
        <div class="sign-box">
            <div class="sign-line"></div>
            <div class="sign-name"><?= h($auditoria['gerente_nombre'] ?: 'Nombre y firma') ?></div>
            <div class="sign-role">Gerente / encargado de sucursal</div>
            <?php if ($firmadoDigitalmente): ?>
                <div class="sign-meta">Firmado digitalmente con contraseña</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="note">
        El presente documento refleja los resultados finales de la auditoría de inventario realizada sobre la sucursal indicada,
        comparando el inventario esperado en sistema contra la evidencia física levantada durante la revisión. Las diferencias,
        faltantes e incidencias aquí señaladas constituyen evidencia documental para seguimiento interno y deberán resolverse
        conforme a los procedimientos administrativos y operativos aplicables.
    </div>
</div>

<div id="modalFirma" class="modal-backdrop-custom">
    <div class="modal-card-custom">
        <div class="modal-head-custom">
            <h3>Firmar acta con contraseñas</h3>
        </div>
        <div class="modal-body-custom">
            <div id="firmaAlert" class="alert-inline"></div>

            <div class="step-badge" id="firmaStepBadge">Paso 1 de 2</div>

            <div id="step1">
                <div class="hint-box">
                    Primero valida la contraseña del <strong>auditor responsable</strong>.
                </div>
                <div class="field">
                    <label>Auditor</label>
                    <input type="text" value="<?= h($auditoria['auditor_nombre']) ?>" disabled>
                </div>
                <div class="field">
                    <label for="password_auditor">Contraseña del auditor</label>
                    <input type="password" id="password_auditor" autocomplete="current-password">
                </div>
            </div>

            <div id="step2" style="display:none;">
                <div class="hint-box">
                    Ahora valida la contraseña del <strong>gerente o responsable presente</strong>.
                </div>
                <div class="field">
                    <label>Gerente / responsable presente</label>
                    <input type="text" value="<?= h($auditoria['gerente_nombre'] ?: '-') ?>" disabled>
                </div>
                <div class="field">
                    <label for="password_gerente">Contraseña del gerente / responsable</label>
                    <input type="password" id="password_gerente" autocomplete="current-password">
                </div>
            </div>
        </div>
        <div class="modal-foot-custom">
            <button type="button" class="btn-action" style="background:#6b7280;" onclick="cerrarModalFirma()">Cancelar</button>
            <button type="button" class="btn-action secondary" id="btnAnterior" style="display:none;" onclick="volverPaso1()">Anterior</button>
            <button type="button" class="btn-action success" id="btnSiguiente" onclick="irPaso2()">Siguiente</button>
            <button type="button" class="btn-action success" id="btnFirmar" style="display:none;" onclick="firmarActa()">Firmar acta</button>
        </div>
    </div>
</div>

<script>
const firmadoDigitalmente = <?= $firmadoDigitalmente ? 'true' : 'false' ?>;

function abrirModalFirma() {
    if (firmadoDigitalmente) return;
    document.getElementById('modalFirma').classList.add('show');
    limpiarAlert();
    document.getElementById('password_auditor').focus();
}

function cerrarModalFirma() {
    document.getElementById('modalFirma').classList.remove('show');
}

function limpiarAlert() {
    const alertBox = document.getElementById('firmaAlert');
    alertBox.className = 'alert-inline';
    alertBox.innerHTML = '';
}

function mostrarError(msg) {
    const alertBox = document.getElementById('firmaAlert');
    alertBox.className = 'alert-inline error';
    alertBox.innerHTML = msg;
}

function mostrarExito(msg) {
    const alertBox = document.getElementById('firmaAlert');
    alertBox.className = 'alert-inline success';
    alertBox.innerHTML = msg;
}

function irPaso2() {
    limpiarAlert();

    const passAuditor = document.getElementById('password_auditor').value.trim();
    if (!passAuditor) {
        mostrarError('Debes capturar la contraseña del auditor.');
        document.getElementById('password_auditor').focus();
        return;
    }

    document.getElementById('step1').style.display = 'none';
    document.getElementById('step2').style.display = 'block';
    document.getElementById('btnAnterior').style.display = 'inline-flex';
    document.getElementById('btnSiguiente').style.display = 'none';
    document.getElementById('btnFirmar').style.display = 'inline-flex';
    document.getElementById('firmaStepBadge').textContent = 'Paso 2 de 2';
    document.getElementById('password_gerente').focus();
}

function volverPaso1() {
    limpiarAlert();
    document.getElementById('step1').style.display = 'block';
    document.getElementById('step2').style.display = 'none';
    document.getElementById('btnAnterior').style.display = 'none';
    document.getElementById('btnSiguiente').style.display = 'inline-flex';
    document.getElementById('btnFirmar').style.display = 'none';
    document.getElementById('firmaStepBadge').textContent = 'Paso 1 de 2';
    document.getElementById('password_auditor').focus();
}

async function firmarActa() {
    limpiarAlert();

    const password_auditor = document.getElementById('password_auditor').value.trim();
    const password_gerente = document.getElementById('password_gerente').value.trim();

    if (!password_auditor) {
        volverPaso1();
        mostrarError('Debes capturar la contraseña del auditor.');
        return;
    }

    if (!password_gerente) {
        mostrarError('Debes capturar la contraseña del gerente o responsable.');
        document.getElementById('password_gerente').focus();
        return;
    }

    const btnFirmar = document.getElementById('btnFirmar');
    btnFirmar.disabled = true;
    btnFirmar.textContent = 'Firmando...';

    try {
        const formData = new FormData();
        formData.append('id_auditoria', '<?= (int)$id_auditoria ?>');
        formData.append('password_auditor', password_auditor);
        formData.append('password_gerente', password_gerente);

        const resp = await fetch('ajax_firmar_acta_auditoria.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const data = await resp.json();

        if (!data.ok) {
            mostrarError(data.msg || 'No fue posible firmar el acta.');
            return;
        }

        mostrarExito((data.msg || 'Acta firmada digitalmente.') + '<br>Token: <strong>' + (data.token || '') + '</strong>');

        setTimeout(() => {
            window.location.reload();
        }, 1200);

    } catch (e) {
        mostrarError('Error de comunicación al firmar el acta.');
    } finally {
        btnFirmar.disabled = false;
        btnFirmar.textContent = 'Firmar acta';
    }
}

document.addEventListener('keydown', function(e) {
    const modal = document.getElementById('modalFirma');
    if (e.key === 'Escape' && modal.classList.contains('show')) {
        cerrarModalFirma();
    }
});

document.getElementById('modalFirma').addEventListener('click', function(e) {
    if (e.target === this) {
        cerrarModalFirma();
    }
});
</script>

</body>
</html>