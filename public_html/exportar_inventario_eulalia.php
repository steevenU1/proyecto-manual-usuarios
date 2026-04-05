<?php
// exportar_inventario_eulalia.php — Export Excel Inventario Almacén Eulalia (con cantidad para accesorios)
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: 403.php"); exit(); }

$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','GerenteZona','Super'];
if (!in_array($ROL, $ALLOWED, true)) {
  header("Location: 403.php"); exit();
}

require_once __DIR__ . '/db.php';

/* ===== Helpers ===== */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nf($n){ return number_format((float)$n, 2, '.', ''); }

/* ===== Obtener ID de Eulalia ===== */
$idEulalia = 0;

// Intento exacto
if ($stmt = $conn->prepare("SELECT id FROM sucursales WHERE nombre='Eulalia' LIMIT 1")) {
  $stmt->execute();
  $res = $stmt->get_result();
  if ($row = $res->fetch_assoc()) {
    $idEulalia = (int)$row['id'];
  }
  $stmt->close();
}

if ($idEulalia <= 0) {
  // Fallback por si el nombre viene con acentos o variantes
  if ($stmt = $conn->prepare("SELECT id FROM sucursales WHERE nombre LIKE '%Eulalia%' LIMIT 1")) {
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
      $idEulalia = (int)$row['id'];
    }
    $stmt->close();
  }
}

if ($idEulalia <= 0) {
  die("No se encontró la sucursal 'Eulalia'. Verifica el catálogo de sucursales.");
}

/* ===== Filtros (mismos que inventario_eulalia.php) ===== */
$fImei        = $_GET['imei']          ?? '';
$fTipo        = $_GET['tipo_producto'] ?? '';
$fEstatus     = $_GET['estatus']       ?? '';
$fAntiguedad  = $_GET['antiguedad']    ?? '';
$fPrecioMin   = $_GET['precio_min']    ?? '';
$fPrecioMax   = $_GET['precio_max']    ?? '';

/* ===== Query principal (misma lógica que la vista) ===== */
$sql = "
  SELECT 
      i.id AS id_inv,
      p.id AS id_prod,
      p.marca, p.modelo, p.color, p.capacidad,
      p.imei1, p.imei2,
      COALESCE(p.costo_con_iva, p.costo, 0) AS costo_mostrar,
      p.precio_lista,
      (p.precio_lista - COALESCE(p.costo_con_iva, p.costo, 0)) AS profit,
      p.tipo_producto,
      i.estatus,
      i.fecha_ingreso,
      i.cantidad AS cantidad_inventario,
      -- es_accesorio: 1 cuando NO tiene IMEI (o vacío), 0 cuando sí
      (CASE WHEN (p.imei1 IS NULL OR p.imei1 = '') THEN 1 ELSE 0 END) AS es_accesorio,
      -- cantidad_mostrar: accesorios = i.cantidad; equipos = 1
      (CASE WHEN (p.imei1 IS NULL OR p.imei1 = '') THEN IFNULL(i.cantidad,0) ELSE 1 END) AS cantidad_mostrar,
      TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) AS antiguedad_dias
  FROM inventario i
  INNER JOIN productos p ON p.id = i.id_producto
  WHERE i.id_sucursal = ?
    AND i.estatus IN ('Disponible','En tránsito')
    AND COALESCE(i.cantidad, 0) > 0
";

$params = [];
$types  = "";

$params[] = $idEulalia;
$types   .= "i";

if ($fImei !== '') {
  $sql .= " AND (p.imei1 LIKE ? OR p.imei2 LIKE ?)";
  $like = "%" . $fImei . "%";
  $params[] = $like;
  $params[] = $like;
  $types   .= "ss";
}

if ($fTipo !== '') {
  $sql .= " AND p.tipo_producto = ?";
  $params[] = $fTipo;
  $types   .= "s";
}

if ($fEstatus !== '') {
  $sql .= " AND i.estatus = ?";
  $params[] = $fEstatus;
  $types   .= "s";
}

if ($fAntiguedad === '<30') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) < 30";
} elseif ($fAntiguedad === '30-90') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) BETWEEN 30 AND 90";
} elseif ($fAntiguedad === '>90') {
  $sql .= " AND TIMESTAMPDIFF(DAY, i.fecha_ingreso, NOW()) > 90";
}

if ($fPrecioMin !== '') {
  $sql .= " AND p.precio_lista >= ?";
  $params[] = (float)$fPrecioMin;
  $types   .= "d";
}
if ($fPrecioMax !== '') {
  $sql .= " AND p.precio_lista <= ?";
  $params[] = (float)$fPrecioMax;
  $types   .= "d";
}

$sql .= " ORDER BY i.fecha_ingreso ASC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  die("Error de consulta: " . $conn->error);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

/* ===== Cabeceras Excel ===== */
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=inventario_eulalia.xls");
header("Pragma: no-cache");
header("Expires: 0");

// BOM UTF-8
echo "\xEF\xBB\xBF";

/* ===== Render HTML-Excel ===== */
echo "<html><head><meta charset='UTF-8'></head><body>";
echo "<table border='1' cellspacing='0' cellpadding='4'>";

/* Encabezados */
echo "<tr style='background:#222;color:#fff;font-weight:bold'>";
echo "<td>ID Inventario</td>";
echo "<td>Marca</td>";
echo "<td>Modelo</td>";
echo "<td>Color</td>";
echo "<td>Capacidad</td>";
echo "<td>IMEI1</td>";
echo "<td>IMEI2</td>";
echo "<td>Tipo producto</td>";
echo "<td>Costo c/IVA</td>";
echo "<td>Precio lista</td>";
echo "<td>Profit</td>";
echo "<td>Cantidad</td>";
echo "<td>Estatus</td>";
echo "<td>Fecha ingreso</td>";
echo "<td>Antigüedad (días)</td>";
echo "</tr>";

/* Filas */
while ($row = $result->fetch_assoc()) {
  $dias          = (int)$row['antiguedad_dias'];
  $costoMostrar  = (float)$row['costo_mostrar'];
  $precioLista   = (float)$row['precio_lista'];
  $profit        = (float)$row['profit'];
  $cantidad      = (int)($row['cantidad_mostrar'] ?? 0);
  $fechaIngreso  = substr((string)$row['fecha_ingreso'], 0, 19); // fecha/hora completa si quieres

  echo "<tr>";
  echo "<td>".h($row['id_inv'])."</td>";
  echo "<td>".h($row['marca'])."</td>";
  echo "<td>".h($row['modelo'])."</td>";
  echo "<td>".h($row['color'])."</td>";
  echo "<td>".h($row['capacidad'] ?? '-')."</td>";

  // IMEI1 / IMEI2 con prefijo ' para que Excel no los formatee raro
  $imei1 = $row['imei1'] ?? '';
  $imei2 = $row['imei2'] ?? '';
  echo "<td>'".h($imei1 === '' ? '-' : $imei1)."'</td>";
  echo "<td>'".h($imei2 === '' ? '-' : $imei2)."'</td>";

  echo "<td>".h($row['tipo_producto'])."</td>";
  echo "<td>".nf($costoMostrar)."</td>";
  echo "<td>".nf($precioLista)."</td>";
  echo "<td>".nf($profit)."</td>";
  echo "<td>".h($cantidad)."</td>";
  echo "<td>".h($row['estatus'])."</td>";
  echo "<td>".h($fechaIngreso)."</td>";
  echo "<td>".h($dias)."</td>";
  echo "</tr>";
}

echo "</table></body></html>";

$stmt->close();
$conn->close();
