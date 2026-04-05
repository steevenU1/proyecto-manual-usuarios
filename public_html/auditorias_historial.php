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
    exit('Sin permiso para consultar auditorías.');
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

function badgeClass(string $estatus): string {
    $estatus = trim($estatus);
    if ($estatus === 'Cerrada') return 'badge-green';
    if ($estatus === 'En proceso') return 'badge-blue';
    if ($estatus === 'Pendiente revision') return 'badge-amber';
    if ($estatus === 'Cancelada') return 'badge-red';
    return 'badge-gray';
}

/* =========================================================
   FILTROS
========================================================= */
$f_folio        = trim((string)($_GET['folio'] ?? ''));
$f_sucursal     = (int)($_GET['id_sucursal'] ?? 0);
$f_estatus      = trim((string)($_GET['estatus'] ?? ''));
$f_auditor      = trim((string)($_GET['auditor'] ?? ''));
$f_fecha_ini    = trim((string)($_GET['fecha_ini'] ?? ''));
$f_fecha_fin    = trim((string)($_GET['fecha_fin'] ?? ''));

/* =========================================================
   LISTA DE SUCURSALES PARA FILTRO
========================================================= */
$sucursales = [];
if (in_array($ROL, ['Admin', 'Administrador', 'Auditor', 'Logistica', 'GerenteZona'], true)) {
    $rsSuc = $conn->query("SELECT id, nombre FROM sucursales WHERE activo = 1 ORDER BY nombre ASC");
    $sucursales = $rsSuc ? $rsSuc->fetch_all(MYSQLI_ASSOC) : [];
} else {
    $stmtSuc = $conn->prepare("SELECT id, nombre FROM sucursales WHERE id = ? LIMIT 1");
    $stmtSuc->bind_param("i", $ID_SUCURSAL);
    $stmtSuc->execute();
    $resSuc = $stmtSuc->get_result();
    $sucursales = $resSuc ? $resSuc->fetch_all(MYSQLI_ASSOC) : [];
    if (!$f_sucursal) $f_sucursal = $ID_SUCURSAL;
}

/* =========================================================
   QUERY PRINCIPAL
========================================================= */
$sql = "
    SELECT
        a.*,
        s.nombre AS sucursal_nombre,
        s.zona AS sucursal_zona,
        u1.nombre AS auditor_nombre,
        u2.nombre AS gerente_nombre
    FROM auditorias a
    INNER JOIN sucursales s ON s.id = a.id_sucursal
    INNER JOIN usuarios u1 ON u1.id = a.id_auditor
    LEFT JOIN usuarios u2 ON u2.id = a.id_gerente
    WHERE 1=1
";

$params = [];
$types  = "";

/* Restricción por rol */
if (in_array($ROL, ['Gerente', 'Supervisor'], true)) {
    $sql .= " AND a.id_sucursal = ? ";
    $params[] = $ID_SUCURSAL;
    $types .= "i";
}

/* Filtros */
if ($f_folio !== '') {
    $sql .= " AND a.folio LIKE ? ";
    $params[] = '%' . $f_folio . '%';
    $types .= "s";
}

if ($f_sucursal > 0) {
    $sql .= " AND a.id_sucursal = ? ";
    $params[] = $f_sucursal;
    $types .= "i";
}

if ($f_estatus !== '') {
    $sql .= " AND a.estatus = ? ";
    $params[] = $f_estatus;
    $types .= "s";
}

if ($f_auditor !== '') {
    $sql .= " AND u1.nombre LIKE ? ";
    $params[] = '%' . $f_auditor . '%';
    $types .= "s";
}

if ($f_fecha_ini !== '') {
    $sql .= " AND DATE(a.fecha_inicio) >= ? ";
    $params[] = $f_fecha_ini;
    $types .= "s";
}

if ($f_fecha_fin !== '') {
    $sql .= " AND DATE(a.fecha_inicio) <= ? ";
    $params[] = $f_fecha_fin;
    $types .= "s";
}

$sql .= " ORDER BY a.id DESC ";

$stmt = $conn->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$auditorias = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   TOTALES REALES DE NO SERIALIZADOS
========================================================= */
$nsTotales = [];
$idsAuditorias = array_map(fn($a) => (int)$a['id'], $auditorias);

if (!empty($idsAuditorias)) {
    $idsSql = implode(',', array_map('intval', $idsAuditorias));

    $sqlNS = "
        SELECT
            id_auditoria,
            SUM(COALESCE(cantidad_sistema, 0)) AS requeridos,
            SUM(COALESCE(cantidad_contada, 0)) AS contados,
            SUM(COALESCE(diferencia, 0)) AS diferencia
        FROM auditorias_snapshot_cantidades
        WHERE id_auditoria IN ($idsSql)
        GROUP BY id_auditoria
    ";

    $rsNS = $conn->query($sqlNS);
    if ($rsNS) {
        while ($row = $rsNS->fetch_assoc()) {
            $nsTotales[(int)$row['id_auditoria']] = [
                'requeridos' => (int)$row['requeridos'],
                'contados'   => (int)$row['contados'],
                'diferencia' => (int)$row['diferencia'],
            ];
        }
    }
}

/* =========================================================
   KPIS BASE
========================================================= */
$totalAuditorias = count($auditorias);
$totalCerradas = 0;
$totalProceso = 0;
$totalConIncidencias = 0;

foreach ($auditorias as $a) {
    if (($a['estatus'] ?? '') === 'Cerrada') $totalCerradas++;
    if (($a['estatus'] ?? '') === 'En proceso') $totalProceso++;
    if ((int)($a['total_incidencias'] ?? 0) > 0) $totalConIncidencias++;
}

/* =========================================================
   KPIS EXTRA SOLO ADMIN / ADMINISTRADOR / LOGISTICA
========================================================= */
$mostrarKpiExtra = in_array($ROL, ['Admin', 'Administrador', 'Logistica'], true);

$kpiExtra = [
    'sucursal_mas_faltantes' => '-',
    'faltantes_maximos'      => 0,
    'sucursal_mayor_impacto' => '-',
    'impacto_mayor'          => 0.0,
    'impacto_total'          => 0.0,
    'promedio_faltantes'     => 0.0,
];

if ($mostrarKpiExtra && !empty($auditorias)) {
    $mapAuditoriaSucursal = [];
    $mapAuditoriaNombre   = [];

    foreach ($auditorias as $a) {
        $idAud = (int)$a['id'];
        $mapAuditoriaSucursal[$idAud] = (int)$a['id_sucursal'];
        $mapAuditoriaNombre[$idAud]   = (string)$a['sucursal_nombre'];
    }

    $faltantesPorSucursal = [];
    $impactoPorSucursal   = [];
    $faltantesTotales     = 0;
    $impactoTotal         = 0.0;

    foreach ($auditorias as $a) {
        $sid = (int)$a['id_sucursal'];
        $snom = (string)$a['sucursal_nombre'];
        if (!isset($faltantesPorSucursal[$sid])) {
            $faltantesPorSucursal[$sid] = ['nombre' => $snom, 'valor' => 0];
        }
        if (!isset($impactoPorSucursal[$sid])) {
            $impactoPorSucursal[$sid] = ['nombre' => $snom, 'valor' => 0.0];
        }
    }

    if (!empty($idsAuditorias)) {
        $idsSql = implode(',', array_map('intval', $idsAuditorias));

        /* ---------- Impacto y faltantes serializados ---------- */
        $sqlSerial = "
            SELECT
                snap.id_auditoria,
                COUNT(*) AS faltantes_serial,
                SUM(COALESCE(p.costo, 0)) AS impacto_serial
            FROM auditorias_snapshot snap
            LEFT JOIN productos p ON p.id = snap.id_producto
            WHERE snap.id_auditoria IN ($idsSql)
              AND COALESCE(snap.escaneado, 0) = 0
            GROUP BY snap.id_auditoria
        ";
        $rsSerial = $conn->query($sqlSerial);
        if ($rsSerial) {
            while ($row = $rsSerial->fetch_assoc()) {
                $idAud = (int)$row['id_auditoria'];
                $sid   = $mapAuditoriaSucursal[$idAud] ?? 0;
                if ($sid <= 0) continue;

                $falt = (int)($row['faltantes_serial'] ?? 0);
                $imp  = (float)($row['impacto_serial'] ?? 0);

                $faltantesPorSucursal[$sid]['valor'] += $falt;
                $impactoPorSucursal[$sid]['valor']   += $imp;

                $faltantesTotales += $falt;
                $impactoTotal     += $imp;
            }
        }

        /* ---------- Impacto y faltantes no serializados ---------- */
        $sqlNoSerial = "
            SELECT
                sc.id_auditoria,
                SUM(GREATEST(COALESCE(sc.cantidad_sistema, 0) - COALESCE(sc.cantidad_contada, 0), 0)) AS faltantes_ns,
                SUM(GREATEST(COALESCE(sc.cantidad_sistema, 0) - COALESCE(sc.cantidad_contada, 0), 0) * COALESCE(p.costo, 0)) AS impacto_ns
            FROM auditorias_snapshot_cantidades sc
            LEFT JOIN productos p ON p.id = sc.id_producto
            WHERE sc.id_auditoria IN ($idsSql)
            GROUP BY sc.id_auditoria
        ";
        $rsNoSerial = $conn->query($sqlNoSerial);
        if ($rsNoSerial) {
            while ($row = $rsNoSerial->fetch_assoc()) {
                $idAud = (int)$row['id_auditoria'];
                $sid   = $mapAuditoriaSucursal[$idAud] ?? 0;
                if ($sid <= 0) continue;

                $falt = (int)($row['faltantes_ns'] ?? 0);
                $imp  = (float)($row['impacto_ns'] ?? 0);

                $faltantesPorSucursal[$sid]['valor'] += $falt;
                $impactoPorSucursal[$sid]['valor']   += $imp;

                $faltantesTotales += $falt;
                $impactoTotal     += $imp;
            }
        }
    }

    $maxFaltantes = -1;
    foreach ($faltantesPorSucursal as $info) {
        if ((int)$info['valor'] > $maxFaltantes) {
            $maxFaltantes = (int)$info['valor'];
            $kpiExtra['sucursal_mas_faltantes'] = (string)$info['nombre'];
            $kpiExtra['faltantes_maximos'] = (int)$info['valor'];
        }
    }

    $maxImpacto = -1;
    foreach ($impactoPorSucursal as $info) {
        if ((float)$info['valor'] > $maxImpacto) {
            $maxImpacto = (float)$info['valor'];
            $kpiExtra['sucursal_mayor_impacto'] = (string)$info['nombre'];
            $kpiExtra['impacto_mayor'] = (float)$info['valor'];
        }
    }

    $kpiExtra['impacto_total'] = $impactoTotal;
    $kpiExtra['promedio_faltantes'] = $totalAuditorias > 0 ? round($faltantesTotales / $totalAuditorias, 1) : 0.0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Historial de Auditorías</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{
            margin:0;
            background:#f5f7fb;
            font-family:Arial, Helvetica, sans-serif;
            color:#1f2937;
        }
        .wrap{
            max-width:1550px;
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
        .card{
            background:#fff;
            border-radius:18px;
            box-shadow:0 10px 24px rgba(0,0,0,.06);
            padding:20px;
            margin-bottom:18px;
        }
        .section-title{
            margin:0 0 14px;
            font-size:20px;
            font-weight:800;
        }
        .grid{
            display:grid;
            grid-template-columns:repeat(4, 1fr);
            gap:16px;
        }
        .grid-extra{
            display:grid;
            grid-template-columns:repeat(4, 1fr);
            gap:16px;
        }
        .filter-grid{
            display:grid;
            grid-template-columns:repeat(6, 1fr);
            gap:14px;
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
        .kpi-value-sm{
            font-size:22px;
            font-weight:800;
            color:#111827;
            line-height:1.2;
        }
        .kpi-note{
            margin-top:8px;
            font-size:13px;
            color:#6b7280;
        }
        .kpi-danger .kpi-value,
        .kpi-danger .kpi-value-sm{
            color:#b91c1c;
        }
        .kpi-warning .kpi-value,
        .kpi-warning .kpi-value-sm{
            color:#b45309;
        }
        .kpi-primary .kpi-value,
        .kpi-primary .kpi-value-sm{
            color:#1d4ed8;
        }
        .field{
            display:flex;
            flex-direction:column;
            gap:7px;
        }
        label{
            font-weight:700;
            font-size:13px;
        }
        input, select{
            border:1px solid #d1d5db;
            border-radius:12px;
            padding:11px 12px;
            font-size:14px;
            outline:none;
            background:#fff;
        }
        input:focus, select:focus{
            border-color:#2563eb;
            box-shadow:0 0 0 3px rgba(37,99,235,.12);
        }
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
            text-decoration:none;
        }
        .btn-secondary{
            background:#e5e7eb;
            color:#111827;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
        }
        .table-wrap{
            overflow:auto;
            border:1px solid #e5e7eb;
            border-radius:16px;
        }
        table{
            width:100%;
            border-collapse:collapse;
            min-width:1600px;
            background:#fff;
        }
        thead th{
            background:#111827;
            color:#fff;
            font-size:13px;
            text-align:center;
            padding:12px 10px;
            white-space:nowrap;
            border-right:1px solid rgba(255,255,255,.08);
        }
        thead tr.group-row th{
            background:#0b1736;
            font-size:12px;
            text-transform:uppercase;
            letter-spacing:.4px;
        }
        thead tr.sub-row th{
            background:#10214b;
            font-size:12px;
        }
        thead .th-serial{
            background:#0e1f4d !important;
        }
        thead .th-no-serial{
            background:#1f3e94 !important;
        }
        tbody td{
            border-bottom:1px solid #eef2f7;
            padding:12px 10px;
            font-size:13px;
            vertical-align:middle;
        }
        tbody tr:hover{
            background:#f9fafb;
        }
        .text-left{ text-align:left; }
        .text-center{ text-align:center; }

        .badge{
            display:inline-block;
            padding:6px 10px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            white-space:nowrap;
        }
        .badge-blue{ background:#eef2ff; color:#3730a3; }
        .badge-green{ background:#ecfdf5; color:#065f46; }
        .badge-red{ background:#fef2f2; color:#991b1b; }
        .badge-amber{ background:#fff7ed; color:#9a3412; }
        .badge-gray{ background:#f3f4f6; color:#374151; }

        .num-ok{
            color:#065f46;
            font-weight:800;
        }
        .num-bad{
            color:#991b1b;
            font-weight:800;
        }
        .num-neutral{
            color:#111827;
            font-weight:700;
        }

        .acciones-wrap{
            display:flex;
            flex-direction:column;
            align-items:center;
            gap:8px;
            min-width:110px;
        }
        .btn-mini{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:96px;
            padding:8px 12px;
            border-radius:12px;
            font-size:12px;
            font-weight:700;
            text-decoration:none;
            line-height:1;
            box-sizing:border-box;
            transition:.18s ease;
            border:1px solid transparent;
        }
        .btn-mini:hover{
            transform:translateY(-1px);
        }
        .btn-mini-gray{
            background:#eef0f4;
            color:#111827;
            border-color:#e5e7eb;
        }
        .btn-mini-blue{
            background:#dbeafe;
            color:#1d4ed8;
            border-color:#bfdbfe;
        }
        .btn-mini-dark{
            background:#111827;
            color:#fff;
            border-color:#111827;
        }
        .btn-mini-disabled{
            background:#f3f4f6;
            color:#9ca3af;
            border-color:#e5e7eb;
            cursor:default;
        }

        .notice{
            margin-top:10px;
            padding:14px 16px;
            border:1px dashed #cbd5e1;
            border-radius:14px;
            background:#f8fafc;
            color:#334155;
            font-size:13px;
        }

        @media (max-width: 1200px){
            .filter-grid{ grid-template-columns:repeat(3, 1fr); }
            .grid{ grid-template-columns:repeat(2,1fr); }
            .grid-extra{ grid-template-columns:repeat(2,1fr); }
        }
        @media (max-width: 700px){
            .filter-grid{ grid-template-columns:1fr; }
            .grid{ grid-template-columns:1fr; }
            .grid-extra{ grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">

    <div class="header">
        <div>
            <h1 class="title">Historial de auditorías</h1>
            <div class="subtitle">
                Consulta, seguimiento y acceso rápido al detalle, conciliación y acta final.
            </div>
        </div>
        <div class="actions">
            <a href="auditorias_nueva.php" class="btn btn-primary">Nueva auditoría</a>
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Resumen</h2>
        <div class="grid">
            <div class="kpi">
                <div class="kpi-label">Auditorías listadas</div>
                <div class="kpi-value"><?= (int)$totalAuditorias ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">En proceso</div>
                <div class="kpi-value"><?= (int)$totalProceso ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Cerradas</div>
                <div class="kpi-value"><?= (int)$totalCerradas ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-label">Con incidencias</div>
                <div class="kpi-value"><?= (int)$totalConIncidencias ?></div>
            </div>
        </div>
    </div>

    <?php if ($mostrarKpiExtra): ?>
    <div class="card">
        <h2 class="section-title">Indicadores de faltantes e impacto</h2>
        <div class="grid-extra">
            <div class="kpi kpi-danger">
                <div class="kpi-label">Sucursal con más faltantes</div>
                <div class="kpi-value-sm"><?= h($kpiExtra['sucursal_mas_faltantes']) ?></div>
                <div class="kpi-note"><?= number_format((int)$kpiExtra['faltantes_maximos']) ?> faltantes acumulados</div>
            </div>
            <div class="kpi kpi-warning">
                <div class="kpi-label">Mayor impacto monetario</div>
                <div class="kpi-value-sm"><?= h($kpiExtra['sucursal_mayor_impacto']) ?></div>
                <div class="kpi-note">$<?= number_format((float)$kpiExtra['impacto_mayor'], 2) ?></div>
            </div>
            <div class="kpi kpi-warning">
                <div class="kpi-label">Impacto monetario total</div>
                <div class="kpi-value">$<?= number_format((float)$kpiExtra['impacto_total'], 2) ?></div>
                <div class="kpi-note">Estimado con costo de productos faltantes</div>
            </div>
            <div class="kpi kpi-primary">
                <div class="kpi-label">Promedio de faltantes por auditoría</div>
                <div class="kpi-value"><?= number_format((float)$kpiExtra['promedio_faltantes'], 1) ?></div>
                <div class="kpi-note">Considera serializados y no serializados</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2 class="section-title">Filtros</h2>

        <form method="GET" action="">
            <div class="filter-grid">
                <div class="field">
                    <label for="folio">Folio</label>
                    <input type="text" name="folio" id="folio" value="<?= h($f_folio) ?>" placeholder="AUD-LUGA-2026-0001">
                </div>

                <div class="field">
                    <label for="id_sucursal">Sucursal</label>
                    <select name="id_sucursal" id="id_sucursal">
                        <option value="">Todas</option>
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" <?= ($f_sucursal === (int)$s['id']) ? 'selected' : '' ?>>
                                <?= h($s['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="estatus">Estatus</label>
                    <select name="estatus" id="estatus">
                        <option value="">Todos</option>
                        <?php
                        $estatuses = ['Borrador','En proceso','Pendiente revision','Cerrada','Cancelada'];
                        foreach ($estatuses as $est):
                        ?>
                            <option value="<?= h($est) ?>" <?= ($f_estatus === $est) ? 'selected' : '' ?>>
                                <?= h($est) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="auditor">Auditor</label>
                    <input type="text" name="auditor" id="auditor" value="<?= h($f_auditor) ?>" placeholder="Nombre del auditor">
                </div>

                <div class="field">
                    <label for="fecha_ini">Fecha inicio desde</label>
                    <input type="date" name="fecha_ini" id="fecha_ini" value="<?= h($f_fecha_ini) ?>">
                </div>

                <div class="field">
                    <label for="fecha_fin">Fecha inicio hasta</label>
                    <input type="date" name="fecha_fin" id="fecha_fin" value="<?= h($f_fecha_fin) ?>">
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary">Filtrar</button>
                <a href="auditorias_historial.php" class="btn btn-secondary">Limpiar</a>
            </div>
        </form>

        <div class="notice">
            Los roles Gerente y Supervisor solo visualizan auditorías de su propia sucursal.
        </div>
    </div>

    <div class="card">
        <h2 class="section-title">Listado de auditorías</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr class="group-row">
                        <th rowspan="2">#</th>
                        <th rowspan="2">Folio</th>
                        <th rowspan="2">Sucursal</th>
                        <th rowspan="2">Auditor</th>
                        <th rowspan="2">Gerente</th>
                        <th rowspan="2">Inicio</th>
                        <th rowspan="2">Cierre</th>
                        <th rowspan="2">Estatus</th>
                        <th colspan="3" class="th-serial">Serializados</th>
                        <th colspan="3" class="th-no-serial">No serializados</th>
                        <th rowspan="2">Incidencias</th>
                        <th rowspan="2">Acciones</th>
                    </tr>
                    <tr class="sub-row">
                        <th>Requeridos</th>
                        <th>Escaneados</th>
                        <th>Faltantes</th>
                        <th>Requeridos</th>
                        <th>Contados</th>
                        <th>Diferencia</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$auditorias): ?>
                    <tr>
                        <td colspan="16" style="text-align:center; padding:28px;">
                            No se encontraron auditorías con los filtros seleccionados.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $n = 1; foreach ($auditorias as $a): ?>
                        <?php
                            $idAud = (int)$a['id'];

                            $serialReq  = (int)($a['total_snapshot'] ?? 0);
                            $serialScan = (int)($a['total_escaneados'] ?? 0);
                            $serialFalt = (int)($a['total_faltantes'] ?? 0);

                            $nsRequeridos = (int)($nsTotales[$idAud]['requeridos'] ?? 0);
                            $nsContados   = (int)($nsTotales[$idAud]['contados'] ?? 0);
                            $nsDiferencia = (int)($nsTotales[$idAud]['diferencia'] ?? 0);

                            $serialScanClass = ($serialReq > 0 && $serialScan >= $serialReq) ? 'num-ok' : 'num-neutral';
                            $serialFaltClass = ($serialFalt > 0) ? 'num-bad' : 'num-ok';

                            $nsDiffClass = 'num-neutral';
                            if ($nsDiferencia < 0) $nsDiffClass = 'num-bad';
                            elseif ($nsDiferencia === 0) $nsDiffClass = 'num-ok';
                            elseif ($nsDiferencia > 0) $nsDiffClass = 'num-ok';
                        ?>
                        <tr>
                            <td class="text-center"><?= $n++ ?></td>
                            <td class="text-left"><strong><?= h($a['folio']) ?></strong></td>
                            <td class="text-left"><?= h($a['sucursal_nombre']) ?></td>
                            <td class="text-left"><?= h($a['auditor_nombre']) ?></td>
                            <td class="text-left"><?= h($a['gerente_nombre'] ?: '-') ?></td>
                            <td class="text-center"><?= h(fmtFecha($a['fecha_inicio'])) ?></td>
                            <td class="text-center"><?= h(fmtFecha($a['fecha_cierre'])) ?></td>
                            <td class="text-center">
                                <span class="badge <?= h(badgeClass((string)($a['estatus'] ?? ''))) ?>">
                                    <?= h($a['estatus']) ?>
                                </span>
                            </td>

                            <td class="text-center"><?= $serialReq ?></td>
                            <td class="text-center">
                                <span class="<?= h($serialScanClass) ?>"><?= $serialScan ?></span>
                            </td>
                            <td class="text-center">
                                <span class="<?= h($serialFaltClass) ?>"><?= $serialFalt ?></span>
                            </td>

                            <td class="text-center"><?= $nsRequeridos ?></td>
                            <td class="text-center"><?= $nsContados ?></td>
                            <td class="text-center">
                                <span class="<?= h($nsDiffClass) ?>">
                                    <?= $nsDiferencia > 0 ? '+' . $nsDiferencia : $nsDiferencia ?>
                                </span>
                            </td>

                            <td class="text-center"><?= (int)($a['total_incidencias'] ?? 0) ?></td>

                            <td class="text-center">
                                <div class="acciones-wrap">
                                    <a class="btn-mini btn-mini-gray" href="auditorias_inicio.php?id=<?= $idAud ?>">Detalles</a>
                                    <a class="btn-mini btn-mini-blue" href="auditorias_conciliar.php?id=<?= $idAud ?>">Conciliación</a>
                                    <?php if (($a['estatus'] ?? '') === 'Cerrada'): ?>
                                        <a class="btn-mini btn-mini-dark" target="_blank" href="generar_acta_auditoria.php?id=<?= $idAud ?>">Acta</a>
                                    <?php else: ?>
                                        <span class="btn-mini btn-mini-disabled">Acta</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>
</body>
</html>
