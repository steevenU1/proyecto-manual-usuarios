<?php
/**
 * backup_db.php
 * Crea un .sql.gz con la BD y rota copias antiguas.
 * Ejecuta este archivo por CRON o manualmente con token.
 */

date_default_timezone_set('America/Mexico_City');

// 1) Seguridad: token para ejecución vía web (opcional)
$TOKEN = 'pon_aqui_un_token_largo_y_unico';
if (php_sapi_name() !== 'cli') {
  if (!isset($_GET['token']) || $_GET['token'] !== $TOKEN) {
    http_response_code(403);
    exit('Forbidden');
  }
}

// 2) Cargar credenciales
require __DIR__ . '/../db.php'; // ajusta si moviste el archivo

// 3) Directorio de backups (intenta fuera de public_html)
$homeDir    = dirname(APP_ROOT);                       // /home/usuario
$backupDir  = $homeDir . '/db_backups';               // /home/usuario/db_backups
if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

// 4) Nombre de archivo
$when   = date('Y-m-d_His');
$fname  = sprintf('%s/%s_%s.sql.gz', $backupDir, $DB['name'], $when);

// 5) Comando mysqldump (usa escapeshellarg SIEMPRE)
$cmd = sprintf(
  'mysqldump --user=%s --password=%s --host=%s ' .
  '--single-transaction --routines --events --triggers ' .
  '--default-character-set=utf8mb4 --no-tablespaces --set-gtid-purged=OFF %s | gzip > %s',
  escapeshellarg($DB['user']),
  escapeshellarg($DB['pass']),
  escapeshellarg($DB['host']),
  escapeshellarg($DB['name']),
  escapeshellarg($fname)
);

// 6) Ejecutar y loguear errores
exec($cmd . ' 2>&1', $out, $ret);
if ($ret !== 0) {
  file_put_contents($backupDir . '/backup_errors.log',
    "[" . date('c') . "] ret=$ret\n" . implode("\n", $out) . "\n\n",
    FILE_APPEND
  );
  exit(1);
}

// 7) Rotación: conserva los últimos 14 backups (ajusta a gusto)
$keep = 14;
$files = glob($backupDir . '/*.sql.gz');
rsort($files); // más nuevos primero
for ($i = $keep; $i < count($files); $i++) {
  @unlink($files[$i]);
}

// 8) (Opcional) imprimir algo si lo corres a mano
echo "OK: $fname\n";