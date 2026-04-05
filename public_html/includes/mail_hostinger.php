<?php
// public_html/includes/mail_hostinger.php

require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Envío SMTP Hostinger
 * return ['ok'=>true] o ['ok'=>false,'error'=>'...']
 */
function send_mail_hostinger(array $opt): array {

  $to      = (string)($opt['to'] ?? '');
  $subject = trim((string)($opt['subject'] ?? 'Notificación'));
  $html    = (string)($opt['html'] ?? '');
  $text    = (string)($opt['text'] ?? strip_tags($html));

  if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    return ['ok'=>false,'error'=>'Destinatario inválido'];
  }

  /* ==========================
     CONFIG SMTP HOSTINGER
  ========================== */
  $SMTP_HOST = 'smtp.hostinger.com';
  $SMTP_USER = 'sistemas@lugaph.site';
  $SMTP_PASS = '1Sp2gd3pa*';   // <- cambia después de pruebas
  $SMTP_PORT = 465; // 465 SSL recomendado en Hostinger

  $FROM_EMAIL = $SMTP_USER;
  $FROM_NAME  = 'Central LUGA';

  try {
    $mail = new PHPMailer(true);

    $mail->CharSet  = 'UTF-8';
    $mail->Encoding = 'base64';

    /* ===== SMTP ===== */
    $mail->isSMTP();
    $mail->Host       = $SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = $SMTP_USER;
    $mail->Password   = $SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = $SMTP_PORT;

    /* ===== Headers limpios ===== */
    $mail->setFrom($FROM_EMAIL, $FROM_NAME);
    $mail->addReplyTo($FROM_EMAIL, $FROM_NAME);
    $mail->addAddress($to);

    // Message-ID correcto (mejora reputación)
    $host = $_SERVER['SERVER_NAME'] ?? 'lugaph.com.mx';
    $mail->Hostname  = $host;
    $mail->MessageID = '<'.uniqid().'@'.$host.'>';

    // Headers extra
    $mail->addCustomHeader('X-Mailer', 'CentralTickets');
    $mail->addCustomHeader('Precedence', 'list');

    /* ===== Contenido ===== */
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $html;
    $mail->AltBody = $text;

    $mail->send();

    return ['ok'=>true];

  } catch (Exception $e) {
    return [
      'ok'=>false,
      'error'=> $mail->ErrorInfo ?: $e->getMessage()
    ];
  }
}