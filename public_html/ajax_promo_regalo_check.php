<?php
// ajax_promo_regalo_check.php
// Modo:
// - modo=check  -> responde si el principal tiene promo activa (aplica + promo_id)
// - modo=combos -> regresa items de inventario elegibles para regalo (solo si aplica)
// Requiere:
// - id_inventario (principal)  [también acepta equipo1 o idInventario]
// - id_sucursal   (para filtrar inventario)  (solo en modo=combos)
// Opcional:
// - debug=1

session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ========================
   Helpers
======================== */
function tableExists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $sql = "
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = '$t'
    LIMIT 1
  ";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = '$t'
      AND COLUMN_NAME  = '$c'
    LIMIT 1
  ";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

function getEstadoColPromos(mysqli $conn): string {
  // Soporta pr.activa o pr.activo; si no hay ninguna, no filtra.
  $hasActiva = false;
  $hasActivo = false;

  $chk = $conn->query("SHOW COLUMNS FROM promos_regalo LIKE 'activa'");
  if ($chk && $chk->num_rows > 0) $hasActiva = true;

  $chk2 = $conn->query("SHOW COLUMNS FROM promos_regalo LIKE 'activo'");
  if ($chk2 && $chk2->num_rows > 0) $hasActivo = true;

  if ($hasActiva) return 'pr.activa';
  if ($hasActivo) return 'pr.activo';
  return '1';
}

function obtenerCodigoProductoPorInventario(mysqli $conn, int $idInventario): string {
  $stmt = $conn->prepare("
    SELECT p.codigo_producto
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $idInventario);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  // Normaliza igual que el query: UPPER(TRIM())
  return strtoupper(trim((string)($row['codigo_producto'] ?? '')));
}

function buscarPromoActivaPorCodigo(mysqli $conn, string $codigoNorm, string $hoy, string $colEstado): ?array {
  $sql = "
    SELECT pr.id, pr.nombre
    FROM promos_regalo pr
    INNER JOIN promos_regalo_principales pp ON pp.id_promo = pr.id
    WHERE ($colEstado = 1)
      AND pr.fecha_inicio <= ?
      AND pr.fecha_fin >= ?
      AND UPPER(TRIM(pp.codigo_producto_principal)) = ?
    LIMIT 1
  ";
  $st = $conn->prepare($sql);
  $st->bind_param("sss", $hoy, $hoy, $codigoNorm);
  $st->execute();
  $promo = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$promo) return null;

  return [
    'id' => (int)$promo['id'],
    'nombre' => (string)($promo['nombre'] ?? '')
  ];
}

/* ========================
   Input (acepta varios nombres)
======================== */
$modo = trim((string)($_POST['modo'] ?? 'check')); // check|combos

// Acepta id_inventario / equipo1 / idInventario
$idInventario = 0;
if (isset($_POST['id_inventario'])) $idInventario = (int)$_POST['id_inventario'];
elseif (isset($_POST['equipo1']))   $idInventario = (int)$_POST['equipo1'];
elseif (isset($_POST['idInventario'])) $idInventario = (int)$_POST['idInventario'];

$idSucursal = (int)($_POST['id_sucursal'] ?? 0);
$debugOn    = ((int)($_POST['debug'] ?? 0) === 1);

$hoy = date('Y-m-d');

$debug = [
  'modo' => $modo,
  'id_inventario' => $idInventario,
  'id_sucursal' => $idSucursal,
  'hoy' => $hoy,
];

if ($idInventario <= 0) {
  echo json_encode([
    'ok' => false,
    'message' => 'id_inventario inválido (no llegó o llegó 0)',
    'debug' => $debugOn ? $debug : null
  ]);
  exit;
}

if ($modo === 'combos' && $idSucursal <= 0) {
  echo json_encode([
    'ok' => false,
    'message' => 'id_sucursal requerido para modo combos',
    'debug' => $debugOn ? $debug : null
  ]);
  exit;
}

/* ========================
   Main
======================== */
try {
  // 1) Principal: codigo_producto
  $codigoPrincipal = obtenerCodigoProductoPorInventario($conn, $idInventario);
  $debug['codigo_principal'] = $codigoPrincipal;

  if ($codigoPrincipal === '') {
    echo json_encode([
      'ok' => true,
      'aplica' => false,
      'message' => 'No se encontró codigo_producto para ese inventario',
      'debug' => $debugOn ? $debug : null
    ]);
    exit;
  }

  // 2) Promo activa por codigo_producto_principal
  $colEstado = getEstadoColPromos($conn);
  $debug['col_estado_usada'] = $colEstado;

  $promo = buscarPromoActivaPorCodigo($conn, $codigoPrincipal, $hoy, $colEstado);

  // =======================
  // modo=check
  // =======================
  if ($modo === 'check') {
    if ($promo) {
      echo json_encode([
        'ok' => true,
        'aplica' => true,
        'promo_id' => (int)$promo['id'],
        'nombre' => $promo['nombre'],
        'debug' => $debugOn ? $debug : null
      ]);
    } else {
      // Diagnóstico rápido: ¿existe el código en principales?
      $stmt3 = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM promos_regalo_principales
        WHERE UPPER(TRIM(codigo_producto_principal)) = ?
      ");
      $stmt3->bind_param("s", $codigoPrincipal);
      $stmt3->execute();
      $cRow = $stmt3->get_result()->fetch_assoc();
      $stmt3->close();

      $debug['match_en_principales'] = (int)($cRow['c'] ?? 0);

      echo json_encode([
        'ok' => true,
        'aplica' => false,
        'message' => 'No hay promo activa para ese codigo_producto (o fechas/estado no coinciden)',
        'debug' => $debugOn ? $debug : null
      ]);
    }
    exit;
  }

  // =======================
  // modo=combos (regalos elegibles)
  // =======================
  if (!$promo) {
    echo json_encode([
      'ok' => true,
      'aplica' => false,
      'items' => [],
      'message' => 'No aplica promo, no hay combos filtrados',
      'debug' => $debugOn ? $debug : null
    ]);
    exit;
  }

  $promoId = (int)$promo['id'];
  $debug['promo_id'] = $promoId;

  // Regla real: elegible si productos.promocion contiene la frase
  $frase = 'Exclusivo en Combo con un equipo financiado';
  $debug['frase_filtro_regalo'] = $frase;

  // Verifica columna promocion (por si en algún ambiente se llama distinto)
  if (!columnExists($conn, 'productos', 'promocion')) {
    echo json_encode([
      'ok' => false,
      'message' => "La columna productos.promocion no existe; no puedo filtrar regalos elegibles.",
      'debug' => $debugOn ? $debug : null
    ]);
    exit;
  }

  $like = '%' . $frase . '%';

  $sql = "
    SELECT
      i.id AS id_inventario,
      p.marca, p.modelo, p.color, p.capacidad,
      p.imei1,
      p.precio_lista,
      UPPER(TRIM(p.codigo_producto)) AS codigo_producto
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.estatus = 'Disponible'
      AND i.id_sucursal = ?
      AND i.id <> ?
      AND LOWER(p.promocion) LIKE LOWER(?)
    ORDER BY p.modelo ASC, p.capacidad ASC, p.color ASC, p.imei1 ASC
    LIMIT 300
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("iis", $idSucursal, $idInventario, $like);
  $stmt->execute();
  $rs = $stmt->get_result();

  $items = [];
  while ($r = $rs->fetch_assoc()) {
    $marca  = trim((string)$r['marca']);
    $modelo = trim((string)$r['modelo']);
    $color  = trim((string)$r['color']);
    $cap    = trim((string)$r['capacidad']);
    $imei   = trim((string)$r['imei1']);
    $precio = (float)($r['precio_lista'] ?? 0);

    $label = trim("$marca $modelo $color $cap");
    if ($imei !== '') $label .= " | $imei";
    $label .= " | $" . number_format($precio, 0);

    $items[] = [
      'id' => (int)$r['id_inventario'],
      'text' => $label,
      'codigo_producto' => (string)($r['codigo_producto'] ?? ''),
      'precio_lista' => $precio
    ];
  }
  $stmt->close();

  echo json_encode([
    'ok' => true,
    'aplica' => true,
    'promo_id' => $promoId,
    'nombre' => $promo['nombre'],
    'items' => $items,
    'debug' => $debugOn ? $debug : null
  ]);
  exit;

} catch (Throwable $e) {
  $debug['error'] = $e->getMessage();
  echo json_encode([
    'ok' => false,
    'message' => 'Error en servidor',
    'debug' => $debugOn ? $debug : null
  ]);
  exit;
}
