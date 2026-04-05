<?php
// diag_tareas.php — Diagnóstico de pantalla blanca (tareas)

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// imprime fatales al final aunque display_errors esté raro
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    header("Content-Type: text/plain; charset=UTF-8");
    echo "\n\n=== FATAL DETECTADO ===\n";
    echo "Tipo: {$e['type']}\n";
    echo "Mensaje: {$e['message']}\n";
    echo "Archivo: {$e['file']}\n";
    echo "Línea: {$e['line']}\n";
  }
});

header("Content-Type: text/plain; charset=UTF-8");
echo "OK 1: PHP vivo\n";

// prueba sesión
if (session_status() === PHP_SESSION_NONE) session_start();
echo "OK 2: session_start\n";

// prueba existencia archivos
$base = __DIR__;
$paths = [
  'db.php' => $base.'/db.php',
  'navbar.php' => $base.'/navbar.php',
];

foreach ($paths as $k=>$p) {
  echo "Check $k: " . (file_exists($p) ? "EXISTE" : "NO EXISTE") . " ($p)\n";
}

echo "OK 3: antes de requires\n";

// prueba require uno por uno
echo "\n--- Probando db.php ---\n";
require_once __DIR__ . '/db.php';
echo "OK db.php cargó\n";

echo "\n--- Probando navbar.php ---\n";
require_once __DIR__ . '/navbar.php';
echo "OK navbar.php cargó\n";

echo "\nOK FINAL: todo cargó sin fatal.\n";
