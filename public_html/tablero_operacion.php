<?php

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rol        = (string)($_SESSION['rol'] ?? '');
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

// Propiedad por session (Luga/Nano)
$propiedad = (string)($_SESSION['propiedad'] ?? $_SESSION['empresa'] ?? 'Luga');
$propiedad = ($propiedad === 'Nano' || $propiedad === 'Luga') ? $propiedad : 'Luga';

// Subdis opcional
$idSubdis = isset($_SESSION['id_subdis']) ? (int)$_SESSION['id_subdis'] : null;

// Nombre del usuario (para comentario automático)
$miNombre = '';
try {
  if ($idUsuario > 0) {
    $stMe = $conn->prepare("SELECT nombre FROM usuarios WHERE id=? LIMIT 1");
    $stMe->bind_param("i", $idUsuario);
    $stMe->execute();
    $me = $stMe->get_result()->fetch_assoc();
    $miNombre = $me['nombre'] ?? '';
    $stMe->close();
  }
} catch(Throwable $e){ $miNombre = ''; }

// Lista usuarios (para asignar responsable)
$usuarios = [];
try {
  $sqlU = "SELECT id, nombre, rol, id_sucursal FROM usuarios WHERE activo=1 ORDER BY nombre ASC";
  $resU = $conn->query($sqlU);
  while($row = $resU->fetch_assoc()){
    $usuarios[] = $row;
  }
} catch (Throwable $e) {
  $usuarios = [];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Tablero de Operación</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f6f7fb; }

    .board-wrap { gap: 12px; }

    .kanban-col {
      background: #fff;
      border: 1px solid rgba(0,0,0,.08);
      border-radius: 16px;
      min-height: 68vh;
      display:flex;
      flex-direction:column;
      overflow:hidden;
      box-shadow: 0 6px 18px rgba(0,0,0,.04);
    }

    .kanban-head{
      padding: 12px 14px;
      border-bottom: 1px solid rgba(0,0,0,.06);
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 10px;
      background: linear-gradient(180deg, #ffffff, #fbfbff);
    }

    .kanban-title{
      font-weight:700;
      font-size: .95rem;
      display:flex;
      align-items:center;
      gap: 8px;
      margin:0;
    }

    .kanban-badge{ font-size:.75rem; }

    .kanban-body{
      padding: 12px;
      overflow:auto;
      flex:1;
    }

    .task-card{
      background:#fff;
      border: 1px solid rgba(0,0,0,.08);
      border-radius: 14px;
      padding: 12px;
      margin-bottom: 10px;
      cursor: grab;
      transition: transform .08s ease, box-shadow .08s ease;
      user-select: none;
    }

    .task-card:active { cursor: grabbing; }

    .task-card:hover {
      transform: translateY(-1px);
      box-shadow: 0 10px 20px rgba(0,0,0,.06);
    }

    .task-title{
      font-weight:700;
      font-size:.95rem;
      margin:0 0 8px;
      color:#1f2937;
      line-height:1.25;
    }

    .task-meta{
      font-size:.82rem;
      color:#666;
      display:flex;
      flex-wrap:wrap;
      gap:8px;
    }

    .pill{
      border: 1px solid rgba(0,0,0,.1);
      border-radius: 999px;
      padding: 3px 9px;
      font-size: .75rem;
      background: #fafafa;
      color:#444;
      line-height: 1.15;
    }

    .task-desc{
      margin-top: 8px;
      font-size: .82rem;
      color:#6b7280;
      line-height: 1.35;
    }

    .task-participantes-box{
      margin-top: 10px;
      border-top: 1px solid rgba(0,0,0,.08);
      padding-top: 8px;
    }

    .task-participantes-title{
      font-size: .74rem;
      font-weight: 700;
      color: #6c757d;
      text-transform: uppercase;
      letter-spacing: .03em;
      margin-bottom: 6px;
    }

    .task-participantes-list{
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .task-participante-item{
      font-size: .82rem;
      color: #374151;
      line-height: 1.25;
      padding: 1px 0;
    }

    .dropzone-hover{
      outline: 2px dashed rgba(13,110,253,.45);
      outline-offset: -6px;
      background: rgba(13,110,253,.03);
    }

    .topbar{
      position: sticky;
      top: 0;
      z-index: 20;
      background: rgba(246,247,251,.92);
      backdrop-filter: blur(8px);
      border-bottom: 1px solid rgba(0,0,0,.06);
    }

    .filters .form-control,
    .filters .form-select {
      border-radius: 12px;
    }

    .btn-round{ border-radius: 12px; }

    @media (max-width: 991px){
      .kanban-col { min-height: 60vh; }
    }
  </style>
</head>
<body>
<?php
$__navCandidates = [
  __DIR__ . '/navbar.php',
  __DIR__ . '/includes/navbar.php',
  __DIR__ . '/partials/navbar.php',
  __DIR__ . '/layout/navbar.php',
];
foreach($__navCandidates as $__navFile){
  if (file_exists($__navFile)) { include $__navFile; break; }
}
?>

<div class="topbar">
  <div class="container-fluid py-3">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
      <div>
        <h4 class="mb-0 fw-bold">Tablero de Operación</h4>
        <div class="text-muted small">
          <?=h($propiedad)?> · Usuario #<?= (int)$idUsuario ?> · Rol: <?=h($rol)?>
        </div>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-primary btn-round" data-bs-toggle="modal" data-bs-target="#modalNueva">
          + Nueva tarea
        </button>
        <button class="btn btn-outline-secondary btn-round" id="btnRefrescar">Refrescar</button>
      </div>
    </div>

    <div class="row g-2 mt-2 filters">
      <div class="col-12 col-lg-3">
        <input type="text" class="form-control" id="fBuscar" placeholder="Buscar (título o descripción)">
      </div>

      <div class="col-6 col-lg-2">
        <select class="form-select" id="fPrioridad">
          <option value="">Prioridad (todas)</option>
          <option>Baja</option>
          <option>Media</option>
          <option>Alta</option>
          <option>Urgente</option>
        </select>
      </div>

      <div class="col-6 col-lg-2">
        <select class="form-select" id="fSolo">
          <option value="">Vista (default)</option>
          <option value="mias">Solo mías</option>
          <option value="asignadas">Asignadas a mí</option>
          <option value="vencidas">Vencidas</option>
        </select>
      </div>

      <div class="col-12 col-lg-3">
        <select class="form-select" id="fResponsable">
          <option value="">Responsable (todos)</option>
          <?php foreach($usuarios as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= h($u['nombre']) ?> (<?=h($u['rol'])?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-2">
        <select class="form-select" id="fVisibilidad">
          <option value="">Visibilidad (todas)</option>
          <option>Privada</option>
          <option>Sucursal</option>
          <option>Empresa</option>
        </select>
      </div>
    </div>
  </div>
</div>

<div class="container-fluid py-3">
  <div class="row g-3 board-wrap" id="board">
    <?php
      $cols = [
        'Pendiente'  => '📝',
        'En proceso' => '⚙️',
        'Bloqueado'  => '⛔',
        'Terminado'  => '✅',
      ];
      foreach($cols as $k => $ico):
    ?>
      <div class="col-12 col-lg-3">
        <div class="kanban-col" data-status="<?=h($k)?>">
          <div class="kanban-head">
            <p class="kanban-title"><?=h($ico)?> <?=h($k)?> <span class="badge text-bg-light kanban-badge" id="count-<?=h($k)?>">0</span></p>
            <span class="text-muted small" id="sum-<?=h($k)?>"></span>
          </div>
          <div class="kanban-body dropzone" data-status="<?=h($k)?>" id="col-<?=h($k)?>">
            <div class="text-muted small">Cargando…</div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Modal nueva tarea -->
<div class="modal fade" id="modalNueva" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" id="formNueva">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Nueva tarea</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>

      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">Título</label>
            <input type="text" class="form-control" name="titulo" required maxlength="255" placeholder="Ej: Reclutamiento sucursal Puebla">
          </div>

          <div class="col-12">
            <label class="form-label">Descripción</label>
            <textarea class="form-control" name="descripcion" rows="3" placeholder="Detalles, contexto, links…"></textarea>
          </div>

          <div class="col-12 col-lg-6">
            <label class="form-label">Responsable</label>
            <select class="form-select" name="id_responsable">
              <option value="">Sin asignar</option>
              <?php foreach($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= h($u['nombre']) ?> (<?=h($u['rol'])?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-lg-3">
            <label class="form-label">Prioridad</label>
            <select class="form-select" name="prioridad">
              <option>Media</option>
              <option>Baja</option>
              <option>Alta</option>
              <option>Urgente</option>
            </select>
          </div>

          <div class="col-6 col-lg-3">
            <label class="form-label">Visibilidad</label>
            <select class="form-select" name="visibilidad">
              <option>Privada</option>
              <option>Sucursal</option>
              <option>Empresa</option>
            </select>
            <div class="form-text">Privada: solo tú + responsable + watchers</div>
          </div>

          <div class="col-6 col-lg-4">
            <label class="form-label">Fecha inicio</label>
            <input type="date" class="form-control" name="fecha_inicio">
          </div>
          <div class="col-6 col-lg-4">
            <label class="form-label">Fecha estimada</label>
            <input type="date" class="form-control" name="fecha_estimada">
          </div>
          <div class="col-12 col-lg-4">
            <label class="form-label">Depende de (ID tarea)</label>
            <input type="number" class="form-control" name="depende_de" min="1" placeholder="Opcional">
          </div>

          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" value="1" id="chkNotif" name="notificar" checked>
              <label class="form-check-label" for="chkNotif">
                Notificar por correo (cuando asigno o comento)
              </label>
            </div>
          </div>

          <div class="col-12">
            <div class="alert alert-light border mb-0 small">
              Se guardará como <b><?=h($propiedad)?></b>
              <?php if ($idSubdis): ?> · Subdis: <b><?= (int)$idSubdis ?></b><?php endif; ?>
              · Sucursal: <b><?= (int)$idSucursal ?></b>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-round" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary btn-round" id="btnCrear">Crear tarea</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal bloqueo por drag -->
<div class="modal fade" id="modalBloqueoDrag" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-warning-subtle">
        <h5 class="modal-title fw-bold">Motivo de bloqueo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="text-muted small mb-2">Describe qué falta, de quién depende y si puedes una fecha estimada.</div>
        <textarea id="txtMotivoBloqueoDrag" class="form-control" rows="3"
          placeholder="Ej: Falta autorización del Gerente de Zona. ETA mañana 12pm."></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary btn-round" id="btnCancelBloqueoDrag" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-warning btn-round" id="btnConfirmBloqueoDrag">Guardar bloqueo</button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
  <div id="toastMsg" class="toast align-items-center text-bg-dark border-0" role="alert" aria-live="polite" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="toastBody">Listo</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const propiedad = <?= json_encode($propiedad) ?>;
const miNombre = <?= json_encode($miNombre) ?>;

function toast(msg){
  const el = document.getElementById('toastMsg');
  document.getElementById('toastBody').textContent = msg;
  const t = bootstrap.Toast.getOrCreateInstance(el, { delay: 2200 });
  t.show();
}

function qs(id){ return document.getElementById(id); }

function getFilters(){
  return {
    buscar: qs('fBuscar').value.trim(),
    prioridad: qs('fPrioridad').value,
    solo: qs('fSolo').value,
    responsable: qs('fResponsable').value,
    visibilidad: qs('fVisibilidad').value
  };
}

function esc(s){
  return (s ?? '').toString()
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}

function limpiarBackdropsYBodyModal(){
  document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
  document.body.classList.remove('modal-open');
  document.body.style.removeProperty('padding-right');
  document.body.style.removeProperty('overflow');
}

function normalizarParticipantes(t){
  if (Array.isArray(t.participantes)) {
    return t.participantes
      .map(x => (x ?? '').toString().trim())
      .filter(Boolean);
  }

  if (typeof t.participantes_nombres === 'string' && t.participantes_nombres.trim() !== '') {
    return t.participantes_nombres
      .split('|')
      .map(x => x.trim())
      .filter(Boolean);
  }

  return [];
}

function renderParticipantes(t){
  const participantes = normalizarParticipantes(t);
  if (!participantes.length) return '';

  return `
    <div class="task-participantes-box">
      <div class="task-participantes-title">Participantes</div>
      <div class="task-participantes-list">
        ${participantes.map(n => `<div class="task-participante-item">${esc(n)}</div>`).join('')}
      </div>
    </div>
  `;
}

function renderCard(t){
  const pri = t.prioridad || 'Media';
  const vis = t.visibilidad || 'Privada';
  const est = t.estatus || 'Pendiente';

  let dueTag = '';
  if (t.fecha_estimada){
    const today = new Date();
    const d = new Date(t.fecha_estimada + 'T00:00:00');
    const diffDays = Math.floor((d - new Date(today.getFullYear(), today.getMonth(), today.getDate())) / 86400000);

    if (diffDays < 0) dueTag = `<span class="pill">⏳ Vencida</span>`;
    else if (diffDays === 0) dueTag = `<span class="pill">⏳ Vence hoy</span>`;
    else if (diffDays <= 2) dueTag = `<span class="pill">⏳ ${diffDays}d</span>`;
  }

  let dep = '';
  if (t.depende_de){
    dep = `<span class="pill">🔒 Dep: #${esc(t.depende_de)}</span>`;
  }

  const resp = t.responsable_nombre
    ? `<span class="pill">👤 ${esc(t.responsable_nombre)}</span>`
    : `<span class="pill">👤 Sin asignar</span>`;

  const participantesHtml = renderParticipantes(t);

  return `
    <div class="task-card" draggable="true" data-id="${esc(t.id)}" data-status="${esc(est)}"
         onclick="if(!window.__isDragging){ window.location.href='tarea_detalle.php?id=${esc(t.id)}'; }">
      <div class="task-title">${esc(t.titulo)}</div>

      <div class="task-meta">
        <span class="pill">⚡ ${esc(pri)}</span>
        <span class="pill">👁 ${esc(vis)}</span>
      </div>

      <div class="task-meta mt-2">
        ${resp}
        ${t.fecha_estimada ? `<span class="pill">📅 ${esc(t.fecha_estimada)}</span>` : ``}
        ${dueTag}
        ${dep}
      </div>

      ${t.descripcion ? `<div class="task-desc">${esc(t.descripcion).slice(0,120)}${t.descripcion.length>120?'…':''}</div>` : ``}

      ${participantesHtml}
    </div>
  `;
}

function clearCols(){
  ['Pendiente','En proceso','Bloqueado','Terminado'].forEach(s => {
    qs('col-'+s).innerHTML = '';
    qs('count-'+s).textContent = '0';
    qs('sum-'+s).textContent = '';
  });
}

async function loadBoard(){
  clearCols();
  const f = getFilters();
  const params = new URLSearchParams(f);

  try {
    const r = await fetch('api_tablero_listar.php?' + params.toString(), { credentials:'same-origin' });
    const data = await r.json();

    if (!data.ok){
      ['Pendiente','En proceso','Bloqueado','Terminado'].forEach(s => {
        qs('col-'+s).innerHTML = `<div class="text-danger small">${esc(data.error || 'Error')}</div>`;
      });
      return;
    }

    const byStatus = { 'Pendiente':[], 'En proceso':[], 'Bloqueado':[], 'Terminado':[] };
    (data.items || []).forEach(t => { (byStatus[t.estatus] ||= []).push(t); });

    Object.keys(byStatus).forEach(st => {
      const col = qs('col-'+st);
      const items = byStatus[st] || [];
      col.innerHTML = items.length ? items.map(renderCard).join('') : `<div class="text-muted small">Sin tareas</div>`;
      qs('count-'+st).textContent = items.length.toString();
    });

    attachDnD();
  } catch (e) {
    ['Pendiente','En proceso','Bloqueado','Terminado'].forEach(s => {
      qs('col-'+s).innerHTML = `<div class="text-danger small">No se pudo cargar el tablero</div>`;
    });
  }
}

function attachDnD(){
  const cards = document.querySelectorAll('.task-card');
  const zones = document.querySelectorAll('.dropzone');

  let dragId = null;
  let fromStatus = null;
  window.__isDragging = false;

  cards.forEach(c => {
    c.addEventListener('dragstart', e => {
      window.__isDragging = true;
      dragId = c.dataset.id;
      fromStatus = c.dataset.status;

      e.dataTransfer.setData('text/plain', dragId);
      e.dataTransfer.effectAllowed = 'move';
    });

    c.addEventListener('dragend', () => {
      setTimeout(() => {
        window.__isDragging = false;
      }, 80);
    });
  });

  zones.forEach(z => {
    z.addEventListener('dragover', e => {
      e.preventDefault();
      z.classList.add('dropzone-hover');
      e.dataTransfer.dropEffect = 'move';
    });

    z.addEventListener('dragleave', () => z.classList.remove('dropzone-hover'));

    z.addEventListener('drop', async e => {
      e.preventDefault();
      z.classList.remove('dropzone-hover');

      const id = e.dataTransfer.getData('text/plain') || dragId;
      const newStatus = z.dataset.status;

      if (!id || !newStatus) return;
      if (fromStatus && newStatus === fromStatus) return;

      if (newStatus === 'Bloqueado') {
        window.__pendingMove = { id, newStatus };
        qs('txtMotivoBloqueoDrag').value = '';
        const modal = bootstrap.Modal.getOrCreateInstance(qs('modalBloqueoDrag'));
        modal.show();
        return;
      }

      const ok = await moverTarea(id, newStatus);
      if (!ok) return;

      toast('Actualizado');
      loadBoard();
    });
  });
}

async function moverTarea(id, estatus){
  const form = new FormData();
  form.append('id', id);
  form.append('estatus', estatus);

  try {
    const r = await fetch('api_tablero_mover.php', {
      method:'POST',
      body: form,
      credentials:'same-origin'
    });
    const j = await r.json();

    if (!j.ok){
      toast(j.error || 'No se pudo mover');
      loadBoard();
      return false;
    }

    return true;
  } catch (e) {
    toast('No se pudo mover');
    loadBoard();
    return false;
  }
}

qs('btnCancelBloqueoDrag').addEventListener('click', () => {
  window.__pendingMove = null;
  loadBoard();
});

qs('btnConfirmBloqueoDrag').addEventListener('click', async () => {
  const pend = window.__pendingMove;
  const motivo = qs('txtMotivoBloqueoDrag').value.trim();

  if (!pend || !pend.id){
    const modal = bootstrap.Modal.getInstance(qs('modalBloqueoDrag'));
    if (modal) modal.hide();
    limpiarBackdropsYBodyModal();
    loadBoard();
    return;
  }

  if (!motivo){
    toast('Escribe el motivo del bloqueo');
    return;
  }

  const ok = await moverTarea(pend.id, 'Bloqueado');
  if (!ok){
    window.__pendingMove = null;
    const modal = bootstrap.Modal.getInstance(qs('modalBloqueoDrag'));
    if (modal) modal.hide();
    limpiarBackdropsYBodyModal();
    loadBoard();
    return;
  }

  const who = (miNombre && miNombre.trim()) ? miNombre.trim() : 'Usuario';
  const comentarioAuto = `🔒 BLOQUEADO por ${who}: ${motivo}`;

  const fc = new FormData();
  fc.append('id_tarea', pend.id);
  fc.append('comentario', comentarioAuto);

  try {
    const rc = await fetch('api_tarea_comentar.php', {
      method:'POST',
      body: fc,
      credentials:'same-origin'
    });
    const jc = await rc.json();

    if (!jc.ok){
      toast(jc.error || 'Bloqueado, pero no se pudo guardar comentario');
    } else {
      toast('Bloqueo guardado');
    }
  } catch (e) {
    toast('Bloqueado, pero no se pudo guardar comentario');
  }

  window.__pendingMove = null;

  const modal = bootstrap.Modal.getInstance(qs('modalBloqueoDrag'));
  if (modal) modal.hide();

  setTimeout(() => {
    limpiarBackdropsYBodyModal();
  }, 250);

  loadBoard();
});

qs('formNueva').addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = qs('btnCrear');
  btn.disabled = true;

  try {
    const form = new FormData(e.target);

    const r = await fetch('api_tablero_guardar.php', {
      method:'POST',
      body: form,
      credentials:'same-origin'
    });

    const raw = await r.text();
    let j = null;

    try {
      j = JSON.parse(raw);
    } catch (parseErr) {
      console.error('Respuesta no JSON:', raw);
      toast('Backend devolvió algo inválido. Revisa consola.');
      btn.disabled = false;
      return;
    }

    console.log('Respuesta crear tarea:', j);

    btn.disabled = false;

    if (!j.ok){
      toast(j.error || 'No se pudo crear');
      return;
    }

    if (j.mail && j.mail.ok === false) {
      console.warn('La tarea se creó pero el mail falló:', j.mail);
    }

    toast('Tarea creada');

    e.target.reset();
    qs('chkNotif').checked = true;

    const modalEl = qs('modalNueva');
    const modal = bootstrap.Modal.getInstance(modalEl) || bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.hide();

    setTimeout(() => {
      limpiarBackdropsYBodyModal();
      loadBoard();
    }, 250);

  } catch (err) {
    console.error(err);
    btn.disabled = false;
    toast('No se pudo crear');
    limpiarBackdropsYBodyModal();
  }
});

['fBuscar','fPrioridad','fSolo','fResponsable','fVisibilidad'].forEach(id => {
  qs(id).addEventListener('input', () => {
    clearTimeout(window.__tbTimer);
    window.__tbTimer = setTimeout(loadBoard, 250);
  });

  qs(id).addEventListener('change', () => {
    clearTimeout(window.__tbTimer);
    window.__tbTimer = setTimeout(loadBoard, 150);
  });
});

qs('btnRefrescar').addEventListener('click', loadBoard);

// Carga inicial
loadBoard();
</script>

</body>
</html>