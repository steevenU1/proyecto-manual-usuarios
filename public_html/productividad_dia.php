<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function column_exists(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = ?
              AND COLUMN_NAME  = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $ok = ($stmt->get_result()->num_rows > 0);
    $stmt->close();
    return $ok;
}

function table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $ok = ($stmt->get_result()->num_rows > 0);
    $stmt->close();
    return $ok;
}

function is_subdis_prop($v): bool {
    $x = strtolower(trim((string)$v));
    return in_array($x, ['subdis','subdistribuidor','subdistribuidores','subdistribuidora'], true);
}
function is_luga_prop($v): bool {
    $x = strtolower(trim((string)$v));
    return ($x === '' || in_array($x, ['propia','luga','tienda propia','tiendas propias'], true));
}
function normalizarZonaDia($raw): string {
    $z = trim((string)$raw);
    if ($z === '') return 'Sin zona';

    $z = preg_replace('/^\s*Zona\s+/i', '', $z);
    $z = trim($z);

    if ($z === '') return 'Sin zona';
    return 'Zona ' . $z;
}
function agruparSucursalesPorZonaDia(array $rows): array {
    $out = [];

    foreach ($rows as $row) {
        $zona = normalizarZonaDia($row['zona'] ?? '');
        if (!isset($out[$zona])) {
            $out[$zona] = [];
        }
        $out[$zona][] = $row;
    }

    uksort($out, function($a, $b){
        $rank = function($z){
            if (preg_match('/zona\s*(\d+)/i', $z, $m)) return (int)$m[1];
            if (stripos($z, 'sin zona') !== false) return 999999;
            return 500000;
        };
        return $rank($a) <=> $rank($b);
    });

    return $out;
}

/* =====================
   Contexto sesión / scope
===================== */
$ROL        = $_SESSION['rol'] ?? '';
$ROL_L      = strtolower(trim($ROL));
$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);

$subdisRoles = ['subdis_admin','subdis_gerente','subdis_ejecutivo','subdis admin','subdis gerente','subdis ejecutivo'];
$isSubdisUser = in_array($ROL_L, $subdisRoles, true);

/* Columnas multi-tenant (si existen) */
$hasSucProp     = column_exists($conn, 'sucursales', 'propiedad');
$hasSucIdSubdis = column_exists($conn, 'sucursales', 'id_subdis');
$hasUsrProp     = column_exists($conn, 'usuarios',   'propiedad');
$hasUsrIdSubdis = column_exists($conn, 'usuarios',   'id_subdis');
$hasVtaProp     = column_exists($conn, 'ventas',     'propiedad');
$hasVtaIdSubdis = column_exists($conn, 'ventas',     'id_subdis');

/* Resolver id_subdis de sesión (y fallback desde usuarios) */
$ID_SUBDIS = (int)($_SESSION['id_subdis'] ?? 0);

if ($isSubdisUser && $ID_SUBDIS <= 0 && $hasUsrIdSubdis) {
    $stmt = $conn->prepare("SELECT IFNULL(id_subdis,0) AS id_subdis FROM usuarios WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $ID_USUARIO);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $ID_SUBDIS = (int)($row['id_subdis'] ?? 0);
}

/* Detectar tabla/columna de subdistribuidores */
$SUBDIS_TABLE = null;
if (table_exists($conn, 'subdistribuidores')) $SUBDIS_TABLE = 'subdistribuidores';
if ($SUBDIS_TABLE === null && table_exists($conn, 'subditribuidores')) $SUBDIS_TABLE = 'subditribuidores';

$SUBDIS_NAME_COL = null;
if ($SUBDIS_TABLE) {
    if (column_exists($conn, $SUBDIS_TABLE, 'nombre_comercial')) $SUBDIS_NAME_COL = 'nombre_comercial';
    else if (column_exists($conn, $SUBDIS_TABLE, 'nombre')) $SUBDIS_NAME_COL = 'nombre';
}

/* Filtros por scope */
$ventasScopeSql = "";
$sucScopeSql    = "";
$usrScopeSql    = "";

if ($isSubdisUser) {
    if ($ID_SUBDIS <= 0) {
        $ventasScopeSql = " AND 1=0 ";
        $sucScopeSql    = " AND 1=0 ";
        $usrScopeSql    = " AND 1=0 ";
    } else {
        if ($hasVtaIdSubdis) {
            $ventasScopeSql .= " AND v.id_subdis = ".(int)$ID_SUBDIS." ";
        }
        if ($hasVtaProp) {
            $ventasScopeSql .= " AND LOWER(COALESCE(v.propiedad,'subdis')) IN ('subdis','subdistribuidor','subdistribuidores','subdistribuidora') ";
        }

        if ($hasSucIdSubdis) $sucScopeSql .= " AND s.id_subdis = ".(int)$ID_SUBDIS." ";
        if ($hasSucProp)     $sucScopeSql .= " AND LOWER(COALESCE(s.propiedad,'subdis')) IN ('subdis','subdistribuidor','subdistribuidores','subdistribuidora') ";

        if ($hasUsrIdSubdis) $usrScopeSql .= " AND u.id_subdis = ".(int)$ID_SUBDIS." ";
        if ($hasUsrProp)     $usrScopeSql .= " AND LOWER(COALESCE(u.propiedad,'subdis')) IN ('subdis','subdistribuidor','subdistribuidores','subdistribuidora') ";
    }
}

/* =====================
   Parámetros
===================== */
$diasProductivos = 5;

$hoyLocal  = (new DateTime('now',       new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
$ayerLocal = (new DateTime('yesterday', new DateTimeZone('America/Mexico_City')))->format('Y-m-d');

$fecha = $_GET['fecha'] ?? $ayerLocal;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $fecha = $ayerLocal;
}

/* ===============================================================
   Detectar columna tipo en productos
================================================================ */
$colTipoProd = null;

$stmt = $conn->prepare("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'productos'
      AND COLUMN_NAME  = 'tipo'
    LIMIT 1
");
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) $colTipoProd = 'tipo';
$stmt->close();

if ($colTipoProd === null) {
    $stmt = $conn->prepare("
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'productos'
          AND COLUMN_NAME  = 'tipo_producto'
        LIMIT 1
    ");
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) $colTipoProd = 'tipo_producto';
    $stmt->close();
}

$modemCase = $colTipoProd
    ? "CASE WHEN LOWER(p.`$colTipoProd`) IN ('modem','mifi') THEN 1 ELSE 0 END"
    : "0";

/* ==================================================================
   Subconsulta por venta (día seleccionado) con scope
================================================================== */
$subVentasAggDay = "
    SELECT
        v.id,
        v.id_usuario,
        v.id_sucursal,
        CASE 
            WHEN IFNULL(vm.es_modem,0) = 1 THEN 0
            WHEN LOWER(v.tipo_venta) = 'financiamiento+combo' THEN 2
            ELSE 1
        END AS unidades,
        CASE 
            WHEN IFNULL(vm.es_modem,0) = 1 THEN 0
            ELSE v.precio_venta
        END AS monto
    FROM ventas v
    INNER JOIN sucursales sx ON sx.id = v.id_sucursal
    LEFT JOIN (
        SELECT
            dv.id_venta,
            MAX($modemCase) AS es_modem
        FROM detalle_venta dv
        JOIN productos p ON p.id = dv.id_producto
        GROUP BY dv.id_venta
    ) vm ON vm.id_venta = v.id
    WHERE DATE(v.fecha_venta) = ?
      AND sx.tipo_sucursal = 'Tienda'
      AND IFNULL(sx.activo, 1) = 1
      $ventasScopeSql
";

/* =====================
   TARJETAS GLOBALES
===================== */
$sqlGlobal = "
    SELECT
        COUNT(*)                   AS tickets,
        IFNULL(SUM(va.monto),0)    AS ventas_validas,
        IFNULL(SUM(va.unidades),0) AS unidades_validas
    FROM ( $subVentasAggDay ) va
";
$stmt = $conn->prepare($sqlGlobal);
$stmt->bind_param("s", $fecha);
$stmt->execute();
$glob = $stmt->get_result()->fetch_assoc();
$stmt->close();

$tickets         = (int)($glob['tickets'] ?? 0);
$ventasValidas   = (float)($glob['ventas_validas'] ?? 0);
$unidadesValidas = (int)($glob['unidades_validas'] ?? 0);
$ticketProm      = $tickets > 0 ? ($ventasValidas / $tickets) : 0.0;

/* =====================
   Cuota diaria GLOBAL (u.)
===================== */
$extraSucCuota = $isSubdisUser ? $sucScopeSql : "";

$sqlCuotaDiariaGlobalU = "
    SELECT IFNULL(SUM(cuota_calc),0) AS cuota_diaria_global_u
    FROM (
        SELECT 
            s.id,
            (
                IFNULL((
                    SELECT css.cuota_unidades
                    FROM cuotas_semanales_sucursal css
                    WHERE css.id_sucursal = s.id
                      AND ? BETWEEN css.semana_inicio AND css.semana_fin
                    ORDER BY css.semana_inicio DESC
                    LIMIT 1
                ), 0)
                *
                GREATEST((
                    SELECT COUNT(*) FROM usuarios u2
                    WHERE u2.id_sucursal = s.id
                      AND u2.activo = 1
                      AND ".($isSubdisUser ? "LOWER(u2.rol) = 'subdis_ejecutivo'" : "u2.rol = 'Ejecutivo'")."
                ), 0)
            ) / ? AS cuota_calc
        FROM sucursales s
        WHERE s.tipo_sucursal='Tienda'
          AND IFNULL(s.activo, 1) = 1
        $extraSucCuota
    ) t
";
$stmt = $conn->prepare($sqlCuotaDiariaGlobalU);
$stmt->bind_param("si", $fecha, $diasProductivos);
$stmt->execute();
$cdgU = $stmt->get_result()->fetch_assoc();
$stmt->close();

$cuotaDiariaGlobalU = (float)($cdgU['cuota_diaria_global_u'] ?? 0);

/* =====================
   Cuota diaria GLOBAL ($)
===================== */
$sqlCuotaDiariaGlobalM = "
    SELECT IFNULL(SUM(cuota_diaria),0) AS cuota_diaria_global_monto
    FROM (
        SELECT
            s.id,
            IFNULL((
                SELECT cs.cuota_monto
                FROM cuotas_sucursales cs
                WHERE cs.id_sucursal = s.id
                  AND cs.fecha_inicio <= ?
                ORDER BY cs.fecha_inicio DESC
                LIMIT 1
            ), 0) / ? AS cuota_diaria
        FROM sucursales s
        WHERE s.tipo_sucursal='Tienda'
          AND IFNULL(s.activo, 1) = 1
        $extraSucCuota
    ) t
";
$stmt = $conn->prepare($sqlCuotaDiariaGlobalM);
$stmt->bind_param("si", $fecha, $diasProductivos);
$stmt->execute();
$cdgM = $stmt->get_result()->fetch_assoc();
$stmt->close();

$cuotaDiariaGlobalM = (float)($cdgM['cuota_diaria_global_monto'] ?? 0);
$cumplGlobalM       = $cuotaDiariaGlobalM > 0 ? ($ventasValidas / $cuotaDiariaGlobalM) * 100 : 0;

/* =====================
   RANKING EJECUTIVOS / GERENTES
===================== */
$rankRolesSql = $isSubdisUser
    ? "LOWER(u.rol) IN ('subdis_ejecutivo','subdis_gerente')"
    : "u.rol IN ('Ejecutivo','Gerente')";

$lugaOnlySql = "";
if (!$isSubdisUser && $hasSucProp) {
    $lugaOnlySql = " AND LOWER(COALESCE(s.propiedad,'propia')) NOT IN ('subdis','subdistribuidor','subdistribuidores','subdistribuidora') ";
}

$sqlEjecutivos = "
    SELECT
        u.id,
        u.nombre,
        u.rol,
        s.nombre AS sucursal,

        IFNULL((
            SELECT css.cuota_unidades
            FROM cuotas_semanales_sucursal css
            WHERE css.id_sucursal = s.id
              AND ? BETWEEN css.semana_inicio AND css.semana_fin
            ORDER BY css.semana_inicio DESC
            LIMIT 1
        ) / ?, 0) AS cuota_diaria_ejecutivo,

        IFNULL(COUNT(va.id),0)     AS tickets,
        IFNULL(SUM(va.monto),0)    AS ventas_validas,
        IFNULL(SUM(va.unidades),0) AS unidades_validas

    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN ( $subVentasAggDay ) va ON va.id_usuario = u.id
    WHERE s.tipo_sucursal='Tienda'
      AND IFNULL(s.activo, 1) = 1
      AND u.activo = 1
      AND $rankRolesSql
      $usrScopeSql
      $sucScopeSql
      $lugaOnlySql
    GROUP BY u.id, u.nombre, u.rol, s.nombre
    ORDER BY 
      CASE 
        WHEN LOWER(u.rol) IN ('gerente','subdis_gerente') THEN 1
        ELSE 0
      END,
      unidades_validas DESC,
      ventas_validas DESC
";
$stmt = $conn->prepare($sqlEjecutivos);
$stmt->bind_param("sis", $fecha, $diasProductivos, $fecha);
$stmt->execute();
$resEj = $stmt->get_result();

$ejecutivos = [];
$gerentes   = [];

while ($r = $resEj->fetch_assoc()) {
    $r['cuota_diaria_ejecutivo'] = (float)$r['cuota_diaria_ejecutivo'];
    $r['unidades_validas']       = (int)$r['unidades_validas'];
    $r['ventas_validas']         = (float)$r['ventas_validas'];
    $r['tickets']                = (int)$r['tickets'];
    $r['rol']                    = trim((string)($r['rol'] ?? ''));
    $rolNorm                     = strtolower(str_replace([' ', '-'], '_', $r['rol']));
    $r['cumplimiento']           = $r['cuota_diaria_ejecutivo'] > 0
        ? ($r['unidades_validas'] / $r['cuota_diaria_ejecutivo'] * 100)
        : 0;

    if (in_array($rolNorm, ['gerente', 'subdis_gerente'], true)) {
        $gerentes[] = $r;
    } else {
        $ejecutivos[] = $r;
    }
}
$stmt->close();

/* =====================
   RANKING SUCURSALES
===================== */
$selProp = $hasSucProp ? "s.propiedad AS propiedad," : "'Propia' AS propiedad,";
$selIdS  = $hasSucIdSubdis ? "s.id_subdis AS id_subdis," : "NULL AS id_subdis,";

$joinSd  = ($SUBDIS_TABLE && $hasSucIdSubdis) ? "LEFT JOIN `$SUBDIS_TABLE` sd ON sd.id = s.id_subdis" : "";
$selSd   = ($SUBDIS_TABLE && $hasSucIdSubdis && $SUBDIS_NAME_COL)
    ? "sd.`$SUBDIS_NAME_COL` AS subdistribuidor,"
    : "NULL AS subdistribuidor,";

$sqlSucursales = "
    SELECT
        s.id      AS id_sucursal,
        s.nombre  AS sucursal,
        s.zona,
        $selProp
        $selIdS
        $selSd

        IFNULL((
            SELECT cs.cuota_monto
            FROM cuotas_sucursales cs
            WHERE cs.id_sucursal = s.id
              AND cs.fecha_inicio <= ?
            ORDER BY cs.fecha_inicio DESC
            LIMIT 1
        ) / ?, 0) AS cuota_diaria_monto,

        IFNULL(SUM(va.monto),0)    AS ventas_validas,
        IFNULL(SUM(va.unidades),0) AS unidades_validas

    FROM sucursales s
    $joinSd
    LEFT JOIN ( $subVentasAggDay ) va ON va.id_sucursal = s.id
    WHERE s.tipo_sucursal='Tienda'
      AND IFNULL(s.activo, 1) = 1
      $sucScopeSql
    GROUP BY s.id
    ORDER BY ventas_validas DESC
";
$stmt = $conn->prepare($sqlSucursales);
$stmt->bind_param("sis", $fecha, $diasProductivos, $fecha);
$stmt->execute();
$resSuc = $stmt->get_result();

$sucursales = [];
while ($s = $resSuc->fetch_assoc()) {
    $s['cuota_diaria_monto'] = (float)$s['cuota_diaria_monto'];
    $s['ventas_validas']     = (float)$s['ventas_validas'];
    $s['unidades_validas']   = (int)$s['unidades_validas'];
    $s['cumplimiento_monto'] = $s['cuota_diaria_monto'] > 0
        ? ($s['ventas_validas'] / $s['cuota_diaria_monto'] * 100)
        : 0;
    $sucursales[] = $s;
}
$stmt->close();

/* División (solo cuando el viewer es LUGA) */
$lugaSucRows = [];
$subdisGroups = [];

if (!$isSubdisUser) {
    foreach ($sucursales as $s) {
        $prop = $s['propiedad'] ?? 'Propia';

        if ($hasSucProp && is_subdis_prop($prop)) {
            $gName = trim((string)($s['subdistribuidor'] ?? ''));
            if ($gName === '') {
                $id = (int)($s['id_subdis'] ?? 0);
                $gName = $id > 0 ? ('Subdis #'.$id) : 'Subdis (sin nombre)';
            }

            if (!isset($subdisGroups[$gName])) {
                $subdisGroups[$gName] = ['nombre'=>$gName, 'rows'=>[]];
            }
            $subdisGroups[$gName]['rows'][] = $s;
        } else {
            $lugaSucRows[] = $s;
        }
    }
    ksort($subdisGroups, SORT_NATURAL | SORT_FLAG_CASE);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Productividad del Día (<?= h($fecha) ?>)</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">

  <style>
    .num { font-variant-numeric: tabular-nums; letter-spacing: -.2px; }
    .clip { max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .progress{height:20px}
    .progress-bar{font-size:.75rem}
    #topbar, .navbar-luga{ font-size:16px; }

    @media (max-width:576px){
      #topbar, .navbar-luga{
        font-size:16px;
        --brand-font:1.00em;
        --nav-font:.95em;
        --drop-font:.95em;
        --icon-em:1.05em;
        --pad-y:.44em;
        --pad-x:.62em;
      }
      #topbar .navbar-brand img,
      .navbar-luga .navbar-brand img{
        width:1.8em;
        height:1.8em;
      }
      #topbar .btn-asistencia,
      .navbar-luga .btn-asistencia{
        font-size:.95em;
        padding:.5em .9em !important;
        border-radius:12px;
      }
      #topbar .nav-avatar,
      #topbar .nav-initials,
      .navbar-luga .nav-avatar,
      .navbar-luga .nav-initials{
        width:2.1em;
        height:2.1em;
      }
      #topbar .navbar-toggler,
      .navbar-luga .navbar-toggler{
        padding:.45em .7em;
      }
    }

    @media (max-width:360px){
      #topbar, .navbar-luga{ font-size:15px; }
    }

    @media (max-width:576px){
      body { font-size: 14px; }
      .container { padding-left: 8px; padding-right: 8px; }

      .table { font-size: 12px; table-layout: auto; }
      .table thead th { font-size: 11px; }
      .table td, .table th { padding: .30rem .35rem; }

      .suc-col { display:none !important; }

      .person-name{
        max-width: 100%;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
      }

      .suc-name{
        max-width: none !important;
        white-space: normal !important;
        overflow: visible !important;
        text-overflow: unset !important;
        word-break: break-word;
      }

      .clip { max-width: 120px; }

      th.col-uds, td.col-uds{ width:40px !important; max-width:40px !important; text-align:center; }
      th.col-qta, td.col-qta{ width:55px !important; max-width:55px !important; text-align:center; }
      th.col-cumpl, td.col-cumpl{ width:60px !important; max-width:60px !important; text-align:center; }
    }

    @media (max-width:360px){
      .table { font-size: 11px; }
      .table td, .table th { padding: .28rem .30rem; }
      .clip { max-width: 96px; }
    }
  </style>
</head>
<body class="bg-light">

<?php include __DIR__ . '/navbar.php'; ?>

<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
    <h2 class="m-0">
      📅 Productividad del Día — <?= date('d/m/Y', strtotime($fecha)) ?>
      <?php if($isSubdisUser): ?>
        <span class="badge bg-info text-dark ms-2">SUBDIS</span>
      <?php endif; ?>
    </h2>
    <form method="GET" class="d-flex gap-2">
      <input type="date" name="fecha" class="form-control" value="<?= h($fecha) ?>" max="<?= h($hoyLocal) ?>">
      <button class="btn btn-primary">Ver</button>
      <a class="btn btn-outline-secondary" href="productividad_dia.php?fecha=<?= h($ayerLocal) ?>">Ayer</a>
    </form>
  </div>

  <div class="row mt-3 g-3">
    <div class="col-md-3">
      <div class="card shadow text-center">
        <div class="card-header bg-dark text-white">Unidades</div>
        <div class="card-body"><h3><?= (int)$unidadesValidas ?></h3></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow text-center">
        <div class="card-header bg-dark text-white">Ventas $</div>
        <div class="card-body"><h3>$<?= number_format($ventasValidas,2) ?></h3></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow text-center">
        <div class="card-header bg-dark text-white">Tickets</div>
        <div class="card-body"><h3><?= (int)$tickets ?></h3></div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow text-center">
        <div class="card-header bg-dark text-white">Ticket Prom.</div>
        <div class="card-body"><h3>$<?= number_format($ticketProm,2) ?></h3></div>
      </div>
    </div>
  </div>

  <div class="row mt-3 g-3">
    <div class="col-md-4">
      <div class="card shadow text-center">
        <div class="card-header bg-primary text-white">Cuota diaria global (u.)</div>
        <div class="card-body"><h4><?= number_format($cuotaDiariaGlobalU,2) ?></h4></div>
      </div>
    </div>
    <div class="col-md-8">
      <div class="card shadow">
        <div class="card-body">
          <?php
            $cumplGlobalU = $cuotaDiariaGlobalU > 0 ? ($unidadesValidas / $cuotaDiariaGlobalU) * 100 : 0;
            $clsU = ($cumplGlobalU>=100?'bg-success':($cumplGlobalU>=60?'bg-warning':'bg-danger'));
          ?>
          <div class="d-flex justify-content-between">
            <div><strong>Cumplimiento global del día (u.)</strong></div>
            <div><strong><?= number_format(min(100,$cumplGlobalU),1) ?>%</strong></div>
          </div>
          <div class="progress" style="height:22px">
            <div class="progress-bar <?= $clsU ?>" style="width:<?= min(100,$cumplGlobalU) ?>%"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row mt-3 g-3">
    <div class="col-md-4">
      <div class="card shadow text-center">
        <div class="card-header bg-primary text-white">Cuota diaria global ($)</div>
        <div class="card-body"><h4>$<?= number_format($cuotaDiariaGlobalM,2) ?></h4></div>
      </div>
    </div>
    <div class="col-md-8">
      <div class="card shadow">
        <div class="card-body">
          <?php $clsM = ($cumplGlobalM>=100?'bg-success':($cumplGlobalM>=60?'bg-warning':'bg-danger')); ?>
          <div class="d-flex justify-content-between">
            <div><strong>Cumplimiento global del día ($)</strong></div>
            <div><strong><?= number_format(min(100,$cumplGlobalM),1) ?>%</strong></div>
          </div>
          <div class="progress" style="height:22px">
            <div class="progress-bar <?= $clsM ?>" style="width:<?= min(100,$cumplGlobalM) ?>%"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <ul class="nav nav-tabs mt-4" id="tabsDia">
    <li class="nav-item">
      <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabEjecutivos">
        Ejecutivos / Gerentes 👔
      </button>
    </li>
    <li class="nav-item">
      <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSucursales">
        Sucursales 🏢
      </button>
    </li>
  </ul>

  <div class="tab-content">
    <div class="tab-pane fade show active" id="tabEjecutivos">
      <div class="card shadow mt-3">
        <div class="card-header bg-dark text-white">
          Ranking del Día (<?= date('d/m/Y', strtotime($fecha)) ?>)
          <?php if($isSubdisUser && $ID_SUBDIS>0): ?>
            <span class="badge bg-info text-dark ms-2">Subdis #<?= (int)$ID_SUBDIS ?></span>
          <?php endif; ?>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive-sm">
            <table class="table table-striped table-bordered table-sm align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Nombre</th>
                  <th class="suc-col">Sucursal</th>
                  <th class="col-uds">Uds</th>
                  <th class="d-none d-sm-table-cell">Ventas $</th>
                  <th class="d-none d-sm-table-cell">Tickets</th>
                  <th class="col-qta">Qta</th>
                  <th class="col-cumpl">% </th>
                  <th class="d-none d-sm-table-cell">Progreso</th>
                </tr>
              </thead>
              <tbody>
                <?php
                  $renderPeopleRows = function(array $rows){
                    foreach ($rows as $e) {
                      $cuotaDiaU = (float)$e['cuota_diaria_ejecutivo'];
                      $cumpl     = (float)$e['cumplimiento'];
                      $fila      = $cumpl>=100 ? 'table-success' : ($cumpl>=60 ? 'table-warning' : 'table-danger');
                      $cls       = $cumpl>=100 ? 'bg-success' : ($cumpl>=60 ? 'bg-warning' : 'bg-danger');
                      ?>
                      <tr class="<?= $fila ?>">
                        <td class="person-name" title="<?= h($e['nombre']) ?>"><?= h($e['nombre']) ?></td>
                        <td class="suc-col" title="<?= h($e['sucursal']) ?>"><?= h($e['sucursal']) ?></td>
                        <td class="num col-uds"><?= (int)$e['unidades_validas'] ?></td>
                        <td class="d-none d-sm-table-cell num">$<?= number_format((float)$e['ventas_validas'],2) ?></td>
                        <td class="d-none d-sm-table-cell num"><?= (int)$e['tickets'] ?></td>
                        <td class="num col-qta"><?= number_format($cuotaDiaU,2) ?></td>
                        <td class="num col-cumpl"><?= number_format($cumpl,1) ?>%</td>
                        <td class="d-none d-sm-table-cell" style="min-width:160px">
                          <div class="progress">
                            <div class="progress-bar <?= $cls ?>" style="width:<?= min(100,$cumpl) ?>%"></div>
                          </div>
                        </td>
                      </tr>
                      <?php
                    }
                  };

                  $hayDatosTabla = (!empty($ejecutivos) || !empty($gerentes));

                  if (!empty($ejecutivos)) {
                    echo '<tr class="table-secondary"><td colspan="8"><strong>Ejecutivos</strong></td></tr>';
                    $renderPeopleRows($ejecutivos);
                  }

                  if (!empty($gerentes)) {
                    echo '<tr class="table-secondary"><td colspan="8"><strong>Gerentes</strong></td></tr>';
                    $renderPeopleRows($gerentes);
                  }

                  if (!$hayDatosTabla) {
                    echo '<tr><td colspan="8" class="text-center py-3 text-muted">Sin datos para la fecha seleccionada.</td></tr>';
                  }
                ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="tab-pane fade" id="tabSucursales">
      <div class="card shadow mt-3">
        <div class="card-header bg-dark text-white">
          Ranking de Sucursales (<?= date('d/m/Y', strtotime($fecha)) ?>)
          <?php if($isSubdisUser && $ID_SUBDIS>0): ?>
            <span class="badge bg-info text-dark ms-2">Subdis #<?= (int)$ID_SUBDIS ?></span>
          <?php endif; ?>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive-sm">
            <table class="table table-striped table-bordered table-sm align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Sucursal</th>
                  <th class="d-none d-sm-table-cell">Zona</th>
                  <th class="d-none d-sm-table-cell">Uds</th>
                  <th class="w-120">Ventas $</th>
                  <th>Qta $</th>
                  <th>%</th>
                  <th class="d-none d-sm-table-cell">Progreso</th>
                </tr>
              </thead>
              <tbody>
                <?php
                  $renderRows = function(array $rows){
                    foreach ($rows as $s) {
                      $cumpl = (float)$s['cumplimiento_monto'];
                      $fila  = $cumpl>=100 ? 'table-success' : ($cumpl>=60 ? 'table-warning' : 'table-danger');
                      $cls   = $cumpl>=100 ? 'bg-success' : ($cumpl>=60 ? 'bg-warning' : 'bg-danger');
                      $zonaTxt = normalizarZonaDia($s['zona'] ?? '');
                      echo '<tr class="'.$fila.'">';
                      echo '<td class="suc-name" title="'.h($s['sucursal']).'">'.h($s['sucursal']).'</td>';
                      echo '<td class="d-none d-sm-table-cell">'.($zonaTxt !== 'Sin zona' ? h($zonaTxt) : '<span class="text-muted">—</span>').'</td>';
                      echo '<td class="d-none d-sm-table-cell num">'.(int)$s['unidades_validas'].'</td>';
                      echo '<td class="num">$'.number_format((float)$s['ventas_validas'],2).'</td>';
                      echo '<td class="num">$'.number_format((float)$s['cuota_diaria_monto'],2).'</td>';
                      echo '<td class="num">'.number_format($cumpl,1).'%</td>';
                      echo '<td class="d-none d-sm-table-cell" style="min-width:160px"><div class="progress"><div class="progress-bar '.$cls.'" style="width:'.min(100,$cumpl).'%"></div></div></td>';
                      echo '</tr>';
                    }
                  };

                  if ($isSubdisUser) {
                    $zonasDia = agruparSucursalesPorZonaDia($sucursales);

                    if (!empty($zonasDia)) {
                      foreach ($zonasDia as $zonaNombre => $rowsZona) {
                        echo '<tr class="table-secondary"><td colspan="7"><strong>'.h($zonaNombre).'</strong></td></tr>';
                        $renderRows($rowsZona);
                      }
                    } else {
                      echo '<tr><td colspan="7" class="text-center py-3 text-muted">Sin datos para la fecha seleccionada.</td></tr>';
                    }
                  } else {
                    $hayDatos = false;

                    if (!empty($lugaSucRows)) {
                      $zonasLuga = agruparSucursalesPorZonaDia($lugaSucRows);
                      echo '<tr class="table-secondary"><td colspan="7"><strong>Tiendas LUGA</strong></td></tr>';
                      foreach ($zonasLuga as $zonaNombre => $rowsZona) {
                        echo '<tr class="table-light"><td colspan="7"><strong>'.h($zonaNombre).'</strong></td></tr>';
                        $renderRows($rowsZona);
                      }
                      $hayDatos = true;
                    }

                    foreach ($subdisGroups as $g) {
                      echo '<tr class="table-secondary"><td colspan="7"><strong>Subdistribuidor: '.h($g['nombre']).'</strong></td></tr>';
                      $zonasSub = agruparSucursalesPorZonaDia($g['rows']);
                      foreach ($zonasSub as $zonaNombre => $rowsZona) {
                        echo '<tr class="table-light"><td colspan="7"><strong>'.h($zonaNombre).'</strong></td></tr>';
                        $renderRows($rowsZona);
                      }
                      $hayDatos = true;
                    }

                    if (!$hayDatos) {
                      echo '<tr><td colspan="7" class="text-center py-3 text-muted">Sin datos para la fecha seleccionada.</td></tr>';
                    }
                  }
                ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

</body>
</html>
