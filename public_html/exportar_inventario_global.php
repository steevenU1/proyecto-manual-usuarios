<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: 403.php"); exit(); }

$ROL_RAW = $_SESSION['rol'] ?? '';
$ROL_NORM = strtolower(trim((string)$ROL_RAW));
$ROL_NORM = str_replace([' ', '-'], '_', $ROL_NORM);
$ROL_NORM = preg_replace('/_+/', '_', $ROL_NORM);

/* ===== Roles permitidos =====
   - LUGA: admin, gerentezona, logistica
   - SUBDIS: subdis_admin, subdis_gerente, subdis_ejecutivo
*/
$ALLOWED_NORM = ['admin','gerentezona','logistica','subdis_admin','subdis_gerente','subdis_ejecutivo'];
if (!in_array($ROL_NORM, $ALLOWED_NORM, true)) { header("Location: 403.php"); exit(); }

/* ===== Detectar tipo de usuario ===== */
$isSubdisUser = in_array($ROL_NORM, ['subdis_admin','subdis_gerente','subdis_ejecutivo'], true);
$isLugaUser   = !$isSubdisUser;

require_once __DIR__.'/db.php';

/* ===== Filtros (mismos que la vista) ===== */
$filtroImei       = $_GET['imei']        ?? '';
$filtroSucursal   = $_GET['sucursal']    ?? '';
$filtroEstatus    = $_GET['estatus']     ?? '';
$filtroAntiguedad = $_GET['antiguedad']  ?? '';
$filtroPrecioMin  = $_GET['precio_min']  ?? '';
$filtroPrecioMax  = $_GET['precio_max']  ?? '';
$filtroModelo     = $_GET['modelo']      ?? '';

/* ===== Nuevo: mismo switch que la vista =====
   LUGA:
   - por defecto SOLO LUGA
   - con mostrar_subdis=1 incluye también Subdis
*/
$mostrarSubdis = ($isLugaUser && isset($_GET['mostrar_subdis']) && (string)$_GET['mostrar_subdis'] === '1');

/* ===== Scope Subdis ===== */
$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
$idSubdis  = 0;

if ($isSubdisUser) {
  $idSubdis = (int)($_SESSION['id_subdis'] ?? 0);
  if ($idSubdis <= 0) $idSubdis = (int)($_SESSION['id_subdistribuidor'] ?? 0);

  if ($idSubdis <= 0 && $idUsuario > 0) {
    try {
      $stmtSub = $conn->prepare("
        SELECT COALESCE(s.id_subdis,0) AS id_subdis
        FROM usuarios u
        INNER JOIN sucursales s ON s.id = u.id_sucursal
        WHERE u.id = ?
        LIMIT 1
      ");
      $stmtSub->bind_param('i', $idUsuario);
      $stmtSub->execute();
      $rsSub = $stmtSub->get_result();
      if ($rwSub = $rsSub->fetch_assoc()) {
        $idSubdis = (int)($rwSub['id_subdis'] ?? 0);
      }
      $stmtSub->close();
    } catch (Throwable $e) {
      // noop
    }
  }

  if ($idSubdis > 0) {
    $_SESSION['id_subdis'] = $idSubdis;
  }
}

/* ===== Helpers ===== */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nf($n){ return number_format((float)$n, 2, '.', ''); }

function codigo_fallback_from_row($row){
  $partes = array_filter([
    $row['tipo_producto'] ?? '',
    $row['marca'] ?? '',
    $row['modelo'] ?? '',
    $row['color'] ?? '',
    $row['capacidad'] ?? ''
  ], fn($x)=>$x!=='');
  if (!$partes) return '-';
  $code = strtoupper(implode('-', $partes));
  return preg_replace('/\s+/', '', $code);
}

function normalizarPropiedad($valor){
  $v = trim((string)$valor);
  if ($v === '') return 'Luga';
  $vLower = mb_strtolower($v, 'UTF-8');
  if (in_array($vLower, ['subdis','subdistribuidor','subdistribuidora'], true)) {
    return 'Subdis';
  }
  return 'Luga';
}

/* ===== Descubrir columnas reales de productos (en orden) ===== */
$cols = [];
if ($rsCols = $conn->query("SHOW COLUMNS FROM productos")) {
  while ($c = $rsCols->fetch_assoc()) $cols[] = $c['Field'];
}

/* ===== Detectar si inventario.cantidad existe ===== */
$hasCantidad = false;
if ($rsC = $conn->query("SHOW COLUMNS FROM inventario LIKE 'cantidad'")) {
  $hasCantidad = $rsC->num_rows > 0;
}

/* ===== SELECT base armado según existencia de 'cantidad' ===== */
$selectCantidad =
  $hasCantidad
    ? " i.cantidad AS cantidad_inventario,
        (CASE WHEN (p.imei1 IS NULL OR p.imei1='') THEN IFNULL(i.cantidad,0) ELSE 1 END) AS cantidad_mostrar "
    : " NULL AS cantidad_inventario,
        (CASE WHEN (p.imei1 IS NULL OR p.imei1='') THEN 0 ELSE 1 END) AS cantidad_mostrar ";

/* ===== Columnas sensibles para Subdis ===== */
$COLS_SENSIBLES = ['costo','costo_con_iva','costo_dto','costo_dto_iva'];

/* ===== Consulta ===== */
$sql = "
  SELECT
    i.id AS id_inventario,
    s.id AS id_sucursal,
    s.nombre AS sucursal,
    COALESCE(NULLIF(TRIM(s.propiedad), ''), 'Luga') AS propiedad,
    COALESCE(s.id_subdis, 0) AS id_subdis,
    p.*,
    COALESCE(p.costo_con_iva, p.costo, 0) AS costo_mostrar,
    (p.precio_lista - COALESCE(p.costo_con_iva, p.costo, 0)) AS profit,
    {$selectCantidad},
    i.estatus AS estatus_inventario,
    i.fecha_ingreso,
    TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) AS antiguedad_dias,

    /* ===== Sucursal destino cuando el equipo está en tránsito ===== */
    (
      SELECT sd.nombre
      FROM detalle_traspaso dt
      INNER JOIN traspasos t ON t.id = dt.id_traspaso
      INNER JOIN sucursales sd ON sd.id = t.id_sucursal_destino
      WHERE dt.id_inventario = i.id
        AND dt.resultado = 'Pendiente'
        AND t.estatus IN ('Pendiente','Parcial')
      ORDER BY t.id DESC, dt.id DESC
      LIMIT 1
    ) AS sucursal_transito

  FROM inventario i
  INNER JOIN productos p ON p.id = i.id_producto
  INNER JOIN sucursales s ON s.id = i.id_sucursal
  WHERE i.estatus IN ('Disponible','En tránsito')
";

/* ===== Igual que en la vista: cantidad > 0 si existe columna ===== */
if ($hasCantidad) {
  $sql .= " AND COALESCE(i.cantidad, 0) > 0";
}

$params = [];
$types  = "";

/* ===== Scope multi-tenant ===== */
if ($isSubdisUser) {
  if ($idSubdis > 0) {
    $sql .= " AND (COALESCE(NULLIF(TRIM(s.propiedad), ''), '') IN ('Subdis','Subdistribuidor','Subdistribuidora') AND COALESCE(s.id_subdis,0)=?)";
    $params[] = $idSubdis;
    $types .= "i";
  } else {
    // seguridad: si no se pudo determinar subdis, no exportar nada
    $sql .= " AND 1=0";
  }
} else {
  // LUGA: por defecto SOLO LUGA
  if (!$mostrarSubdis) {
    $sql .= " AND COALESCE(NULLIF(TRIM(s.propiedad), ''), 'Luga') NOT IN ('Subdis','Subdistribuidor','Subdistribuidora')";
  }
}

/* ===== filtros ===== */
if ($filtroSucursal !== '') {
  $sql .= " AND s.id = ?";
  $params[] = (int)$filtroSucursal;
  $types .= "i";
}
if ($filtroImei !== '') {
  $sql .= " AND (p.imei1 LIKE ? OR p.imei2 LIKE ?)";
  $like = "%$filtroImei%";
  $params[] = $like;
  $params[] = $like;
  $types .= "ss";
}
if ($filtroModelo !== '') {
  $sql .= " AND p.modelo LIKE ?";
  $params[] = "%$filtroModelo%";
  $types .= "s";
}
if ($filtroEstatus !== '') {
  $sql .= " AND i.estatus = ?";
  $params[] = $filtroEstatus;
  $types .= "s";
}
if ($filtroAntiguedad == '<30') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) < 30";
} elseif ($filtroAntiguedad == '30-90') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) BETWEEN 30 AND 90";
} elseif ($filtroAntiguedad == '>90') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) > 90";
}
if ($filtroPrecioMin !== '') {
  $sql .= " AND p.precio_lista >= ?";
  $params[] = (float)$filtroPrecioMin;
  $types .= "d";
}
if ($filtroPrecioMax !== '') {
  $sql .= " AND p.precio_lista <= ?";
  $params[] = (float)$filtroPrecioMax;
  $types .= "d";
}

$sql .= " ORDER BY s.nombre, i.fecha_ingreso DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();

/* ===== Nombre de archivo ===== */
$filename = 'inventario_global.xls';
if ($isSubdisUser) {
  $filename = 'inventario_subdis.xls';
} else {
  $filename = $mostrarSubdis ? 'inventario_global_con_subdis.xls' : 'inventario_global_luga.xls';
}

/* ===== Cabeceras para que Excel abra el HTML ===== */
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename={$filename}");
header("Pragma: no-cache");
header("Expires: 0");

/* BOM UTF-8 */
echo "\xEF\xBB\xBF";

/* ===== Render ===== */
echo "<html><head><meta charset='UTF-8'></head><body>";
echo "<table border='1' cellspacing='0' cellpadding='4'>";

/* Encabezados */
echo "<tr style='background:#222;color:#fff;font-weight:bold'>";
echo "<td>ID Inventario</td>";
echo "<td>Sucursal</td>";
echo "<td>Propiedad</td>";

foreach ($cols as $col) {

  // SUBDIS: NO exportar costos
  if ($isSubdisUser && in_array($col, $COLS_SENSIBLES, true)) continue;

  // Etiquetas amigables
  if ($col === 'costo_con_iva') {
    echo "<td>Costo c/IVA</td>";
  } elseif ($isSubdisUser && $col === 'precio_lista') {
    echo "<td>Precio Sugerido</td>";
  } else {
    echo "<td>".h($col)."</td>";
  }
}

// SUBDIS: NO exportar profit
if (!$isSubdisUser) echo "<td>Profit</td>";

echo "<td>Cantidad</td>";
echo "<td>Estatus Inventario</td>";
echo "<td>Sucursal destino tránsito</td>";
echo "<td>Fecha Ingreso</td>";
echo "<td>Antigüedad (días)</td>";
echo "</tr>";

/* Filas */
while ($row = $result->fetch_assoc()) {
  echo "<tr>";
  echo "<td>".h($row['id_inventario'])."</td>";
  echo "<td>".h($row['sucursal'])."</td>";
  echo "<td>".h(normalizarPropiedad($row['propiedad'] ?? 'Luga'))."</td>";

  foreach ($cols as $col) {

    // SUBDIS: NO exportar costos
    if ($isSubdisUser && in_array($col, $COLS_SENSIBLES, true)) continue;

    $val = $row[$col] ?? '';

    if ($col === 'codigo_producto') {
      if ($val === '' || $val === null) { $val = codigo_fallback_from_row($row); }
      echo "<td>".h($val)."</td>";
      continue;
    }

    if ($col === 'imei1' || $col === 'imei2') {
      echo "<td>'".h($val === '' ? '-' : $val)."'</td>";
      continue;
    }

    if (in_array($col, ['costo','costo_con_iva','costo_dto','costo_dto_iva','precio_lista'], true)) {
      echo "<td>".nf($val)."</td>";
      continue;
    }

    echo "<td>".h($val)."</td>";
  }

  // Profit solo LUGA
  if (!$isSubdisUser) echo "<td>".nf($row['profit'])."</td>";

  // Cantidad
  $cantidadMostrar = isset($row['cantidad_mostrar']) ? (int)$row['cantidad_mostrar'] : 1;
  echo "<td>".h($cantidadMostrar)."</td>";

  echo "<td>".h($row['estatus_inventario'])."</td>";

  // Sucursal destino tránsito
  $destinoTransito = '-';
  if (($row['estatus_inventario'] ?? '') === 'En tránsito') {
    $destinoTransito = $row['sucursal_transito'] ?: 'Sin destino detectado';
  }
  echo "<td>".h($destinoTransito)."</td>";

  echo "<td>".h($row['fecha_ingreso'])."</td>";
  echo "<td>".h($row['antiguedad_dias'])."</td>";
  echo "</tr>";
}

echo "</table></body></html>";

$stmt->close();
$conn->close();