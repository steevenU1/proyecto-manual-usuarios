<?php
// tickets_cambiar_estado.php — Cambia estado con transiciones válidas + NOTIFICA POR CORREO
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
$estadoNew = trim((string)($_POST['estado'] ?? ''));

$valid = ['abierto','en_progreso','resuelto','cerrado'];
if ($ticketId <= 0 || !in_array($estadoNew, $valid, true)) back('', 'Datos inválidos');

/* =========================
   Leer ticket (para transición + mail)
========================= */
$stmt = $conn->prepare("
  SELECT id, estado, asunto, prioridad, sucursal_origen_id, creado_por_id, sistema_origen
  FROM tickets
  WHERE id=? LIMIT 1
");
$stmt->bind_param('i', $ticketId);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ticket) back('', 'Ticket no encontrado');

$estadoOld = (string)($ticket['estado'] ?? 'abierto');

/* =========================
   Reglas de transición
========================= */
$transiciones = [
  'abierto'      => ['en_progreso','resuelto','cerrado'],
  'en_progreso'  => ['resuelto','cerrado'],
  'resuelto'     => ['cerrado','en_progreso'],
  'cerrado'      => ['en_progreso'], // permitir reabrir como en_progreso
];

if (!in_array($estadoNew, $transiciones[$estadoOld] ?? [], true)) {
  back('', "Transición no válida: $estadoOld → $estadoNew");
}

/* =========================
   Update estado
========================= */
$st = $conn->prepare("UPDATE tickets SET estado=?, updated_at=NOW() WHERE id=?");
$st->bind_param('si', $estadoNew, $ticketId);
if (!$st->execute()) { $st->close(); back('', 'No se pudo cambiar el estado'); }
$st->close();

/* =========================
   Notificación por correo (no tumba flujo)
========================= */
try {
  $emailSistemas = 'efernandez@lugaph.com.mx'; // fijo
  $emailCreador  = get_user_email($conn, (int)($ticket['creado_por_id'] ?? 0));

  $asuntoTicket = (string)($ticket['asunto'] ?? '');
  $prioridad    = (string)($ticket['prioridad'] ?? '');
  $sucId        = (int)($ticket['sucursal_origen_id'] ?? 0);
  $sucNombre    = get_sucursal_nombre($conn, $sucId);

  $nombreOperador = (string)($_SESSION['nombre'] ?? 'Operador');
  $idOperador     = (int)($_SESSION['id_usuario'] ?? 0);

  $base = 'https://lugaph.com.mx'; // ajusta si aplica subcarpeta
  $link = $base . '/tickets_luga.php?q=' . $ticketId;

  $subject = "Cambio de estado ticket #{$ticketId}: {$estadoOld} → {$estadoNew}";

  $html = "
    <div style='font-family:Arial,sans-serif;line-height:1.4'>
      <h2 style='margin:0 0 10px'>Cambio de estado de ticket</h2>

      <table style='border-collapse:collapse;font-size:14px'>
        <tr><td style='padding:4px 10px 4px 0'><b>ID</b></td><td style='padding:4px 0'>#{$ticketId}</td></tr>
        <tr><td style='padding:4px 10px 4px 0'><b>Asunto</b></td><td style='padding:4px 0'>".htxt($asuntoTicket)."</td></tr>
        <tr><td style='padding:4px 10px 4px 0'><b>Prioridad</b></td><td style='padding:4px 0'>".htxt($prioridad)."</td></tr>
        <tr><td style='padding:4px 10px 4px 0'><b>Sucursal</b></td><td style='padding:4px 0'>".htxt($sucNombre)." (ID {$sucId})</td></tr>
        <tr><td style='padding:4px 10px 4px 0'><b>Cambio</b></td><td style='padding:4px 0'>".htxt($estadoOld)." → <b>".htxt($estadoNew)."</b></td></tr>
        <tr><td style='padding:4px 10px 4px 0'><b>Operador</b></td><td style='padding:4px 0'>".htxt($nombreOperador)." (ID {$idOperador})</td></tr>
      </table>

      <p style='margin:12px 0 0'>
        <b>Abrir ticket:</b> <a href='".htxt($link)."'>".htxt($link)."</a>
      </p>
    </div>
  ";

  $text =
    "Cambio de estado de ticket\n".
    "ID: #{$ticketId}\n".
    "Asunto: {$asuntoTicket}\n".
    "Prioridad: {$prioridad}\n".
    "Sucursal: {$sucNombre} (ID {$sucId})\n".
    "Cambio: {$estadoOld} -> {$estadoNew}\n".
    "Operador: {$nombreOperador} (ID {$idOperador})\n\n".
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
      error_log("MAIL ESTADO FAIL to={$to} id={$ticketId} err=" . ($r['error'] ?? 'unknown'));
    }
  }

} catch (Throwable $mailErr) {
  error_log('[tickets_cambiar_estado][mail] '.$mailErr->getMessage());
}

back("Estado actualizado a {$estadoNew}.");