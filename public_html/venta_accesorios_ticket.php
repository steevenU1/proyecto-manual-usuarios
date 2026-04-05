<?php
// venta_accesorios_ticket.php — Ticket/recibo de venta de accesorios con LOGO
// Uso: venta_accesorios_ticket.php?id=123[&logo=https://ruta/logo.png]
// Ahora muestra la SERIE / IMEI del accesorio (si existe) debajo de la descripción.

session_start();
if (!isset($_SESSION['id_usuario'])) { header('Location: index.php'); exit(); }

require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function money($n){ return '$'.number_format((float)$n, 2, '.', ','); }
function col_exists(mysqli $c,$t,$col){
  $t=$c->real_escape_string($t); $col=$c->real_escape_string($col);
  $r=$c->query("SHOW COLUMNS FROM `{$t}` LIKE '{$col}'"); return $r && $r->num_rows>0;
}
function is_http_url($u){
  if (!$u) return false;
  $u = trim((string)$u);
  if (strlen($u) > 500) return false;
  return (str_starts_with($u, 'http://') || str_starts_with($u, 'https://'));
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo "ID inválido."; exit; }

// ---- Venta (encabezado) ----
$sqlVenta = "
  SELECT 
    v.*,
    u.nombre   AS usuario_nombre,
    s.nombre   AS sucursal_nombre
    ".(col_exists($conn,'sucursales','logo_url') ? ", s.logo_url AS sucursal_logo_url" : "")."
  FROM ventas_accesorios v
  LEFT JOIN usuarios   u ON u.id = v.id_usuario
  LEFT JOIN sucursales s ON s.id = v.id_sucursal
  WHERE v.id = ?
  LIMIT 1
";
$st = $conn->prepare($sqlVenta);
if (!$st) { echo "Error preparando consulta."; exit; }
$st->bind_param('i', $id);
$st->execute();
$venta = $st->get_result()->fetch_assoc();
if (!$venta) { echo "Venta no encontrada."; exit; }

// ---- Detalle ----
// Tomamos la serie/IMEI desde productos (imei1 / imei2) cuando exista.
$sqlDet = "
  SELECT d.*, 
         COALESCE(
           d.descripcion_snapshot,
           TRIM(CONCAT(p.marca,' ',p.modelo,' ',COALESCE(p.color,'')))
         ) AS descripcion,
         COALESCE(NULLIF(p.imei1,''), NULLIF(p.imei2,'')) AS serie
  FROM detalle_venta_accesorio d
  LEFT JOIN productos p ON p.id = d.id_producto
  WHERE d.id_venta = ?
  ORDER BY d.id ASC
";
$sd = $conn->prepare($sqlDet);
$sd->bind_param('i', $id);
$sd->execute();
$detalles = $sd->get_result()->fetch_all(MYSQLI_ASSOC);

// Datos “bonitos”
$folio       = $venta['id'];
$tag         = $venta['tag'] ?? '';
$cliente     = $venta['nombre_cliente'] ?? '';
$telefono    = $venta['telefono'] ?? '';
$forma_pago  = $venta['forma_pago'] ?? '';
$efectivo    = (float)($venta['efectivo'] ?? 0);
$tarjeta     = (float)($venta['tarjeta'] ?? 0);
$total       = (float)($venta['total'] ?? 0);
$comentarios = $venta['comentarios'] ?? '';
$fecha       = $venta['created_at'] ?? $venta['fecha'] ?? date('Y-m-d H:i:s');
$usuarioNom  = $venta['usuario_nombre'] ?? ('Usuario #'.(int)($venta['id_usuario'] ?? 0));
$sucursalNom = $venta['sucursal_nombre'] ?? ('Sucursal #'.(int)($venta['id_sucursal'] ?? 0));

// ---- Resolver LOGO ----
$DEFAULT_LOGO = 'https://i.ibb.co/DDw7yjYV/43f8e23a-8877-4928-9407-32d18fb70f79.png';
$logoUrl = $DEFAULT_LOGO;

// prioridad 1: query param ?logo=
if (isset($_GET['logo']) && is_http_url($_GET['logo'])) {
  $logoUrl = $_GET['logo'];
}
// prioridad 2: campo de sucursal si existe y viene con valor
elseif (!empty($venta['sucursal_logo_url']) && is_http_url($venta['sucursal_logo_url'])) {
  $logoUrl = $venta['sucursal_logo_url'];
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Ticket venta accesorios #<?= (int)$folio ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{
      --ticket-max: 860px;    /* en pantalla */
      --ticket-pad: 16px;
      --logo-h: 64px;         /* alto de logo en pantalla */
    }
    @media print {
      :root{
        --ticket-max: 86mm;   /* ancho tipo ticket térmico (80–90mm aprox) */
        --ticket-pad: 8px;
        --logo-h: 56px;       /* un poco más compacto en impresión */
      }
      .no-print { display: none !important; }
      body { background: #fff; }
      .card { box-shadow: none !important; border: 0 !important; }
      .container { max-width: var(--ticket-max) !important; }
    }
    body { background: #f5f6f8; }
    .container { max-width: var(--ticket-max); }
    .ticket-card { border:1px solid #e9ecef; }
    .table-sm td, .table-sm th { padding-top: .35rem; padding-bottom: .35rem; }
    .lh-tight { line-height: 1.1; }
    .brand-logo {
      height: var(--logo-h);
      width: auto;
      object-fit: contain;
      image-rendering: -webkit-optimize-contrast;
    }
    .brand-name { font-weight: 700; letter-spacing: .2px; }
    .muted { color:#6c757d; }
  </style>
</head>
<body>
<div class="container my-4">
  <div class="card shadow-sm ticket-card">
    <div class="card-body" style="padding: var(--ticket-pad);">

      <!-- Encabezado con LOGO -->
      <div class="d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
          <img src="<?= h($logoUrl) ?>" alt="Logo" class="brand-logo" onerror="this.style.display='none'">
          <div class="lh-tight">
            <div class="brand-name"><?= h($sucursalNom) ?></div>
            <small class="text-muted">Ticket de Venta — Accesorios</small>
          </div>
        </div>
        <div class="text-end">
          <div class="fw-semibold">Folio #<?= (int)$folio ?></div>
          <small class="text-muted">TAG: <?= h($tag) ?></small><br>
          <button class="btn btn-outline-secondary btn-sm no-print mt-1" onclick="window.print()">Imprimir</button>
        </div>
      </div>

      <hr class="my-3">

      <!-- Datos del cliente / sucursal / fecha -->
      <div class="row g-3 mb-2">
        <div class="col-md-4">
          <div class="small text-muted">Cliente</div>
          <div class="fw-semibold"><?= h($cliente) ?></div>
          <div class="text-muted"><?= h($telefono) ?></div>
        </div>
        <div class="col-md-4">
          <div class="small text-muted">Sucursal</div>
          <div class="fw-semibold"><?= h($sucursalNom) ?></div>
          <div class="text-muted">Atendido por: <?= h($usuarioNom) ?></div>
        </div>
        <div class="col-md-4">
          <div class="small text-muted">Fecha</div>
          <div class="fw-semibold"><?= h(date('d/m/Y H:i', strtotime($fecha))) ?></div>
          <div class="text-muted">Forma de pago: <?= h($forma_pago) ?></div>
        </div>
      </div>

      <!-- Detalle -->
      <div class="table-responsive">
        <table class="table table-sm">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Descripción</th>
              <th class="text-center">Cant.</th>
              <th class="text-end">Precio</th>
              <th class="text-end">Subtotal</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $i = 1;
            foreach ($detalles as $d):
              $desc  = $d['descripcion'] ?? ('Producto #'.(int)$d['id_producto']);
              $serie = trim($d['serie'] ?? '');
              $cant  = (int)$d['cantidad'];
              $precio = (float)$d['precio_unitario'];
              $sub    = (float)$d['subtotal'];
            ?>
            <tr>
              <td><?= $i++ ?></td>
              <td>
                <?= h($desc) ?>
                <?php if ($serie !== ''): ?>
                  <br><small class="text-muted">Serie: <?= h($serie) ?></small>
                <?php endif; ?>
              </td>
              <td class="text-center"><?= $cant ?></td>
              <td class="text-end"><?= money($precio) ?></td>
              <td class="text-end"><?= money($sub) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="4" class="text-end">Total</th>
              <th class="text-end"><?= money($total) ?></th>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="row g-3">
        <div class="col-md-6">
          <div class="small text-muted mb-1">Comentarios</div>
          <div class="border rounded p-2" style="min-height:48px"><?= nl2br(h($comentarios)) ?></div>
        </div>
        <div class="col-md-6">
          <div class="small text-muted mb-1">Pago</div>
          <table class="table table-sm mb-0">
            <tr><td>Efectivo</td><td class="text-end"><?= money($efectivo) ?></td></tr>
            <tr><td>Tarjeta</td><td class="text-end"><?= money($tarjeta) ?></td></tr>
            <tr class="table-light"><th>Total</th><th class="text-end"><?= money($total) ?></th></tr>
          </table>
        </div>
      </div>

      <hr>
      <div class="text-center">
        <small class="text-muted lh-tight d-block">
          Gracias por su compra. Conserve este comprobante.
        </small>
      </div>
    </div>
  </div>

  <div class="text-center mt-3 no-print">
    <a href="venta_accesorios.php" class="btn btn-outline-primary btn-sm">Registrar otra venta</a>
    <a href="dashboard_unificado.php" class="btn btn-outline-secondary btn-sm">Ir al dashboard</a>
  </div>
</div>
</body>
</html>
