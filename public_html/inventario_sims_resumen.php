<?php
// inventario_sims_resumen.php — Central 2.0 (UI buscador PRO + Búsqueda global por caja + Localizador FIX + Logística con alcance global)
// - Caja (select) = coincidencia exacta (requiere sucursal concreta).
// - Caja (texto, caja_q) = coincidencia parcial por LIKE y funciona también en Global sin elegir sucursal.
// - Localizador GLOBAL (cuando Admin/Logistica no eligen sucursal y escriben caja_q):
//     * IGNORA estatus, operador, plan y q; busca únicamente por caja y te dice en QUÉ SUCURSAL está.
//     * Coincidencia EXACTA si caja_q es “limpia” ([A-Za-z0-9_-]+); si no, LIKE.
// - KPIs/Cards/Detalle/Export se mantienen como estaban (filtran por estatus='Disponible').

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

$ROL         = $_SESSION['rol'] ?? 'Ejecutivo';
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function selectOptions(array $opts, $sel){
  $html=''; foreach($opts as $val=>$txt){
    $selAttr = ((string)$val === (string)$sel) ? ' selected' : '';
    $html.='<option value="'.h($val).'"'.$selAttr.'>'.h($txt).'</option>';
  } return $html;
}

/* 🔐 Privilegios: Admin o Logistica tienen alcance “global” */
function isPrivileged($rol){ return in_array($rol, ['Admin','Logistica'], true); }

/** Detecta la columna real usada para “caja” en inventario_sims. */
function detectarColCaja(mysqli $conn): string {
  foreach (['caja_id','id_caja','caja'] as $col) {
    $colEsc = $conn->real_escape_string($col);
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'inventario_sims'
              AND COLUMN_NAME = '$colEsc' LIMIT 1";
    $q = $conn->query($sql);
    if ($q && $q->fetch_row()){ return $col; }
  }
  return 'caja_id'; // fallback
}
/** Condición de “caja no vacía” (ignora NULL, '' y '0'). */
function condCajaNoVacia(string $expr): string {
  return "NULLIF(TRIM($expr),'') IS NOT NULL AND TRIM($expr) <> '0'";
}

/* ===== Parámetros / filtros ===== */
$scope        = isPrivileged($ROL) ? ($_GET['scope'] ?? 'global') : 'sucursal';
$selSucursal  = isPrivileged($ROL) ? (int)($_GET['sucursal'] ?? 0) : $ID_SUCURSAL; // 0 = todas (solo Admin/Logistica)
$operador     = $_GET['operador']  ?? 'ALL';
$tipoPlan     = $_GET['tipo_plan'] ?? 'ALL';
$q            = trim((string)($_GET['q'] ?? ''));

// Select de caja (exacto; útil con sucursal concreta)
$cajaRaw = $_GET['caja'] ?? '0';
$caja    = (string)(is_array($cajaRaw) ? '0' : trim((string)$cajaRaw));
if ($caja === '') $caja = '0';

// Búsqueda por texto de caja (global o sucursal)
$caja_q = trim((string)($_GET['caja_q'] ?? ''));
$useCajaLike = ($caja_q !== '');

/* Forzar reglas de alcance para quienes NO son Admin/Logistica */
if (!isPrivileged($ROL)) { $scope='sucursal'; $selSucursal=$ID_SUCURSAL; }
if ($scope!=='global') { $scope='sucursal'; }

$CAJA_COL = detectarColCaja($conn);
$CAJA_TR  = "TRIM(i.$CAJA_COL)";
$haySucursalConcreta = ($scope==='sucursal') || ($scope==='global' && $selSucursal>0);

/* ===== WHERE base (para módulos que sí filtran por Disponible) ===== */
$where = ["i.estatus='Disponible'"];
$params=[]; $types='';

if ($operador!=='ALL'){ $where[]="i.operador=?"; $params[]=$operador; $types.='s'; }
if ($tipoPlan!=='ALL'){ $where[]="i.tipo_plan=?"; $params[]=$tipoPlan; $types.='s'; }
if ($q!==''){
  $where[]="(i.iccid LIKE ? OR i.dn LIKE ?)";
  $like="%$q%"; $params[]=$like; $params[]=$like; $types.='ss';
}
$whereSql = $where ? 'WHERE '.implode(' AND ',$where) : '';

/* ===== Sucursales (Admin/Logistica) ===== */
$sucursales=[];
if (isPrivileged($ROL)) {
  $rs = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
  $sucursales = $rs->fetch_all(MYSQLI_ASSOC);
}

/* ===== Cajas del select (solo cuando hay sucursal concreta) ===== */
$cajas = [];
if ($haySucursalConcreta) {
  $sqlCajas = "
    SELECT DISTINCT TRIM(i.$CAJA_COL) AS caja_val,
           CAST(TRIM(i.$CAJA_COL) AS UNSIGNED) AS caja_num
    FROM inventario_sims i
    WHERE i.estatus='Disponible'
      AND i.id_sucursal = ?
      AND ".condCajaNoVacia("i.$CAJA_COL")."
    ORDER BY caja_num, caja_val
  ";
  $st = $conn->prepare($sqlCajas);
  $st->bind_param('i', $selSucursal);
  $st->execute();
  $res = $st->get_result();
  while($r = $res->fetch_assoc()){
    $val = (string)$r['caja_val'];
    $cajas[$val] = $val;
  }
  $st->close();
}

/* ===== KPIs ===== */
function kpis(mysqli $conn, $whereSql, $params, $types, $scope, $selSucursal, $useCajaLike, $caja_q, $caja, $CAJA_TR){
  $extra=''; $p=$params; $t=$types;

  if ($scope==='sucursal') {
    $extra .= ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?";
    $p[]=$selSucursal; $t.='i';
    if ($useCajaLike) {
      $extra .= " AND $CAJA_TR LIKE ?";
      $p[]="%$caja_q%"; $t.='s';
    } elseif ($caja !== '0') {
      $extra .= " AND $CAJA_TR=?";
      $p[]=$caja; $t.='s';
    }
  } else { // GLOBAL
    if ($selSucursal>0) {
      $extra .= ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?";
      $p[]=$selSucursal; $t.='i';
      if ($useCajaLike) {
        $extra .= " AND $CAJA_TR LIKE ?";
        $p[]="%$caja_q%"; $t.='s';
      } elseif ($caja !== '0') {
        $extra .= " AND $CAJA_TR=?";
        $p[]=$caja; $t.='s';
      }
    } else {
      if ($useCajaLike){
        $extra .= ($whereSql ? " AND " : "WHERE ")."$CAJA_TR LIKE ?";
        $p[]="%$caja_q%"; $t.='s';
      }
    }
  }

  $sql="SELECT COUNT(*) total,
               SUM(i.operador='Bait') bait,
               SUM(i.operador='AT&T') att
        FROM inventario_sims i $whereSql $extra";
  $st=$conn->prepare($sql);
  if ($p){ $st->bind_param($t, ...$p); }
  $st->execute();
  $row=$st->get_result()->fetch_assoc() ?: [];
  return ['total'=>(int)($row['total']??0), 'bait'=>(int)($row['bait']??0), 'att'=>(int)($row['att']??0)];
}
$kpis = kpis($conn,$whereSql,$params,$types,$scope,$selSucursal,$useCajaLike,$caja_q,$caja,$CAJA_TR);

/* ===== Cards por sucursal (GLOBAL) ===== */
$cards=[];
if ($scope==='global'){
  $extra=''; $p=$params; $t=$types;

  if ($selSucursal>0){
    $extra .= ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?";
    $p[]=$selSucursal; $t.='i';
    if ($useCajaLike){
      $extra .= " AND $CAJA_TR LIKE ?";
      $p[]="%$caja_q%"; $t.='s';
    } elseif ($caja !== '0') {
      $extra .= " AND $CAJA_TR=?";
      $p[]=$caja; $t.='s';
    }
  } else {
    if ($useCajaLike){
      $extra .= ($whereSql ? " AND " : "WHERE ")."$CAJA_TR LIKE ?";
      $p[]="%$caja_q%"; $t.='s';
    }
  }

  $sql="SELECT s.id id_suc, s.nombre,
               COUNT(i.id) disponibles,
               SUM(i.operador='Bait') bait,
               SUM(i.operador='AT&T') att
        FROM sucursales s
        LEFT JOIN inventario_sims i ON i.id_sucursal=s.id
        $whereSql $extra
        GROUP BY s.id
        HAVING disponibles > 0
        ORDER BY s.nombre";
  $st=$conn->prepare($sql);
  if ($p){ $st->bind_param($t, ...$p); }
  $st->execute();
  $res=$st->get_result(); while($row=$res->fetch_assoc()){ $cards[]=$row; }
}

/* ===== Detalle (SUCURSAL) ===== */
$detalle=[]; $sucursalNombre='';
if ($scope==='sucursal'){
  $st=$conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
  $st->bind_param('i', $selSucursal); $st->execute();
  $sucursalNombre = (string)($st->get_result()->fetch_column() ?: '');

  $extra = ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?";
  $p=array_merge($params,[$selSucursal]); $t=$types.'i';
  if ($useCajaLike){
    $extra .= " AND $CAJA_TR LIKE ?";
    $p[]="%{$caja_q}%"; $t.='s';
  } elseif ($caja !== '0'){
    $extra .= " AND $CAJA_TR=?";
    $p[]=$caja; $t.='s';
  }
  $sql="SELECT i.id, i.iccid, i.dn, i.operador, i.tipo_plan, i.fecha_ingreso,
               TRIM(i.$CAJA_COL) AS caja_val
        FROM inventario_sims i
        $whereSql $extra
        ORDER BY i.operador, i.tipo_plan, i.iccid";
  $st=$conn->prepare($sql); $st->bind_param($t, ...$p); $st->execute();
  $res=$st->get_result(); while($row=$res->fetch_assoc()){ $detalle[]=$row; }
}

/* ===== EXPORT CSV ===== */
if (isset($_GET['export']) && $_GET['export']==='1') {
  $extraWhere=''; $p=$params; $t=$types;

  if ($scope==='sucursal') {
    $extraWhere .= ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?";
    $p[]=$selSucursal; $t.='i';
    if ($useCajaLike){
      $extraWhere .= " AND $CAJA_TR LIKE ?";
      $p[]="%$caja_q%"; $t.='s';
    } elseif ($caja !== '0'){
      $extraWhere .= " AND $CAJA_TR=?";
      $p[]=$caja; $t.='s';
    }

    $sql = "SELECT s.nombre AS sucursal, i.operador, i.tipo_plan, i.iccid, i.dn,
                   TRIM(i.$CAJA_COL) AS caja_val, i.fecha_ingreso
            FROM inventario_sims i
            LEFT JOIN sucursales s ON s.id=i.id_sucursal
            $whereSql $extraWhere
            ORDER BY i.operador, i.tipo_plan, i.iccid";
  } else {
    if ($selSucursal>0){
      $extraWhere .= ($whereSql ? " AND " : "WHERE ")."i.id_sucursal=?";
      $p[]=$selSucursal; $t.='i';
      if ($useCajaLike){
        $extraWhere .= " AND $CAJA_TR LIKE ?";
        $p[]="%$caja_q%"; $t.='s';
      } elseif ($caja !== '0'){
        $extraWhere .= " AND $CAJA_TR=?";
        $p[]=$caja; $t.='s';
      }
    } else {
      if ($useCajaLike){
        $extraWhere .= ($whereSql ? " AND " : "WHERE ")."$CAJA_TR LIKE ?";
        $p[]="%$caja_q%"; $t.='s';
      }
    }
    $sql = "SELECT s.nombre AS sucursal, i.operador, i.tipo_plan, i.iccid, i.dn,
                   TRIM(i.$CAJA_COL) AS caja_val, i.fecha_ingreso
            FROM inventario_sims i
            LEFT JOIN sucursales s ON s.id=i.id_sucursal
            $whereSql $extraWhere
            ORDER BY s.nombre, i.operador, i.tipo_plan, i.iccid";
  }

  if (ob_get_level()) { while (ob_get_level()) { ob_end_clean(); } }
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="inventario_sims_disponibles.csv"');

  $stmt=$conn->prepare($sql);
  if ($p){ $stmt->bind_param($t, ...$p); }
  $stmt->execute();
  $res=$stmt->get_result();

  $out=fopen('php://output','w');
  fputcsv($out, ['Sucursal','Operador','Tipo Plan','ICCID','DN','Caja','Fecha Ingreso']);
  while($r=$res->fetch_assoc()){
    fputcsv($out, [
      $r['sucursal'] ?? '',
      $r['operador'] ?? '',
      $r['tipo_plan'] ?? '',
      $r['iccid'] ?? '',
      $r['dn'] ?? '',
      $r['caja_val'] ?? '',
      $r['fecha_ingreso'] ?? ''
    ]);
  }
  fclose($out);
  exit;
}

/* ===== Vista ===== */
require_once __DIR__ . '/navbar.php';
?>
<!doctype html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Inventario SIMs — Central 2.0</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    .kpi-card{ border:0; border-radius:1rem; box-shadow:0 6px 20px rgba(0,0,0,.08); }
    .kpi-value{ font-size: clamp(1.8rem, 2.5vw, 2.4rem); font-weight:800; line-height:1; }
    .kpi-sub{ opacity:.75; font-weight:600; }
    .suc-card{ border:0; border-radius:1rem; box-shadow:0 4px 16px rgba(0,0,0,.06); transition:.2s transform; }
    .suc-card:hover{ transform: translateY(-3px); }
    .badge-soft{ background:rgba(13,110,253,.1); color:#0d6efd; }
    .grid{ display:grid; grid-template-columns: repeat(auto-fill, minmax(260px,1fr)); gap:1rem; }
    .sticky-top-lite{ position: sticky; top: .5rem; z-index: 100; }
    .table thead th{ position:sticky; top:0; background:var(--bs-body-bg); z-index:5; }

    /* ======= Buscador PRO ======= */
    .filter-card{ border:0; border-radius:1rem; box-shadow:0 10px 28px rgba(2,8,20,.08); }
    .filter-title{ font-weight:800; letter-spacing:.2px; }
    .label-lite{ font-size:.75rem; text-transform:uppercase; letter-spacing:.06em; color:var(--bs-secondary-color); margin-bottom:.4rem; }
    .input-pill .form-control, .input-pill .form-select{
      border-radius: .8rem; padding-left: 2.25rem;
    }
    .input-icon{ position:relative; }
    .input-icon > i{
      position:absolute; left:.75rem; top:50%; transform:translateY(-50%); opacity:.65;
    }
    .filter-grid{
      display:grid; gap: .85rem 1rem;
      grid-template-columns: repeat(12, 1fr);
      align-items:end;
    }
    @media (min-width: 992px){
      .g-ambito   { grid-column: span 2; }
      .g-sucursal { grid-column: span 5; }
      .g-caja-sel { grid-column: span 3; }
      .g-caja-txt { grid-column: span 4; }
      .g-operador { grid-column: span 2; }
      .g-plan     { grid-column: span 2; }
      .g-buscar   { grid-column: span 4; }
      .g-actions  { grid-column: span 4; justify-self:end; }
    }
    @media (max-width: 991.98px){
      .g-ambito,.g-sucursal,.g-caja-sel,.g-caja-txt,.g-operador,.g-plan,.g-buscar,.g-actions{ grid-column: 1 / -1; }
    }
    .hint{ font-size:.86rem; color: var(--bs-secondary-color); }
    .btn-soft{ border-radius: .75rem; box-shadow: 0 6px 16px rgba(2,8,20,.08); }
  </style>
</head>
<body class="bg-body-tertiary">
<div class="container py-3 py-md-4">

  <!-- Encabezado + Filtros -->
  <div class="sticky-top-lite mb-3">
    <div class="card filter-card">
      <div class="card-body">
        <div class="row g-4 align-items-center">
          <div class="col-lg-3">
            <h2 class="filter-title mb-1">Inventario de SIMs — <span class="text-secondary">Disponibles</span></h2>
            <div class="text-secondary small">
              Central 2.0 · Vista <?= h($scope==='global'?'global':'por sucursal'); ?>.
              <?= ($scope==='global' && $selSucursal===0) ? 'Puedes buscar por caja en todas las sucursales.' : '' ?>
            </div>
          </div>

          <div class="col-lg-9">
            <form id="filtros" class="filter-grid" method="get">
              <?php if (isPrivileged($ROL)): ?>
                <div class="g-ambito">
                  <div class="label-lite">Ámbito</div>
                  <div class="input-icon input-pill">
                    <i class="bi bi-globe2"></i>
                    <select name="scope" class="form-select">
                      <option value="global"   <?= $scope==='global'?'selected':''; ?>>Global</option>
                      <option value="sucursal" <?= $scope==='sucursal'?'selected':''; ?>>Por sucursal</option>
                    </select>
                  </div>
                </div>

                <div class="g-sucursal">
                  <div class="label-lite">Sucursal</div>
                  <div class="input-icon input-pill">
                    <i class="bi bi-building"></i>
                    <select name="sucursal" class="form-select" <?= $scope==='global'?'':'disabled'; ?>>
                      <option value="0">— Todas —</option>
                      <?php foreach($sucursales as $s): ?>
                        <option value="<?= (int)$s['id']; ?>" <?= (int)$s['id']===$selSucursal?'selected':''; ?>>
                          <?= h($s['nombre']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <?php if ($scope!=='global'): ?>
                    <input type="hidden" name="sucursal" value="<?= (int)$selSucursal; ?>">
                  <?php endif; ?>
                </div>

                <div class="g-caja-sel">
                  <div class="label-lite">Caja (select)</div>
                  <div class="input-icon input-pill">
                    <i class="bi bi-box-seam"></i>
                    <select name="caja" class="form-select" <?= $haySucursalConcreta ? '' : 'disabled'; ?>>
                      <option value="0">Todas</option>
                      <?php if ($haySucursalConcreta && $cajas): ?>
                        <?php foreach($cajas as $val=>$txt): ?>
                          <option value="<?= h($val); ?>" <?= ((string)$val===(string)$caja && !$useCajaLike)?'selected':''; ?>>
                            <?= h($txt); ?>
                          </option>
                        <?php endforeach; ?>
                      <?php elseif ($haySucursalConcreta && !$cajas): ?>
                        <option value="0" selected>(sin cajas registradas)</option>
                      <?php else: ?>
                        <option value="0" selected>(elige sucursal o usa "Caja (texto)")</option>
                      <?php endif; ?>
                    </select>
                  </div>
                </div>
              <?php else: ?>
                <input type="hidden" name="scope" value="sucursal">
                <input type="hidden" name="sucursal" value="<?= (int)$selSucursal; ?>">
              <?php endif; ?>

              <div class="g-caja-txt">
                <div class="label-lite">Caja (texto)</div>
                <div class="input-icon input-pill">
                  <i class="bi bi-search"></i>
                  <input type="text" class="form-control" name="caja_q"
                         value="<?= h($caja_q); ?>" placeholder="Ej: 12 / A-01">
                </div>
              </div>

              <div class="g-operador">
                <div class="label-lite">Operador</div>
                <div class="input-icon input-pill">
                  <i class="bi bi-diagram-3"></i>
                  <select name="operador" class="form-select">
                    <?= selectOptions(['ALL'=>'Todos','Bait'=>'Bait','AT&T'=>'AT&T'], $operador); ?>
                  </select>
                </div>
              </div>

              <div class="g-buscar">
                <div class="label-lite">Buscar ICCID / DN</div>
                <div class="input-icon input-pill">
                  <i class="bi bi-upc-scan"></i>
                  <input type="text" class="form-control" name="q" value="<?= h($q); ?>" placeholder="ICCID o DN…">
                </div>
              </div>

              <div class="g-actions d-flex gap-2">
                <button class="btn btn-primary btn-soft px-4" type="submit"><i class="bi bi-funnel me-1"></i>Aplicar</button>
                <a class="btn btn-outline-secondary btn-soft px-4" href="inventario_sims_resumen.php"><i class="bi bi-arrow-counterclockwise me-1"></i>Limpiar</a>
                <button class="btn btn-success btn-soft px-4" type="submit" name="export" value="1"><i class="bi bi-filetype-csv me-1"></i>Exportar CSV</button>
              </div>
            </form>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
      <div class="card kpi-card">
        <div class="card-body">
          <div class="kpi-sub text-secondary">SIMs disponibles</div>
          <div class="kpi-value"><?= number_format($kpis['total']); ?></div>
          <div class="small text-secondary">Según filtros (incluye caja si aplicó)</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card kpi-card">
        <div class="card-body">
          <div class="kpi-sub text-secondary">Bait</div>
          <div class="kpi-value"><?= number_format($kpis['bait']); ?></div>
          <div class="small text-secondary">Por operador</div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card kpi-card">
        <div class="card-body">
          <div class="kpi-sub text-secondary">AT&amp;T</div>
          <div class="kpi-value"><?= number_format($kpis['att']); ?></div>
          <div class="small text-secondary">Por operador</div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($scope==='global' && $selSucursal===0 && $useCajaLike): ?>
    <!-- Localizador de Caja (Global absoluto: ignora estatus/operador/plan/q; SOLO caja) -->
    <?php
      // Coincidencia EXACTA si es "limpia" (alfa-num/guión/guión_bajo), de lo contrario LIKE.
      $isExact = (bool)preg_match('/^[A-Za-z0-9_-]+$/', $caja_q);
      $sqlLoc = "SELECT
                  s.id   AS id_suc,
                  s.nombre AS sucursal,
                  TRIM(i.$CAJA_COL) AS caja_val,
                  COUNT(*) AS piezas,
                  SUM(i.estatus='Disponible') AS disponibles
                FROM inventario_sims i
                LEFT JOIN sucursales s ON s.id = i.id_sucursal
                WHERE ".condCajaNoVacia("i.$CAJA_COL")." AND ".($isExact ? "$CAJA_TR = ?" : "$CAJA_TR LIKE ?")."
                GROUP BY s.id, TRIM(i.$CAJA_COL)
                HAVING piezas > 0
                ORDER BY s.nombre, caja_val";
      $param = $isExact ? $caja_q : ("%$caja_q%");
      $st = $conn->prepare($sqlLoc);
      $st->bind_param('s', $param);
      $st->execute();
      $loc = $st->get_result()->fetch_all(MYSQLI_ASSOC);
      $st->close();
    ?>
    <div class="card border-0 shadow-sm rounded-4 mb-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">Localizador de caja: “<?= h($caja_q); ?>”</h5>
          <span class="text-secondary small">Búsqueda global (todos los estatus)</span>
        </div>
        <?php if (!$loc): ?>
          <div class="text-secondary">No se encontraron coincidencias.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th>Sucursal</th>
                  <th>Caja</th>
                  <th class="text-end">Piezas</th>
                  <th class="text-end">Disponibles</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($loc as $L): ?>
                  <tr>
                    <td><?= h($L['sucursal']); ?></td>
                    <td><?= h($L['caja_val']); ?></td>
                    <td class="text-end"><?= (int)$L['piezas']; ?></td>
                    <td class="text-end"><?= (int)$L['disponibles']; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($scope==='global'): ?>
    <!-- Cards por sucursal -->
    <div class="grid mb-5">
      <?php if (!$cards): ?>
        <div class="text-center text-secondary py-5">No hay SIMs que coincidan con los filtros.</div>
      <?php else: foreach($cards as $c): ?>
        <div class="card suc-card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <h5 class="mb-0"><?= h($c['nombre']); ?></h5>
              <span class="badge text-bg-light">ID <?= (int)$c['id_suc']; ?></span>
            </div>
            <div class="display-6 fw-bold mb-2"><?= number_format((int)$c['disponibles']); ?></div>
            <div class="d-flex gap-2 mb-3">
              <span class="badge badge-soft">Bait: <?= (int)$c['bait']; ?></span>
              <span class="badge badge-soft">AT&amp;T: <?= (int)$c['att']; ?></span>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <a class="btn btn-sm btn-outline-primary"
                 href="?<?= h(http_build_query(array_merge($_GET, [
                   'scope'=>'sucursal',
                   'sucursal'=>(int)$c['id_suc']
                 ]))); ?>">
                Ver detalle
              </a>
              <button class="btn btn-sm btn-outline-success" type="submit" form="filtros" name="export" value="1"
                onclick="(function(f){
                  const sel = f.querySelector('select[name=sucursal]');
                  if (sel) sel.value='<?= (int)$c['id_suc']; ?>';
                  const hid = f.querySelector('input[type=hidden][name=sucursal]');
                  if (hid) hid.value='<?= (int)$c['id_suc']; ?>';
                })(document.getElementById('filtros')); ">
                Exportar CSV
              </button>
            </div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

  <?php else: ?>
    <!-- Detalle por sucursal -->
    <div class="card border-0 shadow-sm rounded-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
          <div>
            <h4 class="mb-0">Sucursal: <?= h($sucursalNombre ?: ('#'.$selSucursal)); ?></h4>
            <div class="text-secondary small">Listado de SIMs disponibles</div>
          </div>
          <?php if (isPrivileged($ROL)): ?>
            <a class="btn btn-outline-secondary btn-sm"
               href="?<?= h(http_build_query(array_merge($_GET, ['scope'=>'global','sucursal'=>0]))); ?>">
              Volver a Global
            </a>
          <?php endif; ?>
        </div>

        <div class="table-responsive" style="max-height: 70vh;">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th class="text-secondary">#</th>
                <th>ICCID</th>
                <th>DN</th>
                <th>Operador</th>
                <th>Plan</th>
                <th>Caja</th>
                <th>Ingreso</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$detalle): ?>
                <tr><td colspan="7" class="text-center text-secondary">Sin registros</td></tr>
              <?php else:
                $i=1; foreach($detalle as $r): ?>
                <tr>
                  <td class="text-secondary"><?= $i++; ?></td>
                  <td class="fw-semibold"><?= h($r['iccid']); ?></td>
                  <td><?= h($r['dn']); ?></td>
                  <td><?= h($r['operador']); ?></td>
                  <td><?= h($r['tipo_plan']); ?></td>
                  <td><?= h($r['caja_val']); ?></td>
                  <td class="text-nowrap"><?= h($r['fecha_ingreso']); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <div class="d-flex gap-2 mt-3">
          <button class="btn btn-success btn-soft" type="submit" form="filtros" name="export" value="1"><i class="bi bi-filetype-csv me-1"></i>Exportar CSV</button>
        </div>
      </div>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
