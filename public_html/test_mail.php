<?php
require_once __DIR__ . '/includes/mail_hostinger.php';

$r = send_mail_hostinger([
  'to' => 'e.fernandez.r@outlook.com',
  'subject' => 'Test SMTP Hostinger',
  'html' => '<b>Si lees esto, jaló 😎</b><br><small>Enviado desde Hostinger</small>'
]);

header('Content-Type: text/plain; charset=utf-8');
var_export($r);