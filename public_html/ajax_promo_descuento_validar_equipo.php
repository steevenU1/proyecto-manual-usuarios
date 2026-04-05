<?php
// ajax_promo_descuento_validar_equipo.php
// Valida que un inventario sea ELEGIBLE como "segundo equipo con descuento" (combo) para una promo,
// y calcula su precio con descuento (precio_lista * (1 - porcentaje/100)).
//
// INPUT (POST):
// - promo_id (int)
// - id_inventario (int)
//
// OUTPUT (JSON):
// - ok (bool)
// - elegible (bool)
// - porcentaje_descuento (float)
// - precio_lista (float)
// - precio_descuento (float)
// - codigo_producto (string)

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['id_usuario'])) {
  echo json_encode(['ok'=>false,'message'=>'No autenticado'], JSON_UNESCAPED_UNICODE);
  exit;
}

require_once __DIR__ . '/db.php';

function respond($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$promoId = (int)($_POST['promo_id'] ?? 0);
$idInv   = (int)($_POST['id_inventario'] ?? 0);

if ($promoId <= 0 || $idInv <= 0) respond(['ok'=>false,'message'=>'Parámetros inválidos']);

$hoy = date('Y-m-d');

// 1) Verificar promo activa y obtener porcentaje
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
$promo = $stP->get_result()->fetch_assoc();
$stP->close();

if (!$promo) respond(['ok'=>false,'message'=>'La promo no está activa']);

$porc = (float)($promo['porcentaje_descuento'] ?? 0);
if ($porc <= 0) $porc = 50.0;

// 2) Obtener código y precio_lista del inventario
$stI = $conn->prepare("
  SELECT
    UPPER(TRIM(p.codigo_producto)) AS codigo_producto,
    COALESCE(p.precio_lista,0) AS precio_lista
  FROM inventario i
  INNER JOIN productos p ON p.id = i.id_producto
  WHERE i.id = ?
  LIMIT 1
");
$stI->bind_param("i", $idInv);
$stI->execute();
$row = $stI->get_result()->fetch_assoc();
$stI->close();

$codigo = strtoupper(trim((string)($row['codigo_producto'] ?? '')));
$precioLista = (float)($row['precio_lista'] ?? 0);

if ($codigo === '') {
  respond([
    'ok'=>true,
    'elegible'=>false,
    'message'=>'El producto no tiene codigo_producto',
    'porcentaje_descuento'=>$porc,
    'precio_lista'=>$precioLista,
    'precio_descuento'=>0,
    'codigo_producto'=>''
  ]);
}

// 3) Validar que el código esté configurado como combo para la promo
$stC = $conn->prepare("
  SELECT 1
  FROM promos_equipos_descuento_combo
  WHERE promo_id = ?
    AND activo = 1
    AND UPPER(TRIM(codigo_producto)) = ?
  LIMIT 1
");
$stC->bind_param("is", $promoId, $codigo);
$stC->execute();
$ok = $stC->get_result()->num_rows > 0;
$stC->close();

$precioDesc = $precioLista * (1 - ($porc / 100));
if ($precioDesc < 0) $precioDesc = 0;

respond([
  'ok' => true,
  'elegible' => $ok ? true : false,
  'porcentaje_descuento' => $porc,
  'precio_lista' => $precioLista,
  'precio_descuento' => $precioDesc,
  'codigo_producto' => $codigo
]);
