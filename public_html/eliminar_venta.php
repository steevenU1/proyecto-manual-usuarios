<?php
// eliminar_venta.php — Elimina venta y devuelve equipos al inventario (tenant-safe + Subdis_Admin scoped)

session_start();
require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header("Location: historial_ventas.php?msg=" . urlencode("❌ Método inválido"));
  exit();
}

/* ================= Helpers ================= */
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = '{$t}'
            AND COLUMN_NAME  = '{$c}'
          LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

/** Semana actual martes-lunes */
function obtenerSemanaActual() : array {
  $hoy = new DateTime();
  $n   = (int)$hoy->format('N'); // 1=lun..7=dom
  $dif = $n - 2;                 // martes=2
  if ($dif < 0) $dif += 7;
  $inicio = (new DateTime())->modify("-$dif days")->setTime(0,0,0);
  $fin    = (clone $inicio)->modify("+6 days")->setTime(23,59,59);
  return [$inicio, $fin];
}

function normalizeProp(?string $p): string {
  $p = trim((string)$p);
  if ($p === '') return '';
  $pl = strtolower($p);

  // Luga
  if ($pl === 'luga' || $pl === 'luga ph' || $pl === 'luga_ph' || $pl === 'luga-ph') return 'Luga';

  // Subdis
  if ($pl === 'subdis' || $pl === 'subdistribuidor' || $pl === 'subdistribuidores') return 'Subdis';
  if (strpos($pl, 'subdis') !== false) return 'Subdis';

  return $p; // fallback
}

/* ================= Entrada ================= */
$idVenta    = (int)($_POST['id_venta'] ?? 0);
$csrfPost   = (string)($_POST['csrf'] ?? '');

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario = (string)($_SESSION['rol'] ?? '');
$idSucSes   = (int)($_SESSION['id_sucursal'] ?? 0);
$sesSubdis  = (int)($_SESSION['id_subdis'] ?? 0);

if ($idVenta <= 0) {
  header("Location: historial_ventas.php?msg=" . urlencode("❌ Venta inválida"));
  exit();
}

/* ================= CSRF ================= */
$token = (string)($_SESSION['del_venta_token'] ?? '');
if ($token === '' || !hash_equals($token, $csrfPost)) {
  header("Location: historial_ventas.php?msg=" . urlencode("❌ Token inválido (CSRF)"));
  exit();
}

/* ================= Permisos base ================= */
$esAdmin       = ($rolUsuario === 'Admin');
$esSubdisAdmin = in_array($rolUsuario, ['Subdis_Admin','subdis_admin'], true);

// (mantenemos compat: si algún día vuelves a permitir eliminar a ejecutivos/gerentes por semana)
$permitePropiosSemana = false;

/*
  ✅ Reglas pedidas:
  - Admin: puede eliminar cualquiera (Luga o Subdis), sujeto a que exista la venta.
  - Subdis_Admin: puede eliminar SOLO ventas de SU subdis (por id_subdis).
*/
if (!$esAdmin && !$esSubdisAdmin && !$permitePropiosSemana) {
  header("Location: historial_ventas.php?msg=" . urlencode("❌ Sin permiso"));
  exit();
}

/* =========================================================
   1) Cargar venta + sucursal (para tenant gate REAL basado en la venta)
========================================================= */
$colSucSubtipo  = hasColumn($conn, 'sucursales', 'subtipo');
$colSucIdSubdis = hasColumn($conn, 'sucursales', 'id_subdis');

$colVenProp   = hasColumn($conn, 'ventas', 'propiedad');
$colVenProp2  = hasColumn($conn, 'ventas', 'propietario');
$colVenSub    = hasColumn($conn, 'ventas', 'id_subdis');

$selectProp = "'' AS v_propiedad";
if ($colVenProp)      $selectProp = "COALESCE(v.propiedad,'') AS v_propiedad";
elseif ($colVenProp2) $selectProp = "COALESCE(v.propietario,'') AS v_propiedad";

$selectSub  = $colVenSub ? "COALESCE(v.id_subdis,0) AS v_id_subdis" : "0 AS v_id_subdis";
$selectSubt = $colSucSubtipo ? "COALESCE(s.subtipo,'') AS s_subtipo" : "'' AS s_subtipo";
$selectSid  = $colSucIdSubdis ? "COALESCE(s.id_subdis,0) AS s_id_subdis" : "0 AS s_id_subdis";

$sqlVenta = "
  SELECT v.id, v.id_usuario, v.id_sucursal, v.fecha_venta,
         {$selectProp}, {$selectSub}, {$selectSubt}, {$selectSid}
  FROM ventas v
  INNER JOIN sucursales s ON s.id = v.id_sucursal
  WHERE v.id = ?
  LIMIT 1
";
$st = $conn->prepare($sqlVenta);
$st->bind_param("i", $idVenta);
$st->execute();
$venta = $st->get_result()->fetch_assoc();
$st->close();

if (!$venta) {
  header("Location: historial_ventas.php?msg=" . urlencode("❌ Venta no encontrada"));
  exit();
}

/* =========================================================
   2) Determinar tenant de la VENTA (no del usuario)
========================================================= */
$ventaPropiedadRaw = trim((string)($venta['v_propiedad'] ?? ''));
$ventaPropiedad    = normalizeProp($ventaPropiedadRaw);
$ventaIdSubdis     = (int)($venta['v_id_subdis'] ?? 0);

$sucSubtipo        = trim((string)($venta['s_subtipo'] ?? ''));
$sucIdSubdis       = (int)($venta['s_id_subdis'] ?? 0);

// ¿La venta es Subdis?
$ventaEsSubdis = false;
$ventaSubdisId = 0;

if ($colVenProp || $colVenProp2) {
  $ventaEsSubdis = ($ventaPropiedad === 'Subdis');
} else {
  // fallback por sucursal
  $ventaEsSubdis = ($colSucSubtipo && $sucSubtipo === 'Subdistribuidor');
}

if ($colVenSub) {
  $ventaSubdisId = $ventaIdSubdis;
} else {
  $ventaSubdisId = $sucIdSubdis;
}

// Normaliza: si por alguna razón viene Subdis pero sin id, al menos toma el de sucursal
if ($ventaEsSubdis && $ventaSubdisId <= 0 && $sucIdSubdis > 0) {
  $ventaSubdisId = $sucIdSubdis;
}

/* =========================================================
   3) Permisos finos (scope)
========================================================= */
if ($esSubdisAdmin) {
  // Debe ser venta Subdis + mismo id_subdis del subdis_admin
  $miSubdis = $sesSubdis;
  if ($miSubdis <= 0) {
    // fallback: intenta obtenerlo por sucursal sesión
    if ($idSucSes > 0 && $colSucIdSubdis) {
      $stX = $conn->prepare("SELECT COALESCE(id_subdis,0) AS id_subdis FROM sucursales WHERE id=? LIMIT 1");
      $stX->bind_param("i", $idSucSes);
      $stX->execute();
      $miSubdis = (int)($stX->get_result()->fetch_assoc()['id_subdis'] ?? 0);
      $stX->close();
    }
  }

  if (!$ventaEsSubdis || $miSubdis <= 0 || $ventaSubdisId !== (int)$miSubdis) {
    header("Location: historial_ventas.php?msg=" . urlencode("❌ No autorizado (Subdis scope)"));
    exit();
  }
}

if ($permitePropiosSemana && !$esAdmin && !$esSubdisAdmin) {
  // si activas esto en el futuro:
  list($ini, $fin) = obtenerSemanaActual();
  $fechaVenta = new DateTime($venta['fecha_venta']);
  $enSemana   = ($fechaVenta >= $ini && $fechaVenta <= $fin);

  if ((int)$venta['id_usuario'] !== $idUsuario || !$enSemana) {
    header("Location: historial_ventas.php?msg=" . urlencode("❌ Solo puedes eliminar tus ventas de esta semana"));
    exit();
  }
}

/* =========================================================
   4) Operación: devolver equipos y borrar venta + detalle
========================================================= */
$idSucursalVenta = (int)($venta['id_sucursal'] ?? 0);

// inventario columns
$invHasSuc   = hasColumn($conn, 'inventario', 'id_sucursal');
$invHasProp  = hasColumn($conn, 'inventario', 'propiedad');
$invHasProp2 = hasColumn($conn, 'inventario', 'propietario');
$invHasSub   = hasColumn($conn, 'inventario', 'id_subdis');

// elegimos columna de propiedad en inventario si existe
$invColProp = null;
if ($invHasProp)      $invColProp = 'propiedad';
elseif ($invHasProp2) $invColProp = 'propietario';

$conn->begin_transaction();

try {
  // Traer detalle con IMEI (lo más seguro para regresar inventario)
  $sqlDet = "SELECT dv.id_producto, dv.imei1
             FROM detalle_venta dv
             WHERE dv.id_venta=?";
  $st = $conn->prepare($sqlDet);
  $st->bind_param("i", $idVenta);
  $st->execute();
  $res = $st->get_result();

  // Preparar UPDATE inventario por IMEI (JOIN productos)
  // Nota: regresamos si está 'Vendido', amarramos a sucursal si existe,
  // y aplicamos tenant gate según la VENTA (no sesión).
  $whereUpd = " WHERE i.estatus='Vendido' AND p.imei1 = ? ";
  $typesUpd = "s";
  $extraParams = [];

  if ($invHasSuc) {
    $whereUpd .= " AND i.id_sucursal = ? ";
    $typesUpd .= "i";
  }

  // gate por propiedad en inventario (si existe)
  if ($invColProp) {
    if ($ventaEsSubdis) {
      $whereUpd .= " AND (i.`$invColProp` IN ('Subdis','SUBDISTRIBUIDOR','Subdistribuidor') OR i.`$invColProp` IS NULL OR i.`$invColProp`='') ";
    } else {
      $whereUpd .= " AND (i.`$invColProp` IN ('Luga','LUGA') OR i.`$invColProp` IS NULL OR i.`$invColProp`='') ";
    }
  }

  // gate por id_subdis en inventario (si existe)
  if ($invHasSub) {
    if ($ventaEsSubdis) {
      $whereUpd .= " AND (i.id_subdis = ?) ";
      $typesUpd .= "i";
    } else {
      $whereUpd .= " AND (i.id_subdis IS NULL OR i.id_subdis=0) ";
    }
  }

  $sqlUpd = "UPDATE inventario i
             INNER JOIN productos p ON p.id = i.id_producto
             SET i.estatus='Disponible'
             {$whereUpd}
             LIMIT 1";
  $upd = $conn->prepare($sqlUpd);

  while ($row = $res->fetch_assoc()) {
    $imei = trim((string)($row['imei1'] ?? ''));
    if ($imei === '') continue;

    // Bind dinámico: imei + (sucursal?) + (id_subdis?)
    if ($invHasSuc && $invHasSub && $ventaEsSubdis) {
      $sub = (int)$ventaSubdisId;
      $upd->bind_param($typesUpd, $imei, $idSucursalVenta, $sub);
    } elseif ($invHasSuc && $invHasSub && !$ventaEsSubdis) {
      $upd->bind_param($typesUpd, $imei, $idSucursalVenta);
    } elseif ($invHasSuc && !$invHasSub) {
      $upd->bind_param($typesUpd, $imei, $idSucursalVenta);
    } elseif (!$invHasSuc && $invHasSub && $ventaEsSubdis) {
      $sub = (int)$ventaSubdisId;
      $upd->bind_param($typesUpd, $imei, $sub);
    } else {
      $upd->bind_param($typesUpd, $imei);
    }

    $upd->execute();
  }

  $upd->close();
  $st->close();

  // Borrar detalle
  $delDet = $conn->prepare("DELETE FROM detalle_venta WHERE id_venta=?");
  $delDet->bind_param("i", $idVenta);
  $delDet->execute();
  $delDet->close();

  // Borrar venta (con guardas por propiedad/id_subdis si existen)
  $delSql = "DELETE FROM ventas WHERE id=? ";
  $delParams = [$idVenta];
  $delTypes  = "i";

  if ($colVenProp || $colVenProp2) {
    $col = $colVenProp ? 'propiedad' : 'propietario';
    if ($ventaEsSubdis) {
      $delSql .= " AND ($col='Subdis' OR $col='SUBDISTRIBUIDOR' OR $col='Subdistribuidor') ";
    } else {
      $delSql .= " AND ($col='Luga' OR $col='LUGA' OR $col IS NULL OR $col='') ";
    }
  }

  if ($colVenSub) {
    if ($ventaEsSubdis) {
      $delSql .= " AND id_subdis=? ";
      $delParams[] = (int)$ventaSubdisId;
      $delTypes .= "i";
    } else {
      $delSql .= " AND (id_subdis IS NULL OR id_subdis=0) ";
    }
  }

  $delVen = $conn->prepare($delSql);
  $delVen->bind_param($delTypes, ...$delParams);
  $delVen->execute();
  $delVen->close();

  $conn->commit();

  header("Location: historial_ventas.php?msg=" . urlencode("✅ Venta eliminada correctamente"));
  exit();

} catch (Throwable $e) {
  $conn->rollback();
  // Debug rápido (si lo necesitas): agrega el mensaje real
  // header("Location: historial_ventas.php?msg=" . urlencode("❌ Error: ".$e->getMessage())); exit();
  header("Location: historial_ventas.php?msg=" . urlencode("❌ Ocurrió un error al eliminar la venta"));
  exit();
}