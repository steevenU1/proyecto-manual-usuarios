<?php
// tickets_modal_luga.php — Renderiza el contenido HTML del modal de detalle (LUGA)
session_start();
if (!isset($_SESSION['id_usuario'])) { http_response_code(403); exit(''); }

$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','Logistica','Gerente','Ejecutivo'];
if (!in_array($ROL, $ALLOWED, true)) { http_response_code(403); exit(''); }

require_once __DIR__.'/db.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
date_default_timezone_set('America/Mexico_City');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(422); ?>
<div class="modal-header">
  <h5 class="modal-title">ID inválido</h5>
  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body"><div class="alert alert-danger">El ID proporcionado no es válido.</div></div>
<?php exit; }

// Leer ticket
$stmt = $conn->prepare("SELECT id, asunto, estado, prioridad, sistema_origen, sucursal_origen_id, creado_por_id, created_at, updated_at
                        FROM tickets WHERE id=? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$t = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$t) { ?>
<div class="modal-header">
  <h5 class="modal-title">Ticket no encontrado</h5>
  <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body"><div class="alert alert-warning">No existe el ticket solicitado.</div></div>
<?php exit; }

// Sucursal nombre
$nomSucursal = '';
$qs = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
$qs->bind_param('i', $t['sucursal_origen_id']);
$qs->execute(); $rs = $qs->get_result()->fetch_assoc(); $qs->close();
if ($rs) $nomSucursal = $rs['nombre'];

// Mensajes
$mens = [];
$ms = $conn->prepare("SELECT id, autor_sistema, autor_id, cuerpo, created_at
                      FROM ticket_mensajes WHERE ticket_id=? ORDER BY id ASC");
$ms->bind_param('i', $id);
$ms->execute();
$rm = $ms->get_result();
if ($rm) { while($row = $rm->fetch_assoc()) $mens[]=$row; }
$ms->close();

// Estados válidos
$estados = ['abierto','en_progreso','resuelto','cerrado'];
?>
<div class="modal-header">
  <h5 class="modal-title">Ticket #<?=h($t['id'])?></h5>
  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
</div>
<div class="modal-body">
  <div class="row g-3">
    <div class="col-12">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="small text-muted">
            Origen: <strong><?=h($t['sistema_origen'])?></strong> ·
            Sucursal: <?=h($nomSucursal)?> (<?=h((string)$t['sucursal_origen_id'])?>)
          </div>
          <div class="fs-5 fw-semibold mt-1"><?=h($t['asunto'])?></div>
          <div class="small text-muted">Creado: <?=h($t['created_at'])?> · Actualizado: <?=h($t['updated_at'])?></div>
        </div>
        <div>
          <span class="badge bg-secondary"><?=h($t['estado'])?></span>
          <span class="badge bg-info ms-1"><?=h($t['prioridad'])?></span>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="border rounded p-2 bg-light" style="max-height:45vh; overflow:auto">
        <?php if (!$mens): ?>
          <div class="text-muted">Sin mensajes.</div>
        <?php else: foreach ($mens as $m): ?>
          <div class="mb-3">
            <div class="small text-muted">
              <?=h($m['autor_sistema'])?> • <?=h($m['created_at'])?>
              <?php if(!empty($m['autor_id'])):?> • Usuario ID: <?=h($m['autor_id'])?><?php endif;?>
            </div>
            <div><?=nl2br(h($m['cuerpo']))?></div>
          </div>
          <hr class="my-1">
        <?php endforeach; endif; ?>
      </div>
    </div>

    <div class="col-12">
      <form class="d-flex gap-2 align-items-end" id="formEstado" method="post" action="tickets_cambiar_estado.php">
        <input type="hidden" name="ticket_id" value="<?=h($t['id'])?>">
        <!-- <div class="flex-grow-1">
          <label class="form-label">Estado</label>
          <select name="estado" class="form-select">
            <?php foreach ($estados as $e): $sel = ($e===$t['estado'])?'selected':''; ?>
              <option value="<?=$e?>" <?=$sel?>><?=$e?></option>
            <?php endforeach; ?>
          </select>
        </div> -->
        <!-- <button class="btn btn-outline-secondary">Cambiar</button> -->
      </form>
    </div>

    <div class="col-12">
      <form id="formResponder" method="post" action="tickets_responder_luga.php">
        <input type="hidden" name="ticket_id" value="<?=h($t['id'])?>">
        <div class="mb-2">
          <label class="form-label">Responder</label>
          <textarea name="mensaje" class="form-control" rows="3" required></textarea>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-primary">Enviar</button>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<div class="modal-footer">
  <div class="text-muted small">#<?=h($t['id'])?> · Origen: <?=h($t['sistema_origen'])?> · Última actualización: <?=h($t['updated_at'])?></div>
</div>
