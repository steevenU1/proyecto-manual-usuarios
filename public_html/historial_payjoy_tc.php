<?php
// historial_payjoy_tc.php — Historial de TC PayJoy
// ✅ Export CSV dentro del mismo archivo (descarga real)
// ✅ Bugfix view week/month sin bugearse por mes
// ✅ Filtro Subdis si existe v.id_subdis

ob_start(); // 🔥 buffer para evitar “headers already sent”

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ========================
   Helpers
======================== */
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function tableExists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $sql = "
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = '{$t}'
    LIMIT 1
  ";
  $r = $conn->query($sql);
  return (bool)$r && $r->num_rows > 0;
}

function hasColumn(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = '{$t}'
      AND COLUMN_NAME = '{$c}'
    LIMIT 1
  ";
  $r = $conn->query($sql);
  return (bool)$r && $r->num_rows > 0;
}

function obtenerSemanaPorIndice(int $offset = 0): array {
  $hoy = new DateTime();
  $dia = (int)$hoy->format('N'); // 1=lun..7=dom
  $dif = $dia - 2; if ($dif < 0) $dif += 7; // martes=2
  $inicio = (new DateTime())->modify("-$dif days")->setTime(0,0,0);
  if ($offset > 0) $inicio->modify('-'.(7*$offset).' days');
  $fin = (clone $inicio)->modify('+6 days')->setTime(23,59,59);
  return [$inicio,$fin];
}
function rangoMensualDesde(DateTime $cualquierDiaDelMes): array {
  $mIni = (clone $cualquierDiaDelMes)->modify('first day of this month')->setTime(0,0,0);
  $mFin = (clone $cualquierDiaDelMes)->modify('last day of this month')->setTime(23,59,59);
  return [$mIni, $mFin];
}
function safeMonth(string $ym, string $fallbackYm): string {
  return preg_match('/^\d{4}\-(0[1-9]|1[0-2])$/', $ym) ? $ym : $fallbackYm;
}

/* ========================
   Roles / Sesión
======================== */
$ROL        = $_SESSION['rol'] ?? 'Ejecutivo';
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

$isAdmin     = in_array($ROL, ['Admin','SuperAdmin','RH'], true);
$isGerente   = in_array($ROL, ['Gerente','Gerente General','GerenteZona','GerenteSucursal'], true);
$isEjecutivo = !$isAdmin && !$isGerente;

/* ========================
   Subdis soporte
======================== */
$VENTAS_TBL   = 'ventas_payjoy_tc';
$hasSubdisCol = hasColumn($conn, $VENTAS_TBL, 'id_subdis');

/* ========================
   GET filtros
======================== */
$fil_suc  = isset($_GET['id_sucursal']) ? (int)$_GET['id_sucursal'] : 0;
$fil_user = isset($_GET['id_usuario'])  ? (int)$_GET['id_usuario']  : 0;
$q        = trim((string)($_GET['q'] ?? ''));

$fil_subdis = 0;
if ($hasSubdisCol) {
  $fil_subdis = isset($_GET['id_subdis']) ? (int)$_GET['id_subdis'] : 0;
}

/* ========================
   Vista (week|month) BUGFIX
======================== */
$view = strtolower(trim((string)($_GET['view'] ?? 'week')));
if (!in_array($view, ['week','month'], true)) $view = 'week';

$todayYm = (new DateTime())->format('Y-m');
$mes_ui  = safeMonth((string)($_GET['mes'] ?? $todayYm), $todayYm);

$semana = isset($_GET['semana']) ? max(0,(int)$_GET['semana']) : 0;

/* Normalización por rol */
if ($isEjecutivo) {
  $fil_suc   = $idSucursal;
  $fil_user  = $idUsuario;
  $fil_subdis = 0;
} elseif ($isGerente) {
  $fil_suc   = $idSucursal;
  $fil_subdis = 0;
}

/* Rango final */
if ($view === 'month') {
  $dtMes = new DateTime($mes_ui . '-01');
  list($iniObj, $finObj) = rangoMensualDesde($dtMes);
} else {
  list($iniObj, $finObj) = obtenerSemanaPorIndice($semana);
}
$ini = $iniObj->format('Y-m-d 00:00:00');
$fin = $finObj->format('Y-m-d 23:59:59');

/* ========================
   Query Builder
======================== */
function buildMainQuery(
  bool $isEjecutivo,
  bool $isGerente,
  bool $isAdmin,
  int $idUsuario,
  int $idSucursal,
  int $fil_suc,
  int $fil_user,
  string $q,
  bool $hasSubdisCol,
  int $fil_subdis
): array {
  $sql = "
    SELECT v.id, v.fecha_venta, v.nombre_cliente, v.tag, v.comision,
           u.nombre AS usuario,
           s.nombre AS sucursal
  ";
  if ($hasSubdisCol) $sql .= ", v.id_subdis ";
  $sql .= "
    FROM ventas_payjoy_tc v
    INNER JOIN usuarios u   ON u.id = v.id_usuario
    INNER JOIN sucursales s ON s.id = v.id_sucursal
    WHERE v.fecha_venta BETWEEN ? AND ?
  ";

  $types  = "ss";
  $params = [];

  if ($isEjecutivo) {
    $sql .= " AND v.id_usuario=? AND v.id_sucursal=? ";
    $types .= "ii";
    $params[] = $idUsuario; $params[] = $idSucursal;
  } elseif ($isGerente) {
    $sql .= " AND v.id_sucursal=? ";
    $types .= "i";
    $params[] = $idSucursal;
    if ($fil_user > 0) {
      $sql .= " AND v.id_usuario=? ";
      $types .= "i";
      $params[] = $fil_user;
    }
  } else { // Admin
    if ($fil_suc > 0) {
      $sql .= " AND v.id_sucursal=? ";
      $types .= "i";
      $params[] = $fil_suc;
    }
    if ($fil_user > 0) {
      $sql .= " AND v.id_usuario=? ";
      $types .= "i";
      $params[] = $fil_user;
    }
    if ($hasSubdisCol && $fil_subdis > 0) {
      $sql .= " AND v.id_subdis=? ";
      $types .= "i";
      $params[] = $fil_subdis;
    }
  }

  if ($q !== '') {
    $sql .= " AND (v.tag LIKE CONCAT('%',?,'%') OR v.nombre_cliente LIKE CONCAT('%',?,'%')) ";
    $types .= "ss";
    $params[] = $q; $params[] = $q;
  }

  return [$sql, $types, $params];
}

/* ========================
   ✅ EXPORT CSV (ANTES del navbar / HTML)
======================== */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  list($sqlBase, $types, $extraParams) = buildMainQuery(
    $isEjecutivo, $isGerente, $isAdmin,
    $idUsuario, $idSucursal,
    $fil_suc, $fil_user,
    $q,
    $hasSubdisCol, $fil_subdis
  );

  $sql = $sqlBase . " ORDER BY v.fecha_venta DESC, v.id DESC ";
  $stmt = $conn->prepare($sql);
  $params = array_merge([$ini, $fin], $extraParams);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $res = $stmt->get_result();

  $scopeName = ($view === 'month')
    ? ("MES_" . $iniObj->format('Y-m'))
    : ("SEMANA_" . $iniObj->format('Ymd') . "_" . $finObj->format('Ymd'));

  $filename = "payjoy_tc_" . $scopeName . ".csv";

  // Limpia cualquier salida previa
  if (ob_get_length()) { ob_end_clean(); }

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  header('X-Content-Type-Options: nosniff');

  // BOM UTF-8 para Excel
  echo "\xEF\xBB\xBF";

  $out = fopen('php://output', 'w');
  $headers = ['ID','Fecha','Sucursal','Usuario','Cliente','TAG','Comision'];
  if ($hasSubdisCol) $headers[] = 'Subdis';
  fputcsv($out, $headers);

  while ($r = $res->fetch_assoc()) {
    $fecha = (new DateTime($r['fecha_venta']))->format('Y-m-d H:i');
    $row = [
      (int)$r['id'],
      $fecha,
      $r['sucursal'],
      $r['usuario'],
      $r['nombre_cliente'],
      $r['tag'],
      number_format((float)$r['comision'], 2, '.', '')
    ];
    if ($hasSubdisCol) $row[] = (int)($r['id_subdis'] ?? 0);
    fputcsv($out, $row);
  }
  fclose($out);
  $stmt->close();
  exit();
}

/* ========================
   Ya ahora sí: navbar + render normal
======================== */
require_once __DIR__ . '/navbar.php';

/* ========================
   Cargar selects (Sucursal/Usuario/Subdis)
======================== */
$sucursales = [];
$usuarios   = [];
$subdisOps  = [];

if ($isAdmin) {
  $rs = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
  while ($r = $rs->fetch_assoc()) $sucursales[] = $r;

  $ru = $conn->query("SELECT id, nombre FROM usuarios ORDER BY nombre");
  while ($r = $ru->fetch_assoc()) $usuarios[] = $r;

  if ($hasSubdisCol) {
    if (tableExists($conn, 'subdistribuidores') && hasColumn($conn, 'subdistribuidores', 'id') && hasColumn($conn, 'subdistribuidores', 'nombre')) {
      $rx = $conn->query("SELECT id, nombre FROM subdistribuidores ORDER BY nombre");
      while ($r = $rx->fetch_assoc()) $subdisOps[] = $r;
    } else {
      $rx = $conn->query("SELECT DISTINCT id_subdis AS id FROM {$VENTAS_TBL} WHERE id_subdis IS NOT NULL AND id_subdis <> 0 ORDER BY id_subdis");
      while ($r = $rx->fetch_assoc()) {
        $subdisOps[] = ['id' => (int)$r['id'], 'nombre' => 'Subdis #' . (int)$r['id']];
      }
    }
  }
} elseif ($isGerente) {
  $stmtU = $conn->prepare("SELECT id, nombre FROM usuarios WHERE id_sucursal=? ORDER BY nombre");
  $stmtU->bind_param("i", $idSucursal);
  $stmtU->execute();
  $ru = $stmtU->get_result();
  while ($r = $ru->fetch_assoc()) $usuarios[] = $r;
  $stmtU->close();
}

/* ========================
   Query principal tabla
======================== */
list($sqlBase, $types, $extraParams) = buildMainQuery(
  $isEjecutivo, $isGerente, $isAdmin,
  $idUsuario, $idSucursal,
  $fil_suc, $fil_user,
  $q,
  $hasSubdisCol, $fil_subdis
);

$sql = $sqlBase . " ORDER BY v.fecha_venta DESC, v.id DESC ";
$stmt = $conn->prepare($sql);
$params = array_merge([$ini, $fin], $extraParams);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$totalRegs = 0;
$totalCom  = 0.0;
while ($r = $res->fetch_assoc()) {
  $rows[] = $r;
  $totalRegs++;
  $totalCom += (float)$r['comision'];
}
$stmt->close();

$promedio = $totalRegs > 0 ? ($totalCom / $totalRegs) : 0.0;

/* Top usuarios */
$topUsuarios = [];
if (!$isEjecutivo) {
  $sqlTop = "
    SELECT u.nombre AS usuario, COUNT(*) AS ventas, SUM(v.comision) AS com_total
    FROM ventas_payjoy_tc v
    INNER JOIN usuarios u ON u.id = v.id_usuario
    WHERE v.fecha_venta BETWEEN ? AND ?
  ";
  $pTop = [$ini,$fin]; $tTop = "ss";

  if ($isGerente) {
    $sqlTop .= " AND v.id_sucursal=? ";
    $pTop[] = $idSucursal; $tTop .= "i";
    if ($fil_user > 0) { $sqlTop .= " AND v.id_usuario=? "; $pTop[] = $fil_user; $tTop .= "i"; }
  } else {
    if ($fil_suc > 0)  { $sqlTop .= " AND v.id_sucursal=? "; $pTop[]=$fil_suc;  $tTop.="i"; }
    if ($fil_user > 0) { $sqlTop .= " AND v.id_usuario=? ";  $pTop[]=$fil_user; $tTop.="i"; }
    if ($hasSubdisCol && $fil_subdis > 0) { $sqlTop .= " AND v.id_subdis=? "; $pTop[]=$fil_subdis; $tTop.="i"; }
  }
  if ($q !== '') { $sqlTop .= " AND (v.tag LIKE CONCAT('%',?,'%') OR v.nombre_cliente LIKE CONCAT('%',?,'%')) "; $pTop[]=$q; $pTop[]=$q; $tTop.="ss"; }

  $sqlTop .= " GROUP BY u.id, u.nombre ORDER BY ventas DESC, com_total DESC LIMIT 5";
  $st = $conn->prepare($sqlTop);
  $st->bind_param($tTop, ...$pTop);
  $st->execute();
  $rt = $st->get_result();
  while ($row = $rt->fetch_assoc()) $topUsuarios[] = $row;
  $st->close();
}

$roleBadge = $isAdmin ? 'Admin' : ($isGerente ? 'Gerente' : 'Ejecutivo');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Historial TC PayJoy</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
  :root { --radius: 18px; }
  body { background:#f6f8fb; }
  .page { padding: 16px; }
  .sticky-actions{
    position: sticky; top:0; z-index: 1010;
    background: rgba(246,248,251,.92);
    backdrop-filter: blur(8px);
    padding: 10px 0 10px;
    border-bottom: 1px solid rgba(0,0,0,.05);
  }
  .filter-bar{
    width:100%;
    border-radius: var(--radius);
    background:#fff;
    border: 1px solid rgba(0,0,0,.06);
    box-shadow: 0 10px 25px rgba(0,0,0,.06);
    padding: 12px;
  }
  .filter-bar .form-label{ color:#6c757d; font-weight:700; }
  .filter-bar .form-control, .filter-bar .form-select{ border-radius: 14px; }
  .badge-role { font-weight:700; }
  .kpi {
    border-radius: var(--radius);
    padding:16px;
    background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
    border:1px solid rgba(71,119,255,.14);
    box-shadow: 0 10px 24px rgba(0,0,0,.06);
    height: 100%;
  }
  .kpi h6 { margin:0; font-weight:900; color:#3b6cff; font-size:.92rem; }
  .kpi .v { font-size:1.55rem; font-weight:900; letter-spacing:-.5px; }
  .kpi .hint { color:#6c757d; font-size:.85rem; }
  .table-rounded {
    border-radius: var(--radius);
    overflow:hidden;
    border:1px solid rgba(0,0,0,.06);
    background:#fff;
    box-shadow: 0 10px 24px rgba(0,0,0,.06);
  }
</style>
</head>
<body>
<div class="page container-fluid">

  <div class="sticky-actions">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
      <h3 class="m-0">💳 PayJoy · Historial</h3>
      <span class="badge bg-secondary badge-role"><?= h($roleBadge) ?></span>
    </div>

    <div class="filter-bar">
      <form class="row gy-2 gx-2 align-items-end" method="get">
        <div class="col-6 col-md-2">
          <label class="form-label mb-0 small">Vista</label>
          <select class="form-select form-select-sm" name="view"
            onchange="if(this.value==='week'){ const m=this.form.querySelector('[name=mes]'); if(m) m.value=''; } this.form.submit()">
            <option value="week"  <?= $view==='week'?'selected':'' ?>>Semana</option>
            <option value="month" <?= $view==='month'?'selected':'' ?>>Mes</option>
          </select>
        </div>

        <?php if ($view === 'month'): ?>
          <div class="col-6 col-md-2">
            <label class="form-label mb-0 small">Mes</label>
            <input class="form-control form-control-sm" type="month" name="mes" value="<?= h($mes_ui) ?>">
          </div>
        <?php else: ?>
          <input type="hidden" name="mes" value="">
        <?php endif; ?>

        <?php if ($view === 'week'): ?>
          <input type="hidden" name="semana" value="<?= (int)$semana ?>">
        <?php endif; ?>

        <?php if ($isAdmin): ?>
          <div class="col-6 col-md-2">
            <label class="form-label mb-0 small">Sucursal</label>
            <select class="form-select form-select-sm" name="id_sucursal">
              <option value="0">Todas</option>
              <?php foreach ($sucursales as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= ($fil_suc===(int)$s['id']?'selected':'') ?>><?= h($s['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label mb-0 small">Usuario</label>
            <select class="form-select form-select-sm" name="id_usuario">
              <option value="0">Todos</option>
              <?php foreach ($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= ($fil_user===(int)$u['id']?'selected':'') ?>><?= h($u['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php if ($hasSubdisCol): ?>
            <div class="col-6 col-md-2">
              <label class="form-label mb-0 small">Subdis</label>
              <select class="form-select form-select-sm" name="id_subdis">
                <option value="0">Todos</option>
                <?php foreach ($subdisOps as $sd): ?>
                  <option value="<?= (int)$sd['id'] ?>" <?= ($fil_subdis===(int)$sd['id']?'selected':'') ?>><?= h($sd['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

        <?php elseif ($isGerente): ?>
          <div class="col-6 col-md-2">
            <label class="form-label mb-0 small">Sucursal</label>
            <input class="form-control form-control-sm" value="Tu sucursal (ID: <?= (int)$idSucursal ?>)" disabled>
          </div>

          <div class="col-6 col-md-2">
            <label class="form-label mb-0 small">Usuario</label>
            <select class="form-select form-select-sm" name="id_usuario">
              <option value="0">Todos</option>
              <?php foreach ($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= ($fil_user===(int)$u['id']?'selected':'') ?>><?= h($u['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <input type="hidden" name="id_sucursal" value="<?= (int)$idSucursal ?>">
          <input type="hidden" name="id_usuario"  value="<?= (int)$idUsuario ?>">
        <?php endif; ?>

        <div class="col-8 col-md-2">
          <label class="form-label mb-0 small">Buscar</label>
          <input class="form-control form-control-sm" type="text" name="q" value="<?= h($q) ?>" placeholder="TAG o cliente">
        </div>

        <div class="col-4 col-md-2 d-grid">
          <button class="btn btn-sm btn-primary">Filtrar</button>
        </div>
      </form>
    </div>
  </div>

  <div class="row g-3 mt-3">
    <div class="col-12 col-lg-4">
      <div class="kpi">
        <h6><?= $view==='month' ? 'Mes seleccionado' : 'Semana (mar→lun)' ?></h6>
        <div class="v">
          <?php if ($view==='month'): ?>
            <?= h($iniObj->format('M Y')) ?>
          <?php else: ?>
            <?= h($iniObj->format('d/m/Y')) ?> — <?= h($finObj->format('d/m/Y')) ?>
          <?php endif; ?>
        </div>
        <div class="hint"><?= h($iniObj->format('d/m')) ?> — <?= h($finObj->format('d/m')) ?></div>
      </div>
    </div>

    <div class="col-6 col-lg-2">
      <div class="kpi"><h6>Ventas</h6><div class="v"><?= (int)$totalRegs ?></div><div class="hint">Registros</div></div>
    </div>

    <div class="col-6 col-lg-3">
      <div class="kpi"><h6>Comisión</h6><div class="v">$<?= number_format($totalCom,2) ?></div><div class="hint">$100 c/u</div></div>
    </div>

    <div class="col-12 col-lg-3">
      <div class="kpi"><h6>Promedio</h6><div class="v">$<?= number_format($promedio,2) ?></div><div class="hint">por venta</div></div>
    </div>
  </div>

  <div class="d-flex flex-wrap gap-2 mt-3">
    <?php
      $currentGet = $_GET;
      $currentGet['view'] = $view;
      if ($view === 'week') $currentGet['mes'] = '';
    ?>

    <?php if ($view === 'week'): ?>
      <?php $gPrev = $currentGet; $gPrev['semana'] = (int)$semana + 1; ?>
      <a class="btn btn-sm btn-outline-secondary" href="?<?= http_build_query($gPrev) ?>">← Semana anterior</a>

      <?php $gNow = $currentGet; $gNow['semana'] = 0; ?>
      <a class="btn btn-sm btn-secondary" href="?<?= http_build_query($gNow) ?>">Semana actual</a>
    <?php else: ?>
      <?php $gBack = $currentGet; $gBack['view'] = 'week'; $gBack['mes'] = ''; ?>
      <a class="btn btn-sm btn-outline-secondary" href="?<?= http_build_query($gBack) ?>">Ver por semana</a>
    <?php endif; ?>

    <a class="btn btn-sm btn-outline-success" href="payjoy_tc_nueva.php">+ Nueva venta</a>

    <?php $gExp = $currentGet; $gExp['export'] = 'csv'; ?>
    <a class="btn btn-sm btn-outline-primary" href="?<?= http_build_query($gExp) ?>">
      Exportar CSV (<?= $view==='month' ? 'Mes' : 'Semana' ?>)
    </a>
  </div>

  <div class="table-responsive mt-3 table-rounded">
    <table class="table table-hover align-middle m-0">
      <thead class="table-light">
        <tr>
          <th>#</th><th>Fecha</th><th>Sucursal</th><th>Usuario</th><th>Cliente</th><th>TAG</th>
          <?php if ($hasSubdisCol): ?><th class="text-center">Subdis</th><?php endif; ?>
          <th class="text-end">Comisión</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= h((new DateTime($r['fecha_venta']))->format('d/m/Y H:i')) ?></td>
          <td><?= h($r['sucursal']) ?></td>
          <td><?= h($r['usuario']) ?></td>
          <td><?= h($r['nombre_cliente']) ?></td>
          <td><span class="badge bg-light text-dark"><?= h($r['tag']) ?></span></td>
          <?php if ($hasSubdisCol): ?>
            <td class="text-center">
              <?php $sd = (int)($r['id_subdis'] ?? 0); ?>
              <?= $sd>0 ? '<span class="badge bg-info text-dark">#'.$sd.'</span>' : '<span class="text-muted">—</span>' ?>
            </td>
          <?php endif; ?>
          <td class="text-end">$<?= number_format((float)$r['comision'],2) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="<?= $hasSubdisCol ? 8 : 7 ?>" class="text-center text-muted py-4">Sin registros en el rango.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>
</body>
</html>