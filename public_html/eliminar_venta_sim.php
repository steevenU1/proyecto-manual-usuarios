<?php
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

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

/** Calcula inicio/fin de la semana actual (martes-lunes) */
function obtenerSemanaActual() : array {
    $hoy = new DateTime();
    $n   = (int)$hoy->format('N'); // 1=lun..7=dom
    $dif = $n - 2;                 // martes=2
    if ($dif < 0) $dif += 7;
    $inicio = (new DateTime())->modify("-$dif days")->setTime(0,0,0);
    $fin    = (clone $inicio)->modify("+6 days")->setTime(23,59,59);
    return [$inicio, $fin];
}

/* ================= Entrada ================= */
$idVenta    = (int)($_POST['id_venta'] ?? 0);
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario = trim((string)($_SESSION['rol'] ?? ''));
$rolLower   = strtolower($rolUsuario);
$idSucSes   = (int)($_SESSION['id_sucursal'] ?? 0);

if ($idVenta <= 0) {
    header("Location: historial_ventas_sims.php?msg=" . urlencode("❌ Venta inválida"));
    exit();
}

/* =========================================================
   TENANT (Luga / Subdis + id_subdis) por sucursal de sesión
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

/* =========================================================
   1) Cargar venta SIM + datos de sucursal (para tenant gate fallback)
========================================================= */
$colVsProp = hasColumn($conn, 'ventas_sims', 'propiedad');
$colVsSub  = hasColumn($conn, 'ventas_sims', 'id_subdis');

$selectProp = $colVsProp ? "vs.propiedad AS vs_propiedad" : "'' AS vs_propiedad";
$selectSub  = $colVsSub  ? "vs.id_subdis AS vs_id_subdis" : "0 AS vs_id_subdis";
$selectSubt = $colSucSubtipo ? "s.subtipo AS s_subtipo" : "'' AS s_subtipo";
$selectSidS = $colSucIdSubdis ? "s.id_subdis AS s_id_subdis" : "0 AS s_id_subdis";

$sqlVenta = "
  SELECT
    vs.id, vs.id_usuario, vs.id_sucursal, vs.fecha_venta,
    {$selectProp},
    {$selectSub},
    {$selectSubt},
    {$selectSidS}
  FROM ventas_sims vs
  INNER JOIN sucursales s ON s.id = vs.id_sucursal
  WHERE vs.id=? LIMIT 1
";
$st = $conn->prepare($sqlVenta);
$st->bind_param("i", $idVenta);
$st->execute();
$venta = $st->get_result()->fetch_assoc();
$st->close();

if (!$venta) {
    header("Location: historial_ventas_sims.php?msg=" . urlencode("❌ Venta de SIM no encontrada"));
    exit();
}

/* =========================================================
   2) Tenant Gate
========================================================= */
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
    header("Location: historial_ventas_sims.php?msg=" . urlencode("❌ No autorizado (tenant)."));
    exit();
}

/* =========================================================
   3) Permisos
   - Admin (Luga) o subdis_admin: puede eliminar cualquier venta dentro de su tenant (sin semana)
   - Otros: solo propia y semana actual
========================================================= */
$esAdmin = ($rolUsuario === 'Admin' || $rolLower === 'subdis_admin');
$puedeEliminar = false;

if ($esAdmin) {
    $puedeEliminar = true;
} else {
    if ((int)$venta['id_usuario'] === $idUsuario) {
        list($inicioSemana, $finSemana) = obtenerSemanaActual();
        $fechaVenta = new DateTime($venta['fecha_venta']);
        if ($fechaVenta >= $inicioSemana && $fechaVenta <= $finSemana) {
            $puedeEliminar = true;
        }
    }
}

if (!$puedeEliminar) {
    header("Location: historial_ventas_sims.php?msg=" . urlencode("❌ No tienes permiso para eliminar esta venta"));
    exit();
}

/* =========================================================
   4) Operación: devolver SIMs y borrar venta
========================================================= */
$idSucursalVenta = (int)($venta['id_sucursal'] ?? 0);

$invHasSuc  = hasColumn($conn, 'inventario_sims', 'id_sucursal');
$invHasProp = hasColumn($conn, 'inventario_sims', 'propiedad');
$invHasSub  = hasColumn($conn, 'inventario_sims', 'id_subdis');

$conn->begin_transaction();

try {
    // Devolver SIMs al inventario
    $st = $conn->prepare("SELECT id_sim FROM detalle_venta_sims WHERE id_venta=?");
    $st->bind_param("i", $idVenta);
    $st->execute();
    $res = $st->get_result();

    // Update inventario más seguro
    $whereInv = " WHERE id=? ";
    if ($invHasSuc)  $whereInv .= " AND id_sucursal=? ";
    if ($invHasProp) $whereInv .= " AND (propiedad=? OR propiedad IS NULL OR propiedad='') ";
    if ($invHasSub)  $whereInv .= " AND (id_subdis=? OR id_subdis IS NULL OR id_subdis=0) ";

    $sqlUpd = "UPDATE inventario_sims SET estatus='Disponible' {$whereInv} LIMIT 1";
    $upd = $conn->prepare($sqlUpd);

    while ($row = $res->fetch_assoc()) {
        $idSim = (int)($row['id_sim'] ?? 0);
        if ($idSim <= 0) continue;

        if ($invHasSuc && $invHasProp && $invHasSub) {
            $prop = $tenantPropiedad;
            $sub  = ($esSubdis ? $tenantIdSubdis : 0);
            $upd->bind_param("iisi", $idSim, $idSucursalVenta, $prop, $sub);
        } elseif ($invHasSuc && $invHasProp) {
            $prop = $tenantPropiedad;
            $upd->bind_param("iis", $idSim, $idSucursalVenta, $prop);
        } elseif ($invHasSuc && $invHasSub) {
            $sub  = ($esSubdis ? $tenantIdSubdis : 0);
            $upd->bind_param("iii", $idSim, $idSucursalVenta, $sub);
        } elseif ($invHasProp && $invHasSub) {
            $prop = $tenantPropiedad;
            $sub  = ($esSubdis ? $tenantIdSubdis : 0);
            $upd->bind_param("isi", $idSim, $prop, $sub);
        } elseif ($invHasSuc) {
            $upd->bind_param("ii", $idSim, $idSucursalVenta);
        } elseif ($invHasProp) {
            $prop = $tenantPropiedad;
            $upd->bind_param("is", $idSim, $prop);
        } elseif ($invHasSub) {
            $sub  = ($esSubdis ? $tenantIdSubdis : 0);
            $upd->bind_param("ii", $idSim, $sub);
        } else {
            $upd->bind_param("i", $idSim);
        }

        $upd->execute();
    }

    $upd->close();
    $st->close();

    // Borrar detalle
    $delDet = $conn->prepare("DELETE FROM detalle_venta_sims WHERE id_venta=?");
    $delDet->bind_param("i", $idVenta);
    $delDet->execute();
    $delDet->close();

    // Borrar venta (reforzado por tenant si existen columnas)
    if ($colVsProp || $colVsSub) {
        $delSql = "DELETE FROM ventas_sims WHERE id=? ";
        $delParams = [$idVenta];
        $delTypes  = "i";

        if ($esSubdis) {
            if ($colVsProp) $delSql .= " AND propiedad='Subdis' ";
            if ($colVsSub)  { $delSql .= " AND id_subdis=? "; $delParams[] = $tenantIdSubdis; $delTypes .= "i"; }
        } else {
            if ($colVsProp) $delSql .= " AND (propiedad='Luga' OR propiedad IS NULL OR propiedad='') ";
            if ($colVsSub)  $delSql .= " AND (id_subdis IS NULL OR id_subdis=0) ";
        }

        $delVen = $conn->prepare($delSql);
        $delVen->bind_param($delTypes, ...$delParams);
        $delVen->execute();
        $delVen->close();
    } else {
        $delVen = $conn->prepare("DELETE FROM ventas_sims WHERE id=?");
        $delVen->bind_param("i", $idVenta);
        $delVen->execute();
        $delVen->close();
    }

    $conn->commit();

    header("Location: historial_ventas_sims.php?msg=" . urlencode("✅ Venta de SIM eliminada correctamente"));
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    // error_log($e->getMessage());
    header("Location: historial_ventas_sims.php?msg=" . urlencode("❌ Ocurrió un error al eliminar la venta"));
    exit();
}
