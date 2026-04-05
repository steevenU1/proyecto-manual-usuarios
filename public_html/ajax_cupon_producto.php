<?php
// ajax_cupon_producto.php
// Busca cupón vigente con base en codigo_producto (desde inventario->productos)

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

$id_inventario = isset($_POST['id_inventario']) ? (int)$_POST['id_inventario'] : 0;

if ($id_inventario <= 0) {
  echo json_encode([
    'ok' => false,
    'message' => 'ID inventario inválido',
    'monto_cupon' => 0
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

try {

  /* 1) Obtener codigo_producto desde inventario */
  $sql = "
    SELECT p.codigo_producto AS codigo
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.id = ?
    LIMIT 1
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $id_inventario);
  $stmt->execute();
  $res = $stmt->get_result();

  if (!$res || !$res->num_rows) {
    echo json_encode(['ok' => true, 'monto_cupon' => 0], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $row = $res->fetch_assoc();
  $codigo = trim((string)($row['codigo'] ?? ''));

  if ($codigo === '') {
    echo json_encode(['ok' => true, 'monto_cupon' => 0], JSON_UNESCAPED_UNICODE);
    exit;
  }

  /* 2) Buscar cupón VIGENTE en cupones_descuento */
  // Reglas:
  // - activo=1
  // - fecha_inicio <= hoy
  // - fecha_fin >= hoy OR fecha_fin IS NULL
  // Usamos CURDATE() porque tus fechas se ven tipo DATE (YYYY-MM-DD).
  $sql2 = "
    SELECT descuento_mxn
    FROM cupones_descuento
    WHERE codigo_producto = ?
      AND activo = 1
      AND (fecha_inicio IS NULL OR fecha_inicio <= CURDATE())
      AND (fecha_fin    IS NULL OR fecha_fin    >= CURDATE())
    ORDER BY
      COALESCE(fecha_inicio, '1900-01-01') DESC,
      id DESC
    LIMIT 1
  ";

  $stmt2 = $conn->prepare($sql2);
  $stmt2->bind_param('s', $codigo);
  $stmt2->execute();
  $stmt2->bind_result($descuento);

  $monto = 0.0;
  if ($stmt2->fetch()) {
    $monto = (float)$descuento;
  }

  echo json_encode([
    'ok' => true,
    'monto_cupon' => $monto
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  echo json_encode([
    'ok' => false,
    'message' => $e->getMessage(),
    'monto_cupon' => 0
  ], JSON_UNESCAPED_UNICODE);
}