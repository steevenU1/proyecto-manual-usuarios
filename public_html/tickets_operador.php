<?php
// tickets_operador.php — Vista operador LUGA (lista + detalle + responder)
// ✅ Ahora por defecto muestra PENDIENTES históricos (abierto/en_progreso/en_espera)
// ✅ Filtro "Desde" opcional (si vacío, no filtra por fecha)
// ✅ Búsqueda por #ID exacto o por asunto
// ✅ Botones rápidos: Pendientes, Todos, Hoy, Este mes
// ✅ Bootstrap bundle habilitado (offcanvas)

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','Logistica','Gerente'];
if (!in_array($ROL, $ALLOWED, true)) { header("Location: 403.php"); exit(); }

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__.'/navbar.php')) require_once __DIR__.'/navbar.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
date_default_timezone_set('America/Mexico_City');

/* ===== Helper: badge de estado con color ===== */
function badge_estado($estado_raw){
  $txt  = (string)($estado_raw ?? '');
  $norm = mb_strtolower(str_replace('_',' ', trim($txt)), 'UTF-8');
  $cls  = 'abierto';
  if ($norm === 'abierto') $cls = 'abierto';
  elseif ($norm === 'en progreso' || $norm === 'en proceso' || $norm === 'en_progreso') $cls = 'enproceso';
  elseif ($norm === 'en espera' || $norm === 'en_espera') $cls = 'espera';
  elseif ($norm === 'resuelto') $cls = 'resuelto';
  elseif ($norm === 'cerrado') $cls = 'cerrado';
  elseif ($norm === 'cancelado') $cls = 'cancelado';

  $label = $txt;
  if (strpos($label, '_') !== false) {
    $label = str_replace('_',' ', $label);
    $label = mb_strtoupper(mb_substr($label,0,1),'UTF-8').mb_substr($label,1,null,'UTF-8');
  }
  return '<span class="badge-estado '.$cls.'">'.h($label).'</span>';
}

/* =======================
   Filtros
   ======================= */
$estado     = $_GET['estado']     ?? '';
$prioridad  = $_GET['prioridad']  ?? '';
$origen     = $_GET['origen']     ?? ''; // 'NANO','LUGA','OTRO' o ''
$sucursalId = (int)($_GET['sucursal'] ?? 0);
$q          = trim($_GET['q'] ?? '');

// ✅ Desde ahora es opcional. Si viene vacío, no filtramos por fecha.
$sinceRaw = $_GET['since'] ?? '';
$since = trim((string)$sinceRaw);

// ✅ Vista por defecto: pendientes históricos
// Si el usuario no manda estado y tampoco pidió "Todos", mostramos pendientes.
$quick = $_GET['quick'] ?? ''; // 'pendientes' | 'todos' | '' (default)
if ($quick === 'todos') {
  // no forzamos estado
} elseif ($quick === 'pendientes' || ($quick === '' && $estado === '')) {
  // Forzamos pendientes si no eligieron estado
  $estado = '__PENDIENTES__';
}

/* =======================
   WHERE dinámico
   ======================= */
$where = ["1=1"];
$args  = [];
$types = "";

// Fecha (opcional)
if ($since !== '') {
  // Normaliza: viene tipo "2026-01-11T00:00" por datetime-local
  $sinceNorm = str_replace('T', ' ', $since);
  if (strlen($sinceNorm) === 16) $sinceNorm .= ':00'; // agrega segundos si faltan
  $where[] = "t.updated_at >= ?";
  $args[]  = $sinceNorm;
  $types  .= "s";
}

// Estado
if ($estado !== '') {
  if ($estado === '__PENDIENTES__') {
    $where[] = "t.estado IN ('abierto','en_progreso','en_espera')";
  } else {
    $where[] = "t.estado = ?";
    $args[]  = $estado;
    $types  .= "s";
  }
}

// Prioridad
if ($prioridad !== '')  {
  $where[] = "t.prioridad = ?";
  $args[]  = $prioridad;
  $types  .= "s";
}

// Origen
if ($origen !== '')     {
  $where[] = "t.sistema_origen = ?";
  $args[]  = $origen;
  $types  .= "s";
}

// Sucursal
if ($sucursalId > 0)    {
  $where[] = "t.sucursal_origen_id = ?";
  $args[]  = $sucursalId;
  $types  .= "i";
}

// Búsqueda: si es número, intenta ID exacto; si no, asunto LIKE
if ($q !== '') {
  if (ctype_digit($q)) {
    $where[] = "(t.id = ? OR t.asunto LIKE ?)";
    $args[]  = (int)$q;           $types .= "i";
    $args[]  = "%{$q}%";          $types .= "s";
  } else {
    $where[] = "(t.asunto LIKE ?)";
    $args[]  = "%{$q}%";
    $types  .= "s";
  }
}

$LIMIT = 1000;

$sql = "
  SELECT t.id, t.asunto, t.estado, t.prioridad, t.sistema_origen,
         t.sucursal_origen_id, t.creado_por_id, t.created_at, t.updated_at
  FROM tickets t
  WHERE ".implode(' AND ', $where)."
  ORDER BY
    CASE t.estado
      WHEN 'abierto' THEN 1
      WHEN 'en_progreso' THEN 2
      WHEN 'en_espera' THEN 3
      WHEN 'resuelto' THEN 4
      WHEN 'cerrado' THEN 5
      WHEN 'cancelado' THEN 6
      ELSE 9
    END,
    t.updated_at DESC
  LIMIT {$LIMIT}
";

$stmt = $conn->prepare($sql);
if ($stmt === false) { die("Error prepare: ".$conn->error); }

if ($types !== "") {
  $stmt->bind_param($types, ...$args);
}

$stmt->execute();
$res = $stmt->get_result();
$tickets = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

// Map de sucursales (opcional)
$sucursales = [];
$qSuc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
if ($qSuc) { while($r=$qSuc->fetch_assoc()){ $sucursales[(int)$r['id']]=$r['nombre']; } }

// Flash
$flash_ok  = $_SESSION['flash_ok']  ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

// Helper para valor datetime-local
function to_datetime_local($dt){
  $dt = trim((string)$dt);
  if ($dt === '') return '';
  return str_replace(' ','T', substr($dt,0,16)); // YYYY-mm-ddTHH:ii
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Operador de Tickets (LUGA)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  /* Pills de estado */
  .badge-estado{font-weight:600;padding:.35rem .55rem;border-radius:50rem;font-size:.78rem;display:inline-block}
  .badge-estado.abierto{background:#0d6efd;color:#fff}
  .badge-estado.enproceso{background:#ffc107;color:#212529}
  .badge-estado.espera{background:#6c757d;color:#fff}
  .badge-estado.resuelto{background:#198754;color:#fff}
  .badge-estado.cerrado{background:#212529;color:#fff}
  .badge-estado.cancelado{background:#dc3545;color:#fff}
</style>
</head>
<body class="bg-light">
<div class="container-fluid py-3">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Operador de Tickets</h1>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
      <a class="btn btn-outline-primary" href="?quick=pendientes">Pendientes</a>
      <a class="btn btn-outline-secondary" href="?quick=todos">Todos</a>
      <a class="btn btn-outline-secondary" href="?since=<?=h(date('Y-m-d 00:00:00'))?>">Hoy</a>
      <a class="btn btn-outline-secondary" href="?since=<?=h(date('Y-m-01 00:00:00'))?>">Este mes</a>
    </div>
  </div>

  <?php if ($flash_ok): ?><div class="alert alert-success"><?=h($flash_ok)?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="alert alert-danger"><?=h($flash_err)?></div><?php endif; ?>

  <!-- Filtros -->
  <form class="row g-2 mb-3" method="get">
    <input type="hidden" name="quick" value="<?=h($quick)?>">
    <div class="col-md-2">
      <label class="form-label">Estado</label>
      <select name="estado" class="form-select">
        <option value="" <?=($estado===''||$estado==='__PENDIENTES__')?'selected':''?>>(pendientes)</option>
        <?php foreach (['abierto','en_progreso','en_espera','resuelto','cerrado','cancelado'] as $e): ?>
          <option value="<?=$e?>" <?=$estado===$e?'selected':''?>><?=$e?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text">Vacío = pendientes</div>
    </div>
    <div class="col-md-2">
      <label class="form-label">Prioridad</label>
      <select name="prioridad" class="form-select">
        <option value="">(todas)</option>
        <?php foreach (['baja','media','alta','critica'] as $p): ?>
          <option value="<?=$p?>" <?=$prioridad===$p?'selected':''?>><?=$p?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Origen</label>
      <select name="origen" class="form-select">
        <option value="">(todos)</option>
        <?php foreach (['NANO','LUGA','OTRO'] as $o): ?>
          <option value="<?=$o?>" <?=$origen===$o?'selected':''?>><?=$o?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Sucursal origen</label>
      <select name="sucursal" class="form-select">
        <option value="0">(todas)</option>
        <?php foreach ($sucursales as $id=>$nom): ?>
          <option value="<?=$id?>" <?=$sucursalId===$id?'selected':''?>><?=h($nom)?> (<?=$id?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Buscar / Desde</label>
      <div class="d-flex gap-2">
        <input name="q" class="form-control" placeholder="Asunto o #ID" value="<?=h($q)?>">
        <input name="since" type="datetime-local" class="form-control"
               value="<?=h(to_datetime_local($since))?>" title="Desde updated (opcional)">
      </div>
      <div class="form-text">Si “Desde” va vacío, muestra histórico.</div>
    </div>
    <div class="col-12 d-grid d-md-flex justify-content-md-end mt-2">
      <button class="btn btn-primary">Aplicar filtros</button>
    </div>
  </form>

  <!-- Tabla -->
  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm align-middle m-0">
          <thead class="table-light">
            <tr>
              <th style="width:70px">#</th>
              <th>Asunto</th>
              <th>Estado</th>
              <th>Prioridad</th>
              <th>Origen</th>
              <th>Sucursal</th>
              <th style="width:170px">Actualizado</th>
              <th style="width:220px">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$tickets): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">Sin tickets con estos filtros.</td></tr>
          <?php endif; ?>
          <?php foreach ($tickets as $t): ?>
            <tr>
              <td><?=h($t['id'])?></td>
              <td><?=h($t['asunto'])?></td>
              <td><?=badge_estado($t['estado'] ?? '')?></td>
              <td><?=h($t['prioridad'])?></td>
              <td><?=h($t['sistema_origen'])?></td>
              <td><?=h($sucursales[(int)$t['sucursal_origen_id']] ?? '')?></td>
              <td><?=h($t['updated_at'])?></td>
              <td class="d-flex gap-1 flex-wrap">
                <button class="btn btn-sm btn-outline-primary"
                        data-bs-toggle="offcanvas"
                        data-bs-target="#oc<?=h($t['id'])?>">Abrir</button>

                <!-- Cambio rápido de estado -->
                <form method="post" action="tickets_cambiar_estado.php" class="d-inline">
                  <input type="hidden" name="ticket_id" value="<?=h($t['id'])?>">
                  <select name="estado" class="form-select form-select-sm d-inline-block" style="width:140px">
                    <?php
                      $estados = ['abierto','en_progreso','en_espera','resuelto','cerrado','cancelado'];
                      foreach ($estados as $e) {
                        $sel = ($e === ($t['estado'] ?? '')) ? 'selected' : '';
                        echo "<option value=\"{$e}\" {$sel}>{$e}</option>";
                      }
                    ?>
                  </select>
                  <button class="btn btn-sm btn-outline-secondary">Cambiar</button>
                </form>
              </td>
            </tr>

            <!-- Offcanvas detalle -->
            <div class="offcanvas offcanvas-end" tabindex="-1" id="oc<?=h($t['id'])?>" aria-labelledby="lbl<?=h($t['id'])?>">
              <div class="offcanvas-header">
                <h5 id="lbl<?=h($t['id'])?>">Ticket #<?=h($t['id'])?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
              </div>
              <div class="offcanvas-body">
                <?php
                  // Traer conversación
                  $mens = [];
                  $ms = $conn->prepare("SELECT id, autor_sistema, autor_id, cuerpo, created_at
                                          FROM ticket_mensajes WHERE ticket_id=? ORDER BY id ASC");
                  $idT = (int)$t['id'];
                  $ms->bind_param('i',$idT); $ms->execute();
                  $rms = $ms->get_result();
                  if ($rms) { while($row = $rms->fetch_assoc()) $mens[]=$row; }
                  $ms->close();
                ?>

                <div class="mb-2 small text-muted">
                  Estado: <?=badge_estado($t['estado'] ?? '')?> ·
                  Prioridad: <span class="badge bg-info"><?=h($t['prioridad'])?></span> ·
                  Origen: <strong><?=h($t['sistema_origen'])?></strong><br>
                  Creado: <?=h($t['created_at'])?> · Actualizado: <?=h($t['updated_at'])?>
                </div>
                <div class="fw-semibold mb-2"><?=h($t['asunto'])?></div>

                <div class="border rounded p-2 bg-light" style="max-height:45vh; overflow:auto">
                  <?php if (!$mens): ?>
                    <div class="text-muted">Sin mensajes.</div>
                  <?php else: foreach ($mens as $m): ?>
                    <div class="mb-3">
                      <div class="small text-muted">
                        <?=h($m['autor_sistema'])?> • <?=h($m['created_at'])?>
                        <?php if (!empty($m['autor_id'])): ?> • Usuario ID: <?=h($m['autor_id'])?><?php endif; ?>
                      </div>
                      <div><?=nl2br(h($m['cuerpo']))?></div>
                    </div>
                    <hr class="my-1">
                  <?php endforeach; endif; ?>
                </div>

                <form class="mt-3" method="post" action="tickets_responder_luga.php">
                  <input type="hidden" name="ticket_id" value="<?=h($t['id'])?>">
                  <div class="mb-2">
                    <label class="form-label">Responder</label>
                    <textarea name="mensaje" class="form-control" rows="3" required></textarea>
                  </div>
                  <div class="d-flex gap-2">
                    <button class="btn btn-primary">Enviar</button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="offcanvas">Cerrar</button>
                  </div>
                </form>

              </div>
            </div>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="text-muted small mt-2">
    Mostrando <?=count($tickets)?> tickets (máx <?=$LIMIT?>) ·
    <?=($since!=='') ? ('Desde: '.h(str_replace('T',' ',$since))) : 'Histórico: sin filtro de fecha'?>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
// Auto-refresh suave cada 90s
setTimeout(()=>location.reload(), 90000);
</script>
</body>
</html>
