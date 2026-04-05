<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';

$ROL       = $_SESSION['rol'] ?? '';
$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
$idSucSes  = (int)($_SESSION['id_sucursal'] ?? 0);

/* ================= Helpers ================= */
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = '{$t}'
            AND COLUMN_NAME  = '{$c}' LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

// util: semana martes-lunes (rango actual)
function rangoSemanaActual() {
  $hoy = new DateTime();
  $n = (int)$hoy->format('N'); // 1=lun ... 7=dom
  $dif = $n - 2;               // martes=2
  if ($dif < 0) $dif += 7;
  $inicio = (new DateTime())->modify("-$dif days")->setTime(0,0,0);
  $fin    = (clone $inicio)->modify("+6 days")->setTime(23,59,59);
  return [$inicio, $fin];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: historial_ventas.php");
  exit();
}

/* ================= Entrada ================= */
$idVenta   = (int)($_POST['id_venta'] ?? 0);
$tag       = trim($_POST['tag'] ?? '');
$precio    = (float)($_POST['precio_venta'] ?? 0);
$enganche  = (float)($_POST['enganche'] ?? 0);
$forma     = trim($_POST['forma_pago_enganche'] ?? '');
$cliente   = trim($_POST['nombre_cliente'] ?? '');
$telefono  = trim($_POST['telefono_cliente'] ?? '');

// normaliza forma de pago
$formasOK = ['','Efectivo','Tarjeta','Mixto','N/A'];
if (!in_array($forma, $formasOK, true)) $forma = '';

if ($idVenta <= 0 || $precio < 0 || $enganche < 0) {
  header("Location: historial_ventas.php?msg=" . urlencode("Datos inválidos."));
  exit();
}

/* =========================================================
   TENANT (Luga / Subdis + id_subdis) detectado por sucursal de sesión
========================================================= */
$colSucSubtipo  = hasColumn($conn, 'sucursales', 'subtipo');
$colSucIdSubdis = hasColumn($conn, 'sucursales', 'id_subdis');

$tenantPropiedad = 'Luga';
$tenantIdSubdis  = 0;
$subtipoSesion   = '';

if ($idSucSes > 0) {
  if ($colSucSubtipo && $colSucIdSubdis) {
    $stTen = $conn->prepare("SELECT subtipo, id_subdis FROM sucursales WHERE id=? LIMIT 1");
    $stTen->bind_param("i", $idSucSes);
  } elseif ($colSucSubtipo) {
    $stTen = $conn->prepare("SELECT subtipo, 0 AS id_subdis FROM sucursales WHERE id=? LIMIT 1");
    $stTen->bind_param("i", $idSucSes);
  } else {
    $stTen = $conn->prepare("SELECT '' AS subtipo, 0 AS id_subdis FROM sucursales WHERE id=? LIMIT 1");
    $stTen->bind_param("i", $idSucSes);
  }

  $stTen->execute();
  $rowTen = $stTen->get_result()->fetch_assoc();
  $stTen->close();

  $subtipoSesion  = $rowTen['subtipo'] ?? '';
  $tenantIdSubdis = (int)($rowTen['id_subdis'] ?? 0);

  if (trim((string)$subtipoSesion) === 'Subdistribuidor') {
    $tenantPropiedad = 'Subdis';
  }
}

// fallback por rol si subtipo no está bien
$rolLower = strtolower((string)$ROL);
if ($tenantPropiedad === 'Luga' && strpos($rolLower, 'subdis') !== false) {
  $tenantPropiedad = 'Subdis';
  $tenantIdSubdis  = (int)($_SESSION['id_subdis'] ?? $tenantIdSubdis);
}

$esSubdis = ($tenantPropiedad === 'Subdis');

/* =========================================================
   Carga dueño + tipo + fecha + tenant data (ventas + sucursal)
========================================================= */
$colVenProp = hasColumn($conn, 'ventas', 'propiedad');
$colVenSub  = hasColumn($conn, 'ventas', 'id_subdis');

$selectProp = $colVenProp ? "v.propiedad AS v_propiedad" : "'' AS v_propiedad";
$selectSub  = $colVenSub  ? "v.id_subdis AS v_id_subdis" : "0 AS v_id_subdis";
$selectSubt = $colSucSubtipo ? "s.subtipo AS s_subtipo" : "'' AS s_subtipo";
$selectSidS = $colSucIdSubdis ? "s.id_subdis AS s_id_subdis" : "0 AS s_id_subdis";

$sqlVenta = "
  SELECT
    v.id_usuario, v.tipo_venta, v.fecha_venta,
    {$selectProp},
    {$selectSub},
    {$selectSubt},
    {$selectSidS}
  FROM ventas v
  INNER JOIN sucursales s ON s.id = v.id_sucursal
  WHERE v.id=? LIMIT 1
";
$st = $conn->prepare($sqlVenta);
$st->bind_param("i", $idVenta);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();

if (!$row) {
  header("Location: historial_ventas.php?msg=" . urlencode("Venta no encontrada."));
  exit();
}

$idDueno    = (int)$row['id_usuario'];
$tipoVenta  = (string)$row['tipo_venta'];
$fechaVenta = new DateTime($row['fecha_venta']);

// Tenant check
$ventaPropiedad = trim((string)($row['v_propiedad'] ?? ''));
$ventaIdSubdis  = (int)($row['v_id_subdis'] ?? 0);
$sucSubtipo     = trim((string)($row['s_subtipo'] ?? ''));
$sucIdSubdis    = (int)($row['s_id_subdis'] ?? 0);

$okTenant = true;

if ($esSubdis) {
  if ($colVenProp) {
    if ($ventaPropiedad !== 'Subdis') $okTenant = false;
  } else {
    if ($colSucSubtipo && $sucSubtipo !== 'Subdistribuidor') $okTenant = false;
  }

  if ($colVenSub) {
    if ($tenantIdSubdis > 0 && $ventaIdSubdis !== $tenantIdSubdis) $okTenant = false;
  } else {
    if ($colSucIdSubdis && $tenantIdSubdis > 0 && $sucIdSubdis !== $tenantIdSubdis) $okTenant = false;
  }
} else {
  if ($colVenProp) {
    if (!($ventaPropiedad === 'Luga' || $ventaPropiedad === '')) $okTenant = false;
  } else {
    if ($colSucSubtipo && $sucSubtipo === 'Subdistribuidor') $okTenant = false;
  }

  if ($colVenSub) {
    if ($ventaIdSubdis !== 0) $okTenant = false;
  } else {
    if ($colSucIdSubdis && $sucIdSubdis !== 0) $okTenant = false;
  }
}

if (!$okTenant) {
  header("Location: historial_ventas.php?msg=" . urlencode("No autorizado (tenant)."));
  exit();
}

/* =========================================================
   Permisos: Admin cualquiera (pero ya pasó tenant); Ejecut/Gen/SubdisEjecutivo solo propias
========================================================= */
$puedeEditar = false;

if ($ROL === 'Admin') {
  $puedeEditar = true;
} else {
  $rolesPropios = ['Ejecutivo','Gerente','subdis_ejecutivo','Subdis_Ejecutivo','SubdisEjecutivo'];
  if (in_array($ROL, $rolesPropios, true) && $idDueno === $idUsuario) {
    $puedeEditar = true;
  }
}

if (!$puedeEditar) {
  header("Location: historial_ventas.php?msg=" . urlencode("No puedes editar esta venta."));
  exit();
}

/* Ventana: solo semana actual */
list($iniSemana, $finSemana) = rangoSemanaActual();
if ($fechaVenta < $iniSemana || $fechaVenta > $finSemana) {
  header("Location: historial_ventas.php?msg=" . urlencode("Solo puedes editar ventas de la semana actual."));
  exit();
}

/* Regla TAG: requerido salvo Contado */
if ($tipoVenta !== 'Contado' && $tag === '') {
  header("Location: historial_ventas.php?msg=" . urlencode("El TAG es obligatorio para este tipo de venta."));
  exit();
}

/* =========================================================
   UPDATE solo campos permitidos
   + (opcional) reforzar el UPDATE con condición tenant si existen columnas
========================================================= */
$sql = "UPDATE ventas
        SET tag=?, precio_venta=?, enganche=?, forma_pago_enganche=?,
            nombre_cliente=?, telefono_cliente=?
        WHERE id=?";

$params = [$tag, $precio, $enganche, $forma, $cliente, $telefono, $idVenta];
$types  = "sddsssi";

// Refuerzo en el WHERE por tenant (si existen columnas)
if ($esSubdis) {
  if ($colVenProp) $sql .= " AND propiedad='Subdis' ";
  if ($colVenSub)  { $sql .= " AND id_subdis=? "; $params[] = $tenantIdSubdis; $types .= "i"; }
} else {
  if ($colVenProp) $sql .= " AND (propiedad='Luga' OR propiedad IS NULL OR propiedad='') ";
  if ($colVenSub)  $sql .= " AND (id_subdis IS NULL OR id_subdis=0) ";
}

$st = $conn->prepare($sql);
$st->bind_param($types, ...$params);
$st->execute();
$st->close();

header("Location: historial_ventas.php?msg=" . urlencode("Venta #$idVenta actualizada."));
exit();
