<?php
// tablero_tareas.php — Tablero de Tareas (Central)
// - Tabs: Mi tablero | Área | Jefes
// - Filtros: búsqueda, estatus, área, prioridad, vencidas, bloqueadas
// - Detecta dependencias abiertas (bloqueo) via subquery
// - No impone permisos por rol (los controlas en navbar)
// Requiere: db.php, navbar.php, tablas de tareas ya creadas.

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);
$ROL        = (string)($_SESSION['rol'] ?? '');
$ID_SUC     = (int)($_SESSION['id_sucursal'] ?? 0);

// ===================== Helpers =====================
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function qstr($s){ return trim((string)$s); }

function fmtFecha($dt){
  if (!$dt) return '—';
  try { return (new DateTime($dt))->format('d/m/Y H:i'); } catch(Exception $e){ return '—'; }
}
function diffHuman($finCompromiso, $finReal=null){
  if (!$finCompromiso) return ['txt'=>'—','cls'=>'secondary'];
  try{
    $now = new DateTime();
    $end = new DateTime($finCompromiso);
    $delta = $now->getTimestamp() - $end->getTimestamp(); // + si ya venció
    $abs = abs($delta);

    $min = (int)floor($abs/60);
    $hrs = (int)floor($abs/3600);
    $days = (int)floor($abs/86400);

    if ($days > 0) $span = $days.' d';
    elseif ($hrs > 0) $span = $hrs.' h';
    else $span = $min.' min';

    if ($finReal) return ['txt'=>'Cerrada','cls'=>'success'];

    if ($delta > 0) return ['txt'=>'Vencida • '.$span,'cls'=>'danger'];
    if ($delta > -86400) return ['txt'=>'< 24h • '.$span,'cls'=>'warning'];
    return ['txt'=>'En tiempo','cls'=>'success'];
  } catch(Exception $e){
    return ['txt'=>'—','cls'=>'secondary'];
  }
}

// ===================== Parámetros =====================
$tab       = qstr($_GET['tab'] ?? 'mio'); // mio | area | jefes
$q         = qstr($_GET['q'] ?? '');
$estatus   = qstr($_GET['estatus'] ?? 'all');
$prioridad = qstr($_GET['prioridad'] ?? 'all');
$areaId    = (int)($_GET['area'] ?? 0);

$soloVencidas  = (int)($_GET['vencidas'] ?? 0) === 1;
$soloBloqueadas= (int)($_GET['bloqueadas'] ?? 0) === 1;

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

// ===================== Catálogo Áreas =====================
$areas = [];
$resA = $conn->query("SELECT id, nombre FROM areas WHERE activa=1 ORDER BY nombre");
while($row = $resA->fetch_assoc()) $areas[] = $row;

// ===================== Saber si soy jefe de alguna área =====================
$misAreasJefe = [];
$stmtJ = $conn->prepare("
  SELECT aj.id_area, a.nombre
  FROM areas_jefes aj
  JOIN areas a ON a.id=aj.id_area
  WHERE aj.id_usuario=? AND a.activa=1
  ORDER BY a.nombre
");
$stmtJ->bind_param("i", $ID_USUARIO);
$stmtJ->execute();
$rsJ = $stmtJ->get_result();
while($r = $rsJ->fetch_assoc()) $misAreasJefe[] = $r;
$stmtJ->close();

// ===================== Armar WHERE dinámico =====================
$where = [];
$params = [];
$types  = "";

// Base select + bloqueo por dependencias abiertas
$baseSelect = "
  SELECT
    t.*,
    a.nombre AS area_nombre,
    (SELECT COUNT(*)
     FROM tarea_dependencias td
     JOIN tareas tdep ON tdep.id = td.depende_de
     WHERE td.id_tarea = t.id AND tdep.estatus <> 'Terminada'
    ) AS deps_abiertas,
    (SELECT GROUP_CONCAT(DISTINCT u.nombre ORDER BY u.nombre SEPARATOR ', ')
     FROM tarea_usuarios tu
     JOIN usuarios u ON u.id = tu.id_usuario
     WHERE tu.id_tarea = t.id AND tu.rol_en_tarea = 'responsable'
    ) AS responsables
  FROM tareas t
  JOIN areas a ON a.id = t.id_area
";

// Tab logic
if ($tab === 'mio') {
  $baseSelect .= " JOIN tarea_usuarios tu_mio ON tu_mio.id_tarea=t.id AND tu_mio.id_usuario=? ";
  $types .= "i"; $params[] = $ID_USUARIO;
} elseif ($tab === 'jefes') {
  // jefes: solo áreas donde soy jefe
  if (!empty($misAreasJefe)) {
    $ids = array_map(fn($x)=> (int)$x['id_area'], $misAreasJefe);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $baseSelect .= " JOIN areas_jefes aj ON aj.id_area=t.id_area AND aj.id_usuario=? ";
    $types .= "i"; $params[] = $ID_USUARIO;
  } else {
    // No soy jefe: forzamos cero resultados (pero sin romper)
    $where[] = " 1=0 ";
  }
} else {
  $tab = 'area'; // fallback
}

// Filtros
if ($q !== '') {
  $where[] = " (t.titulo LIKE ? OR t.descripcion LIKE ?) ";
  $types  .= "ss";
  $params[] = "%$q%";
  $params[] = "%$q%";
}
if ($estatus !== 'all') {
  $where[] = " t.estatus = ? ";
  $types  .= "s";
  $params[] = $estatus;
}
if ($prioridad !== 'all') {
  $where[] = " t.prioridad = ? ";
  $types  .= "s";
  $params[] = $prioridad;
}
if ($areaId > 0) {
  $where[] = " t.id_area = ? ";
  $types  .= "i";
  $params[] = $areaId;
}
if ($soloVencidas) {
  $where[] = " (t.fecha_fin_real IS NULL AND t.fecha_fin_compromiso < NOW() AND t.estatus <> 'Terminada' AND t.estatus <> 'Cancelada') ";
}
if ($soloBloqueadas) {
  $where[] = " (
    (SELECT COUNT(*)
     FROM tarea_dependencias td
     JOIN tareas tdep ON tdep.id = td.depende_de
     WHERE td.id_tarea = t.id AND tdep.estatus <> 'Terminada'
    ) > 0
  ) ";
}

// Final WHERE
$sqlWhere = "";
if (!empty($where)) $sqlWhere = " WHERE " . implode(" AND ", $where);

// Orden: vencidas arriba, luego compromiso
$orderBy = "
  ORDER BY
    (t.fecha_fin_real IS NULL AND t.fecha_fin_compromiso < NOW() AND t.estatus <> 'Terminada' AND t.estatus <> 'Cancelada') DESC,
    t.fecha_fin_compromiso ASC,
    t.id DESC
";

// Count total
$countSql = "SELECT COUNT(*) AS total FROM ( " . $baseSelect . $sqlWhere . " ) X";
$stmtC = $conn->prepare($countSql);
if ($types !== "") $stmtC->bind_param($types, ...$params);
$stmtC->execute();
$total = (int)($stmtC->get_result()->fetch_assoc()['total'] ?? 0);
$stmtC->close();

$totalPages = max(1, (int)ceil($total / $perPage));

// Fetch data with limit
$dataSql = $baseSelect . $sqlWhere . $orderBy . " LIMIT ? OFFSET ? ";
$stmt = $conn->prepare($dataSql);

$types2 = $types . "ii";
$params2 = array_merge($params, [$perPage, $offset]);
$stmt->bind_param($types2, ...$params2);

$stmt->execute();
$rs = $stmt->get_result();
$tareas = [];
while($r = $rs->fetch_assoc()) $tareas[] = $r;
$stmt->close();

// ===================== KPIs rápidos =====================
$kpi = ['total'=>$total,'vencidas'=>0,'porVencer'=>0,'bloqueadas'=>0];
foreach($tareas as $t){
  $isDone = !empty($t['fecha_fin_real']) || $t['estatus']==='Terminada' || $t['estatus']==='Cancelada';
  $isOver = !$isDone && !empty($t['fecha_fin_compromiso']) && strtotime($t['fecha_fin_compromiso']) < time();
  $isSoon = !$isDone && !empty($t['fecha_fin_compromiso']) && strtotime($t['fecha_fin_compromiso']) >= time() && strtotime($t['fecha_fin_compromiso']) < (time()+86400);
  $isBlock = ((int)$t['deps_abiertas']) > 0;

  if ($isOver) $kpi['vencidas']++;
  if ($isSoon) $kpi['porVencer']++;
  if ($isBlock) $kpi['bloqueadas']++;
}

// ===================== URL helper pagination =====================
function buildUrl($overrides=[]){
  $q = $_GET;
  foreach($overrides as $k=>$v){
    if ($v === null) unset($q[$k]); else $q[$k]=$v;
  }
  return basename(__FILE__) . '?' . http_build_query($q);
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tablero de Tareas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .kpi-card{ border:1px solid rgba(0,0,0,.08); border-radius:14px; padding:14px; background:#fff; }
    .soft{ color:#6c757d; }
    .chip{ display:inline-block; padding:.2rem .55rem; border-radius:999px; font-size:.78rem; border:1px solid rgba(0,0,0,.08); }
    .trow:hover{ background:rgba(0,0,0,.02); }
    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
</head>
<body class="bg-light">

<div class="container-fluid py-3">

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
      <h4 class="mb-0">Tablero de Tareas</h4>
      <div class="soft small">Usuario: <span class="mono"><?=h($_SESSION['nombre'] ?? $ROL)?></span> • Sucursal: <span class="mono"><?=h((string)$ID_SUC)?></span></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-primary" href="tarea_nueva.php">
        + Nueva tarea
      </a>
    </div>
  </div>

  <!-- KPIs -->
  <div class="row g-2 mb-3">
    <div class="col-12 col-md-3"><div class="kpi-card">
      <div class="soft small">Resultados</div>
      <div class="fs-4 fw-semibold"><?= (int)$kpi['total'] ?></div>
    </div></div>
    <div class="col-12 col-md-3"><div class="kpi-card">
      <div class="soft small">Vencidas (en esta vista)</div>
      <div class="fs-4 fw-semibold text-danger"><?= (int)$kpi['vencidas'] ?></div>
    </div></div>
    <div class="col-12 col-md-3"><div class="kpi-card">
      <div class="soft small">Por vencer (&lt;24h)</div>
      <div class="fs-4 fw-semibold text-warning"><?= (int)$kpi['porVencer'] ?></div>
    </div></div>
    <div class="col-12 col-md-3"><div class="kpi-card">
      <div class="soft small">Bloqueadas (dependencias)</div>
      <div class="fs-4 fw-semibold"><?= (int)$kpi['bloqueadas'] ?></div>
    </div></div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?=$tab==='mio'?'active':''?>" href="<?=h(buildUrl(['tab'=>'mio','page'=>1]))?>">Mi tablero</a></li>
    <li class="nav-item"><a class="nav-link <?=$tab==='area'?'active':''?>" href="<?=h(buildUrl(['tab'=>'area','page'=>1]))?>">Área</a></li>
    <li class="nav-item"><a class="nav-link <?=$tab==='jefes'?'active':''?>" href="<?=h(buildUrl(['tab'=>'jefes','page'=>1]))?>">Jefes</a></li>
  </ul>

  <!-- Filtros -->
  <form class="card border-0 shadow-sm mb-3" method="get">
    <div class="card-body">
      <input type="hidden" name="tab" value="<?=h($tab)?>">
      <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
          <label class="form-label small soft mb-1">Buscar</label>
          <input class="form-control" name="q" value="<?=h($q)?>" placeholder="Título o descripción...">
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small soft mb-1">Estatus</label>
          <select class="form-select" name="estatus">
            <?php
              $opts = ['all'=>'Todos','Nueva'=>'Nueva','En_proceso'=>'En proceso','Bloqueada'=>'Bloqueada','En_revision'=>'En revisión','Terminada'=>'Terminada','Cancelada'=>'Cancelada'];
              foreach($opts as $k=>$v){
                $sel = ($estatus===$k)?'selected':'';
                echo "<option value=\"".h($k)."\" $sel>".h($v)."</option>";
              }
            ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label small soft mb-1">Prioridad</label>
          <select class="form-select" name="prioridad">
            <?php
              $po = ['all'=>'Todas','Baja'=>'Baja','Media'=>'Media','Alta'=>'Alta'];
              foreach($po as $k=>$v){
                $sel = ($prioridad===$k)?'selected':'';
                echo "<option value=\"".h($k)."\" $sel>".h($v)."</option>";
              }
            ?>
          </select>
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label small soft mb-1">Área</label>
          <select class="form-select" name="area">
            <option value="0">Todas</option>
            <?php foreach($areas as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= $areaId===(int)$a['id']?'selected':'' ?>>
                <?= h($a['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-md-1 d-grid">
          <button class="btn btn-dark">Filtrar</button>
        </div>

        <div class="col-12">
          <div class="d-flex flex-wrap gap-2 mt-2">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" value="1" id="vencidas" name="vencidas" <?= $soloVencidas?'checked':'' ?>>
              <label class="form-check-label small" for="vencidas">Solo vencidas</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" value="1" id="bloqueadas" name="bloqueadas" <?= $soloBloqueadas?'checked':'' ?>>
              <label class="form-check-label small" for="bloqueadas">Solo bloqueadas</label>
            </div>

            <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?=h(basename(__FILE__).'?tab='.$tab)?>">Limpiar</a>
          </div>
        </div>

      </div>
    </div>
  </form>

  <!-- Contenido -->
  <div class="card border-0 shadow-sm">
    <div class="card-body">

      <?php if ($tab==='jefes' && empty($misAreasJefe)): ?>
        <div class="alert alert-info mb-0">
          No tienes áreas asignadas como <b>jefe</b> en esta central todavía.
          <div class="small soft mt-1">Cuando definamos áreas_jefes, aquí te van a salir vencidas/bloqueadas para seguimiento.</div>
        </div>
      <?php else: ?>

        <div class="table-responsive">
          <table class="table align-middle mb-0">
            <thead>
              <tr class="small text-secondary">
                <th style="min-width:280px;">Tarea</th>
                <th>Área</th>
                <th>Estatus</th>
                <th>Responsables</th>
                <th>Compromiso</th>
                <th>Riesgo</th>
                <th class="text-end">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($tareas)): ?>
                <tr><td colspan="7" class="text-center py-4 soft">Sin resultados.</td></tr>
              <?php endif; ?>

              <?php foreach($tareas as $t): ?>
                <?php
                  $deps = (int)$t['deps_abiertas'];
                  $isBlock = $deps > 0;
                  $risk = diffHuman($t['fecha_fin_compromiso'] ?? null, $t['fecha_fin_real'] ?? null);

                  $estatusLbl = [
                    'Nueva'=>'Nueva',
                    'En_proceso'=>'En proceso',
                    'Bloqueada'=>'Bloqueada',
                    'En_revision'=>'En revisión',
                    'Terminada'=>'Terminada',
                    'Cancelada'=>'Cancelada'
                  ][$t['estatus']] ?? h($t['estatus']);

                  $estCls = 'secondary';
                  if ($t['estatus']==='En_proceso') $estCls='primary';
                  if ($t['estatus']==='Bloqueada') $estCls='warning';
                  if ($t['estatus']==='Terminada') $estCls='success';
                  if ($t['estatus']==='Cancelada') $estCls='dark';
                ?>
                <tr class="trow">
                  <td>
                    <div class="fw-semibold"><?=h($t['titulo'])?></div>
                    <div class="small soft">
                      #<?= (int)$t['id'] ?>
                      <?php if ($isBlock): ?>
                        • <span class="chip">Bloqueada por <?= $deps ?> dep.</span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td><span class="chip"><?=h($t['area_nombre'])?></span></td>
                  <td><span class="badge bg-<?=$estCls?>"><?=$estatusLbl?></span></td>
                  <td class="small"><?= h($t['responsables'] ?: '—') ?></td>
                  <td class="small">
                    <div><?= h(fmtFecha($t['fecha_fin_compromiso'] ?? null)) ?></div>
                    <div class="soft">Creada: <?= h(fmtFecha($t['fecha_creacion'] ?? null)) ?></div>
                  </td>
                  <td><span class="badge bg-<?=$risk['cls']?>"><?=$risk['txt']?></span></td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-primary" href="tarea_ver.php?id=<?= (int)$t['id'] ?>">Ver</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Paginación -->
        <div class="d-flex align-items-center justify-content-between mt-3">
          <div class="small soft">
            Mostrando <?= min($total, $offset+1) ?>–<?= min($total, $offset+$perPage) ?> de <?= $total ?>
          </div>
          <div class="btn-group">
            <a class="btn btn-outline-secondary btn-sm <?= $page<=1?'disabled':'' ?>" href="<?=h(buildUrl(['page'=>$page-1]))?>">←</a>
            <span class="btn btn-outline-secondary btn-sm disabled">Página <?= (int)$page ?> / <?= (int)$totalPages ?></span>
            <a class="btn btn-outline-secondary btn-sm <?= $page>=$totalPages?'disabled':'' ?>" href="<?=h(buildUrl(['page'=>$page+1]))?>">→</a>
          </div>
        </div>

      <?php endif; ?>

    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
