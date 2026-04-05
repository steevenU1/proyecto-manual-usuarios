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
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

/* =========================================================
   PERMISOS
========================================================= */
$ROL = (string)($_SESSION['rol'] ?? '');
$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);

$ROLES_PERMITIDOS = ['Admin', 'Administrador', 'Logistica'];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para acceder al panel de logística.');
}

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function qs(array $override = []): string {
    $params = array_merge($_GET, $override);
    return http_build_query($params);
}

function badge_estado(string $estado): string {
    $map = [
        'capturada'              => 'secondary',
        'recepcion_registrada'   => 'info',
        'en_revision_logistica'  => 'warning text-dark',
        'garantia_autorizada'    => 'success',
        'garantia_rechazada'     => 'danger',
        'enviada_diagnostico'    => 'primary',
        'cotizacion_disponible'  => 'info',
        'cotizacion_aceptada'    => 'success',
        'cotizacion_rechazada'   => 'danger',
        'en_reparacion'          => 'warning text-dark',
        'reparado'               => 'success',
        'reemplazo_capturado'    => 'primary',
        'entregado'              => 'success',
        'cerrado'                => 'dark',
        'cancelado'              => 'dark',
    ];

    $cls = $map[$estado] ?? 'secondary';
    return '<span class="badge rounded-pill text-bg-' . $cls . '">' . h($estado) . '</span>';
}

function badge_dictamen(string $dictamen): string {
    $map = [
        'procede'            => 'success',
        'no_procede'         => 'danger',
        'revision_logistica' => 'warning text-dark',
        'imei_no_localizado' => 'secondary',
    ];

    $cls = $map[$dictamen] ?? 'secondary';
    return '<span class="badge rounded-pill text-bg-' . $cls . '">' . h($dictamen) . '</span>';
}

/* =========================================================
   FILTROS
========================================================= */
$f_folio      = trim((string)($_GET['folio'] ?? ''));
$f_imei       = trim((string)($_GET['imei'] ?? ''));
$f_cliente    = trim((string)($_GET['cliente'] ?? ''));
$f_estado     = trim((string)($_GET['estado'] ?? ''));
$f_fecha_ini  = trim((string)($_GET['fecha_ini'] ?? ''));
$f_fecha_fin  = trim((string)($_GET['fecha_fin'] ?? ''));
$f_bandeja    = trim((string)($_GET['bandeja'] ?? 'pendientes'));

/* =========================================================
   PAGINACION
========================================================= */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

/* =========================================================
   WHERE DINAMICO
========================================================= */
$where = [];
$params = [];
$types = '';

if ($f_bandeja === 'pendientes') {
    $where[] = "gc.estado IN ('capturada','recepcion_registrada','en_revision_logistica','enviada_diagnostico','cotizacion_disponible','cotizacion_aceptada','en_reparacion')";
} elseif ($f_bandeja === 'garantias') {
    $where[] = "gc.estado IN ('capturada','recepcion_registrada','en_revision_logistica','garantia_autorizada','garantia_rechazada')";
} elseif ($f_bandeja === 'reparaciones') {
    $where[] = "gc.estado IN ('enviada_diagnostico','cotizacion_disponible','cotizacion_aceptada','cotizacion_rechazada','en_reparacion','reparado')";
} elseif ($f_bandeja === 'cerrados') {
    $where[] = "gc.estado IN ('entregado','cerrado','cancelado')";
}

if ($f_folio !== '') {
    $where[] = "gc.folio LIKE ?";
    $params[] = '%' . $f_folio . '%';
    $types .= 's';
}

if ($f_imei !== '') {
    $where[] = "(gc.imei_original LIKE ? OR gc.imei2_original LIKE ?)";
    $params[] = '%' . $f_imei . '%';
    $params[] = '%' . $f_imei . '%';
    $types .= 'ss';
}

if ($f_cliente !== '') {
    $where[] = "gc.cliente_nombre LIKE ?";
    $params[] = '%' . $f_cliente . '%';
    $types .= 's';
}

if ($f_estado !== '') {
    $where[] = "gc.estado = ?";
    $params[] = $f_estado;
    $types .= 's';
}

if ($f_fecha_ini !== '') {
    $where[] = "DATE(gc.fecha_captura) >= ?";
    $params[] = $f_fecha_ini;
    $types .= 's';
}

if ($f_fecha_fin !== '') {
    $where[] = "DATE(gc.fecha_captura) <= ?";
    $params[] = $f_fecha_fin;
    $types .= 's';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* =========================================================
   KPIS
========================================================= */
$kpis = [
    'pendientes' => 0,
    'revision' => 0,
    'cotizacion' => 0,
    'reparacion' => 0,
];

$sqlKpi = "SELECT
            SUM(CASE WHEN gc.estado IN ('capturada','recepcion_registrada','en_revision_logistica') THEN 1 ELSE 0 END) AS pendientes,
            SUM(CASE WHEN gc.estado = 'en_revision_logistica' THEN 1 ELSE 0 END) AS revision,
            SUM(CASE WHEN gc.estado = 'cotizacion_disponible' THEN 1 ELSE 0 END) AS cotizacion,
            SUM(CASE WHEN gc.estado = 'en_reparacion' THEN 1 ELSE 0 END) AS reparacion
           FROM garantias_casos gc";

$resK = $conn->query($sqlKpi);
if ($resK && $rowK = $resK->fetch_assoc()) {
    $kpis = [
        'pendientes' => (int)($rowK['pendientes'] ?? 0),
        'revision'   => (int)($rowK['revision'] ?? 0),
        'cotizacion' => (int)($rowK['cotizacion'] ?? 0),
        'reparacion' => (int)($rowK['reparacion'] ?? 0),
    ];
}

/* =========================================================
   TOTAL
========================================================= */
$sqlCount = "SELECT COUNT(*) AS total
             FROM garantias_casos gc
             $whereSql";

$st = $conn->prepare($sqlCount);
if (!$st) {
    exit("Error en count logística: " . h($conn->error));
}
if ($types !== '') {
    $st->bind_param($types, ...$params);
}
$st->execute();
$totalRows = (int)($st->get_result()->fetch_assoc()['total'] ?? 0);
$st->close();

$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* =========================================================
   CONSULTA PRINCIPAL
========================================================= */
$sql = "SELECT
            gc.id,
            gc.folio,
            gc.fecha_captura,
            gc.fecha_dictamen,
            gc.cliente_nombre,
            gc.cliente_telefono,
            gc.marca,
            gc.modelo,
            gc.color,
            gc.capacidad,
            gc.imei_original,
            gc.imei2_original,
            gc.dictamen_preliminar,
            gc.motivo_no_procede,
            gc.estado,
            gc.es_reparable,
            gc.requiere_cotizacion,
            s.nombre AS sucursal_nombre,
            u.nombre AS capturista_nombre,
            COUNT(gd.id) AS total_documentos
        FROM garantias_casos gc
        LEFT JOIN sucursales s ON s.id = gc.id_sucursal
        LEFT JOIN usuarios u ON u.id = gc.id_usuario_captura
        LEFT JOIN garantias_documentos gd ON gd.id_garantia = gc.id
        $whereSql
        GROUP BY
            gc.id,
            gc.folio,
            gc.fecha_captura,
            gc.fecha_dictamen,
            gc.cliente_nombre,
            gc.cliente_telefono,
            gc.marca,
            gc.modelo,
            gc.color,
            gc.capacidad,
            gc.imei_original,
            gc.imei2_original,
            gc.dictamen_preliminar,
            gc.motivo_no_procede,
            gc.estado,
            gc.es_reparable,
            gc.requiere_cotizacion,
            s.nombre,
            u.nombre
        ORDER BY
            CASE
                WHEN gc.estado IN ('capturada','recepcion_registrada','en_revision_logistica') THEN 1
                WHEN gc.estado IN ('enviada_diagnostico','cotizacion_disponible','cotizacion_aceptada','en_reparacion') THEN 2
                ELSE 3
            END,
            gc.id DESC
        LIMIT ? OFFSET ?";

$st = $conn->prepare($sql);
if (!$st) {
    exit("Error en consulta logística: " . h($conn->error));
}

$paramsMain = $params;
$typesMain = $types . 'ii';
$paramsMain[] = $perPage;
$paramsMain[] = $offset;

$st->bind_param($typesMain, ...$paramsMain);
$st->execute();
$res = $st->get_result();
$st->close();

/* =========================================================
   ESTADOS
========================================================= */
$estados = [
    'capturada',
    'recepcion_registrada',
    'en_revision_logistica',
    'garantia_autorizada',
    'garantia_rechazada',
    'enviada_diagnostico',
    'cotizacion_disponible',
    'cotizacion_aceptada',
    'cotizacion_rechazada',
    'en_reparacion',
    'reparado',
    'reemplazo_capturado',
    'entregado',
    'cerrado',
    'cancelado'
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Garantías | Panel de Logística</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body{
            background: linear-gradient(180deg, #f8fbff 0%, #f3f6fb 100%);
        }
        .page-wrap{
            max-width: 1480px;
            margin: 24px auto 40px;
            padding: 0 14px;
        }
        .hero{
            border-radius:22px;
            border:1px solid #e8edf3;
            background: linear-gradient(135deg, rgba(13,110,253,.08), rgba(25,135,84,.08));
            box-shadow:0 10px 28px rgba(17,24,39,.06);
        }
        .soft-card{
            border:1px solid #e8edf3;
            border-radius:20px;
            box-shadow:0 8px 24px rgba(17,24,39,.05);
            background:#fff;
        }
        .kpi-card{
            border-radius:18px;
            border:1px solid #ebeff5;
            padding:18px;
            background:#fff;
            box-shadow:0 6px 18px rgba(17,24,39,.04);
        }
        .kpi-value{
            font-size:1.6rem;
            font-weight:700;
            line-height:1;
        }
        .kpi-label{
            color:#6b7280;
            font-size:.9rem;
            margin-top:.35rem;
        }
        .chip{
            display:inline-flex;
            align-items:center;
            gap:.35rem;
            border-radius:999px;
            padding:.45rem .8rem;
            font-size:.82rem;
            font-weight:600;
            background:#eef4ff;
            color:#2457c5;
        }
        .table thead th{
            white-space:nowrap;
            font-size:.88rem;
        }
        .table td{
            vertical-align:middle;
            font-size:.92rem;
        }
        .truncate{
            max-width:220px;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .empty-box{
            border:1px dashed #ced4da;
            border-radius:18px;
            background:#fff;
            padding:28px;
            text-align:center;
            color:#6c757d;
        }
        .tabs-soft .btn{
            border-radius:999px;
        }
        .actions-wrap{
            display:flex;
            flex-wrap:wrap;
            gap:.35rem;
            justify-content:center;
        }
        .btn-docs{
            min-width: 96px;
        }
    </style>
</head>
<body>

<div class="page-wrap">

    <!-- HERO -->
    <div class="hero p-4 p-md-5 mb-4">
        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
            <div>
                <h3 class="fw-bold mb-1">
                    <i class="bi bi-truck me-2"></i>Panel de logística
                </h3>
                <div class="text-muted">
                    Revisión de garantías, diagnósticos, cotizaciones y seguimiento de reparaciones.
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <span class="chip"><i class="bi bi-person-badge"></i> <?= h($ROL) ?></span>
                <span class="chip"><i class="bi bi-list-check"></i> Casos visibles: <?= number_format($totalRows) ?></span>
            </div>
        </div>
    </div>

    <!-- KPIS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value"><?= number_format($kpis['pendientes']) ?></div>
                <div class="kpi-label">Pendientes de atención</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value"><?= number_format($kpis['revision']) ?></div>
                <div class="kpi-label">En revisión logística</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value"><?= number_format($kpis['cotizacion']) ?></div>
                <div class="kpi-label">Cotización disponible</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="kpi-card">
                <div class="kpi-value"><?= number_format($kpis['reparacion']) ?></div>
                <div class="kpi-label">En reparación</div>
            </div>
        </div>
    </div>

    <!-- BANDEJAS -->
    <div class="soft-card p-4 mb-4">
        <div class="d-flex flex-wrap gap-2 tabs-soft">
            <a href="?<?= h(qs(['bandeja' => 'pendientes', 'page' => 1])) ?>"
               class="btn <?= $f_bandeja === 'pendientes' ? 'btn-primary' : 'btn-outline-primary' ?>">
                Pendientes
            </a>

            <a href="?<?= h(qs(['bandeja' => 'garantias', 'page' => 1])) ?>"
               class="btn <?= $f_bandeja === 'garantias' ? 'btn-primary' : 'btn-outline-primary' ?>">
                Garantías
            </a>

            <a href="?<?= h(qs(['bandeja' => 'reparaciones', 'page' => 1])) ?>"
               class="btn <?= $f_bandeja === 'reparaciones' ? 'btn-primary' : 'btn-outline-primary' ?>">
                Reparaciones
            </a>

            <a href="?<?= h(qs(['bandeja' => 'cerrados', 'page' => 1])) ?>"
               class="btn <?= $f_bandeja === 'cerrados' ? 'btn-primary' : 'btn-outline-primary' ?>">
                Cerrados
            </a>
        </div>
    </div>

    <!-- FILTROS -->
    <div class="soft-card p-4 mb-4">
        <form method="get" class="row g-3">
            <input type="hidden" name="bandeja" value="<?= h($f_bandeja) ?>">

            <div class="col-md-2">
                <label class="form-label fw-semibold">Folio</label>
                <input type="text" name="folio" class="form-control" value="<?= h($f_folio) ?>" placeholder="GAR-2026...">
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">IMEI</label>
                <input type="text" name="imei" class="form-control" value="<?= h($f_imei) ?>" placeholder="Buscar IMEI">
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Cliente</label>
                <input type="text" name="cliente" class="form-control" value="<?= h($f_cliente) ?>" placeholder="Nombre cliente">
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Estado</label>
                <select name="estado" class="form-select">
                    <option value="">Todos</option>
                    <?php foreach ($estados as $e): ?>
                        <option value="<?= h($e) ?>" <?= $f_estado === $e ? 'selected' : '' ?>>
                            <?= h($e) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Fecha inicio</label>
                <input type="date" name="fecha_ini" class="form-control" value="<?= h($f_fecha_ini) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label fw-semibold">Fecha fin</label>
                <input type="date" name="fecha_fin" class="form-control" value="<?= h($f_fecha_fin) ?>">
            </div>

            <div class="col-12 d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel me-1"></i>Filtrar
                </button>

                <a href="garantias_logistica.php?bandeja=pendientes" class="btn btn-outline-secondary">
                    <i class="bi bi-eraser me-1"></i>Limpiar
                </a>
            </div>
        </form>
    </div>

    <!-- TABLA -->
    <div class="soft-card p-4">
        <?php if ($totalRows <= 0): ?>
            <div class="empty-box">
                <div class="mb-2 fs-3"><i class="bi bi-inbox"></i></div>
                <div class="fw-semibold mb-1">No se encontraron casos en esta bandeja</div>
                <div>Prueba ajustando filtros o cambia la bandeja de trabajo.</div>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Folio</th>
                            <th>Fecha</th>
                            <th>Cliente</th>
                            <th>Equipo</th>
                            <th>IMEI</th>
                            <th>Sucursal</th>
                            <th>Capturó</th>
                            <th>Dictamen</th>
                            <th>Estado</th>
                            <th>Ruta</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $res->fetch_assoc()): ?>
                            <?php
                                $ruta = 'Garantía';
                                if ((int)$row['requiere_cotizacion'] === 1 || (int)$row['es_reparable'] === 1) {
                                    $ruta = 'Reparación / cotización';
                                }
                                $totalDocs = (int)($row['total_documentos'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= h($row['folio']) ?></div>
                                </td>

                                <td>
                                    <div><?= h(date('d/m/Y H:i', strtotime($row['fecha_captura']))) ?></div>
                                    <div class="text-muted small">
                                        Dictamen: <?= h($row['fecha_dictamen'] ? date('d/m/Y H:i', strtotime($row['fecha_dictamen'])) : '-') ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="fw-semibold truncate" title="<?= h($row['cliente_nombre']) ?>">
                                        <?= h($row['cliente_nombre']) ?>
                                    </div>
                                    <div class="text-muted small"><?= h($row['cliente_telefono']) ?></div>
                                </td>

                                <td>
                                    <div class="fw-semibold">
                                        <?= h(trim(($row['marca'] ?? '') . ' ' . ($row['modelo'] ?? ''))) ?>
                                    </div>
                                    <div class="text-muted small">
                                        <?= h($row['color']) ?> <?= $row['capacidad'] ? '• ' . h($row['capacidad']) : '' ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="truncate" title="<?= h($row['imei_original']) ?>">
                                        <?= h($row['imei_original']) ?>
                                    </div>
                                    <?php if (!empty($row['imei2_original'])): ?>
                                        <div class="text-muted small truncate" title="<?= h($row['imei2_original']) ?>">
                                            <?= h($row['imei2_original']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td><?= h($row['sucursal_nombre']) ?></td>
                                <td><?= h($row['capturista_nombre']) ?></td>
                                <td><?= badge_dictamen((string)$row['dictamen_preliminar']) ?></td>
                                <td><?= badge_estado((string)$row['estado']) ?></td>
                                <td>
                                    <span class="badge text-bg-light border"><?= h($ruta) ?></span>
                                </td>
                                <td class="text-center">
                                    <div class="actions-wrap">
                                        <a href="garantias_detalle.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye me-1"></i>Gestionar
                                        </a>

                                        <a href="generar_documento_garantia.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-success" target="_blank">
                                            <i class="bi bi-file-earmark-text me-1"></i>Formato
                                        </a>

                                        <a href="garantias_detalle.php?id=<?= (int)$row['id'] ?>#documentos" class="btn btn-sm btn-outline-secondary btn-docs">
                                            <i class="bi bi-folder2-open me-1"></i>Docs
                                            <span class="badge text-bg-light ms-1"><?= $totalDocs ?></span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINACION -->
            <?php if ($totalPages > 1): ?>
                <nav class="mt-4">
                    <ul class="pagination mb-0 justify-content-center flex-wrap">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= h(qs(['page' => max(1, $page - 1)])) ?>">Anterior</a>
                        </li>

                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= h(qs(['page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>

                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= h(qs(['page' => min($totalPages, $page + 1)])) ?>">Siguiente</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

</body>
</html>