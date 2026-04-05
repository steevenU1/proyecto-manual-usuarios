<?php
// tickets_luga.php — Lista (todos los orígenes) + Modal de detalle/respuesta en la misma página
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','Logistica','Gerente','Ejecutivo']; // ajusta si quieres
if (!in_array($ROL, $ALLOWED, true)) { header("Location: 403.php"); exit(); }

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__.'/navbar.php')) require_once __DIR__.'/navbar.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
date_default_timezone_set('America/Mexico_City');

// CSRF para creación (modal nuevo)
if (empty($_SESSION['ticket_csrf_luga'])) {
  $_SESSION['ticket_csrf_luga'] = bin2hex(random_bytes(16));
}

// Flash
$flash_ok  = $_SESSION['flash_ok']  ?? '';
$flash_err = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

// Datos de sesión
$idUsuario   = (int)($_SESSION['id_usuario']  ?? 0);
$idSucursalU = (int)($_SESSION['id_sucursal'] ?? 0);
$nombreUser  = (string)($_SESSION['nombre']   ?? 'Usuario');

// ===== Filtros tabla =====
$estado     = $_GET['estado']     ?? '';
$prioridad  = $_GET['prioridad']  ?? '';
$origen     = $_GET['origen']     ?? '';        // (todos/NANO/LUGA/OTRO)
$sucursalId = (int)($_GET['sucursal'] ?? 0);
$q          = trim($_GET['q'] ?? '');
$since      = $_GET['since'] ?? (date('Y-m-01 00:00:00')); // inicio de mes

// Catálogo sucursales
$sucursales = [];
$qSuc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
if ($qSuc) { while($r=$qSuc->fetch_assoc()){ $sucursales[(int)$r['id']]=$r['nombre']; } }

// WHERE dinámico (TODOS orígenes)
$where = ["t.updated_at > ?"];
$args  = [$since];
$types = "s";

if ($estado !== '')     { $where[]="t.estado=?";            $args[]=$estado;     $types.="s"; }
if ($prioridad !== '')  { $where[]="t.prioridad=?";         $args[]=$prioridad;  $types.="s"; }
if ($origen !== '')     { $where[]="t.sistema_origen=?";    $args[]=$origen;     $types.="s"; }
if ($sucursalId > 0)    { $where[]="t.sucursal_origen_id=?";$args[]=$sucursalId; $types.="i"; }
if ($q !== '') {
  if (ctype_digit($q)) {
    $where[]="(t.id=?)"; $args[]=(int)$q; $types.="i";
  } else {
    $where[]="(t.asunto LIKE ?)"; $args[]="%{$q}%"; $types.="s";
  }
}

$sql = "
  SELECT t.id, t.asunto, t.estado, t.prioridad, t.sistema_origen,
         t.sucursal_origen_id, t.creado_por_id, t.created_at, t.updated_at
  FROM tickets t
  WHERE ".implode(' AND ', $where)."
  ORDER BY t.updated_at DESC
  LIMIT 200
";
$stmt = $conn->prepare($sql);
if (!$stmt) { die("Error prepare: ".$conn->error); }
$stmt->bind_param($types, ...$args);
$stmt->execute();
$res = $stmt->get_result();
$tickets = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

/* ===== Helper para pintar la pill de estado ===== */
function badge_estado($estado_raw){
  $txt = (string)$estado_raw;
  $norm = mb_strtolower($txt, 'UTF-8');
  $norm = str_replace('_',' ', $norm);
  $norm = preg_replace('/\s+/', ' ', trim($norm));

  // clase por estado
  $cls = 'abierto';
  if ($norm === 'abierto')              $cls = 'abierto';
  elseif ($norm === 'en progreso' || $norm === 'en proceso') $cls = 'enproceso';
  elseif ($norm === 'en espera' )       $cls = 'espera';
  elseif ($norm === 'resuelto')         $cls = 'resuelto';
  elseif ($norm === 'cerrado')          $cls = 'cerrado';
  elseif ($norm === 'cancelado')        $cls = 'cancelado';

  // label bonito (capitaliza primera)
  $label = mb_strtoupper(mb_substr($norm,0,1,'UTF-8'),'UTF-8') . mb_substr($norm,1,null,'UTF-8');
  return '<span class="badge-estado '.$cls.'">'.h($label).'</span>';
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Tickets (LUGA)</title>
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
<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Tickets (LUGA)</h1>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNuevo">➕ Nuevo ticket</button>
      <a class="btn btn-outline-secondary" href="?since=<?=h(date('Y-m-d 00:00:00'))?>">Hoy</a>
      <a class="btn btn-outline-secondary" href="tickets_luga.php">Todos</a>
    </div>
  </div>

  <?php if ($flash_ok): ?><div class="alert alert-success"><?=h($flash_ok)?></div><?php endif; ?>
  <?php if ($flash_err): ?><div class="alert alert-danger"><?=h($flash_err)?></div><?php endif; ?>

  <!-- Filtros -->
  <form class="row g-2 mb-3" method="get">
    <div class="col-md-2">
      <select name="estado" class="form-select" title="Estado">
        <option value="">Estado (todos)</option>
        <?php foreach (['abierto','en_progreso','resuelto','cerrado'] as $e): ?>
          <option value="<?=$e?>" <?=$estado===$e?'selected':''?>><?=$e?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="prioridad" class="form-select" title="Prioridad">
        <option value="">Prioridad (todas)</option>
        <?php foreach (['baja','media','alta','critica'] as $p): ?>
          <option value="<?=$p?>" <?=$prioridad===$p?'selected':''?>><?=$p?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <select name="origen" class="form-select" title="Origen">
        <option value="">Origen (todos)</option>
        <?php foreach (['NANO','LUGA','OTRO'] as $o): ?>
          <option value="<?=$o?>" <?=$origen===$o?'selected':''?>><?=$o?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select name="sucursal" class="form-select" title="Sucursal origen">
        <option value="0">Sucursal (todas)</option>
        <?php foreach ($sucursales as $id=>$nom): ?>
          <option value="<?=$id?>" <?=$sucursalId===$id?'selected':''?>><?=h($nom)?> (<?=$id?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <div class="d-flex gap-2">
        <input name="q" class="form-control" placeholder="Buscar asunto o #ID" value="<?=h($q)?>">
        <input name="since" type="datetime-local" class="form-control"
               value="<?=h(str_replace(' ','T',$since))?>" title="Desde (updated)">
      </div>
    </div>
    <div class="col-12 d-grid d-md-flex justify-content-md-end mt-2">
      <button class="btn btn-secondary">Filtrar</button>
    </div>
  </form>

  <!-- Tabla -->
  <div class="card shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm align-middle m-0" id="tblTickets">
          <thead class="table-light">
            <tr>
              <th style="width:70px">#</th>
              <th>Asunto</th>
              <th>Estado</th>
              <th>Prioridad</th>
              <th>Origen</th>
              <th>Sucursal</th>
              <th style="width:170px">Actualizado</th>
              <th style="width:110px"></th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$tickets): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">Sin resultados.</td></tr>
          <?php endif; ?>
          <?php foreach ($tickets as $t): ?>
            <tr>
              <td><?=h($t['id'])?></td>
              <td><?=h($t['asunto'])?></td>
              <td><?=badge_estado($t['estado'])?></td>
              <td><?=h($t['prioridad'])?></td>
              <td><?=h($t['sistema_origen'])?></td>
              <td><?=h($sucursales[(int)$t['sucursal_origen_id']] ?? '')?></td>
              <td><?=h($t['updated_at'])?></td>
              <td>
                <button class="btn btn-sm btn-outline-primary btnAbrir" data-id="<?=h($t['id'])?>">Abrir</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="text-muted small mt-2">Mostrando <?=count($tickets)?> tickets · Desde: <?=h($since)?></div>
</div>

<!-- Modal contenedor (se carga HTML por AJAX) -->
<div class="modal fade" id="modalDetalle" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content" id="modalDetalleContent">
      <!-- contenido dinámico -->
    </div>
  </div>
</div>

<!-- =========================
     MODAL: Nuevo ticket (LUGA)
     * No se cierra por clic afuera ni por ESC *
========================= -->
<div class="modal fade" id="modalNuevo" tabindex="-1" aria-hidden="true"
     data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" action="tickets_guardar_luga.php" id="formTicketNuevo" novalidate>
        <div class="modal-header">
          <h5 class="modal-title">Nuevo ticket</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf" value="<?=h($_SESSION['ticket_csrf_luga'])?>">

          <div class="mb-3">
            <label class="form-label">Asunto <span class="text-danger">*</span></label>
            <input type="text" name="asunto" class="form-control" maxlength="255" required placeholder="Ej. Alta de usuario en sistema X">
            <div class="invalid-feedback">Escribe el asunto.</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Mensaje inicial <span class="text-danger">*</span></label>
            <textarea name="mensaje" class="form-control" rows="5" required placeholder="Describe el problema o solicitud con detalle."></textarea>
            <div class="invalid-feedback">Escribe el detalle del ticket.</div>
          </div>

          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Prioridad</label>
              <select name="prioridad" class="form-select">
                <option value="media" selected>Media</option>
                <option value="baja">Baja</option>
                <option value="alta">Alta</option>
                <option value="critica">Crítica</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Sucursal origen</label>
              <select name="sucursal_origen_id" class="form-select">
                <?php foreach ($sucursales as $id=>$nom): ?>
                  <option value="<?=$id?>" <?=$id==$idSucursalU?'selected':''?>><?=h($nom)?> (<?=$id?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Usuario</label>
              <input type="text" class="form-control" value="<?=h($nombreUser)?>" disabled>
              <div class="form-text">ID: <?=h((string)$idUsuario)?></div>
            </div>
          </div>
        </div>
        <div class="modal-footer d-flex justify-content-between">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" id="btnEnviarNuevo" type="submit">Crear ticket</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"> -->
<script>
(function(){
  // Validación "Nuevo ticket"
  const formNuevo = document.getElementById('formTicketNuevo');
  const btnNuevo  = document.getElementById('btnEnviarNuevo');
  if (formNuevo && btnNuevo) {
    formNuevo.addEventListener('submit', function(e){
      if (!formNuevo.checkValidity()) { e.preventDefault(); e.stopPropagation(); formNuevo.classList.add('was-validated'); return; }
      btnNuevo.disabled = true; btnNuevo.textContent = 'Guardando...';
    });
  }

  // Abrir detalle en modal (contenido vía fetch HTML)
  document.querySelectorAll('.btnAbrir').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const id = btn.getAttribute('data-id');
      const content = document.getElementById('modalDetalleContent');
      const modalEl = document.getElementById('modalDetalle');
      const modal = new bootstrap.Modal(modalEl);

      content.innerHTML =
        '<div class="modal-header"><h5 class="modal-title">Cargando...</h5>' +
        '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
        '<div class="modal-body"><div class="text-center text-muted py-5">Cargando detalle del ticket #' + id + '...</div></div>';

      modal.show();

      try {
        const res = await fetch('tickets_modal_luga.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' });
        const html = await res.text();
        content.innerHTML = html;

        // Wire formularios internos del modal (responder / cambiar estado)
        wireModalForms();
      } catch (e) {
        content.innerHTML =
          '<div class="modal-header"><h5 class="modal-title">Error</h5>' +
          '<button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>' +
          '<div class="modal-body"><div class="alert alert-danger">No se pudo cargar el detalle del ticket.</div></div>';
      }
    });
  });

  // Función para enchufar eventos dentro del modal cargado
  window.wireModalForms = function(){
    const fr = document.getElementById('formResponder');
    if (fr) {
      const btn = fr.querySelector('button[type="submit"]');
      fr.addEventListener('submit', ()=>{ if (btn){ btn.disabled = true; btn.textContent = 'Enviando...'; } });
    }
    const fe = document.getElementById('formEstado');
    if (fe) {
      const btn = fe.querySelector('button[type="submit"]');
      fe.addEventListener('submit', ()=>{ if (btn){ btn.disabled = true; btn.textContent = 'Guardando...'; } });
    }
  };

  // Auto-refresh de la tabla cada 90s (no recarga si ALGÚN modal está abierto)
  setInterval(()=>{
    const m1 = document.getElementById('modalDetalle');
    const m2 = document.getElementById('modalNuevo');
    const visible1 = m1 && m1.classList.contains('show');
    const visible2 = m2 && m2.classList.contains('show');
    if (!visible1 && !visible2) location.reload();
  }, 90000);
})();
</script>
</body>
</html>
