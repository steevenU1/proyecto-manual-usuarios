<?php
// tickets_guardar_luga.php — Inserta ticket (origen LUGA) + primer mensaje + NOTIFICA POR CORREO
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','Logistica','Gerente','Ejecutivo']; // mismos que en el form
if (!in_array($ROL, $ALLOWED, true)) { header("Location: 403.php"); exit(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/mail_hostinger.php';

function back($ok='', $err=''){
  if ($ok)  $_SESSION['flash_ok']  = $ok;
  if ($err) $_SESSION['flash_err'] = $err;
  header('Location: tickets_luga.php');
  exit();
}

function clean($s){ return trim((string)$s); }
function htxt($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function get_user_email(mysqli $conn, int $idUsuario): string {
  if ($idUsuario <= 0) return '';
  $sql = "SELECT correo FROM usuarios WHERE id=? LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) return '';
  $st->bind_param("i", $idUsuario);
  $st->execute();
  $res = $st->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $st->close();

  $email = $row ? (string)$row['correo'] : '';
  return (filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : '';
}

function get_sucursal_nombre(mysqli $conn, int $id): string {
  if ($id <= 0) return '';
  $sql = "SELECT nombre FROM sucursales WHERE id=? LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) return '';
  $st->bind_param("i", $id);
  $st->execute();
  $res = $st->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $st->close();

  return $row ? (string)$row['nombre'] : '';
}

/* =========================
   CSRF
========================= */
if (!hash_equals($_SESSION['ticket_csrf_luga'] ?? '', $_POST['csrf'] ?? '')) {
  back('', 'Token inválido o formulario duplicado. Refresca la página.');
}
// Consumir token para evitar dobles envíos
unset($_SESSION['ticket_csrf_luga']);

/* =========================
   Inputs
========================= */
$asunto    = clean($_POST['asunto']  ?? '');
$mensaje   = clean($_POST['mensaje'] ?? '');
$prioridad = (string)($_POST['prioridad'] ?? 'media');
$sucId     = (int)($_POST['sucursal_origen_id'] ?? ($_SESSION['id_sucursal'] ?? 0));
$usrId     = (int)($_SESSION['id_usuario'] ?? 0);

$nombreUser = (string)($_SESSION['nombre'] ?? 'Usuario');

if ($asunto === '' || $mensaje === '') {
  back('', 'Asunto y mensaje son obligatorios.');
}

$conn->begin_transaction();

try {
  /* =========================
     1) Crear ticket (origen LUGA)
  ========================= */
  $stmt = $conn->prepare("
    INSERT INTO tickets (sistema_origen, sucursal_origen_id, creado_por_id, asunto, prioridad)
    VALUES ('LUGA', ?, ?, ?, ?)
  ");
  if (!$stmt) { throw new Exception('Prepare tickets: '.$conn->error); }

  $stmt->bind_param('iiss', $sucId, $usrId, $asunto, $prioridad);
  $stmt->execute();
  $ticketId = (int)$conn->insert_id;
  $stmt->close();

  /* =========================
     2) Primer mensaje
  ========================= */
  $stmt2 = $conn->prepare("
    INSERT INTO ticket_mensajes (ticket_id, autor_sistema, autor_id, cuerpo)
    VALUES (?, 'LUGA', ?, ?)
  ");
  if (!$stmt2) { throw new Exception('Prepare mensajes: '.$conn->error); }

  $stmt2->bind_param('iis', $ticketId, $usrId, $mensaje);
  $stmt2->execute();
  $stmt2->close();

  $conn->commit();

  /* =========================
     3) Notificación por correo (NO tumba flujo si falla)
     Debug opcional:
       - action="tickets_guardar_luga.php?maildebug=1"
       o <input type="hidden" name="maildebug" value="1">
  ========================= */
  try {
    $mailDebug = isset($_GET['maildebug']) || isset($_POST['maildebug']);

    $emailCreador  = get_user_email($conn, $usrId);

    // 👉 correo fijo que siempre recibe
    $emailSistemas = 'efernandez@lugaph.com.mx';

    $sucNombre = get_sucursal_nombre($conn, $sucId);

    // Link directo al ticket
    $base = 'https://lugaph.com.mx';
    $link = $base . '/tickets_luga.php?q=' . $ticketId;

    // Asunto más “inbox friendly”
    $subject = "Nuevo ticket #{$ticketId} (LUGA) - {$prioridad}";

    $html = "
      <div style='font-family:Arial,sans-serif;line-height:1.4'>
        <h2 style='margin:0 0 10px'>Nuevo ticket creado</h2>

        <table style='border-collapse:collapse;font-size:14px'>
          <tr><td style='padding:4px 10px 4px 0'><b>ID</b></td><td style='padding:4px 0'>#{$ticketId}</td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Origen</b></td><td style='padding:4px 0'>LUGA</td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Prioridad</b></td><td style='padding:4px 0'>".htxt($prioridad)."</td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Sucursal</b></td><td style='padding:4px 0'>".htxt($sucNombre)." (ID {$sucId})</td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Creado por</b></td><td style='padding:4px 0'>".htxt($nombreUser)." (ID {$usrId})</td></tr>
          <tr><td style='padding:4px 10px 4px 0'><b>Asunto</b></td><td style='padding:4px 0'>".htxt($asunto)."</td></tr>
        </table>

        <hr style='border:none;border-top:1px solid #ddd;margin:12px 0'>

        <p style='margin:0 0 6px'><b>Mensaje inicial</b></p>
        <div style='background:#f6f6f6;border:1px solid #eee;border-radius:10px;padding:10px;white-space:pre-wrap'>"
          .htxt($mensaje).
        "</div>

        <p style='margin:12px 0 0'>
          <b>Abrir ticket:</b> <a href='".htxt($link)."'>".htxt($link)."</a>
        </p>
      </div>
    ";

    $text =
      "Nuevo ticket creado\n".
      "ID: #{$ticketId}\n".
      "Origen: LUGA\n".
      "Prioridad: {$prioridad}\n".
      "Sucursal: {$sucNombre} (ID {$sucId})\n".
      "Creado por: {$nombreUser} (ID {$usrId})\n".
      "Asunto: {$asunto}\n\n".
      "Mensaje:\n{$mensaje}\n\n".
      "Abrir: {$link}\n";

    // destinatarios: fijo + creador (si existe)
    $destinatarios = array_values(array_filter(array_unique([
      $emailSistemas,
      $emailCreador
    ])));

    $debugLines = [];
    if (!$destinatarios) {
      $debugLines[] = "MAIL SKIP: sin destinatarios válidos";
    }

    foreach ($destinatarios as $to) {
      $r = send_mail_hostinger([
        'to'      => $to,
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text
      ]);

      if (!$r['ok']) {
        $msg = "MAIL FAIL to={$to} id={$ticketId} err=" . ($r['error'] ?? 'unknown');
        error_log($msg);
        $debugLines[] = $msg;
      } else {
        $debugLines[] = "MAIL OK to={$to} id={$ticketId}";
      }
    }

    if ($mailDebug) {
      $_SESSION['flash_ok'] = "✅ Ticket creado (#{$ticketId}). Debug mail:\n" . implode("\n", $debugLines);
    }

  } catch (Throwable $mailErr) {
    error_log('[tickets_guardar_luga][mail] '.$mailErr->getMessage());
  }

  /* =========================
     4) Flash + redirect
  ========================= */
  $_SESSION['ticket_csrf_luga'] = bin2hex(random_bytes(16));
  if (empty($_SESSION['flash_ok'])) {
    $_SESSION['flash_ok'] = "✅ Ticket creado (#{$ticketId}).";
  }

  header('Location: tickets_nuevo_luga.php');
  exit();

} catch (Throwable $e) {
  $conn->rollback();
  error_log('[tickets_guardar_luga] '.$e->getMessage());
  back('', 'No se pudo guardar el ticket. Intenta de nuevo.');
}