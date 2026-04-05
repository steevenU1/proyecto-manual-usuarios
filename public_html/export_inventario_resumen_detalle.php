<?php
// export_inventario_resumen_detalle.php
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';

// ===== Parámetros =====
$marca     = $_GET['marca']     ?? '';
$modelo    = $_GET['modelo']    ?? '';
$capacidad = $_GET['capacidad'] ?? '';
$sucursal  = (int)($_GET['sucursal'] ?? 0);
$tipo      = $_GET['tipo']      ?? '';  // opcional, por si quieres separar Equipo / Modem / Accesorio

if ($marca === '' || $modelo === '' || $capacidad === '') {
  die('Parámetros incompletos para exportar.');
}

// ===== Consulta (MISMA LÓGICA QUE EL DETALLE) =====
// - Solo estatus Disponible / En tránsito
// - No mostrar cantidad 0 (COALESCE(i.cantidad,0) > 0)
// - Si no tiene IMEI => usa inventario.cantidad
//   Si tiene IMEI => cuenta 1 por registro
$sql = "
  SELECT 
    s.id       AS id_sucursal,
    s.nombre   AS sucursal,
    COALESCE(NULLIF(p.color,''),'N/D') AS color,
    SUM(
      CASE
        WHEN (p.imei1 IS NULL OR p.imei1 = '') THEN COALESCE(i.cantidad,0)
        ELSE 1
      END
    ) AS piezas
  FROM inventario i
  INNER JOIN productos  p ON p.id = i.id_producto
  INNER JOIN sucursales s ON s.id = i.id_sucursal
  WHERE i.estatus IN ('Disponible','En tránsito')
    AND COALESCE(i.cantidad,0) > 0
    AND p.marca = ?
    AND p.modelo = ?
    AND p.capacidad = ?
";

$params = [$marca, $modelo, $capacidad];
$types  = "sss";

if ($tipo !== '') {
  $sql   .= " AND p.tipo_producto = ? ";
  $params[] = $tipo;
  $types   .= "s";
}

if ($sucursal > 0) {
  $sql   .= " AND s.id = ? ";
  $params[] = $sucursal;
  $types   .= "i";
}

$sql .= " GROUP BY s.id, s.nombre, color
          ORDER BY s.nombre, color";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  die("Error en la consulta: " . $conn->error);
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$totalPiezas = 0;
while($r = $res->fetch_assoc()){
  $r['piezas'] = (int)($r['piezas'] ?? 0);
  $rows[]      = $r;
  $totalPiezas += $r['piezas'];
}
$stmt->close();

// ===== Cabeceras Excel =====
$nombreArchivo = "detalle_inventario_" . preg_replace('/\s+/', '_', $marca . '_' . $modelo . '_' . $capacidad) . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"{$nombreArchivo}\"");
header("Pragma: no-cache");
header("Expires: 0");

// BOM UTF-8
echo "\xEF\xBB\xBF";

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ===== Render simple HTML-table (Excel lo abre sin problema) =====
echo "<html><head><meta charset='UTF-8'></head><body>";
echo "<h3>Detalle inventario por sucursal y color</h3>";
echo "<p><strong>Modelo:</strong> " . h("$marca $modelo $capacidad") . "</p>";
if ($tipo !== '') {
  echo "<p><strong>Tipo:</strong> " . h($tipo) . "</p>";
}
if ($sucursal > 0) {
  echo "<p><strong>Sucursal filtrada:</strong> " . (int)$sucursal . "</p>";
}

echo "<table border='1' cellspacing='0' cellpadding='4'>";
echo "<tr style='background:#222;color:#fff;font-weight:bold'>";
echo "<td>Sucursal</td>";
echo "<td>Color</td>";
echo "<td>Piezas</td>";
echo "</tr>";

if (empty($rows)) {
  echo "<tr><td colspan='3'>Sin equipos/accesorios con existencias para este modelo.</td></tr>";
} else {
  foreach($rows as $r){
    echo "<tr>";
    echo "<td>".h($r['sucursal'])."</td>";
    echo "<td>".h($r['color'])."</td>";
    echo "<td>".(int)$r['piezas']."</td>";
    echo "</tr>";
  }
  echo "<tr style='font-weight:bold;background:#f3f4f6'>";
  echo "<td colspan='2'>Total</td>";
  echo "<td>".(int)$totalPiezas."</td>";
  echo "</tr>";
}

echo "</table></body></html>";

$conn->close();
