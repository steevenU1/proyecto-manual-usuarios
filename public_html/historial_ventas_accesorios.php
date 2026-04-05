<?php
// historial_ventas_accesorios.php — Listado + Export CSV + Eliminar (solo Admin con reposición de inventario)
// Subdistribuidores: filtra por propiedad/id_subdis si existe en el esquema.
// Detecta si existe detalle_venta_*; si no, impide eliminar para no perder trazabilidad de inventario.

session_start();
if (!isset($_SESSION['id_usuario'])) { header('Location: index.php'); exit(); }

require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

$ROL         = $_SESSION['rol'] ?? '';
$ROL_N       = strtolower(trim((string)$ROL));
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);
$ID_SUBDIS   = (int)($_SESSION['id_subdis'] ?? 0);

$PERM_DELETE = ($ROL === 'Admin'); // Solo Admin puede eliminar

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function money($n){ return '$'.number_format((float)$n, 2, '.', ','); }

function role_in(string $rolLower, array $listLower): bool {
  return in_array($rolLower, $listLower, true);
}

function column_exists(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $st = $conn->prepare($sql); if (!$st) return false;
  $st->bind_param('ss', $table, $column);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
}

function table_exists(mysqli $conn, string $table): bool {
  $st = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? LIMIT 1");
  if (!$st) return false;
  $st->bind_param('s',$table);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
}

/* =======================
   Compat: Subdis columns
======================= */
$VENTA_HAS_PROPIEDAD = column_exists($conn, 'ventas_accesorios', 'propiedad');
$VENTA_HAS_ID_SUBDIS = column_exists($conn, 'ventas_accesorios', 'id_subdis');

$INV_HAS_PROPIEDAD   = column_exists($conn, 'inventario', 'propiedad');
$INV_HAS_ID_SUBDIS   = column_exists($conn, 'inventario', 'id_subdis');

/* =======================
   Subdis table join
======================= */
$SUBDIS_TAB_OK = table_exists($conn, 'subdistribuidores')
                 && column_exists($conn, 'subdistribuidores', 'id')
                 && column_exists($conn, 'subdistribuidores', 'nombre_comercial');

/* === Resolver tabla de detalle (necesaria para reponer inventario) === */
$DETALLE_TAB = null;
foreach (['detalle_venta_accesorios','detalle_venta_accesorio','detalle_venta_acc','detalle_venta'] as $cand) {
  if (table_exists($conn, $cand)
      && column_exists($conn, $cand, 'id_venta')
      && column_exists($conn, $cand, 'id_producto')
      && column_exists($conn, $cand, 'cantidad')) {
    $DETALLE_TAB = $cand; break;
  }
}

/* =======================
   Eliminar venta (Admin)
======================= */
$flash = '';
if ($PERM_DELETE && isset($_GET['action'], $_GET['id']) && $_GET['action']==='delete') {
  $ventaId = (int)$_GET['id'];

  try {
    if ($ventaId <= 0) throw new Exception('ID de venta inválido.');
    if (!$DETALLE_TAB) throw new Exception('No existe tabla de detalle; no se puede reponer inventario. Eliminación cancelada.');

    // Traer encabezado (para saber sucursal y, si existe, propiedad/id_subdis)
    $cols = "id, id_sucursal";
    if ($VENTA_HAS_PROPIEDAD) $cols .= ", propiedad";
    if ($VENTA_HAS_ID_SUBDIS) $cols .= ", id_subdis";

    $stV = $conn->prepare("SELECT {$cols} FROM ventas_accesorios WHERE id=? LIMIT 1");
    if (!$stV) throw new Exception('No se pudo preparar la consulta de venta.');
    $stV->bind_param('i', $ventaId);
    $stV->execute();
    $venta = $stV->get_result()->fetch_assoc();
    $stV->close();
    if (!$venta) throw new Exception('Venta no encontrada.');

    $idSucursalVenta = (int)$venta['id_sucursal'];

    $ventaPropiedad  = $VENTA_HAS_PROPIEDAD ? (string)($venta['propiedad'] ?? '') : '';
    $ventaIdSubdis   = $VENTA_HAS_ID_SUBDIS ? (int)($venta['id_subdis'] ?? 0) : 0;

    // Traer detalle (producto, cantidad)
    $stD = $conn->prepare("SELECT id_producto, cantidad FROM {$DETALLE_TAB} WHERE id_venta=?");
    if (!$stD) throw new Exception('No se pudo preparar el detalle de la venta.');
    $stD->bind_param('i', $ventaId);
    $stD->execute();
    $det = $stD->get_result()->fetch_all(MYSQLI_ASSOC);
    $stD->close();

    if (!$det) throw new Exception('La venta no tiene renglones de detalle; no se puede reponer inventario.');

    $conn->begin_transaction();

    // Construir UPDATE/INSERT de inventario con filtros por propiedad/id_subdis si existen.
    $whereExtra = "";
    $typesUpdBase = "iii"; // cant, sucursal, producto
    $typesInsBase = "iii"; // producto, sucursal, cant

    $updParamsExtra = [];
    $insColsExtra = "";
    $insValsExtra = "";
    $insParamsExtra = [];
    $insTypesExtra = "";

    if ($INV_HAS_PROPIEDAD && $VENTA_HAS_PROPIEDAD) {
      $whereExtra .= " AND propiedad = ? ";
      $typesUpdBase .= "s";
      $updParamsExtra[] = $ventaPropiedad;

      $insColsExtra .= ", propiedad";
      $insValsExtra .= ", ?";
      $insTypesExtra .= "s";
      $insParamsExtra[] = $ventaPropiedad;
    }

    if ($INV_HAS_ID_SUBDIS && $VENTA_HAS_ID_SUBDIS) {
      $whereExtra .= " AND id_subdis = ? ";
      $typesUpdBase .= "i";
      $updParamsExtra[] = $ventaIdSubdis;

      $insColsExtra .= ", id_subdis";
      $insValsExtra .= ", ?";
      $insTypesExtra .= "i";
      $insParamsExtra[] = $ventaIdSubdis;
    }

    $sqlUpd = "
      UPDATE inventario
         SET cantidad = cantidad + ?
       WHERE id_sucursal = ? AND id_producto = ? AND estatus = 'Disponible'
             {$whereExtra}
       ORDER BY id ASC
       LIMIT 1
    ";

    $sqlIns = "
      INSERT INTO inventario (id_producto, id_sucursal, estatus, cantidad, fecha_ingreso{$insColsExtra})
      VALUES (?,?, 'Disponible', ?, NOW(){$insValsExtra})
    ";

    $stUpd = $conn->prepare($sqlUpd);
    $stIns = $conn->prepare($sqlIns);
    if (!$stUpd || !$stIns) throw new Exception('No se pudieron preparar consultas de reposición de inventario.');

    foreach ($det as $r) {
      $pid  = (int)$r['id_producto'];
      $cant = max(0, (int)$r['cantidad']);
      if ($cant <= 0) continue;

      // Bind dinámico UPDATE
      $bindTypes = $typesUpdBase;
      $bindValues = [$cant, $idSucursalVenta, $pid];
      foreach ($updParamsExtra as $v) $bindValues[] = $v;

      $tmp = [];
      $tmp[] = $bindTypes;
      foreach ($bindValues as $k => $v) { $tmp[] = &$bindValues[$k]; }
      call_user_func_array([$stUpd, 'bind_param'], $tmp);

      $stUpd->execute();

      if ($stUpd->affected_rows < 1) {
        // Insert
        $bindTypes2  = $typesInsBase . $insTypesExtra;
        $bindValues2 = [$pid, $idSucursalVenta, $cant];
        foreach ($insParamsExtra as $v) $bindValues2[] = $v;

        $tmp2 = [];
        $tmp2[] = $bindTypes2;
        foreach ($bindValues2 as $k => $v) { $tmp2[] = &$bindValues2[$k]; }
        call_user_func_array([$stIns, 'bind_param'], $tmp2);

        $stIns->execute();
        if ($stIns->affected_rows < 1) throw new Exception("No se pudo reponer inventario para producto #{$pid}.");
      }
    }

    $stUpd->close();
    $stIns->close();

    // Borrar detalle y encabezado
    $stDelD = $conn->prepare("DELETE FROM {$DETALLE_TAB} WHERE id_venta=?");
    $stDelD->bind_param('i', $ventaId);
    $stDelD->execute();
    $stDelD->close();

    $stDelV = $conn->prepare("DELETE FROM ventas_accesorios WHERE id=? LIMIT 1");
    $stDelV->bind_param('i', $ventaId);
    $stDelV->execute();
    $stDelV->close();

    $conn->commit();
    $flash = '<div class="alert alert-success alert-dismissible fade show mt-2">✅ Venta eliminada y stock repuesto correctamente.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
  } catch (Throwable $e) {
    $conn->rollback();
    $flash = '<div class="alert alert-danger alert-dismissible fade show mt-2">❌ '.h($e->getMessage()).'<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
  }
}

/* =======================
   Columna de fecha
======================= */
$DATE_COL = 'created_at';
foreach (['created_at','fecha_venta','fecha','fecha_registro'] as $c) {
  if (column_exists($conn, 'ventas_accesorios', $c)) { $DATE_COL = $c; break; }
}

/* =======================
   Alcance por rol (SCOPE)
======================= */
$scopeWhere=[]; $scopeParams=[]; $scopeTypes='';

$isSubdisAdmin    = role_in($ROL_N, ['subdis_admin','subdistribuidor_admin','subdisadmin','subdis-admin']);
$isSubdisGerente  = role_in($ROL_N, ['subdis_gerente','subdisgerente','subdis-gerente']);
$isSubdisEjecutivo= role_in($ROL_N, ['subdis_ejecutivo','subdisejecutivo','subdis-ejecutivo']);
$isSubdisRole     = ($isSubdisAdmin || $isSubdisGerente || $isSubdisEjecutivo);

if ($isSubdisRole) {
  // Base: siempre encerrar dentro del mismo subdis (si hay columnas)
  if ($VENTA_HAS_PROPIEDAD) {
    $scopeWhere[] = "v.propiedad = ?";
    $scopeParams[] = "Subdistribuidor";
    $scopeTypes .= "s";
  }
  if ($VENTA_HAS_ID_SUBDIS && $ID_SUBDIS > 0) {
    $scopeWhere[] = "v.id_subdis = ?";
    $scopeParams[] = $ID_SUBDIS;
    $scopeTypes .= "i";
  } elseif ($VENTA_HAS_ID_SUBDIS) {
    // Si existe la columna pero no viene id_subdis en sesión, para no “abrir” todo, caemos a usuario.
    $scopeWhere[] = "v.id_usuario = ?";
    $scopeParams[] = $ID_USUARIO;
    $scopeTypes .= "i";
  }

  // Ahora el alcance específico del rol
  if ($isSubdisAdmin) {
    // ve todo su subdis (ya cubierto arriba)
  } elseif ($isSubdisGerente) {
    $scopeWhere[] = "v.id_sucursal = ?";
    $scopeParams[] = $ID_SUCURSAL;
    $scopeTypes .= "i";
  } else { // subdis_ejecutivo
    $scopeWhere[] = "v.id_usuario = ?";
    $scopeParams[] = $ID_USUARIO;
    $scopeTypes .= "i";
  }

} else {
  // Roles Luga normales
  switch ($ROL) {
    case 'Ejecutivo':
      $scopeWhere[]='v.id_usuario = ?';
      $scopeParams[]=$ID_USUARIO;
      $scopeTypes.='i';
      break;

    case 'Gerente':
      $scopeWhere[]='v.id_sucursal = ?';
      $scopeParams[]=$ID_SUCURSAL;
      $scopeTypes.='i';
      break;

    default:
      // Admin u otros: sin scope extra
      break;
  }
}

/* =======================
   Filtros
======================= */
$hoy = date('Y-m-d');
$inicioDefault = date('Y-m-01');

$fecha_ini = $_GET['fecha_ini'] ?? $inicioDefault;
$fecha_fin = $_GET['fecha_fin'] ?? $hoy;
$q         = trim($_GET['q'] ?? '');
$forma     = $_GET['forma'] ?? '';
$orden     = $_GET['orden'] ?? 'fecha_desc';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = min(100, max(10, (int)($_GET['per'] ?? 20)));
$export    = isset($_GET['export']) && $_GET['export']=='1';

$propiedad = $_GET['propiedad'] ?? ''; // '', 'Luga', 'Subdistribuidor'
if (!in_array($propiedad, ['', 'Luga', 'Subdistribuidor'], true)) $propiedad = '';

// NUEVO: filtro por subdis (solo Admin, y solo si existe columna y tabla)
$subdis_id = (int)($_GET['subdis_id'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha_ini)) $fecha_ini = $inicioDefault;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha_fin)) $fecha_fin = $hoy;
$fecha_fin_inclusive = $fecha_fin.' 23:59:59';

$filtrosWhere=[]; $filtrosParams=[]; $filtrosTypes='';

if ($fecha_ini) { $filtrosWhere[]="v.`$DATE_COL` >= ?"; $filtrosParams[]=$fecha_ini.' 00:00:00'; $filtrosTypes.='s'; }
if ($fecha_fin) { $filtrosWhere[]="v.`$DATE_COL` <= ?"; $filtrosParams[]=$fecha_fin_inclusive; $filtrosTypes.='s'; }

if ($forma !== '' && in_array($forma,['Efectivo','Tarjeta','Mixto'],true)) {
  $filtrosWhere[]='v.forma_pago = ?';
  $filtrosParams[]=$forma;
  $filtrosTypes.='s';
}

if ($q !== '') {
  $filtrosWhere[]="(v.tag LIKE ? OR v.nombre_cliente LIKE ? OR v.telefono LIKE ? OR u.nombre LIKE ? OR s.nombre LIKE ?)";
  $like='%'.$q.'%';
  array_push($filtrosParams,$like,$like,$like,$like,$like);
  $filtrosTypes.='sssss';
}

// Admin: propiedad
if ($PERM_DELETE && $VENTA_HAS_PROPIEDAD && $propiedad !== '') {
  $filtrosWhere[] = "v.propiedad = ?";
  $filtrosParams[] = $propiedad;
  $filtrosTypes .= 's';
}

// Admin: subdis_id (solo cuando tiene sentido)
if ($PERM_DELETE && $VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK && $subdis_id > 0) {
  $filtrosWhere[] = "v.id_subdis = ?";
  $filtrosParams[] = $subdis_id;
  $filtrosTypes .= 'i';
}

/* WHERE común */
$where = []; $params=[]; $types='';
if ($scopeWhere){ $where[]='('.implode(' AND ',$scopeWhere).')'; $params=array_merge($params,$scopeParams); $types.=$scopeTypes; }
if ($filtrosWhere){ $where[]='('.implode(' AND ',$filtrosWhere).')'; $params=array_merge($params,$filtrosParams); $types.=$filtrosTypes; }
$whereSQL = $where ? ('WHERE '.implode(' AND ',$where)) : '';

/* Orden */
$ordenMap = [
  'fecha_desc'=>"v.`$DATE_COL` DESC", 'fecha_asc'=>"v.`$DATE_COL` ASC",
  'total_desc'=>"v.total DESC", 'total_asc'=>"v.total ASC",
  'cliente_asc'=>"v.nombre_cliente ASC", 'cliente_desc'=>"v.nombre_cliente DESC"
];
$orderBy = $ordenMap[$orden] ?? $ordenMap['fecha_desc'];

/* Joins base (sin WHERE) */
$joinsBase = "
  LEFT JOIN usuarios   u  ON u.id = v.id_usuario
  LEFT JOIN sucursales s  ON s.id = v.id_sucursal
";

if ($VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK) {
  $joinsBase .= "\n  LEFT JOIN subdistribuidores sd ON sd.id = v.id_subdis\n";
}

/* Traer catálogo subdis para dropdown (Admin) */
$subdisOptions = [];
if ($PERM_DELETE && $VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK) {
  $sqlSd = "SELECT id, nombre_comercial FROM subdistribuidores WHERE estatus='Activo' ORDER BY nombre_comercial ASC";
  $rsSd = $conn->query($sqlSd);
  if ($rsSd) $subdisOptions = $rsSd->fetch_all(MYSQLI_ASSOC);
}

/* ========= EXPORT CSV ========= */
if ($export) {
  while (ob_get_level()) { ob_end_clean(); }
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="ventas_accesorios'.($DETALLE_TAB?'_detalle':'').'.csv"');
  $out = fopen('php://output','w'); fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

  $csvExtraCols = [];
  if ($VENTA_HAS_PROPIEDAD) $csvExtraCols[] = 'Propiedad';
  if ($VENTA_HAS_ID_SUBDIS) $csvExtraCols[] = 'ID Subdis';
  if ($VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK) $csvExtraCols[] = 'Subdis';

  if ($DETALLE_TAB) {
    $headers = ['Folio','TAG','Cliente','Teléfono','Usuario','Sucursal','Forma de pago'];
    $headers = array_merge($headers, $csvExtraCols);
    $headers = array_merge($headers, ['Accesorio','Cantidad','Precio unitario','Subtotal renglón','Total venta','Fecha','IMEI1','IMEI2']);
    fputcsv($out, $headers);

    $selExtra = "";
    if ($VENTA_HAS_PROPIEDAD) $selExtra .= ", v.propiedad";
    if ($VENTA_HAS_ID_SUBDIS) $selExtra .= ", v.id_subdis";
    if ($VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK) $selExtra .= ", sd.nombre_comercial AS subdis_nombre";

    $csvSQL = "
      SELECT 
        v.id AS folio, v.tag, v.nombre_cliente, v.telefono,
        COALESCE(u.nombre, CONCAT('Usuario #',v.id_usuario))   AS usuario,
        COALESCE(s.nombre, CONCAT('Sucursal #',v.id_sucursal)) AS sucursal,
        v.forma_pago
        {$selExtra},
        v.total AS total_venta, v.`$DATE_COL` AS fecha,
        d.id_producto, d.cantidad, d.precio_unitario,
        (d.cantidad * d.precio_unitario) AS subtotal_renglon,
        TRIM(CONCAT(p.marca,' ',p.modelo,' ',COALESCE(p.color,''))) AS accesorio,
        p.imei1 AS imei1,
        p.imei2 AS imei2
      FROM ventas_accesorios v
      $joinsBase
      INNER JOIN {$DETALLE_TAB} d ON d.id_venta = v.id
      LEFT JOIN productos p ON p.id = d.id_producto
      $whereSQL
      ORDER BY $orderBy, v.id ASC, d.id ASC
    ";
    $st = $conn->prepare($csvSQL);
    if ($types!=='') $st->bind_param($types, ...$params);
    $st->execute(); $rs = $st->get_result();

    while ($r = $rs->fetch_assoc()) {
      $row = [
        $r['folio'], $r['tag'], $r['nombre_cliente'], $r['telefono'],
        $r['usuario'], $r['sucursal'], $r['forma_pago']
      ];

      if ($VENTA_HAS_PROPIEDAD) $row[] = $r['propiedad'] ?? '';
      if ($VENTA_HAS_ID_SUBDIS) $row[] = $r['id_subdis'] ?? '';
      if ($VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK) $row[] = $r['subdis_nombre'] ?? '';

      $row = array_merge($row, [
        $r['accesorio'] ?: '—',
        number_format((float)$r['cantidad'],0,'.',''),
        number_format((float)$r['precio_unitario'],2,'.',''),
        number_format((float)$r['subtotal_renglon'],2,'.',''),
        number_format((float)$r['total_venta'],2,'.',''),
        $r['fecha'],
        $r['imei1'] ?? '',
        $r['imei2'] ?? ''
      ]);

      fputcsv($out, $row);
    }
    fclose($out); exit;

  } else {
    $headers = ['Folio','TAG','Cliente','Teléfono','Usuario','Sucursal','Forma de pago'];
    $headers = array_merge($headers, $csvExtraCols);
    $headers = array_merge($headers, ['Total venta','Fecha','Accesorio','Cantidad','Precio unitario','Subtotal renglón','IMEI1','IMEI2']);
    fputcsv($out, $headers);

    $selExtra = "";
    if ($VENTA_HAS_PROPIEDAD) $selExtra .= ", v.propiedad";
    if ($VENTA_HAS_ID_SUBDIS) $selExtra .= ", v.id_subdis";
    if ($VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK) $selExtra .= ", sd.nombre_comercial AS subdis_nombre";

    $csvSQL = "
      SELECT v.id AS folio, v.tag, v.nombre_cliente, v.telefono,
             COALESCE(u.nombre, CONCAT('Usuario #',v.id_usuario))   AS usuario,
             COALESCE(s.nombre, CONCAT('Sucursal #',v.id_sucursal)) AS sucursal,
             v.forma_pago
             {$selExtra},
             v.total AS total_venta, v.`$DATE_COL` AS fecha
      FROM ventas_accesorios v
      $joinsBase
      $whereSQL
      ORDER BY $orderBy, v.id ASC
    ";
    $st = $conn->prepare($csvSQL);
    if ($types!=='') $st->bind_param($types, ...$params);
    $st->execute(); $rs = $st->get_result();

    while ($r = $rs->fetch_assoc()) {
      $row = [
        $r['folio'], $r['tag'], $r['nombre_cliente'], $r['telefono'],
        $r['usuario'], $r['sucursal'], $r['forma_pago']
      ];

      if ($VENTA_HAS_PROPIEDAD) $row[] = $r['propiedad'] ?? '';
      if ($VENTA_HAS_ID_SUBDIS) $row[] = $r['id_subdis'] ?? '';
      if ($VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK) $row[] = $r['subdis_nombre'] ?? '';

      $row = array_merge($row, [
        number_format((float)$r['total_venta'],2,'.',''),
        $r['fecha'],
        '—', 0, number_format(0,2,'.',''), number_format(0,2,'.',''),
        '', ''
      ]);

      fputcsv($out, $row);
    }
    fclose($out); exit;
  }
}
/* ========= FIN EXPORT ========= */

/* =======================
   Listado UI
======================= */
$sqlBase = "FROM ventas_accesorios v $joinsBase $whereSQL";

$countSQL = "SELECT COUNT(*) AS n ".$sqlBase;
$st = $conn->prepare($countSQL);
if ($types!=='') $st->bind_param($types, ...$params);
$st->execute();
$totalRows = (int)($st->get_result()->fetch_assoc()['n'] ?? 0);

$page = max(1,$page);
$offset = ($page-1)*$perPage;

$selExtraList = "";
if ($VENTA_HAS_PROPIEDAD) $selExtraList .= ", v.propiedad";
if ($VENTA_HAS_ID_SUBDIS) $selExtraList .= ", v.id_subdis";
if ($VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK) $selExtraList .= ", sd.nombre_comercial AS subdis_nombre";

$listSQL = "
  SELECT v.id, v.tag, v.nombre_cliente, v.telefono, v.forma_pago, v.total, v.`$DATE_COL` AS fecha
         {$selExtraList},
         COALESCE(u.nombre, CONCAT('Usuario #',v.id_usuario))   AS usuario_nombre,
         COALESCE(s.nombre, CONCAT('Sucursal #',v.id_sucursal)) AS sucursal_nombre
  $sqlBase
  ORDER BY $orderBy
  LIMIT ?, ?
";

$st2 = $conn->prepare($listSQL);
if ($types!=='') {
  $types2=$types.'ii';
  $bind=array_merge($params,[$offset,$perPage]);
  $st2->bind_param($types2, ...$bind);
} else {
  $st2->bind_param('ii', $offset, $perPage);
}
$st2->execute();
$rows = $st2->get_result()->fetch_all(MYSQLI_ASSOC);

$totalPages = max(1, (int)ceil($totalRows / $perPage));
function sel($a,$b){ return $a===$b?'selected':''; }

require_once __DIR__.'/navbar.php';
?>
<!doctype html>
<html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Historial · Ventas de Accesorios</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f5f7fb}.card-ghost{backdrop-filter:saturate(140%) blur(6px);border:1px solid #0001;box-shadow:0 10px 25px #0001}
.table thead th{position:sticky;top:0;background:#fff;z-index:1}.money{text-align:right}.kpi{font-size:.95rem}
.badge-soft{background:#6c757d14;border:1px solid #6c757d2e}.modal-xxl{--bs-modal-width:min(1100px,96vw)}
.ticket-frame{width:100%;height:80vh;border:0;border-radius:.75rem;background:#fff}
</style>
</head>
<body>
<div class="container py-4">

  <div class="d-flex align-items-center justify-content-between mb-2">
    <div>
      <h3 class="mb-0">Historial · Ventas de Accesorios</h3>
      <?php
        $scopeText='Toda la compañía';
        if($ROL==='Gerente') $scopeText='Sucursal';
        if($ROL==='Ejecutivo') $scopeText='Mis ventas';
        if($isSubdisAdmin) $scopeText='Subdis: Todo el subdistribuidor';
        if($isSubdisGerente) $scopeText='Subdis: Mi sucursal';
        if($isSubdisEjecutivo) $scopeText='Subdis: Mis ventas';
      ?>
      <span class="badge rounded-pill text-secondary badge-soft"><?= h($scopeText) ?></span>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="venta_accesorios.php">Nueva venta</a>
      <a class="btn btn-outline-primary btn-sm" id="btnExport">Exportar CSV</a>
    </div>
  </div>

  <?= $flash ?>

  <?php
  $frm_fecha_ini=$fecha_ini; $frm_fecha_fin=$fecha_fin; $frm_q=$q; $frm_forma=$forma; $frm_orden=$orden; $frm_per=$perPage;
  $frm_propiedad=$propiedad; $frm_subdis=$subdis_id;
  ?>

  <form class="card card-ghost p-3 mb-3" method="get" id="frmFiltros">
    <div class="row g-2 align-items-end">
      <div class="col-lg-2">
        <label class="form-label">Fecha inicial</label>
        <input type="date" name="fecha_ini" value="<?= h($frm_fecha_ini) ?>" class="form-control">
      </div>
      <div class="col-lg-2">
        <label class="form-label">Fecha final</label>
        <input type="date" name="fecha_fin" value="<?= h($frm_fecha_fin) ?>" class="form-control">
      </div>
      <div class="col-lg-3">
        <label class="form-label">Buscar</label>
        <input type="text" name="q" value="<?= h($frm_q) ?>" class="form-control" placeholder="TAG, cliente, teléfono, usuario, sucursal…">
      </div>
      <div class="col-lg-2">
        <label class="form-label">Forma de pago</label>
        <select class="form-select" name="forma">
          <option value="">Todas</option>
          <option value="Efectivo" <?= sel($frm_forma,'Efectivo')?> >Efectivo</option>
          <option value="Tarjeta"  <?= sel($frm_forma,'Tarjeta')?> >Tarjeta</option>
          <option value="Mixto"    <?= sel($frm_forma,'Mixto')?> >Mixto</option>
        </select>
      </div>

      <?php if ($PERM_DELETE && $VENTA_HAS_PROPIEDAD): ?>
      <div class="col-lg-2">
        <label class="form-label">Propiedad</label>
        <select class="form-select" name="propiedad" id="selPropiedad">
          <option value="" <?= sel($frm_propiedad,'') ?>>Todas</option>
          <option value="Luga" <?= sel($frm_propiedad,'Luga') ?>>Luga</option>
          <option value="Subdistribuidor" <?= sel($frm_propiedad,'Subdistribuidor') ?>>Subdistribuidor</option>
        </select>
      </div>
      <?php endif; ?>

      <?php if ($PERM_DELETE && $VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK): ?>
      <div class="col-lg-2">
        <label class="form-label">Subdis</label>
        <select class="form-select" name="subdis_id" id="selSubdis">
          <option value="0">Todos</option>
          <?php foreach ($subdisOptions as $sd): ?>
            <option value="<?= (int)$sd['id'] ?>" <?= ((int)$sd['id']===$frm_subdis?'selected':'') ?>>
              <?= h($sd['nombre_comercial']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Solo Admin.</div>
      </div>
      <?php endif; ?>

      <div class="col-lg-2">
        <label class="form-label">Orden</label>
        <select class="form-select" name="orden">
          <option value="fecha_desc"  <?= sel($frm_orden,'fecha_desc')?>>Fecha ↓</option>
          <option value="fecha_asc"   <?= sel($frm_orden,'fecha_asc')?>>Fecha ↑</option>
          <option value="total_desc"  <?= sel($frm_orden,'total_desc')?>>Total ↓</option>
          <option value="total_asc"   <?= sel($frm_orden,'total_asc')?>>Total ↑</option>
          <option value="cliente_asc" <?= sel($frm_orden,'cliente_asc')?>>Cliente A-Z</option>
          <option value="cliente_desc"<?= sel($frm_orden,'cliente_desc')?>>Cliente Z-A</option>
        </select>
      </div>

      <div class="col-lg-1">
        <label class="form-label">Por pág.</label>
        <input type="number" name="per" value="<?= (int)$frm_per ?>" min="10" max="100" class="form-control">
      </div>

      <div class="col-lg-12 mt-2">
        <button class="btn btn-primary">Aplicar</button>
        <a class="btn btn-outline-secondary" href="historial_ventas_accesorios.php">Limpiar</a>
      </div>
    </div>
  </form>

  <div class="card card-ghost">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Folio</th><th>TAG</th><th>Cliente</th><th>Teléfono</th>
            <th>Usuario</th><th>Sucursal</th>
            <?php if ($VENTA_HAS_PROPIEDAD): ?><th>Propiedad</th><?php endif; ?>
            <?php if ($VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK): ?><th>Subdis</th><?php endif; ?>
            <th>Forma</th>
            <th class="text-end">Total</th><th>Fecha</th>
            <th class="text-end">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $colspan = 10;
            if ($VENTA_HAS_PROPIEDAD) $colspan++;
            if ($VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK) $colspan++;
          ?>
          <?php if (!$rows): ?>
            <tr><td colspan="<?= (int)$colspan ?>" class="text-center text-muted py-4">Sin resultados con los filtros actuales.</td></tr>
          <?php else: foreach ($rows as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= h($r['tag']) ?></td>
              <td><?= h($r['nombre_cliente']) ?></td>
              <td><?= h($r['telefono']) ?></td>
              <td><?= h($r['usuario_nombre']) ?></td>
              <td><?= h($r['sucursal_nombre']) ?></td>

              <?php if ($VENTA_HAS_PROPIEDAD): ?>
                <td><span class="badge text-bg-light"><?= h($r['propiedad'] ?? '') ?></span></td>
              <?php endif; ?>

              <?php if ($VENTA_HAS_ID_SUBDIS && $SUBDIS_TAB_OK): ?>
                <td><?= h($r['subdis_nombre'] ?? '') ?></td>
              <?php endif; ?>

              <td><span class="badge text-bg-light"><?= h($r['forma_pago']) ?></span></td>
              <td class="text-end"><?= money($r['total']) ?></td>
              <td><?= h(date('d/m/Y H:i', strtotime($r['fecha']))) ?></td>
              <td class="text-end">
                <div class="btn-group btn-group-sm">
                  <a class="btn btn-outline-primary btnTicket" data-id="<?= (int)$r['id'] ?>">Ticket</a>
                  <?php if ($PERM_DELETE): ?>
                    <a class="btn btn-outline-danger btnDelete" data-id="<?= (int)$r['id'] ?>">Eliminar</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="d-flex justify-content-between align-items-center p-3">
      <div class="kpi text-muted">Mostrando <?= (int)min($totalRows, $offset + count($rows)) ?> de <?= (int)$totalRows ?> ventas</div>
      <nav><ul class="pagination pagination-sm mb-0">
        <?php
          $qs=$_GET; unset($qs['page'],$qs['export']);
          $baseQS=http_build_query($qs);
          $mk=fn($p)=>'?'.$baseQS.'&page='.$p;
          $start=max(1,$page-2); $end=min($totalPages,$page+2);
        ?>
        <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="<?= h($mk(max(1,$page-1))) ?>">«</a></li>
        <?php for($i=$start;$i<=$end;$i++): ?>
          <li class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="<?= h($mk($i)) ?>"><?= $i ?></a></li>
        <?php endfor; ?>
        <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>"><a class="page-link" href="<?= h($mk(min($totalPages,$page+1))) ?>">»</a></li>
      </ul></nav>
    </div>
    <?php else: ?>
      <div class="p-3 text-muted kpi">Total: <?= (int)$totalRows ?> ventas</div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal Ticket -->
<div class="modal fade" id="ticketModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xxl">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Ticket de venta</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body"><iframe id="ticketFrame" class="ticket-frame" src="about:blank"></iframe></div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-primary" id="btnPrintTicket">Imprimir</button>
      </div>
    </div>
  </div>
</div>

<!-- Si tu proyecto ya carga bootstrap.bundle en navbar/layout, déjalo así. Si no, descomenta: -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
document.querySelectorAll('.btnTicket').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id = btn.dataset.id;
    const frame = document.getElementById('ticketFrame');
    frame.src = 'venta_accesorios_ticket.php?id=' + encodeURIComponent(id);
    new bootstrap.Modal(document.getElementById('ticketModal')).show();
  });
});
document.getElementById('btnPrintTicket').addEventListener('click', ()=>{
  const f = document.getElementById('ticketFrame');
  try{ f.contentWindow.focus(); f.contentWindow.print(); }catch(e){}
});
document.getElementById('btnExport').addEventListener('click', ()=>{
  const frm = document.getElementById('frmFiltros');
  const params = new URLSearchParams(new FormData(frm));
  params.set('export','1');
  window.location.href = window.location.pathname + '?' + params.toString();
});

// Eliminar venta (solo Admin)
document.querySelectorAll('.btnDelete').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    const id = btn.dataset.id;
    if (!id) return;
    if (!confirm('¿Eliminar venta #' + id + '?\nSe repondrá el stock de accesorios en inventario.')) return;
    const url = new URL(window.location.href);
    url.searchParams.set('action','delete');
    url.searchParams.set('id', id);
    // Mantener filtros/paginación actuales
    window.location.href = url.toString();
  });
});
</script>
</body></html>
