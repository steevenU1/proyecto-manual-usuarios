<?php

/** procesar_venta.php — Central LUGA
 * + Comisiones actualizadas
 * + Cupón por equipo:
 *      - monto_cupon_principal
 *      - monto_cupon_combo
 *      - monto_cupon (total)
 * + ✅ Promo Regalo (2x1) por codigo_producto (backend seguro)
 * + ✅ Promo Descuento (2º equipo % descuento) en:
 *      A) Financiamiento+Combo (mismo registro): combo a % descuento del precio_lista
 *      B) Doble venta (segunda venta): valida TAG origen y aplica % descuento al precio_lista del equipo seleccionado
 * + ✅ Soporte para:
 *      - pago_semanal
 *      - primer_pago
 *
 * NOTA: Este archivo NO asume columnas nuevas; inserta campos opcionales en ventas solo si existen.
 */

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guard_corte.php';

date_default_timezone_set('America/Mexico_City');

/* ========================
   Candado de captura por corte de AYER
======================== */
$id_sucursal_guard = isset($_POST['id_sucursal'])
  ? (int)$_POST['id_sucursal']
  : (int)($_SESSION['id_sucursal'] ?? 0);

list($bloquear, $motivoBloqueo, $ayerBloqueo) = debe_bloquear_captura($conn, $id_sucursal_guard);
if ($bloquear) {
  header("Location: nueva_venta.php?err=" . urlencode("⛔ Captura bloqueada: $motivoBloqueo Debes generar el corte de $ayerBloqueo."));
  exit();
}

/* ========================
   🔑 Validación de contraseña del usuario logueado
======================== */
$id_usuario_sesion = (int)($_SESSION['id_usuario'] ?? 0);
$passConfirm       = $_POST['password_confirm'] ?? '';

if ($id_usuario_sesion <= 0) {
  header("Location: nueva_venta.php?err=" . urlencode("Sesión inválida, vuelve a iniciar sesión."));
  exit();
}

if ($passConfirm === '') {
  header("Location: nueva_venta.php?err=" . urlencode("Debes capturar tu contraseña para confirmar la venta."));
  exit();
}

$stmtUser = $conn->prepare("SELECT password FROM usuarios WHERE id = ? LIMIT 1");
$stmtUser->bind_param("i", $id_usuario_sesion);
$stmtUser->execute();
$resUser = $stmtUser->get_result()->fetch_assoc();
$stmtUser->close();

if (!$resUser) {
  header("Location: nueva_venta.php?err=" . urlencode("Sesión inválida. No se encontró el usuario en la base de datos."));
  exit();
}

$stored = (string)($resUser['password'] ?? '');
$okPass = false;

if ($stored !== '' && password_verify($passConfirm, $stored)) $okPass = true;
if (!$okPass && $stored !== '' && hash_equals($stored, $passConfirm)) $okPass = true;
if (!$okPass && $stored !== '' && hash_equals($stored, md5($passConfirm))) $okPass = true;

if (!$okPass) {
  header("Location: nueva_venta.php?err=" . urlencode("❌ Contraseña incorrecta. La venta no fue guardada."));
  exit();
}

/* ========================
   Helpers / Utilidades
======================== */
function tableExists(mysqli $conn, string $table): bool
{
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

function columnExists(mysqli $conn, string $table, string $column): bool
{
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

function esFechaYmdValida(?string $fecha): bool
{
  $fecha = trim((string)$fecha);
  if ($fecha === '') return false;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) return false;
  $dt = DateTime::createFromFormat('Y-m-d', $fecha);
  return $dt && $dt->format('Y-m-d') === $fecha;
}

$colTipoProd = columnExists($conn, 'productos', 'tipo') ? 'tipo' : 'tipo_producto';

function norm(string $s): string
{
  $s = trim($s);
  if ($s === '') return '';
  if (function_exists('mb_strtolower')) $s = mb_strtolower($s, 'UTF-8');
  else $s = strtolower($s);
  $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
  if ($t !== false) $s = strtolower($t);
  return preg_replace('/[^a-z0-9]+/', '', $s);
}

function generarTagContado(mysqli $conn): string
{
  for ($i = 0; $i < 30; $i++) {
    $num = random_int(0, 999999);
    $tag = 'CONT' . str_pad((string)$num, 6, '0', STR_PAD_LEFT);

    $stmt = $conn->prepare("SELECT 1 FROM ventas WHERE tag = ? LIMIT 1");
    $stmt->bind_param("s", $tag);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if (!$res || $res->num_rows === 0) return $tag;
  }
  return 'CONT' . str_pad(substr((string)time(), -6), 6, '0', STR_PAD_LEFT);
}

function esMiFiModem(array $row): bool
{
  $candidatos = [];
  $candidatos[] = isset($row['tipo_raw']) ? (string)$row['tipo_raw'] : '';
  foreach (['nombre_comercial', 'subtipo', 'descripcion', 'modelo'] as $k) {
    if (isset($row[$k])) $candidatos[] = (string)$row[$k];
  }
  $joined = norm(implode(' ', $candidatos));
  foreach (['modem', 'mifi', 'hotspot', 'router', 'cpe', 'pocketwifi'] as $n) {
    if (strpos($joined, $n) !== false) return true;
  }
  return false;
}

/* ========================
   Tablas de comisión
======================== */
function comisionTramoEjecutivo(float $precio): float
{
  if ($precio >= 1     && $precio <= 2999) return 75.0;
  if ($precio >= 3000  && $precio <= 5498) return 100.0;
  if ($precio >= 5499)                     return 150.0;
  return 0.0;
}

function comisionTramoGerente(float $precio, bool $isModem): float
{
  if ($isModem) return 25.0;
  if ($precio >= 1     && $precio <= 2999) return 25.0;
  if ($precio >= 3000  && $precio <= 5498) return 75.0;
  if ($precio >= 5499)                     return 100.0;
  return 0.0;
}

function calcularComisionBaseParaCampoComision(string $rolVendedor, bool $esCombo, bool $esModem, float $precioBase): float
{
  if ($rolVendedor === 'Gerente') return comisionTramoGerente($precioBase, $esModem);
  if ($esCombo) return 75.0;
  if ($esModem) return 50.0;
  return comisionTramoEjecutivo($precioBase);
}

function calcularComisionGerenteParaCampo(bool $esCombo, bool $esModem, float $precioBase): float
{
  if ($esCombo) return 75.0;
  return comisionTramoGerente($precioBase, $esModem);
}

function obtenerComisionEspecial(int $id_producto, mysqli $conn, string $colTipoProd): float
{
  $hoy = date('Y-m-d');

  $stmt = $conn->prepare("
    SELECT marca, modelo, capacidad, $colTipoProd AS tipo_raw,
           nombre_comercial, subtipo, descripcion
    FROM productos WHERE id=?
  ");
  $stmt->bind_param("i", $id_producto);
  $stmt->execute();
  $prod = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$prod) return 0.0;

  $stmt2 = $conn->prepare("
    SELECT monto
    FROM comisiones_especiales
    WHERE marca=? AND modelo=? AND (capacidad=? OR capacidad='' OR capacidad IS NULL)
      AND fecha_inicio <= ? AND (fecha_fin IS NULL OR fecha_fin >= ?)
      AND activo=1
    ORDER BY fecha_inicio DESC
    LIMIT 1
  ");
  $stmt2->bind_param("sssss", $prod['marca'], $prod['modelo'], $prod['capacidad'], $hoy, $hoy);
  $stmt2->execute();
  $res = $stmt2->get_result()->fetch_assoc();
  $stmt2->close();

  return (float)($res['monto'] ?? 0);
}

function validarInventario(mysqli $conn, int $id_inv, int $id_sucursal): bool
{
  $stmt = $conn->prepare("
    SELECT COUNT(*) FROM inventario
    WHERE id=? AND estatus='Disponible' AND id_sucursal=?
  ");
  $stmt->bind_param("ii", $id_inv, $id_sucursal);
  $stmt->execute();
  $stmt->bind_result($ok);
  $stmt->fetch();
  $stmt->close();
  return (int)$ok > 0;
}

/* ========================
   Promo Regalo helpers
======================== */
function obtenerCodigoProductoPorInventario(mysqli $conn, int $idInventario): string
{
  $st = $conn->prepare("
    SELECT p.codigo_producto
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.id = ?
    LIMIT 1
  ");
  $st->bind_param("i", $idInventario);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  return strtoupper(trim((string)($row['codigo_producto'] ?? '')));
}

function getColEstadoPromos(mysqli $conn): string
{
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

function buscarPromoRegaloActivaPorInventario(mysqli $conn, int $idInventario): ?array
{
  $hoy = date('Y-m-d');
  $codigo = obtenerCodigoProductoPorInventario($conn, $idInventario);
  if ($codigo === '') return null;

  $colEstado = getColEstadoPromos($conn);

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
  $st2 = $conn->prepare($sql);
  $st2->bind_param("sss", $hoy, $hoy, $codigo);
  $st2->execute();
  $promo = $st2->get_result()->fetch_assoc();
  $st2->close();

  if (!$promo) return null;

  return [
    'id' => (int)$promo['id'],
    'nombre' => (string)($promo['nombre'] ?? ''),
    'codigo_producto' => $codigo
  ];
}

function validarRegaloElegible(mysqli $conn, int $inventarioRegalo): bool
{
  if (!columnExists($conn, 'productos', 'promocion')) return false;
  $needle = 'exclusivo en combo con un equipo financiado';

  $st = $conn->prepare("
    SELECT COALESCE(p.promocion,'') AS promo_txt
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.id = ?
    LIMIT 1
  ");
  $st->bind_param("i", $inventarioRegalo);
  $st->execute();
  $r = $st->get_result()->fetch_assoc();
  $st->close();

  $txt = trim((string)($r['promo_txt'] ?? ''));
  if ($txt === '') return false;

  if (function_exists('mb_strtolower')) $txt = mb_strtolower($txt, 'UTF-8');
  else $txt = strtolower($txt);
  return (strpos($txt, $needle) !== false);
}

/* ========================
   Promo Descuento helpers
======================== */
function buscarPromoDescuentoActivaPorCodigoPrincipal(mysqli $conn, string $codigoPrincipal): ?array
{
  if (!tableExists($conn, 'promos_equipos_descuento')) return null;
  if (!tableExists($conn, 'promos_equipos_descuento_principal')) return null;

  $hoy = date('Y-m-d');
  $codigoPrincipal = strtoupper(trim($codigoPrincipal));
  if ($codigoPrincipal === '') return null;

  $sql = "
    SELECT pr.id, pr.nombre, pr.porcentaje_descuento, pr.permite_combo, pr.permite_doble_venta
    FROM promos_equipos_descuento pr
    INNER JOIN promos_equipos_descuento_principal pp ON pp.promo_id = pr.id
    WHERE pr.activa = 1
      AND (pr.fecha_inicio IS NULL OR pr.fecha_inicio <= ?)
      AND (pr.fecha_fin    IS NULL OR pr.fecha_fin    >= ?)
      AND pp.activo = 1
      AND UPPER(TRIM(pp.codigo_producto)) = ?
    ORDER BY pr.id DESC
    LIMIT 1
  ";
  $st = $conn->prepare($sql);
  $st->bind_param("sss", $hoy, $hoy, $codigoPrincipal);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$row) return null;

  $porc = (float)($row['porcentaje_descuento'] ?? 0);
  if ($porc <= 0) $porc = 50.0;

  return [
    'id' => (int)$row['id'],
    'nombre' => (string)($row['nombre'] ?? ''),
    'porcentaje' => $porc,
    'permite_combo' => (int)($row['permite_combo'] ?? 0),
    'permite_doble_venta' => (int)($row['permite_doble_venta'] ?? 0),
  ];
}

function validarInventarioEnListaComboPromo(mysqli $conn, int $promoId, int $idInventarioCombo): bool
{
  if ($promoId <= 0 || $idInventarioCombo <= 0) return false;
  if (!tableExists($conn, 'promos_equipos_descuento_combo')) return false;

  $codigo = obtenerCodigoProductoPorInventario($conn, $idInventarioCombo);
  if ($codigo === '') return false;

  $sql = "
    SELECT 1
    FROM promos_equipos_descuento_combo
    WHERE promo_id = ? AND activo = 1 AND UPPER(TRIM(codigo_producto)) = ?
    LIMIT 1
  ";
  $st = $conn->prepare($sql);
  $st->bind_param("is", $promoId, $codigo);
  $st->execute();
  $ok = $st->get_result()->num_rows > 0;
  $st->close();
  return $ok;
}

function precioListaPorInventario(mysqli $conn, int $idInventario): float
{
  $st = $conn->prepare("
    SELECT COALESCE(p.precio_lista,0) AS precio_lista
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    WHERE i.id = ?
    LIMIT 1
  ");
  $st->bind_param("i", $idInventario);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();
  return (float)($row['precio_lista'] ?? 0);
}

function obtenerCodigoPrincipalPorTag(mysqli $conn, string $tag): ?string
{
  $tag = trim($tag);
  if ($tag === '') return null;

  $tieneEsCombo = columnExists($conn, 'detalle_venta', 'es_combo');

  if ($tieneEsCombo) {
    $sql = "
      SELECT UPPER(TRIM(p.codigo_producto)) AS codigo
      FROM ventas v
      INNER JOIN detalle_venta d ON d.id_venta = v.id
      INNER JOIN productos p ON p.id = d.id_producto
      WHERE v.tag = ?
        AND (d.es_combo = 0 OR d.es_combo IS NULL)
      ORDER BY d.id ASC
      LIMIT 1
    ";
  } else {
    $sql = "
      SELECT UPPER(TRIM(p.codigo_producto)) AS codigo
      FROM ventas v
      INNER JOIN detalle_venta d ON d.id_venta = v.id
      INNER JOIN productos p ON p.id = d.id_producto
      WHERE v.tag = ?
      ORDER BY d.id ASC
      LIMIT 1
    ";
  }

  $st = $conn->prepare($sql);
  $st->bind_param("s", $tag);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  $codigo = strtoupper(trim((string)($row['codigo'] ?? '')));
  return $codigo !== '' ? $codigo : null;
}

/**
 * Registra un renglón en detalle_venta + actualiza inventario.
 * - $overridePrecioUnitario: si se manda, se guarda ese precio_unitario y se usa para el tramo de comisión.
 */
function venderEquipo(
  mysqli $conn,
  int $id_venta,
  int $id_inventario,
  bool $esCombo,
  string $rolVendedor,
  bool $tieneEsCombo,
  bool $tieneComisionGerente,
  string $colTipoProd,
  float $montoCuponAplicable = 0.0,
  bool $forceZero = false,
  bool $esRegalo = false,
  ?float $overridePrecioUnitario = null
): float {

  $sql = "
    SELECT i.id_producto,
           p.imei1,
           p.precio_lista,
           p.`$colTipoProd` AS tipo_raw,
           p.nombre_comercial,
           p.subtipo,
           p.descripcion,
           p.modelo
    FROM inventario i
    INNER JOIN productos p ON i.id_producto = p.id
    WHERE i.id=? AND i.estatus='Disponible'
    LIMIT 1
  ";
  $stmtProd = $conn->prepare($sql);
  $stmtProd->bind_param("i", $id_inventario);
  $stmtProd->execute();
  $row = $stmtProd->get_result()->fetch_assoc();
  $stmtProd->close();

  if (!$row) throw new RuntimeException("El equipo $id_inventario no está disponible.");

  $precioLista = (float)$row['precio_lista'];
  $esModem     = esMiFiModem($row);

  $precioUnitario = ($overridePrecioUnitario !== null) ? (float)$overridePrecioUnitario : $precioLista;

  $precioBaseComision = $precioUnitario;
  if ($montoCuponAplicable > 0) {
    $precioBaseComision -= $montoCuponAplicable;
    if ($precioBaseComision < 0) $precioBaseComision = 0.0;
  }

  if ($forceZero) {
    $precioUnitario = 0.0;
    $precioBaseComision = 0.0;
  }

  $comisionBase        = calcularComisionBaseParaCampoComision($rolVendedor, $esCombo, $esModem, $precioBaseComision);
  $comisionGerenteBase = calcularComisionGerenteParaCampo($esCombo, $esModem, $precioBaseComision);

  if ($rolVendedor === 'Gerente') $comisionGerenteBase = 0.0;

  $comEsp = obtenerComisionEspecial((int)$row['id_producto'], $conn, $colTipoProd);
  if ($forceZero) {
    $comisionBase = 0.0;
    $comisionGerenteBase = 0.0;
    $comEsp = 0.0;
  }

  $comisionRegular = $comisionBase;
  $comisionTotal   = $comisionBase + $comEsp;

  $tieneEsRegalo = columnExists($conn, 'detalle_venta', 'es_regalo');
  $esComboInt    = $esCombo ? 1 : 0;
  $esRegaloInt   = ($tieneEsRegalo && $esRegalo) ? 1 : 0;

  if ($tieneEsRegalo && $tieneEsCombo && $tieneComisionGerente) {
    $stmtD = $conn->prepare("
      INSERT INTO detalle_venta
        (id_venta, id_producto, es_combo, es_regalo, imei1, precio_unitario,
         comision, comision_regular, comision_especial, comision_gerente)
      VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    $stmtD->bind_param(
      "iiiisddddd",
      $id_venta,
      $row['id_producto'],
      $esComboInt,
      $esRegaloInt,
      $row['imei1'],
      $precioUnitario,
      $comisionTotal,
      $comisionRegular,
      $comEsp,
      $comisionGerenteBase
    );
  } elseif ($tieneEsCombo && $tieneComisionGerente) {
    $stmtD = $conn->prepare("
      INSERT INTO detalle_venta
        (id_venta, id_producto, es_combo, imei1, precio_unitario,
         comision, comision_regular, comision_especial, comision_gerente)
      VALUES (?,?,?,?,?,?,?,?,?)
    ");
    $stmtD->bind_param(
      "iiisddddd",
      $id_venta,
      $row['id_producto'],
      $esComboInt,
      $row['imei1'],
      $precioUnitario,
      $comisionTotal,
      $comisionRegular,
      $comEsp,
      $comisionGerenteBase
    );
  } elseif ($tieneComisionGerente) {
    $stmtD = $conn->prepare("
      INSERT INTO detalle_venta
        (id_venta, id_producto, imei1, precio_unitario,
         comision, comision_regular, comision_especial, comision_gerente)
      VALUES (?,?,?,?,?,?,?,?)
    ");
    $stmtD->bind_param(
      "iisddddd",
      $id_venta,
      $row['id_producto'],
      $row['imei1'],
      $precioUnitario,
      $comisionTotal,
      $comisionRegular,
      $comEsp,
      $comisionGerenteBase
    );
  } elseif ($tieneEsCombo) {
    $stmtD = $conn->prepare("
      INSERT INTO detalle_venta
        (id_venta, id_producto, es_combo, imei1, precio_unitario,
         comision, comision_regular, comision_especial)
      VALUES (?,?,?,?,?,?,?,?)
    ");
    $stmtD->bind_param(
      "iiisdddd",
      $id_venta,
      $row['id_producto'],
      $esComboInt,
      $row['imei1'],
      $precioUnitario,
      $comisionTotal,
      $comisionRegular,
      $comEsp
    );
  } else {
    $stmtD = $conn->prepare("
      INSERT INTO detalle_venta
        (id_venta, id_producto, imei1, precio_unitario,
         comision, comision_regular, comision_especial)
      VALUES (?,?,?,?,?,?,?)
    ");
    $stmtD->bind_param(
      "iisdddd",
      $id_venta,
      $row['id_producto'],
      $row['imei1'],
      $precioUnitario,
      $comisionTotal,
      $comisionRegular,
      $comEsp
    );
  }

  $stmtD->execute();
  $stmtD->close();

  $stmtU = $conn->prepare("UPDATE inventario SET estatus='Vendido' WHERE id=?");
  $stmtU->bind_param("i", $id_inventario);
  $stmtU->execute();
  $stmtU->close();

  return $comisionTotal;
}

/* ========================
   1) Recibir + Validar
======================== */
$id_usuario   = (int)($_SESSION['id_usuario']);
$rol_usuario  = (string)($_SESSION['rol'] ?? 'Ejecutivo');
$esRolSubdis  = ($rol_usuario === 'Subdistribuidor') || (strpos($rol_usuario, 'Subdis_') === 0);
$id_sucursal  = isset($_POST['id_sucursal']) ? (int)$_POST['id_sucursal'] : (int)($_SESSION['id_sucursal'] ?? 0);

$id_cliente   = isset($_POST['id_cliente']) ? (int)$_POST['id_cliente'] : 0;

$tag                 = trim($_POST['tag'] ?? '');
$nombre_cliente      = trim($_POST['nombre_cliente'] ?? '');
$telefono_cliente    = trim($_POST['telefono_cliente'] ?? '');
$tipo_venta          = $_POST['tipo_venta'] ?? '';
$equipo1             = (int)($_POST['equipo1'] ?? 0);
$equipo2             = isset($_POST['equipo2']) ? (int)($_POST['equipo2'] ?? 0) : 0;
$precio_venta        = (float)($_POST['precio_venta'] ?? 0);
$enganche            = (float)($_POST['enganche'] ?? 0);
$forma_pago_enganche = $_POST['forma_pago_enganche'] ?? '';
$enganche_efectivo   = (float)($_POST['enganche_efectivo'] ?? 0);
$enganche_tarjeta    = (float)($_POST['enganche_tarjeta'] ?? 0);
$plazo_semanas       = (int)($_POST['plazo_semanas'] ?? 0);
$pago_semanal        = isset($_POST['pago_semanal']) ? (float)$_POST['pago_semanal'] : 0.0;
$primer_pago         = trim((string)($_POST['primer_pago'] ?? ''));
$financiera          = $_POST['financiera'] ?? '';
$comentarios         = trim($_POST['comentarios'] ?? '');

// Cupón por equipo
$monto_cupon_principal = isset($_POST['monto_cupon_principal']) ? (float)$_POST['monto_cupon_principal'] : 0.0;
$monto_cupon_combo     = isset($_POST['monto_cupon_combo']) ? (float)$_POST['monto_cupon_combo'] : 0.0;
$monto_cupon_total_in  = isset($_POST['monto_cupon']) ? (float)$_POST['monto_cupon'] : 0.0;

if ($monto_cupon_principal < 0) $monto_cupon_principal = 0.0;
if ($monto_cupon_combo < 0)     $monto_cupon_combo = 0.0;
if ($monto_cupon_total_in < 0)  $monto_cupon_total_in = 0.0;

// Si el front mandó separados, usamos suma real. Si no, compat con flujo viejo.
$monto_cupon = $monto_cupon_principal + $monto_cupon_combo;
if ($monto_cupon <= 0 && $monto_cupon_total_in > 0) {
  $monto_cupon = $monto_cupon_total_in;
  $monto_cupon_principal = $monto_cupon_total_in;
  $monto_cupon_combo = 0.0;
}
$cupon_aplicado = $monto_cupon > 0 ? 1 : 0;

// Promo flags
$promo_regalo_aplicado = isset($_POST['promo_regalo_aplicado']) ? (int)$_POST['promo_regalo_aplicado'] : 0;

// Promo descuento (tolerante a nombres)
$promo_descuento_aplicado = 0;
if (isset($_POST['promo_descuento_aplicado'])) $promo_descuento_aplicado = (int)$_POST['promo_descuento_aplicado'];
if (isset($_POST['promo_desc_aplicado']))      $promo_descuento_aplicado = (int)$_POST['promo_desc_aplicado'];

$promo_descuento_id = 0;
if (isset($_POST['promo_descuento_id'])) $promo_descuento_id = (int)$_POST['promo_descuento_id'];
if (isset($_POST['id_promo_descuento'])) $promo_descuento_id = (int)$_POST['id_promo_descuento'];

$tag_origen_descuento = '';
if (isset($_POST['tag_origen_descuento'])) $tag_origen_descuento = trim((string)$_POST['tag_origen_descuento']);
if (isset($_POST['promo_descuento_tag_origen'])) $tag_origen_descuento = trim((string)$_POST['promo_descuento_tag_origen']);

$es_doble_venta_descuento = 0;
if (isset($_POST['promo_descuento_doble_venta'])) $es_doble_venta_descuento = (int)$_POST['promo_descuento_doble_venta'];
if (isset($_POST['promo_desc_doble_venta']))      $es_doble_venta_descuento = (int)$_POST['promo_desc_doble_venta'];

// ✅ Fallback: si el front no mandó el flag pero viene TAG origen y la venta es Financiamiento (sin equipo2),
// asumimos que es "segunda venta" para evitar que se dispare la validación de combo.
if (
  $es_doble_venta_descuento !== 1
  && $tag_origen_descuento !== ''
  && ($tipo_venta ?? '') === 'Financiamiento'
  && (int)$equipo2 <= 0
) {
  $es_doble_venta_descuento = 1;
}

/* ========================
   Guard: Bloqueo de promos para roles Subdis_*
   - Las promos SOLO se bloquean a Subdis_* (admin/gerente/ejecutivo)
   - No afecta a roles LUGA
======================== */
require_once __DIR__ . '/promos_guard.php';

$__promo_err_exclusividad = '';
$__promo_err_doble_promo  = '';

if (function_exists('subdis_bloquea_promos_luga') && subdis_bloquea_promos_luga()) {
  // Ignora TODO lo relacionado a promos si es Subdis_*
  $promo_regalo_aplicado     = 0;
  $promo_descuento_aplicado  = 0;
  $promo_descuento_id        = 0;
  $tag_origen_descuento      = '';
  $es_doble_venta_descuento  = 0;

  // Limpia también inputs auxiliares (compat / logs)
  if (isset($_POST['promo_regalo_id'])) $_POST['promo_regalo_id'] = 0;
  if (isset($_POST['id_promo_regalo'])) $_POST['id_promo_regalo'] = 0;
  if (isset($_POST['promo_descuento_modo'])) $_POST['promo_descuento_modo'] = '';
} else {
  // Regla: Cupón vs Promo (mutuamente excluyentes)
  if ($monto_cupon > 0 && ($promo_regalo_aplicado === 1 || $promo_descuento_aplicado === 1)) {
    $__promo_err_exclusividad = "No puedes aplicar CUPÓN y PROMO en la misma venta. Elige solo uno.";
  }

  if ($promo_regalo_aplicado === 1 && $promo_descuento_aplicado === 1) {
    $__promo_err_doble_promo = "No puedes aplicar dos PROMOS a la vez (regalo y descuento).";
  }
}

// Si hay promo (regalo o descuento), forzamos cupón a 0 para evitar descuentos dobles.
if (($promo_regalo_aplicado === 1 || $promo_descuento_aplicado === 1)) {
  $monto_cupon = 0.0;
  $monto_cupon_principal = 0.0;
  $monto_cupon_combo = 0.0;
  $cupon_aplicado = 0;
}

$errores = [];

// Exclusividad cupón vs promos
if (!empty($__promo_err_exclusividad)) $errores[] = $__promo_err_exclusividad;
if (!empty($__promo_err_doble_promo))  $errores[] = $__promo_err_doble_promo;

// Base
if (!$tipo_venta)            $errores[] = "Selecciona el tipo de venta.";
if ($precio_venta <= 0)      $errores[] = "El precio de venta debe ser mayor a 0.";
if (!$forma_pago_enganche)   $errores[] = "Selecciona la forma de pago.";
if ($equipo1 <= 0)           $errores[] = "Selecciona el equipo principal.";
if ($id_cliente <= 0)        $errores[] = "Debes seleccionar un cliente antes de registrar la venta.";

if ($equipo1 && !validarInventario($conn, $equipo1, $id_sucursal)) {
  $errores[] = "El equipo principal no está disponible en la sucursal seleccionada.";
}

/* ========================
   Promo Regalo (backend seguro)
======================== */
$promoActiva = null;
$promo_id_final = null;
$promo_nombre_final = '';

if ($promo_regalo_aplicado === 1) {
  $promoActiva = buscarPromoRegaloActivaPorInventario($conn, $equipo1);

  if (!$promoActiva) {
    $errores[] = "La promo de regalo ya no está activa para el equipo principal.";
  } else {
    $promo_id_final = (int)$promoActiva['id'];
    $promo_nombre_final = (string)$promoActiva['nombre'];

    if ($tipo_venta !== 'Financiamiento+Combo') $errores[] = "Para aplicar promo regalo, la venta debe ser Financiamiento+Combo.";
    if ($equipo2 <= 0) $errores[] = "Para promo regalo debes seleccionar un equipo combo.";

    if ($equipo2 > 0) {
      if ($equipo2 === $equipo1) $errores[] = "El equipo combo (regalo) no puede ser el mismo que el principal.";
      if (!validarInventario($conn, $equipo2, $id_sucursal)) {
        $errores[] = "El equipo combo no está disponible en la sucursal seleccionada.";
      } elseif (!validarRegaloElegible($conn, $equipo2)) {
        $errores[] = "El equipo seleccionado como regalo no es elegible para esta promo.";
      }
    }
  }
}

/* ========================
   Promo Descuento (backend seguro)
======================== */
$promoDesc = null;
$porcDesc  = 0.0;
$precioComboConDesc = null;
$precioPrincipalConDesc = null;
$promo_desc_nombre = '';

if ($promo_regalo_aplicado !== 1 && $promo_descuento_aplicado === 1) {

  if ($es_doble_venta_descuento === 1) {
    if ($tag_origen_descuento === '') {
      $errores[] = "Para aplicar descuento en segunda venta, debes capturar el TAG origen.";
    } else {
      $codigoPrincipalOrigen = obtenerCodigoPrincipalPorTag($conn, $tag_origen_descuento);
      if (!$codigoPrincipalOrigen) {
        $errores[] = "No se encontró un equipo principal en el TAG origen (o el TAG no existe).";
      } else {
        $promoDesc = buscarPromoDescuentoActivaPorCodigoPrincipal($conn, $codigoPrincipalOrigen);
        if (!$promoDesc) {
          $errores[] = "El TAG origen no corresponde a una promo activa de descuento.";
        } else {
          if ((int)$promoDesc['permite_doble_venta'] !== 1) $errores[] = "La promo activa no permite aplicarse como segunda venta.";

          $porcDesc = (float)$promoDesc['porcentaje'];
          $promo_desc_nombre = (string)$promoDesc['nombre'];

          if ($tipo_venta !== 'Financiamiento') $errores[] = "La segunda venta con descuento debe registrarse como Financiamiento.";

          if (!validarInventarioEnListaComboPromo($conn, (int)$promoDesc['id'], $equipo1)) {
            $errores[] = "El equipo seleccionado no es elegible como segundo equipo con descuento para esta promo.";
          } else {
            $precioLista = precioListaPorInventario($conn, $equipo1);
            $precioPrincipalConDesc = $precioLista * (1 - ($porcDesc / 100));
            if ($precioPrincipalConDesc < 0) $precioPrincipalConDesc = 0.0;
          }
        }
      }
    }
  } else {
    if ($tipo_venta !== 'Financiamiento+Combo') $errores[] = "Para aplicar descuento en combo, la venta debe ser Financiamiento+Combo.";
    if ($equipo2 <= 0) $errores[] = "Para aplicar descuento en combo, debes seleccionar el equipo combo.";

    if ($equipo2 > 0) {
      if ($equipo2 === $equipo1) $errores[] = "El equipo combo debe ser distinto del principal.";
      if (!validarInventario($conn, $equipo2, $id_sucursal)) {
        $errores[] = "El equipo combo no está disponible en la sucursal seleccionada.";
      } else {
        $codigoPrincipal = obtenerCodigoProductoPorInventario($conn, $equipo1);
        $promoDesc = buscarPromoDescuentoActivaPorCodigoPrincipal($conn, $codigoPrincipal);
        if (!$promoDesc) {
          $errores[] = "El equipo principal no tiene una promo activa de descuento.";
        } else {
          if ((int)$promoDesc['permite_combo'] !== 1) $errores[] = "La promo activa no permite aplicarse en combo dentro de la misma venta.";
          if ($promo_descuento_id > 0 && (int)$promoDesc['id'] !== (int)$promo_descuento_id) {
            $errores[] = "La promo seleccionada ya no coincide con la promo activa para el equipo principal.";
          }
          $porcDesc = (float)$promoDesc['porcentaje'];
          $promo_desc_nombre = (string)$promoDesc['nombre'];

          if (!validarInventarioEnListaComboPromo($conn, (int)$promoDesc['id'], $equipo2)) {
            $errores[] = "El equipo combo seleccionado no es elegible para el descuento de esta promo.";
          } else {
            $precioListaCombo = precioListaPorInventario($conn, $equipo2);
            $precioComboConDesc = $precioListaCombo * (1 - ($porcDesc / 100));
            if ($precioComboConDesc < 0) $precioComboConDesc = 0.0;
          }
        }
      }
    }
  }
}

// Combo normal si no hay promos
if ($promo_regalo_aplicado !== 1 && $promo_descuento_aplicado !== 1 && $tipo_venta === 'Financiamiento+Combo') {
  if ($equipo2 <= 0) $errores[] = "Selecciona el equipo combo.";
  if ($equipo2 > 0) {
    if ($equipo2 === $equipo1) $errores[] = "El equipo combo no puede ser el mismo que el principal.";
    if (!validarInventario($conn, $equipo2, $id_sucursal)) $errores[] = "El equipo combo no está disponible en la sucursal seleccionada.";
  }
}

$esFin = in_array($tipo_venta, ['Financiamiento', 'Financiamiento+Combo'], true);

if ($esFin) {
  if ($nombre_cliente === '') $errores[] = "Nombre del cliente es obligatorio.";
  if ($telefono_cliente === '' || !preg_match('/^\d{10}$/', $telefono_cliente)) $errores[] = "Teléfono del cliente debe tener 10 dígitos.";
  if ($tag === '')            $errores[] = "TAG (ID del crédito) es obligatorio.";
  if ($enganche < 0)          $errores[] = "El enganche no puede ser negativo (puede ser 0).";
  if ($plazo_semanas <= 0)    $errores[] = "El plazo en semanas debe ser mayor a 0.";
  if ($pago_semanal <= 0)     $errores[] = "El pago semanal debe ser mayor a 0.";
  if ($primer_pago === '')    $errores[] = "Debes capturar la fecha del primer pago.";
  if ($primer_pago !== '' && !esFechaYmdValida($primer_pago)) $errores[] = "La fecha del primer pago no es válida.";
  if ($financiera === '')     $errores[] = "Selecciona una financiera (no puede ser N/A).";

  if ($forma_pago_enganche === 'Mixto') {
    if ($enganche_efectivo <= 0 && $enganche_tarjeta <= 0) $errores[] = "En pago Mixto, al menos uno de los montos debe ser > 0.";
    if (round($enganche_efectivo + $enganche_tarjeta, 2) !== round($enganche, 2)) $errores[] = "Efectivo + Tarjeta debe ser igual al Enganche.";
  }
} else {
  $tag               = generarTagContado($conn);
  $plazo_semanas     = 0;
  $pago_semanal      = 0.0;
  $primer_pago       = '';
  $financiera        = 'N/A';
  $enganche_efectivo = 0;
  $enganche_tarjeta  = 0;
}

/* Validaciones de cupón por flujo */
if ($monto_cupon_principal > 0 && $equipo1 <= 0) {
  $errores[] = "No se puede aplicar cupón al equipo principal porque no hay equipo principal seleccionado.";
}

if ($monto_cupon_combo > 0 && $equipo2 <= 0) {
  $errores[] = "No se puede aplicar cupón al equipo combo porque no hay equipo combo seleccionado.";
}

if ($monto_cupon_combo > 0 && $tipo_venta !== 'Financiamiento+Combo') {
  $errores[] = "No se puede aplicar cupón al combo si la venta no es Financiamiento+Combo.";
}

/* Recalcular precio_venta */
if (!$errores) {
  $calc = 0.0;

  if ($promo_regalo_aplicado === 1) {
    $p1 = precioListaPorInventario($conn, $equipo1);
    $calc = $p1; // combo regalo = 0
  } elseif ($promo_descuento_aplicado === 1) {
    if ($es_doble_venta_descuento === 1) {
      if ($precioPrincipalConDesc !== null) {
        $calc = (float)$precioPrincipalConDesc;
      }
    } else {
      $p1 = precioListaPorInventario($conn, $equipo1);
      $p2 = ($precioComboConDesc !== null) ? (float)$precioComboConDesc : 0.0;
      $calc = $p1 + $p2;
    }
  } else {
    $p1 = ($equipo1 > 0) ? precioListaPorInventario($conn, $equipo1) : 0.0;
    $calc = $p1;

    if ($tipo_venta === 'Financiamiento+Combo' && $equipo2 > 0) {
      $precioLista2 = precioListaPorInventario($conn, $equipo2);

      // Intentamos respetar precio combo si viene desde el option del front en el total enviado,
      // pero en backend la referencia segura sigue siendo precio_lista cuando no hay columna dedicada.
      // Como tu flujo previo ya operaba así para el principal, aquí mantenemos consistencia.
      $calc += $precioLista2;
    }
  }

  $calc -= $monto_cupon_principal;
  $calc -= $monto_cupon_combo;
  if ($calc < 0) $calc = 0.0;

  // Para usuarios LUGA normales el total se recalcula automáticamente.
  // Para roles Subdis_* se respeta el precio capturado manualmente.
  if (!$esRolSubdis) {
    if (abs($precio_venta - $calc) > 0.5) {
      $precio_venta = $calc;
    }
  }
}

if ($errores) {
  header("Location: nueva_venta.php?err=" . urlencode(implode(' ', $errores)));
  exit();
}

/* Propiedad */
$propietario = 'LUGA';
$id_subdis   = null;

if ($esRolSubdis) {
  $propietario = 'SUBDISTRIBUIDOR';

  if (isset($_SESSION['id_subdis'])) {
    $tmp = (int)$_SESSION['id_subdis'];
    $id_subdis = $tmp > 0 ? $tmp : null;
  } elseif (isset($_SESSION['id_subdistribuidor'])) {
    $tmp = (int)$_SESSION['id_subdistribuidor'];
    $id_subdis = $tmp > 0 ? $tmp : null;
  }

  if ($id_subdis === null) {
    $colSub = null;
    if (columnExists($conn, 'usuarios', 'id_subdis')) $colSub = 'id_subdis';
    elseif (columnExists($conn, 'usuarios', 'id_subdistribuidor')) $colSub = 'id_subdistribuidor';

    if ($colSub) {
      $st = $conn->prepare("SELECT `$colSub` AS id_sub FROM usuarios WHERE id=? LIMIT 1");
      $st->bind_param("i", $id_usuario);
      $st->execute();
      $ru = $st->get_result()->fetch_assoc();
      $st->close();

      $tmp = (int)($ru['id_sub'] ?? 0);
      $id_subdis = $tmp > 0 ? $tmp : null;
    }
  }

  if ($id_subdis === null) $propietario = 'LUGA';
}

// Columnas opcionales
$tieneUltimaCompra   = columnExists($conn, 'clientes', 'ultima_compra');
$tieneMontoCupon     = columnExists($conn, 'ventas', 'monto_cupon');
$tieneCuponAplicado  = columnExists($conn, 'ventas', 'cupon_aplicado');
$tienePropietario    = columnExists($conn, 'ventas', 'propietario');
$tieneIdSubdis       = columnExists($conn, 'ventas', 'id_subdis');

$tienePromoAplicado  = columnExists($conn, 'ventas', 'promo_regalo_aplicado');
$tienePromoId        = columnExists($conn, 'ventas', 'id_promo_regalo');
$tienePromoNombre    = columnExists($conn, 'ventas', 'promo_regalo_nombre');

$tienePromoDescAplicado = columnExists($conn, 'ventas', 'promo_descuento_aplicado');
$tienePromoDescId       = columnExists($conn, 'ventas', 'id_promo_descuento');
$tienePromoDescNombre   = columnExists($conn, 'ventas', 'promo_descuento_nombre');
$tienePromoDescPorc     = columnExists($conn, 'ventas', 'promo_descuento_porcentaje');
$tieneTagOrigenDesc     = columnExists($conn, 'ventas', 'tag_origen_descuento');
$tieneEsDobleVentaDesc  = columnExists($conn, 'ventas', 'promo_descuento_doble_venta');

$tienePagoSemanal       = columnExists($conn, 'ventas', 'pago_semanal');
$tienePrimerPago        = columnExists($conn, 'ventas', 'primer_pago');

/* ========================
   Insertar Venta (TX)
======================== */
try {
  $conn->begin_transaction();

  $cols = [];
  $vals = [];
  $types = '';
  $params = [];

  $cols[] = "tag";
  $vals[] = "?";
  $types .= "s";
  $params[] = $tag;

  $cols[] = "nombre_cliente";
  $vals[] = "?";
  $types .= "s";
  $params[] = $nombre_cliente;

  $cols[] = "telefono_cliente";
  $vals[] = "?";
  $types .= "s";
  $params[] = $telefono_cliente;

  $cols[] = "id_cliente";
  $vals[] = "?";
  $types .= "i";
  $params[] = $id_cliente;

  $cols[] = "tipo_venta";
  $vals[] = "?";
  $types .= "s";
  $params[] = $tipo_venta;

  $cols[] = "precio_venta";
  $vals[] = "?";
  $types .= "d";
  $params[] = $precio_venta;

  if ($tieneMontoCupon) {
    $cols[] = "monto_cupon";
    $vals[] = "?";
    $types .= "d";
    $params[] = $monto_cupon;
  }
  if ($tieneCuponAplicado) {
    $cols[] = "cupon_aplicado";
    $vals[] = "?";
    $types .= "i";
    $params[] = $cupon_aplicado;
  }

  if ($tienePromoAplicado) {
    $cols[] = "promo_regalo_aplicado";
    $vals[] = "?";
    $types .= "i";
    $params[] = $promo_regalo_aplicado;
  }
  if ($tienePromoId) {
    $cols[] = "id_promo_regalo";
    $vals[] = "?";
    $types .= "i";
    $params[] = (int)($promo_id_final ?? 0);
  }
  if ($tienePromoNombre) {
    $cols[] = "promo_regalo_nombre";
    $vals[] = "?";
    $types .= "s";
    $params[] = $promo_nombre_final;
  }

  if ($tienePromoDescAplicado) {
    $cols[] = "promo_descuento_aplicado";
    $vals[] = "?";
    $types .= "i";
    $params[] = $promo_descuento_aplicado;
  }
  if ($tienePromoDescId) {
    $cols[] = "id_promo_descuento";
    $vals[] = "?";
    $types .= "i";

    $promoIdFinal = 0;

    if ($promoDesc && isset($promoDesc['id'])) {
      $promoIdFinal = (int)$promoDesc['id'];
    } elseif ($promo_descuento_id > 0) {
      $promoIdFinal = (int)$promo_descuento_id;
    }

    $params[] = $promoIdFinal;
  }
  if ($tienePromoDescNombre) {
    $cols[] = "promo_descuento_nombre";
    $vals[] = "?";
    $types .= "s";
    $params[] = $promo_desc_nombre;
  }
  if ($tienePromoDescPorc) {
    $cols[] = "promo_descuento_porcentaje";
    $vals[] = "?";
    $types .= "d";
    $params[] = (float)($porcDesc ?? 0.0);
  }
  if ($tieneTagOrigenDesc) {
    $cols[] = "tag_origen_descuento";
    $vals[] = "?";
    $types .= "s";
    $params[] = $tag_origen_descuento;
  }
  if ($tieneEsDobleVentaDesc) {
    $cols[] = "promo_descuento_doble_venta";
    $vals[] = "?";
    $types .= "i";
    $params[] = $es_doble_venta_descuento;
  }

  if ($tienePropietario) {
    $cols[] = "propietario";
    $vals[] = "?";
    $types .= "s";
    $params[] = $propietario;
  }
  if ($tieneIdSubdis) {
    $cols[] = "id_subdis";
    $vals[] = "?";
    $types .= "i";
    $params[] = (int)($id_subdis ?? 0);
  }

  $cols[] = "id_usuario";
  $vals[] = "?";
  $types .= "i";
  $params[] = $id_usuario;

  $cols[] = "id_sucursal";
  $vals[] = "?";
  $types .= "i";
  $params[] = $id_sucursal;

  $cols[] = "comision";
  $vals[] = "?";
  $types .= "d";
  $params[] = 0.0;

  $cols[] = "enganche";
  $vals[] = "?";
  $types .= "d";
  $params[] = $enganche;

  $cols[] = "forma_pago_enganche";
  $vals[] = "?";
  $types .= "s";
  $params[] = $forma_pago_enganche;

  $cols[] = "enganche_efectivo";
  $vals[] = "?";
  $types .= "d";
  $params[] = $enganche_efectivo;

  $cols[] = "enganche_tarjeta";
  $vals[] = "?";
  $types .= "d";
  $params[] = $enganche_tarjeta;

  $cols[] = "plazo_semanas";
  $vals[] = "?";
  $types .= "i";
  $params[] = $plazo_semanas;

  if ($tienePagoSemanal) {
    $cols[] = "pago_semanal";
    $vals[] = "?";
    $types .= "d";
    $params[] = $pago_semanal;
  }

  if ($tienePrimerPago) {
    $cols[] = "primer_pago";
    $vals[] = "?";
    $types .= "s";
    $params[] = ($primer_pago !== '' ? $primer_pago : null);
  }

  $cols[] = "financiera";
  $vals[] = "?";
  $types .= "s";
  $params[] = $financiera;

  $cols[] = "comentarios";
  $vals[] = "?";
  $types .= "s";
  $params[] = $comentarios;

  $sqlVenta = "INSERT INTO ventas (" . implode(",", $cols) . ") VALUES (" . implode(",", $vals) . ")";
  $stmtVenta = $conn->prepare($sqlVenta);

  $bind = [];
  $bind[] = $types;
  for ($i = 0; $i < count($params); $i++) $bind[] = &$params[$i];
  call_user_func_array([$stmtVenta, 'bind_param'], $bind);

  $stmtVenta->execute();
  $id_venta = (int)$stmtVenta->insert_id;
  $stmtVenta->close();

  /* Registrar equipos */
  $tieneEsCombo         = columnExists($conn, 'detalle_venta', 'es_combo');
  $tieneComisionGerente = columnExists($conn, 'detalle_venta', 'comision_gerente');

  $overridePrincipal = null;
  $overrideCombo = null;

  // Subdis_* puede capturar manualmente el precio de venta total.
  // Sin combo: todo el monto capturado se guarda en el principal.
  // Con combo: tomamos el precio de lista del principal y el resto se asigna al combo.
  if ($esRolSubdis && $promo_regalo_aplicado !== 1 && $promo_descuento_aplicado !== 1) {
    if ($tipo_venta === 'Financiamiento+Combo' && $equipo2 > 0) {
      $precioListaPrincipal = precioListaPorInventario($conn, $equipo1);
      $overridePrincipal = (float)$precioListaPrincipal;
      $overrideCombo = (float)$precio_venta - (float)$precioListaPrincipal;
      if ($overrideCombo < 0) $overrideCombo = 0.0;
    } else {
      $overridePrincipal = (float)$precio_venta;
    }
  }

  if ($promo_regalo_aplicado !== 1 && $promo_descuento_aplicado === 1 && $es_doble_venta_descuento === 1 && $precioPrincipalConDesc !== null) {
    $overridePrincipal = (float)$precioPrincipalConDesc;
  }

  $totalComision = 0.0;

  // Principal
  $totalComision += venderEquipo(
    $conn,
    $id_venta,
    $equipo1,
    false,
    $rol_usuario,
    $tieneEsCombo,
    $tieneComisionGerente,
    $colTipoProd,
    $monto_cupon_principal,
    false,
    false,
    $overridePrincipal
  );

  // Combo
  if ($tipo_venta === 'Financiamiento+Combo' && $equipo2) {
    $esRegalo = ($promo_regalo_aplicado === 1);

    if (!$esRegalo && $promo_descuento_aplicado === 1 && $precioComboConDesc !== null) {
      $overrideCombo = (float)$precioComboConDesc;
    }

    $totalComision += venderEquipo(
      $conn,
      $id_venta,
      $equipo2,
      true,
      $rol_usuario,
      $tieneEsCombo,
      $tieneComisionGerente,
      $colTipoProd,
      $monto_cupon_combo,
      $esRegalo,
      $esRegalo,
      $overrideCombo
    );
  }

  $stmtUpd = $conn->prepare("UPDATE ventas SET comision=? WHERE id=?");
  $stmtUpd->bind_param("di", $totalComision, $id_venta);
  $stmtUpd->execute();
  $stmtUpd->close();

  if ($tieneUltimaCompra && $id_cliente > 0) {
    $stmtCli = $conn->prepare("UPDATE clientes SET ultima_compra = NOW() WHERE id = ?");
    $stmtCli->bind_param("i", $id_cliente);
    $stmtCli->execute();
    $stmtCli->close();
  }

  $conn->commit();

  $extra = '';
  if ($promo_regalo_aplicado === 1 && $promo_id_final) $extra .= " | Promo regalo (#{$promo_id_final})";
  if ($promo_regalo_aplicado !== 1 && $promo_descuento_aplicado === 1 && $promoDesc) {
    $extra .= " | Promo descuento (#{$promoDesc['id']})";
    if ($es_doble_venta_descuento === 1 && $tag_origen_descuento !== '') $extra .= " TAG origen: {$tag_origen_descuento}";
  }
  if ($monto_cupon > 0) {
    $extra .= " | Cupón total $" . number_format($monto_cupon, 2);
  }

  header("Location: historial_ventas.php?msg=" . urlencode("Venta #$id_venta registrada. Comisión $" . number_format($totalComision, 2) . $extra));
  exit();
} catch (Throwable $e) {
  $conn->rollback();
  header("Location: nueva_venta.php?err=" . urlencode("Error al registrar la venta: " . $e->getMessage()));
  exit();
}
