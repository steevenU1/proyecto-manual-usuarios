<?php
// inventario_retiros.php — Retiros multi-sucursal con carrito persistente, reversión parcial e IMEI finder
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// ✅ Permitir Admin y Logistica
$ROL = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id_usuario']) || !in_array($ROL, ['Admin', 'Logistica'], true)) {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
if (!function_exists('h')) {
  function h($s)
  {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
  }
}

// ===============================
// 1) Resolver ID de “Luga Eulalia” (tolerante)
// ===============================
$idEulalia = 0;
$nombreEulalia = 'Luga Eulalia';
if ($st = $conn->prepare("SELECT id,nombre FROM sucursales WHERE LOWER(nombre) IN ('luga eulalia','eulalia') LIMIT 1")) {
  $st->execute();
  if ($r = $st->get_result()->fetch_assoc()) {
    $idEulalia = (int)$r['id'];
    $nombreEulalia = $r['nombre'];
  }
  $st->close();
}
if ($idEulalia <= 0) {
  $rs = $conn->query("SELECT id,nombre FROM sucursales WHERE LOWER(nombre) LIKE '%eulalia%' ORDER BY LENGTH(nombre) ASC LIMIT 1");
  if ($rs && ($r = $rs->fetch_assoc())) {
    $idEulalia = (int)$r['id'];
    $nombreEulalia = $r['nombre'];
  }
}
if ($idEulalia <= 0) {
  echo "<div class='container my-4'><div class='alert alert-danger'>No se localizó la sucursal “Luga Eulalia”.</div></div>";
  exit();
}

// ===============================
// 2) Catálogo de sucursales + sucursal seleccionada
// ===============================
$sucursales = [];
$resSuc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre ASC");
while ($row = $resSuc->fetch_assoc()) $sucursales[] = $row;

$idSucursalSel = (int)($_GET['sucursal'] ?? 0);
if ($idSucursalSel <= 0) $idSucursalSel = $idEulalia;
$nomSucursalSel = null;
foreach ($sucursales as $s) {
  if ((int)$s['id'] === $idSucursalSel) {
    $nomSucursalSel = $s['nombre'];
    break;
  }
}
if (!$nomSucursalSel) {
  $idSucursalSel = $idEulalia;
  $nomSucursalSel = $nombreEulalia;
}

// ===============================
// 3) Alerts
// ===============================
$mensaje = $_GET['msg'] ?? '';
$alert   = '';
if ($mensaje === 'ok')        $alert = "<div class='alert alert-success my-3'>✅ Retiro realizado correctamente.</div>";
elseif ($mensaje === 'revok') $alert = "<div class='alert alert-success my-3'>✅ Reversión aplicada.</div>";
elseif ($mensaje === 'err') {
  $err = h($_GET['errdetail'] ?? 'Ocurrió un error.');
  $alert = "<div class='alert alert-danger my-3'>❌ $err</div>";
}

// ===============================
// 4) Inventario disponible (por sucursal seleccionada)
// ===============================
$f_q = trim($_GET['q'] ?? '');
$params = [];
$sql = "
  SELECT inv.id AS id_inventario, inv.id_sucursal, inv.id_producto, inv.estatus,
         p.marca, p.modelo, p.color, p.capacidad, p.imei1, p.imei2, p.tipo_producto, p.codigo_producto
  FROM inventario inv
  INNER JOIN productos p ON p.id = inv.id_producto
  WHERE inv.estatus = 'Disponible' AND inv.id_sucursal = ?
";
$params[] = ['i', $idSucursalSel];
if ($f_q !== '') {
  $sql .= " AND (p.marca LIKE ? OR p.modelo LIKE ? OR p.color LIKE ? OR p.capacidad LIKE ? OR p.imei1 LIKE ? OR p.codigo_producto LIKE ?) ";
  $like = "%$f_q%";
  $params[] = ['s', $like];
  $params[] = ['s', $like];
  $params[] = ['s', $like];
  $params[] = ['s', $like];
  $params[] = ['s', $like];
  $params[] = ['s', $like];
}
$sql .= " ORDER BY p.marca, p.modelo, p.capacidad, p.color, inv.id ASC ";
$types = '';
$binds = [];
foreach ($params as $p) {
  $types .= $p[0];
  $binds[] = $p[1];
}
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$binds);
$stmt->execute();
$itemsDisponibles = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ===============================
// 5) Historial (últimos 200 por sucursal)
// ===============================
$h_motivo = $_GET['h_motivo'] ?? '';
$h_qfolio = trim($_GET['h_folio'] ?? '');
$h_estado = $_GET['h_estado'] ?? '';

$histSql = "
  SELECT r.id, r.folio, r.fecha, r.motivo, r.destino, r.nota,
         r.id_sucursal, s.nombre AS sucursal_nombre, u.nombre AS usuario_nombre,
         r.revertido, r.fecha_reversion, r.nota_reversion,
         COUNT(d.id) AS cantidad
  FROM inventario_retiros r
  LEFT JOIN inventario_retiros_detalle d ON d.retiro_id = r.id
  LEFT JOIN sucursales s ON s.id = r.id_sucursal
  LEFT JOIN usuarios   u ON u.id = r.id_usuario
  WHERE r.id_sucursal = ?
";
$histParams = [['i', $idSucursalSel]];
if ($h_motivo !== '') {
  $histSql .= " AND r.motivo=? ";
  $histParams[] = ['s', $h_motivo];
}
if ($h_qfolio !== '') {
  $histSql .= " AND r.folio LIKE ? ";
  $histParams[] = ['s', "%$h_qfolio%"];
}
if ($h_estado === 'vigente')   $histSql .= " AND r.revertido=0 ";
elseif ($h_estado === 'revertido') $histSql .= " AND r.revertido=1 ";
$histSql .= " GROUP BY r.id ORDER BY r.fecha DESC LIMIT 200";
$types = '';
$binds = [];
foreach ($histParams as $p) {
  $types .= $p[0];
  $binds[] = $p[1];
}
$stmt = $conn->prepare($histSql);
$stmt->bind_param($types, ...$binds);
$stmt->execute();
$historial = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ===============================
// 6) KPIs
// ===============================
$disponibles = count($itemsDisponibles);
$vigentes = 0;
$revertidos = 0;
$totalRetirados = 0;
$ultimo = '';
foreach ($historial as $h) {
  $totalRetirados += (int)$h['cantidad'];
  if ((int)$h['revertido'] === 1) $revertidos++;
  else $vigentes++;
  if ($ultimo === '' && !empty($h['fecha'])) $ultimo = $h['fecha'];
}

// ===============================
// 7) Templates de detalle con checkboxes para reversión parcial
// ===============================
$detailTemplates = [];
foreach ($historial as $h) {
  ob_start();
  $did = (int)$h['id'];
  $isRevertido = (int)$h['revertido'] === 1;
  $qdet = $conn->prepare("
    SELECT d.id_inventario, d.id_producto, d.imei1,
           p.marca, p.modelo, p.capacidad, p.color, p.codigo_producto,
           i.estatus AS est_actual
    FROM inventario_retiros_detalle d
    LEFT JOIN productos p ON p.id = d.id_producto
    LEFT JOIN inventario i ON i.id = d.id_inventario
    WHERE d.retiro_id = ?
    ORDER BY d.id ASC
  ");
  $qdet->bind_param('i', $did);
  $qdet->execute();
  $detallito = $qdet->get_result()->fetch_all(MYSQLI_ASSOC);
  $qdet->close(); ?>
  <div id="tpl-det-<?= (int)$h['id'] ?>" class="d-none">
    <form action="revertir_retiro.php" method="POST" id="revForm-<?= (int)$h['id'] ?>">
      <input type="hidden" name="id_retiro" value="<?= (int)$h['id'] ?>">
      <input type="hidden" name="id_sucursal" value="<?= (int)$idSucursalSel ?>">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr>
              <th style="width:32px;">
                <?php if (!$isRevertido): ?>
                  <input type="checkbox" onclick="document.querySelectorAll('#revForm-<?= (int)$h['id'] ?> input[name=\'items[]\']').forEach(c=>c.checked=this.checked)">
                <?php endif; ?>
              </th>
              <th>ID Inv.</th>
              <th>Marca</th>
              <th>Modelo</th>
              <th>Cap.</th>
              <th>Color</th>
              <th>IMEI</th>
              <th>Código</th>
              <th>Estatus</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($detallito as $d): $canRevert = (!$isRevertido && $d['est_actual'] === 'Retirado'); ?>
              <tr>
                <td>
                  <?php if ($canRevert): ?>
                    <input type="checkbox" name="items[]" value="<?= (int)$d['id_inventario'] ?>" checked>
                  <?php endif; ?>
                </td>
                <td><?= (int)$d['id_inventario'] ?></td>
                <td><?= h($d['marca']) ?></td>
                <td><?= h($d['modelo']) ?></td>
                <td><?= h($d['capacidad']) ?></td>
                <td><?= h($d['color']) ?></td>
                <td class="font-monospace"><?= h($d['imei1']) ?></td>
                <td><?= h($d['codigo_producto']) ?></td>
                <td>
                  <?php if ($d['est_actual'] === 'Retirado'): ?>
                    <span class="badge text-bg-warning">Retirado</span>
                  <?php else: ?>
                    <span class="badge text-bg-success"><?= h($d['est_actual'] ?: 'N/D') ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (!empty($h['nota'])): ?>
          <div class="mt-2"><strong>Nota:</strong> <?= h($h['nota']) ?></div>
        <?php endif; ?>
        <?php if ($isRevertido && !empty($h['nota_reversion'])): ?>
          <div class="mt-2"><strong>Nota de reversión:</strong> <?= h($h['nota_reversion']) ?></div>
        <?php endif; ?>
      </div>

      <?php if (!$isRevertido): ?>
        <div class="d-flex align-items-center gap-2 mt-3">
          <input type="text" name="nota_reversion" class="form-control form-control-sm" placeholder="Nota de reversión (opcional)" style="max-width:320px">
          <button class="btn btn-warning btn-sm" onclick="return confirm('¿Revertir los equipos seleccionados?')">
            <i class="bi bi-arrow-counterclockwise me-1"></i> Revertir seleccionados
          </button>
        </div>
      <?php endif; ?>
    </form>
  </div>
<?php
  $detailTemplates[] = ob_get_clean();
}

// ===============================
// 8) Buscador de IMEI en retiros (en la sucursal seleccionada)
// ===============================
$findImei = trim($_GET['find_imei'] ?? '');
$imeiRows = [];
if ($findImei !== '') {
  $st = $conn->prepare("
    SELECT r.id AS retiro_id, r.folio, r.fecha, r.id_sucursal, s.nombre AS sucursal,
           d.id_inventario, d.imei1,
           i.estatus AS est_actual
    FROM inventario_retiros r
    INNER JOIN inventario_retiros_detalle d ON d.retiro_id = r.id
    LEFT JOIN sucursales s ON s.id = r.id_sucursal
    LEFT JOIN inventario i ON i.id = d.id_inventario
    WHERE r.id_sucursal = ? AND d.imei1 LIKE ?
    ORDER BY r.fecha DESC
    LIMIT 50
  ");
  $likeI = "%$findImei%";
  $st->bind_param("is", $idSucursalSel, $likeI);
  $st->execute();
  $imeiRows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <title>Retiros de Inventario — <?= h($nomSucursalSel) ?></title>
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
    body {
      background: #f6f7fb;
    }

    .page-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin: 18px auto 8px;
      padding: 6px 4px;
    }

    .page-title {
      font-weight: 700;
      letter-spacing: .2px;
      margin: 0;
    }

    .role-chip {
      font-size: .8rem;
      padding: .2rem .55rem;
      border-radius: 999px;
      background: #eef2ff;
      color: #3743a5;
      border: 1px solid #d9e0ff;
    }

    .filters-card {
      border: 1px solid #e9ecf1;
      box-shadow: 0 1px 6px rgba(16, 24, 40, .06);
      border-radius: 16px;
    }

    .kpi {
      border: 1px solid #e9ecf1;
      border-radius: 16px;
      background: #fff;
      box-shadow: 0 2px 8px rgba(16, 24, 40, .06);
      padding: 16px;
    }

    .kpi h6 {
      margin: 0;
      font-size: .9rem;
      color: #6b7280;
    }

    .kpi .metric {
      font-weight: 800;
      font-size: 1.4rem;
      margin-top: 4px;
    }

    .badge-soft {
      border: 1px solid transparent;
    }

    .badge-soft.success {
      background: #e9f9ee;
      color: #0b7a3a;
      border-color: #b9ebc9;
    }

    .badge-soft.warning {
      background: #fff6e6;
      color: #955f00;
      border-color: #ffe2ad;
    }

    .badge-soft.secondary {
      background: #f1f5f9;
      color: #0f172a;
      border-color: #e2e8f0;
    }

    .chip {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 2px 10px;
      border-radius: 999px;
      background: #f1f5f9;
      color: #0f172a;
      font-size: .8rem;
      border: 1px solid #e2e8f0;
    }

    .status-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      display: inline-block;
    }

    .dot-green {
      background: #16a34a;
    }

    .dot-gray {
      background: #94a3b8;
    }

    .copy-btn {
      border: 0;
      background: transparent;
      cursor: pointer;
    }

    .copy-btn:hover {
      opacity: .85;
    }

    .sel-panel {
      display: none;
    }

    /* ===== Carrito (FAB + Offcanvas) restilizado ===== */
    .fab-cart {
      position: fixed;
      right: 16px;
      bottom: 18px;
      z-index: 1035;
      border-radius: 999px;
      box-shadow: 0 12px 30px rgba(2, 6, 23, .25);
      padding-left: 16px;
      padding-right: 16px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .fab-cart i {
      font-size: 1.2rem;
    }

    .fab-badge {
      position: absolute;
      top: -6px;
      right: -6px;
      font-size: .75rem;
    }

    .offcanvas.custom-cart .offcanvas-header {
      background: linear-gradient(135deg, #111827, #1f2937);
      color: #e5e7eb;
      border-bottom: 1px solid rgba(255, 255, 255, .1);
    }

    .offcanvas.custom-cart .offcanvas-title {
      font-weight: 700;
    }

    .offcanvas.custom-cart .table thead {
      position: sticky;
      top: 0;
      z-index: 1;
      background: #f8fafc;
    }

    .cart-row:hover {
      background: #fff7ed;
    }

    .badge-ghost {
      background: #f1f5f9;
      border: 1px solid #e2e8f0;
      color: #0f172a;
      border-radius: 999px;
      padding: .2rem .6rem;
    }

    .btn-ghost {
      background: #fff;
      border: 1px solid #e5e7eb;
    }
  </style>
</head>

<body data-msg="<?= h($mensaje) ?>">
  <div class="container-fluid px-3 px-lg-4">

    <div class="page-head">
      <div>
        <h2 class="page-title">📤 Retiros de Inventario — <?= h($nomSucursalSel) ?></h2>
        <div class="mt-1"><span class="role-chip"><?= h($ROL) ?></span></div>
      </div>
      <div class="d-flex gap-2 align-items-center">
        <form method="GET" class="d-flex align-items-center gap-2">
          <?php foreach (['q', 'h_motivo', 'h_estado', 'h_folio', 'find_imei'] as $k) if (isset($_GET[$k])) echo '<input type="hidden" name="' . h($k) . '" value="' . h($_GET[$k]) . '">'; ?>
          <label class="me-1 small text-muted">Sucursal:</label>
          <select name="sucursal" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($sucursales as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id'] === $idSucursalSel) ? 'selected' : '' ?>><?= h($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <a href="exportar_retiros_eulalia.php?<?= http_build_query(array_merge($_GET, ['sucursal' => $idSucursalSel])) ?>" class="btn btn-success btn-sm rounded-pill">
          <i class="bi bi-file-earmark-spreadsheet me-1"></i> Exportar historial
        </a>
        <a href="inventario_retiros.php?<?= http_build_query(['sucursal' => $idSucursalSel]) ?>" class="btn btn-light btn-sm rounded-pill border"><i class="bi bi-arrow-counterclockwise me-1"></i> Limpiar</a>
      </div>
    </div>

    <?= $alert ?>

    <!-- KPIs -->
    <div class="row g-3 mb-3">
      <div class="col-6 col-md-4 col-lg-2">
        <div class="kpi">
          <h6>Disponibles</h6>
          <div class="metric"><span class="badge badge-soft success"><?= number_format($disponibles) ?></span></div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <div class="kpi">
          <h6>Retiros vigentes</h6>
          <div class="metric"><span class="badge badge-soft warning"><?= number_format($vigentes) ?></span></div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-lg-2">
        <div class="kpi">
          <h6>Retiros revertidos</h6>
          <div class="metric"><span class="badge badge-soft secondary"><?= number_format($revertidos) ?></span></div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-lg-3">
        <div class="kpi">
          <h6>Último retiro</h6>
          <div class="metric"><?= $ultimo ? h($ultimo) : '—' ?></div>
        </div>
      </div>
      <div class="col-6 col-md-4 col-lg-3">
        <div class="kpi">
          <h6>Total equipos retirados</h6>
          <div class="metric"><?= number_format($totalRetirados) ?></div>
        </div>
      </div>
    </div>

    <!-- Forms auxiliares -->
    <form id="searchForm" method="GET"><input type="hidden" name="sucursal" value="<?= (int)$idSucursalSel ?>"></form>

    <!-- ===== Nuevo retiro ===== -->
    <div class="card mb-4 filters-card">
      <div class="card-header bg-white fw-semibold">Nuevo retiro</div>
      <div class="card-body">
        <form id="formRetiro" action="procesar_retiro.php" method="POST">
          <input type="hidden" name="id_sucursal" value="<?= (int)$idSucursalSel ?>">
          <div id="selHidden"></div> <!-- aquí inyectamos inputs hidden con todo el carrito -->

          <div class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Sucursal</label>
              <input type="text" class="form-control" value="<?= h($nomSucursalSel) ?>" readonly>
            </div>
            <div class="col-md-3">
              <label class="form-label">Motivo</label>
              <select id="motivo" name="motivo" class="form-select" required>
                <option value="">— Selecciona —</option>
                <?php foreach (['Venta a distribuidor', 'Venta', 'Garantía', 'Merma', 'Utilitario', 'Robo', 'Otro'] as $m): ?>
                  <option value="<?= h($m) ?>" <?= $h_motivo === $m ? 'selected' : '' ?>><?= h($m) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Destino (opcional)</label>
              <input id="destino" type="text" name="destino" class="form-control" placeholder="Ej. Dist. López / Taller">
            </div>
            <div class="col-md-3">
              <label class="form-label">Nota (opcional)</label>
              <input id="nota" type="text" name="nota" class="form-control" maxlength="200">
            </div>
          </div>

          <hr>

          <!-- Panel selección persistente -->
          <div id="panelSel" class="alert alert-secondary sel-panel d-flex justify-content-between align-items-center">
            <div>
              <strong>Seleccionados:</strong> <span id="selCount">0</span>
              <span id="selExtra" class="text-muted small"></span>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" id="btnVerSel" class="btn btn-outline-secondary btn-sm">Ver seleccionados</button>
              <button type="button" id="btnClearSel" class="btn btn-outline-danger btn-sm">Vaciar carrito</button>
              <button type="button" id="btnClearAll" class="btn btn-outline-danger btn-sm">Vaciar TODOS</button>
            </div>
          </div>

          <!-- Búsqueda -->
          <div class="row g-2 align-items-end">
            <div class="col-md-8">
              <label class="form-label">Búsqueda</label>
              <div class="d-flex gap-2">
                <input id="qsearch" type="text" name="q" form="searchForm" class="form-control"
                  value="<?= h($f_q) ?>" placeholder="Marca, modelo, color, IMEI, código…">
                <button type="submit" class="btn btn-outline-secondary" form="searchForm"><i class="bi bi-search me-1"></i>Buscar</button>
              </div>
            </div>
            <div class="col-md-4 text-end">
              <button type="button" id="btnResumen" class="btn btn-danger rounded-pill">
                <i class="bi bi-box-arrow-up me-1"></i> Retirar seleccionados
              </button>
            </div>
          </div>

          <div class="table-responsive mt-3" style="max-height: 55vh; overflow:auto;">
            <table class="table table-sm table-hover align-middle" id="tablaInventario">
              <thead class="table-light">
                <tr>
                  <th style="width:32px;"><input type="checkbox" id="chkAll"></th>
                  <th>Marca</th>
                  <th>Modelo</th>
                  <th>Cap.</th>
                  <th>Color</th>
                  <th>IMEI</th>
                  <th>Código</th>
                  <th>Tipo</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($itemsDisponibles)): ?>
                  <tr>
                    <td colspan="8" class="text-center text-muted py-4">Sin resultados</td>
                  </tr>
                  <?php else: foreach ($itemsDisponibles as $it): ?>
                    <tr>
                      <td><input type="checkbox" class="chk-item" data-id="<?= (int)$it['id_inventario'] ?>" name="items[]" value="<?= (int)$it['id_inventario'] ?>"></td>
                      <td><?= h($it['marca']) ?></td>
                      <td><?= h($it['modelo']) ?></td>
                      <td><?= h($it['capacidad']) ?></td>
                      <td><?= h($it['color']) ?></td>
                      <td>
                        <span><?= h($it['imei1']) ?></span>
                        <?php if (!empty($it['imei1'])): ?>
                          <button class="copy-btn ms-1" type="button" title="Copiar IMEI" onclick="copyText('<?= h($it['imei1']) ?>')"><i class="bi bi-clipboard"></i></button>
                        <?php endif; ?>
                      </td>
                      <td><?= h($it['codigo_producto']) ?></td>
                      <td><?= h($it['tipo_producto']) ?></td>
                    </tr>
                <?php endforeach;
                endif; ?>
              </tbody>
            </table>
          </div>
        </form>
      </div>
    </div>

    <!-- ===== Buscador de IMEI en retiros (sucursal actual) ===== -->
    <div class="card mb-4">
      <div class="card-header bg-white fw-semibold">Buscar IMEI en retiros (<?= h($nomSucursalSel) ?>)</div>
      <div class="card-body">
        <form method="GET" class="row g-2">
          <input type="hidden" name="sucursal" value="<?= (int)$idSucursalSel ?>">
          <div class="col-md-8">
            <input class="form-control" name="find_imei" value="<?= h($findImei) ?>" placeholder="Ej. 3520..., o parte del IMEI">
          </div>
          <div class="col-md-4">
            <button class="btn btn-outline-primary">Buscar IMEI</button>
          </div>
        </form>

        <?php if ($findImei !== ''): ?>
          <div class="table-responsive mt-3">
            <table class="table table-sm align-middle">
              <thead class="table-light">
                <tr>
                  <th>Folio</th>
                  <th>Fecha</th>
                  <th>ID Inv</th>
                  <th>IMEI</th>
                  <th>Estatus actual</th>
                  <th>Acción</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($imeiRows)): ?>
                  <tr>
                    <td colspan="6" class="text-center text-muted">Sin coincidencias</td>
                  </tr>
                  <?php else: foreach ($imeiRows as $r): ?>
                    <tr>
                      <td><span class="badge bg-dark"><?= h($r['folio']) ?></span></td>
                      <td><?= h($r['fecha']) ?></td>
                      <td><?= (int)$r['id_inventario'] ?></td>
                      <td class="font-monospace"><?= h($r['imei1']) ?></td>
                      <td>
                        <?php if ($r['est_actual'] === 'Retirado'): ?>
                          <span class="badge text-bg-warning">Retirado</span>
                        <?php else: ?>
                          <span class="badge text-bg-success"><?= h($r['est_actual'] ?: 'N/D') ?></span>
                        <?php endif; ?>
                      </td>
                      <td class="d-flex gap-2">
                        <button class="btn btn-outline-secondary btn-sm btn-detalle" data-id="<?= (int)$r['retiro_id'] ?>" data-folio="<?= h($r['folio']) ?>" type="button">Abrir detalle</button>
                        <?php if ($r['est_actual'] === 'Retirado'): ?>
                          <form action="revertir_retiro.php" method="POST" onsubmit="return confirm('¿Revertir este equipo?');">
                            <input type="hidden" name="id_retiro" value="<?= (int)$r['retiro_id'] ?>">
                            <input type="hidden" name="id_sucursal" value="<?= (int)$idSucursalSel ?>">
                            <input type="hidden" name="items[]" value="<?= (int)$r['id_inventario'] ?>">
                            <button class="btn btn-warning btn-sm">Revertir este</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                <?php endforeach;
                endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ===== Historial ===== -->
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center bg-white">
        <h6 class="m-0">Historial de retiros (últimos 200) — <?= h($nomSucursalSel) ?></h6>
        <button class="btn btn-outline-secondary btn-sm rounded-pill" data-bs-toggle="collapse" data-bs-target="#filtrosHist"><i class="bi bi-funnel me-1"></i>Filtros</button>
      </div>

      <div id="filtrosHist" class="collapse px-2 pt-2">
        <form method="GET" class="row g-2 mb-3">
          <input type="hidden" name="sucursal" value="<?= (int)$idSucursalSel ?>">
          <div class="col-md-3">
            <label class="form-label">Motivo</label>
            <select name="h_motivo" class="form-select" onchange="this.form.submit()">
              <option value="">— Todos —</option>
              <?php foreach (['Venta a distribuidor', 'Garantía', 'Merma', 'Utilitario', 'Otro'] as $m): ?>
                <option value="<?= h($m) ?>" <?= $h_motivo === $m ? 'selected' : '' ?>><?= h($m) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Estado</label>
            <select name="h_estado" class="form-select" onchange="this.form.submit()">
              <option value="" <?= $h_estado === '' ? 'selected' : '' ?>>— Todos —</option>
              <option value="vigente" <?= $h_estado === 'vigente' ? 'selected' : '' ?>>Vigente</option>
              <option value="revertido" <?= $h_estado === 'revertido' ? 'selected' : '' ?>>Revertido</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Folio</label>
            <input type="text" name="h_folio" class="form-control" value="<?= h($h_qfolio) ?>" placeholder="Buscar folio…">
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <button class="btn btn-outline-secondary w-100">Aplicar</button>
          </div>
        </form>
      </div>

      <div class="px-2 pb-2">
        <table id="tablaHistorial" class="table table-striped table-hover align-middle nowrap" style="width:100%;">
          <thead class="table-light">
            <tr>
              <th>Folio</th>
              <th>Fecha</th>
              <th>Usuario</th>
              <th>Motivo</th>
              <th>Destino</th>
              <th>Cantidad</th>
              <th>Estado</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($historial)): foreach ($historial as $h): ?>
                <tr>
                  <td><span class="badge bg-dark"><?= h($h['folio']) ?></span></td>
                  <td><?= h($h['fecha']) ?></td>
                  <td><?= h($h['usuario_nombre'] ?? 'N/D') ?></td>
                  <td><?= h($h['motivo']) ?></td>
                  <td><?= h($h['destino'] ?? '') ?></td>
                  <td><strong><?= (int)$h['cantidad'] ?></strong></td>
                  <td>
                    <?php if ((int)$h['revertido'] === 1): ?>
                      <span class="chip"><span class="status-dot dot-gray"></span>Revertido</span><br>
                      <small class="text-muted"><?= h($h['fecha_reversion']) ?></small>
                    <?php else: ?>
                      <span class="chip"><span class="status-dot dot-green"></span>Vigente</span>
                    <?php endif; ?>
                  </td>
                  <td class="d-flex gap-2">
                    <button class="btn btn-outline-primary btn-sm btn-detalle" type="button"
                      data-id="<?= (int)$h['id'] ?>" data-folio="<?= h($h['folio']) ?>">Detalle</button>

                    <?php if ((int)$h['revertido'] === 0): ?>
                      <form action="revertir_retiro.php" method="POST" onsubmit="return confirmarReversionTotal();" class="d-flex gap-1">
                        <input type="hidden" name="id_retiro" value="<?= (int)$h['id'] ?>">
                        <input type="hidden" name="id_sucursal" value="<?= (int)$idSucursalSel ?>">
                        <button class="btn btn-warning btn-sm">Revertir TODO</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
            <?php endforeach;
            endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Templates de detalle -->
    <?php foreach ($detailTemplates as $tpl) echo $tpl; ?>

  </div>

  <!-- ===== FAB Carrito (contador + acceso rápido) ===== -->
  <button type="button" class="btn btn-primary fab-cart" id="openCart">
    <i class="bi bi-cart-fill"></i>
    <span class="badge text-bg-danger fab-badge" id="fabCount" style="display:none">0</span>
  </button>

  <!-- ===== Offcanvas Carrito ===== -->
  <div class="offcanvas offcanvas-end custom-cart" tabindex="-1" id="carritoOffcanvas" aria-labelledby="carritoLabel">
    <div class="offcanvas-header">
      <h5 class="offcanvas-title" id="carritoLabel">Carrito de retiros — <?= h($nomSucursalSel) ?></h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column">
      <div class="mb-2 d-flex flex-wrap gap-2">
        <span class="badge-ghost">Sucursal: <?= h($nomSucursalSel) ?></span>
        <button class="btn btn-ghost btn-sm" id="ocVaciar"><i class="bi bi-trash3 me-1"></i>Vaciar carrito</button>
        <button class="btn btn-ghost btn-sm" id="ocVaciarTodos"><i class="bi bi-trash3-fill me-1"></i>Vaciar TODOS</button>
      </div>
      <div class="table-responsive" style="max-height:50vh;overflow:auto;border:1px solid #e5e7eb;border-radius:12px;">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th style="width:48px">#</th>
              <th>ID</th>
              <th>Marca</th>
              <th>Modelo</th>
              <th>Cap.</th>
              <th>Color</th>
              <th>IMEI</th>
              <th style="width:56px;"></th>
            </tr>
          </thead>
          <tbody id="ocBody">
            <tr>
              <td colspan="8" class="text-center text-muted">Sin seleccionados</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="mt-3 d-grid">
        <button class="btn btn-danger" id="ocRetirar"><i class="bi bi-box-arrow-up me-1"></i> Retirar seleccionados</button>
      </div>
    </div>
  </div>

  <!-- ====== Modales ====== -->
  <div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Confirmar retiro de equipos</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <strong>Sucursal:</strong> <?= h($nomSucursalSel) ?><br>
            <strong>Motivo:</strong> <span id="resMotivo"></span><br>
            <strong>Destino:</strong> <span id="resDestino"></span><br>
            <strong>Nota:</strong> <span id="resNota"></span>
          </div>
          <div class="alert alert-warning py-2">Se retirarán <strong id="resCantidad">0</strong> equipos.</div>
          <div class="table-responsive" style="max-height: 50vh; overflow:auto;">
            <table class="table table-sm table-striped align-middle">
              <thead>
                <tr>
                  <th>#</th>
                  <th>ID</th>
                  <th>Marca</th>
                  <th>Modelo</th>
                  <th>Cap.</th>
                  <th>Color</th>
                  <th>IMEI</th>
                </tr>
              </thead>
              <tbody id="resumenBody"></tbody>
            </table>
          </div>
          <small class="text-muted">El carrito conserva equipos que no aparezcan en esta búsqueda.</small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" id="btnConfirmarEnviar" class="btn btn-danger">Confirmar y retirar</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="detalleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Detalle del retiro <span id="detFolio" class="text-muted"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="detalleBody"></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button></div>
      </div>
    </div>
  </div>

  <!-- JS -->
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <!-- Si tu navbar NO incluye Bootstrap Bundle, descomenta la línea siguiente -->
  <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

  <!-- DataTables -->
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.1/js/dataTables.responsive.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.1/js/responsive.bootstrap5.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
  <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

  <script>
    // === utils básicos
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

    function confirmarReversionTotal() {
      return confirm("¿Revertir TODO el retiro?");
    }

    // === DataTable hist
    $(function() {
      $('#tablaHistorial').DataTable({
        pageLength: 25,
        order: [
          [1, 'desc']
        ],
        fixedHeader: true,
        responsive: true,
        language: {
          url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/es-ES.json',
          emptyTable: 'Sin retiros registrados'
        },
        dom: "<'row align-items-center mb-2'<'col-sm-12 col-md-6'B><'col-sm-12 col-md-6'f>>tr<'row mt-2'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        buttons: [{
            extend: 'csvHtml5',
            className: 'btn btn-light btn-sm rounded-pill border',
            text: '<i class=\"bi bi-filetype-csv me-1\"></i>CSV'
          },
          {
            extend: 'excelHtml5',
            className: 'btn btn-light btn-sm rounded-pill border',
            text: '<i class=\"bi bi-file-earmark-excel me-1\"></i>Excel'
          },
          {
            extend: 'colvis',
            className: 'btn btn-light btn-sm rounded-pill border',
            text: '<i class=\"bi bi-view-list me-1\"></i>Columnas'
          }
        ],
        columnDefs: [{
          targets: [0, 1, 5, 6, 7],
          className: 'text-nowrap'
        }]
      });
    });

    // === Modal Detalle
    const detalleModal = new bootstrap.Modal(document.getElementById('detalleModal'));
    document.addEventListener('click', (e) => {
      const btn = e.target.closest('.btn-detalle');
      if (!btn) return;
      const id = btn.getAttribute('data-id');
      const folio = btn.getAttribute('data-folio') || '';
      const tpl = document.getElementById('tpl-det-' + id);
      if (tpl) {
        document.getElementById('detFolio').textContent = folio ? `(${folio})` : '';
        document.getElementById('detalleBody').innerHTML = tpl.innerHTML;
        detalleModal.show();
      }
    });

    // === Carrito persistente enriquecido por sucursal (localStorage)
    (function() {
      const sucursalId = <?= (int)$idSucursalSel ?>;
      const storeKey = `retiros:sel:${sucursalId}`; // guarda { items:[{id,marca,modelo,cap,color,imei}], updated_at:epoch }
      const ONE_DAY = 24 * 60 * 60 * 1000;

      // Fallback por si localStorage truena
      let memoryState = {
        items: [],
        updated_at: Date.now()
      };

      function canUseLS() {
        try {
          localStorage.setItem('__t', '1');
          localStorage.removeItem('__t');
          return true;
        } catch {
          return false;
        }
      }
      const useLS = canUseLS();

      function loadState() {
        try {
          if (!useLS) return memoryState;
          const raw = localStorage.getItem(storeKey);
          if (!raw) return {
            items: [],
            updated_at: Date.now()
          };
          let data = JSON.parse(raw);
          // Migración desde arreglo simple de IDs
          if (Array.isArray(data)) data = {
            items: data.map(id => ({
              id: Number(id)
            })),
            updated_at: Date.now()
          };
          if (!data.items) data.items = [];
          if (!data.updated_at) data.updated_at = Date.now();
          if (Date.now() - data.updated_at > ONE_DAY) {
            localStorage.removeItem(storeKey);
            return {
              items: [],
              updated_at: Date.now()
            };
          }
          return data;
        } catch {
          return {
            items: [],
            updated_at: Date.now()
          };
        }
      }

      function saveState(state) {
        state.updated_at = Date.now();
        if (useLS) localStorage.setItem(storeKey, JSON.stringify(state));
        else memoryState = state;
        updatePanel();
        renderOffcanvas();
        updateFab();
      }

      function clearState() {
        if (useLS) localStorage.removeItem(storeKey);
        else memoryState = {
          items: [],
          updated_at: Date.now()
        };
        updatePanel();
        renderOffcanvas();
        updateFab();
        document.querySelectorAll('.chk-item').forEach(chk => {
          chk.checked = false;
        });
      }

      function clearAllCarts() {
        if (useLS) Object.keys(localStorage).forEach(k => {
          if (k.startsWith('retiros:sel:')) localStorage.removeItem(k);
        });
        memoryState = {
          items: [],
          updated_at: Date.now()
        };
        clearState();
      }

      function getItemsSet() {
        return new Set(loadState().items.map(x => Number(x.id)));
      }

      // refs UI
      const chkAll = document.getElementById('chkAll');
      const panelSel = document.getElementById('panelSel');
      const selCount = document.getElementById('selCount');
      const selExtra = document.getElementById('selExtra');
      const btnVerSel = document.getElementById('btnVerSel');
      const btnClearSel = document.getElementById('btnClearSel');
      const btnClearAll = document.getElementById('btnClearAll');
      const formRetiro = document.getElementById('formRetiro');

      // FAB / Offcanvas
      const carritoCanvas = new bootstrap.Offcanvas(document.getElementById('carritoOffcanvas'));
      const openCartBtn = document.getElementById('openCart');
      const ocBody = document.getElementById('ocBody');
      const ocVaciar = document.getElementById('ocVaciar');
      const ocVaciarTodos = document.getElementById('ocVaciarTodos');
      const ocRetirar = document.getElementById('ocRetirar');
      const fabCount = document.getElementById('fabCount');

      openCartBtn?.addEventListener('click', () => carritoCanvas.show());
      ocVaciar?.addEventListener('click', () => {
        if (confirm('¿Vaciar carrito de esta sucursal?')) clearState();
      });
      ocVaciarTodos?.addEventListener('click', () => {
        if (confirm('¿Vaciar carritos de TODAS las sucursales?')) clearAllCarts();
      });
      ocRetirar?.addEventListener('click', () => document.getElementById('btnConfirmarEnviar')?.click());

      // helpers
      function rowToItem(chk) {
        const tr = chk.closest('tr');
        const tds = tr ? tr.querySelectorAll('td') : [];
        return {
          id: Number(chk.dataset.id),
          marca: tds[1]?.textContent?.trim() || '',
          modelo: tds[2]?.textContent?.trim() || '',
          cap: tds[3]?.textContent?.trim() || '',
          color: tds[4]?.textContent?.trim() || '',
          imei: tds[5]?.querySelector('span')?.textContent?.trim() || ''
        };
      }

      function syncChecksFromState() {
        const set = getItemsSet();
        document.querySelectorAll('.chk-item').forEach(chk => {
          chk.checked = set.has(Number(chk.dataset.id));
        });
        updatePanel();
      }

      function updatePanel() {
        const state = loadState();
        const visibles = Array.from(document.querySelectorAll('.chk-item'));
        const visibleSelected = visibles.filter(c => c.checked).length;
        const total = state.items.length;
        if (selCount) selCount.textContent = total;
        if (selExtra) selExtra.textContent = (total > visibleSelected) ? `(+${total-visibleSelected} fuera de este listado)` : '';
        if (panelSel) panelSel.style.display = total > 0 ? 'flex' : 'none';
        const visiblesCount = visibles.length;
        if (chkAll) {
          chkAll.checked = (visiblesCount > 0 && visibleSelected === visiblesCount);
          chkAll.indeterminate = (visibleSelected > 0 && visibleSelected < visiblesCount);
        }
        updateFab();
      }

      function updateFab() {
        const n = loadState().items.length;
        if (fabCount) {
          if (n > 0) {
            fabCount.style.display = 'inline-block';
            fabCount.textContent = n;
          } else {
            fabCount.style.display = 'none';
          }
        }
      }

      function renderOffcanvas() {
        if (!ocBody) return;
        const items = loadState().items;
        ocBody.innerHTML = '';
        if (items.length === 0) {
          ocBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Sin seleccionados</td></tr>';
          return;
        }
        let i = 0;
        items.forEach(it => {
          const tr = document.createElement('tr');
          tr.className = 'cart-row';
          tr.innerHTML = `
          <td>${++i}</td>
          <td>${it.id}</td>
          <td>${it.marca||''}</td>
          <td>${it.modelo||''}</td>
          <td>${it.cap||''}</td>
          <td>${it.color||''}</td>
          <td class="font-monospace">${it.imei||''}</td>
          <td class="text-end">
            <button class="btn btn-outline-danger btn-sm" data-remove="${it.id}" title="Quitar"><i class="bi bi-x-lg"></i></button>
          </td>`;
          ocBody.appendChild(tr);
        });
      }
      ocBody?.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-remove]');
        if (!btn) return;
        const id = Number(btn.getAttribute('data-remove'));
        const st = loadState();
        st.items = st.items.filter(x => Number(x.id) !== id);
        saveState(st);
        const chk = document.querySelector(`.chk-item[data-id="${id}"]`);
        if (chk) chk.checked = false;
      });

      // Delegación para .chk-item
      document.addEventListener('change', (e) => {
        const chk = e.target.closest?.('.chk-item');
        if (!chk) return;
        const st = loadState();
        const id = Number(chk.dataset.id);
        if (chk.checked) {
          if (!st.items.some(x => Number(x.id) === id)) st.items.push(rowToItem(chk));
        } else {
          st.items = st.items.filter(x => Number(x.id) !== id);
        }
        saveState(st);
      });

      // Master checkbox
      chkAll?.addEventListener('change', e => {
        const st = loadState();
        document.querySelectorAll('.chk-item').forEach(chk => {
          const id = Number(chk.dataset.id);
          if (e.target.checked) {
            if (!st.items.some(x => Number(x.id) === id)) st.items.push(rowToItem(chk));
            chk.checked = true;
          } else {
            st.items = st.items.filter(x => Number(x.id) !== id);
            chk.checked = false;
          }
        });
        saveState(st);
      });

      // Panel superior
      btnVerSel?.addEventListener('click', () => syncChecksFromState());
      btnClearSel?.addEventListener('click', () => {
        if (!confirm('¿Vaciar la selección de esta sucursal?')) return;
        clearState();
      });
      btnClearAll?.addEventListener('click', () => {
        if (!confirm('¿Vaciar los carritos de TODAS las sucursales?')) return;
        clearAllCarts();
      });

      // Resumen (usa carrito)
      const confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
      document.getElementById('btnResumen')?.addEventListener('click', () => {
        const st = loadState();
        if (st.items.length === 0) {
          alert('No hay equipos seleccionados.');
          return;
        }
        document.getElementById('resMotivo').textContent = document.getElementById('motivo')?.value || '—';
        document.getElementById('resDestino').textContent = document.getElementById('destino')?.value || '—';
        document.getElementById('resNota').textContent = document.getElementById('nota')?.value || '—';
        document.getElementById('resCantidad').textContent = st.items.length;

        const tbody = document.getElementById('resumenBody');
        tbody.innerHTML = '';
        let idx = 0;
        st.items.forEach(it => {
          const row = document.createElement('tr');
          row.innerHTML = `<td>${++idx}</td><td>${it.id}</td><td>${it.marca||''}</td><td>${it.modelo||''}</td>
                         <td>${it.cap||''}</td><td>${it.color||''}</td><td class="font-monospace">${it.imei||''}</td>`;
          tbody.appendChild(row);
        });
        confirmModal.show();
      });

      // Submit: inyecta TODOS los IDs del carrito como inputs hidden
      document.getElementById('btnConfirmarEnviar')?.addEventListener('click', () => {
        const st = loadState();
        if (st.items.length === 0) {
          alert('No hay equipos seleccionados.');
          return;
        }
        const cont = document.getElementById('selHidden');
        cont.innerHTML = '';
        st.items.forEach(it => {
          const inp = document.createElement('input');
          inp.type = 'hidden';
          inp.name = 'items[]';
          inp.value = it.id;
          cont.appendChild(inp);
        });
        formRetiro?.submit();
      });

      // Búsqueda con Enter sin disparar el form de retiro
      document.getElementById('qsearch')?.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
          e.preventDefault();
          document.getElementById('searchForm').submit();
        }
      });

      // 🔥 Vaciar carrito automático al regresar con ?msg=ok (y opcionalmente ?msg=revok)
      (function autoClearOnSuccess() {
        const msg = (document.body.getAttribute('data-msg') || '').trim().toLowerCase();
        if (msg === 'ok' /* || msg === 'revok' */) { // ← descomenta 'revok' si también quieres limpiar tras reversión total
          clearState();
          // Limpia los parámetros del URL para que no repita la limpieza al recargar
          try {
            const url = new URL(window.location.href);
            url.searchParams.delete('msg');
            url.searchParams.delete('err');
            url.searchParams.delete('errdetail');
            history.replaceState(null, '', url);
          } catch (_) { /* noop */ }
        }
      })();

      // ===== Arranque
      syncChecksFromState();
      renderOffcanvas();
      updateFab();
    })();
  </script>
</body>

</html>
