<?php
header('Content-Type:text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo "STEP 0 (inicio) => ", __FILE__, "\n";
$step = (int)($_GET['step'] ?? 0);

if ($step >= 1) {
  echo "STEP 1 include db.php\n";
  include 'db.php';
}
if ($step >= 2) {
  echo "STEP 2 include helpers_nomina.php\n";
  include 'helpers_nomina.php';
}
if ($step >= 3) {
  echo "STEP 3 include navbar.php\n";
  include 'navbar.php';
}
if ($step >= 4) {
  echo "STEP 4 echo final (si ves HTML aquí, este archivo NO lo imprime)\n";
}
