<?php
// switch_venta_sim.php — Cambia venta a Portabilidad y deja comisiones en 0
// Reglas: solo semana actual (Mar→Lun), solo Ejecutivo/SubdisEjecutivo, solo ventas propias, solo si hoy es "Nueva".
declare(strict_types=1);

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

date_default_timezone_set('America/Mexico_City');
require_once __DIR__ . '/db.php';

/* ===== Helpers ===== */
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

function limitesSemanaActualMX(): array {
    // Semana operativa Mar→Lun con TZ MX
    $now = new DateTime('now');
    $n   = (int)$now->format('N'); // 1=lun...7=dom
    $dif = $n - 2; if ($dif < 0) $dif += 7; // martes=2
    $ini = (clone $now)->modify("-{$dif} days")->setTime(0,0,0);
    $fin = (clone $ini)->modify("+6 days")->setTime(23,59,59);
    return [$ini, $fin];
}
function back_to(?string $url) {
    if (!$url) $url = 'historial_ventas_sims.php';
    header("Location: $url"); exit();
}

/* ===== Validaciones base ===== */
$rol       = trim((string)($_SESSION['rol'] ?? ''));
$rolLower  = strtolower($rol);
$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
$idSucSes  = (int)($_SESSION['id_sucursal'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') back_to('historial_ventas_sims.php');

$csrf    = $_POST['csrf']     ?? '';
$back    = $_POST['back']     ?? '';
$idVenta = (int)($_POST['id_venta'] ?? 0);

if (!$csrf || !hash_equals($_SESSION['csrf_sim'] ?? '', $csrf)) {
    $_SESSION['flash_error'] = 'Solicitud inválida (CSRF).';
    back_to($back);
}

// Solo Ejecutivo o subdis_ejecutivo
$esEjecutivo = (strcasecmp($rol, 'Ejecutivo') === 0) || ($rolLower === 'subdis_ejecutivo');
if (!$esEjecutivo) {
    $_SESSION['flash_error'] = 'Solo Ejecutivos pueden usar el switch.';
    back_to($back);
}
if ($idVenta <= 0) {
    $_SESSION['flash_error'] = 'Venta inválida.';
    back_to($back);
}

/* =========================================================
   TENANT (Luga/Subdis + id_subdis) por sucursal de sesión
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

// fallback por rol
if ($tenantPropiedad === 'Luga' && strpos($rolLower, 'subdis') !== false) {
    $tenantPropiedad = 'Subdis';
    $tenantIdSubdis  = (int)($_SESSION['id_subdis'] ?? $tenantIdSubdis);
}
$esSubdis = ($tenantPropiedad === 'Subdis');

/* ===== Cargar venta (con datos tenant) ===== */
$colVsProp = hasColumn($conn, 'ventas_sims', 'propiedad');
$colVsSub  = hasColumn($conn, 'ventas_sims', 'id_subdis');

$selectProp = $colVsProp ? "vs.propiedad AS vs_propiedad" : "'' AS vs_propiedad";
$selectSub  = $colVsSub  ? "vs.id_subdis AS vs_id_subdis" : "0 AS vs_id_subdis";
$selectSubt = $colSucSubtipo ? "s.subtipo AS s_subtipo" : "'' AS s_subtipo";
$selectSidS = $colSucIdSubdis ? "s.id_subdis AS s_id_subdis" : "0 AS s_id_subdis";

$sql = "
    SELECT
      vs.id, vs.id_usuario, vs.id_sucursal, vs.tipo_venta, vs.fecha_venta,
      {$selectProp},
      {$selectSub},
      {$selectSubt},
      {$selectSidS}
    FROM ventas_sims vs
    INNER JOIN sucursales s ON s.id = vs.id_sucursal
    WHERE vs.id=? LIMIT 1
";
$st = $conn->prepare($sql);
$st->bind_param("i", $idVenta);
$st->execute();
$venta = $st->get_result()->fetch_assoc();
$st->close();

if (!$venta) {
    $_SESSION['flash_error'] = 'Venta no encontrada.';
    back_to($back);
}

/* ===== Tenant gate ===== */
$ventaPropiedad = trim((string)($venta['vs_propiedad'] ?? ''));
$ventaIdSubdis  = (int)($venta['vs_id_subdis'] ?? 0);
$sucSubtipo     = trim((string)($venta['s_subtipo'] ?? ''));
$sucIdSubdis    = (int)($venta['s_id_subdis'] ?? 0);

$okTenant = true;

if ($esSubdis) {
    if ($colVsProp) {
        if ($ventaPropiedad !== 'Subdis') $okTenant = false;
    } else {
        if ($colSucSubtipo && $sucSubtipo !== 'Subdistribuidor') $okTenant = false;
    }

    if ($colVsSub) {
        if ($tenantIdSubdis > 0 && $ventaIdSubdis !== $tenantIdSubdis) $okTenant = false;
    } else {
        if ($colSucIdSubdis && $tenantIdSubdis > 0 && $sucIdSubdis !== $tenantIdSubdis) $okTenant = false;
    }
} else {
    if ($colVsProp) {
        if (!($ventaPropiedad === 'Luga' || $ventaPropiedad === '')) $okTenant = false;
    } else {
        if ($colSucSubtipo && $sucSubtipo === 'Subdistribuidor') $okTenant = false;
    }

    if ($colVsSub) {
        if ($ventaIdSubdis !== 0) $okTenant = false;
    } else {
        if ($colSucIdSubdis && $sucIdSubdis !== 0) $okTenant = false;
    }
}

if (!$okTenant) {
    $_SESSION['flash_error'] = 'No autorizado (tenant).';
    back_to($back);
}

/* ===== Validación de ownership ===== */
if ((int)$venta['id_usuario'] !== $idUsuario) {
    $_SESSION['flash_error'] = 'Solo puedes modificar tus propias ventas.';
    back_to($back);
}

/* ===== Elegibilidad ===== */
$tipoActual = (string)($venta['tipo_venta'] ?? '');
if ($tipoActual !== 'Nueva') {
    $_SESSION['flash_error'] = 'Solo se pueden convertir ventas de tipo “Nueva”.';
    back_to($back);
}

// Semana actual (Mar→Lun)
[$iniAct, $finAct] = limitesSemanaActualMX();
$fechaVentaDT = new DateTime((string)$venta['fecha_venta']);
if ($fechaVentaDT < $iniAct || $fechaVentaDT > $finAct) {
    $_SESSION['flash_error'] = 'El switch solo está disponible durante la semana actual.';
    back_to($back);
}

/* ===== Actualizar: Portabilidad + comisiones 0 ===== */
$nota = " [switch->Portabilidad ".date('d/m')."]";

$upd  = "UPDATE ventas_sims
         SET tipo_venta='Portabilidad',
             comision_ejecutivo=0,
             comision_gerente=0,
             comentarios=CONCAT(COALESCE(comentarios,''), ?)
         WHERE id=? ";

$params = [$nota, $idVenta];
$types  = "si";

// Refuerzo tenant en UPDATE si existen columnas
if ($esSubdis) {
    if ($colVsProp) $upd .= " AND propiedad='Subdis' ";
    if ($colVsSub)  { $upd .= " AND id_subdis=? "; $params[] = $tenantIdSubdis; $types .= "i"; }
} else {
    if ($colVsProp) $upd .= " AND (propiedad='Luga' OR propiedad IS NULL OR propiedad='') ";
    if ($colVsSub)  $upd .= " AND (id_subdis IS NULL OR id_subdis=0) ";
}

$upd .= " LIMIT 1";

$st = $conn->prepare($upd);
$st->bind_param($types, ...$params);

try {
    $st->execute();
    $st->close();
    $_SESSION['flash_ok'] = '✅ Venta convertida a Portabilidad.';
} catch (mysqli_sql_exception $e) {
    $_SESSION['flash_error'] = 'No se pudo actualizar: '.$e->getMessage();
}

back_to($back);
