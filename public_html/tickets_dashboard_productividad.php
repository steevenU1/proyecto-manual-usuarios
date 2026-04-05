<?php
// tickets_dashboard_productividad.php — Dashboard de Productividad (Tickets)
// KPIs + Tendencias + Backlog REAL (sin rango) + Backlog por origen + SLA (primera respuesta)
// + NUEVO: Cards "Tickets creados por origen" (en el periodo seleccionado)
// Requiere tablas: tickets, ticket_mensajes, sucursales
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','Logistica','Gerente'];
if (!in_array($ROL, $ALLOWED, true)) { header("Location: 403.php"); exit(); }

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__.'/navbar.php')) require_once __DIR__.'/navbar.php';

date_default_timezone_set('America/Mexico_City');
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===========================
   Config / Filtros Dashboard
   =========================== */
$rangeDays  = (int)($_GET['range'] ?? 30);
if ($rangeDays <= 0) $rangeDays = 30;
if ($rangeDays > 365) $rangeDays = 365;

$origen     = $_GET['origen'] ?? ''; // '', 'NANO', 'LUGA', 'OTRO', 'MIPLAN'
$sucursalId = (int)($_GET['sucursal'] ?? 0);
$prioridad  = $_GET['prioridad'] ?? ''; // '', baja/media/alta/critica

// IMPORTANT: Ajusta esto si tu cliente se identifica distinto en ticket_mensajes.autor_sistema
$autorCliente = $_GET['autor_cliente'] ?? 'CLIENTE';

$desdeDash = (new DateTime())->modify("-{$rangeDays} days")->setTime(0,0,0)->format('Y-m-d H:i:s');
$hastaDash = (new DateTime())->setTime(23,59,59)->format('Y-m-d H:i:s');

/* ===========================
   Map de sucursales
   =========================== */
$sucursales = [];
$qSuc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
if ($qSuc) { while($r=$qSuc->fetch_assoc()){ $sucursales[(int)$r['id']] = $r['nombre']; } }

/* ===========================
   WHERE dinámico (para productividad en ventana)
   =========================== */
$where = ["t.created_at >= ?", "t.created_at <= ?"];
$args  = [$desdeDash, $hastaDash];
$types = "ss";

if ($origen !== '')     { $where[] = "t.sistema_origen = ?";   $args[] = $origen;     $types .= "s"; }
if ($sucursalId > 0)    { $where[] = "t.sucursal_origen_id=?"; $args[] = $sucursalId; $types .= "i"; }
if ($prioridad !== '')  { $where[] = "t.prioridad = ?";        $args[] = $prioridad;  $types .= "s"; }

$whereSql = implode(" AND ", $where);

/* ===========================
   WHERE especial para backlog REAL (SIN rango)
   - Backlog es "pendientes actuales", no "pendientes en la ventana"
   =========================== */
$whereBacklog = [
  "t.estado IN ('abierto','en_progreso','en_espera','en_espera_cliente','en_espera_proveedor')"
];
$argsBacklog  = [];
$typesBacklog = "";

if ($origen !== '')     { $whereBacklog[] = "t.sistema_origen = ?";   $argsBacklog[] = $origen;     $typesBacklog .= "s"; }
if ($sucursalId > 0)    { $whereBacklog[] = "t.sucursal_origen_id=?"; $argsBacklog[] = $sucursalId; $typesBacklog .= "i"; }
if ($prioridad !== '')  { $whereBacklog[] = "t.prioridad = ?";        $argsBacklog[] = $prioridad;  $typesBacklog .= "s"; }

$whereBacklogSql = implode(" AND ", $whereBacklog);

/* ============================================================
   Subquery: Primera respuesta (FRT)
   - Para cada ticket, toma el primer mensaje cuyo autor_sistema != $autorCliente
   ============================================================ */
$subFirstResp = "
  SELECT tm.ticket_id, MIN(tm.created_at) AS first_resp_at
  FROM ticket_mensajes tm
  WHERE tm.autor_sistema <> ?
  GROUP BY tm.ticket_id
";

/* ===========================
   1) KPIs de PRODUCTIVIDAD (en ventana)
   =========================== */
$sqlKpiWindow = "
  SELECT
    COUNT(*) AS creados_window,
    SUM(CASE WHEN t.estado IN ('resuelto','cerrado') THEN 1 ELSE 0 END) AS resueltos_window,

    AVG(CASE
      WHEN fr.first_resp_at IS NULL THEN NULL
      ELSE TIMESTAMPDIFF(MINUTE, t.created_at, fr.first_resp_at)
    END) AS avg_min_primera_respuesta,

    SUM(CASE
      WHEN fr.first_resp_at IS NOT NULL
       AND TIMESTAMPDIFF(MINUTE, t.created_at, fr.first_resp_at) <= 15
      THEN 1 ELSE 0 END) AS sla_15,

    SUM(CASE
      WHEN fr.first_resp_at IS NOT NULL
       AND TIMESTAMPDIFF(MINUTE, t.created_at, fr.first_resp_at) <= 60
      THEN 1 ELSE 0 END) AS sla_60,

    AVG(CASE
      WHEN t.estado IN ('resuelto','cerrado') THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)
      ELSE NULL
    END) AS avg_horas_resolucion
  FROM tickets t
  LEFT JOIN ($subFirstResp) fr ON fr.ticket_id = t.id
  WHERE $whereSql
";

$kpi = [
  'creados_window'=>0,'resueltos_window'=>0,
  'backlog'=>0,

  'avg_min_primera_respuesta'=>null,
  'avg_horas_resolucion'=>null,

  'sla_15'=>0,'sla_60'=>0,
  'pct_sla_15'=>0,'pct_sla_60'=>0,
  'pct_resueltos_window'=>0,
];

$st = $conn->prepare($sqlKpiWindow);
if (!$st) { die("Error prepare KPI ventana: ".$conn->error); }
$bindArgs = array_merge([$autorCliente], $args);
$bindTypes = "s".$types;
$st->bind_param($bindTypes, ...$bindArgs);
$st->execute();
$r = $st->get_result();
if ($r && ($row = $r->fetch_assoc())) {
  $kpi['creados_window'] = (int)($row['creados_window'] ?? 0);
  $kpi['resueltos_window'] = (int)($row['resueltos_window'] ?? 0);

  $kpi['avg_min_primera_respuesta'] = ($row['avg_min_primera_respuesta'] !== null) ? (int)round($row['avg_min_primera_respuesta']) : null;
  $kpi['avg_horas_resolucion'] = ($row['avg_horas_resolucion'] !== null) ? (int)round($row['avg_horas_resolucion']) : null;

  $kpi['sla_15'] = (int)($row['sla_15'] ?? 0);
  $kpi['sla_60'] = (int)($row['sla_60'] ?? 0);
}
$st->close();

$kpi['pct_sla_15'] = ($kpi['creados_window'] > 0) ? (int)round(($kpi['sla_15'] / $kpi['creados_window']) * 100) : 0;
$kpi['pct_sla_60'] = ($kpi['creados_window'] > 0) ? (int)round(($kpi['sla_60'] / $kpi['creados_window']) * 100) : 0;
$kpi['pct_resueltos_window'] = ($kpi['creados_window'] > 0) ? (int)round(($kpi['resueltos_window'] / $kpi['creados_window']) * 100) : 0;

/* ===========================
   1.1) NUEVO: Creados por origen (en ventana)
   - Esto es lo que te deja presumir "cuánto le trabajé a cada sistema"
   =========================== */
$creadosPorOrigen = [
  'LUGA'   => 0,
  'MIPLAN' => 0,
  'NANO'   => 0,
  'OTRO'   => 0,
];

$sqlCreadosOrigen = "
  SELECT t.sistema_origen, COUNT(*) AS c
  FROM tickets t
  WHERE $whereSql
  GROUP BY t.sistema_origen
";
$st = $conn->prepare($sqlCreadosOrigen);
if ($st) {
  $st->bind_param($types, ...$args);
  $st->execute();
  $r = $st->get_result();
  if ($r) {
    while ($row = $r->fetch_assoc()) {
      $o = strtoupper(trim((string)($row['sistema_origen'] ?? '')));
      $c = (int)($row['c'] ?? 0);
      if ($o === '') continue;
      // Normalizaciones suaves (por si viene "MI PLAN" o "MIPLAN")
      if ($o === 'MI PLAN') $o = 'MIPLAN';
      if (!isset($creadosPorOrigen[$o])) {
        // si llega algo inesperado, lo mandamos a OTRO
        $creadosPorOrigen['OTRO'] += $c;
      } else {
        $creadosPorOrigen[$o] += $c;
      }
    }
  }
  $st->close();
}

// Total creados por origen (para %)
$totalCreadosOrigen = max(1, array_sum($creadosPorOrigen));

/* ===========================
   2) Backlog REAL total (SIN rango)
   =========================== */
$sqlBacklogTotal = "SELECT COUNT(*) AS c FROM tickets t WHERE $whereBacklogSql";
$stB = $conn->prepare($sqlBacklogTotal);
if ($stB) {
  if ($typesBacklog !== '') $stB->bind_param($typesBacklog, ...$argsBacklog);
  $stB->execute();
  $rb = $stB->get_result();
  if ($rb && ($row = $rb->fetch_assoc())) {
    $kpi['backlog'] = (int)($row['c'] ?? 0);
  }
  $stB->close();
}

/* ===========================
   3) Backlog REAL por origen (SIN rango)
   =========================== */
$sqlBacklogOrigen = "
  SELECT t.sistema_origen, COUNT(*) AS c
  FROM tickets t
  WHERE $whereBacklogSql
  GROUP BY t.sistema_origen
";

$backlogOrigen = ['NANO'=>0,'LUGA'=>0,'MIPLAN'=>0,'OTRO'=>0];

$st = $conn->prepare($sqlBacklogOrigen);
if ($st) {
  if ($typesBacklog !== '') $st->bind_param($typesBacklog, ...$argsBacklog);
  $st->execute();
  $r = $st->get_result();
  if ($r) {
    while($row = $r->fetch_assoc()){
      $o = strtoupper(trim((string)($row['sistema_origen'] ?? '')));
      if ($o === 'MI PLAN') $o = 'MIPLAN';
      $c = (int)($row['c'] ?? 0);
      if ($o === '') continue;
      if (!isset($backlogOrigen[$o])) $backlogOrigen['OTRO'] += $c;
      else $backlogOrigen[$o] += $c;
    }
  }
  $st->close();
}

/* ===========================
   4) Serie por día (PRODUCTIVIDAD en ventana)
   =========================== */
$sqlSerie = "
  SELECT
    DATE(t.created_at) AS dia,
    COUNT(*) AS creados,
    SUM(CASE WHEN t.estado IN ('resuelto','cerrado') THEN 1 ELSE 0 END) AS resueltos,
    AVG(CASE
      WHEN fr.first_resp_at IS NULL THEN NULL
      ELSE TIMESTAMPDIFF(MINUTE, t.created_at, fr.first_resp_at)
    END) AS avg_min_primera_respuesta,
    AVG(CASE
      WHEN t.estado IN ('resuelto','cerrado') THEN TIMESTAMPDIFF(HOUR, t.created_at, t.updated_at)
      ELSE NULL
    END) AS avg_horas_resolucion
  FROM tickets t
  LEFT JOIN ($subFirstResp) fr ON fr.ticket_id = t.id
  WHERE $whereSql
  GROUP BY DATE(t.created_at)
  ORDER BY dia ASC
";

$serie = [];
$st = $conn->prepare($sqlSerie);
if (!$st) { die("Error prepare serie: ".$conn->error); }
$bindArgs = array_merge([$autorCliente], $args);
$bindTypes = "s".$types;
$st->bind_param($bindTypes, ...$bindArgs);
$st->execute();
$r = $st->get_result();
if ($r) $serie = $r->fetch_all(MYSQLI_ASSOC);
$st->close();

$labels = [];
$dataCreados = [];
$dataResueltos = [];
foreach ($serie as $d) {
  $labels[] = $d['dia'];
  $dataCreados[] = (int)$d['creados'];
  $dataResueltos[] = (int)$d['resueltos'];
}

/* ===========================
   5) Top sucursales por backlog REAL (SIN rango)
   =========================== */
$sqlTopSuc = "
  SELECT t.sucursal_origen_id, COUNT(*) AS c
  FROM tickets t
  WHERE $whereBacklogSql
  GROUP BY t.sucursal_origen_id
  ORDER BY c DESC
  LIMIT 8
";
$topSuc = [];
$st = $conn->prepare($sqlTopSuc);
if ($st) {
  if ($typesBacklog !== '') $st->bind_param($typesBacklog, ...$argsBacklog);
  $st->execute();
  $r = $st->get_result();
  if ($r) $topSuc = $r->fetch_all(MYSQLI_ASSOC);
  $st->close();
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Dashboard Tickets · Productividad</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
  .kpi-card .label{font-size:.82rem;color:#6c757d}
  .kpi-card .val{font-size:1.8rem;font-weight:700;margin:0}
  .chip{display:inline-flex;align-items:center;gap:.4rem;padding:.25rem .55rem;border:1px solid #e9ecef;border-radius:999px;background:#fff;font-size:.82rem}
  .muted{color:#6c757d}
  .mini-title{font-size:.9rem;color:#6c757d}
  .origin-card{background:#fff}
  .origin-card .pct{font-size:.85rem;color:#6c757d}
</style>
</head>
<body class="bg-light">
<div class="container-fluid py-3">

  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
      <div class="text-muted small">Panel ejecutivo</div>
      <h1 class="h4 m-0">Productividad del área · Tickets</h1>
      <div class="text-muted small">
        Productividad por ventana: <strong>últimos <?= (int)$rangeDays ?> días</strong> (<?=h($desdeDash)?> → <?=h($hastaDash)?>)
        · Backlog: <strong>pendientes actuales</strong> (sin rango)
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-outline-secondary" href="tickets_operador.php">Ir a operador</a>
    </div>
  </div>

  <!-- Filtros -->
  <form class="card shadow-sm mb-3" method="get">
    <div class="card-body">
      <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
          <label class="form-label">Rango (productividad)</label>
          <select name="range" class="form-select">
            <?php foreach ([30,60,90,180,365] as $d): ?>
              <option value="<?=$d?>" <?=$rangeDays===$d?'selected':''?>><?=$d?> días</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Origen</label>
          <select name="origen" class="form-select">
            <option value="">(todos)</option>
            <?php foreach (['LUGA','MIPLAN','NANO','OTRO'] as $o): ?>
              <option value="<?=$o?>" <?=$origen===$o?'selected':''?>><?=$o?></option>
            <?php endforeach; ?>
          </select>
          <div class="text-muted small">Aplica a productividad y backlog</div>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label">Sucursal</label>
          <select name="sucursal" class="form-select">
            <option value="0">(todas)</option>
            <?php foreach ($sucursales as $id=>$nom): ?>
              <option value="<?=$id?>" <?=$sucursalId===$id?'selected':''?>><?=h($nom)?> (<?=$id?>)</option>
            <?php endforeach; ?>
          </select>
          <div class="text-muted small">Aplica a productividad y backlog</div>
        </div>
        <div class="col-6 col-md-2">
          <label class="form-label">Prioridad</label>
          <select name="prioridad" class="form-select">
            <option value="">(todas)</option>
            <?php foreach (['baja','media','alta','critica'] as $p): ?>
              <option value="<?=$p?>" <?=$prioridad===$p?'selected':''?>><?=$p?></option>
            <?php endforeach; ?>
          </select>
          <div class="text-muted small">Aplica a productividad y backlog</div>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label">Autor cliente (FRT)</label>
          <input name="autor_cliente" class="form-control" value="<?=h($autorCliente)?>" placeholder="CLIENTE">
          <div class="text-muted small">FRT = 1er mensaje con autor_sistema distinto</div>
        </div>
        <div class="col-12 d-grid d-md-flex justify-content-md-end mt-1">
          <button class="btn btn-primary">Aplicar</button>
        </div>
      </div>
    </div>
  </form>

  <!-- NUEVO: Cards creados por origen (en ventana) -->
  <div class="mb-2">
    <div class="mini-title">Tickets creados por origen (en el periodo seleccionado)</div>
  </div>
  <div class="row g-2 mb-3">
    <?php
      $origList = ['LUGA','MIPLAN','NANO','OTRO'];
      foreach ($origList as $o):
        $c = (int)$creadosPorOrigen[$o];
        $pct = (int)round(($c / $totalCreadosOrigen) * 100);
    ?>
      <div class="col-6 col-lg-3">
        <div class="card shadow-sm origin-card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="text-muted small">Origen</div>
                <div class="h6 mb-0"><?=h($o)?></div>
              </div>
              <div class="pct"><?=$pct?>%</div>
            </div>
            <div class="mt-2">
              <div class="display-6 m-0" style="font-weight:700; line-height:1;"><?= $c ?></div>
              <div class="text-muted small">tickets creados</div>
            </div>
            <div class="progress mt-2" style="height:10px">
              <div class="progress-bar" role="progressbar" style="width: <?=$pct?>%" aria-valuenow="<?=$pct?>" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- KPIs -->
  <div class="row g-2 mb-3">
    <div class="col-6 col-lg-2">
      <div class="card shadow-sm kpi-card"><div class="card-body">
        <div class="label">Creados (ventana)</div>
        <p class="val"><?= (int)$kpi['creados_window'] ?></p>
        <div class="chip">Resueltos: <?= (int)$kpi['pct_resueltos_window'] ?>%</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-2">
      <div class="card shadow-sm kpi-card"><div class="card-body">
        <div class="label">Resueltos (ventana)</div>
        <p class="val"><?= (int)$kpi['resueltos_window'] ?></p>
        <div class="chip">Backlog real: <?= (int)$kpi['backlog'] ?></div>
      </div></div>
    </div>
    <div class="col-6 col-lg-2">
      <div class="card shadow-sm kpi-card"><div class="card-body">
        <div class="label">Backlog (pendientes)</div>
        <p class="val"><?= (int)$kpi['backlog'] ?></p>
        <div class="text-muted small">Pendientes actuales (sin rango)</div>
      </div></div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card shadow-sm kpi-card"><div class="card-body">
        <div class="label">Primera respuesta (prom)</div>
        <p class="val"><?= $kpi['avg_min_primera_respuesta']===null ? '—' : ((int)$kpi['avg_min_primera_respuesta']).' min' ?></p>
        <div class="d-flex gap-2 flex-wrap">
          <div class="chip">SLA ≤15m: <?= (int)$kpi['pct_sla_15'] ?>%</div>
          <div class="chip">SLA ≤60m: <?= (int)$kpi['pct_sla_60'] ?>%</div>
        </div>
      </div></div>
    </div>
    <div class="col-12 col-lg-3">
      <div class="card shadow-sm kpi-card"><div class="card-body">
        <div class="label">Resolución (prom)</div>
        <p class="val"><?= $kpi['avg_horas_resolucion']===null ? '—' : ((int)$kpi['avg_horas_resolucion']).' h' ?></p>
        <div class="text-muted small">Estimado: created_at → updated_at (resuelto/cerrado)</div>
      </div></div>
    </div>
  </div>

  <div class="row g-2 mb-3">
    <!-- Backlog por origen -->
    <div class="col-12 col-xl-4">
      <div class="card shadow-sm"><div class="card-body">
        <div class="text-muted small">Carga pendiente</div>
        <div class="h6 mb-2">Backlog por origen (real)</div>

        <?php
          $sumBO = max(1, array_sum($backlogOrigen));
          $pL = (int)round(($backlogOrigen['LUGA'] / $sumBO) * 100);
          $pM = (int)round(($backlogOrigen['MIPLAN'] / $sumBO) * 100);
          $pN = (int)round(($backlogOrigen['NANO'] / $sumBO) * 100);
          $pO = (int)round(($backlogOrigen['OTRO'] / $sumBO) * 100);
        ?>

        <?php
          $rows = [
            ['LUGA',   $backlogOrigen['LUGA'],   $pL],
            ['MIPLAN', $backlogOrigen['MIPLAN'], $pM],
            ['NANO',   $backlogOrigen['NANO'],   $pN],
            ['OTRO',   $backlogOrigen['OTRO'],   $pO],
          ];
          foreach ($rows as $rr):
        ?>
          <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2 bg-light">
            <div><strong><?=h($rr[0])?></strong></div>
            <div class="d-flex align-items-center gap-2">
              <span class="muted small"><?=$rr[2]?>%</span>
              <span class="badge bg-dark"><?= (int)$rr[1] ?></span>
            </div>
          </div>
        <?php endforeach; ?>

        <div class="text-muted small mt-2">
          Backlog real = todo lo pendiente.
        </div>
      </div></div>
    </div>

    <!-- Chart -->
    <div class="col-12 col-xl-8">
      <div class="card shadow-sm"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <div class="text-muted small">Tendencia</div>
            <div class="h6 mb-0">Creados vs Resueltos por día (ventana)</div>
          </div>
          <div class="text-muted small">Objetivo: resueltos alcance/supere creados ✅</div>
        </div>
        <div style="height:320px" class="mt-2">
          <canvas id="chartTrend"></canvas>
        </div>
      </div></div>
    </div>
  </div>

  <!-- Tabla diaria -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="h6 mb-0">Detalle diario (ventana)</div>
        <div class="text-muted small">FRT: primer mensaje distinto a <strong><?=h($autorCliente)?></strong></div>
      </div>

      <div class="table-responsive mt-2">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th>Día</th>
              <th class="text-end">Creados</th>
              <th class="text-end">Resueltos</th>
              <th class="text-end">Avg 1ra resp (min)</th>
              <th class="text-end">Avg resolución (h)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$serie): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">Sin datos en este rango.</td></tr>
            <?php else: foreach ($serie as $d): ?>
              <tr>
                <td><?=h($d['dia'])?></td>
                <td class="text-end"><?= (int)$d['creados'] ?></td>
                <td class="text-end"><?= (int)$d['resueltos'] ?></td>
                <td class="text-end"><?= $d['avg_min_primera_respuesta']===null ? '—' : (int)round($d['avg_min_primera_respuesta']) ?></td>
                <td class="text-end"><?= $d['avg_horas_resolucion']===null ? '—' : (int)round($d['avg_horas_resolucion']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Top sucursales backlog -->
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="h6 mb-2">Top sucursales con más backlog (real)</div>
      <div class="row g-2">
        <?php if (!$topSuc): ?>
          <div class="text-muted">Sin backlog (o sin datos) con estos filtros.</div>
        <?php else: foreach ($topSuc as $x): ?>
          <?php
            $sid = (int)($x['sucursal_origen_id'] ?? 0);
            $nom = $sucursales[$sid] ?? ('Sucursal #'.$sid);
            $c   = (int)($x['c'] ?? 0);
          ?>
          <div class="col-12 col-md-6 col-xl-3">
            <div class="border rounded p-2 bg-light d-flex justify-content-between align-items-center">
              <div class="small fw-semibold"><?=h($nom)?></div>
              <span class="badge bg-dark"><?=$c?></span>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="text-muted small mt-3">
        Tip: si quieres “creados por origen por día” (mini tabla) también lo metemos y queda 🔥.
      </div>
    </div>
  </div>

</div>

<script>
const labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
const creados = <?= json_encode($dataCreados, JSON_UNESCAPED_UNICODE) ?>;
const resueltos = <?= json_encode($dataResueltos, JSON_UNESCAPED_UNICODE) ?>;

const ctx = document.getElementById('chartTrend');
new Chart(ctx, {
  type: 'line',
  data: {
    labels,
    datasets: [
      { label: 'Creados', data: creados, tension: 0.25 },
      { label: 'Resueltos', data: resueltos, tension: 0.25 }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'top' } },
    scales: { y: { beginAtZero: true } }
  }
});
</script>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
