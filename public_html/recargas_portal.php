<?php
// recargas_portal.php — Ejecutivos/Gerentes: validar clientes y confirmar recargas con comprobante + métricas + confirm modal
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

/* =========================
   CONFIG (feature flags)
========================= */
// Si es FALSE, no se bloquean acciones por días (libre para pruebas)
const RECARGAS_ENFORCE_WAIT = false;

// Días de espera (solo aplican si RECARGAS_ENFORCE_WAIT=true)
const REC1_WAIT_DAYS = 15;
const REC2_WAIT_DAYS = 30;
/* ========================= */

$ROL         = $_SESSION['rol'] ?? '';
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);

if (!in_array($ROL, ['Ejecutivo','Gerente','Admin','Logistica'], true)) { header("Location: 403.php"); exit(); }
if (file_exists(__DIR__.'/navbar.php')) require_once __DIR__.'/navbar.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function elegible_dias($fechaVenta, $dias){
  if (!RECARGAS_ENFORCE_WAIT) return true;
  return (new DateTime() >= (new DateTime($fechaVenta))->modify("+$dias day"));
}
function pct($num, $den){ if ($den <= 0) return 0; return (int)round(($num * 100) / $den); }

// Flash (opcional)
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$promoId = (int)($_GET['promo'] ?? 0);

// Promos activas
$promos = $conn->query("
  SELECT id, nombre, origen, fecha_inicio, fecha_fin
  FROM recargas_promo
  WHERE activa=1
  ORDER BY id DESC
")->fetch_all(MYSQLI_ASSOC);

/* =========================
   AUTO-BASE (lazy upsert)
========================= */
$promo = null;
if ($promoId > 0) {
  $promo = $conn->query("SELECT id, nombre, origen, fecha_inicio, fecha_fin, activa FROM recargas_promo WHERE id=$promoId")->fetch_assoc();
  if ($promo && (int)$promo['activa'] === 1) {
    $fi = $conn->real_escape_string($promo['fecha_inicio']);
    $ff = $conn->real_escape_string($promo['fecha_fin']);
    $filtroSucursal = (!in_array($ROL, ['Admin','Logistica'], true)) ? "AND v.id_sucursal = ".(int)$ID_SUCURSAL : "";
    $sqlAutoBase = "
      INSERT IGNORE INTO recargas_promo_clientes
        (id_promo, id_venta, id_sucursal, nombre_cliente, telefono_cliente, fecha_venta)
      SELECT {$promo['id']}, v.id, v.id_sucursal,
             COALESCE(NULLIF(TRIM(v.nombre_cliente),''),'Cliente'),
             TRIM(v.telefono_cliente),
             v.fecha_venta
      FROM ventas v
      WHERE DATE(v.fecha_venta) BETWEEN '$fi' AND '$ff'
        AND TRIM(v.telefono_cliente) <> ''
        $filtroSucursal
    ";
    $conn->query($sqlAutoBase);
  }
}

// Filtros
$wherePromo = $promoId>0 ? "AND rpc.id_promo=$promoId" : "";
$whereSuc   = ($ROL==='Admin' || $ROL==='Logistica') ? '' : "AND rpc.id_sucursal=$ID_SUCURSAL";

$q = trim($_GET['q'] ?? '');
$whereQ = $q!=='' ? "AND (rpc.telefono_cliente LIKE '%".$conn->real_escape_string($q)."%' OR rpc.nombre_cliente LIKE '%".$conn->real_escape_string($q)."%')" : '';

// Nombre de la sucursal del usuario (para mostrarlo bonito arriba cuando no es Admin/Logistica)
$sucursalNombreUsuario = null;
if (!in_array($ROL, ['Admin','Logistica'], true) && $ID_SUCURSAL > 0) {
  $rowSuc = $conn->query("SELECT nombre FROM sucursales WHERE id=".(int)$ID_SUCURSAL." LIMIT 1")->fetch_assoc();
  if ($rowSuc) $sucursalNombreUsuario = $rowSuc['nombre'];
}

// Stats (sin aplicar $whereQ)
$stats = ['total'=>0,'r1'=>0,'r2'=>0,'ambas'=>0];
if ($promoId > 0) {
  $rowStats = $conn->query("
    SELECT
      COUNT(*) AS total,
      SUM(CASE WHEN rec1_status='confirmada' THEN 1 ELSE 0 END) AS r1,
      SUM(CASE WHEN rec2_status='confirmada' THEN 1 ELSE 0 END) AS r2,
      SUM(CASE WHEN rec1_status='confirmada' AND rec2_status='confirmada' THEN 1 ELSE 0 END) AS ambas
    FROM recargas_promo_clientes rpc
    WHERE 1=1 $wherePromo $whereSuc
  ")->fetch_assoc();
  if ($rowStats) {
    $stats['total']=(int)$rowStats['total']; $stats['r1']=(int)$rowStats['r1'];
    $stats['r2']=(int)$rowStats['r2'];     $stats['ambas']=(int)$rowStats['ambas'];
  }
}
$pend_r1 = max(0, $stats['total'] - $stats['r1']);
$pend_r2 = max(0, $stats['total'] - $stats['r2']);
$done_pct_r1 = pct($stats['r1'], $stats['total']);
$done_pct_r2 = pct($stats['r2'], $stats['total']);
$ambas_pct   = pct($stats['ambas'], $stats['total']);

// Listado (✅ ahora incluye sucursal_nombre)
$sql = "
SELECT
  rpc.*,
  p.nombre AS promo_nombre, p.origen, p.fecha_inicio, p.fecha_fin,
  COALESCE(s.nombre, CONCAT('#', rpc.id_sucursal)) AS sucursal_nombre
FROM recargas_promo_clientes rpc
JOIN recargas_promo p ON p.id = rpc.id_promo
LEFT JOIN sucursales s ON s.id = rpc.id_sucursal
WHERE 1=1 $wherePromo $whereSuc $whereQ
ORDER BY rpc.id DESC
LIMIT 500
";
$rs = $conn->query($sql);
?>
<div class="container mt-3">
  <h3>Recargas Promocionales — Portal</h3>

  <?php if ($flash) echo $flash; ?>

  <form class="row g-2 mb-2">
    <div class="col-md-4">
      <label class="form-label">Promoción</label>
      <select name="promo" class="form-select" onchange="this.form.submit()">
        <option value="0">— Selecciona promoción activa —</option>
        <?php foreach($promos as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $promoId===$p['id']?'selected':'' ?>>
            <?= h($p['nombre'].' ('.$p['origen'].') '.$p['fecha_inicio'].'→'.$p['fecha_fin']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Buscar (nombre/teléfono)</label>
      <input class="form-control" name="q" value="<?= h($q) ?>">
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <button class="btn btn-outline-secondary w-100">Filtrar</button>
    </div>

    <?php if (in_array($ROL, ['Admin','Logistica'], true)): ?>
  <div class="col-md-3 d-flex align-items-end justify-content-end gap-2">
    <?php if ($promoId > 0): ?>
      <a
        class="btn btn-success"
        target="_blank" rel="noopener"
        href="export_recargas_csv.php?promo=<?= (int)$promoId ?>&status=all&q=<?= urlencode($q) ?>"
        title="Exporta la promo seleccionada"
      >
        Exportar promo
      </a>
    <?php endif; ?>

    <a
      class="btn btn-outline-dark"
      target="_blank" rel="noopener"
      href="export_recargas_csv.php?promo=all&status=all&q=<?= urlencode($q) ?>"
      title="Exporta TODOS los clientes de TODAS las promos (respeta permisos)"
    >
      Exportar TODO
    </a>
  </div>
<?php endif; ?>
  </form>

  <?php if ($promoId > 0 && $promo): ?>
    <!-- Cards -->
    <div class="row g-3 mb-3">
      <div class="col-12">
        <div class="text-muted small">
          <strong>Promo:</strong> <?= h($promo['nombre']) ?> (<?= h($promo['origen']) ?>) —
          <strong>Rango:</strong> <?= h($promo['fecha_inicio']) ?> → <?= h($promo['fecha_fin']) ?>
          <?php if (!in_array($ROL, ['Admin','Logistica'], true)): ?>
            — <strong>Sucursal:</strong> <?= h($sucursalNombreUsuario ?: ('#'.$ID_SUCURSAL)) ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
          <div class="d-flex justify-content-between align-items-center">
            <div><div class="text-muted small">Clientes en base</div><div class="h4 mb-0"><?= number_format($stats['total']) ?></div></div>
            <span class="badge bg-dark-subtle text-dark">Base</span>
          </div>
          <div class="progress mt-3" style="height:6px;"><div class="progress-bar" style="width:100%;"></div></div>
        </div></div>
      </div>

      <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="text-muted small">Recarga 1 confirmadas</div>
              <div class="h4 mb-0"><?= number_format($stats['r1']) ?></div>
              <div class="small text-muted"><?= $done_pct_r1 ?>% avance · Pendientes: <?= number_format($pend_r1) ?></div>
            </div>
            <span class="badge bg-success">R1</span>
          </div>
          <div class="progress mt-3" style="height:6px;"><div class="progress-bar bg-success" style="width:<?= $done_pct_r1 ?>%;"></div></div>
        </div></div>
      </div>

      <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="text-muted small">Recarga 2 confirmadas</div>
              <div class="h4 mb-0"><?= number_format($stats['r2']) ?></div>
              <div class="small text-muted"><?= $done_pct_r2 ?>% avance · Pendientes: <?= number_format($pend_r2) ?></div>
            </div>
            <span class="badge bg-primary">R2</span>
          </div>
          <div class="progress mt-3" style="height:6px;"><div class="progress-bar" style="width:<?= $done_pct_r2 ?>%;"></div></div>
        </div></div>
      </div>

      <div class="col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <div class="text-muted small">R1 + R2 confirmadas</div>
              <div class="h4 mb-0"><?= number_format($stats['ambas']) ?></div>
              <div class="small text-muted"><?= $ambas_pct ?>% del total</div>
            </div>
            <span class="badge bg-info text-dark">Ambas</span>
          </div>
          <div class="progress mt-3" style="height:6px;"><div class="progress-bar bg-info" style="width:<?= $ambas_pct ?>%;"></div></div>
        </div></div>
      </div>
    </div>
  <?php endif; ?>

  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>Cliente</th>
          <th>Teléfono</th>
          <th>Sucursal</th>
          <th>Venta</th>
          <th>R1</th>
          <th>R2</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php while($r = $rs->fetch_assoc()):
        $elig1 = elegible_dias($r['fecha_venta'], REC1_WAIT_DAYS);
        $elig2 = elegible_dias($r['fecha_venta'], REC2_WAIT_DAYS);

        if (!RECARGAS_ENFORCE_WAIT) {
          $badge1 = $r['rec1_status']==='confirmada' ? "<span class='badge bg-success'>Confirmada</span>" : "<span class='badge bg-primary'>Habilitada</span>";
          $badge2 = $r['rec2_status']==='confirmada' ? "<span class='badge bg-success'>Confirmada</span>" : "<span class='badge bg-primary'>Habilitada</span>";
        } else {
          $badge1 = $r['rec1_status']==='confirmada' ? "<span class='badge bg-success'>Confirmada</span>"
                   : ($elig1 ? "<span class='badge bg-primary'>Elegible ".(int)REC1_WAIT_DAYS."d</span>" : "<span class='badge bg-secondary'>Aún no (".(int)REC1_WAIT_DAYS."d)</span>");
          $badge2 = $r['rec2_status']==='confirmada' ? "<span class='badge bg-success'>Confirmada</span>"
                   : ($elig2 ? "<span class='badge bg-primary'>Elegible ".(int)REC2_WAIT_DAYS."d</span>" : "<span class='badge bg-secondary'>Aún no (".(int)REC2_WAIT_DAYS."d)</span>");
        }
      ?>
        <tr>
          <td><?= h($r['nombre_cliente']) ?></td>
          <td><?= h($r['telefono_cliente']) ?></td>
          <td><?= h($r['sucursal_nombre']) ?></td>
          <td><?= h($r['fecha_venta']) ?></td>
          <td><?= $badge1 ?></td>
          <td><?= $badge2 ?></td>
          <td>
            <div class="d-flex gap-2">
              <button
                class="btn btn-sm btn-outline-success"
                <?= ($r['rec1_status']==='confirmada' || (RECARGAS_ENFORCE_WAIT && !$elig1))?'disabled':'' ?>
                data-id="<?= (int)$r['id'] ?>" data-which="1"
                data-nombre="<?= h($r['nombre_cliente']) ?>"
                data-telefono="<?= h($r['telefono_cliente']) ?>"
                onclick="openUpload(this)">Confirmar R1</button>

              <button
                class="btn btn-sm btn-outline-success"
                <?= ($r['rec2_status']==='confirmada' || (RECARGAS_ENFORCE_WAIT && !$elig2))?'disabled':'' ?>
                data-id="<?= (int)$r['id'] ?>" data-which="2"
                data-nombre="<?= h($r['nombre_cliente']) ?>"
                data-telefono="<?= h($r['telefono_cliente']) ?>"
                onclick="openUpload(this)">Confirmar R2</button>

              <?php if ($r['rec1_comprobante_path']): ?>
                <a class="btn btn-sm btn-outline-info" target="_blank" href="<?= h($r['rec1_comprobante_path']) ?>">Ver R1</a>
              <?php endif; ?>
              <?php if ($r['rec2_comprobante_path']): ?>
                <a class="btn btn-sm btn-outline-info" target="_blank" href="<?= h($r['rec2_comprobante_path']) ?>">Ver R2</a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal de subida + confirmación -->
<div class="modal fade" id="uploadModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="recargas_confirmar.php" enctype="multipart/form-data" onsubmit="return validateUpload();">
      <div class="modal-header">
        <h5 class="modal-title">Confirmar recarga promocional</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="alert alert-warning border-2">
          <div class="fw-bold fs-6">⚠️ ¡Atención! Verifica antes de guardar</div>
          <ul class="mb-0 mt-2">
            <li>Aplica la recarga al <strong>cliente correcto</strong>.</li>
            <li>Confirma el <strong>número telefónico</strong> del cliente.</li>
            <li>Sube la <strong>captura de pantalla</strong> del operador.</li>
          </ul>
        </div>

        <div class="mb-2">
          <div class="small text-muted">Cliente</div>
          <div class="fw-semibold" id="confNombre">—</div>
        </div>
        <div class="mb-3">
          <div class="small text-muted">Teléfono</div>
          <div class="fw-semibold" id="confTelefono">—</div>
        </div>

        <input type="hidden" name="id_rpc" id="id_rpc">
        <input type="hidden" name="which" id="which">

        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="ackCheck">
          <label class="form-check-label" for="ackCheck">
            Confirmo que revisé nombre y número del cliente y la recarga se aplicó correctamente.
          </label>
        </div>

        <div class="mb-2">
          <label class="form-label">Comprobante (PNG/JPG/PDF, máx. 5MB)</label>
          <input type="file" name="comprobante" id="fileInput" class="form-control" accept=".png,.jpg,.jpeg,.pdf" required>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-primary" id="submitBtn" disabled>Confirmar</button>
      </div>
    </form>
  </div>
</div>

<script>
let uploadModal;

function openUpload(btn){
  const id     = btn.dataset.id;
  const which  = btn.dataset.which;
  const nombre = btn.dataset.nombre || '';
  const tel    = btn.dataset.telefono || '';

  document.getElementById('id_rpc').value = id;
  document.getElementById('which').value  = which;
  document.getElementById('confNombre').textContent   = nombre || '—';
  document.getElementById('confTelefono').textContent = tel || '—';

  // reset estado
  document.getElementById('ackCheck').checked = false;
  document.getElementById('fileInput').value  = '';
  document.getElementById('submitBtn').disabled = true;

  uploadModal = new bootstrap.Modal(document.getElementById('uploadModal'));
  uploadModal.show();
}

function validateUpload(){
  const ok = document.getElementById('ackCheck').checked && document.getElementById('fileInput').files.length > 0;
  if (!ok) return false;
  return true;
}

// Habilitar botón si cumple ambos requisitos
['ackCheck','fileInput'].forEach(id=>{
  document.addEventListener('change', function(e){
    if (e.target && e.target.id === id){
      const ready = document.getElementById('ackCheck').checked && document.getElementById('fileInput').files.length > 0;
      document.getElementById('submitBtn').disabled = !ready;
    }
  }, true);
});
</script>
