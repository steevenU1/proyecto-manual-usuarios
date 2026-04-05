<?php
// ajax_check_corte.php — Verifica candado por sucursal (llamado desde nueva_venta)
header('Content-Type: application/json; charset=UTF-8');

session_start();
if (!isset($_SESSION['id_usuario'])) {
  echo json_encode(['ok' => false, 'error' => 'Auth']); exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guard_corte.php';

$id_sucursal = (int)($_POST['id_sucursal'] ?? 0);
if ($id_sucursal <= 0) {
  echo json_encode(['ok' => false, 'error' => 'Parámetros']); exit;
}

list($bloquear, $motivo, $ayer) = debe_bloquear_captura($conn, $id_sucursal);

echo json_encode([
  'ok'       => true,
  'bloquear' => $bloquear,
  'motivo'   => $motivo,
  'ayer'     => $ayer,
]);
