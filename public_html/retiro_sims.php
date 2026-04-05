<?php
// retiro_sims.php — Retiro de SIMs con pestañas: Buscar / Carrito / Historial

session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

if (!isset($_SESSION['carrito_retiro']) || !is_array($_SESSION['carrito_retiro'])) {
  $_SESSION['carrito_retiro'] = [];
}
if (empty($_SESSION['retiro_token'])) {
  $_SESSION['retiro_token'] = bin2hex(random_bytes(16));
}

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function baseUrl(): string
{
  $self = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
  return $self ?: 'retiro_sims.php';
}

function qs(array $extra = []): string
{
  $keep = $_GET;
  unset($keep['a'], $keep['id']);
  $q = array_merge($keep, $extra);
  return $q ? ('?' . http_build_query($q)) : '';
}

$tab = trim((string)($_GET['tab'] ?? 'buscar'));
$tabsValidas = ['buscar', 'carrito', 'historial'];
if (!in_array($tab, $tabsValidas, true)) {
  $tab = 'buscar';
}

// ===== Filtros =====
$sucursalSel = trim($_GET['sucursal'] ?? '');
$q_iccid     = trim($_GET['iccid'] ?? '');
$q_dn        = trim($_GET['dn'] ?? '');
$q_caja      = trim($_GET['caja'] ?? '');
$q_lote      = trim($_GET['lote'] ?? '');

// ===== Acciones de carrito (ANTES de HTML) =====
$accion = $_GET['a'] ?? '';

if ($accion === 'add' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  if (!in_array($id, $_SESSION['carrito_retiro'], true)) {
    $row = $conn->query("SELECT estatus FROM inventario_sims WHERE id={$id} LIMIT 1")->fetch_assoc();
    if ($row && $row['estatus'] === 'Disponible') {
      $_SESSION['carrito_retiro'][] = $id;
      $_SESSION['flash_ok'] = "SIM agregada al carrito.";
    } else {
      $_SESSION['flash_err'] = "No se puede agregar: solo se permiten SIMs en estatus Disponible.";
    }
  }
  header("Location: " . baseUrl() . qs(['tab' => 'buscar']));
  exit();
}

if ($accion === 'del' && isset($_GET['id'])) {
  $id = (int)$_GET['id'];
  $_SESSION['carrito_retiro'] = array_values(array_filter(
    $_SESSION['carrito_retiro'],
    fn($x) => (int)$x !== $id
  ));
  $_SESSION['flash_ok'] = "SIM removida del carrito.";
  header("Location: " . baseUrl() . qs(['tab' => 'carrito']));
  exit();
}

if ($accion === 'vaciar') {
  $_SESSION['carrito_retiro'] = [];
  $_SESSION['flash_ok'] = "Carrito vaciado.";
  header("Location: " . baseUrl() . qs(['tab' => 'carrito']));
  exit();
}

/* ==== Agregar caja completa al carrito ==== */
if ($accion === 'add_caja') {
  $cajaParam = trim($_GET['caja'] ?? '');
  if ($cajaParam === '') {
    $_SESSION['flash_err'] = "No se especificó la caja a retirar.";
    header("Location: " . baseUrl() . qs(['tab' => 'buscar']));
    exit();
  }

  $types = 's';
  $params = [$cajaParam];
  $sqlCaja = "SELECT id, estatus
              FROM inventario_sims
              WHERE caja_id = ?
                AND estatus = 'Disponible'";

  if ($sucursalSel !== '') {
    $sqlCaja .= " AND id_sucursal = ?";
    $types   .= 'i';
    $params[] = (int)$sucursalSel;
  }

  $stmtCaja = $conn->prepare($sqlCaja);
  $stmtCaja->bind_param($types, ...$params);
  $stmtCaja->execute();
  $resCaja = $stmtCaja->get_result();

  $agregadas = 0;
  while ($row = $resCaja->fetch_assoc()) {
    $idSim = (int)$row['id'];
    if (!in_array($idSim, $_SESSION['carrito_retiro'], true)) {
      $_SESSION['carrito_retiro'][] = $idSim;
      $agregadas++;
    }
  }

  if ($agregadas > 0) {
    $_SESSION['flash_ok'] = "Se agregaron {$agregadas} SIM(s) de la caja {$cajaParam} al carrito.";
  } else {
    $_SESSION['flash_err'] = "No se encontraron SIMs disponibles para la caja {$cajaParam} con los filtros actuales.";
  }

  header("Location: " . baseUrl() . qs(['tab' => 'buscar']));
  exit();
}

/* ==== Agregar lote completo al carrito ==== */
if ($accion === 'add_lote') {
  $loteParam = trim($_GET['lote'] ?? '');
  if ($loteParam === '') {
    $_SESSION['flash_err'] = "No se especificó el lote a retirar.";
    header("Location: " . baseUrl() . qs(['tab' => 'buscar']));
    exit();
  }

  $types = 's';
  $params = [$loteParam];
  $sqlLote = "SELECT id, estatus
              FROM inventario_sims
              WHERE lote = ?
                AND estatus = 'Disponible'";

  if ($sucursalSel !== '') {
    $sqlLote .= " AND id_sucursal = ?";
    $types   .= 'i';
    $params[] = (int)$sucursalSel;
  }

  $stmtLote = $conn->prepare($sqlLote);
  $stmtLote->bind_param($types, ...$params);
  $stmtLote->execute();
  $resLote = $stmtLote->get_result();

  $agregadas = 0;
  while ($row = $resLote->fetch_assoc()) {
    $idSim = (int)$row['id'];
    if (!in_array($idSim, $_SESSION['carrito_retiro'], true)) {
      $_SESSION['carrito_retiro'][] = $idSim;
      $agregadas++;
    }
  }

  if ($agregadas > 0) {
    $_SESSION['flash_ok'] = "Se agregaron {$agregadas} SIM(s) del lote {$loteParam} al carrito.";
  } else {
    $_SESSION['flash_err'] = "No se encontraron SIMs disponibles para el lote {$loteParam} con los filtros actuales.";
  }

  header("Location: " . baseUrl() . qs(['tab' => 'buscar']));
  exit();
}

/* ==== Carga masiva por ICCID ==== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['bulk_action'] ?? '') === 'bulk_add') {
  $csrf = (string)($_POST['csrf'] ?? '');
  if ($csrf === '' || $csrf !== ($_SESSION['retiro_token'] ?? '')) {
    $_SESSION['flash_err'] = "Token inválido. Recarga la página e intenta de nuevo.";
    header("Location: " . baseUrl() . qs(['tab' => 'buscar']));
    exit();
  }

  $raw = trim((string)($_POST['iccid_bulk'] ?? ''));
  if ($raw === '') {
    $_SESSION['flash_err'] = "Pega al menos un ICCID para agregar.";
    header("Location: " . baseUrl() . qs(['tab' => 'buscar']));
    exit();
  }

  $parts = preg_split('/[,\s;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
  $iccidList = [];

  foreach ($parts as $p) {
    $v = trim($p);
    $v = trim($v, "\"' \t\n\r\0\x0B");
    if ($v === '') continue;
    $iccidList[] = $v;
  }

  $seen = [];
  $unique = [];
  foreach ($iccidList as $v) {
    if (!isset($seen[$v])) {
      $seen[$v] = true;
      $unique[] = $v;
    }
  }

  $MAX_BULK = 500;
  if (count($unique) > $MAX_BULK) {
    $unique = array_slice($unique, 0, $MAX_BULK);
    $_SESSION['flash_err'] = "Se detectaron más de {$MAX_BULK} ICCID. Se procesaron solo los primeros {$MAX_BULK}.";
  }

  if (!count($unique)) {
    $_SESSION['flash_err'] = "No se detectaron ICCID válidos.";
    header("Location: " . baseUrl() . qs(['tab' => 'buscar']));
    exit();
  }

  $placeholders = implode(',', array_fill(0, count($unique), '?'));
  $types = str_repeat('s', count($unique));
  $params = $unique;

  $sqlBulk = "SELECT id, iccid, estatus
              FROM inventario_sims
              WHERE iccid IN ($placeholders)";

  if ($sucursalSel !== '') {
    $sqlBulk .= " AND id_sucursal = ?";
    $types   .= "i";
    $params[] = (int)$sucursalSel;
  }

  $stmtBulk = $conn->prepare($sqlBulk);
  $bind = [];
  $bind[] = $types;
  foreach ($params as $k => $val) {
    $bind[] = &$params[$k];
  }
  call_user_func_array([$stmtBulk, 'bind_param'], $bind);
  $stmtBulk->execute();
  $resBulk = $stmtBulk->get_result();

  $found = [];
  while ($row = $resBulk->fetch_assoc()) {
    $found[(string)$row['iccid']] = [
      'id' => (int)$row['id'],
      'estatus' => (string)$row['estatus'],
    ];
  }

  $added = 0;
  $already = 0;
  $notFound = 0;
  $notDisponible = 0;

  foreach ($unique as $ic) {
    if (!isset($found[$ic])) {
      $notFound++;
      continue;
    }
    if ($found[$ic]['estatus'] !== 'Disponible') {
      $notDisponible++;
      continue;
    }
    $idSim = (int)$found[$ic]['id'];
    if (in_array($idSim, $_SESSION['carrito_retiro'], true)) {
      $already++;
      continue;
    }
    $_SESSION['carrito_retiro'][] = $idSim;
    $added++;
  }

  $msg = "Carga masiva: agregadas {$added}, ya estaban {$already}, no encontradas {$notFound}, no disponibles {$notDisponible}.";
  if ($added > 0) {
    $_SESSION['flash_ok'] = $msg;
  } else {
    $_SESSION['flash_err'] = $msg;
  }

  header("Location: " . baseUrl() . qs(['tab' => 'buscar']));
  exit();
}

// ===== Navbar =====
require_once __DIR__ . '/navbar.php';

// ===== Catálogos =====
$suc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);

$sucMap = [];
foreach ($suc as $sx) {
  $sucMap[(int)$sx['id']] = $sx['nombre'];
}

// ===== Búsqueda de SIMs retirables =====
$where = ["estatus = 'Disponible'"];
$types = '';
$params = [];

if ($sucursalSel !== '') {
  $where[] = "id_sucursal = ?";
  $types .= 'i';
  $params[] = (int)$sucursalSel;
}
if ($q_iccid !== '') {
  $where[] = "iccid LIKE ?";
  $types .= 's';
  $params[] = '%' . $q_iccid . '%';
}
if ($q_dn !== '') {
  $where[] = "dn LIKE ?";
  $types .= 's';
  $params[] = '%' . $q_dn . '%';
}
if ($q_caja !== '') {
  $where[] = "caja_id = ?";
  $types .= 's';
  $params[] = $q_caja;
}
if ($q_lote !== '') {
  $where[] = "lote = ?";
  $types .= 's';
  $params[] = $q_lote;
}

$sql = "SELECT id, iccid, dn, operador, caja_id, lote, tipo_plan, id_sucursal, estatus, fecha_ingreso
        FROM inventario_sims
        " . (count($where) ? 'WHERE ' . implode(' AND ', $where) : '') . "
        ORDER BY fecha_ingreso DESC
        LIMIT 100";

$stmt = $conn->prepare($sql);
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

/* ==== Conteo disponibles por caja ==== */
$totalCajaDisponibles = null;
if ($q_caja !== '') {
  $typesCaja = 's';
  $paramsCaja = [$q_caja];
  $whereCaja = ["caja_id = ?", "estatus = 'Disponible'"];

  if ($sucursalSel !== '') {
    $whereCaja[] = "id_sucursal = ?";
    $typesCaja .= 'i';
    $paramsCaja[] = (int)$sucursalSel;
  }

  $sqlCountCaja = "SELECT COUNT(*) AS total
                   FROM inventario_sims
                   WHERE " . implode(' AND ', $whereCaja);
  $stmtCount = $conn->prepare($sqlCountCaja);
  $stmtCount->bind_param($typesCaja, ...$paramsCaja);
  $stmtCount->execute();
  $resCount = $stmtCount->get_result()->fetch_assoc();
  $totalCajaDisponibles = (int)($resCount['total'] ?? 0);
}

/* ==== Conteo disponibles por lote ==== */
$totalLoteDisponibles = null;
if ($q_lote !== '') {
  $typesLote = 's';
  $paramsLote = [$q_lote];
  $whereLote = ["lote = ?", "estatus = 'Disponible'"];

  if ($sucursalSel !== '') {
    $whereLote[] = "id_sucursal = ?";
    $typesLote .= 'i';
    $paramsLote[] = (int)$sucursalSel;
  }

  $sqlCountLote = "SELECT COUNT(*) AS total
                   FROM inventario_sims
                   WHERE " . implode(' AND ', $whereLote);
  $stmtCountLote = $conn->prepare($sqlCountLote);
  $stmtCountLote->bind_param($typesLote, ...$paramsLote);
  $stmtCountLote->execute();
  $resCountLote = $stmtCountLote->get_result()->fetch_assoc();
  $totalLoteDisponibles = (int)($resCountLote['total'] ?? 0);
}

// ===== Carrito =====
$carrito = [];
if (count($_SESSION['carrito_retiro'])) {
  $ids = implode(',', array_map('intval', $_SESSION['carrito_retiro']));
  $carrito = $conn->query("
      SELECT id, iccid, dn, operador, caja_id, lote, tipo_plan, id_sucursal
      FROM inventario_sims
      WHERE id IN ($ids)
      ORDER BY fecha_ingreso DESC
  ")->fetch_all(MYSQLI_ASSOC);
}

// ===== Totales visuales del carrito =====
$carritoTotal = count($carrito);
$carritoCajas = [];
$carritoLotes = [];
foreach ($carrito as $c) {
  if (!empty($c['caja_id'])) $carritoCajas[(string)$c['caja_id']] = true;
  if (!empty($c['lote']))    $carritoLotes[(string)$c['lote']] = true;
}
$totalCajasCarrito = count($carritoCajas);
$totalLotesCarrito = count($carritoLotes);

// ===== Historial breve =====
$historial = [];
$sqlHist = "
  SELECT rs.id, rs.folio, rs.tipo_retiro, rs.modalidad, rs.motivo, rs.total_sims, rs.fecha_retiro,
         rs.id_sucursal, rs.id_usuario,
         s.nombre AS sucursal_nombre,
         COALESCE(u.nombre, u.usuario, CONCAT('Usuario #', rs.id_usuario)) AS usuario_nombre
  FROM retiros_sims rs
  LEFT JOIN sucursales s ON s.id = rs.id_sucursal
  LEFT JOIN usuarios u   ON u.id = rs.id_usuario
  ORDER BY rs.fecha_retiro DESC, rs.id DESC
  LIMIT 20
";
$historial = $conn->query($sqlHist)->fetch_all(MYSQLI_ASSOC);

// ===== Flash =====
$flash_ok  = $_SESSION['flash_ok'] ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Retiro de SIMs</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    .mini-kpi {
      border: 1px solid #e9ecef;
      border-radius: 14px;
      padding: 14px;
      background: #fff;
      height: 100%;
    }
    .mini-kpi .num {
      font-size: 1.35rem;
      font-weight: 700;
      line-height: 1.1;
    }
    .card-soft {
      border: 1px solid #edf0f3;
      border-radius: 18px;
      box-shadow: 0 6px 18px rgba(0,0,0,.04);
    }
    .table td, .table th {
      vertical-align: middle;
    }
    .nav-tabs .nav-link {
      border-top-left-radius: 14px;
      border-top-right-radius: 14px;
      font-weight: 600;
    }
    .tab-pane {
      padding-top: 1rem;
    }
    .badge-soft {
      background: #f1f5f9;
      color: #334155;
      border: 1px solid #e2e8f0;
    }
  </style>
</head>
<body>
<div class="container py-3">

  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
      <h3 class="mb-1">Retiro de SIMs</h3>
      <div class="text-muted">Organizado por pestañas para que no se vuelva una serpiente kilométrica.</div>
    </div>
    
  </div>

  <?php if ($flash_ok): ?>
    <div class="alert alert-success"><?= h($flash_ok) ?></div>
  <?php endif; ?>
  <?php if ($flash_err): ?>
    <div class="alert alert-danger"><?= h($flash_err) ?></div>
  <?php endif; ?>

  <div class="row g-3 mb-3">
    <div class="col-md-4">
      <div class="mini-kpi">
        <div class="text-muted small">SIMs en carrito</div>
        <div class="num"><?= (int)$carritoTotal ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="mini-kpi">
        <div class="text-muted small">Cajas detectadas</div>
        <div class="num"><?= (int)$totalCajasCarrito ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="mini-kpi">
        <div class="text-muted small">Lotes detectados</div>
        <div class="num"><?= (int)$totalLotesCarrito ?></div>
      </div>
    </div>
  </div>

  <ul class="nav nav-tabs" id="tabsRetiro" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $tab === 'buscar' ? 'active' : '' ?>"
              id="buscar-tab"
              data-bs-toggle="tab"
              data-bs-target="#tab-buscar"
              type="button"
              role="tab">
        Buscar SIMs
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $tab === 'carrito' ? 'active' : '' ?>"
              id="carrito-tab"
              data-bs-toggle="tab"
              data-bs-target="#tab-carrito"
              type="button"
              role="tab">
        Carrito y confirmación
        <?php if ($carritoTotal > 0): ?>
          <span class="badge rounded-pill text-bg-danger ms-1"><?= (int)$carritoTotal ?></span>
        <?php endif; ?>
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $tab === 'historial' ? 'active' : '' ?>"
              id="historial-tab"
              data-bs-toggle="tab"
              data-bs-target="#tab-historial"
              type="button"
              role="tab">
        Historial
      </button>
    </li>
  </ul>

  <div class="tab-content">
    <!-- TAB BUSCAR -->
    <div class="tab-pane fade <?= $tab === 'buscar' ? 'show active' : '' ?>" id="tab-buscar" role="tabpanel">
      <div class="card card-soft mb-3">
        <div class="card-body">
          <form class="row g-2" method="get" id="formFiltros">
            <input type="hidden" name="tab" value="buscar">

            <div class="col-md-3">
              <label class="form-label">Sucursal</label>
              <select name="sucursal" class="form-select" onchange="document.getElementById('formFiltros').submit()">
                <option value="">Todas</option>
                <?php foreach ($suc as $r): ?>
                  <option value="<?= (int)$r['id']; ?>" <?= ($sucursalSel !== '' && (int)$sucursalSel === (int)$r['id'] ? 'selected' : '') ?>>
                    <?= h($r['nombre']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-2">
              <label class="form-label">ICCID</label>
              <input name="iccid" class="form-control" value="<?= h($q_iccid) ?>" placeholder="Buscar por ICCID">
            </div>

            <div class="col-md-2">
              <label class="form-label">DN</label>
              <input name="dn" class="form-control" value="<?= h($q_dn) ?>" placeholder="Opcional">
            </div>

            <div class="col-md-2">
              <label class="form-label">Caja</label>
              <input name="caja" class="form-control" value="<?= h($q_caja) ?>" placeholder="ID de caja">
            </div>

            <div class="col-md-2">
              <label class="form-label">Lote</label>
              <input name="lote" class="form-control" value="<?= h($q_lote) ?>" placeholder="ID de lote">
            </div>

            <div class="col-md-1 d-flex align-items-end">
              <button class="btn btn-primary w-100">Buscar</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card card-soft mb-3">
        <div class="card-header bg-white"><strong>Agregar ICCID en masa al carrito</strong></div>
        <div class="card-body">
          <form method="post" action="<?= h(baseUrl() . qs(['tab' => 'buscar'])) ?>">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['retiro_token']) ?>">
            <input type="hidden" name="bulk_action" value="bulk_add">

            <label class="form-label">
              Pega aquí los ICCID
              <?php if ($sucursalSel !== ''): ?>
                <span class="badge text-bg-info">Filtrando por sucursal</span>
              <?php endif; ?>
            </label>

            <textarea name="iccid_bulk" class="form-control" rows="4"
              placeholder="Puedes pegar una columna de Excel, separados por comas, espacios o saltos de línea"></textarea>

            <div class="d-flex flex-wrap gap-2 mt-2 align-items-center">
              <button class="btn btn-success">Agregar al carrito</button>
              <div class="text-muted small">Límite por pegada: <strong>500</strong>.</div>
            </div>
          </form>
        </div>
      </div>

      <?php if ($q_caja !== '' && $totalCajaDisponibles !== null): ?>
        <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div>
            Caja <strong><?= h($q_caja) ?></strong>:
            <strong><?= $totalCajaDisponibles ?></strong> SIM(s) disponibles
            <?= $sucursalSel !== '' ? 'en la sucursal seleccionada.' : 'con los filtros actuales.' ?>
          </div>
          <div>
            <a class="btn btn-sm btn-outline-primary<?= $totalCajaDisponibles ? '' : ' disabled'; ?>"
               href="<?= h(baseUrl() . qs(['a' => 'add_caja', 'caja' => $q_caja, 'tab' => 'buscar'])) ?>">
              Agregar caja completa
            </a>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($q_lote !== '' && $totalLoteDisponibles !== null): ?>
        <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2">
          <div>
            Lote <strong><?= h($q_lote) ?></strong>:
            <strong><?= $totalLoteDisponibles ?></strong> SIM(s) disponibles
            <?= $sucursalSel !== '' ? 'en la sucursal seleccionada.' : 'con los filtros actuales.' ?>
          </div>
          <div>
            <a class="btn btn-sm btn-outline-warning<?= $totalLoteDisponibles ? '' : ' disabled'; ?>"
               href="<?= h(baseUrl() . qs(['a' => 'add_lote', 'lote' => $q_lote, 'tab' => 'buscar'])) ?>">
              Agregar lote completo
            </a>
          </div>
        </div>
      <?php endif; ?>

      <div class="card card-soft mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <strong>SIMs disponibles para retiro</strong>
          <span class="text-muted small">Solo se muestran SIMs con estatus Disponible</span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>ICCID</th>
                  <th>DN</th>
                  <th>Operador</th>
                  <th>Caja</th>
                  <th>Lote</th>
                  <th>Tipo plan</th>
                  <th>Sucursal</th>
                  <th>Estatus</th>
                  <th class="text-end">Acción</th>
                </tr>
              </thead>
              <tbody>
              <?php while ($r = $result->fetch_assoc()): ?>
                <?php
                  $enCarrito = in_array((int)$r['id'], $_SESSION['carrito_retiro'], true);
                  $nombreSucursal = $sucMap[(int)$r['id_sucursal']] ?? ('ID ' . $r['id_sucursal']);
                ?>
                <tr>
                  <td><?= h($r['iccid']) ?></td>
                  <td><?= h($r['dn']) ?></td>
                  <td><?= h($r['operador']) ?></td>
                  <td><?= h($r['caja_id']) ?></td>
                  <td><?= h($r['lote']) ?></td>
                  <td><?= h($r['tipo_plan']) ?></td>
                  <td><?= h($nombreSucursal) ?></td>
                  <td><span class="badge text-bg-success"><?= h($r['estatus']) ?></span></td>
                  <td class="text-end">
                    <?php if ($enCarrito): ?>
                      <span class="badge badge-soft">Ya en carrito</span>
                    <?php else: ?>
                      <a class="btn btn-success btn-sm"
                         href="<?= h(baseUrl() . qs(['a' => 'add', 'id' => $r['id'], 'tab' => 'buscar'])) ?>">
                        Agregar
                      </a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- TAB CARRITO -->
    <div class="tab-pane fade <?= $tab === 'carrito' ? 'show active' : '' ?>" id="tab-carrito" role="tabpanel">
      <div class="card card-soft mb-3">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
          <strong>Carrito (<?= count($_SESSION['carrito_retiro']) ?>)</strong>
          <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="<?= h(baseUrl() . qs(['a' => 'vaciar', 'tab' => 'carrito'])) ?>">Vaciar</a>
            <button class="btn btn-sm btn-danger"
                    data-bs-toggle="modal"
                    data-bs-target="#confirmModal"
                    <?= count($_SESSION['carrito_retiro']) ? '' : 'disabled' ?>>
              Confirmar retiro
            </button>
          </div>
        </div>

        <div class="card-body">
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <div class="mini-kpi">
                <div class="text-muted small">Total de SIMs</div>
                <div class="num"><?= (int)$carritoTotal ?></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="mini-kpi">
                <div class="text-muted small">Cajas en carrito</div>
                <div class="num"><?= (int)$totalCajasCarrito ?></div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="mini-kpi">
                <div class="text-muted small">Lotes en carrito</div>
                <div class="num"><?= (int)$totalLotesCarrito ?></div>
              </div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th>ICCID</th>
                  <th>DN</th>
                  <th>Operador</th>
                  <th>Caja</th>
                  <th>Lote</th>
                  <th>Tipo plan</th>
                  <th>Sucursal</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($carrito as $c):
                $nombreSucursalC = $sucMap[(int)$c['id_sucursal']] ?? ('ID ' . $c['id_sucursal']);
              ?>
                <tr>
                  <td><?= h($c['iccid']) ?></td>
                  <td><?= h($c['dn']) ?></td>
                  <td><?= h($c['operador']) ?></td>
                  <td><?= h($c['caja_id']) ?></td>
                  <td><?= h($c['lote']) ?></td>
                  <td><?= h($c['tipo_plan']) ?></td>
                  <td><?= h($nombreSucursalC) ?></td>
                  <td class="text-end">
                    <a class="btn btn-outline-danger btn-sm"
                       href="<?= h(baseUrl() . qs(['a' => 'del', 'id' => $c['id'], 'tab' => 'carrito'])) ?>">
                      Quitar
                    </a>
                  </td>
                </tr>
              <?php endforeach; if (!count($carrito)): ?>
                <tr>
                  <td colspan="8" class="text-center text-muted py-4">Sin elementos en el carrito</td>
                </tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="mt-3 text-muted small">
            Aquí ya solo revisas, limpias el carrito si hace falta y confirmas el retiro. Más ordenadito, menos cardio de scroll.
          </div>
        </div>
      </div>
    </div>

    <!-- TAB HISTORIAL -->
    <div class="tab-pane fade <?= $tab === 'historial' ? 'show active' : '' ?>" id="tab-historial" role="tabpanel">
      <div class="card card-soft mb-3">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
          <strong>Últimos retiros registrados</strong>
          <a href="retiro_sims_historial.php" class="btn btn-sm btn-outline-dark">Ver historial completo</a>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>Folio</th>
                  <th>Fecha</th>
                  <th>Tipo</th>
                  <th>Modalidad</th>
                  <th>Sucursal</th>
                  <th>Usuario</th>
                  <th>Total SIMs</th>
                  <th>Motivo</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($historial): ?>
                  <?php foreach ($historial as $h): ?>
                    <tr>
                      <td><strong><?= h($h['folio']) ?></strong></td>
                      <td><?= h(date('d/m/Y H:i', strtotime((string)$h['fecha_retiro']))) ?></td>
                      <td><?= h($h['tipo_retiro']) ?></td>
                      <td><span class="badge badge-soft"><?= h($h['modalidad']) ?></span></td>
                      <td><?= h($h['sucursal_nombre'] ?? ('ID ' . $h['id_sucursal'])) ?></td>
                      <td><?= h($h['usuario_nombre']) ?></td>
                      <td><?= (int)$h['total_sims'] ?></td>
                      <td><?= h($h['motivo']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="8" class="text-center text-muted py-4">Aún no hay retiros registrados.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Modal Confirmación -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form method="post" action="retiro_sims_confirmar.php?tab=carrito" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirmar retiro de <?= count($carrito) ?> SIM(s)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['retiro_token']) ?>">

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label">Tipo de retiro</label>
            <select name="tipo_retiro" class="form-select" required>
              <option value="">Selecciona...</option>
              <option value="Merma">Merma</option>
              <option value="Venta a distribuidor">Venta a distribuidor</option>
              <option value="Vencimiento">Vencimiento</option>
              <option value="Daño">Daño</option>
              <option value="Baja administrativa">Baja administrativa</option>
              <option value="Otro">Otro</option>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">Motivo del retiro</label>
            <input name="motivo" class="form-control" required minlength="5" placeholder="Describe el motivo general">
          </div>

          <div class="col-12">
            <label class="form-label">Observaciones</label>
            <textarea name="observaciones" class="form-control" rows="3" placeholder="Opcional"></textarea>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>ICCID</th>
                <th>DN</th>
                <th>Operador</th>
                <th>Caja</th>
                <th>Lote</th>
                <th>Sucursal</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($carrito as $c):
              $nombreSucursalC = $sucMap[(int)$c['id_sucursal']] ?? ('ID ' . $c['id_sucursal']);
            ?>
              <tr>
                <td><?= h($c['iccid']) ?></td>
                <td><?= h($c['dn']) ?></td>
                <td><?= h($c['operador']) ?></td>
                <td><?= h($c['caja_id']) ?></td>
                <td><?= h($c['lote']) ?></td>
                <td><?= h($nombreSucursalC) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <p class="text-muted small mb-0">
          Al confirmar, las SIMs se marcarán como <strong>Retirado</strong> y se registrará el movimiento en el historial formal.
        </p>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger">Confirmar retiro</button>
      </div>
    </form>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
(function() {
  const currentTab = <?= json_encode($tab, JSON_UNESCAPED_UNICODE) ?>;
  const map = {
    buscar: 'buscar-tab',
    carrito: 'carrito-tab',
    historial: 'historial-tab'
  };

  const btnId = map[currentTab] || 'buscar-tab';
  const btn = document.getElementById(btnId);
  if (btn) {
    const tab = new bootstrap.Tab(btn);
    tab.show();
  }

  document.querySelectorAll('#tabsRetiro button[data-bs-toggle="tab"]').forEach(btn => {
    btn.addEventListener('shown.bs.tab', function(e) {
      const targetId = e.target.getAttribute('id');
      let nextTab = 'buscar';
      if (targetId === 'carrito-tab') nextTab = 'carrito';
      if (targetId === 'historial-tab') nextTab = 'historial';

      const url = new URL(window.location.href);
      url.searchParams.set('tab', nextTab);
      window.history.replaceState({}, '', url.toString());
    });
  });
})();
</script>
</body>
</html>