<?php
// compras_pagos.php — CxP (pagos) con separación Luga/Subdis
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

include 'db.php';

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
$isSubdisAdmin = ($ROL === 'subdis_admin');
$ID_SUBDIS_SES = $isSubdisAdmin ? (int)($_SESSION['id_subdis'] ?? 0) : 0;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die("ID inválido.");

// Si es subdis_admin, debe tener id_subdis
if ($isSubdisAdmin && $ID_SUBDIS_SES <= 0) {
  http_response_code(403);
  die("Acceso inválido: falta id_subdis en sesión.");
}

// Traer compra (incluye propiedad/id_subdis)
$enc = $conn->query("
  SELECT c.*, p.nombre AS proveedor, s.nombre AS sucursal
  FROM compras c
  INNER JOIN proveedores p ON p.id=c.id_proveedor
  INNER JOIN sucursales  s ON s.id=c.id_sucursal
  WHERE c.id=$id
")->fetch_assoc();
if (!$enc) die("Factura no encontrada");

// ===== Candado de acceso por propiedad/id_subdis =====
$propCompra  = (string)($enc['propiedad'] ?? 'Luga');
$idSubCompra = isset($enc['id_subdis']) ? (int)$enc['id_subdis'] : 0;

if ($isSubdisAdmin) {
  if ($propCompra !== 'Subdistribuidor' || $idSubCompra !== $ID_SUBDIS_SES) {
    http_response_code(403);
    die("No tienes permiso para gestionar pagos de esta compra.");
  }
} else {
  // Luga por defecto solo ve compras Luga
  if ($propCompra !== 'Luga') {
    http_response_code(403);
    die("No tienes permiso para gestionar pagos de esta compra.");
  }
}

// ==============================
// POST: Insertar pago (antes de imprimir cualquier cosa)
// ==============================
if ($_SERVER['REQUEST_METHOD']==='POST') {

  // Revalidación ligera (por si el id cambia o alguien intenta trucos)
  // (ya tenemos $enc, pero lo dejamos claro)
  if ($isSubdisAdmin) {
    if ($propCompra !== 'Subdistribuidor' || $idSubCompra !== $ID_SUBDIS_SES) {
      http_response_code(403);
      die("No tienes permiso para gestionar pagos de esta compra.");
    }
  } else {
    if ($propCompra !== 'Luga') {
      http_response_code(403);
      die("No tienes permiso para gestionar pagos de esta compra.");
    }
  }

  $fecha_pago = $_POST['fecha_pago'] ?? date('Y-m-d');
  $monto      = (float)($_POST['monto'] ?? 0);
  $metodo     = substr(trim($_POST['metodo_pago'] ?? ''),0,40);
  $ref        = substr(trim($_POST['referencia'] ?? ''),0,120);
  $notas      = trim($_POST['notas'] ?? '');

  // Tope de saldo (server-side)
  $row = $conn->query("SELECT IFNULL(SUM(monto),0) AS pagado FROM compras_pagos WHERE id_compra=$id")->fetch_assoc();
  $sumPagado = (float)($row['pagado'] ?? 0);
  $totalFact = (float)$enc['total'];
  $saldo     = max(0, $totalFact - $sumPagado);

  if ($monto > 0) {
    if ($monto > $saldo && $saldo > 0) {
      // Si intentan pagar de más, lo capamos al saldo (o puedes bloquear con die)
      $monto = $saldo;
    }

    $stmt = $conn->prepare("INSERT INTO compras_pagos (id_compra, fecha_pago, monto, metodo_pago, referencia, notas) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("isdsss", $id, $fecha_pago, $monto, $metodo, $ref, $notas);
    $stmt->execute();
    $stmt->close();

    // Recalcular estatus
    $row2 = $conn->query("SELECT IFNULL(SUM(monto),0) AS pagado FROM compras_pagos WHERE id_compra=$id")->fetch_assoc();
    $sumPagado2 = (float)($row2['pagado'] ?? 0);
    $nuevoEstatus = ($sumPagado2 <= 0) ? 'Pendiente' : (($sumPagado2 + 0.0001) >= $totalFact ? 'Pagada' : 'Parcial');

    $st = $conn->prepare("UPDATE compras SET estatus=? WHERE id=?");
    $st->bind_param("si", $nuevoEstatus, $id);
    $st->execute();
    $st->close();

    // Redirect (PRG)
    header("Location: compras_pagos.php?id=".$id);
    exit();
  }
}

// A partir de aquí ya es SEGURO imprimir HTML
include 'navbar.php';

// Cargar pagos para mostrar tabla
$pagos = $conn->query("SELECT * FROM compras_pagos WHERE id_compra=$id ORDER BY fecha_pago ASC, id ASC");

// Totales
$pagado = 0.0;
$rows = [];
if ($pagos) {
  while ($tmp = $pagos->fetch_assoc()) { $pagado += (float)$tmp['monto']; $rows[] = $tmp; }
}
$saldo = max(0, (float)$enc['total'] - $pagado);

$ownerChip = ($propCompra === 'Subdistribuidor') ? 'Subdistribuidor' : 'Luga';
?>
<!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"> -->

<div class="container my-4">
  <h4 class="mb-2">
    Pagos a factura: <?= htmlspecialchars($enc['num_factura']) ?>
    <span class="badge rounded-pill ms-2" style="background:#ecfeff;color:#155e75;border:1px solid #a5f3fc;">
      Propiedad: <?= htmlspecialchars($ownerChip) ?>
    </span>
  </h4>

  <p class="text-muted mb-3">
    <strong>Proveedor:</strong> <?= htmlspecialchars($enc['proveedor']) ?> ·
    <strong>Sucursal:</strong> <?= htmlspecialchars($enc['sucursal']) ?> ·
    <strong>Vence:</strong> <?= htmlspecialchars($enc['fecha_vencimiento'] ?: '-') ?>
  </p>

  <div class="row g-3">
    <div class="col-md-7">
      <div class="card">
        <div class="card-header">Historial de pagos</div>
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>Fecha</th><th>Método</th><th>Referencia</th><th class="text-end">Monto</th></tr></thead>
              <tbody>
              <?php if (!empty($rows)): foreach($rows as $p): ?>
                <tr>
                  <td><?= htmlspecialchars($p['fecha_pago']) ?></td>
                  <td><?= htmlspecialchars($p['metodo_pago']) ?></td>
                  <td><?= htmlspecialchars($p['referencia']) ?></td>
                  <td class="text-end">$<?= number_format((float)$p['monto'],2) ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="4" class="text-center text-muted">Sin pagos</td></tr>
              <?php endif; ?>
              </tbody>
              <tfoot>
                <tr><th colspan="3" class="text-end">Total factura</th><th class="text-end">$<?= number_format((float)$enc['total'],2) ?></th></tr>
                <tr><th colspan="3" class="text-end">Pagado</th><th class="text-end">$<?= number_format((float)$pagado,2) ?></th></tr>
                <tr class="table-light"><th colspan="3" class="text-end">Saldo</th><th class="text-end">$<?= number_format((float)$saldo,2) ?></th></tr>
              </tfoot>
            </table>
          </div>
          <a href="compras_ver.php?id=<?= (int)$enc['id'] ?>" class="btn btn-outline-secondary">Ver factura</a>
          <a href="compras_resumen.php" class="btn btn-outline-primary">Regresar a CxP</a>
        </div>
      </div>
    </div>

    <div class="col-md-5">
      <div class="card">
        <div class="card-header">Registrar pago</div>
        <div class="card-body">
          <?php if ($saldo <= 0): ?>
            <div class="alert alert-success mb-0">Factura saldada. No es necesario registrar más pagos.</div>
          <?php else: ?>
            <form method="POST">
              <div class="mb-2">
                <label class="form-label">Fecha</label>
                <input type="date" name="fecha_pago" class="form-control" value="<?= date('Y-m-d') ?>" required>
              </div>
              <div class="mb-2">
                <label class="form-label">Monto</label>
                <input type="number" step="0.01" min="0.01" name="monto" class="form-control" max="<?= max(0, $saldo) ?>" required>
                <small class="text-muted">Saldo actual: $<?= number_format((float)$saldo,2) ?></small>
              </div>
              <div class="mb-2">
                <label class="form-label">Método</label>
                <input type="text" name="metodo_pago" class="form-control" placeholder="Transferencia, efectivo, etc.">
              </div>
              <div class="mb-2">
                <label class="form-label">Referencia</label>
                <input type="text" name="referencia" class="form-control" placeholder="Folio, banco, etc.">
              </div>
              <div class="mb-3">
                <label class="form-label">Notas</label>
                <textarea name="notas" class="form-control" rows="2"></textarea>
              </div>
              <button class="btn btn-success" type="submit">Guardar pago</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
