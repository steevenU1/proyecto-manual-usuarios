<?php
// export_payjoy_tc.php — Export CSV PayJoy TC (scope=week|month, respeta filtros del historial)
// + Soporte Subdis: propiedad/id_subdis si existen columnas. (y nombre_comercial si existe tabla subdistribuidores)

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

/* ================= Helpers DB ================= */
function column_exists(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) return false;
  $st->bind_param('ss', $table, $column);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
}
function table_exists(mysqli $conn, string $table): bool {
  $st = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? LIMIT 1");
  if (!$st) return false;
  $st->bind_param('s', $table);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
}

/* ===== Contexto de sesión / roles ===== */
$ROL        = $_SESSION['rol'] ?? 'Ejecutivo';
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$idSubdisSesion = (int)($_SESSION['id_subdis'] ?? 0);

$isAdmin     = in_array($ROL, ['Admin','SuperAdmin','RH'], true);
$isGerente   = in_array($ROL, ['Gerente','Gerente General','GerenteZona','GerenteSucursal'], true);
$isSubdis    = in_array($ROL, ['Subdistribuidor','Subdis','Master Admin','MasterAdmin','Master_Admin'], true);
$isEjecutivo = !$isAdmin && !$isGerente && !$isSubdis;

/* ===== Detectar columnas subdis en ventas_payjoy_tc ===== */
$TBL = 'ventas_payjoy_tc';
$HAS_PROP  = column_exists($conn, $TBL, 'propiedad');
$HAS_SUBID = column_exists($conn, $TBL, 'id_subdis');

$SUBDIS_TAB_OK = table_exists($conn, 'subdistribuidores')
                 && column_exists($conn, 'subdistribuidores', 'id')
                 && column_exists($conn, 'subdistribuidores', 'nombre_comercial');

/* ===== Parámetros de filtros que llegan desde historial_payjoy_tc.php ===== */
$scope    = $_GET['scope'] ?? 'week'; // week | month
$q        = trim($_GET['q'] ?? '');
$fil_suc  = isset($_GET['id_sucursal']) ? (int)$_GET['id_sucursal'] : 0;
$fil_user = isset($_GET['id_usuario'])  ? (int)$_GET['id_usuario']  : 0;

// NUEVO: Admin filtros subdis/propiedad
$fil_propiedad = $_GET['propiedad'] ?? '';
if (!in_array($fil_propiedad, ['', 'Luga', 'Subdistribuidor'], true)) $fil_propiedad = '';
$fil_subdis_id = isset($_GET['subdis_id']) ? (int)$_GET['subdis_id'] : 0;

/* ===== Rango de fechas ===== */
if ($scope === 'month') {
  $ini = $_GET['m_ini'] ?? '';
  $fin = $_GET['m_fin'] ?? '';
} else {
  $ini = $_GET['ini'] ?? '';
  $fin = $_GET['fin'] ?? '';
}

if (!$ini || !$fin) {
  http_response_code(400);
  echo "Falta rango (ini/fin o m_ini/m_fin)";
  exit();
}

/* NO re-calculamos nada: confiamos en el rango que viene de la vista */
$iniSQL = $ini;
$finSQL = $fin;

/* ===== SQL base (mismo FROM/JOIN que en historial) ===== */
$joins = "";
$selExtra = "";
if ($HAS_PROP)  $selExtra .= ", v.propiedad";
if ($HAS_SUBID) $selExtra .= ", v.id_subdis";

if ($HAS_SUBID && $SUBDIS_TAB_OK) {
  $joins   .= " LEFT JOIN subdistribuidores sd ON sd.id = v.id_subdis ";
  $selExtra .= ", sd.nombre_comercial AS subdis_nombre";
}

$sql = "
  SELECT 
      v.id,
      v.fecha_venta,
      s.nombre  AS sucursal,
      u.nombre  AS usuario,
      v.nombre_cliente,
      v.tag,
      v.comision
      {$selExtra}
  FROM ventas_payjoy_tc v
  INNER JOIN usuarios   u ON u.id = v.id_usuario
  INNER JOIN sucursales s ON s.id = v.id_sucursal
  {$joins}
  WHERE v.fecha_venta BETWEEN ? AND ?
";

$params = [$iniSQL, $finSQL];
$types  = "ss";

/* ===== Filtros por rol (alineado a la vista) ===== */
if ($isEjecutivo) {
  $sql      .= " AND v.id_usuario=? AND v.id_sucursal=? ";
  $params[]  = $idUsuario;
  $params[]  = $idSucursal;
  $types    .= "ii";

} elseif ($isGerente) {
  $sql      .= " AND v.id_sucursal=? ";
  $params[]  = $idSucursal;
  $types    .= "i";

  if ($fil_user > 0) {
    $sql      .= " AND v.id_usuario=? ";
    $params[]  = $fil_user;
    $types    .= "i";
  }

} elseif ($isSubdis) {
  // Subdis: filtrar por propiedad/id_subdis si existen columnas
  if ($HAS_PROP) {
    $sql     .= " AND v.propiedad=? ";
    $params[] = 'Subdistribuidor';
    $types   .= "s";
  }
  if ($HAS_SUBID && $idSubdisSesion > 0) {
    $sql     .= " AND v.id_subdis=? ";
    $params[] = $idSubdisSesion;
    $types   .= "i";
  } else {
    // fallback seguro
    $sql     .= " AND v.id_usuario=? ";
    $params[] = $idUsuario;
    $types   .= "i";
  }

} else {
  // Admin
  if ($fil_suc > 0) {
    $sql      .= " AND v.id_sucursal=? ";
    $params[]  = $fil_suc;
    $types    .= "i";
  }
  if ($fil_user > 0) {
    $sql      .= " AND v.id_usuario=? ";
    $params[]  = $fil_user;
    $types    .= "i";
  }

  if ($HAS_PROP && $fil_propiedad !== '') {
    $sql      .= " AND v.propiedad=? ";
    $params[]  = $fil_propiedad;
    $types    .= "s";
  }
  if ($HAS_SUBID && $fil_subdis_id > 0) {
    $sql      .= " AND v.id_subdis=? ";
    $params[]  = $fil_subdis_id;
    $types    .= "i";
  }
}

/* ===== Búsqueda por TAG / Cliente ===== */
if ($q !== '') {
  $sql      .= " AND (v.tag LIKE CONCAT('%',?,'%') OR v.nombre_cliente LIKE CONCAT('%',?,'%')) ";
  $params[]  = $q;
  $params[]  = $q;
  $types    .= "ss";
}

$sql .= " ORDER BY v.fecha_venta DESC, v.id DESC ";

/* ===== Ejecutar query ===== */
$stmt = $conn->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo "Error al preparar consulta: " . $conn->error;
  exit();
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

/* ===== Headers CSV ===== */
$fname = "payjoy_tc_".$scope."_".date('Ymd_His').".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');

$out = fopen('php://output', 'w');

/* Header dinámico */
$header = ['ID','Fecha','Sucursal','Usuario'];
if ($HAS_PROP)  $header[] = 'Propiedad';
if ($HAS_SUBID) $header[] = 'ID Subdis';
if ($HAS_SUBID && $SUBDIS_TAB_OK) $header[] = 'Subdis';
$header = array_merge($header, ['Cliente','TAG','Comisión']);
fputcsv($out, $header);

$total = 0.0;
$count = 0;

while ($r = $res->fetch_assoc()) {
  $fechaFmt = $r['fecha_venta'];
  try { $fechaFmt = (new DateTime($r['fecha_venta']))->format('Y-m-d H:i'); } catch (Exception $e) {}

  $row = [
    $r['id'],
    $fechaFmt,
    $r['sucursal'],
    $r['usuario'],
  ];

  if ($HAS_PROP)  $row[] = $r['propiedad'] ?? '';
  if ($HAS_SUBID) $row[] = $r['id_subdis'] ?? '';
  if ($HAS_SUBID && $SUBDIS_TAB_OK) $row[] = $r['subdis_nombre'] ?? '';

  $row[] = $r['nombre_cliente'];
  $row[] = $r['tag'];
  $row[] = number_format((float)$r['comision'], 2, '.', '');

  fputcsv($out, $row);

  $total += (float)$r['comision'];
  $count++;
}

fputcsv($out, []);
fputcsv($out, ['Total filas', $count, '', '', '', '', '', 'Total comisión', number_format($total, 2, '.', '')]);

fclose($out);
exit();
