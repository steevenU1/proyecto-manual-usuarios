<?php
// inventario_subdis.php — SUBDISTRIBUIDOR (RAM + "Almacenamiento", fecha sin hora, tabla compacta, CANTIDAD para accesorios)
// Scope: solo sucursales del subdis + almacén Eulalia (por nombre).
session_start();

/* ===============================
   0) Login base
   =============================== */
if (!isset($_SESSION['id_usuario'])) {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';

/* ===============================
   1) Normalizar rol (tolerante)
   =============================== */
function norm_role($rol): string {
  $r = strtolower(trim((string)$rol));
  $r = str_replace([' ', '-'], '_', $r);
  $r = preg_replace('/_+/', '_', $r);
  return $r;
}

$ROL_RAW = $_SESSION['rol'] ?? '';
$ROL = norm_role($ROL_RAW);

// Permitir roles exactos o cualquier cosa que contenga "subdis"
$ALLOWED = ['subdis_admin', 'subdis_gerente', 'subdis_ejecutivo'];
$roleOk = in_array($ROL, $ALLOWED, true) || (strpos($ROL, 'subdis') !== false);

if (!$roleOk) {
  header("Location: 403.php");
  exit();
}

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
if ($idUsuario <= 0) {
  header("Location: 403.php");
  exit();
}

/* ===============================
   2) Resolver id_subdis (sesión -> usuarios -> join sucursal)
   =============================== */
function safe_fetch_one_int(mysqli $conn, string $sql, string $types, array $params): int {
  try {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return 0;
    $v = array_values($row)[0] ?? 0;
    return (int)$v;
  } catch (Throwable $e) {
    return 0;
  }
}

$idSubdis = (int)($_SESSION['id_subdis'] ?? 0);
if ($idSubdis <= 0) $idSubdis = (int)($_SESSION['id_subdistribuidor'] ?? 0);

// 2.1 usuarios.id_subdis (si existe)
if ($idSubdis <= 0) {
  $idSubdis = safe_fetch_one_int($conn,
    "SELECT COALESCE(id_subdis,0) AS id_subdis FROM usuarios WHERE id=? LIMIT 1",
    "i",
    [$idUsuario]
  );
}

// 2.2 usuarios.id_subdistribuidor (si existe)
if ($idSubdis <= 0) {
  $idSubdis = safe_fetch_one_int($conn,
    "SELECT COALESCE(id_subdistribuidor,0) AS id_subdis FROM usuarios WHERE id=? LIMIT 1",
    "i",
    [$idUsuario]
  );
}

// 2.3 JOIN usuarios.id_sucursal -> sucursales.id_subdis  ✅ CLAVE
if ($idSubdis <= 0) {
  $idSubdis = safe_fetch_one_int($conn,
    "SELECT COALESCE(s.id_subdis,0) AS id_subdis
     FROM usuarios u
     INNER JOIN sucursales s ON s.id = u.id_sucursal
     WHERE u.id=? LIMIT 1",
    "i",
    [$idUsuario]
  );
}

if ($idSubdis <= 0) {
  // Si aquí cae, significa que tu usuario no está ligado a una sucursal subdis
  // o sucursales.id_subdis no está poblado para esa sucursal.
  header("Location: 403.php");
  exit();
}

// Persistir para siguientes vistas
$_SESSION['id_subdis'] = $idSubdis;

/* ===============================
   3) Incluye validación de activo + navbar
   =============================== */
require_once __DIR__ . '/verificar_sesion.php';
require_once __DIR__ . '/navbar.php';

// No se puede editar precio desde esta vista
$canEditPrice = false;

/* ===============================
   4) Helpers
   =============================== */
if (!function_exists('h')) {
  function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
}

/* ===============================
   5) Filtros
   =============================== */
$filtroImei       = $_GET['imei']        ?? '';
$filtroSucursal   = $_GET['sucursal']    ?? '';
$filtroEstatus    = $_GET['estatus']     ?? '';
$filtroAntiguedad = $_GET['antiguedad']  ?? '';
$filtroPrecioMin  = $_GET['precio_min']  ?? '';
$filtroPrecioMax  = $_GET['precio_max']  ?? '';
$filtroModelo     = $_GET['modelo']      ?? '';

$sql = "
  SELECT 
         i.id AS id_inventario,
         s.id AS id_sucursal,
         s.nombre AS sucursal,
         p.id AS id_producto,
         p.marca, p.modelo, p.color, p.ram, p.capacidad,
         p.imei1, p.imei2,
         p.costo,
         p.costo_con_iva,
         p.precio_lista,
         p.proveedor,
         p.codigo_producto,
         p.tipo_producto,
         (p.precio_lista - COALESCE(p.costo_con_iva, p.costo, 0)) AS profit,
         i.estatus, i.fecha_ingreso,
         i.cantidad AS cantidad_inventario,
         (CASE WHEN (p.imei1 IS NULL OR p.imei1 = '') THEN 1 ELSE 0 END) AS es_accesorio,
         (CASE WHEN (p.imei1 IS NULL OR p.imei1 = '') THEN IFNULL(i.cantidad,0) ELSE 1 END) AS cantidad_mostrar,
         TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) AS antiguedad_dias
  FROM inventario i
  INNER JOIN productos p ON p.id = i.id_producto
  INNER JOIN sucursales s ON s.id = i.id_sucursal
  WHERE i.estatus IN ('Disponible','En tránsito')
    AND (
      (COALESCE(s.propiedad,'') IN ('Subdis','Subdistribuidor','Subdistribuidora','SUBDIS','SUBDISTRIBUIDOR') AND COALESCE(s.id_subdis,0) = ?)
      OR (LOWER(s.nombre) LIKE '%eulalia%')
    )
    AND COALESCE(i.cantidad, 0) > 0
";

$params = [];
$types  = "";

// Scope param
$params[] = $idSubdis;
$types .= "i";

if ($filtroSucursal !== '') {
  $sql .= " AND s.id = ?";
  $params[] = (int)$filtroSucursal;
  $types .= "i";
}
if ($filtroImei !== '') {
  $sql .= " AND (p.imei1 LIKE ? OR p.imei2 LIKE ?)";
  $like = "%$filtroImei%";
  $params[] = $like;
  $params[] = $like;
  $types .= "ss";
}
if ($filtroModelo !== '') {
  $sql .= " AND p.modelo LIKE ?";
  $params[] = "%$filtroModelo%";
  $types .= "s";
}
if ($filtroEstatus !== '') {
  $sql .= " AND i.estatus = ?";
  $params[] = $filtroEstatus;
  $types .= "s";
}
if ($filtroAntiguedad == '<30') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) < 30";
} elseif ($filtroAntiguedad == '30-90') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) BETWEEN 30 AND 90";
} elseif ($filtroAntiguedad == '>90') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) > 90";
}
if ($filtroPrecioMin !== '') {
  $sql .= " AND p.precio_lista >= ?";
  $params[] = (float)$filtroPrecioMin;
  $types .= "d";
}
if ($filtroPrecioMax !== '') {
  $sql .= " AND p.precio_lista <= ?";
  $params[] = (float)$filtroPrecioMax;
  $types .= "d";
}

$sql .= " ORDER BY s.nombre ASC, i.fecha_ingreso ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$stmtSuc = $conn->prepare("
  SELECT id, nombre
  FROM sucursales
  WHERE (
    (COALESCE(propiedad,'') IN ('Subdis','Subdistribuidor','Subdistribuidora','SUBDIS','SUBDISTRIBUIDOR') AND COALESCE(id_subdis,0)=?)
    OR (LOWER(nombre) LIKE '%eulalia%')
  )
  ORDER BY nombre
");
$stmtSuc->bind_param('i', $idSubdis);
$stmtSuc->execute();
$sucursales = $stmtSuc->get_result();

// Agregados
$rangos = ['<30' => 0, '30-90' => 0, '>90' => 0];
$inventario = [];

$total = 0;
$sumAntiguedad = 0;
$sumPrecio = 0.0;
$sumProfit = 0.0;
$cntDisp = 0;
$cntTrans = 0;

while ($row = $result->fetch_assoc()) {
  $inventario[] = $row;
  $dias = (int)$row['antiguedad_dias'];
  if ($dias < 30) $rangos['<30']++;
  elseif ($dias <= 90) $rangos['30-90']++;
  else $rangos['>90']++;

  $total++;
  $sumAntiguedad += $dias;
  $sumPrecio  += (float)$row['precio_lista'];
  $sumProfit  += (float)$row['profit'];
  if ($row['estatus'] === 'Disponible') $cntDisp++;
  if ($row['estatus'] === 'En tránsito') $cntTrans++;
}

$promAntiguedad = $total ? round($sumAntiguedad / $total, 1) : 0;
$promPrecio     = $total ? round($sumPrecio / $total, 2) : 0.0;
$promProfit     = $total ? round($sumProfit / $total, 2) : 0.0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Inventario Subdistribuidor</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/img/favicon.ico?v=7" sizes="any">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/responsive/2.5.1/css/responsive.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">

  <style>
    body { background: #f6f7fb; }
    .page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:18px auto 8px;padding:6px 4px;}
    .page-title{font-weight:700;letter-spacing:.2px;margin:0;}
    .role-chip{font-size:.8rem;padding:.2rem .55rem;border-radius:999px;background:#eef2ff;color:#3743a5;border:1px solid #d9e0ff;}
    .toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
    .filters-card{border:1px solid #e9ecf1;box-shadow:0 1px 6px rgba(16, 24, 40, .06);border-radius:16px;}
    .kpi{border:1px solid #e9ecf1;border-radius:16px;background:#fff;box-shadow:0 2px 8px rgba(16,24,40,.06);padding:16px;}
    .kpi h6{margin:0;font-size:.9rem;color:#6b7280;}
    .kpi .metric{font-weight:800;font-size:1.4rem;margin-top:4px;}
    .badge-soft{border:1px solid transparent;}
    .badge-soft.success{background:#e9f9ee;color:#0b7a3a;border-color:#b9ebc9;}
    .badge-soft.warning{background:#fff6e6;color:#955f00;border-color:#ffe2ad;}
    .profit-pos{color:#0b7a3a;font-weight:700;}
    .profit-neg{color:#b42318;font-weight:700;}
    .chip{display:inline-flex;align-items:center;gap:6px;padding:2px 10px;border-radius:999px;background:#f1f5f9;color:#0f172a;font-size:.8rem;border:1px solid #e2e8f0;}
    .status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;}
    .dot-green{background:#16a34a;}
    .dot-amber{background:#f59e0b;}
    .dot-gray{background:#94a3b8;}
    .ant-pill{font-size:.75rem;padding:.2rem .5rem;border-radius:999px;}
    .ant-pill.lt{background:#e9f9ee;color:#0b7a3a;border:1px solid #b9ebc9;}
    .ant-pill.md{background:#fff6e6;color:#955f00;border:1px solid #ffe2ad;}
    .ant-pill.gt{background:#ffecec;color:#9f1c1c;border:1px solid #ffc6c6;}
    .table-wrap{background:#fff;border:1px solid #e9ecf1;border-radius:16px;padding:8px 8px 16px;box-shadow:0 2px 10px rgba(16,24,40,.06);}
    #tablaInventario td,#tablaInventario th{padding:.35rem .5rem;font-size:.88rem;}
    .copy-btn{border:0;background:transparent;cursor:pointer;}
    .copy-btn:hover{opacity:.8;}
    .quick-search{display:flex;align-items:center;gap:6px;}
    .quick-search .form-control{max-width:240px;}
    @media (max-width:992px){
      .page-head{flex-direction:column;align-items:flex-start;}
      .toolbar{width:100%;justify-content:flex-start;flex-wrap:wrap;}
      .quick-search .form-control{max-width:100%;}
    }
  </style>
</head>
<body>
  <div class="container-fluid px-3 px-lg-4">

    <div class="page-head">
      <div>
        <h2 class="page-title">🌎 Inventario Subdistribuidor</h2>
        <div class="mt-1"><span class="role-chip">Subdistribuidor</span></div>
      </div>
      <div class="toolbar">
        <button class="btn btn-outline-secondary btn-sm rounded-pill" data-bs-toggle="collapse" data-bs-target="#filtrosCollapse">
          <i class="bi bi-sliders me-1"></i> Filtros
        </button>
        <a href="inventario_subdis.php" class="btn btn-light btn-sm rounded-pill border">
          <i class="bi bi-arrow-counterclockwise me-1"></i> Limpiar
        </a>

        <div class="quick-search ms-lg-2 mt-2 mt-lg-0">
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-qr-code"></i></span>
            <input id="qImei" type="text" class="form-control" placeholder="Buscar IMEI rápido..." value="<?= h($filtroImei) ?>">
            <button id="btnClearQImei" class="btn btn-outline-secondary" type="button" title="Limpiar"><i class="bi bi-x-circle"></i></button>
          </div>
        </div>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-6 col-md-4 col-lg-2"><div class="kpi"><h6>Total equipos</h6><div class="metric"><?= number_format($total) ?></div></div></div>
      <div class="col-6 col-md-4 col-lg-2"><div class="kpi"><h6>Disponible</h6><div class="metric"><span class="badge badge-soft success"><?= number_format($cntDisp) ?></span></div></div></div>
      <div class="col-6 col-md-4 col-lg-2"><div class="kpi"><h6>En tránsito</h6><div class="metric"><span class="badge badge-soft warning"><?= number_format($cntTrans) ?></span></div></div></div>
      <div class="col-6 col-md-4 col-lg-2"><div class="kpi"><h6>Antigüedad prom.</h6><div class="metric"><?= $promAntiguedad ?> d</div></div></div>
      <div class="col-6 col-md-4 col-lg-2"><div class="kpi"><h6>Ticket promedio</h6><div class="metric">$<?= number_format($promPrecio, 2) ?></div></div></div>
      <div class="col-6 col-md-4 col-lg-2"><div class="kpi"><h6>Profit prom.</h6><div class="metric <?= $promProfit >= 0 ? 'text-success' : 'text-danger' ?>">$<?= number_format($promProfit, 2) ?></div></div></div>
    </div>

    <div id="filtrosCollapse" class="collapse">
      <div class="card filters-card p-3 mb-3">
        <form method="GET">
          <div class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
              <label class="form-label">Sucursal</label>
              <select name="sucursal" class="form-select">
                <option value="">Todas</option>
                <?php while ($s = $sucursales->fetch_assoc()): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= $filtroSucursal == $s['id'] ? 'selected' : '' ?>><?= h($s['nombre']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">IMEI</label>
              <input type="text" name="imei" class="form-control" placeholder="Buscar IMEI..." value="<?= h($filtroImei) ?>">
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label">Modelo</label>
              <input type="text" name="modelo" class="form-control" placeholder="Buscar modelo..." value="<?= h($filtroModelo) ?>">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Estatus</label>
              <select name="estatus" class="form-select">
                <option value="">Todos</option>
                <option value="Disponible" <?= $filtroEstatus == 'Disponible' ? 'selected' : '' ?>>Disponible</option>
                <option value="En tránsito" <?= $filtroEstatus == 'En tránsito' ? 'selected' : '' ?>>En tránsito</option>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Antigüedad</label>
              <select name="antiguedad" class="form-select">
                <option value="">Todas</option>
                <option value="<30" <?= $filtroAntiguedad == '<30' ? 'selected' : '' ?>>&lt; 30 días</option>
                <option value="30-90" <?= $filtroAntiguedad == '30-90' ? 'selected' : '' ?>>30-90 días</option>
                <option value=">90" <?= $filtroAntiguedad == '>90' ? 'selected' : '' ?>>&gt; 90 días</option>
              </select>
            </div>
            <div class="col-6 col-md-1">
              <label class="form-label">Precio min</label>
              <input type="number" step="0.01" name="precio_min" class="form-control" value="<?= h($filtroPrecioMin) ?>">
            </div>
            <div class="col-6 col-md-1">
              <label class="form-label">Precio max</label>
              <input type="number" step="0.01" name="precio_max" class="form-control" value="<?= h($filtroPrecioMax) ?>">
            </div>
            <div class="col-12 col-md-12 text-end">
              <button class="btn btn-primary rounded-pill"><i class="bi bi-filter me-1"></i>Aplicar</button>
              <a href="inventario_subdis.php" class="btn btn-light rounded-pill border"><i class="bi bi-eraser me-1"></i>Limpiar</a>
            </div>
          </div>
        </form>
      </div>
    </div>

    <div class="table-wrap">
      <table id="tablaInventario" class="table table-striped table-hover align-middle" style="width:100%;">
        <thead class="table-light">
          <tr>
            <th>Sucursal</th><th>Marca</th><th>Modelo</th><th>Color</th><th>RAM</th><th>Almacenamiento</th>
            <th>IMEI1</th><th>IMEI2</th><th>Proveedor</th>
            <th>Costo c/IVA ($)</th><th>Precio Lista ($)</th><th>Profit ($)</th>
            <th>Cantidad</th><th>Estatus</th><th>Fecha ingreso</th><th>Antigüedad</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($inventario as $row):
            $dias  = (int)$row['antiguedad_dias'];
            $costoConIva = $row['costo_con_iva'];
            if ($costoConIva === null || $costoConIva === '') $costoConIva = $row['costo'];
            $profit = (float)$row['profit'];
            $antClass = $dias < 30 ? 'lt' : ($dias <= 90 ? 'md' : 'gt');
            $estatus = $row['estatus'];
            $statusChip = $estatus === 'Disponible'
              ? '<span class="chip"><span class="status-dot dot-green"></span>Disponible</span>'
              : '<span class="chip"><span class="status-dot dot-amber"></span>En tránsito</span>';
            $fechaSolo = h(substr((string)$row['fecha_ingreso'], 0, 10));
            $cantMostrar = (int)$row['cantidad_mostrar'];
          ?>
            <tr>
              <td><?= h($row['sucursal']) ?></td>
              <td><?= h($row['marca']) ?></td>
              <td><?= h($row['modelo']) ?></td>
              <td><?= h($row['color']) ?></td>
              <td><?= h($row['ram'] ?? '-') ?></td>
              <td><?= h($row['capacidad'] ?? '-') ?></td>
              <td><?= h($row['imei1'] ?? '-') ?></td>
              <td><?= h($row['imei2'] ?? '-') ?></td>
              <td><?= h($row['proveedor'] ?? '-') ?></td>
              <td class="text-end">$<?= number_format((float)$costoConIva, 2) ?></td>
              <td class="text-end">$<?= number_format((float)$row['precio_lista'], 2) ?></td>
              <td class="text-end"><span class="<?= $profit >= 0 ? 'profit-pos' : 'profit-neg' ?>">$<?= number_format($profit, 2) ?></span></td>
              <td class="text-end"><?= number_format($cantMostrar) ?></td>
              <td><?= $statusChip ?></td>
              <td><?= $fechaSolo ?></td>
              <td><span class="ant-pill <?= $antClass ?>"><?= $dias ?> d</span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
</body>
</html>
