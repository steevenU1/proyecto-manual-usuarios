<?php
// ajax_promo_descuento_validar_tag.php
// Valida el TAG de una venta "principal" para Promo: Segundo equipo con descuento.
//
// Devuelve JSON compatible con el front:
// - ok (bool)
// - aplica (bool)
// - venta_id (int)
// - principal_codigo (string)
// - promo (obj)
// - (compat) promo_id, promo_nombre, porcentaje_descuento

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['id_usuario'])) {
  echo json_encode(['ok'=>false,'message'=>'No autenticado'], JSON_UNESCAPED_UNICODE);
  exit;
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function respond($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

function columnExists(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '$t'
            AND COLUMN_NAME = '$c'
          LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

$tag = trim((string)($_POST['tag_origen'] ?? ($_POST['tag'] ?? '')));
if ($tag === '') respond(['ok'=>false,'message'=>'TAG requerido']);

$hoy = date('Y-m-d');

try {
  // 1) venta por TAG
  $stV = $conn->prepare("SELECT id, tag FROM ventas WHERE tag = ? LIMIT 1");
  $stV->bind_param("s", $tag);
  $stV->execute();
  $venta = $stV->get_result()->fetch_assoc();
  $stV->close();

  if (!$venta) {
    respond(['ok'=>true,'aplica'=>false,'message'=>'No existe una venta con ese TAG']);
  }

  $ventaId = (int)$venta['id'];

  // 2) Obtener producto principal desde detalle_venta
  $hasEsCombo = columnExists($conn, 'detalle_venta', 'es_combo');

  if ($hasEsCombo) {
    $sqlP = "
      SELECT dv.id_producto
      FROM detalle_venta dv
      WHERE dv.id_venta = ?
        AND (dv.es_combo = 0 OR dv.es_combo IS NULL)
      ORDER BY dv.id ASC
      LIMIT 1
    ";
  } else {
    $sqlP = "
      SELECT dv.id_producto
      FROM detalle_venta dv
      WHERE dv.id_venta = ?
      ORDER BY dv.id ASC
      LIMIT 1
    ";
  }

  $stP = $conn->prepare($sqlP);
  $stP->bind_param("i", $ventaId);
  $stP->execute();
  $rowP = $stP->get_result()->fetch_assoc();
  $stP->close();

  $idProducto = (int)($rowP['id_producto'] ?? 0);
  if ($idProducto <= 0) {
    respond([
      'ok'=>true,
      'aplica'=>false,
      'venta_id'=>$ventaId,
      'message'=>'La venta origen no tiene equipo principal en detalle_venta'
    ]);
  }

  // 3) codigo_producto del principal
  $stC = $conn->prepare("SELECT UPPER(TRIM(codigo_producto)) AS codigo FROM productos WHERE id = ? LIMIT 1");
  $stC->bind_param("i", $idProducto);
  $stC->execute();
  $rowC = $stC->get_result()->fetch_assoc();
  $stC->close();

  $codigo = strtoupper(trim((string)($rowC['codigo'] ?? '')));
  if ($codigo === '') {
    respond([
      'ok'=>true,
      'aplica'=>false,
      'venta_id'=>$ventaId,
      'message'=>'El equipo principal no tiene codigo_producto'
    ]);
  }

  // 4) promo activa por codigo en PRINCIPAL
  $st2 = $conn->prepare("
    SELECT
      pr.id,
      pr.nombre,
      pr.modo,
      pr.porcentaje_descuento,
      pr.permite_combo,
      pr.permite_doble_venta
    FROM promos_equipos_descuento pr
    INNER JOIN promos_equipos_descuento_principal pp ON pp.promo_id = pr.id
    WHERE pr.activa = 1
      AND (pr.fecha_inicio IS NULL OR pr.fecha_inicio <= ?)
      AND (pr.fecha_fin    IS NULL OR pr.fecha_fin    >= ?)
      AND pp.activo = 1
      AND UPPER(TRIM(pp.codigo_producto)) = ?
    ORDER BY pr.id DESC
    LIMIT 1
  ");
  $st2->bind_param("sss", $hoy, $hoy, $codigo);
  $st2->execute();
  $promo = $st2->get_result()->fetch_assoc();
  $st2->close();

  if (!$promo) {
    respond([
      'ok'=>true,
      'aplica'=>false,
      'venta_id'=>$ventaId,
      'principal_codigo'=>$codigo,
      // compat:
      'promo_id'=>0,
      'promo_nombre'=>'',
      'porcentaje_descuento'=>0
    ]);
  }

  $promoId = (int)$promo['id'];

  // Contar combos configurados (informativo)
  $st3 = $conn->prepare("
    SELECT COUNT(*) AS n
    FROM promos_equipos_descuento_combo
    WHERE promo_id = ? AND activo = 1
  ");
  $st3->bind_param("i", $promoId);
  $st3->execute();
  $n = (int)($st3->get_result()->fetch_assoc()['n'] ?? 0);
  $st3->close();

  $promoObj = [
    'id' => $promoId,
    'nombre' => (string)$promo['nombre'],
    'modo' => (string)$promo['modo'],
    'porcentaje_descuento' => (float)$promo['porcentaje_descuento'],
    'permite_combo' => (int)$promo['permite_combo'],
    'permite_doble_venta' => (int)$promo['permite_doble_venta'],
    'combos_configurados' => $n
  ];

  respond([
    'ok' => true,
    'aplica' => true,
    'venta_id' => $ventaId,
    'principal_codigo' => $codigo,
    'promo' => $promoObj,

    // ✅ Compat con tu JS actual
    'promo_id' => $promoObj['id'],
    'promo_nombre' => $promoObj['nombre'],
    'porcentaje_descuento' => $promoObj['porcentaje_descuento']
  ]);

} catch (Throwable $e) {
  respond(['ok'=>false,'message'=>'Error: '.$e->getMessage()]);
}
