<?php
// ajax_promo_descuento_calc.php
// Calcula precio con descuento de un inventario para una promo específica.
// ✅ Backend seguro: SOLO calcula si el inventario está en promos_equipos_descuento_combo (activo=1)
// ✅ Valida fechas/vigencia
// ✅ Valida inventario Disponible

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

// 1) Promo vigente
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

if (!$promo) respond(['ok'=>false,'message'=>'Promo inválida o fuera de vigencia']);

$porc = (float)($promo['porcentaje_descuento'] ?? 0);
if ($porc <= 0) $porc = 50.0;

// 2) Validar que el inventario sea elegible (está en combos permitidos)
$st = $conn->prepare("
  SELECT
    COALESCE(p.precio_lista,0) AS precio_lista,
    UPPER(TRIM(REPLACE(p.codigo_producto, CHAR(160), ' '))) AS codigo_producto
  FROM inventario i
  INNER JOIN productos p ON p.id = i.id_producto
  INNER JOIN promos_equipos_descuento_combo pc
          ON pc.promo_id = ?
         AND pc.activo = 1
         AND UPPER(TRIM(REPLACE(pc.codigo_producto, CHAR(160), ' '))) = UPPER(TRIM(REPLACE(p.codigo_producto, CHAR(160), ' ')))
  WHERE i.id = ?
    AND i.estatus = 'Disponible'
  LIMIT 1
");
$st->bind_param("ii", $promoId, $idInv);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row) {
  respond(['ok'=>false,'message'=>'Inventario no elegible para esta promo (o no disponible).']);
}

$precioLista = (float)($row['precio_lista'] ?? 0);

$precioDesc  = $precioLista * (1 - ($porc / 100));
if ($precioDesc < 0) $precioDesc = 0;

respond([
  'ok' => true,
  'promo_id' => $promoId,
  'porcentaje_descuento' => $porc,
  'precio_lista' => $precioLista,
  'precio_descuento' => $precioDesc
]);
