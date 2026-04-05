<?php
// deposito_comprobante.php — sirve comprobante con permisos (LUGA vs SUBDIS)
session_start();

if (!isset($_SESSION['id_usuario'])) {
  http_response_code(403);
  exit('Sin permiso');
}

$ROL = (string)($_SESSION['rol'] ?? '');
if (!in_array($ROL, ['Admin', 'Subdis_Admin'], true)) {
  http_response_code(403);
  exit('Sin permiso');
}

require_once __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('ID inválido');
}

/* =======================
   Helpers
   ======================= */
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$t}'
            AND COLUMN_NAME = '{$c}'
          LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

function safe_basename(string $path): string {
  $path = str_replace(["\0", "\r", "\n"], '', $path);
  $path = str_replace('\\', '/', $path);
  return basename($path);
}

/* =======================
   Scope flags
   ======================= */
$isAdmin       = ($ROL === 'Admin');
$isSubdisAdmin = ($ROL === 'Subdis_Admin');

$id_subdis = (int)($_SESSION['id_subdis'] ?? 0);
if ($isSubdisAdmin && $id_subdis <= 0) {
  // seguridad: subdis sin id_subdis = no ve nada
  http_response_code(403);
  exit('Sin permiso');
}

// Columnas opcionales (según tu evolución de multi-tenant)
$DS_HAS_PROP   = hasColumn($conn, 'depositos_sucursal', 'propiedad');
$DS_HAS_SUBDIS = hasColumn($conn, 'depositos_sucursal', 'id_subdis');
$SUC_HAS_SUB   = hasColumn($conn, 'sucursales', 'id_subdis');

/* =======================
   Cargar depósito + sucursal
   ======================= */
$selectProp   = $DS_HAS_PROP   ? ", ds.propiedad" : ", NULL AS propiedad";
$selectSubdis = $DS_HAS_SUBDIS ? ", ds.id_subdis" : ", NULL AS id_subdis";
$selectSucSub = $SUC_HAS_SUB   ? ", s.id_subdis AS suc_id_subdis" : ", NULL AS suc_id_subdis";

$sql = "
  SELECT
    ds.id,
    ds.id_sucursal,
    ds.comprobante_archivo,
    ds.comprobante_nombre,
    ds.comprobante_mime,
    ds.comprobante_size
    {$selectProp}
    {$selectSubdis}
    {$selectSucSub}
  FROM depositos_sucursal ds
  INNER JOIN sucursales s ON s.id = ds.id_sucursal
  WHERE ds.id = ?
  LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$dep = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$dep || empty($dep['comprobante_archivo'])) {
  http_response_code(404);
  exit('No encontrado');
}

/* =======================
   Permisos / Scope real
   ======================= */
if ($isAdmin) {
  // Admin LUGA: si existe propiedad, exigir LUGA
  if ($DS_HAS_PROP) {
    if ((string)$dep['propiedad'] !== 'LUGA') {
      http_response_code(403);
      exit('Sin permiso');
    }
  }
  // Si existe id_subdis, Admin LUGA solo ve NULL/0
  if ($DS_HAS_SUBDIS) {
    $dsSub = (int)($dep['id_subdis'] ?? 0);
    if ($dsSub !== 0) {
      http_response_code(403);
      exit('Sin permiso');
    }
  }
} else {
  // Subdis_Admin: si existe propiedad, exigir SUBDIS
  if ($DS_HAS_PROP) {
    if ((string)$dep['propiedad'] !== 'SUBDIS') {
      http_response_code(403);
      exit('Sin permiso');
    }
  }

  $ok = false;

  // Preferimos depositos_sucursal.id_subdis si existe
  if ($DS_HAS_SUBDIS) {
    $dsSub = (int)($dep['id_subdis'] ?? 0);
    if ($dsSub === $id_subdis) $ok = true;
  }

  // Fallback por sucursales.id_subdis si existe
  if (!$ok && $SUC_HAS_SUB) {
    $sSub = (int)($dep['suc_id_subdis'] ?? 0);
    if ($sSub === $id_subdis) $ok = true;
  }

  if (!$ok) {
    http_response_code(403);
    exit('Sin permiso');
  }
}

/* =======================
   Resolver ruta de archivo (seguro)
   ======================= */
// En tu BD puede venir "uploads/depositos/xxx.pdf" o similar.
// Para evitar traversal, tomamos basename y reconstruimos contra /uploads si aplica.
$archivo = trim((string)$dep['comprobante_archivo']);
$archivo = str_replace('\\', '/', $archivo);
$basename = safe_basename($archivo);

// Intento 1: respetar ruta relativa dentro del proyecto (si guardas "uploads/..."):
$path1 = __DIR__ . '/' . ltrim($archivo, '/');

// Intento 2: usar solo nombre dentro de /uploads/depositos (si guardas solo nombre):
$path2 = __DIR__ . '/uploads/depositos/' . $basename;

// Intento 3: /uploads/ (fallback)
$path3 = __DIR__ . '/uploads/' . $basename;

$path = null;
if (is_file($path1)) $path = $path1;
elseif (is_file($path2)) $path = $path2;
elseif (is_file($path3)) $path = $path3;

if (!$path) {
  http_response_code(404);
  exit('Archivo no disponible');
}

// Defensa: realpath dentro de /uploads si existe esa carpeta
$real = realpath($path);
$uploadsReal = realpath(__DIR__ . '/uploads');
if ($uploadsReal && $real && strpos($real, $uploadsReal) !== 0) {
  // Si NO está dentro de /uploads, bloqueamos por seguridad
  http_response_code(403);
  exit('Ruta no permitida');
}

/* =======================
   Headers y salida
   ======================= */
$mime  = !empty($dep['comprobante_mime']) ? (string)$dep['comprobante_mime'] : 'application/octet-stream';
$fname = !empty($dep['comprobante_nombre']) ? (string)$dep['comprobante_nombre'] : basename($real ?: $path);

// inline para iframe
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real ?: $path));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $fname) . '"');
header('X-Content-Type-Options: nosniff');

readfile($real ?: $path);
exit;