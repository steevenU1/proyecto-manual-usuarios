<?php
// promos_guard.php
if (session_status() === PHP_SESSION_NONE) session_start();

$ROL = (string)($_SESSION['rol'] ?? '');
$isSubdis = (stripos($ROL, 'Subdis_') === 0) || (stripos($ROL, 'subdis_') === 0);

// 🔧 SWITCH: si mañana decides permitir promos a subdis, cambias aquí.
if (!defined('PROMOS_LUGA_BLOQUEAR_EN_SUBDIS')) {
  define('PROMOS_LUGA_BLOQUEAR_EN_SUBDIS', true);
}

function subdis_bloquea_promos_luga(): bool {
  global $isSubdis;
  return (PROMOS_LUGA_BLOQUEAR_EN_SUBDIS && $isSubdis);
}