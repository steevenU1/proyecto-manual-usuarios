<?php
// historial_ventas.php — Historial de ventas (Tenant-safe + Admin Luga puede incluir Subdis)

session_start();

/* ======================
   Auth primero
====================== */
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

/* =========================================================
   Helpers de compatibilidad de esquema
========================================================= */
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $tableEsc  = $conn->real_escape_string($table);
  $columnEsc = $conn->real_escape_string($column);
  $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '{$tableEsc}'
          AND COLUMN_NAME  = '{$columnEsc}'
        LIMIT 1
    ";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

function obtenerSemanaPorIndice($offset = 0) {
  $hoy = new DateTime();
  $diaSemana = $hoy->format('N'); // 1=lunes ... 7=domingo
  $dif = $diaSemana - 2;          // martes=2
  if ($dif < 0) $dif += 7;

  $inicio = new DateTime();
  $inicio->modify("-$dif days")->setTime(0,0,0);

  if ($offset > 0) {
    $inicio->modify("-" . (7*$offset) . " days");
  }

  $fin = clone $inicio;
  $fin->modify("+6 days")->setTime(23,59,59);

  return [$inicio, $fin];
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* =========================================================
   Semana seleccionada (para filtros/listado)
========================================================= */
$semanaSeleccionada = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioSemanaObj->format('Y-m-d');
$finSemana    = $finSemanaObj->format('Y-m-d');

// Rango de la semana ACTUAL (para permitir edición)
list($inicioActualObj, $finActualObj) = obtenerSemanaPorIndice(0);

$msg                 = $_GET['msg'] ?? '';
$id_sucursal_sesion  = (int)($_SESSION['id_sucursal'] ?? 0);
$ROL                 = $_SESSION['rol'] ?? '';
$idUsuarioSesion     = (int)($_SESSION['id_usuario'] ?? 0);

date_default_timezone_set('America/Mexico_City');
if (method_exists($conn, 'set_charset')) { @$conn->set_charset('utf8mb4'); }
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

/* =========================================================
   ✅ CSRF delete venta (para eliminar_venta.php)
========================================================= */
if (empty($_SESSION['del_venta_token'])) {
  $_SESSION['del_venta_token'] = bin2hex(random_bytes(16));
}

/* =========================================================
   TENANT (Luga / Subdis + id_subdis)
   - Se detecta por la sucursal de sesión (NO por filtro)
========================================================= */
$colVentasPropiedad = null;
if (hasColumn($conn, 'ventas', 'propietario')) {
  $colVentasPropiedad = 'propietario';
} elseif (hasColumn($conn, 'ventas', 'propiedad')) {
  $colVentasPropiedad = 'propiedad';
}
$colVentasIdSubdis  = hasColumn($conn, 'ventas', 'id_subdis');

$colSucSubtipo   = hasColumn($conn, 'sucursales', 'subtipo');
$colSucIdSubdis  = hasColumn($conn, 'sucursales', 'id_subdis');

$tenantPropiedad = 'Luga'; // default
$tenantIdSubdis  = 0;      // solo aplica a Subdis
$subtipoSesion   = '';
$nombreSucursalSesion = '';

if ($id_sucursal_sesion > 0) {
  if ($colSucSubtipo && $colSucIdSubdis) {
    $stTen = $conn->prepare("SELECT nombre, subtipo, id_subdis FROM sucursales WHERE id=? LIMIT 1");
    $stTen->bind_param("i", $id_sucursal_sesion);
  } elseif ($colSucSubtipo) {
    $stTen = $conn->prepare("SELECT nombre, subtipo, 0 AS id_subdis FROM sucursales WHERE id=? LIMIT 1");
    $stTen->bind_param("i", $id_sucursal_sesion);
  } else {
    $stTen = $conn->prepare("SELECT nombre, '' AS subtipo, 0 AS id_subdis FROM sucursales WHERE id=? LIMIT 1");
    $stTen->bind_param("i", $id_sucursal_sesion);
  }
  $stTen->execute();
  $rowTen = $stTen->get_result()->fetch_assoc();
  $stTen->close();

  $nombreSucursalSesion = $rowTen['nombre'] ?? '';
  $subtipoSesion        = $rowTen['subtipo'] ?? '';
  $tenantIdSubdis       = (int)($rowTen['id_subdis'] ?? 0);

  if (trim((string)$subtipoSesion) === 'Subdistribuidor') {
    $tenantPropiedad = 'Subdis';
  }
}

// Fallback por rol
$rolLower = strtolower((string)$ROL);
if ($tenantPropiedad === 'Luga' && (strpos($rolLower, 'subdis') !== false)) {
  $tenantPropiedad = 'Subdis';
  $tenantIdSubdis  = (int)($_SESSION['id_subdis'] ?? $tenantIdSubdis);
}

// Bandera UI (oculta comisiones si es Subdis)
$esSubdistribuidor = ($tenantPropiedad === 'Subdis');

/* =========================================================
   ✅ Admin Luga puede incluir ventas Subdis (toggle)
========================================================= */
$adminLugaPuedeVerSubdis = (in_array($ROL, ['Admin','Logistica'], true) && $tenantPropiedad === 'Luga');
$incluyeSubdis = $adminLugaPuedeVerSubdis ? (int)($_GET['incl_subdis'] ?? 1) : 0; // default ON

/* =========================================================
   Sucursal seleccionada
   - SOLO Admin ve y puede elegir; default: TODAS (0)
========================================================= */
$puedeElegirSucursal   = in_array($ROL, ['Admin','Logistica'], true);
$sucursalSeleccionada  = $puedeElegirSucursal ? (int)($_GET['sucursal'] ?? 0) : 0; // Admin default 0 (Todas)
$sucursalFiltro        = $puedeElegirSucursal ? $sucursalSeleccionada : $id_sucursal_sesion;

// Header de sucursal filtrada (solo para mostrar)
$subtipoSucursal = '';
$nombreSucursalFiltro = '';
if ($sucursalFiltro) {
  if ($colSucSubtipo) {
    $stmtSub = $conn->prepare("SELECT nombre, subtipo FROM sucursales WHERE id = ? LIMIT 1");
    $stmtSub->bind_param("i", $sucursalFiltro);
  } else {
    $stmtSub = $conn->prepare("SELECT nombre, '' AS subtipo FROM sucursales WHERE id = ? LIMIT 1");
    $stmtSub->bind_param("i", $sucursalFiltro);
  }
  $stmtSub->execute();
  $rowSub = $stmtSub->get_result()->fetch_assoc();
  $nombreSucursalFiltro = $rowSub['nombre'] ?? '';
  $subtipoSucursal      = $rowSub['subtipo'] ?? '';
  $stmtSub->close();
}

// Catálogo de sucursales para combo (solo Admin)
$listaSucursales = [];
if ($puedeElegirSucursal) {
  if ($tenantPropiedad === 'Subdis') {
    // Solo sucursales subdis de ESTE id_subdis
    if ($colSucSubtipo && $colSucIdSubdis) {
      $stS = $conn->prepare("SELECT id, nombre FROM sucursales WHERE subtipo='Subdistribuidor' AND id_subdis=? ORDER BY nombre");
      $stS->bind_param("i", $tenantIdSubdis);
      $stS->execute();
      $rsSuc = $stS->get_result();
      while ($r = $rsSuc->fetch_assoc()) $listaSucursales[] = $r;
      $stS->close();
    } elseif ($colSucSubtipo) {
      $rsSuc = $conn->query("SELECT id, nombre FROM sucursales WHERE subtipo='Subdistribuidor' ORDER BY nombre");
      while ($r = $rsSuc->fetch_assoc()) $listaSucursales[] = $r;
    } else {
      $listaSucursales = [];
    }
  } else {
    // Tenant Luga
    if ($adminLugaPuedeVerSubdis && $incluyeSubdis === 1) {
      // ✅ Admin Luga incluyendo Subdis: TODAS sucursales
      $rsSuc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
    } else {
      // Luga normal: excluir Subdistribuidor si existe subtipo
      if ($colSucSubtipo) {
        $rsSuc = $conn->query("SELECT id, nombre FROM sucursales WHERE (subtipo IS NULL OR subtipo='' OR subtipo<>'Subdistribuidor') ORDER BY nombre");
      } else {
        $rsSuc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
      }
    }
    while ($r = $rsSuc->fetch_assoc()) $listaSucursales[] = $r;
  }
}

/* =========================================================
   Detección del nombre de columna de tipo de producto
========================================================= */
$colTipoProd = hasColumn($conn, 'productos', 'tipo') ? 'tipo' : 'tipo_producto';

/* =========================================================
   ✅ FIX: detectar módem por tipo/descripcion/codigo
========================================================= */
$colDescProd = hasColumn($conn, 'productos', 'descripcion') ? 'descripcion' : null;
$colCodProd  = hasColumn($conn, 'productos', 'codigo_producto') ? 'codigo_producto' : null;

$exprTipo = "TRIM(COALESCE(p.$colTipoProd,'')) COLLATE utf8mb4_general_ci";
$exprDesc = $colDescProd ? "TRIM(COALESCE(p.$colDescProd,'')) COLLATE utf8mb4_general_ci" : "''";
$exprCod  = $colCodProd  ? "TRIM(COALESCE(p.$colCodProd,''))" : "''";

$condEsModem = "(
  $exprTipo LIKE '%modem%' OR $exprTipo LIKE '%mifi%'
  OR $exprDesc LIKE '%modem%' OR $exprDesc LIKE '%mifi%'
  OR $exprCod LIKE 'MOD-%'
)";

/* =========================================================
   Usuarios para filtro
========================================================= */
$activosExpr = '';
if (hasColumn($conn, 'usuarios', 'activo')) {
  $activosExpr = "u.activo = 1";
} elseif (hasColumn($conn, 'usuarios', 'estatus')) {
  $activosExpr = "LOWER(u.estatus) IN ('activo','activa','alta')";
} elseif (hasColumn($conn, 'usuarios', 'fecha_baja')) {
  $activosExpr = "(u.fecha_baja IS NULL OR u.fecha_baja='0000-00-00')";
}

$existsVentas = "EXISTS (
    SELECT 1
    FROM ventas v
    WHERE v.id_usuario = u.id
      AND DATE(v.fecha_venta) BETWEEN ? AND ?
)";

$esInactivoCase = $activosExpr
  ? "CASE WHEN {$activosExpr} THEN 0 ELSE 1 END"
  : "CASE WHEN {$existsVentas} THEN 1 ELSE 0 END";

$joinSucUsuarios = " INNER JOIN sucursales su ON su.id = u.id_sucursal ";
$tenantWhereUsuarios = " 1=1 ";

if ($tenantPropiedad === 'Subdis') {
  if ($colSucSubtipo) $tenantWhereUsuarios .= " AND su.subtipo='Subdistribuidor' ";
  if ($colSucIdSubdis) $tenantWhereUsuarios .= " AND su.id_subdis=".(int)$tenantIdSubdis." ";
} else {
  if (!($adminLugaPuedeVerSubdis && $incluyeSubdis === 1)) {
    if ($colSucSubtipo) $tenantWhereUsuarios .= " AND (su.subtipo IS NULL OR su.subtipo='' OR su.subtipo<>'Subdistribuidor') ";
  }
}

if ($puedeElegirSucursal && $sucursalFiltro === 0) {
  $sqlUsuarios = "
        SELECT u.id, u.nombre,
               {$esInactivoCase} AS es_inactivo
        FROM usuarios u
        {$joinSucUsuarios}
        WHERE {$tenantWhereUsuarios}
          AND (
            " . ($activosExpr ? "({$activosExpr}) OR " : "") . "
            {$existsVentas}
          )
        ORDER BY es_inactivo ASC, u.nombre ASC
    ";
  $stmtUsuarios = $conn->prepare($sqlUsuarios);
  $stmtUsuarios->bind_param("ss", $inicioSemana, $finSemana);
} else {
  $sqlUsuarios = "
        SELECT u.id, u.nombre,
               {$esInactivoCase} AS es_inactivo
        FROM usuarios u
        {$joinSucUsuarios}
        WHERE {$tenantWhereUsuarios}
          AND u.id_sucursal = ?
          AND (
                " . ($activosExpr ? "{$activosExpr} OR " : "") . "
                {$existsVentas}
              )
        ORDER BY es_inactivo ASC, u.nombre ASC
    ";
  $stmtUsuarios = $conn->prepare($sqlUsuarios);
  $stmtUsuarios->bind_param("iss", $sucursalFiltro, $inicioSemana, $finSemana);
}
$stmtUsuarios->execute();
$resUsuarios = $stmtUsuarios->get_result();

$usuariosActivos = [];
$usuariosInactivos = [];
while ($row = $resUsuarios->fetch_assoc()) {
  if ((int)$row['es_inactivo'] === 1) $usuariosInactivos[] = $row;
  else $usuariosActivos[] = $row;
}
$stmtUsuarios->close();

/* =========================================================
   WHERE base para consultas
========================================================= */
$where  = " WHERE DATE(v.fecha_venta) BETWEEN ? AND ?";
$params = [$inicioSemana, $finSemana];
$types  = "ss";

// Tenant gate (ventas.propiedad / ventas.id_subdis)
if ($tenantPropiedad === 'Subdis') {
  if ($colVentasPropiedad) {
    $where .= " AND UPPER(TRIM(v.`{$colVentasPropiedad}`)) IN ('SUBDISTRIBUIDOR','SUBDIS')";
  }
  if ($colVentasIdSubdis) {
    $where .= " AND v.id_subdis=?";
    $params[] = $tenantIdSubdis;
    $types .= "i";
  }
} else {
  if (!($adminLugaPuedeVerSubdis && $incluyeSubdis === 1)) {
    if ($colVentasPropiedad) {
      $where .= " AND (
        v.`{$colVentasPropiedad}` IS NULL
        OR TRIM(v.`{$colVentasPropiedad}`) = ''
        OR UPPER(TRIM(v.`{$colVentasPropiedad}`)) = 'LUGA'
      )";
    }
    if ($colVentasIdSubdis) {
      $where .= " AND (v.id_subdis IS NULL OR v.id_subdis=0)";
    }
  }
}

// Filtro por rol + sucursal
if (in_array($ROL, ['Ejecutivo','subdis_ejecutivo','Subdis_Ejecutivo','SubdisEjecutivo'], true)) {
  $where .= " AND v.id_usuario=?";
  $params[] = $idUsuarioSesion;
  $types .= "i";
} elseif ($ROL === 'Gerente') {
  $where .= " AND v.id_sucursal=?";
  $params[] = $id_sucursal_sesion;
  $types .= "i";
} else {
  if ($puedeElegirSucursal && $sucursalFiltro > 0) {
    $where .= " AND v.id_sucursal=?";
    $params[] = $sucursalFiltro;
    $types .= "i";
  }
}

// Filtros GET
if (!empty($_GET['tipo_venta'])) {
  $where .= " AND v.tipo_venta=?";
  $params[] = $_GET['tipo_venta'];
  $types .= "s";
}
if (!empty($_GET['usuario'])) {
  $where .= " AND v.id_usuario=?";
  $params[] = $_GET['usuario'];
  $types .= "i";
}
if (!empty($_GET['buscar'])) {
  $where .= " AND (v.nombre_cliente LIKE ? OR v.telefono_cliente LIKE ? OR v.tag LIKE ?
                     OR EXISTS(SELECT 1 FROM detalle_venta dv WHERE dv.id_venta=v.id AND dv.imei1 LIKE ?))";
  $busqueda = "%".$_GET['buscar']."%";
  array_push($params, $busqueda, $busqueda, $busqueda, $busqueda);
  $types .= "ssss";
}

/* =========================================================
   MÉTRICAS PARA CARDS
========================================================= */

// Unidades sin módem y módems
$sqlUnits = "
    SELECT
      SUM(CASE WHEN $condEsModem THEN 0 ELSE 1 END) AS unidades_sin_modem,
      SUM(CASE WHEN $condEsModem THEN 1 ELSE 0 END) AS unidades_modem
    FROM detalle_venta dv
    INNER JOIN ventas v ON dv.id_venta = v.id
    INNER JOIN productos p ON p.id = dv.id_producto
    $where
";
$stU = $conn->prepare($sqlUnits);
$stU->bind_param($types, ...$params);
$stU->execute();
$rowU = $stU->get_result()->fetch_assoc();
$totalUnidades       = (int)($rowU['unidades_sin_modem'] ?? 0);
$totalModemsUnidades = (int)($rowU['unidades_modem'] ?? 0);
$stU->close();

// Combos
$sqlCombos = "
  SELECT SUM(CASE WHEN LOWER(v.tipo_venta)='financiamiento+combo' THEN 1 ELSE 0 END) AS combos_u
  FROM ventas v
  $where
";
$stC = $conn->prepare($sqlCombos);
$stC->bind_param($types, ...$params);
$stC->execute();
$totalCombosUnidades = (int)($stC->get_result()->fetch_assoc()['combos_u'] ?? 0);
$stC->close();

// Monto vendido excluyendo ventas solo módem + ticket promedio
$sqlMonto = "
  SELECT
    IFNULL(SUM(CASE WHEN d.has_non_modem=1 THEN v.precio_venta ELSE 0 END),0) AS total_monto,
    SUM(CASE WHEN d.has_non_modem=1 THEN 1 ELSE 0 END) AS ventas_con_monto
  FROM ventas v
  LEFT JOIN (
    SELECT dv.id_venta,
           MAX(CASE WHEN ($condEsModem) THEN 0 ELSE 1 END) AS has_non_modem
    FROM detalle_venta dv
    INNER JOIN productos p ON dv.id_producto = p.id
    GROUP BY dv.id_venta
  ) d ON d.id_venta = v.id
  $where
";
$stM = $conn->prepare($sqlMonto);
$stM->bind_param($types, ...$params);
$stM->execute();
$rowM = $stM->get_result()->fetch_assoc();
$totalMonto     = (float)($rowM['total_monto'] ?? 0);
$ventasConMonto = (int)($rowM['ventas_con_monto'] ?? 0);
$ticketPromedio = ($ventasConMonto > 0) ? ($totalMonto / $ventasConMonto) : 0.0;
$stM->close();

// Comisiones (solo si NO es subdis)
$totalComisiones = 0.0;
if (!$esSubdistribuidor) {
  $sqlResumen = "
      SELECT IFNULL(SUM(dv.comision_regular + dv.comision_especial),0) AS total_comisiones
      FROM detalle_venta dv
      INNER JOIN ventas v ON dv.id_venta = v.id
      $where
  ";
  $stmtResumen = $conn->prepare($sqlResumen);
  $stmtResumen->bind_param($types, ...$params);
  $stmtResumen->execute();
  $resumen = $stmtResumen->get_result()->fetch_assoc();
  $totalComisiones = (float)($resumen['total_comisiones'] ?? 0);
  $stmtResumen->close();
}

/* =========================================================
   Datos del listado
========================================================= */
$sqlVentas = "
    SELECT v.id, v.tag, v.nombre_cliente, v.telefono_cliente, v.tipo_venta,
           v.precio_venta, v.fecha_venta,
           v.enganche, v.forma_pago_enganche, v.enganche_efectivo, v.enganche_tarjeta,
           v.comentarios,
           u.id AS id_usuario, u.nombre AS usuario
    FROM ventas v
    INNER JOIN usuarios u ON v.id_usuario = u.id
    $where
    ORDER BY v.fecha_venta DESC
";
$stmt = $conn->prepare($sqlVentas);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$ventas = $stmt->get_result();
$totalVentas = $ventas->num_rows;

// Detalle por venta
$sqlDetalle = "
    SELECT dv.id_venta, p.marca, p.modelo, p.color, dv.imei1,
           dv.comision_regular, dv.comision_especial, dv.comision,
           p.precio_lista
    FROM detalle_venta dv
    INNER JOIN productos p ON dv.id_producto = p.id
    ORDER BY dv.id_venta, dv.id ASC
";
$detalleResult = $conn->query($sqlDetalle);
$detalles = [];
while ($row = $detalleResult->fetch_assoc()) {
  $detalles[$row['id_venta']][] = $row;
}

// Meses en español
$MESES_ES = [
  1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
  5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
  9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
];
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Historial de Ventas</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="stylesheet" href="ui_luga.css">
  <style>
    :root { --surface:#ffffff; --muted:#6b7280; }
    body{ background:#f6f7fb; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; }
    .page-title{ font-weight:700; letter-spacing:.2px; margin:0; }
    .small-muted{ color: var(--muted); font-size:.92rem; }
    .card-surface{ background:var(--surface); border:1px solid rgba(0,0,0,.05); box-shadow:0 6px 16px rgba(16,24,40,.06); border-radius:18px; }

    /* ✅ Cards más compactas */
    .stat{ display:flex; align-items:center; gap:.65rem; }
    .stat .icon{ width:34px; height:34px; border-radius:10px; display:grid; place-items:center; background:#eef2ff; font-size:1rem; }
    .stat .label{ color: var(--muted); font-size:.85rem; line-height:1.1; }
    .stat .value{ font-weight:800; font-size:1.35rem; line-height:1.05; }

    .chip{ display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .6rem; border-radius:999px; font-weight:600; font-size:.85rem; border:1px solid transparent; }
    .chip-info{ background:#e8f0fe; color:#1a56db; border-color:#cbd8ff; }
    .chip-success{ background:#e7f8ef; color:#0f7a3d; border-color:#b7f1cf; }
    .chip-warn{ background:#fff6e6; color:#9a6200; border-color:#ffe1a8; }

    .btn-soft{ border:1px solid rgba(0,0,0,.08); background:#fff; }
    .btn-soft:hover{ background:#f9fafb; }
    .filters .form-control, .filters .form-select { height:42px; }
    .accordion-button{ gap:.5rem; }
    .venta-head .tag{ font-weight:700; }
    .comentarios-box{ background:#fffdf6; border:1px dashed #ffdca8; border-radius:12px; padding:.6rem .8rem; color:#7a591f; }
  
    .ui-luga body, body.ui-luga{ background:#f6f8fc; }
    .hero-luga-hv{
      background:
        radial-gradient(720px 240px at 0% 0%, rgba(13,110,253,.11), transparent 60%),
        radial-gradient(640px 220px at 100% 0%, rgba(25,135,84,.08), transparent 60%),
        linear-gradient(135deg, #ffffff, #f8fbff);
      border:1px solid rgba(13,110,253,.08);
      border-radius:1.35rem;
      box-shadow:0 16px 38px rgba(15,23,42,.07), 0 2px 8px rgba(15,23,42,.04);
    }
    .hero-pill{
      display:inline-flex; align-items:center; gap:.55rem; padding:.58rem 1rem;
      border-radius:999px; background:rgba(255,255,255,.94);
      box-shadow:0 8px 20px rgba(15,23,42,.05),0 2px 6px rgba(15,23,42,.04);
      font-weight:800; color:#0f172a;
    }
    .page-title{ font-weight:900; letter-spacing:-.02em; }
    .small-muted{ color:#64748b; font-size:.92rem; }
    .card-surface{
      background:#fff;
      border:1px solid rgba(148,163,184,.14);
      box-shadow:0 10px 24px rgba(2,8,20,.06),0 2px 6px rgba(2,8,20,.05);
      border-radius:1.15rem;
    }
    .stat{ display:flex; align-items:center; gap:.75rem; }
    .stat .icon{
      width:42px; height:42px; border-radius:12px; display:grid; place-items:center;
      background:linear-gradient(180deg, rgba(239,246,255,1), rgba(248,251,255,1));
      color:#0d6efd; border:1px solid rgba(13,110,253,.12);
      font-size:1.08rem;
    }
    .stat .label{
      color:#64748b; font-size:.78rem; line-height:1.1;
      text-transform:uppercase; letter-spacing:.45px; font-weight:800;
    }
    .stat .value{ font-weight:900; font-size:1.22rem; line-height:1.05; color:#0f172a; }
    .chip{
      display:inline-flex; align-items:center; gap:.42rem; padding:.38rem .72rem;
      border-radius:999px; font-weight:700; font-size:.84rem; border:1px solid transparent;
      box-shadow:0 6px 14px rgba(15,23,42,.04);
    }
    .chip-info{ background:#e8f0fe; color:#1a56db; border-color:#cbd8ff; }
    .chip-success{ background:#e7f8ef; color:#0f7a3d; border-color:#b7f1cf; }
    .chip-warn{ background:#fff6e6; color:#9a6200; border-color:#ffe1a8; }

    .filters .form-control, .filters .form-select,
    .modal .form-control, .modal .form-select{
      min-height:46px;
      border-radius:.9rem;
      border-color:rgba(148,163,184,.24);
      box-shadow:none;
    }
    .filters .form-control:focus, .filters .form-select:focus,
    .modal .form-control:focus, .modal .form-select:focus{
      border-color:rgba(13,110,253,.38);
      box-shadow:0 0 0 .25rem rgba(13,110,253,.12);
    }

    .btn-soft{
      border:1px solid rgba(13,110,253,.16);
      background:linear-gradient(180deg, rgba(239,246,255,1), rgba(248,251,255,1));
      color:#0d6efd;
      border-radius:.9rem;
      font-weight:800;
      box-shadow:0 8px 18px rgba(13,110,253,.08);
    }
    .btn-soft:hover{ background:linear-gradient(180deg, rgba(224,242,254,1), rgba(239,246,255,1)); color:#0a58ca; }

    .btn-luga{
      border-radius:.95rem !important;
      min-height:46px;
      font-weight:800;
      padding:.7rem 1.05rem;
      transition:all .18s ease;
    }
    .btn-luga:hover{ transform:translateY(-1px); }
    .btn-luga-primary{
      background:linear-gradient(180deg, rgba(239,246,255,1), rgba(248,251,255,1));
      border:1px solid rgba(13,110,253,.24);
      color:#0d6efd;
      box-shadow:0 8px 18px rgba(13,110,253,.08);
    }
    .btn-luga-primary:hover{ color:#0a58ca; background:linear-gradient(180deg, rgba(224,242,254,1), rgba(239,246,255,1)); }
    .btn-luga-success{
      background:linear-gradient(90deg,#16a34a,#22c55e);
      border:0; color:#fff;
      box-shadow:0 14px 28px rgba(34,197,94,.22), inset 0 1px 0 rgba(255,255,255,.18);
    }
    .btn-luga-success:hover{ color:#fff; filter:brightness(.98); }
    .btn-luga-ghost{
      background:#fff; border:1px solid rgba(148,163,184,.24); color:#334155;
      box-shadow:0 6px 16px rgba(15,23,42,.05);
    }
    .btn-luga-ghost:hover{ background:#f8fafc; color:#0f172a; }
    .btn-luga-danger{
      background:linear-gradient(180deg, #fff5f5, #fff);
      border:1px solid rgba(220,53,69,.20); color:#b42318;
      box-shadow:0 8px 18px rgba(220,53,69,.08);
    }
    .btn-luga-danger:hover{ color:#912018; background:#fff1f2; }

    .accordion-item.card-surface{ overflow:hidden; }
    .accordion-button{
      gap:.5rem;
      background:linear-gradient(180deg, rgba(248,250,252,.95), rgba(255,255,255,1));
      font-weight:700;
    }
    .accordion-button:not(.collapsed){
      background:linear-gradient(180deg, rgba(239,246,255,.95), rgba(255,255,255,1));
      color:#0f172a;
      box-shadow:none;
    }
    .accordion-button:focus{ box-shadow:none; border-color:transparent; }
    .venta-head .tag{ font-weight:800; color:#0f172a; }
    .tbl-wrap{
      border:1px solid rgba(148,163,184,.14);
      border-radius:1rem;
      overflow:hidden;
      background:#fff;
      box-shadow:0 8px 18px rgba(15,23,42,.04);
    }
    .tbl-wrap .table{ margin-bottom:0; }
    .tbl-wrap thead th{
      background:#0f172a !important;
      color:#fff !important;
      font-weight:800;
      border-bottom:0;
      white-space:nowrap;
    }
    .tbl-wrap tbody tr:hover{ background:rgba(13,110,253,.03); }
    .comentarios-box{
      background:linear-gradient(180deg, #fffdf6, #fffaf0);
      border:1px dashed #ffdca8;
      border-radius:1rem;
      padding:.75rem .9rem;
      color:#7a591f;
      box-shadow:0 6px 16px rgba(255,193,7,.06);
    }
    .modal-content{
      border:0;
      border-radius:1.35rem;
      box-shadow:0 28px 60px rgba(15,23,42,.14), 0 8px 18px rgba(15,23,42,.08);
      overflow:hidden;
    }
    .modal-header{
      border-bottom:1px solid rgba(148,163,184,.12);
      background:
        radial-gradient(500px 180px at 0% 0%, rgba(13,110,253,.08), transparent 60%),
        linear-gradient(180deg, rgba(248,250,252,.95), rgba(255,255,255,1));
    }
    .modal-title{ font-weight:900; letter-spacing:-.01em; color:#0f172a; }
    .modal-footer{
      border-top:1px solid rgba(148,163,184,.12);
      background:linear-gradient(180deg, rgba(248,250,252,.92), rgba(255,255,255,1));
    }

  </style>
</head>
<body class="ui-luga">
<div class="container py-3">

  <!-- Encabezado -->
  <div class="page-header">
    <div>
      <h1 class="page-title">🧾 Historial de Ventas</h1>
      <div class="small-muted">
        Usuario: <strong><?= h($_SESSION['nombre'] ?? '') ?></strong>
        · Tenant: <strong><?= h($tenantPropiedad) ?><?= $tenantPropiedad==='Subdis' ? ' #'.(int)$tenantIdSubdis : '' ?></strong>
        <?php if ($puedeElegirSucursal): ?>
          · Sucursal: <strong><?= $sucursalFiltro ? h($nombreSucursalFiltro) : 'Todas' ?></strong>
        <?php endif; ?>
        <?php if ($adminLugaPuedeVerSubdis): ?>
          · Alcance: <strong><?= $incluyeSubdis===1 ? 'Luga + Subdis' : 'Solo Luga' ?></strong>
        <?php endif; ?>
        · Semana: <strong><?= $inicioSemanaObj->format('d/m/Y') ?> – <?= $finSemanaObj->format('d/m/Y') ?></strong>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
      <span class="chip chip-info"><i class="bi bi-receipt"></i> Ventas: <?= (int)$totalVentas ?></span>
      <span class="chip chip-success"><i class="bi bi-bag-check"></i> Unidades: <?= (int)$totalUnidades ?></span>
      <span class="chip chip-success"><i class="bi bi-currency-dollar"></i> Monto: $<?= number_format($totalMonto,2) ?></span>
      <span class="chip chip-info"><i class="bi bi-graph-up"></i> Ticket prom.: $<?= number_format($ticketPromedio,2) ?></span>
      <?php if (!$esSubdistribuidor): ?>
        <span class="chip chip-warn"><i class="bi bi-coin"></i> Comisiones: $<?= number_format($totalComisiones,2) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-success card-surface mt-3"><?= h($msg) ?></div>
  <?php endif; ?>

  <!-- Tarjetas resumen (✅ 5 en una fila en desktop) -->
  <div class="row g-3 mt-2 row-cols-1 row-cols-md-5">
    <div class="col">
      <div class="card card-surface p-2 h-100">
        <div class="stat">
          <div class="icon"><i class="bi bi-bag-check"></i></div>
          <div>
            <div class="label">Unidades vendidas</div>
            <div class="value"><?= (int)$totalUnidades ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="col">
      <div class="card card-surface p-2 h-100">
        <div class="stat">
          <div class="icon"><i class="bi bi-router"></i></div>
          <div>
            <div class="label">Módems</div>
            <div class="value"><?= (int)$totalModemsUnidades ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="col">
      <div class="card card-surface p-2 h-100">
        <div class="stat">
          <div class="icon"><i class="bi bi-box-seam"></i></div>
          <div>
            <div class="label">Combos</div>
            <div class="value"><?= (int)$totalCombosUnidades ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="col">
      <div class="card card-surface p-2 h-100">
        <div class="stat">
          <div class="icon"><i class="bi bi-currency-dollar"></i></div>
          <div>
            <div class="label">Monto vendido</div>
            <div class="value">$<?= number_format($totalMonto,2) ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="col">
      <div class="card card-surface p-2 h-100">
        <div class="stat">
          <div class="icon"><i class="bi bi-graph-up"></i></div>
          <div>
            <div class="label">Ticket promedio</div>
            <div class="value">$<?= number_format($ticketPromedio,2) ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Filtros -->
  <form method="GET" class="card card-surface p-3 mt-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h5 class="m-0"><i class="bi bi-funnel me-2"></i>Filtros</h5>
      <div class="d-flex gap-2">
        <?php
          $prev = max(0, $semanaSeleccionada - 1);
          $next = $semanaSeleccionada + 1;
          $qsPrev = $_GET; $qsPrev['semana'] = $prev;
          $qsNext = $_GET; $qsNext['semana'] = $next;
        ?>
        <a class="btn btn-soft btn-sm" href="?<?= http_build_query($qsPrev) ?>"><i class="bi bi-arrow-left"></i> Semana previa</a>
        <a class="btn btn-soft btn-sm" href="?<?= http_build_query($qsNext) ?>">Siguiente semana <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>

    <div class="row g-3 mt-1 filters">
      <div class="col-md-3">
        <label class="small-muted">Semana</label>
        <select name="semana" class="form-select" onchange="this.form.submit()">
          <?php for ($i=0; $i<8; $i++):
            list($ini, $fin) = obtenerSemanaPorIndice($i);
            $texto = "Del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}";
          ?>
            <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>><?= $texto ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <?php if ($puedeElegirSucursal): ?>
      <div class="col-md-3">
        <label class="small-muted">Sucursal</label>
        <select name="sucursal" class="form-select" onchange="this.form.submit()">
          <option value="0" <?= $sucursalFiltro===0?'selected':'' ?>>Todas</option>
          <?php foreach ($listaSucursales as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= ($sucursalFiltro==(int)$s['id'])?'selected':'' ?>>
              <?= h($s['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($adminLugaPuedeVerSubdis): ?>
      <div class="col-md-3">
        <label class="small-muted d-block">Alcance</label>
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="incl_subdis" value="1"
                 id="incl_subdis" <?= $incluyeSubdis===1 ? 'checked' : '' ?>
                 onchange="this.form.submit()">
          <label class="form-check-label" for="incl_subdis">
            Incluir ventas Subdis
          </label>
        </div>
      </div>
      <?php endif; ?>

      <div class="col-md-3">
        <label class="small-muted">Tipo de venta</label>
        <select name="tipo_venta" class="form-select">
          <option value="">Todas</option>
          <option value="Contado" <?= (($_GET['tipo_venta'] ?? '')=='Contado')?'selected':'' ?>>Contado</option>
          <option value="Financiamiento" <?= (($_GET['tipo_venta'] ?? '')=='Financiamiento')?'selected':'' ?>>Financiamiento</option>
          <option value="Financiamiento+Combo" <?= (($_GET['tipo_venta'] ?? '')=='Financiamiento+Combo')?'selected':'' ?>>Financiamiento + Combo</option>
        </select>
      </div>

      <div class="col-md-3">
        <label class="small-muted">Usuario</label>
        <select name="usuario" class="form-select">
          <option value="">Todos</option>

          <?php if (!empty($usuariosActivos)): ?>
            <optgroup label="Activos">
              <?php foreach ($usuariosActivos as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (($_GET['usuario'] ?? '')==$u['id'])?'selected':'' ?>>
                  <?= h($u['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>

          <?php if (!empty($usuariosInactivos)): ?>
            <optgroup label="Inactivos (con ventas en semana)">
              <?php foreach ($usuariosInactivos as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= (($_GET['usuario'] ?? '')==$u['id'])?'selected':'' ?>>
                  <?= h($u['nombre']) ?> (inactivo)
                </option>
              <?php endforeach; ?>
            </optgroup>
          <?php endif; ?>
        </select>
      </div>

      <div class="col-md-3">
        <label class="small-muted">Buscar (Cliente, Tel, IMEI, TAG)</label>
        <input type="text" name="buscar" class="form-control" value="<?= h($_GET['buscar'] ?? '') ?>" placeholder="Ej. Juan, 722..., 3520, LUGA-...">
      </div>
    </div>

    <div class="mt-3 d-flex justify-content-end gap-2">
      <button class="btn btn-luga btn-luga-primary"><i class="bi bi-funnel"></i> Filtrar</button>
      <a href="historial_ventas.php" class="btn btn-luga btn-luga-ghost">Limpiar</a>
      <a href="exportar_excel.php?<?= http_build_query($_GET) ?>" class="btn btn-luga btn-luga-success">
        <i class="bi bi-file-earmark-excel"></i> Exportar a Excel
      </a>
    </div>
  </form>

  <!-- 🔽 Descarga mensual solo para Admin -->
  <?php if ($ROL === 'Admin'): ?>
    <div class="card card-surface p-3 mt-2">
      <div class="d-flex flex-wrap align-items-end gap-3">
        <div>
          <h6 class="mb-1">
            <i class="bi bi-calendar3 me-1"></i>Descargar ventas de un mes completo
          </h6>
          <div class="small-muted">
            Exporta todas las ventas del mes seleccionado.
            Se respeta el filtro de sucursal elegido en los filtros de arriba.
          </div>
        </div>
        <form method="GET" action="exportar_ventas_mes.php" class="ms-auto d-flex flex-wrap align-items-end gap-2">
          <input type="hidden" name="sucursal" value="<?= (int)$sucursalFiltro ?>">
          <?php if ($adminLugaPuedeVerSubdis): ?>
            <input type="hidden" name="incl_subdis" value="<?= (int)$incluyeSubdis ?>">
          <?php endif; ?>

          <div>
            <label class="small-muted mb-0">Mes</label>
            <select name="mes" class="form-select form-select-sm" required>
              <?php
                $mesActual = (int)date('n');
                foreach ($MESES_ES as $numMes => $nombreMes):
              ?>
                <option value="<?= $numMes ?>" <?= $numMes === $mesActual ? 'selected' : '' ?>>
                  <?= $nombreMes ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="small-muted mb-0">Año</label>
            <select name="anio" class="form-select form-select-sm" required>
              <?php
                $anioActual = (int)date('Y');
                for ($y = $anioActual - 3; $y <= $anioActual + 1; $y++):
              ?>
                <option value="<?= $y ?>" <?= $y === $anioActual ? 'selected' : '' ?>>
                  <?= $y ?>
                </option>
              <?php endfor; ?>
            </select>
          </div>

          <div class="mb-0">
            <label class="small-muted mb-0 d-block">&nbsp;</label>
            <button class="btn btn-luga btn-luga-success btn-sm">
              <i class="bi bi-file-earmark-excel"></i> Descargar mes
            </button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

  <!-- Historial (accordion) -->
  <?php if ($totalVentas === 0): ?>
    <div class="alert alert-info card-surface mt-3 mb-0">
      <i class="bi bi-info-circle me-1"></i>No hay ventas para los filtros seleccionados.
    </div>
  <?php else: ?>
    <div class="accordion mt-3" id="ventasAccordion">
      <?php $idx = 0; while ($venta = $ventas->fetch_assoc()): $idx++; ?>
        <?php
          $esPropia = ((int)$venta['id_usuario'] === $idUsuarioSesion);

          $fechaVentaDT = new DateTime($venta['fecha_venta']);
          $enSemanaActual = ($fechaVentaDT >= $inicioActualObj && $fechaVentaDT <= $finActualObj);

          $puedeEliminar = in_array($ROL, ['Admin','Subdis_Admin'], true);

          $puedeEditar   = (in_array($ROL, ['Ejecutivo','Gerente','subdis_ejecutivo','Subdis_Ejecutivo','SubdisEjecutivo'], true) && $esPropia && $enSemanaActual);

          $chipIcon = 'bi-tag';
          if ($venta['tipo_venta'] === 'Contado') $chipIcon = 'bi-cash-coin';
          elseif ($venta['tipo_venta'] === 'Financiamiento') $chipIcon = 'bi-bank';
          elseif ($venta['tipo_venta'] === 'Financiamiento+Combo') $chipIcon = 'bi-box-seam';

          $accId = "venta".$idx;
        ?>
        <div class="accordion-item card-surface mb-2">
          <h2 class="accordion-header" id="h<?= $accId ?>">
            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#c<?= $accId ?>" aria-expanded="<?= $idx===1?'true':'false' ?>" aria-controls="c<?= $accId ?>">
              <div class="venta-head d-flex flex-wrap align-items-center gap-2">
                <span class="badge text-bg-secondary">#<?= (int)$venta['id'] ?></span>
                <span class="chip chip-info"><i class="bi <?= $chipIcon ?>"></i> <?= h($venta['tipo_venta']) ?></span>
                <span class="tag">TAG: <?= h($venta['tag']) ?></span>
                <span>Cliente: <strong><?= h($venta['nombre_cliente']) ?></strong> (<?= h($venta['telefono_cliente']) ?>)</span>
                <span class="ms-2"><i class="bi bi-calendar-event"></i> <?= h($venta['fecha_venta']) ?></span>
                <span class="ms-2"><i class="bi bi-person"></i> <?= h($venta['usuario']) ?></span>
                <span class="ms-2 fw-semibold"><i class="bi bi-currency-dollar"></i> $<?= number_format((float)$venta['precio_venta'],2) ?></span>
                <span class="ms-2 chip chip-success">
                  <i class="bi bi-wallet2"></i> Enganche: $<?= number_format((float)$venta['enganche'],2) ?>
                  <?php if (!empty($venta['forma_pago_enganche'])): ?>
                    &nbsp;(<em><?= h($venta['forma_pago_enganche']) ?></em>)
                  <?php endif; ?>
                </span>
                <?php if (!$enSemanaActual): ?>
                  <span class="ms-2 badge rounded-pill text-bg-secondary">Fuera de semana actual</span>
                <?php endif; ?>
              </div>
            </button>
          </h2>
          <div id="c<?= $accId ?>" class="accordion-collapse collapse <?= $idx===1?'show':'' ?>" aria-labelledby="h<?= $accId ?>" data-bs-parent="#ventasAccordion">
            <div class="accordion-body">

              <div class="d-flex justify-content-end gap-2 mb-2">
                <?php if ($puedeEditar): ?>
                  <button
                    class="btn btn-luga btn-luga-primary btn-sm btn-edit-venta"
                    data-bs-toggle="modal"
                    data-bs-target="#editarVentaModal"
                    data-id="<?= (int)$venta['id'] ?>"
                    data-tag="<?= h($venta['tag']) ?>"
                    data-precio="<?= number_format((float)$venta['precio_venta'], 2, '.', '') ?>"
                    data-enganche="<?= number_format((float)$venta['enganche'], 2, '.', '') ?>"
                    data-formapago="<?= h($venta['forma_pago_enganche']) ?>"
                    data-cliente="<?= h($venta['nombre_cliente']) ?>"
                    data-telefono="<?= h($venta['telefono_cliente']) ?>"
                    data-tipo="<?= h($venta['tipo_venta']) ?>"
                  >
                    <i class="bi bi-pencil-square"></i> Editar
                  </button>
                <?php endif; ?>

                <?php if ($puedeEliminar): ?>
                  <button
                    class="btn btn-luga btn-luga-danger btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#confirmEliminarModal"
                    data-idventa="<?= (int)$venta['id'] ?>">
                    <i class="bi bi-trash"></i> Eliminar
                  </button>
                <?php endif; ?>
              </div>

              <div class="tbl-wrap">
                <table class="table table-hover align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>Marca</th>
                      <th>Modelo</th>
                      <th>Color</th>
                      <th>IMEI</th>
                      <?php if (!$esSubdistribuidor): ?>
                        <th>Precio Lista</th>
                        <th>Comisión Regular</th>
                        <th>Comisión Especial</th>
                        <th>Total Comisión</th>
                      <?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (isset($detalles[$venta['id']])): ?>
                      <?php $esPrincipal = true; foreach ($detalles[$venta['id']] as $equipo): ?>
                        <tr>
                          <td><?= h($equipo['marca']) ?></td>
                          <td><?= h($equipo['modelo']) ?></td>
                          <td><?= h($equipo['color']) ?></td>
                          <td><?= h($equipo['imei1']) ?></td>
                          <?php if (!$esSubdistribuidor): ?>
                            <td>
                              <?php if ($esPrincipal): ?>
                                $<?= number_format((float)$equipo['precio_lista'], 2) ?>
                              <?php else: ?>
                                -
                              <?php endif; ?>
                            </td>
                            <td>$<?= number_format((float)$equipo['comision_regular'], 2) ?></td>
                            <td>$<?= number_format((float)$equipo['comision_especial'], 2) ?></td>
                            <td>$<?= number_format((float)$equipo['comision'], 2) ?></td>
                          <?php endif; ?>
                        </tr>
                        <?php $esPrincipal = false; endforeach; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="<?= $esSubdistribuidor ? 5 : 8; ?>" class="text-center small-muted">Sin equipos registrados</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <?php if ((float)$venta['enganche'] > 0): ?>
                <div class="mt-3 small-muted">
                  <strong>Desglose enganche:</strong>
                  Efectivo $<?= number_format((float)$venta['enganche_efectivo'],2) ?> ·
                  Tarjeta $<?= number_format((float)$venta['enganche_tarjeta'],2) ?>
                </div>
              <?php endif; ?>

              <?php if (trim((string)$venta['comentarios']) !== ''): ?>
                <div class="mt-3 comentarios-box">
                  <i class="bi bi-chat-text me-1"></i>
                  <strong>Comentarios:</strong> <?= nl2br(h($venta['comentarios'])) ?>
                </div>
              <?php endif; ?>

            </div>
          </div>
        </div>
      <?php endwhile; ?>
    </div>
  <?php endif; ?>

</div>

<div class="modal fade" id="editarVentaModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form action="editar_venta.php" method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Editar venta</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id_venta" id="ev_id_venta">
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label">TAG</label>
            <input type="text" class="form-control" name="tag" id="ev_tag" maxlength="50">
            <div class="form-text" id="ev_tag_help">Obligatorio excepto en ventas de Contado.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Precio de venta</label>
            <input type="number" step="0.01" min="0" class="form-control" name="precio_venta" id="ev_precio" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Enganche</label>
            <input type="number" step="0.01" min="0" class="form-control" name="enganche" id="ev_enganche">
          </div>
          <div class="col-md-6">
            <label class="form-label">Forma de pago del enganche</label>
            <select class="form-select" name="forma_pago_enganche" id="ev_forma">
              <option value="">N/A</option>
              <option value="Efectivo">Efectivo</option>
              <option value="Tarjeta">Tarjeta</option>
              <option value="Mixto">Mixto</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Nombre del cliente</label>
            <input type="text" class="form-control" name="nombre_cliente" id="ev_cliente" maxlength="100">
          </div>
          <div class="col-md-6">
            <label class="form-label">Teléfono del cliente</label>
            <input type="text" class="form-control" name="telefono_cliente" id="ev_tel" maxlength="20">
          </div>
        </div>
        <div class="form-text mt-2">
          Solo puedes editar estos campos. Otros datos (equipos, comisiones, etc.) no se modifican aquí.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-luga btn-luga-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-luga btn-luga-primary">Guardar cambios</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="confirmEliminarModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form action="eliminar_venta.php" method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Confirmar eliminación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id_venta" id="modalIdVenta">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['del_venta_token']) ?>">
        <p class="mb-0">¿Seguro que deseas eliminar esta venta? <br>
        <small class="text-muted">Esto devolverá los equipos al inventario y quitará la comisión.</small></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-luga btn-luga-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-luga btn-luga-danger">Eliminar</button>
      </div>
    </form>
  </div>
</div>

<script>
const modalDel = document.getElementById('confirmEliminarModal');
if (modalDel) {
  modalDel.addEventListener('show.bs.modal', (ev) => {
    const btn = ev.relatedTarget;
    const id = btn?.getAttribute('data-idventa') || '';
    document.getElementById('modalIdVenta').value = id;
  });
}

document.querySelectorAll('.btn-edit-venta').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('ev_id_venta').value = btn.dataset.id || '';
    document.getElementById('ev_tag').value      = btn.dataset.tag || '';
    document.getElementById('ev_precio').value   = btn.dataset.precio || '';
    document.getElementById('ev_enganche').value = btn.dataset.enganche || '';
    document.getElementById('ev_forma').value    = btn.dataset.formapago || '';
    document.getElementById('ev_cliente').value  = btn.dataset.cliente || '';
    document.getElementById('ev_tel').value      = btn.dataset.telefono || '';

    const tipo = (btn.dataset.tipo || '').trim();
    const tagInput = document.getElementById('ev_tag');
    const help = document.getElementById('ev_tag_help');
    const requerido = (tipo !== 'Contado');
    tagInput.required = requerido;
    help.textContent = requerido
      ? 'Obligatorio para Financiamiento / Financiamiento+Combo.'
      : 'Opcional en ventas de Contado.';
  });
});
</script>
</body>
</html>