<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { http_response_code(401); exit('No autenticado'); }

/* ==== Include con fallback ==== */
if (file_exists(__DIR__ . '/includes/docs_lib.php')) {
  require_once __DIR__ . '/includes/docs_lib.php';
} else {
  require_once __DIR__ . '/docs_lib.php';
}

/* ==== Guardas por si el include no dejó $conn ==== */
if (!isset($conn) || !($conn instanceof mysqli)) {
  http_response_code(500);
  exit('Error: conexión DB no disponible (conn). Revisa docs_lib.php');
}

/* ==== Contexto ==== */
$mi_id  = (int)($_SESSION['id_usuario'] ?? 0);
$mi_rol = (string)($_SESSION['rol'] ?? 'Ejecutivo');

/* ==== Parámetros ==== */
$doc_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($doc_id <= 0) { http_response_code(400); exit('ID inválido'); }

/* ==== Buscar doc ==== */
if (!function_exists('get_doc_record')) {
  http_response_code(500);
  exit('Error: función get_doc_record() no existe. Revisa docs_lib.php');
}
$doc = get_doc_record($conn, $doc_id);
if (!$doc) { http_response_code(404); exit('No encontrado'); }

/* ==== Permisos básicos ====
   - Propietario puede ver
   - O rol autorizado por doc_tipo_roles
*/
if (!function_exists('user_can_view_doc_type')) {
  http_response_code(500);
  exit('Error: función user_can_view_doc_type() no existe. Revisa docs_lib.php');
}
$docUsuarioId = (int)($doc['usuario_id'] ?? 0);
$docTipoId    = (int)($doc['doc_tipo_id'] ?? 0);

$permitido = ($docUsuarioId === $mi_id) || user_can_view_doc_type($conn, $mi_rol, $docTipoId);
if (!$permitido) { http_response_code(403); exit('Sin permiso'); }

/* ==== Resolver base path (evita 500 si DOCS_BASE_PATH no existe) ==== */
$base = null;
if (defined('DOCS_BASE_PATH') && DOCS_BASE_PATH) {
  $base = (string)DOCS_BASE_PATH;
} elseif (defined('DOCS_BASE_DIR') && DOCS_BASE_DIR) {
  $base = (string)DOCS_BASE_DIR;
} elseif (defined('UPLOADS_DOCS_PATH') && UPLOADS_DOCS_PATH) {
  $base = (string)UPLOADS_DOCS_PATH;
} else {
  // Fallback razonable: ajústalo si tu proyecto guarda docs en otra carpeta
  $base = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'docs';
}

/* ==== Servir archivo ==== */
$ruta = (string)($doc['ruta'] ?? '');
if ($ruta === '') { http_response_code(404); exit('Documento sin ruta'); }

/* Normaliza separadores */
$rel = str_replace(['\\','/'], DIRECTORY_SEPARATOR, $ruta);

/* Bloquea traversal (.. / ../) */
$relClean = preg_replace('~\.\.(\/|\\\\)~', '', $rel);
$relClean = ltrim($relClean, '/\\');

$abs = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $relClean;

/* Verifica que exista */
if (!is_file($abs)) {
  http_response_code(404);
  exit('Archivo no existe en disco');
}

/* download=1 => forzar descarga (inline = false) */
$inline = empty($_GET['download']); // si agregas ?download=1 => $inline=false

$mime   = !empty($doc['mime']) ? (string)$doc['mime'] : 'application/octet-stream';
$nombre = !empty($doc['nombre_original']) ? (string)$doc['nombre_original'] : 'documento';

/* output_file_secure es la que ya tenías funcional */
if (!function_exists('output_file_secure')) {
  // Fallback mínimo si no existiera (no debería pasar si antes funcionaba)
  while (ob_get_level()) { @ob_end_clean(); }
  header('X-Content-Type-Options: nosniff');
  header('Content-Type: ' . $mime);
  $disp = $inline ? 'inline' : 'attachment';
  $safe = preg_replace('/[^\w\-. ()\[\]]+/u', '_', $nombre);
  if ($safe === '') $safe = 'documento';
  header("Content-Disposition: {$disp}; filename=\"{$safe}\"");
  header('Content-Length: ' . filesize($abs));
  readfile($abs);
  exit;
}

output_file_secure($abs, $mime, $nombre, $inline);
exit;