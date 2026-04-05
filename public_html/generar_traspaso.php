<?php
// generar_traspaso_eulalia.php — Traspaso desde Almacén (Eulalia)
// Soporta: Equipos por IMEI (estatus→En tránsito) y Accesorios por cantidad (descuenta cantidad)
// Incluye: Carrito lateral, modal de confirmación previo al envío y acuse con accesorios + overlay de “cargando acuse”.

session_start();
if (!isset($_SESSION['id_usuario']) || !in_array(($_SESSION['rol'] ?? ''), ['Admin','Gerente','Logistica'], true)) {
  header("Location: 403.php"); exit();
}

require_once __DIR__ . '/db.php';

// -------------------- Helpers --------------------
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function table_exists(mysqli $c,$t){ $t=$c->real_escape_string($t); $r=$c->query("SHOW TABLES LIKE '{$t}'"); return $r && $r->num_rows>0; }
function col_exists(mysqli $c,$t,$col){
  $t=$c->real_escape_string($t); $col=$c->real_escape_string($col);
  $r=$c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'"); return $r && $r->num_rows>0;
}

// ¿Tenemos productos.tipo_producto?
$hasTipoProd = col_exists($conn, 'productos', 'tipo_producto');

// -------------------- Auto-migración: detalle accesorios --------------------
$conn->query("
  CREATE TABLE IF NOT EXISTS detalle_traspaso_acc (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_traspaso INT NOT NULL,
    id_inventario_origen INT NOT NULL,
    id_producto INT NOT NULL,
    cantidad INT NOT NULL CHECK (cantidad > 0),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (id_traspaso),
    INDEX (id_inventario_origen),
    INDEX (id_producto)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// -------------------- Resolver ID de Almacén "Eulalia" --------------------
$idEulalia = 0;
if ($st = $conn->prepare("SELECT id FROM sucursales WHERE LOWER(nombre) IN ('eulalia','luga eulalia') LIMIT 1")) {
  $st->execute(); $rs=$st->get_result(); if($row=$rs->fetch_assoc()) $idEulalia=(int)$row['id']; $st->close();
}
if ($idEulalia<=0) {
  $rs=$conn->query("SELECT id FROM sucursales WHERE LOWER(nombre) LIKE '%eulalia%' ORDER BY LENGTH(nombre) ASC LIMIT 1");
  if ($rs && $r=$rs->fetch_assoc()) $idEulalia=(int)$r['id'];
}
if ($idEulalia<=0) {
  echo "<div class='container my-4'><div class='alert alert-danger shadow-sm'>No se encontró la sucursal de inventario central “Eulalia”.</div></div>";
  exit();
}

$mensaje    = '';
$acuseUrl   = '';
$acuseReady = false;

// -------------------- POST: Generar traspaso --------------------
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $idUsuario         = (int)($_SESSION['id_usuario'] ?? 0);
  $idSucursalDestino = (int)($_POST['sucursal_destino'] ?? 0);

  // equipos[] = ids de inventario de equipos IMEI
  $equiposSeleccionados = (isset($_POST['equipos']) && is_array($_POST['equipos'])) ? array_values(array_unique(array_map('intval', $_POST['equipos']))) : [];

  // acc_qty[ID_INVENTARIO] = cantidad a traspasar
  $accQty = [];
  if (isset($_POST['acc_qty']) && is_array($_POST['acc_qty'])) {
    foreach ($_POST['acc_qty'] as $invId => $qty) {
      $invId = (int)$invId; $qty = (int)$qty;
      if ($invId>0 && $qty>0) $accQty[$invId] = $qty;
    }
  }

  if ($idSucursalDestino<=0) {
    $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm'>Selecciona una sucursal destino.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
  } elseif ($idSucursalDestino === $idEulalia) {
    $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm'>El destino no puede ser Eulalia.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
  } elseif (empty($equiposSeleccionados) && empty($accQty)) {
    $mensaje = "<div class='alert alert-warning alert-dismissible fade show shadow-sm'>No seleccionaste equipos ni accesorios.<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
  } else {
    $conn->begin_transaction();
    try {
      // Cabecera
      $st = $conn->prepare("INSERT INTO traspasos (id_sucursal_origen, id_sucursal_destino, usuario_creo, estatus) VALUES (?,?,?,'Pendiente')");
      $st->bind_param("iii", $idEulalia, $idSucursalDestino, $idUsuario);
      $st->execute();
      $idTraspaso = (int)$st->insert_id;
      $st->close();

      // ------- Validar y mover EQUIPOS por IMEI -------
      if (!empty($equiposSeleccionados)) {
        $place = implode(',', array_fill(0, count($equiposSeleccionados), '?'));
        $types = str_repeat('i', count($equiposSeleccionados));
        $sqlVal = "SELECT i.id FROM inventario i
                   WHERE i.id_sucursal=? AND i.estatus='Disponible' AND i.id IN ($place)";
        $stVal = $conn->prepare($sqlVal);
        $stVal->bind_param('i'.$types, $idEulalia, ...$equiposSeleccionados);
        $stVal->execute();
        $rsVal = $stVal->get_result();
        $validos = [];
        while ($r = $rsVal->fetch_assoc()) $validos[] = (int)$r['id'];
        $stVal->close();

        if (count($validos)!==count($equiposSeleccionados)) {
          throw new Exception("Algunos equipos ya no están disponibles en Eulalia.");
        }

        $stDet = $conn->prepare("INSERT INTO detalle_traspaso (id_traspaso, id_inventario) VALUES (?,?)");
        $stUpd = $conn->prepare("UPDATE inventario SET estatus='En tránsito' WHERE id=? AND id_sucursal=? AND estatus='Disponible'");
        foreach ($validos as $idInv) {
          $stDet->bind_param("ii", $idTraspaso, $idInv);
          $stDet->execute();
          $stUpd->bind_param("ii", $idInv, $idEulalia);
          $stUpd->execute();
          if ($stUpd->affected_rows !== 1) throw new Exception("No se pudo poner En tránsito el inventario #$idInv");
        }
        $stDet->close(); $stUpd->close();
      }

      // ------- Validar y mover ACCESORIOS por cantidad -------
      if (!empty($accQty)) {
        $place = implode(',', array_fill(0, count(array_keys($accQty)), '?'));
        $types = str_repeat('i', count($accQty));

        $sqlAcc = "
          SELECT i.id, i.id_producto, i.cantidad,
                 p.marca, p.modelo, p.color, p.imei1" .
          ($hasTipoProd ? ", p.tipo_producto" : "") . "
          FROM inventario i
          JOIN productos p ON p.id=i.id_producto
          WHERE i.id_sucursal=? AND i.estatus='Disponible' AND i.id IN ($place)
        ";
        $stAcc = $conn->prepare($sqlAcc);
        $stAcc->bind_param('i'.$types, $idEulalia, ...array_keys($accQty));
        $stAcc->execute();
        $rsAcc = $stAcc->get_result();
        $map = [];
        while ($r = $rsAcc->fetch_assoc()) { $map[(int)$r['id']] = $r; }
        $stAcc->close();

        foreach ($accQty as $invId => $qty) {
          if (!isset($map[$invId])) throw new Exception("Accesorio #$invId no encontrado en Eulalia / no disponible.");
          $row  = $map[$invId];
          $imei = (string)($row['imei1'] ?? '');
          $tipo = $hasTipoProd ? trim((string)($row['tipo_producto'] ?? '')) : '';

          if (!$hasTipoProd) {
            if ($imei !== '') {
              throw new Exception("El inventario #$invId parece equipo con IMEI, usa el panel de equipos.");
            }
          } else {
            if (strcasecmp($tipo, 'Accesorio') !== 0 && $imei !== '') {
              throw new Exception("El inventario #$invId parece equipo con IMEI, usa el panel de equipos.");
            }
          }

          $disp = (int)$row['cantidad'];
          if ($qty > $disp) throw new Exception("Cantidad solicitada mayor a la disponible en inventario (#$invId).");
        }

        $stDetA = $conn->prepare("INSERT INTO detalle_traspaso_acc (id_traspaso, id_inventario_origen, id_producto, cantidad) VALUES (?,?,?,?)");
        $stUpdA = $conn->prepare("UPDATE inventario SET cantidad = cantidad - ? WHERE id=? AND id_sucursal=? AND estatus='Disponible' AND cantidad >= ?");
        foreach ($accQty as $invId => $qty) {
          $pid = (int)$map[$invId]['id_producto'];
          $stDetA->bind_param("iiii", $idTraspaso, $invId, $pid, $qty);
          $stDetA->execute();

          $stUpdA->bind_param("iiii", $qty, $invId, $idEulalia, $qty);
          $stUpdA->execute();
          if ($stUpdA->affected_rows !== 1) throw new Exception("No se pudo descontar cantidad del inventario #$invId");
        }
        $stDetA->close(); $stUpdA->close();
      }

      $conn->commit();
      $acuseUrl   = "acuse_traspaso.php?id={$idTraspaso}&print=1";
      $acuseReady = true;
      $mensaje = "<div class='alert alert-success alert-dismissible fade show shadow-sm'>
        <i class='bi bi-check-circle me-1'></i> <strong>Traspaso #{$idTraspaso}</strong> generado con éxito.
        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
      </div>";
    } catch (Throwable $e) {
      $conn->rollback();
      $mensaje = "<div class='alert alert-danger alert-dismissible fade show shadow-sm'>".
                 h($e->getMessage()).
                 "<button type='button' class='btn-close' data-bs-dismiss='alert'></button></div>";
    }
  }
}

// -------------------- Inventario: Equipos disponibles (IMEI) --------------------
$condEq = "
  i.id_sucursal=? 
  AND i.estatus='Disponible' 
  AND (p.imei1 IS NOT NULL AND p.imei1 <> '')
";
if ($hasTipoProd) {
  $condEq .= " AND (p.tipo_producto IS NULL OR p.tipo_producto = '' OR p.tipo_producto <> 'Accesorio')";
}

$sqlEq = "
SELECT i.id, p.marca, p.modelo, p.color, p.imei1, p.imei2
FROM inventario i
JOIN productos p ON p.id = i.id_producto
WHERE $condEq
ORDER BY i.fecha_ingreso ASC, i.id ASC
";
$stEq = $conn->prepare($sqlEq);
$stEq->bind_param("i", $idEulalia);
$stEq->execute();
$rsEq = $stEq->get_result();
$equipos = $rsEq->fetch_all(MYSQLI_ASSOC);
$stEq->close();

// -------------------- Inventario: Accesorios disponibles (por cantidad) --------------------
$condAcc = "
  i.id_sucursal=? 
  AND i.estatus='Disponible'
  AND i.cantidad > 0
";

if ($hasTipoProd) {
  $condAcc .= " AND ( 
      p.tipo_producto = 'Accesorio'
      OR (
        (p.tipo_producto IS NULL OR p.tipo_producto = '')
        AND (p.imei1 IS NULL OR p.imei1 = '')
      )
    )";
} else {
  $condAcc .= " AND (p.imei1 IS NULL OR p.imei1 = '')";
}

$sqlAccList = "
SELECT i.id, i.id_producto, i.cantidad,
       p.marca, p.modelo, p.color, p.imei1
FROM inventario i
JOIN productos p ON p.id = i.id_producto
WHERE $condAcc
ORDER BY p.marca, p.modelo, p.color
";
$stAL = $conn->prepare($sqlAccList);
$stAL->bind_param("i", $idEulalia);
$stAL->execute();
$rsAL = $stAL->get_result();
$accesorios = $rsAL->fetch_all(MYSQLI_ASSOC);
$stAL->close();

// -------------------- Sucursales destino (tiendas) --------------------
$sucursales = [];
$resSuc = $conn->query("
  SELECT id, nombre FROM sucursales
  WHERE (LOWER(tipo_sucursal)='tienda' OR tipo_sucursal IS NULL OR tipo_sucursal='')
    AND id <> {$idEulalia}
  ORDER BY nombre ASC
");
while ($row = $resSuc->fetch_assoc()) $sucursales[] = $row;
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Generar traspaso (Eulalia)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

  <style>
    body{ background:#f7f8fb; }
    .page-header{ background:linear-gradient(180deg,#ffffff,#f4f6fb); border:1px solid #eef0f6; border-radius:18px; padding:18px 20px; box-shadow:0 6px 20px rgba(18,38,63,.06); }
    .muted{ color:#6c757d; }
    .card{ border:1px solid #eef0f6; border-radius:16px; }
    .sticky-aside{ position:sticky; top:92px; }
    .chip{ border:1px solid #e6e9f2; border-radius:999px; padding:.15rem .5rem; background:#fff; font-size:.8rem; }
    .table-hover tbody tr:hover{ background:#f5f8ff; }
    .modal-xxl{ max-width:1200px; }
    .modal-80{ max-width: 80vw; }
    #frameAcuse{ width:100%; min-height:72vh; border:0; background:#fff; }
    .tab-pane{ padding-top:6px; }
    .qty-input{ max-width:110px; }
    .cart-card .list-group-item small code{font-size:.75rem}
    .cart-empty{ color:#94a3b8; }

    .acuse-wrap { position: relative; }
    .acuse-loading {
      position: absolute; inset: 0; display: grid; place-items: center;
      background: rgba(255,255,255,.9); z-index: 2; text-align: center; padding: 24px;
    }
    .acuse-loading .spin {
      width: 36px; height: 36px; border: 3px solid #cbd5e1; border-top-color: #0d6efd;
      border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 10px;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .acuse-hint { color:#475569; font-size:.9rem }
    .acuse-hint code { background:#eef2ff; padding:2px 6px; border-radius:6px }
    .btn[disabled] { pointer-events: none; opacity: .6; }
  </style>
</head>
<body>
<?php include __DIR__.'/navbar.php'; ?>

<div class="container my-4">

  <div class="page-header d-flex align-items-center justify-content-between mb-3">
    <div>
      <h1 class="h4 mb-1"><i class="bi bi-arrow-left-right me-2"></i>Generar traspaso</h1>
      <div class="muted">
        <span class="badge rounded-pill text-bg-light border"><i class="bi bi-house-gear me-1"></i>Origen: Eulalia</span>
      </div>
    </div>
    <div class="d-flex gap-2">
      <?php if ($acuseUrl): ?>
      <a class="btn btn-primary btn-sm" target="_blank" rel="noopener" href="<?= h($acuseUrl) ?>"><i class="bi bi-printer me-1"></i>Imprimir acuse</a>
      <?php endif; ?>
      <a class="btn btn-outline-secondary btn-sm" href="traspasos_salientes.php"><i class="bi bi-clock-history me-1"></i>Histórico</a>
    </div>
  </div>

  <?= $mensaje ?>

  <form id="formTraspaso" method="POST">
    <div class="row g-3">
      <div class="col-lg-9">
        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-geo-alt text-primary"></i><strong>Seleccionar sucursal destino</strong>
            </div>
            <span class="muted small" id="miniDestino">Destino: —</span>
          </div>
          <div class="card-body">
            <div class="row g-2">
              <div class="col-md-6">
                <select name="sucursal_destino" id="sucursal_destino" class="form-select" required>
                  <option value="">— Selecciona Sucursal —</option>
                  <?php foreach ($sucursales as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </div>

        <ul class="nav nav-tabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-equipos" type="button" role="tab">
              <i class="bi bi-phone me-1"></i>Equipos (IMEI)
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-accesorios" type="button" role="tab">
              <i class="bi bi-smartwatch me-1"></i>Accesorios (por cantidad)
            </button>
          </li>
        </ul>

        <div class="tab-content">
          <!-- EQUIPOS -->
          <div class="tab-pane fade show active" id="tab-equipos" role="tabpanel">
            <div class="card mt-2">
              <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Inventario de equipos en Eulalia</strong>
                <div class="d-flex align-items-center gap-3">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="checkAllEq">
                    <label class="form-check-label" for="checkAllEq">Seleccionar visibles</label>
                  </div>
                </div>
              </div>
              <div class="card-body pt-2">
                <div class="input-group mb-2">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
                  <input type="text" id="buscadorEq" class="form-control" placeholder="Buscar por IMEI, marca o modelo...">
                  <button type="button" class="btn btn-outline-secondary" id="clearEq"><i class="bi bi-x-circle"></i></button>
                </div>
                <div class="table-responsive" style="max-height:420px; overflow:auto;">
                  <table class="table table-hover align-middle mb-0" id="tablaEquipos">
                    <thead class="table-light position-sticky top-0">
                      <tr>
                        <th class="text-center">Sel</th><th>ID Inv</th><th>Marca</th><th>Modelo</th><th>Color</th><th>IMEI1</th><th>IMEI2</th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($equipos)): ?>
                      <tr><td colspan="7" class="text-center text-muted py-4">Sin equipos disponibles</td></tr>
                    <?php else: foreach($equipos as $row): ?>
                      <tr data-id="<?= (int)$row['id'] ?>" data-marca="<?= h($row['marca']) ?>" data-modelo="<?= h($row['modelo']) ?>" data-color="<?= h($row['color']) ?>" data-imei1="<?= h($row['imei1']) ?>" data-imei2="<?= h($row['imei2'] ?? '') ?>">
                        <td class="text-center"><input type="checkbox" class="form-check-input chk-eq" name="equipos[]" value="<?= (int)$row['id'] ?>"></td>
                        <td class="fw-semibold"><?= (int)$row['id'] ?></td>
                        <td><?= h($row['marca']) ?></td>
                        <td><?= h($row['modelo']) ?></td>
                        <td><span class="chip"><?= h($row['color']) ?></span></td>
                        <td><code><?= h($row['imei1']) ?></code></td>
                        <td><?= $row['imei2'] ? "<code>".h($row['imei2'])."</code>" : "—" ?></td>
                      </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <!-- ACCESORIOS -->
          <div class="tab-pane fade" id="tab-accesorios" role="tabpanel">
            <div class="card mt-2">
              <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Inventario de accesorios en Eulalia</strong>
                <span class="small text-muted">Indica cuántas piezas quieres traspasar</span>
              </div>
              <div class="card-body pt-2">
                <div class="input-group mb-2">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
                  <input type="text" id="buscadorAcc" class="form-control" placeholder="Buscar por marca, modelo o serie...">
                  <button type="button" class="btn btn-outline-secondary" id="clearAcc"><i class="bi bi-x-circle"></i></button>
                </div>
                <div class="table-responsive" style="max-height:420px; overflow:auto;">
                  <table class="table table-hover align-middle mb-0" id="tablaAcc">
                    <thead class="table-light position-sticky top-0">
                      <tr>
                        <th>ID Inv</th><th>Marca</th><th>Modelo</th><th>Color</th><th>Serie/IMEI</th><th class="text-end">Disp.</th><th class="text-end">Traspasar</th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($accesorios)): ?>
                      <tr><td colspan="7" class="text-center text-muted py-4">Sin accesorios disponibles</td></tr>
                    <?php else: foreach($accesorios as $row): ?>
                      <tr data-id="<?= (int)$row['id'] ?>"
                          data-marca="<?= h($row['marca']) ?>"
                          data-modelo="<?= h($row['modelo']) ?>"
                          data-color="<?= h($row['color']) ?>"
                          data-serie="<?= h($row['imei1']) ?>"
                          data-disp="<?= (int)$row['cantidad'] ?>">
                        <td class="fw-semibold"><?= (int)$row['id'] ?></td>
                        <td><?= h($row['marca']) ?></td>
                        <td><?= h($row['modelo']) ?></td>
                        <td><span class="chip"><?= h($row['color']) ?></span></td>
                        <td><?= $row['imei1'] ? "<code>".h($row['imei1'])."</code>" : "—" ?></td>
                        <td class="text-end"><?= (int)$row['cantidad'] ?></td>
                        <td class="text-end">
                          <div class="input-group input-group-sm justify-content-end" style="max-width:140px;margin-left:auto">
                            <button class="btn btn-outline-secondary btn-sm btn-acc-dec" type="button">-</button>
                            <input type="number" class="form-control form-control-sm text-center qty-input"
                                   name="acc_qty[<?= (int)$row['id'] ?>]" min="0" max="<?= (int)$row['cantidad'] ?>" step="1" value="0">
                            <button class="btn btn-outline-secondary btn-sm btn-acc-inc" type="button">+</button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                  </table>
                </div>
                <div class="mt-2 small text-muted">La validación impide solicitar más piezas de las disponibles.</div>
              </div>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2 mt-3">
          <button type="button" id="btnRevisar" class="btn btn-primary"><i class="bi bi-eye-check me-1"></i>Revisar y confirmar</button>
          <a href="traspasos_salientes.php" class="btn btn-outline-secondary">Histórico</a>
        </div>
      </div>

      <!-- Carrito lateral -->
      <div class="col-lg-3">
        <div class="card cart-card sticky-aside">
          <div class="card-header d-flex justify-content-between align-items-center">
            <strong><i class="bi bi-bag-check me-1"></i>Carrito</strong>
            <button type="button" class="btn btn-sm btn-outline-danger" id="btnVaciarCarrito" title="Vaciar"><i class="bi bi-trash"></i></button>
          </div>
          <div class="card-body">
            <div class="small text-muted mb-2" id="destinoCart">Destino: —</div>
            <div class="mb-2"><b>Equipos:</b></div>
            <ul class="list-group mb-3" id="cartEq"></ul>
            <div class="mb-2"><b>Accesorios:</b></div>
            <ul class="list-group" id="cartAcc"></ul>
            <div class="mt-3">
              <div class="d-flex justify-content-between"><span class="text-muted">Total equipos</span><span id="totEq">0</span></div>
              <div class="d-flex justify-content-between"><span class="text-muted">Total piezas acc.</span><span id="totAcc">0</span></div>
            </div>
            <div class="mt-3">
              <button type="button" id="btnConfirmarCart" class="btn btn-success w-100"><i class="bi bi-send-check me-1"></i>Confirmar y generar</button>
            </div>
          </div>
        </div>
      </div>
    </div><!-- row -->
  </form>
</div>

<!-- Modal ACUSE -->
<div class="modal fade" id="modalAcuse" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-xxl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Acuse de entrega</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-0 acuse-wrap">
        <div id="acuseLoading" class="acuse-loading" hidden>
          <div>
            <div class="spin"></div>
            <div><b>Cargando acuse…</b></div>
            <div class="acuse-hint">No cierres esta ventana. Si tarda, pulsa <code>Abrir</code> para verlo en una pestaña nueva.</div>
          </div>
        </div>
        <iframe id="frameAcuse" src="about:blank"></iframe>
      </div>
      <div class="modal-footer">
        <button type="button" id="btnOpenAcuse" class="btn btn-outline-secondary"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir</button>
        <button type="button" id="btnPrintAcuse" class="btn btn-primary"><i class="bi bi-printer me-1"></i>Imprimir</button>
        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal de confirmación -->
<div class="modal fade" id="modalConfirm" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-80">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-question-circle me-2"></i>Confirmar traspaso</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2 small text-muted" id="confirmDestino">Destino: —</div>
        <div class="row g-3">
          <div class="col-lg-6">
            <div class="card">
              <div class="card-header"><b>Equipos seleccionados</b></div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>ID Inv</th><th>Modelo</th><th>Color</th><th>IMEI</th></tr></thead>
                    <tbody id="confirmEqTbody"><tr><td colspan="4" class="text-center cart-empty py-3">Ninguno</td></tr></tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="card">
              <div class="card-header"><b>Accesorios seleccionados</b></div>
              <div class="card-body p-0">
                <div class="table-responsive">
                  <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>ID Inv</th><th>Modelo</th><th>Color</th><th class="text-end">Pzas</th></tr></thead>
                    <tbody id="confirmAccTbody"><tr><td colspan="4" class="text-center cart-empty py-3">Ninguno</td></tr></tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="mt-3 d-flex gap-3">
          <div class="badge text-bg-light border">Total equipos: <span id="confirmTotEq">0</span></div>
          <div class="badge text-bg-light border">Total accesorios (pzas): <span id="confirmTotAcc">0</span></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Seguir editando</button>
        <button class="btn btn-success" id="btnConfirmarSubmit"><i class="bi bi-check2-circle me-1"></i>Confirmar y generar</button>
      </div>
    </div>
  </div>
</div>

<script>
// ---- Helpers de filtro ----
const filtra = (inputId, tableId) => {
  const f = (document.getElementById(inputId).value || '').toLowerCase();
  document.querySelectorAll('#'+tableId+' tbody tr').forEach(tr=>{
    tr.style.display = tr.innerText.toLowerCase().includes(f) ? '' : 'none';
  });
};

// --- NUEVO: limpiar buscadores cuando aplica (scanner-friendly) ---
function clearAndFocus(inputId, tableId){
  const el = document.getElementById(inputId);
  if (!el) return;
  if (el.value !== '') {
    el.value = '';
    filtra(inputId, tableId);
  }
  el.focus();
  try { el.select(); } catch(e){}
}

document.getElementById('buscadorEq').addEventListener('keyup', ()=>filtra('buscadorEq','tablaEquipos'));
document.getElementById('clearEq').addEventListener('click', ()=>{
  document.getElementById('buscadorEq').value='';
  filtra('buscadorEq','tablaEquipos');
  document.getElementById('buscadorEq').focus();
});

document.getElementById('buscadorAcc').addEventListener('keyup', ()=>filtra('buscadorAcc','tablaAcc'));
document.getElementById('clearAcc').addEventListener('click', ()=>{
  document.getElementById('buscadorAcc').value='';
  filtra('buscadorAcc','tablaAcc');
  document.getElementById('buscadorAcc').focus();
});

// ---- Destino helper ----
const selDestino = document.getElementById('sucursal_destino');
const miniDestino = document.getElementById('miniDestino');
const destinoCart = document.getElementById('destinoCart');
function updateDestinoLabels(){
  const txt = selDestino.options[selDestino.selectedIndex]?.text || '—';
  miniDestino.textContent = 'Destino: ' + txt;
  destinoCart.textContent = 'Destino: ' + txt;
}
selDestino.addEventListener('change', updateDestinoLabels);

// ---- Seleccionar visibles (equipos) ----
document.getElementById('checkAllEq').addEventListener('change', function(){
  const checked = this.checked;
  document.querySelectorAll('#tablaEquipos tbody tr').forEach(tr=>{
    if (tr.style.display !== 'none') {
      const chk = tr.querySelector('.chk-eq'); if (chk) { chk.checked = checked; }
    }
  });
  rebuildCart();
});

// ---- Validación/controles cantidades accesorios ----
document.querySelectorAll('#tablaAcc .btn-acc-inc').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const input = btn.parentElement.querySelector('input.qty-input');
    const max = parseInt(input.getAttribute('max'),10) || 0;
    let v = parseInt(input.value,10) || 0;
    if (v < max) {
      input.dataset.viaBtn = '1';                 // NUEVO: marca que vino por botón
      input.value = v+1;
      input.dispatchEvent(new Event('input'));    // sigue validando + carrito
      setTimeout(()=>{ delete input.dataset.viaBtn; }, 0);
    }
  });
});
document.querySelectorAll('#tablaAcc .btn-acc-dec').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const input = btn.parentElement.querySelector('input.qty-input');
    let v = parseInt(input.value,10) || 0;
    if (v > 0) {
      input.dataset.viaBtn = '1';                 // NUEVO: marca que vino por botón
      input.value = v-1;
      input.dispatchEvent(new Event('input'));
      setTimeout(()=>{ delete input.dataset.viaBtn; }, 0);
    }
  });
});

document.querySelectorAll('#tablaAcc input.qty-input').forEach(inp=>{
  // Mantiene validación y carrito en vivo
  inp.addEventListener('input', ()=>{
    const tr = inp.closest('tr'); const disp = parseInt(tr.dataset.disp, 10) || 0;
    let v = parseInt(inp.value,10)||0;
    if (v<0) v=0; if (v>disp) v=disp; inp.value = v;
    rebuildCart();
  });

  // NUEVO: si escribes a mano y confirmas (blur/change), limpia buscadorAcc
  inp.addEventListener('change', ()=>{
    const v = parseInt(inp.value,10)||0;
    // Si el cambio viene por botones +/- NO limpiar (te deja seguir sumando)
    if ((inp.dataset.viaBtn || '') === '1') return;
    if (v > 0) clearAndFocus('buscadorAcc', 'tablaAcc');
  });

  // NUEVO: Enter = confirmar y limpiar buscadorAcc (solo si no viene de botones)
  inp.addEventListener('keydown', (e)=>{
    if (e.key === 'Enter') {
      e.preventDefault();
      const v = parseInt(inp.value,10)||0;
      if ((inp.dataset.viaBtn || '') === '1') return;
      if (v > 0) {
        clearAndFocus('buscadorAcc', 'tablaAcc');
      } else {
        // si no puso nada, igual regresa el foco al buscador para seguir
        document.getElementById('buscadorAcc')?.focus();
      }
      inp.blur();
    }
  });
});

// ---- Carrito ----
const cartEqUl  = document.getElementById('cartEq');
const cartAccUl = document.getElementById('cartAcc');
const totEq = document.getElementById('totEq');
const totAcc = document.getElementById('totAcc');

function rebuildCart(){
  // Equipos
  cartEqUl.innerHTML = '';
  let eqCount = 0;
  document.querySelectorAll('#tablaEquipos .chk-eq').forEach(chk=>{
    if (chk.checked){
      const tr = chk.closest('tr');
      const id = tr.dataset.id, modelo = tr.dataset.modelo, color = tr.dataset.color, imei1 = tr.dataset.imei1;
      eqCount++;
      const li = document.createElement('li');
      li.className='list-group-item d-flex justify-content-between align-items-center';
      li.innerHTML = `<div><b>#${id}</b> ${modelo} <span class="text-muted">(${color})</span><br><small>IMEI <code>${imei1}</code></small></div>
                      <button type="button" class="btn btn-sm btn-outline-danger" data-remove-eq="${id}"><i class="bi bi-x"></i></button>`;
      cartEqUl.appendChild(li);
    }
  });
  if (eqCount===0){
    cartEqUl.innerHTML = `<li class="list-group-item text-center cart-empty">Sin equipos</li>`;
  }
  totEq.textContent = eqCount;

  // Accesorios
  cartAccUl.innerHTML = '';
  let accPzas = 0;
  document.querySelectorAll('#tablaAcc input.qty-input').forEach(inp=>{
    const v = parseInt(inp.value,10)||0;
    if (v>0){
      const tr = inp.closest('tr');
      const id = tr.dataset.id, modelo = tr.dataset.modelo, color = tr.dataset.color;
      accPzas += v;
      const li = document.createElement('li');
      li.className='list-group-item d-flex justify-content-between align-items-center';
      li.innerHTML = `<div><b>#${id}</b> ${modelo} <span class="text-muted">(${color})</span></div>
                      <div class="d-flex align-items-center gap-2">
                        <span class="badge text-bg-light border">${v}</span>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-remove-acc="${id}"><i class="bi bi-x"></i></button>
                      </div>`;
      cartAccUl.appendChild(li);
    }
  });
  if (accPzas===0){
    cartAccUl.innerHTML = `<li class="list-group-item text-center cart-empty">Sin accesorios</li>`;
  }
  totAcc.textContent = accPzas;

  cartEqUl.querySelectorAll('[data-remove-eq]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = btn.getAttribute('data-remove-eq');
      const chk = document.querySelector(`#tablaEquipos .chk-eq[value="${id}"]`);
      if (chk){ chk.checked = false; rebuildCart(); }
    });
  });
  cartAccUl.querySelectorAll('[data-remove-acc]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = btn.getAttribute('data-remove-acc');
      const inp = document.querySelector(`#tablaAcc tr[data-id="${id}"] input.qty-input`);
      if (inp){ inp.value = 0; rebuildCart(); }
    });
  });
}

// NUEVO: cuando marcas equipo, limpiar buscadorEq (modo scanner)
document.querySelectorAll('#tablaEquipos .chk-eq').forEach(chk=>{
  chk.addEventListener('change', ()=>{
    rebuildCart();
    if (chk.checked) clearAndFocus('buscadorEq','tablaEquipos');
  });
});

document.getElementById('btnVaciarCarrito').addEventListener('click', ()=>{
  document.querySelectorAll('#tablaEquipos .chk-eq').forEach(chk=>{ chk.checked=false; });
  document.querySelectorAll('#tablaAcc input.qty-input').forEach(inp=>{ inp.value=0; });
  rebuildCart();
});

// ---- Modal Confirmación ----
const confirmEqTbody = document.getElementById('confirmEqTbody');
const confirmAccTbody = document.getElementById('confirmAccTbody');
const confirmTotEq = document.getElementById('confirmTotEq');
const confirmTotAcc = document.getElementById('confirmTotAcc');
const confirmDestino = document.getElementById('confirmDestino');
const modalConfirm = new bootstrap.Modal(document.getElementById('modalConfirm'));

function fillConfirmModal(){
  const txt = selDestino.options[selDestino.selectedIndex]?.text || '—';
  confirmDestino.textContent = 'Destino: ' + txt;

  const selectedEq = Array.from(document.querySelectorAll('#tablaEquipos .chk-eq:checked'));
  confirmEqTbody.innerHTML = '';
  if (selectedEq.length===0){
    confirmEqTbody.innerHTML = `<tr><td colspan="4" class="text-center cart-empty py-3">Ninguno</td></tr>`;
  } else {
    selectedEq.forEach(chk=>{
      const tr = chk.closest('tr');
      confirmEqTbody.insertAdjacentHTML('beforeend',
        `<tr>
          <td><b>#${tr.dataset.id}</b></td>
          <td>${tr.dataset.modelo}</td>
          <td>${tr.dataset.color}</td>
          <td><code>${tr.dataset.imei1}</code></td>
        </tr>`
      );
    });
  }
  confirmTotEq.textContent = selectedEq.length;

  const selectedAccRows = Array.from(document.querySelectorAll('#tablaAcc input.qty-input')).filter(inp=>(parseInt(inp.value,10)||0)>0);
  confirmAccTbody.innerHTML = '';
  let totalPzas=0;
  if (selectedAccRows.length===0){
    confirmAccTbody.innerHTML = `<tr><td colspan="4" class="text-center cart-empty py-3">Ninguno</td></tr>`;
  } else {
    selectedAccRows.forEach(inp=>{
      const v = parseInt(inp.value,10)||0; totalPzas+=v;
      const tr = inp.closest('tr');
      confirmAccTbody.insertAdjacentHTML('beforeend',
        `<tr>
          <td><b>#${tr.dataset.id}</b></td>
          <td>${tr.dataset.modelo}</td>
          <td>${tr.dataset.color}</td>
          <td class="text-end"><b>${v}</b></td>
        </tr>`
      );
    });
  }
  confirmTotAcc.textContent = totalPzas;
}

function hayAlgoEnCarrito(){
  const eq = document.querySelector('#tablaEquipos .chk-eq:checked');
  const acc = Array.from(document.querySelectorAll('#tablaAcc input.qty-input')).some(inp=>(parseInt(inp.value,10)||0)>0);
  return !!eq || acc;
}

document.getElementById('btnRevisar').addEventListener('click', ()=>{
  if (!selDestino.value){ alert('Selecciona una sucursal destino.'); return; }
  if (!hayAlgoEnCarrito()){ alert('Agrega equipos o accesorios al carrito.'); return; }
  fillConfirmModal();
  modalConfirm.show();
});

document.getElementById('btnConfirmarCart').addEventListener('click', ()=>{
  if (!selDestino.value){ alert('Selecciona una sucursal destino.'); return; }
  if (!hayAlgoEnCarrito()){ alert('Agrega equipos o accesorios al carrito.'); return; }
  fillConfirmModal();
  modalConfirm.show();
});

document.getElementById('btnConfirmarSubmit').addEventListener('click', ()=>{
  modalConfirm.hide();
  document.getElementById('formTraspaso').submit();
});

updateDestinoLabels();
rebuildCart();

const ACUSE_URL   = <?= json_encode($acuseUrl, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const ACUSE_READY = <?= $acuseReady ? 'true' : 'false' ?>;

(function(){
  if (!ACUSE_READY || !ACUSE_URL) return;

  const modalEl   = document.getElementById('modalAcuse');
  const modal     = new bootstrap.Modal(modalEl);
  const frame     = document.getElementById('frameAcuse');
  const overlay   = document.getElementById('acuseLoading');
  const btnOpen   = document.getElementById('btnOpenAcuse');
  const btnPrint  = document.getElementById('btnPrintAcuse');

  function setBusy(busy) {
    if (busy) {
      overlay.hidden = false;
      btnOpen.setAttribute('disabled','disabled');
      btnPrint.setAttribute('disabled','disabled');
    } else {
      overlay.hidden = true;
      btnOpen.removeAttribute('disabled');
      btnPrint.removeAttribute('disabled');
    }
  }

  btnOpen.onclick = ()=> window.open(ACUSE_URL,'_blank','noopener');
  btnPrint.onclick = ()=> {
    try { frame.contentWindow?.print(); }
    catch(e){ window.open(ACUSE_URL,'_blank','noopener'); }
  };

  setBusy(true);
  frame.src = 'about:blank';
  modal.show();

  let done = false;
  frame.addEventListener('load', () => {
    if (done) return;
    if (frame.src === 'about:blank') return;
    done = true;
    setBusy(false);
  });

  const fallback = setTimeout(()=>{ if (!done) setBusy(false); }, 7000);
  requestAnimationFrame(()=> { frame.src = ACUSE_URL; });

  modalEl.addEventListener('hidden.bs.modal', ()=> {
    clearTimeout(fallback);
    frame.src = 'about:blank';
    setBusy(false);
  });
})();
</script>
</body>
</html>