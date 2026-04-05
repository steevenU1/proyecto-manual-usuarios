<?php
/* =========================================================
   Historial de Ventas SIM — botón Switch (Prepago = !Pospago)
   - Semana Mar→Lun estable con TZ MX
   - Filtros GET robustos (tipo_venta, usuario, buscar)
   - Export semanal y mensual arrastran exactamente los GET
   - Ejecutivo convierte sus ventas Prepago (no Pospago) a Portabilidad
   - ✅ Multi-tenant (Luga/Subdis) + roles subdis_*:
     subdis_admin: todas su red
     subdis_gerente: su sucursal
     subdis_ejecutivo: sus ventas
========================================================= */

session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

/* --- TZ antes de cualquier DateTime --- */
date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/db.php';

/* ===== CSRF token ===== */
if (empty($_SESSION['csrf_sim'])) {
    $_SESSION['csrf_sim'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_sim'];

/* ========================
   HELPERS
======================== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function get_get(string $key, $default = '') {
    return isset($_GET[$key]) ? $_GET[$key] : $default;
}
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
/** Semana Mar→Lun por índice (0 = actual) */
function obtenerSemanaPorIndice(int $offset = 0): array {
    $hoy = new DateTimeImmutable('now');
    $diaSemana = (int)$hoy->format('N'); // 1=lun...7=dom
    $dif = $diaSemana - 2; // martes=2
    if ($dif < 0) $dif += 7;
    $inicio = $hoy->sub(new DateInterval('P'.$dif.'D'))->setTime(0,0,0);
    if ($offset !== 0) $inicio = $inicio->sub(new DateInterval('P'.(7*max(0,$offset)).'D'));
    $fin = $inicio->add(new DateInterval('P6D'))->setTime(23,59,59);
    return [$inicio,$fin];
}
/* Selects de Mes/Año */
function mesOptionsHtml($mesSel){
    $meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    $html = '';
    foreach($meses as $k=>$v){
        $sel = ($k == (int)$mesSel) ? 'selected' : '';
        $html .= "<option value=\"$k\" $sel>$v</option>";
    }
    return $html;
}
function anioOptionsHtml($anioSel, $rango = 6){
    $min = (int)$anioSel - $rango;
    $max = (int)$anioSel + $rango;
    $html = '';
    for($a=$max;$a>=$min;$a--){
        $sel = ($a == (int)$anioSel) ? 'selected' : '';
        $html .= "<option value=\"$a\" $sel>$a</option>";
    }
    return $html;
}

/* ========================
   TENANT DETECTION (Luga/Subdis)
======================== */
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rolRaw      = $_SESSION['rol'] ?? 'Ejecutivo';
$rol         = trim((string)$rolRaw);
$rolLower    = strtolower($rol);

$colSucSubtipo  = hasColumn($conn, 'sucursales', 'subtipo');
$colSucIdSubdis = hasColumn($conn, 'sucursales', 'id_subdis');

$tenantPropiedad = 'Luga';
$tenantIdSubdis  = 0;
$subtipoSesion   = '';
$nombreSucursalSesion = '';

if ($id_sucursal > 0) {
    if ($colSucSubtipo && $colSucIdSubdis) {
        $stTen = $conn->prepare("SELECT nombre, subtipo, id_subdis FROM sucursales WHERE id=? LIMIT 1");
        $stTen->bind_param("i", $id_sucursal);
    } elseif ($colSucSubtipo) {
        $stTen = $conn->prepare("SELECT nombre, subtipo, 0 AS id_subdis FROM sucursales WHERE id=? LIMIT 1");
        $stTen->bind_param("i", $id_sucursal);
    } else {
        $stTen = $conn->prepare("SELECT nombre, '' AS subtipo, 0 AS id_subdis FROM sucursales WHERE id=? LIMIT 1");
        $stTen->bind_param("i", $id_sucursal);
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

// fallback por rol
if ($tenantPropiedad === 'Luga' && strpos($rolLower, 'subdis') !== false) {
    $tenantPropiedad = 'Subdis';
    $tenantIdSubdis  = (int)($_SESSION['id_subdis'] ?? $tenantIdSubdis);
}
$esSubdis = ($tenantPropiedad === 'Subdis');

/* ✅ Admin Luga (solo este rol verá "Exportar Histórico") */
$esAdminLuga = (strcasecmp($rol, 'Admin') === 0);

/* ========================
   FILTROS BASE (SEMANA)
======================== */
$semanaSeleccionada = (int)get_get('semana', 0);
list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semanaSeleccionada);
$inicioSemana = $inicioSemanaObj->format('Y-m-d');
$finSemana    = $finSemanaObj->format('Y-m-d');

/* Flag: ¿es la semana actual? (solo aquí se muestra el botón Switch) */
$esSemanaActual = ($semanaSeleccionada === 0);

$idUsuarioSesion = (int)($_SESSION['id_usuario'] ?? 0);

/* Ejecutivo (incluye subdis_ejecutivo) */
$esEjecutivo = (strcasecmp($rol, 'Ejecutivo') === 0) || ($rolLower === 'subdis_ejecutivo');

/* Mes/Año para export mensual */
$mesDefault  = (int)get_get('mes',  (int)date('n'));
$anioDefault = (int)get_get('anio', (int)date('Y'));

/* =========================================================
   Combo de usuarios (activos + inactivos con ventas en semana)
   ✅ Tenant-safe
========================================================= */
$activeExprs = [];
if (hasColumn($conn, 'usuarios', 'activo'))        $activeExprs[] = 'u.activo = 1';
if (hasColumn($conn, 'usuarios', 'estatus'))       $activeExprs[] = "LOWER(u.estatus) IN ('activo','activa','alta')";
if (hasColumn($conn, 'usuarios', 'fecha_baja'))    $activeExprs[] = "(u.fecha_baja IS NULL OR u.fecha_baja='0000-00-00')";
$activeExprSql = !empty($activeExprs) ? '(' . implode(' OR ', $activeExprs) . ')' : '1=1';

/*
  Rol:
  - subdis_admin: puede ver usuarios de toda su red (subdis)
  - subdis_gerente/gerente/ejecutivo/subdis_ejecutivo: solo su sucursal
  - admin (luga): aquí lo dejamos como antes (su sucursal); si luego quieres global, lo ampliamos
*/
$rolEsSubdisAdmin   = ($rolLower === 'subdis_admin');
$rolEsSubdisGerente = ($rolLower === 'subdis_gerente');

$joinSucU = " INNER JOIN sucursales su ON su.id = u.id_sucursal ";
$tenantWhereU = " 1=1 ";
if ($esSubdis) {
    if ($colSucSubtipo)  $tenantWhereU .= " AND su.subtipo='Subdistribuidor' ";
    if ($colSucIdSubdis && $tenantIdSubdis > 0) $tenantWhereU .= " AND su.id_subdis=".(int)$tenantIdSubdis." ";
} else {
    if ($colSucSubtipo)  $tenantWhereU .= " AND (su.subtipo IS NULL OR su.subtipo='' OR su.subtipo<>'Subdistribuidor') ";
}

$usuariosActivos=[]; $usuariosInactivos=[];

if ($rolEsSubdisAdmin) {
    $sqlUsuarios = "
        SELECT u.id, u.nombre,
               CASE WHEN {$activeExprSql} THEN 0 ELSE 1 END AS es_inactivo
        FROM usuarios u
        {$joinSucU}
        LEFT JOIN (
            SELECT id_usuario, COUNT(*) AS cnt
            FROM ventas_sims
            WHERE DATE(fecha_venta) BETWEEN ? AND ?
            GROUP BY id_usuario
        ) vsw ON vsw.id_usuario = u.id
        WHERE {$tenantWhereU}
          AND ( {$activeExprSql} OR vsw.cnt > 0 )
        ORDER BY es_inactivo ASC, u.nombre ASC
    ";
    $stmtUsuarios = $conn->prepare($sqlUsuarios);
    $stmtUsuarios->bind_param("ss", $inicioSemana, $finSemana);
} else {
    $sqlUsuarios = "
        SELECT u.id, u.nombre,
               CASE WHEN {$activeExprSql} THEN 0 ELSE 1 END AS es_inactivo
        FROM usuarios u
        {$joinSucU}
        LEFT JOIN (
            SELECT id_usuario, COUNT(*) AS cnt
            FROM ventas_sims
            WHERE DATE(fecha_venta) BETWEEN ? AND ?
            GROUP BY id_usuario
        ) vsw ON vsw.id_usuario = u.id
        WHERE {$tenantWhereU}
          AND u.id_sucursal = ?
          AND ( {$activeExprSql} OR vsw.cnt > 0 )
        ORDER BY es_inactivo ASC, u.nombre ASC
    ";
    $stmtUsuarios = $conn->prepare($sqlUsuarios);
    $stmtUsuarios->bind_param("ssi", $inicioSemana, $finSemana, $id_sucursal);
}

$stmtUsuarios->execute();
$resUsuarios = $stmtUsuarios->get_result();
while ($row = $resUsuarios->fetch_assoc()) {
    if ((int)$row['es_inactivo'] === 1) $usuariosInactivos[] = $row; else $usuariosActivos[] = $row;
}
$stmtUsuarios->close();

/* ========================
   WHERE base ventas (tenant + roles)
======================== */
$where  = " WHERE DATE(vs.fecha_venta) BETWEEN ? AND ?";
$params = [$inicioSemana, $finSemana];
$types  = "ss";

/* Tenant gate para ventas_sims (si existen columnas, úsala; si no, filtra por sucursal join) */
$colVsProp = hasColumn($conn, 'ventas_sims', 'propiedad');
$colVsSub  = hasColumn($conn, 'ventas_sims', 'id_subdis');

if ($esSubdis) {
    if ($colVsProp) {
        $where .= " AND vs.propiedad='Subdis' ";
    }
    if ($colVsSub) {
        $where .= " AND vs.id_subdis=? ";
        $params[] = $tenantIdSubdis;
        $types   .= "i";
    } else {
        // fallback por sucursal (requiere join s ya existe en query)
        if ($colSucSubtipo)  $where .= " AND s.subtipo='Subdistribuidor' ";
        if ($colSucIdSubdis && $tenantIdSubdis > 0) { $where .= " AND s.id_subdis=? "; $params[]=$tenantIdSubdis; $types.="i"; }
    }
} else {
    if ($colVsProp) {
        $where .= " AND (vs.propiedad='Luga' OR vs.propiedad IS NULL OR vs.propiedad='') ";
    }
    if ($colVsSub) {
        $where .= " AND (vs.id_subdis IS NULL OR vs.id_subdis=0) ";
    } else {
        if ($colSucSubtipo) $where .= " AND (s.subtipo IS NULL OR s.subtipo='' OR s.subtipo<>'Subdistribuidor') ";
    }
}

/* Rol */
if ($rolLower === 'subdis_ejecutivo' || strcasecmp($rol, 'Ejecutivo') === 0) {
    $where   .= " AND vs.id_usuario=?";
    $params[] = $idUsuarioSesion;
    $types   .= "i";
} elseif ($rolLower === 'subdis_gerente' || strcasecmp($rol, 'Gerente') === 0) {
    $where   .= " AND vs.id_sucursal=?";
    $params[] = $id_sucursal;
    $types   .= "i";
} else {
    // subdis_admin y admin normal: sin filtro extra (ya queda por tenant y por filtros GET)
}

/* Filtros GET */
$tipoVenta = trim((string)get_get('tipo_venta', ''));
if ($tipoVenta !== '') { $where.=" AND vs.tipo_venta=?"; $params[]=$tipoVenta; $types.="s"; }

$usuarioSel = (string)get_get('usuario', '');
if ($usuarioSel !== '') { $where.=" AND vs.id_usuario=?"; $params[]=(int)$usuarioSel; $types.="i"; }

$buscar = trim((string)get_get('buscar', ''));
if ($buscar !== '') {
    $where .= " AND (vs.nombre_cliente LIKE ? OR EXISTS(
                SELECT 1 FROM detalle_venta_sims d2
                LEFT JOIN inventario_sims i2 ON d2.id_sim = i2.id
                WHERE d2.id_venta = vs.id AND i2.iccid LIKE ?
            ))";
    $busqLike="%".$buscar."%"; $params[]=$busqLike; $params[]=$busqLike; $types.="ss";
}

/* ========================
   CONSULTA HISTORIAL
======================== */
$selTipoPlan = hasColumn($conn, 'inventario_sims', 'tipo_plan')
    ? "i.tipo_plan AS tipo_plan_inv"
    : "NULL AS tipo_plan_inv";

$sqlVentas = "
    SELECT
        vs.id, vs.tipo_venta, vs.modalidad, vs.precio_total,
        vs.comision_ejecutivo, vs.comision_gerente,
        vs.fecha_venta, vs.comentarios, vs.id_usuario,
        vs.nombre_cliente, vs.es_esim, vs.tipo_sim,
        u.nombre AS usuario, s.nombre AS sucursal,
        i.iccid, i.operador AS operador_inv,
        $selTipoPlan
    FROM ventas_sims vs
    INNER JOIN usuarios   u ON vs.id_usuario  = u.id
    INNER JOIN sucursales s ON vs.id_sucursal = s.id
    LEFT JOIN detalle_venta_sims d ON vs.id   = d.id_venta
    LEFT JOIN inventario_sims    i ON d.id_sim = i.id
    $where
    ORDER BY vs.fecha_venta DESC
";
$stmt = $conn->prepare($sqlVentas);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$ventas = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ========================
   RESÚMENES
======================== */
$totalVentas   = count($ventas);
$sumPrecio = $sumComEje = $sumComGer = 0.0;
$cntEsim = $cntFisicas = 0;
$tipoCounts = ['Nueva'=>0,'Portabilidad'=>0,'Regalo'=>0,'Pospago'=>0];
foreach ($ventas as $v) {
  $sumPrecio += (float)$v['precio_total'];
  $sumComEje += (float)$v['comision_ejecutivo'];
  $sumComGer += (float)$v['comision_gerente'];
  if ((int)($v['es_esim'] ?? 0) === 1) $cntEsim++; else $cntFisicas++;
  $tipo = $v['tipo_venta'] ?? '';
  if (isset($tipoCounts[$tipo])) $tipoCounts[$tipo]++;
}
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Historial de Ventas SIM</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{ --surface:#ffffff; --muted:#6b7280; }
    body{ background:#f6f7fb; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; }
    .page-title{ font-weight:700; letter-spacing:.2px; margin:0; }
    .small-muted{ color:var(--muted); font-size:.92rem; }
    .card-surface{ background:var(--surface); border:1px solid rgba(0,0,0,.05); box-shadow:0 6px 16px rgba(16,24,40,.06); border-radius:18px; }
    .chip{ display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .6rem; border-radius:999px; font-weight:600; font-size:.85rem; border:1px solid transparent; }
    .chip-info{ background:#e8f0fe; color:#1a56db; border-color:#cbd8ff; }
    .chip-success{ background:#e7f8ef; color:#0f7a3d; border-color:#b7f1cf; }
    .chip-purple{ background:#f3e8ff; color:#6d28d9; border-color:#e9d5ff; }
    .filters .form-control, .filters .form-select{ height:42px; }
    .btn-soft{ border:1px solid rgba(0,0,0,.08); background:#fff; }
    .btn-soft:hover{ background:#f9fafb; }
    .tbl-wrap{ overflow:auto; border-radius:14px; }
    .table thead th{ position:sticky; top:0; z-index:1; }
  </style>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container py-3">

  <!-- Encabezado -->
  <div class="page-header">
    <div>
      <h1 class="page-title">📶 Historial de Ventas SIM</h1>
      <div class="small-muted">
        Usuario: <strong><?= h($_SESSION['nombre']) ?></strong>
        · Semana <strong><?= $inicioSemanaObj->format('d/m/Y') ?> – <?= $finSemanaObj->format('d/m/Y') ?></strong>
        · Tenant: <strong><?= h($tenantPropiedad) ?><?= $esSubdis ? ' #'.(int)$tenantIdSubdis : '' ?></strong>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="chip chip-info"><i class="bi bi-receipt"></i> Ventas: <?= (int)$totalVentas ?></span>
      <span class="chip chip-success"><i class="bi bi-currency-dollar"></i> Monto: $<?= number_format($sumPrecio,2) ?></span>
      <span class="chip chip-purple"><i class="bi bi-sim"></i> eSIM: <?= (int)$cntEsim ?></span>
      <span class="chip chip-purple"><i class="bi bi-sim-fill"></i> Físicas: <?= (int)$cntFisicas ?></span>
    </div>
  </div>

  <!-- Mensajes flash -->
  <?php if (!empty($_SESSION['flash_ok'])): ?>
    <div class="alert alert-success"><?= h($_SESSION['flash_ok']); unset($_SESSION['flash_ok']); ?></div>
  <?php endif; ?>
  <?php if (!empty($_SESSION['flash_error'])): ?>
    <div class="alert alert-danger"><?= h($_SESSION['flash_error']); unset($_SESSION['flash_error']); ?></div>
  <?php endif; ?>

  <!-- Chips por tipo de venta -->
  <div class="d-flex flex-wrap gap-2 mt-2">
    <span class="chip chip-info"><i class="bi bi-cash-coin"></i> Nueva: <?= (int)$tipoCounts['Nueva'] ?></span>
    <span class="chip chip-info"><i class="bi bi-arrow-left-right"></i> Portabilidad: <?= (int)$tipoCounts['Portabilidad'] ?></span>
    <span class="chip chip-info"><i class="bi bi-gift"></i> Regalo: <?= (int)$tipoCounts['Regalo'] ?></span>
    <span class="chip chip-info"><i class="bi bi-bank"></i> Pospago: <?= (int)$tipoCounts['Pospago'] ?></span>
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
          <?php for ($i=0;$i<8;$i++): list($ini,$fin)=obtenerSemanaPorIndice($i); $texto="Del {$ini->format('d/m/Y')} al {$fin->format('d/m/Y')}"; ?>
            <option value="<?= $i ?>" <?= $i==$semanaSeleccionada?'selected':'' ?>><?= $texto ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="small-muted">Tipo de venta</label>
        <select name="tipo_venta" class="form-select">
          <?php $tv=(string)get_get('tipo_venta',''); ?>
          <option value="">Todas</option>
          <option value="Nueva"         <?= $tv==='Nueva'?'selected':'' ?>>Nueva</option>
          <option value="Portabilidad"  <?= $tv==='Portabilidad'?'selected':'' ?>>Portabilidad</option>
          <option value="Regalo"        <?= $tv==='Regalo'?'selected':'' ?>>Regalo</option>
          <option value="Pospago"       <?= $tv==='Pospago'?'selected':'' ?>>Pospago</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="small-muted">Usuario</label>
        <select name="usuario" class="form-select">
          <?php $uSel=(string)get_get('usuario',''); ?>
          <option value="">Todos</option>
          <?php if ($usuariosActivos): ?><optgroup label="Activos">
            <?php foreach($usuariosActivos as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= ($uSel!=='' && (int)$uSel===(int)$u['id'])?'selected':'' ?>><?= h($u['nombre']) ?></option>
            <?php endforeach; ?>
          </optgroup><?php endif; ?>
          <?php if ($usuariosInactivos): ?><optgroup label="Inactivos (con ventas en semana)">
            <?php foreach($usuariosInactivos as $u): ?>
              <option value="<?= (int)$u['id'] ?>" <?= ($uSel!=='' && (int)$uSel===(int)$u['id'])?'selected':'' ?>><?= h($u['nombre']) ?> (inactivo)</option>
            <?php endforeach; ?>
          </optgroup><?php endif; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="small-muted">Buscar (ICCID o Cliente)</label>
        <input id="searchInput" name="buscar" type="text" class="form-control" value="<?= h(get_get('buscar','')) ?>" placeholder="Ej. 8952..., Juan Pérez">
      </div>
    </div>

    <div class="mt-3 d-flex justify-content-end gap-2 flex-wrap">
      <div class="d-flex align-items-center gap-2 me-auto">
        <label class="small-muted mb-0">Mes/Año para export mensual:</label>
        <select name="mes" class="form-select form-select-sm" style="width:auto;"><?= mesOptionsHtml($mesDefault) ?></select>
        <select name="anio" class="form-select form-select-sm" style="width:auto;"><?= anioOptionsHtml($anioDefault, 6) ?></select>
      </div>

      <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
      <a href="historial_ventas_sims.php" class="btn btn-secondary">Limpiar</a>

      <button type="submit" class="btn btn-success" formaction="exportar_excel_sims.php" formmethod="GET" formtarget="_blank" title="Exporta SEMANAL con los filtros actuales">
        <i class="bi bi-file-earmark-excel"></i> Exportar Semanal
      </button>
      <button type="submit" class="btn btn-outline-success" formaction="exportar_excel_sims_mensual.php" formmethod="GET" formtarget="_blank" title="Exporta MENSUAL (usa Mes/Año + filtros actuales)">
        <i class="bi bi-file-earmark-spreadsheet"></i> Exportar Mensual
      </button>

      <?php if ($esAdminLuga): ?>
        <button type="submit"
                class="btn btn-outline-dark"
                formaction="export_sims_pospago_historico.php"
                formmethod="GET"
                formtarget="_blank"
                title="Exporta HISTÓRICO (junta todo, respeta filtros GET)">
          <i class="bi bi-clock-history"></i> Exportar Histórico
        </button>
      <?php endif; ?>
    </div>
  </form>

  <!-- Historial -->
  <?php if ($totalVentas === 0): ?>
    <div class="alert alert-info card-surface mt-3 mb-0">
      <i class="bi bi-info-circle me-1"></i>No hay ventas de SIM para los filtros seleccionados.
    </div>
  <?php else: ?>
    <div class="card card-surface mt-3">
      <div class="p-3 pb-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="m-0"><i class="bi bi-table me-2"></i>Listado</h5>
        <div class="small-muted">Tip: el campo de búsqueda también filtra en vivo esta tabla.</div>
      </div>
      <div class="p-3 pt-2 tbl-wrap">
        <table id="tablaSims" class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>ID</th>
              <th>Fecha</th>
              <th>Sucursal</th>
              <th>Usuario</th>
              <th>Cliente</th>
              <th>ICCID / Tipo</th>
              <th>Operador</th>
              <th>Tipo Venta</th>
              <th>Modalidad</th>
              <th>Precio</th>
              <th>Com. Ejecutivo</th>
              <th>Com. Gerente</th>
              <th>Comentarios</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ventas as $v): ?>
              <?php
                $isEsim     = (int)($v['es_esim'] ?? 0) === 1;
                $tipoIcon   = $isEsim ? 'bi-sim' : 'bi-sim-fill';
                $operadorInv= trim((string)($v['operador_inv'] ?? ''));
                $operadorVs = trim((string)($v['tipo_sim'] ?? ''));
                $operador   = $operadorInv !== '' ? $operadorInv : $operadorVs;

                $ventaTipo  = (string)$v['tipo_venta'];
                $esPropia   = ((int)$v['id_usuario'] === (int)($idUsuarioSesion));

                // Prepago por regla de negocio: si NO es Pospago => es Prepago
                $esPrepago  = ($ventaTipo !== 'Pospago');
                $esPospago  = ($ventaTipo === 'Pospago');

                /* Botón Switch:
                   - Semana actual
                   - Rol Ejecutivo (incluye subdis_ejecutivo)
                   - Venta propia
                   - Prepago (no Pospago)
                   - Debe estar en estado 'Nueva' (origen del cambio)
                */
                $puedeSwitch =
                    $esSemanaActual &&
                    $esEjecutivo &&
                    $esPropia &&
                    $esPrepago &&
                    ($ventaTipo === 'Nueva');

                // Eliminar: solo Admin (subdis_admin o Admin)
                $puedeEliminar = (strtolower($_SESSION['rol'] ?? '') === 'admin' || strtolower($_SESSION['rol'] ?? '') === 'subdis_admin');
              ?>
              <tr>
                <td><span class="badge text-bg-secondary">#<?= (int)$v['id'] ?></span></td>
                <td><?= h($v['fecha_venta']) ?></td>
                <td><?= h($v['sucursal']) ?></td>
                <td><?= h($v['usuario']) ?></td>
                <td><?= h($v['nombre_cliente'] ?? '') ?></td>
                <td>
                  <span class="chip chip-purple"><i class="bi <?= $tipoIcon ?>"></i> <?= $isEsim ? 'eSIM' : h($v['iccid']) ?></span>
                  <?php if (!empty($v['tipo_plan_inv'])): ?>
                    <span class="badge text-bg-light border ms-1"><?= h($v['tipo_plan_inv']) ?></span>
                  <?php endif; ?>
                </td>
                <td><span class="chip chip-info"><i class="bi bi-broadcast-pin"></i> <?= $operador !== '' ? h($operador) : '—' ?></span></td>
                <td><?= h($ventaTipo) ?></td>

                <!-- Mostrar modalidad SOLO en Pospago -->
                <td><?= $esPospago ? h($v['modalidad'] ?? '') : '' ?></td>

                <td class="fw-semibold">$<?= number_format((float)$v['precio_total'],2) ?></td>
                <td>$<?= number_format((float)$v['comision_ejecutivo'],2) ?></td>
                <td>$<?= number_format((float)$v['comision_gerente'],2) ?></td>
                <td><?= h($v['comentarios'] ?? '') ?></td>
                <td class="text-nowrap">
                  <?php if ($puedeSwitch): ?>
                    <form class="d-inline" method="POST" action="switch_venta_sim.php">
                      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                      <input type="hidden" name="id_venta" value="<?= (int)$v['id'] ?>">
                      <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                      <button type="submit" class="btn btn-outline-primary btn-sm"
                              onclick="return confirm('¿Convertir esta venta a PORTABILIDAD y dejar comisión en $0.00?');">
                        <i class="bi bi-arrow-left-right"></i> Switch
                      </button>
                    </form>
                  <?php endif; ?>

                  <?php if ($puedeEliminar): ?>
                    <button class="btn btn-outline-danger btn-sm"
                            data-bs-toggle="modal" data-bs-target="#confirmEliminarSim"
                            data-idventa="<?= (int)$v['id'] ?>">
                      <i class="bi bi-trash"></i> Eliminar
                    </button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>

<!-- Modal de confirmación de eliminación -->
<div class="modal fade" id="confirmEliminarSim" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form action="eliminar_venta_sim.php" method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Confirmar eliminación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id_venta" id="modalIdVentaSim">
        <p class="mb-0">¿Seguro que deseas eliminar esta venta de SIM?<br>
        <small class="text-muted">Esto devolverá la SIM al inventario (si aplica) y quitará la comisión asociada.</small></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-danger">Eliminar</button>
      </div>
    </form>
  </div>
</div>

<script>
// Modal: setear id_venta (eliminación)
const modalSim = document.getElementById('confirmEliminarSim');
modalSim?.addEventListener('show.bs.modal', (ev) => {
  const id = ev.relatedTarget?.getAttribute('data-idventa') || '';
  document.getElementById('modalIdVentaSim').value = id;
});

// Búsqueda rápida
(function(){
  const input = document.getElementById('searchInput');
  const rows  = Array.from(document.querySelectorAll('#tablaSims tbody tr'));
  const norm  = s => (s||'').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu,'');
  function filtra(){
    const q = norm(input?.value || '');
    rows.forEach(tr => { tr.style.display = (!q || norm(tr.innerText).includes(q)) ? '' : 'none'; });
  }
  input?.addEventListener('input', filtra);
})();
</script>
</body>
</html>