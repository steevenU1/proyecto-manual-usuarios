<?php
// inventario_global.php — LUGA (RAM + "Almacenamiento", fecha sin hora, tabla compacta, CANTIDAD para accesorios)
// + QuickSearch IMEI con modal si no está disponible: busca Retiro o Traspaso (en tránsito)
// + LUGA por defecto SOLO ve inventario LUGA
// + Checkbox opcional para mostrar inventario Subdis
// + Badge visual de Propiedad
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: 403.php");
  exit();
}

$ROL_RAW = trim((string)($_SESSION['rol'] ?? ''));
$ROL_NORM = strtolower($ROL_RAW);
$ROL_NORM = str_replace([' ', '-'], '_', $ROL_NORM);
$ROL_NORM = preg_replace('/_+/', '_', $ROL_NORM);

$isLugaRole   = in_array($ROL_RAW, ['Admin','GerenteZona','Logistica'], true);
$isSubdisRole = in_array($ROL_NORM, ['subdis_admin','subdis_gerente','subdis_ejecutivo'], true);

if (!($isLugaRole || $isSubdisRole)) {
  header("Location: 403.php");
  exit();
}

$ROL = $ROL_RAW;
// No se puede editar precio desde esta vista
$canEditPrice = false;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';
require_once __DIR__ . '/verificar_sesion.php';

// ===== Helpers =====
if (!function_exists('h')) {
  function h($s)
  {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('normalizarPropiedad')) {
  function normalizarPropiedad($valor)
  {
    $v = trim((string)$valor);
    if ($v === '') return 'Luga';

    $vLower = mb_strtolower($v, 'UTF-8');
    if (in_array($vLower, ['subdis', 'subdistribuidor', 'subdistribuidora'], true)) {
      return 'Subdis';
    }
    return 'Luga';
  }
}

/* ===============================
   Scope / Multi-tenant (Luga vs Subdis)
   - LUGA roles: por defecto SOLO LUGA, opcionalmente pueden ver Subdis con checkbox
   - Subdis roles: SOLO sus sucursales (sucursales.id_subdis)
   =============================== */
$isSubdisUser = $isSubdisRole;
$isLugaUser   = $isLugaRole;

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
$idSubdis  = 0;
$subdisScopeError = '';

// ✅ Nuevo: para LUGA, por defecto NO mostrar subdis
$mostrarSubdis = (!$isSubdisUser && isset($_GET['mostrar_subdis']) && (string)$_GET['mostrar_subdis'] === '1');

if ($isSubdisUser) {
  // 1) sesión
  $idSubdis = (int)($_SESSION['id_subdis'] ?? 0);
  if ($idSubdis <= 0) $idSubdis = (int)($_SESSION['id_subdistribuidor'] ?? 0);

  // 2) intentar deducir desde la sucursal del usuario (más confiable)
  if ($idSubdis <= 0 && $idUsuario > 0) {
    try {
      $stmt = $conn->prepare("
        SELECT COALESCE(s.id_subdis,0) AS id_subdis
        FROM usuarios u
        INNER JOIN sucursales s ON s.id = u.id_sucursal
        WHERE u.id = ?
        LIMIT 1
      ");
      $stmt->bind_param('i', $idUsuario);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($row = $res->fetch_assoc()) {
        $idSubdis = (int)($row['id_subdis'] ?? 0);
      }
      $stmt->close();
    } catch (Throwable $e) {
      // noop
    }
  }

  // Persistir si se pudo
  if ($idSubdis > 0) {
    $_SESSION['id_subdis'] = $idSubdis;
  } else {
    // Por seguridad: si no hay subdis, NO mostramos inventario.
    $subdisScopeError = 'No se pudo determinar tu Subdistribuidor (id_subdis). Por seguridad, no se mostrará inventario. Revisa que tu usuario/sucursal tenga id_subdis asignado.';
  }
}

// ===== Filtros =====
$filtroImei       = $_GET['imei']        ?? '';
$filtroSucursal   = $_GET['sucursal']    ?? '';
$filtroEstatus    = $_GET['estatus']     ?? '';
$filtroAntiguedad = $_GET['antiguedad']  ?? '';
$filtroPrecioMin  = $_GET['precio_min']  ?? '';
$filtroPrecioMax  = $_GET['precio_max']  ?? '';
$filtroModelo     = $_GET['modelo']      ?? ''; // Filtro por modelo

$sql = "
  SELECT 
         i.id AS id_inventario,
         s.id AS id_sucursal,
         s.nombre AS sucursal,
         COALESCE(NULLIF(TRIM(s.propiedad), ''), 'Luga') AS propiedad,
         COALESCE(s.id_subdis, 0) AS id_subdis,
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
    AND COALESCE(i.cantidad, 0) > 0
";

$params = [];
$types  = "";

/* ===== Scope filtro para Subdis ===== */
if ($isSubdisUser) {
  if ($idSubdis > 0) {
    // SOLO sucursales del subdis. Sin Eulalia.
    $sql .= " AND (COALESCE(NULLIF(TRIM(s.propiedad), ''), '') IN ('Subdis','Subdistribuidor','Subdistribuidora') AND COALESCE(s.id_subdis,0)=?)";
    $params[] = $idSubdis;
    $types   .= "i";
  } else {
    // Seguridad: no mostrar nada si no hay subdis
    $sql .= " AND 1=0";
  }
} else {
  // ✅ LUGA: por defecto SOLO LUGA. Si activan checkbox, también ven Subdis.
  if (!$mostrarSubdis) {
    $sql .= " AND COALESCE(NULLIF(TRIM(s.propiedad), ''), 'Luga') NOT IN ('Subdis','Subdistribuidor','Subdistribuidora')";
  }
}

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
if (!empty($params)) {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

if ($isSubdisUser) {
  // Dropdown solo con sucursales permitidas (SOLO Subdis)
  if ($idSubdis > 0) {
    $stmtSuc = $conn->prepare("
      SELECT id, nombre
      FROM sucursales
      WHERE (COALESCE(NULLIF(TRIM(propiedad), ''), '') IN ('Subdis','Subdistribuidor','Subdistribuidora') AND COALESCE(id_subdis,0)=?)
      ORDER BY nombre
    ");
    $stmtSuc->bind_param('i', $idSubdis);
    $stmtSuc->execute();
    $sucursales = $stmtSuc->get_result();
  } else {
    $sucursales = $conn->query("SELECT id, nombre FROM sucursales WHERE 1=0");
  }
} else {
  // ✅ LUGA: dropdown respeta el filtro actual
  if ($mostrarSubdis) {
    $sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
  } else {
    $sucursales = $conn->query("
      SELECT id, nombre
      FROM sucursales
      WHERE COALESCE(NULLIF(TRIM(propiedad), ''), 'Luga') NOT IN ('Subdis','Subdistribuidor','Subdistribuidora')
      ORDER BY nombre
    ");
  }
}

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
  $row['propiedad_norm'] = normalizarPropiedad($row['propiedad'] ?? 'Luga');
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

$pageTitleTxt = $isSubdisUser ? 'Inventario Subdis' : 'Inventario Global';
$pageH2Txt    = $isSubdisUser ? '🌎 Inventario Subdistribuidor' : '🌎 Inventario Global';

if ($isSubdisUser) {
  $pageChipTxt = 'Subdistribuidor';
} else {
  $pageChipTxt = $mostrarSubdis ? 'LUGA + Subdis' : 'Solo LUGA';
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <title><?= h($pageTitleTxt) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" href="/img/favicon.ico?v=7" sizes="any">

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" />

  <!-- DataTables -->
  <link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/responsive/2.5.1/css/responsive.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">

  <style>
    body { background: #f6f7fb; }
    .page-head { display:flex; align-items:center; justify-content:space-between; gap:16px; margin:18px auto 8px; padding:6px 4px; }
    .page-title { font-weight:700; letter-spacing:.2px; margin:0; }
    .role-chip { font-size:.8rem; padding:.2rem .55rem; border-radius:999px; background:#eef2ff; color:#3743a5; border:1px solid #d9e0ff; }
    .toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .filters-card { border:1px solid #e9ecf1; box-shadow:0 1px 6px rgba(16,24,40,.06); border-radius:16px; }
    .kpi { border:1px solid #e9ecf1; border-radius:16px; background:#fff; box-shadow:0 2px 8px rgba(16,24,40,.06); padding:16px; }
    .kpi h6 { margin:0; font-size:.9rem; color:#6b7280; }
    .kpi .metric { font-weight:800; font-size:1.4rem; margin-top:4px; }
    .badge-soft { border:1px solid transparent; }
    .badge-soft.success { background:#e9f9ee; color:#0b7a3a; border-color:#b9ebc9; }
    .badge-soft.warning { background:#fff6e6; color:#955f00; border-color:#ffe2ad; }
    .table thead th { white-space:nowrap; }
    .profit-pos { color:#0b7a3a; font-weight:700; }
    .profit-neg { color:#b42318; font-weight:700; }
    .chip { display:inline-flex; align-items:center; gap:6px; padding:2px 10px; border-radius:999px; background:#f1f5f9; color:#0f172a; font-size:.8rem; border:1px solid #e2e8f0; }
    .status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
    .dot-green { background:#16a34a; }
    .dot-amber { background:#f59e0b; }
    .dot-gray { background:#94a3b8; }
    .ant-pill { font-size:.75rem; padding:.2rem .5rem; border-radius:999px; }
    .ant-pill.lt { background:#e9f9ee; color:#0b7a3a; border:1px solid #b9ebc9; }
    .ant-pill.md { background:#fff6e6; color:#955f00; border:1px solid #ffe2ad; }
    .ant-pill.gt { background:#ffecec; color:#9f1c1c; border:1px solid #ffc6c6; }
    .table-wrap { background:#fff; border:1px solid #e9ecf1; border-radius:16px; padding:8px 8px 16px; box-shadow:0 2px 10px rgba(16,24,40,.06); }
    #tablaInventario td, #tablaInventario th { padding:.35rem .5rem; font-size:.88rem; }
    #tablaInventario td:nth-child(10), #tablaInventario th:nth-child(10) { max-width:180px; }
    #tablaInventario td:nth-child(10) .truncate { display:inline-block; max-width:180px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .dt-buttons .btn { border-radius:999px !important; }
    .copy-btn { border:0; background:transparent; cursor:pointer; }
    .copy-btn:hover { opacity:.8; }
    .quick-search { display:flex; align-items:center; gap:6px; }
    .quick-search .form-control { max-width:240px; }

    .prop-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: .22rem .65rem;
      border-radius: 999px;
      font-size: .76rem;
      font-weight: 700;
      border: 1px solid transparent;
      white-space: nowrap;
    }
    .prop-badge.luga {
      background: #e8f1ff;
      color: #1d4ed8;
      border-color: #c8ddff;
    }
    .prop-badge.subdis {
      background: #fff4e6;
      color: #b45309;
      border-color: #ffd8a8;
    }

    .toggle-wrap {
      background: #f8fafc;
      border: 1px dashed #dbe3ee;
      border-radius: 14px;
      padding: .75rem .95rem;
    }

    @media (max-width: 992px) {
      .page-head { flex-direction:column; align-items:flex-start; }
      .toolbar { width:100%; justify-content:flex-start; flex-wrap:wrap; }
      .quick-search .form-control { max-width:100%; }
    }
  </style>

  <style>
    /* SUBDIS: ocultar columna Profit ($) sin romper DataTables */
    <?php if (!empty($isSubdisUser)): ?>
      #tablaInventario th.col-profit,
      #tablaInventario td.col-profit { display: none !important; }
    <?php endif; ?>
  </style>
</head>

<body>
  <div class="container-fluid px-3 px-lg-4">

    <!-- Encabezado -->
    <div class="page-head">
      <div>
        <h2 class="page-title"><?= h($pageH2Txt) ?></h2>
        <div class="mt-1">
          <span class="role-chip">
            <?=
              $isSubdisUser
                ? h($pageChipTxt)
                : h($pageChipTxt)
            ?>
          </span>
        </div>
      </div>
      <div class="toolbar">
        <button class="btn btn-outline-secondary btn-sm rounded-pill" data-bs-toggle="collapse" data-bs-target="#filtrosCollapse">
          <i class="bi bi-sliders me-1"></i> Filtros
        </button>
        <?php if(!$isSubdisUser): ?>
          <a href="exportar_inventario_global.php?<?= h(http_build_query($_GET)) ?>" class="btn btn-success btn-sm rounded-pill">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Exportar Excel
          </a>
        <?php endif; ?>
        <a href="inventario_global.php" class="btn btn-light btn-sm rounded-pill border">
          <i class="bi bi-arrow-counterclockwise me-1"></i> Limpiar
        </a>

        <!-- 🔎 Buscador rápido IMEI (client-side) -->
        <div class="quick-search ms-lg-2 mt-2 mt-lg-0">
          <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-qr-code"></i></span>
            <input id="qImei" type="text" class="form-control" placeholder="Buscar IMEI rápido..." value="<?= h($filtroImei) ?>">
            <button id="btnClearQImei" class="btn btn-outline-secondary" type="button" title="Limpiar"><i class="bi bi-x-circle"></i></button>
          </div>
        </div>
      </div>
    </div>

    <?php if ($isSubdisUser && $subdisScopeError): ?>
      <div class="alert alert-warning border rounded-3">
        <div class="d-flex gap-2 align-items-start">
          <i class="bi bi-shield-exclamation fs-5"></i>
          <div>
            <div class="fw-semibold">Acceso restringido</div>
            <div class="small"><?= h($subdisScopeError) ?></div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$isSubdisUser): ?>
      <div class="toggle-wrap mb-3">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2">
          <div>
            <div class="fw-semibold">Vista de inventario</div>
            
          </div>
          <form method="GET" class="m-0">
            <input type="hidden" name="sucursal" value="<?= h($filtroSucursal) ?>">
            <input type="hidden" name="imei" value="<?= h($filtroImei) ?>">
            <input type="hidden" name="modelo" value="<?= h($filtroModelo) ?>">
            <input type="hidden" name="estatus" value="<?= h($filtroEstatus) ?>">
            <input type="hidden" name="antiguedad" value="<?= h($filtroAntiguedad) ?>">
            <input type="hidden" name="precio_min" value="<?= h($filtroPrecioMin) ?>">
            <input type="hidden" name="precio_max" value="<?= h($filtroPrecioMax) ?>">

            <div class="form-check form-switch mb-0">
              <input
                class="form-check-input"
                type="checkbox"
                role="switch"
                id="toggleMostrarSubdisTop"
                name="mostrar_subdis"
                value="1"
                <?= $mostrarSubdis ? 'checked' : '' ?>
                onchange="this.form.submit()"
              >
              <label class="form-check-label fw-semibold" for="toggleMostrarSubdisTop">
                Mostrar inventario Subdis
              </label>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-4 col-lg-2">
        <div class="kpi">
          <h6>Total equipos</h6>
          <div class="metric"><?= number_format($total) ?></div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <div class="kpi">
          <h6>Disponible</h6>
          <div class="metric"><span class="badge badge-soft success"><?= number_format($cntDisp) ?></span></div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <div class="kpi">
          <h6>En tránsito</h6>
          <div class="metric"><span class="badge badge-soft warning"><?= number_format($cntTrans) ?></span></div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <div class="kpi">
          <h6>Antigüedad prom.</h6>
          <div class="metric"><?= $promAntiguedad ?> d</div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <div class="kpi">
          <h6>Ticket promedio</h6>
          <div class="metric">$<?= number_format($promPrecio, 2) ?></div>
        </div>
      </div>

      <?php if (empty($isSubdisUser)): ?>
      <div class="col-6 col-md-4 col-lg-2">
        <div class="kpi">
          <h6>Profit prom.</h6>
          <div class="metric <?= $promProfit >= 0 ? 'text-success' : 'text-danger' ?>">$<?= number_format($promProfit, 2) ?></div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Filtros -->
    <div id="filtrosCollapse" class="collapse">
      <div class="card filters-card p-3 mb-3">
        <form method="GET">
          <div class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
              <label class="form-label">Sucursal</label>
              <select name="sucursal" class="form-select">
                <option value="">Todas</option>
                <?php while ($s = $sucursales->fetch_assoc()): ?>
                  <option value="<?= (int)$s['id'] ?>" <?= $filtroSucursal == $s['id'] ? 'selected' : '' ?>>
                    <?= h($s['nombre']) ?>
                  </option>
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
                <option value="<30" <?= $filtroAntiguedad == '<30' ? 'selected' : '' ?>>< 30 días</option>
                <option value="30-90" <?= $filtroAntiguedad == '30-90' ? 'selected' : '' ?>>30-90 días</option>
                <option value=">90" <?= $filtroAntiguedad == '>90' ? 'selected' : '' ?>>> 90 días</option>
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

            <?php if(!$isSubdisUser): ?>
              <div class="col-12 col-md-3">
                <div class="form-check mt-md-4 pt-md-2">
                  <input
                    class="form-check-input"
                    type="checkbox"
                    name="mostrar_subdis"
                    value="1"
                    id="mostrar_subdis"
                    <?= $mostrarSubdis ? 'checked' : '' ?>
                  >
                  <label class="form-check-label" for="mostrar_subdis">
                    Mostrar inventario Subdis
                  </label>
                </div>
              </div>
            <?php endif; ?>

            <div class="col-12 col-md-12 text-end">
              <button class="btn btn-primary rounded-pill"><i class="bi bi-filter me-1"></i>Aplicar</button>
              <a href="inventario_global.php" class="btn btn-light rounded-pill border"><i class="bi bi-eraser me-1"></i>Limpiar</a>
              <?php if(!$isSubdisUser): ?>
                <a href="exportar_inventario_global.php?<?= h(http_build_query($_GET)) ?>" class="btn btn-success rounded-pill">
                  <i class="bi bi-file-earmark-excel me-1"></i>Exportar
                </a>
              <?php endif; ?>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Gráfica + Top 5 -->
    <div class="d-flex justify-content-between mb-3 flex-wrap gap-3">
      <div class="card p-3 shadow-sm" style="max-width:480px; width:100%;">
        <h6 class="mb-2">Antigüedad del inventario</h6>
        <canvas id="graficaAntiguedad"></canvas>
        <div class="mt-2 d-flex gap-2 flex-wrap">
          <span class="ant-pill lt"><span class="status-dot dot-green me-1"></span>< 30 días: <?= (int)$rangos['<30'] ?></span>
          <span class="ant-pill md"><span class="status-dot dot-amber me-1"></span>30–90: <?= (int)$rangos['30-90'] ?></span>
          <span class="ant-pill gt"><span class="status-dot dot-gray me-1"></span>> 90: <?= (int)$rangos['>90'] ?></span>
        </div>
      </div>

      <?php if(empty($isSubdisUser)): ?>
      <div class="card shadow-sm p-3" style="min-width:320px; flex:1;">
        <div class="d-flex align-items-center justify-content-between">
          <h6 class="mb-0">🔥 Top 5 Equipos Vendidos</h6>
          <select id="filtro-top" class="form-select form-select-sm" style="max-width:180px;">
            <option value="semana">Esta Semana</option>
            <option value="mes">Este Mes</option>
            <option value="historico" selected>Histórico</option>
          </select>
        </div>
        <div id="tabla-top" class="mt-2">
          <div class="text-muted small">Cargando...</div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Tabla -->
    <div class="table-wrap">
      <table id="tablaInventario" class="table table-striped table-hover align-middle" style="width:100%;">
        <thead class="table-light">
          <tr>
            <th>Sucursal</th>
            <th>Propiedad</th>
            <th>Marca</th>
            <th>Modelo</th>
            <th>Color</th>
            <th>RAM</th>
            <th>Almacenamiento</th>
            <th>IMEI1</th>
            <th>IMEI2</th>
            <th>Proveedor</th>
            <th>Costo c/IVA ($)</th>
            <th>Precio Lista ($)</th>
            <th class="col-profit">Profit ($)</th>
            <th>Cantidad</th>
            <th>Estatus</th>
            <th>Fecha ingreso</th>
            <th>Antigüedad</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($inventario as $row):
            $dias  = (int)$row['antiguedad_dias'];
            $costoConIva = $row['costo_con_iva'];
            if ($costoConIva === null || $costoConIva === '') {
              $costoConIva = $row['costo'];
            }
            $profit = (float)$row['profit'];
            $antClass = $dias < 30 ? 'lt' : ($dias <= 90 ? 'md' : 'gt');
            $estatus = $row['estatus'];
            $statusChip = $estatus === 'Disponible'
              ? '<span class="chip"><span class="status-dot dot-green"></span>Disponible</span>'
              : '<span class="chip"><span class="status-dot dot-amber"></span>En tránsito</span>';

            $fechaSolo = h(substr((string)$row['fecha_ingreso'], 0, 10));
            $cantMostrar = (int)$row['cantidad_mostrar'];

            $propNorm = $row['propiedad_norm'] ?? 'Luga';
            $propBadge = $propNorm === 'Subdis'
              ? '<span class="prop-badge subdis"><i class="bi bi-shop"></i>Subdis</span>'
              : '<span class="prop-badge luga"><i class="bi bi-building"></i>LUGA</span>';
          ?>
            <tr>
              <td><?= h($row['sucursal']) ?></td>
              <td><?= $propBadge ?></td>
              <td><?= h($row['marca']) ?></td>
              <td><?= h($row['modelo']) ?></td>
              <td><?= h($row['color']) ?></td>
              <td><?= h($row['ram'] ?? '-') ?></td>
              <td><?= h($row['capacidad'] ?? '-') ?></td>
              <td>
                <span><?= h($row['imei1'] ?? '-') ?></span>
                <?php if (!empty($row['imei1'])): ?>
                  <button class="copy-btn ms-1" title="Copiar IMEI" onclick="copyText('<?= h($row['imei1']) ?>')">
                    <i class="bi bi-clipboard"></i>
                  </button>
                <?php endif; ?>
              </td>
              <td>
                <span><?= h($row['imei2'] ?? '-') ?></span>
                <?php if (!empty($row['imei2'])): ?>
                  <button class="copy-btn ms-1" title="Copiar IMEI" onclick="copyText('<?= h($row['imei2']) ?>')">
                    <i class="bi bi-clipboard"></i>
                  </button>
                <?php endif; ?>
              </td>
              <td title="<?= h($row['proveedor'] ?? '-') ?>">
                <span class="truncate"><?= h($row['proveedor'] ?? '-') ?></span>
              </td>
              <td class="text-end">$<?= number_format((float)$costoConIva, 2) ?></td>
              <td class="text-end">$<?= number_format((float)$row['precio_lista'], 2) ?></td>
              <td class="text-end col-profit">
                <span class="<?= $profit >= 0 ? 'profit-pos' : 'profit-neg' ?>">$<?= number_format($profit, 2) ?></span>
              </td>
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

  <!-- Modal Resultado Búsqueda IMEI -->
  <div class="modal fade" id="imeiStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header bg-warning-subtle">
          <h5 class="modal-title"><i class="bi bi-info-circle me-2"></i>Estado del equipo</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body" id="imeiStatusBody"></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- JS -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

  <!-- DataTables core + plugins -->
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.1/js/responsive.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.1/js/responsive.bootstrap5.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

  <script>
    function copyText(t) {
      navigator.clipboard.writeText(t).then(() => {
        const toast = document.createElement('div');
        toast.className = 'position-fixed top-0 start-50 translate-middle-x p-2';
        toast.style.zIndex = 1080;
        toast.innerHTML = '<span class="badge text-bg-success rounded-pill">IMEI copiado</span>';
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 1200);
      });
    }

    let dt;
    $(function() {
      const IS_SUBDIS = <?php echo ($isSubdisUser ? 'true' : 'false'); ?>;

      $.fn.dataTable.ext.search.push(function(settings, data) {
        if (settings.nTable.id !== 'tablaInventario') return true;
        const q = (window.__qImei || '').trim();
        if (q === '') return true;

        // Columnas actuales:
        // 0 Sucursal
        // 1 Propiedad
        // 2 Marca
        // 3 Modelo
        // 4 Color
        // 5 RAM
        // 6 Almacenamiento
        // 7 IMEI1
        // 8 IMEI2
        const imei1 = (data[7] || '').toString();
        const imei2 = (data[8] || '').toString();
        return imei1.indexOf(q) !== -1 || imei2.indexOf(q) !== -1;
      });

      dt = $('#tablaInventario').DataTable({
        pageLength: 25,
        order: [[15, "desc"]],
        responsive: true,
        autoWidth: false,
        fixedHeader: true,
        language: { url: "//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json" },
        dom: "<'row align-items-center mb-2'<'col-sm-12 col-md-6'B><'col-sm-12 col-md-6'f>>" +
             "tr" +
             "<'row mt-2'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        buttons: [
          { extend: 'csvHtml5', className: 'btn btn-light btn-sm rounded-pill border', text: '<i class="bi bi-filetype-csv me-1"></i>CSV' },
          { extend: 'excelHtml5', className: 'btn btn-light btn-sm rounded-pill border', text: '<i class="bi bi-file-earmark-excel me-1"></i>Excel' },
          { extend: 'colvis', className: 'btn btn-light btn-sm rounded-pill border', text: '<i class="bi bi-view-list me-1"></i>Columnas' }
        ],
        columnDefs: [
          { targets: [10,11,12,13], className: 'text-end' },
          { targets: [15,16], className: 'text-nowrap' },
          { responsivePriority: 1, targets: 0 },
          { responsivePriority: 2, targets: 2 },
          { responsivePriority: 3, targets: 3 },
          { responsivePriority: 4, targets: 1 },
          { responsivePriority: 100, targets: [8,9] }
        ]
      });

      // Subdis: ocultar columna "Costo c/IVA ($)" sin romper DataTables
      if (typeof IS_SUBDIS !== 'undefined' && IS_SUBDIS) {
        try { dt.column(10).visible(false); } catch(e) {}
      }

      const $q = $('#qImei');
      const $btnClear = $('#btnClearQImei');

      function applyQuickFilter(val) {
        window.__qImei = val || '';
        dt.draw();

        const q = (window.__qImei || '').trim();
        if (!q) return;

        fetch('imei_lookup_status.php?imei=' + encodeURIComponent(q))
          .then(r => r.json())
          .then(payload => {
            if (!payload) return;
            if (payload.status === 'ok') {
              if (Array.isArray(payload.kind) && payload.kind.length > 0) {
                const body = document.getElementById('imeiStatusBody');
                body.innerHTML = payload.html;
                const modal = new bootstrap.Modal(document.getElementById('imeiStatusModal'));
                modal.show();
              }
            } else if (payload.status === 'not_found') {
              const visibleRows = dt.rows({ filter: 'applied' }).data().length;
              if (visibleRows === 0) {
                const body = document.getElementById('imeiStatusBody');
                body.innerHTML = `
                  <div class="d-flex align-items-start gap-2">
                    <i class="bi bi-exclamation-triangle fs-4 text-warning"></i>
                    <div>
                      <div class="fw-semibold">No está disponible en inventario.</div>
                      <div class="text-muted">No se encontró en retiros ni en traspasos activos.</div>
                    </div>
                  </div>`;
                const modal = new bootstrap.Modal(document.getElementById('imeiStatusModal'));
                modal.show();
              }
            }
          })
          .catch(() => {});
      }

      let tId = null;
      $q.on('input', function() {
        const v = this.value;
        clearTimeout(tId);
        tId = setTimeout(() => applyQuickFilter(v), 140);
      });

      $q.on('keydown', function(e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          applyQuickFilter(this.value);
        }
      });

      $btnClear.on('click', function() {
        $q.val('');
        applyQuickFilter('');
        $q.trigger('focus');
      });

      if ($q.val().trim() !== '') {
        applyQuickFilter($q.val().trim());
      }
    });

    (function() {
      const ctx = document.getElementById('graficaAntiguedad').getContext('2d');
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: ['<30 días', '30-90 días', '>90 días'],
          datasets: [{
            label: 'Cantidad de equipos',
            data: [<?= (int)$rangos['<30'] ?>, <?= (int)$rangos['30-90'] ?>, <?= (int)$rangos['>90'] ?>]
          }]
        },
        options: {
          responsive: true,
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true } }
        }
      });
    })();

    <?php if(empty($isSubdisUser)): ?>
    function cargarTopVendidos(rango = 'historico') {
      fetch('top_productos.php?rango=' + encodeURIComponent(rango))
        .then(res => res.text())
        .then(html => { document.getElementById('tabla-top').innerHTML = html; })
        .catch(() => { document.getElementById('tabla-top').innerHTML = '<div class="text-danger small">No se pudo cargar el Top.</div>'; });
    }

    document.getElementById('filtro-top')?.addEventListener('change', function() {
      cargarTopVendidos(this.value);
    });

    cargarTopVendidos();
    <?php endif; ?>
  </script>
</body>
</html>