<?php
// tickets.reply.php — Agrega mensaje a un ticket (NANO, MIPLAN o LUGA)
// + NOTIFICA POR CORREO si el ticket es inter-central
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

$origen = require_origen(['NANO','MIPLAN','LUGA']);

$in       = json_input();
$ticketId = req_int($in, 'ticket_id', 0);
$autorId  = req_int($in, 'autor_id', 0);
$mensaje  = trim(req_str($in, 'mensaje', ''));

// ✅ nombres opcionales (vienen de la central origen)
$autorNombre    = clean_label((string)req_str($in, 'autor_nombre', ''), 80);
$sucursalNombre = clean_label((string)req_str($in, 'sucursal_nombre', ''), 80);

if ($ticketId <= 0 || $mensaje === '') respond(['ok'=>false, 'error'=>'datos_invalidos'], 422);
if (mb_strlen($mensaje,'UTF-8') > 4000) respond(['ok'=>false, 'error'=>'mensaje_muy_largo'], 422);

// Traer ticket (y validar pertenencia si no es LUGA)
$ticket = null;

if ($origen === 'LUGA') {
  $stmt = $conn->prepare("
    SELECT id, sistema_origen, sucursal_origen_id, asunto, prioridad, estado, created_at, updated_at
    FROM tickets WHERE id=? LIMIT 1
  ");
  if ($stmt) $stmt->bind_param('i', $ticketId);
} else {
  $stmt = $conn->prepare("
    SELECT id, sistema_origen, sucursal_origen_id, asunto, prioridad, estado, created_at, updated_at
    FROM tickets WHERE id=? AND sistema_origen=? LIMIT 1
  ");
  if ($stmt) $stmt->bind_param('is', $ticketId, $origen);
}

if (!$stmt) respond(['ok'=>false,'error'=>'prep_ticket'], 500);

$stmt->execute();
$res = $stmt->get_result();
$ticket = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$ticket) respond(['ok'=>false,'error'=>'ticket_no_encontrado'], 404);

// Guardar mensaje + bump updated_at
$conn->begin_transaction();
try {
  $stmt2 = $conn->prepare(
    "INSERT INTO ticket_mensajes (ticket_id, autor_sistema, autor_id, cuerpo)
     VALUES (?, ?, ?, ?)"
  );
  if (!$stmt2) throw new Exception('prep_insert_msg');
  $stmt2->bind_param('isis', $ticketId, $origen, $autorId, $mensaje);
  if (!$stmt2->execute()) throw new Exception('exec_insert_msg');
  $stmt2->close();

  $stmt3 = $conn->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?");
  if (!$stmt3) throw new Exception('prep_update_ticket');
  $stmt3->bind_param('i', $ticketId);
  if (!$stmt3->execute()) throw new Exception('exec_update_ticket');
  $stmt3->close();

  $conn->commit();
} catch (Throwable $e) {
  $conn->rollback();
  respond(['ok'=>false, 'error'=>'no_se_pudo_guardar'], 500);
}

/* ======================================================
   ✅ Notificación mail si el ticket NO es de LUGA
====================================================== */
try {
  $sistemaOrigenTicket = (string)($ticket['sistema_origen'] ?? '');
  $esInterCentral = ($sistemaOrigenTicket !== 'LUGA');

  if ($esInterCentral) {
    $emailSistemas = 'efernandez@lugaph.com.mx';

    $sucId = (int)($ticket['sucursal_origen_id'] ?? 0);

    $autorTxt = $autorNombre !== '' ? $autorNombre : ("ID ".$autorId);
    $sucTxt   = $sucursalNombre !== '' ? $sucursalNombre : ("ID ".$sucId);

    $asuntoTicket = (string)($ticket['asunto'] ?? '');
    $prioridad    = (string)($ticket['prioridad'] ?? '');
    $estado       = (string)($ticket['estado'] ?? '');

    $subject = "[{$origen}] Respuesta en ticket #{$ticketId}";

    $html = "
      <div style='font-family:Arial,sans-serif;line-height:1.4'>
        <h2 style='margin:0 0 10px'>💬 Nueva respuesta (inter-central)</h2>

        <table style='border-collapse:collapse;font-size:14px'>
          <tr><td style='padding:4px 10px 4px 0'><b>ID</b></td><td style='padding:4px 0'>#{$ticketId}</td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Responde</b></td><td style='padding:4px 0'>".htxt($origen)." • ".htxt($autorTxt)." <span style='color:#777'>(ID {$autorId})</span></td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Origen del ticket</b></td><td style='padding:4px 0'>".htxt($sistemaOrigenTicket)."</td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Estado</b></td><td style='padding:4px 0'>".htxt($estado)."</td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Prioridad</b></td><td style='padding:4px 0'>".htxt($prioridad)."</td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Sucursal</b></td><td style='padding:4px 0'>".htxt($sucTxt)." <span style='color:#777'>(ID {$sucId})</span></td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Asunto</b></td><td style='padding:4px 0'>".htxt($asuntoTicket)."</td></tr>
        </table>

        <hr style='border:none;border-top:1px solid #ddd;margin:12px 0'>

        <p style='margin:0 0 6px'><b>Mensaje</b></p>
        <div style='background:#f6f6f6;border:1px solid #eee;border-radius:10px;padding:10px;white-space:pre-wrap'>"
          . htxt($mensaje) .
        "</div>

        <p style='margin:12px 0 0;color:#666;font-size:12px'>
          Nota: Ingresa a La Central para ver detalles de tu ticket.
        </p>
      </div>
    ";

    $text =
      "Nueva respuesta (inter-central)\n".
      "Ticket: #{$ticketId}\n".
      "Responde: {$origen} • {$autorTxt} (ID {$autorId})\n".
      "Origen del ticket: {$sistemaOrigenTicket}\n".
      "Estado: {$estado}\n".
      "Prioridad: {$prioridad}\n".
      "Sucursal: {$sucTxt} (ID {$sucId})\n".
      "Asunto: {$asuntoTicket}\n\n".
      "Mensaje:\n{$mensaje}\n\n".
      "Nota: Ingresa a La Central para ver detalles de tu ticket.\n";

    $r = send_mail_hostinger([
      'to'      => $emailSistemas,
      'subject' => $subject,
      'html'    => $html,
      'text'    => $text
    ]);

    if (!$r['ok']) {
      error_log("MAIL REPLY FAIL ticket={$ticketId} origen={$origen} err=" . ($r['error'] ?? 'unknown'));
    }
  }
} catch (Throwable $mailErr) {
  error_log('[tickets.reply][mail] '.$mailErr->getMessage());
}

respond(['ok'=>true, 'ticket_id'=>$ticketId]);