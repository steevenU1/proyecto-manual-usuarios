<?php
/**
 * export_sims_pospago_csv.php — Histórico SIMs Pospago (CSV) con TODAS las columnas
 * - Incluye todas las columnas de ventas_sims (prefijo v_) y detalle_venta_sim (prefijo d_)
 * - Enriquecido con usuario_nombre y sucursal_nombre cuando existen
 * Filtros:
 *   ?ini=YYYY-MM-DD (opcional)
 *   ?fin=YYYY-MM-DD (opcional)
 */

session_start();
if (!isset($_SESSION['id_usuario'])) { http_response_code(401); exit('No autorizado'); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

/* ---------- Helpers ---------- */
function columnExists(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}
function tableExists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='$t' LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}
function getCols(mysqli $conn, string $table): array {
  $t = $conn->real_escape_string($table);
  $q = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' ORDER BY ORDINAL_POSITION";
  $cols = [];
  if ($res = $conn->query($q)) {
    while ($r = $res->fetch_assoc()) $cols[] = $r['COLUMN_NAME'];
    $res->close();
  }
  return $cols;
}
function dateColVentasSims(mysqli $conn): string {
  if (columnExists($conn,'ventas_sims','fecha_venta')) return 'fecha_venta';
  if (columnExists($conn,'ventas_sims','created_at'))  return 'created_at';
  return 'fecha';
}

/* ---------- Filtros ---------- */
$ini = isset($_GET['ini']) ? trim($_GET['ini']) : '';
$fin = isset($_GET['fin']) ? trim($_GET['fin']) : '';
$dtCol = dateColVentasSims($conn);

$where = "v.tipo_venta='Pospago'";
$params = []; $types = '';
if ($ini !== '') { $where .= " AND v.$dtCol >= ?"; $params[] = $ini . " 00:00:00"; $types .= 's'; }
if ($fin !== '') { $where .= " AND v.$dtCol <= ?"; $params[] = $fin . " 23:59:59"; $types .= 's'; }

/* ---------- Columnas dinámicas: TODAS ---------- */
$vCols = getCols($conn, 'ventas_sims');
$dCols = tableExists($conn,'detalle_venta_sim') ? getCols($conn, 'detalle_venta_sim') : [];

$selectPieces = [];
foreach ($vCols as $c) {
  $selectPieces[] = "v.`$c` AS `v_$c`";
}
$hasDetalle = false;
$joinDet = '';
if (!empty($dCols)) {
  // Detectar llave de join
  $joinKey = '';
  if (in_array('id_venta_sim', $dCols, true)) $joinKey = 'd.id_venta_sim = v.id';
  elseif (in_array('id_venta', $dCols, true)) $joinKey = 'd.id_venta = v.id';
  if ($joinKey !== '') {
    $hasDetalle = true;
    $joinDet = "LEFT JOIN detalle_venta_sim d ON $joinKey";
    foreach ($dCols as $c) {
      $selectPieces[] = "d.`$c` AS `d_$c`";
    }
  }
}

/* ---------- Enriquecimiento ---------- */
$joinUser = '';
if (columnExists($conn,'ventas_sims','id_usuario') && tableExists($conn,'usuarios') && columnExists($conn,'usuarios','id') && columnExists($conn,'usuarios','nombre')) {
  $joinUser = "LEFT JOIN usuarios u ON u.id = v.id_usuario";
  $selectPieces[] = "u.nombre AS usuario_nombre";
} else {
  $selectPieces[] = "'' AS usuario_nombre";
}

$joinSuc = '';
if (columnExists($conn,'ventas_sims','id_sucursal') && tableExists($conn,'sucursales') && columnExists($conn,'sucursales','id') && columnExists($conn,'sucursales','nombre')) {
  $joinSuc = "LEFT JOIN sucursales s ON s.id = v.id_sucursal";
  $selectPieces[] = "s.nombre AS sucursal_nombre";
} else {
  // fallback si sucursal viene por usuarios
  if ($joinUser && columnExists($conn,'usuarios','id_sucursal') && tableExists($conn,'sucursales') && columnExists($conn,'sucursales','id') && columnExists($conn,'sucursales','nombre')) {
    $joinSuc = "LEFT JOIN sucursales s ON s.id = u.id_sucursal";
    $selectPieces[] = "s.nombre AS sucursal_nombre";
  } elseif ($joinUser && columnExists($conn,'usuarios','sucursal') && tableExists($conn,'sucursales') && columnExists($conn,'sucursales','id') && columnExists($conn,'sucursales','nombre')) {
    $joinSuc = "LEFT JOIN sucursales s ON s.id = u.sucursal";
    $selectPieces[] = "s.nombre AS sucursal_nombre";
  } else {
    $selectPieces[] = "'' AS sucursal_nombre";
  }
}

$select = implode(",\n  ", $selectPieces);

/* ---------- Query final ---------- */
$sql = "
  SELECT
  $select
  FROM ventas_sims v
  $joinUser
  $joinSuc
  $joinDet
  WHERE $where
  ORDER BY v.$dtCol DESC, v.id DESC
";
$stmt = $conn->prepare($sql);
if (!$stmt) { http_response_code(500); exit('Error al preparar consulta'); }
if ($types !== '') $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

/* ---------- Salida CSV ---------- */
$filename = 'reporte_sims_pospago_full_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');

$out = fopen('php://output', 'w');

/* Encabezados tal cual vienen del SELECT (v_* y d_*) */
$headers = [];
while ($finfo = $res->fetch_field()) $headers[] = $finfo->name;
fputcsv($out, $headers);

/* Filas */
while ($row = $res->fetch_assoc()) {
  // Normaliza campos booleanos típicos si existen
  foreach (['v_es_esim','d_es_esim','v_portabilidad','d_portabilidad'] as $k) {
    if (array_key_exists($k, $row)) {
      $val = $row[$k];
      if ($val === null) { /* nada */ }
      elseif (is_numeric($val)) { $row[$k] = ((int)$val) ? 'Sí' : 'No'; }
      else {
        $v = strtolower(trim((string)$val));
        $row[$k] = in_array($v, ['1','si','sí','true','t','y','yes','s'], true) ? 'Sí' :
                   (in_array($v, ['0','no','false','f','n'], true) ? 'No' : $val);
      }
    }
  }
  fputcsv($out, array_values($row));
}
fclose($out);
$stmt->close();
