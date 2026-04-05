<?php
// tickets.create.php — Crea ticket + primer mensaje (emisor: NANO / MIPLAN / LUGA vía token)
// + NOTIFICA POR CORREO (solo inter-centrales)
// + Usa autor_nombre y sucursal_nombre si vienen (para correos legibles)

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/mail_hostinger.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_json.php';

date_default_timezone_set('America/Mexico_City');

function htxt($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function clean_label(string $s, int $max=80): string {
  $s = trim($s);
  $s = preg_replace('/\s+/', ' ', $s);
  if (mb_strlen($s, 'UTF-8') > $max) $s = mb_substr($s, 0, $max, 'UTF-8');
  return $s;
}

$origen = require_origen(['NANO','MIPLAN','LUGA']); // token define el origen

$in         = json_input();
$asunto     = trim(req_str($in, 'asunto', ''));
$mensaje    = trim(req_str($in, 'mensaje', ''));
$prioridad  = strtolower(trim(req_str($in, 'prioridad', 'media')));
$sucId      = req_int($in, 'sucursal_origen_id', 0);

// Compat: algunos clientes envían 'creado_por_id', otros 'autor_id'
$usrIdBody1 = req_int($in, 'creado_por_id', 0);
$usrIdBody2 = req_int($in, 'autor_id', 0);
$usrId      = $usrIdBody2 ?: $usrIdBody1;

// ✅ Nombres opcionales (vienen de la central origen)
$autorNombre    = clean_label((string)req_str($in, 'autor_nombre', ''), 80);
$sucursalNombre = clean_label((string)req_str($in, 'sucursal_nombre', ''), 80);

// Validaciones base
if ($asunto === '' || $mensaje === '') respond(['ok'=>false,'error'=>'asunto_y_mensaje_requeridos'], 422);
if ($sucId <= 0) respond(['ok'=>false,'error'=>'sucursal_invalida'], 422);

$allowPrior = ['baja','media','alta','critica'];
if (!in_array($prioridad, $allowPrior, true)) $prioridad = 'media';

if (mb_strlen($asunto, 'UTF-8') > 255)   $asunto  = mb_substr($asunto, 0, 255, 'UTF-8');
if (mb_strlen($mensaje,'UTF-8') > 4000)  $mensaje = mb_substr($mensaje,0, 4000,'UTF-8');

$ticketId = 0;

$conn->begin_transaction();
try {
  // Ticket
  $stmt = $conn->prepare(
    "INSERT INTO tickets (sistema_origen, sucursal_origen_id, creado_por_id, asunto, prioridad)
     VALUES (?, ?, ?, ?, ?)"
  );
  if (!$stmt) throw new Exception('prep_insert_ticket');
  $stmt->bind_param('siiss', $origen, $sucId, $usrId, $asunto, $prioridad);
  if (!$stmt->execute()) throw new Exception('exec_insert_ticket');
  $ticketId = (int)$conn->insert_id;
  $stmt->close();

  // Primer mensaje
  $stmt2 = $conn->prepare(
    "INSERT INTO ticket_mensajes (ticket_id, autor_sistema, autor_id, cuerpo)
     VALUES (?, ?, ?, ?)"
  );
  if (!$stmt2) throw new Exception('prep_insert_msg');
  $stmt2->bind_param('isis', $ticketId, $origen, $usrId, $mensaje);
  if (!$stmt2->execute()) throw new Exception('exec_insert_msg');
  $stmt2->close();

  // updated_at
  $stmt3 = $conn->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?");
  if (!$stmt3) throw new Exception('prep_update_ticket');
  $stmt3->bind_param('i', $ticketId);
  if (!$stmt3->execute()) throw new Exception('exec_update_ticket');
  $stmt3->close();

  $conn->commit();
} catch (Throwable $e) {
  $conn->rollback();
  respond(['ok'=>false,'error'=>'server','detail'=>'no_se_pudo_crear'], 500);
}

/* ======================================================
   ✅ Notificación mail SOLO si viene de otra central
====================================================== */
try {
  if ($origen !== 'LUGA') {
    $emailSistemas = 'efernandez@lugaph.com.mx';

    $autorTxt = $autorNombre !== '' ? $autorNombre : ("ID ".$usrId);
    $sucTxt   = $sucursalNombre !== '' ? $sucursalNombre : ("ID ".$sucId);

    $subject = "[{$origen}] Nuevo ticket #{$ticketId} - {$prioridad}";

    $html = "
      <div style='font-family:Arial,sans-serif;line-height:1.4'>
        <h2 style='margin:0 0 10px'>🎫 Nuevo ticket inter-central</h2>

        <table style='border-collapse:collapse;font-size:14px'>
          <tr><td style='padding:4px 10px 4px 0'><b>ID</b></td><td style='padding:4px 0'>#{$ticketId}</td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Origen</b></td><td style='padding:4px 0'>".htxt($origen)."</td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Prioridad</b></td><td style='padding:4px 0'>".htxt($prioridad)."</td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Sucursal origen</b></td><td style='padding:4px 0'>".htxt($sucTxt)." <span style='color:#777'>(ID {$sucId})</span></td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Creado por</b></td><td style='padding:4px 0'>".htxt($autorTxt)." <span style='color:#777'>(ID {$usrId})</span></td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Asunto</b></td><td style='padding:4px 0'>".htxt($asunto)."</td></tr>
        </table>

        <hr style='border:none;border-top:1px solid #ddd;margin:12px 0'>

        <p style='margin:0 0 6px'><b>Mensaje inicial</b></p>
        <div style='background:#f6f6f6;border:1px solid #eee;border-radius:10px;padding:10px;white-space:pre-wrap'>"
          . htxt($mensaje) .
        "</div>

        <p style='margin:12px 0 0;color:#666;font-size:12px'>
          Nota: Ingresa a La Central para ver detalles de tu ticket.
        </p>
      </div>
    ";

    $text =
      "Nuevo ticket inter-central\n".
      "ID: #{$ticketId}\n".
      "Origen: {$origen}\n".
      "Prioridad: {$prioridad}\n".
      "Sucursal: {$sucTxt} (ID {$sucId})\n".
      "Creado por: {$autorTxt} (ID {$usrId})\n".
      "Asunto: {$asunto}\n\n".
      "Mensaje:\n{$mensaje}\n\n".
      "Nota: Ingresa a La Central para ver detalles de tu ticket.\n";

    $r = send_mail_hostinger([
      'to'      => $emailSistemas,
      'subject' => $subject,
      'html'    => $html,
      'text'    => $text
    ]);

    if (!$r['ok']) {
      error_log("MAIL CREATE FAIL origen={$origen} id={$ticketId} err=" . ($r['error'] ?? 'unknown'));
    }
  }
} catch (Throwable $mailErr) {
  error_log('[tickets.create][mail] '.$mailErr->getMessage());
}

respond(['ok'=>true, 'id'=>$ticketId, 'ticket_id'=>$ticketId]);