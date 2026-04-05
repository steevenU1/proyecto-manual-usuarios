<?php
// payjoy_tc_guardar.php — Guardar venta PayJoy TC

session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_features.php';

$ROL        = $_SESSION['rol'] ?? 'Ejecutivo';
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_POST['id_sucursal'] ?? ($_SESSION['id_sucursal'] ?? 0));

$isAdminLike = in_array($ROL, ['Admin','Super','SuperAdmin','RH'], true);

// Bandera efectiva
$flagOpen = PAYJOY_TC_CAPTURE_OPEN || ($isAdminLike && PAYJOY_TC_ADMIN_PREVIEW);

// Bloquea si no está habilitado
if (!$flagOpen) {
    header("Location: payjoy_tc_nueva.php?err=" . urlencode("❌ La captura de PayJoy TC aún no está habilitada."));
    exit();
}

// Datos
$nombreCliente = trim($_POST['nombre_cliente'] ?? '');
$tag           = trim($_POST['tag'] ?? '');
$comentarios   = trim($_POST['comentarios'] ?? '');
$comision      = 100.00; // fija

if ($nombreCliente === '' || $tag === '') {
    header("Location: payjoy_tc_nueva.php?err=" . urlencode("Faltan datos obligatorios"));
    exit();
}

/* =========================
   Helpers (compat)
========================= */
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

/* =========================
   ✅ Propiedad (LUGA vs SUBDISTRIBUIDOR) + id_subdis
   Roles subdis reales: Subdistribuidor y Subdis_*
========================= */
$propietario = 'LUGA';
$id_subdis   = null;

$esRolSubdis = ($ROL === 'Subdistribuidor') || (strpos($ROL, 'Subdis_') === 0);

if ($esRolSubdis) {
    $propietario = 'SUBDISTRIBUIDOR';

    // 1) desde sesión
    if (isset($_SESSION['id_subdis'])) {
        $tmp = (int)$_SESSION['id_subdis'];
        $id_subdis = $tmp > 0 ? $tmp : null;
    } elseif (isset($_SESSION['id_subdistribuidor'])) {
        $tmp = (int)$_SESSION['id_subdistribuidor'];
        $id_subdis = $tmp > 0 ? $tmp : null;
    }

    // 2) desde usuarios (compat)
    if ($id_subdis === null) {
        $colSub = null;
        if (columnExists($conn, 'usuarios', 'id_subdis')) $colSub = 'id_subdis';
        elseif (columnExists($conn, 'usuarios', 'id_subdistribuidor')) $colSub = 'id_subdistribuidor';

        if ($colSub) {
            $st = $conn->prepare("SELECT `$colSub` AS id_sub FROM usuarios WHERE id=? LIMIT 1");
            $st->bind_param("i", $idUsuario);
            $st->execute();
            $ru = $st->get_result()->fetch_assoc();
            $st->close();

            $tmp = (int)($ru['id_sub'] ?? 0);
            $id_subdis = $tmp > 0 ? $tmp : null;
        }
    }

    // 3) seguridad: no guardar SUBDISTRIBUIDOR sin id_subdis
    if ($id_subdis === null) {
        $propietario = 'LUGA';
    }
}

// Columnas nuevas en ventas_payjoy_tc (ya las agregaste, pero lo dejamos a prueba de balas)
$tienePropietario = columnExists($conn, 'ventas_payjoy_tc', 'propietario');
$tieneIdSubdis    = columnExists($conn, 'ventas_payjoy_tc', 'id_subdis');

/* =========================
   Insert (con o sin propiedad)
========================= */
if ($tienePropietario && $tieneIdSubdis) {

    $stmt = $conn->prepare("
        INSERT INTO ventas_payjoy_tc (
            id_usuario,
            id_sucursal,
            nombre_cliente,
            tag,
            comision,
            comentarios,
            propietario,
            id_subdis,
            fecha_venta
        ) VALUES (?,?,?,?,?,?,?,?,NOW())
    ");

    $stmt->bind_param(
        "iissdssi",
        $idUsuario,
        $idSucursal,
        $nombreCliente,
        $tag,
        $comision,
        $comentarios,
        $propietario,
        $id_subdis
    );

} else {

    $stmt = $conn->prepare("
        INSERT INTO ventas_payjoy_tc (
            id_usuario,
            id_sucursal,
            nombre_cliente,
            tag,
            comision,
            comentarios,
            fecha_venta
        ) VALUES (?,?,?,?,?,?,NOW())
    ");

    $stmt->bind_param(
        "iissds",
        $idUsuario,
        $idSucursal,
        $nombreCliente,
        $tag,
        $comision,
        $comentarios
    );
}

$stmt->execute();
$stmt->close();

header("Location: historial_payjoy_tc.php?msg=" . urlencode("✅ Venta registrada correctamente"));
exit();
