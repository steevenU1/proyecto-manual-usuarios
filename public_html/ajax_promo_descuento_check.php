<?php
// ajax_promo_descuento_check.php
// Detecta si un inventario (equipo principal) tiene promo de descuento activa.

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['id_usuario'])) {
  echo json_encode(['ok'=>false,'message'=>'No autenticado'], JSON_UNESCAPED_UNICODE);
  exit;
}

require_once __DIR__ . '/db.php';

function respond($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }

// Normaliza código para que no falle por espacios raros / guiones distintos / mayúsculas
function normCodigo($s){
  $s = (string)$s;
  $s = str_replace("\xC2\xA0", " ", $s);         // NBSP -> space
  $s = str_replace(["–","—","-"], "-", $s);      // guiones raros -> '-'
  $s = preg_replace('/\s+/', ' ', $s);           // espacios múltiples -> uno
  $s = trim($s);
  $s = mb_strtoupper($s, 'UTF-8');
  return $s;
}

$idInv = (int)($_POST['id_inventario'] ?? 0);
if ($idInv <= 0) respond(['ok'=>false,'message'=>'id_inventario inválido']);

$hoy = date('Y-m-d');

// 1) Obtener codigo_producto del inventario
$st = $conn->prepare("
  SELECT COALESCE(p.codigo_producto,'') AS codigo
  FROM inventario i
  INNER JOIN productos p ON p.id = i.id_producto
  WHERE i.id = ?
  LIMIT 1
");
$st->bind_param("i", $idInv);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

$codigoRaw = (string)($row['codigo'] ?? '');
$codigo = normCodigo($codigoRaw);

if ($codigo === '') {
  respond(['ok'=>true,'aplica'=>false,'message'=>'Sin código de producto']);
}

// 2) Buscar promo activa por codigo en PRINCIPAL
// Ojo: normalizamos también pp.codigo_producto en SQL (NBSP->space, trim) y comparamos ya normalizado.
$st2 = $conn->prepare("
  SELECT
    pr.id,
    pr.nombre,
    pr.modo,
    pr.porcentaje_descuento,
    pr.permite_combo,
    pr.permite_doble_venta,
    COALESCE(pp.codigo_producto,'') AS codigo_pp
  FROM promos_equipos_descuento pr
  INNER JOIN promos_equipos_descuento_principal pp ON pp.promo_id = pr.id
  WHERE pr.activa = 1
    AND (pr.fecha_inicio IS NULL OR pr.fecha_inicio <= ?)
    AND (pr.fecha_fin    IS NULL OR pr.fecha_fin    >= ?)
    AND pp.activo = 1
    AND UPPER(TRIM(REPLACE(pp.codigo_producto, CHAR(194,160), ' '))) = ?
  ORDER BY pr.id DESC
  LIMIT 1
");
$st2->bind_param("sss", $hoy, $hoy, $codigo);
$st2->execute();
$promo = $st2->get_result()->fetch_assoc();
$st2->close();

if (!$promo) {
  // 👇 Si quieres depurar rápido, descomenta para ver qué código está llegando
  // respond(['ok'=>true,'aplica'=>false,'principal_codigo'=>$codigo,'debug_raw'=>$codigoRaw]);

  respond(['ok'=>true,'aplica'=>false,'principal_codigo'=>$codigo]);
}

// 3) (Opcional) contar combos configurados para esa promo
$promoId = (int)$promo['id'];
$st3 = $conn->prepare("
  SELECT COUNT(*) AS n
  FROM promos_equipos_descuento_combo
  WHERE promo_id = ? AND activo = 1
");
$st3->bind_param("i", $promoId);
$st3->execute();
$n = (int)($st3->get_result()->fetch_assoc()['n'] ?? 0);
$st3->close();

respond([
  'ok' => true,
  'aplica' => true,
  'principal_codigo' => $codigo,
  'promo' => [
    'id' => $promoId,
    'nombre' => (string)$promo['nombre'],
    'modo' => (string)$promo['modo'],
    'porcentaje_descuento' => (float)$promo['porcentaje_descuento'],
    'permite_combo' => (int)$promo['permite_combo'],
    'permite_doble_venta' => (int)$promo['permite_doble_venta'],
    'combos_configurados' => $n
  ]
]);
