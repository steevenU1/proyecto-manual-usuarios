<?php
// tickets_responder_luga.php — Inserta respuesta como LUGA + NOTIFICA POR CORREO
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','Logistica','Gerente'];
if (!in_array($ROL, $ALLOWED, true)) { header("Location: 403.php"); exit(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/mail_hostinger.php';

function back($ok='', $err=''){
  if ($ok)  $_SESSION['flash_ok']  = $ok;
  if ($err) $_SESSION['flash_err'] = $err;
  header('Location: tickets_operador.php');
  exit();
}

function htxt($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function get_user_email(mysqli $conn, int $idUsuario): string {
  if ($idUsuario <= 0) return '';
  $st = $conn->prepare("SELECT correo FROM usuarios WHERE id=? LIMIT 1");
  if (!$st) return '';
  $st->bind_param("i", $idUsuario);
  $st->execute();
  $res = $st->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $st->close();
  $email = $row ? (string)$row['correo'] : '';
  return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function get_sucursal_nombre(mysqli $conn, int $id): string {
  if ($id <= 0) return '';
  $st = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
  if (!$st) return '';
  $st->bind_param("i", $id);
  $st->execute();
  $res = $st->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $st->close();
  return $row ? (string)$row['nombre'] : '';
}

$ticketId  = (int)($_POST['ticket_id'] ?? 0);
$mensaje   = trim((string)($_POST['mensaje'] ?? ''));
$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
$nombreUsr = (string)($_SESSION['nombre'] ?? 'Usuario');

if ($ticketId <= 0 || $mensaje === '') back('', 'Datos inválidos');

/* =========================
   Leer ticket (para correo)
========================= */
$stT = $conn->prepare("
  SELECT id, asunto, prioridad, estado, sistema_origen, sucursal_origen_id, creado_por_id
  FROM tickets
  WHERE id=? LIMIT 1
");
if (!$stT) back('', 'No se pudo leer el ticket');
$stT->bind_param('i', $ticketId);
$stT->execute();
$resT = $stT->get_result();
$ticket = $resT ? $resT->fetch_assoc() : null;
$stT->close();

if (!$ticket) back('', 'El ticket no existe');

/* =========================
   Insert mensaje + bump updated_at
========================= */
$stmt2 = $conn->prepare("
  INSERT INTO ticket_mensajes (ticket_id, autor_sistema, autor_id, cuerpo)
  VALUES (?, 'LUGA', ?, ?)
");
$stmt2->bind_param('iis', $ticketId, $idUsuario, $mensaje);
if (!$stmt2->execute()){
  $stmt2->close();
  back('', 'No se pudo guardar el mensaje');
}
$stmt2->close();

// Bump updated_at seguro
$stUp = $conn->prepare("UPDATE tickets SET updated_at=NOW() WHERE id=?");
$stUp->bind_param('i', $ticketId);
$stUp->execute();
$stUp->close();

/* =========================
   Enviar correo (no tumba flujo)
========================= */
try {
  // Destinatario fijo (sistemas / tú)
  $emailSistemas = 'efernandez@lugaph.com.mx';

  // Creador del ticket
  $emailCreador = get_user_email($conn, (int)$ticket['creado_por_id']);

  // Datos extra para el correo
  $asuntoTicket = (string)($ticket['asunto'] ?? '');
  $prioridad    = (string)($ticket['prioridad'] ?? '');
  $sucId        = (int)($ticket['sucursal_origen_id'] ?? 0);
  $sucNombre    = get_sucursal_nombre($conn, $sucId);

  // Link al ticket
  $base = 'https://lugaph.com.mx'; // ajusta si tienes subcarpeta
  $link = $base . '/tickets_luga.php?q=' . $ticketId;

  $subject = "Respuesta ticket #{$ticketId} - {$prioridad}";

  $html = "
    <div style='font-family:Arial,sans-serif;line-height:1.4'>
      <h2 style='margin:0 0 10px'>Nueva respuesta en ticket</h2>

      <table style='border-collapse:collapse;font-size:14px'>
        <tr><td style='padding:4px 10px 4px 0'><b>ID</b></td><td style='padding:4px 0'>#{$ticketId}</td></tr>
        <tr><td style='padding:4px 10px 4px 0'><b>Prioridad</b></td><td style='padding:4px 0'>".htxt($prioridad)."</td></tr>
        <tr><td style='padding:4px 10px 4px 0'><b>Sucursal</b></td><td style='padding:4px 0'>".htxt($sucNombre)." (ID {$sucId})</td></tr>
        <tr><td style='padding:4px 10px 4px 0'><b>Asunto</b></td><td style='padding:4px 0'>".htxt($asuntoTicket)."</td></tr>
        <tr><td style='padding:4px 10px 4px 0'><b>Respondió</b></td><td style='padding:4px 0'>".htxt($nombreUsr)." (ID {$idUsuario})</td></tr>
      </table>

      <hr style='border:none;border-top:1px solid #ddd;margin:12px 0'>

      <p style='margin:0 0 6px'><b>Respuesta</b></p>
      <div style='background:#f6f6f6;border:1px solid #eee;border-radius:10px;padding:10px;white-space:pre-wrap'>"
        .htxt($mensaje).
      "</div>

      <p style='margin:12px 0 0'>
        <b>Abrir ticket:</b> <a href='".htxt($link)."'>".htxt($link)."</a>
      </p>
    </div>
  ";

  $text =
    "Nueva respuesta en ticket #{$ticketId}\n".
    "Prioridad: {$prioridad}\n".
    "Sucursal: {$sucNombre} (ID {$sucId})\n".
    "Asunto: {$asuntoTicket}\n".
    "Respondió: {$nombreUsr} (ID {$idUsuario})\n\n".
    "Respuesta:\n{$mensaje}\n\n".
    "Abrir: {$link}\n";

  $destinatarios = array_values(array_filter(array_unique([
    $emailSistemas,
    $emailCreador
  ])));

  foreach ($destinatarios as $to) {
    $r = send_mail_hostinger([
      'to'      => $to,
      'subject' => $subject,
      'html'    => $html,
      'text'    => $text
    ]);
    if (!$r['ok']) {
      error_log("MAIL RESP FAIL to={$to} id={$ticketId} err=" . ($r['error'] ?? 'unknown'));
    }
  }

} catch (Throwable $mailErr) {
  error_log('[tickets_responder_luga][mail] '.$mailErr->getMessage());
}

back('Respuesta enviada.');