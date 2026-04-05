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
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

$ROLES_PERMITIDOS = [
    'Admin', 'Administrador',
    'Gerente', 'GerenteZona',
    'Ejecutivo', 'Logistica',
    'Subdis_Admin', 'Subdis_Gerente', 'Subdis_Ejecutivo'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para acceder al módulo de garantías.');
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

/* =========================================================
   PAGINACION
========================================================= */
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

/* =========================================================
   SCOPE POR ROL
========================================================= */
$where = [];
$params = [];
$types = '';

if (in_array($ROL, ['Admin', 'Administrador', 'Logistica'], true)) {
    // ven todo
} elseif (in_array($ROL, ['Gerente', 'Subdis_Gerente'], true)) {
    $where[] = "gc.id_sucursal = ?";
    $params[] = $ID_SUCURSAL;
    $types .= 'i';
} elseif (in_array($ROL, ['Ejecutivo', 'Subdis_Ejecutivo'], true)) {
    $where[] = "gc.id_usuario_captura = ?";
    $params[] = $ID_USUARIO;
    $types .= 'i';
} elseif (in_array($ROL, ['GerenteZona', 'Subdis_Admin'], true)) {
    // por ahora mostramos todo; si luego ocupas zona/tenant lo ajustamos
} else {
    $where[] = "1 = 0";
}

/* =========================================================
   FILTROS DINAMICOS
========================================================= */
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
   TOTAL
========================================================= */
$sqlCount = "SELECT COUNT(*) AS total
             FROM garantias_casos gc
             $whereSql";

$st = $conn->prepare($sqlCount);
if (!$st) {
    exit("Error en consulta count: " . h($conn->error));
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
            gc.id_sucursal,
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
            gc.id_sucursal,
            s.nombre,
            u.nombre
        ORDER BY gc.id DESC
        LIMIT ? OFFSET ?";

$st = $conn->prepare($sql);
if (!$st) {
    exit("Error en consulta principal: " . h($conn->error));
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
   ESTADOS PARA FILTRO
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
    <title>Garantías | Mis casos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body{
            background: linear-gradient(180deg, #f8fbff 0%, #f3f6fb 100%);
        }
        .page-wrap{
            max-width: 1450px;
            margin: 24px auto 40px;
            padding: 0 14px;
        }
        .soft-card{
            border:1px solid #e8edf3;
            border-radius:20px;
            box-shadow:0 8px 24px rgba(17,24,39,.05);
            background:#fff;
        }
        .hero{
            border-radius:22px;
            border:1px solid #e8edf3;
            background: linear-gradient(135deg, rgba(13,110,253,.08), rgba(111,66,193,.08));
            box-shadow:0 10px 28px rgba(17,24,39,.06);
        }
        .hero h3{
            font-weight:700;
            margin-bottom:.35rem;
        }
        .hero p{
            margin-bottom:0;
            color:#6c757d;
        }
        .table thead th{
            white-space:nowrap;
            font-size:.88rem;
        }
        .table td{
            vertical-align:middle;
            font-size:.92rem;
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
        .empty-box{
            border:1px dashed #ced4da;
            border-radius:18px;
            background:#fff;
            padding:28px;
            text-align:center;
            color:#6c757d;
        }
        .truncate{
            max-width:220px;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
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
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div>
                <h3><i class="bi bi-journal-medical me-2"></i>Listado de garantías y reparaciones</h3>
                <p>Consulta, filtra y da seguimiento a los casos registrados en Central.</p>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <span class="chip"><i class="bi bi-person-badge"></i> Rol: <?= h($ROL) ?></span>
                <span class="chip"><i class="bi bi-list-check"></i> Total: <?= number_format($totalRows) ?></span>
                <a href="garantias_nueva.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle me-1"></i>Nueva solicitud
                </a>
            </div>
        </div>
    </div>

    <!-- FILTROS -->
    <div class="soft-card p-4 mb-4">
        <form method="get" class="row g-3">
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

                <a href="garantias_mis_casos.php" class="btn btn-outline-secondary">
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
                <div class="fw-semibold mb-1">No se encontraron casos</div>
                <div>Prueba ajustando los filtros o registrando una nueva solicitud.</div>
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
                            <th>Motivo</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $res->fetch_assoc()): ?>
                            <?php $totalDocs = (int)($row['total_documentos'] ?? 0); ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= h($row['folio']) ?></div>
                                </td>

                                <td>
                                    <?php if (!empty($row['fecha_captura'])): ?>
                                        <?= h(date('d/m/Y H:i', strtotime($row['fecha_captura']))) ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
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
                                    <div class="truncate" title="<?= h($row['motivo_no_procede']) ?>">
                                        <?= h($row['motivo_no_procede']) ?>
                                    </div>
                                </td>

                                <td class="text-center">
                                    <div class="actions-wrap">
                                        <a href="garantias_detalle.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye me-1"></i>Ver
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