<?php
// ajax_promo_descuento_combos.php
// Devuelve <option> de inventario disponible en sucursal, filtrado por lista de combos de una promo.
// ✅ Normaliza codigo_producto (quita CHAR(160)/NBSP) para evitar "no detecta" por espacios raros.
// ✅ Valida fechas de la promo.
// ✅ Si no hay resultados, muestra mensaje.

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: text/html; charset=UTF-8');

if (!isset($_SESSION['id_usuario'])) {
  echo '<option value="">No autenticado</option>';
  exit;
}

require_once __DIR__ . '/db.php';

$promoId    = (int)($_POST['promo_id'] ?? 0);
$idSucursal = (int)($_POST['id_sucursal'] ?? 0);
$excludeInv = (int)($_POST['exclude_inventario'] ?? 0);

if ($promoId <= 0 || $idSucursal <= 0) {
  echo '<option value="">Parámetros inválidos</option>';
  exit;
}

$hoy = date('Y-m-d');

// Traer porcentaje de descuento (y validar vigencia)
$stP = $conn->prepare("
  SELECT porcentaje_descuento
  FROM promos_equipos_descuento
  WHERE id = ?
    AND activa = 1
    AND (fecha_inicio IS NULL OR fecha_inicio <= ?)
    AND (fecha_fin    IS NULL OR fecha_fin    >= ?)
  LIMIT 1
");
$stP->bind_param("iss", $promoId, $hoy, $hoy);
$stP->execute();
$promoRow = $stP->get_result()->fetch_assoc();
$stP->close();

if (!$promoRow) {
  echo '<option value="">Promo inválida o fuera de vigencia</option>';
  exit;
}

$porc = (float)($promoRow['porcentaje_descuento'] ?? 0);
if ($porc <= 0) $porc = 50.0;

// Lista de combos permitidos (codigos) normalizados en SQL
$st = $conn->prepare("
  SELECT UPPER(TRIM(REPLACE(codigo_producto, CHAR(160), ' '))) AS codigo
  FROM promos_equipos_descuento_combo
  WHERE promo_id = ? AND activo = 1
");
$st->bind_param("i", $promoId);
$st->execute();
$res = $st->get_result();

$codigos = [];
while ($r = $res->fetch_assoc()) {
  $c = strtoupper(trim((string)$r['codigo']));
  // colapsar múltiples espacios (por si acaso)
  $c = preg_replace('/\s+/', ' ', $c);
  if ($c !== '') $codigos[] = $c;
}
$st->close();

if (!$codigos) {
  echo '<option value="">No hay combos configurados para esta promo</option>';
  exit;
}

// Armar IN dinámico seguro
$placeholders = implode(',', array_fill(0, count($codigos), '?'));
$types = str_repeat('s', count($codigos));

// Query inventario disponible en sucursal + codigo en lista (normalizado)
$sql = "
  SELECT
    i.id AS id_inventario,
    p.marca, p.modelo, p.color,
    COALESCE(p.capacidad,'') AS capacidad,
    COALESCE(p.imei1,'') AS imei1,
    COALESCE(p.imei2,'') AS imei2,
    COALESCE(p.precio_lista,0) AS precio_lista,
    UPPER(TRIM(REPLACE(p.codigo_producto, CHAR(160), ' '))) AS codigo_producto
  FROM inventario i
  INNER JOIN productos p ON p.id = i.id_producto
  WHERE i.estatus = 'Disponible'
    AND i.id_sucursal = ?
    " . ($excludeInv > 0 ? " AND i.id <> ? " : "") . "
    AND UPPER(TRIM(REPLACE(p.codigo_producto, CHAR(160), ' '))) IN ($placeholders)
  ORDER BY p.marca, p.modelo, p.capacidad, p.color, p.imei1
";

$stmt = $conn->prepare($sql);

if ($excludeInv > 0) {
  $bindTypes = "ii" . $types;
  $params = array_merge([$idSucursal, $excludeInv], $codigos);
} else {
  $bindTypes = "i" . $types;
  $params = array_merge([$idSucursal], $codigos);
}

$bind = [];
$bind[] = $bindTypes;
for ($i=0; $i<count($params); $i++) $bind[] = &$params[$i];
call_user_func_array([$stmt, 'bind_param'], $bind);

$stmt->execute();
$rs = $stmt->get_result();

echo '<option value="">Seleccione equipo con descuento...</option>';

$hay = false;

while ($row = $rs->fetch_assoc()) {
  $hay = true;

  $idInv = (int)$row['id_inventario'];
  $marca = (string)$row['marca'];
  $modelo = (string)$row['modelo'];
  $color = (string)$row['color'];
  $cap = trim((string)$row['capacidad']);
  $imei1 = trim((string)$row['imei1']);
  $imei2 = trim((string)$row['imei2']);
  $precioLista = (float)$row['precio_lista'];

  $precioConDesc = $precioLista * (1 - ($porc / 100));
  if ($precioConDesc < 0) $precioConDesc = 0;

  $label = $marca . ' ' . $modelo;
  if ($cap !== '') $label .= ' ' . $cap;
  if ($color !== '') $label .= ' - ' . $color;
  if ($imei1 !== '') $label .= ' | IMEI1: ' . $imei1;
  if ($imei2 !== '') $label .= ' | IMEI2: ' . $imei2;

  echo '<option value="'.(int)$idInv.'"'
    .' data-precio-lista="'.htmlspecialchars(number_format($precioLista, 2, '.', ''), ENT_QUOTES, 'UTF-8').'"'
    .' data-porc-desc="'.htmlspecialchars(number_format($porc, 2, '.', ''), ENT_QUOTES, 'UTF-8').'"'
    .' data-precio-desc="'.htmlspecialchars(number_format($precioConDesc, 2, '.', ''), ENT_QUOTES, 'UTF-8').'"'
    .'>'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</option>';
}

$stmt->close();

if (!$hay) {
  echo '<option value="" disabled>(No hay equipos disponibles que entren en el listado de combos)</option>';
}
