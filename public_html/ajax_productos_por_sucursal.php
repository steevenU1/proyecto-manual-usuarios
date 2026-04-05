<?php
// ajax_productos_por_sucursal.php
// Devuelve <option> solo de EQUIPOS (no accesorios ni SIMs), con IMEI, disponibles por sucursal.
// Ahora incluye precio_lista y precio_combo como data-attributes.

include 'db.php';
header('Content-Type: text/html; charset=utf-8');

$id_sucursal = isset($_POST['id_sucursal']) ? (int)$_POST['id_sucursal'] : 0;
if ($id_sucursal <= 0) {
  echo '<option value="">Sucursal inválida</option>';
  exit;
}

// ==== Helpers seguros ====
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $table  = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return $rs && $rs->num_rows > 0;
}

$has_i_imei1    = hasColumn($conn,'inventario','imei1');
$has_i_imei2    = hasColumn($conn,'inventario','imei2');
$has_p_imei1    = hasColumn($conn,'productos','imei1');
$has_p_imei2    = hasColumn($conn,'productos','imei2');
$has_p_tipop    = hasColumn($conn,'productos','tipo_producto');
$has_p_pcombo   = hasColumn($conn,'productos','precio_combo');
$has_p_plista   = hasColumn($conn,'productos','precio_lista');

// Selección flexible de IMEIs
$sel_imei1 = $has_i_imei1 ? 'i.imei1' : ($has_p_imei1 ? 'p.imei1' : "''");
$sel_imei2 = $has_i_imei2 ? 'i.imei2' : ($has_p_imei2 ? 'p.imei2' : "''");

// Selección segura de precios
$sel_precio_lista = $has_p_plista
  ? 'COALESCE(p.precio_lista,0) AS precio_lista'
  : '0 AS precio_lista';

$sel_precio_combo = $has_p_pcombo
  ? 'COALESCE(p.precio_combo,0) AS precio_combo'
  : '0 AS precio_combo';

// === Filtro de tipo_producto (excluir accesorios, SIMs, modems, relojes, etc.) ===
$excluirAccesorios = $has_p_tipop
  ? " AND (p.tipo_producto IS NULL OR LOWER(p.tipo_producto) NOT IN (
        'accesorio','accesorios','sim','chip','watch','reloj',
        'smartwatch','cargador','cable','audifonos','audífonos',
        'mica','funda','case','estuche','bocina','modem lte','router'
      ))"
  : "";

// === Exigir que tenga al menos un IMEI válido ===
$exigirImei = " AND (NULLIF($sel_imei1,'') IS NOT NULL OR NULLIF($sel_imei2,'') IS NOT NULL)";

// === Consulta principal ===
$sql = "
  SELECT 
    i.id               AS id_inventario,
    p.id               AS id_producto,
    COALESCE(p.marca,'')   AS marca,
    COALESCE(p.modelo,'')  AS modelo,
    COALESCE(p.color,'')   AS color,
    $sel_imei1         AS imei1,
    $sel_imei2         AS imei2,
    $sel_precio_lista,
    $sel_precio_combo
  FROM inventario i
  INNER JOIN productos p ON i.id_producto = p.id
  WHERE i.id_sucursal = ?
    AND i.estatus = 'Disponible'
    $excluirAccesorios
    $exigirImei
  ORDER BY p.marca, p.modelo, p.color, i.id
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  echo '<option value="">Error SQL: '.htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8').'</option>';
  exit;
}

$stmt->bind_param("i", $id_sucursal);
if (!$stmt->execute()) {
  echo '<option value="">Error ejecutando: '.htmlspecialchars($stmt->error, ENT_QUOTES, 'UTF-8').'</option>';
  exit;
}

$res = $stmt->get_result();
$options = '<option value="">Seleccione un equipo...</option>';

if ($res && $res->num_rows > 0) {
  while ($row = $res->fetch_assoc()) {
    $idInv        = (int)$row['id_inventario'];
    $idProd       = (int)$row['id_producto'];
    $marca        = trim((string)$row['marca']);
    $modelo       = trim((string)$row['modelo']);
    $color        = trim((string)$row['color']);
    $imei1        = trim((string)$row['imei1']);
    $imei2        = trim((string)$row['imei2']);
    $precioLista  = isset($row['precio_lista']) ? (float)$row['precio_lista'] : 0.0;
    $precioCombo  = isset($row['precio_combo']) ? (float)$row['precio_combo'] : 0.0;

    // Texto legible
    $nombre  = trim("$marca $modelo");
    if ($color) {
      $nombre .= " ($color)";
    }

    $imeiTxt = $imei1 ? "IMEI1: $imei1" : "IMEI1: —";
    if ($imei2) {
      $imeiTxt .= " · IMEI2: $imei2";
    }

    $precioListaTxt = '$' . number_format($precioLista, 2, '.', ',');
    if ($precioCombo > 0) {
      $precioComboTxt = '$' . number_format($precioCombo, 2, '.', ',');
      $precioTxt = "Lista: {$precioListaTxt} · Combo: {$precioComboTxt}";
    } else {
      $precioTxt = $precioListaTxt;
    }

    $label = "$nombre — $imeiTxt — $precioTxt";

    // Valores crudos (numéricos) para JS
    $dataPrecioLista = htmlspecialchars(sprintf('%.2F', $precioLista), ENT_QUOTES, 'UTF-8');
    $dataPrecioCombo = htmlspecialchars(sprintf('%.2F', $precioCombo), ENT_QUOTES, 'UTF-8');
    $dataImei1       = htmlspecialchars($imei1, ENT_QUOTES, 'UTF-8');
    $dataImei2       = htmlspecialchars($imei2, ENT_QUOTES, 'UTF-8');

    $options .= '<option value="'.$idInv.'" '.
                'data-idproducto="'.$idProd.'" '.
                'data-imei1="'.$dataImei1.'" '.
                'data-imei2="'.$dataImei2.'" '.
                'data-precio-lista="'.$dataPrecioLista.'" '.
                'data-precio-combo="'.$dataPrecioCombo.'">'.
                htmlspecialchars($label, ENT_QUOTES, 'UTF-8').
                '</option>';
  }
} else {
  $options .= '<option value="">Sin equipos disponibles en esta sucursal</option>';
}

echo $options;
