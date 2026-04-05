<?php
// portal_solicitante_listado.php — Portal Solicitante (LUGA/NANO/MIPLAN)
// Listado tipo portal cliente: tabs por estatus + buscador + cards + acceso a detalle + CTA "Nueva solicitud".

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';

// Navbar (si imprime HTML, no hacemos header() después)
require_once __DIR__ . '/navbar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ====== CONFIG ORIGEN (ideal: desde sesión, fallback a LUGA) ======
$ORIGEN_UI = (string)($_SESSION['origen_portal'] ?? $_SESSION['empresa_clave'] ?? 'LUGA'); // 'NANO' | 'MIPLAN' | 'LUGA'
$ORIGEN_UI = strtoupper(trim($ORIGEN_UI));
if (!in_array($ORIGEN_UI, ['NANO','MIPLAN','LUGA'], true)) $ORIGEN_UI = 'LUGA';

// Rutas clave
$URL_NUEVO   = 'portal_solicitante_nuevo.php';
$URL_DETALLE = 'portal_solicitante_detalle.php';

// ====== Parámetros ======
$q   = trim((string)($_GET['q'] ?? ''));
$tab = strtoupper(trim((string)($_GET['tab'] ?? 'PENDIENTES')));

// Tabs permitidos
$TABS = [
  'PENDIENTES'  => ['EN_AUTORIZACION_SOLICITANTE'],
  'EN_PROCESO'  => ['EN_VALORACION_SISTEMAS','EN_COSTEO','EN_VALIDACION_COSTO_SISTEMAS'],
  'AUTORIZADOS' => ['AUTORIZADO','EN_EJECUCION','FINALIZADO'],
  'RECHAZADOS'  => ['RECHAZADO','CANCELADO'],
  'TODOS'       => [] // sin filtro
];
if (!isset($TABS[$tab])) $tab = 'PENDIENTES';

// Labels / Badge
$ESTATUS_LABEL = [
  'EN_VALORACION_SISTEMAS'        => 'En valoración (Sistemas)',
  'EN_COSTEO'                     => 'En costeo (Costos)',
  'EN_VALIDACION_COSTO_SISTEMAS'  => 'Validación de costo (Sistemas)',
  'EN_AUTORIZACION_SOLICITANTE'   => 'Autorización requerida',
  'AUTORIZADO'                    => 'Autorizado',
  'EN_EJECUCION'                  => 'En ejecución',
  'FINALIZADO'                    => 'Finalizado',
  'RECHAZADO'                     => 'Rechazado',
  'CANCELADO'                     => 'Cancelado',
];

function badgeClass($st){
  if ($st === 'EN_AUTORIZACION_SOLICITANTE') return 'text-bg-warning';
  if ($st === 'AUTORIZADO') return 'text-bg-success';
  if ($st === 'RECHAZADO' || $st === 'CANCELADO') return 'text-bg-danger';
  if ($st === 'EN_EJECUCION') return 'text-bg-primary';
  if ($st === 'FINALIZADO') return 'text-bg-dark';
  if ($st === 'EN_COSTEO' || $st === 'EN_VALIDACION_COSTO_SISTEMAS' || $st === 'EN_VALORACION_SISTEMAS') return 'text-bg-secondary';
  return 'text-bg-secondary';
}

// ====== Contadores por tab ======
function countByStatuses(mysqli $conn, string $empresaClave, array $statuses): int {
  if (empty($statuses)) {
    $stmt = $conn->prepare("
      SELECT COUNT(*) c
      FROM portal_proyectos_solicitudes s
      JOIN portal_empresas e ON e.id = s.empresa_id
      WHERE e.clave=?
    ");
    $stmt->bind_param("s", $empresaClave);
  } else {
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $sql = "
      SELECT COUNT(*) c
      FROM portal_proyectos_solicitudes s
      JOIN portal_empresas e ON e.id = s.empresa_id
      WHERE e.clave=? AND s.estatus IN ($in)
    ";
    $stmt = $conn->prepare($sql);
    $types  = 's' . str_repeat('s', count($statuses));
    $params = array_merge([$empresaClave], $statuses);
    $stmt->bind_param($types, ...$params);
  }

  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (int)($row['c'] ?? 0);
}

$counts = [
  'PENDIENTES'  => countByStatuses($conn, $ORIGEN_UI, $TABS['PENDIENTES']),
  'EN_PROCESO'  => countByStatuses($conn, $ORIGEN_UI, $TABS['EN_PROCESO']),
  'AUTORIZADOS' => countByStatuses($conn, $ORIGEN_UI, $TABS['AUTORIZADOS']),
  'RECHAZADOS'  => countByStatuses($conn, $ORIGEN_UI, $TABS['RECHAZADOS']),
  'TODOS'       => countByStatuses($conn, $ORIGEN_UI, []),
];

// ====== Query principal ======
$where  = " e.clave=? ";
$params = [$ORIGEN_UI];
$types  = "s";

$statuses = $TABS[$tab];
if (!empty($statuses)) {
  $in = implode(',', array_fill(0, count($statuses), '?'));
  $where .= " AND s.estatus IN ($in) ";
  $types .= str_repeat('s', count($statuses));
  foreach ($statuses as $st) $params[] = $st;
}

// búsqueda
if ($q !== '') {
  $where .= " AND (s.folio LIKE ? OR s.titulo LIKE ? OR s.descripcion LIKE ?) ";
  $types .= "sss";
  $like = "%$q%";
  $params[] = $like; $params[] = $like; $params[] = $like;
}

$sql = "
  SELECT
    s.id, s.folio, s.titulo, s.tipo, s.prioridad, s.estatus,
    s.costo_mxn, s.created_at, s.descripcion,
    e.nombre empresa_nombre, e.clave empresa_clave
  FROM portal_proyectos_solicitudes s
  JOIN portal_empresas e ON e.id = s.empresa_id
  WHERE $where
  ORDER BY s.created_at DESC
  LIMIT 200
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function short($s, $n=120){
  $s = trim((string)$s);
  if (mb_strlen($s) <= $n) return $s;
  return mb_substr($s,0,$n-1).'…';
}

// URL helpers (mantener tab/q)
function buildUrl($tab, $q=''){
  $u = '?tab=' . urlencode($tab);
  if ($q !== '') $u .= '&q=' . urlencode($q);
  return $u;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Portal Solicitante • Proyectos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --radius:18px; }
    body{ background:#f5f7fb; }
    .card{ border-radius: var(--radius); }
    .soft-shadow{ box-shadow: 0 10px 30px rgba(20,20,40,.06); }
    .small-muted{ font-size:12px; color:#6c757d; }
    .label{ font-size:12px; color:#6c757d; }
    .pill{
      border:1px solid rgba(0,0,0,.08);
      border-radius:999px;
      padding:.25rem .65rem;
      font-size:12px;
      background:#fff;
      display:inline-flex;
      align-items:center;
      gap:.35rem;
    }
    .tabbtn{
      border-radius: 999px !important;
      padding:.4rem .8rem;
    }
    .project-card{
      transition: transform .08s ease, box-shadow .08s ease;
      border: 1px solid rgba(0,0,0,.06);
    }
    .project-card:hover{
      transform: translateY(-1px);
      box-shadow: 0 14px 40px rgba(20,20,40,.08);
    }
    .mono{
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }
    a.cleanlink{ text-decoration:none; color:inherit; }
    .btn-wide{ min-width: 170px; }
  </style>
</head>
<body>

<div class="container py-4">

  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <div class="small-muted">
        <span class="pill"><span class="mono">Central</span> <b><?= h($ORIGEN_UI) ?></b></span>
        <span class="mx-2">•</span>
        <span>Portal Solicitante</span>
      </div>
      <h3 class="m-0">Mis proyectos</h3>
      <div class="small-muted">Aquí autorizas o rechazas costos cuando aplique.</div>
    </div>

    <div class="d-flex flex-wrap gap-2 align-items-start justify-content-end">
      <a class="btn btn-primary btn-wide" href="<?= h($URL_NUEVO) ?>">+ Nueva solicitud</a>

      <form class="d-flex gap-2" method="get">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <input class="form-control" style="min-width:240px" type="search" name="q" value="<?= h($q) ?>" placeholder="Buscar folio, título, texto…">
        <button class="btn btn-dark">Buscar</button>
        <?php if($q !== ''): ?>
          <a class="btn btn-light" href="<?= h(buildUrl($tab)) ?>">Limpiar</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Tabs -->
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php
      $tabsOrder = ['PENDIENTES','EN_PROCESO','AUTORIZADOS','RECHAZADOS','TODOS'];
      foreach($tabsOrder as $t){
        $active = ($tab === $t);
        $cls = $active ? 'btn btn-dark tabbtn' : 'btn btn-outline-secondary tabbtn';
        $label = $t;
        if ($t==='PENDIENTES')  $label='Pendientes';
        if ($t==='EN_PROCESO')  $label='En proceso';
        if ($t==='AUTORIZADOS') $label='Autorizados';
        if ($t==='RECHAZADOS')  $label='Rechazados';
        if ($t==='TODOS')       $label='Todos';

        $url = buildUrl($t, $q);
        echo '<a class="'.$cls.'" href="'.h($url).'">'.$label.' <span class="badge text-bg-light ms-1">'.$counts[$t].'</span></a>';
      }
    ?>
  </div>

  <?php if(!$rows): ?>
    <div class="card soft-shadow">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
          <div>
            <div class="fw-semibold">No hay proyectos aquí todavía.</div>
            <div class="small-muted mt-1">
              Si es tu primera vez: crea una solicitud y te aparece aquí con su folio.
              <?php if($q !== ''): ?>
                <br>Tip: también puedes <a class="text-decoration-none" href="<?= h(buildUrl($tab)) ?>">limpiar la búsqueda</a>.
              <?php else: ?>
                <br>Tip: prueba cambiar de tab (por ejemplo “Todos”).
              <?php endif; ?>
            </div>
          </div>
          <div class="d-flex gap-2">
            <?php if($q !== ''): ?>
              <a class="btn btn-outline-secondary" href="<?= h(buildUrl($tab)) ?>">Limpiar filtro</a>
            <?php endif; ?>
            <a class="btn btn-primary" href="<?= h($URL_NUEVO) ?>">+ Nueva solicitud</a>
          </div>
        </div>
      </div>
    </div>
  <?php else: ?>

    <div class="row g-3">
      <?php foreach($rows as $r): ?>
        <?php
          $st = (string)$r['estatus'];
          $badge = badgeClass($st);
          $stLabel = $ESTATUS_LABEL[$st] ?? $st;
          $costo = ($r['costo_mxn'] !== null) ? ('$'.number_format((float)$r['costo_mxn'],2).' MXN') : '—';
          $created = substr((string)$r['created_at'],0,16);
          $prio = (string)($r['prioridad'] ?? '');
          $tipo = (string)($r['tipo'] ?? '');
          $isNeedAuth = ($st === 'EN_AUTORIZACION_SOLICITANTE');
          $detailUrl = $URL_DETALLE . '?id='.(int)$r['id'];
        ?>
        <div class="col-12 col-lg-6">
          <a class="cleanlink" href="<?= h($detailUrl) ?>">
            <div class="card project-card soft-shadow h-100">
              <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <div>
                    <div class="mono fw-semibold"><?= h($r['folio']) ?></div>
                    <div class="fs-5 fw-semibold"><?= h($r['titulo']) ?></div>
                    <div class="small-muted mt-1">
                      Creado: <?= h($created) ?>
                      <?= $tipo ? ' • Tipo: <b>'.h($tipo).'</b>' : '' ?>
                      <?= $prio ? ' • Prioridad: <b>'.h($prio).'</b>' : '' ?>
                    </div>
                  </div>
                  <div class="text-end">
                    <span class="badge <?= h($badge) ?>"><?= h($stLabel) ?></span>
                    <div class="mt-2">
                      <div class="label">Costo</div>
                      <div class="fw-bold"><?= h($costo) ?></div>
                    </div>
                  </div>
                </div>

                <hr>

                <div class="small-muted">
                  <?= h(short($r['descripcion'] ?? '', 160)) ?>
                </div>

                <?php if($isNeedAuth): ?>
                  <div class="mt-3">
                    <span class="badge text-bg-warning">⚠️ Requiere tu autorización</span>
                  </div>
                <?php endif; ?>

              </div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="small-muted mt-3">
      Nota: Este listado muestra máximo 200 resultados por performance.
    </div>

  <?php endif; ?>

</div>

</body>
</html>
