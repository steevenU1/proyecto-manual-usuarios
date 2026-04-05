<?php
// descansos_semana_sucursal.php — Listado GLOBAL de descansos por semana (Mar→Lun) y sucursal
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');

$ROL = $_SESSION['rol'] ?? '';
if (!in_array($ROL, ['Admin','GerenteZona','Logistica'], true)) {
  header("Location: 403.php"); exit();
}

/* ===== Charset ===== */
if (method_exists($conn, 'set_charset')) { @$conn->set_charset('utf8mb4'); }
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function semanaOperativaDesdeFecha(string $ymd): DateTime {
  $d = new DateTime($ymd);
  $dow = (int)$d->format('N'); // 1=lun ... 7=dom
  $offset = ($dow >= 2) ? $dow - 2 : 6 + $dow; // martes=2
  $d->modify("-{$offset} days")->setTime(0,0,0);
  return $d; // martes
}

function rangoHumano(DateTime $martes): string {
  $fin = (clone $martes)->modify('+6 day');
  return
    'Martes '.$martes->format('d').' de '.strftime('%B', $martes->getTimestamp()).
    ' → Lunes '.$fin->format('d').' de '.strftime('%B', $fin->getTimestamp());
}

setlocale(LC_TIME, 'es_MX.UTF-8', 'spanish');

/* ===== Fecha base ===== */
$fechaBase = $_GET['fecha'] ?? date('Y-m-d');
$martes = semanaOperativaDesdeFecha($fechaBase);
$inicio = $martes->format('Y-m-d');
$fin    = (clone $martes)->modify('+6 day')->format('Y-m-d');

/* ===== Sucursales ===== */
$sucursales = [];
$rs = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");
while ($r = $rs->fetch_assoc()) {
  $sucursales[(int)$r['id']] = [
    'nombre' => $r['nombre'],
    'staff' => 0,
    'descansos' => 0
  ];
}
$rs->close();

/* ===== Staff por sucursal ===== */
$rs = $conn->query("
  SELECT id_sucursal, COUNT(*) total
  FROM usuarios
  WHERE activo=1
  GROUP BY id_sucursal
");
while ($r = $rs->fetch_assoc()) {
  $sid = (int)$r['id_sucursal'];
  if (isset($sucursales[$sid])) {
    $sucursales[$sid]['staff'] = (int)$r['total'];
  }
}
$rs->close();

/* ===== Descansos ===== */
$descansos = [];
$st = $conn->prepare("
  SELECT
    dp.fecha,
    dp.dia_descanso,
    u.nombre AS usuario,
    s.nombre AS sucursal,
    u2.nombre AS asignador,
    s.id AS id_sucursal
  FROM descansos_programados dp
  JOIN usuarios u ON u.id = dp.id_usuario
  JOIN sucursales s ON s.id = u.id_sucursal
  LEFT JOIN usuarios u2 ON u2.id = dp.asignado_por
  WHERE dp.es_descanso = 1
    AND dp.fecha BETWEEN ? AND ?
  ORDER BY s.nombre, u.nombre, dp.fecha
");
$st->bind_param('ss', $inicio, $fin);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

foreach ($rows as $r) {
  $sid = (int)$r['id_sucursal'];
  $descansos[] = $r;
  if (isset($sucursales[$sid])) {
    $sucursales[$sid]['descansos']++;
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Descansos por sucursal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    body{ background:#f6f8fb; }
    .card-elev{ border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(15,23,42,.06); }
    .table-xs td,.table-xs th{ padding:.45rem .6rem; font-size:.92rem; }
    .badge-zero{ background:#fee2e2; color:#991b1b; }
    .badge-ok{ background:#dcfce7; color:#166534; }
  </style>
</head>
<body>

<div class="container my-4">

  <div class="d-flex justify-content-between align-items-end mb-3">
    <div>
      <h3><i class="bi bi-moon-stars me-2"></i>Descansos programados</h3>
      <div class="text-muted small"><?= h(ucfirst(rangoHumano($martes))) ?></div>
    </div>
    <form method="get" class="d-flex gap-2">
      <input type="date" name="fecha" value="<?= h($fechaBase) ?>" class="form-control form-control-sm">
      <button class="btn btn-primary btn-sm">Ver</button>
    </form>
  </div>

  <div class="card card-elev mb-4">
    <div class="card-body p-0">
      <table class="table table-bordered table-xs mb-0">
        <thead class="table-dark">
          <tr>
            <th>Sucursal</th>
            <th class="text-end">Colaboradores</th>
            <th class="text-end">Descansos</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($sucursales as $s): ?>
          <tr>
            <td><?= h($s['nombre']) ?></td>
            <td class="text-end"><?= (int)$s['staff'] ?></td>
            <td class="text-end"><?= (int)$s['descansos'] ?></td>
            <td>
              <?= $s['descansos'] === 0
                ? '<span class="badge badge-zero">SIN DESCANSOS</span>'
                : '<span class="badge badge-ok">OK</span>' ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card card-elev">
    <div class="card-header"><b>Listado detallado</b></div>
    <div class="card-body p-0">
      <table class="table table-striped table-xs mb-0">
        <thead class="table-dark">
          <tr>
            <th>Sucursal</th>
            <th>Colaborador</th>
            <th>Fecha</th>
            <th>Día</th>
            <th>Asignado por</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$descansos): ?>
          <tr><td colspan="5" class="text-muted p-3">No hay descansos registrados en esta semana.</td></tr>
        <?php else: foreach ($descansos as $d): ?>
          <tr>
            <td><?= h($d['sucursal']) ?></td>
            <td><?= h($d['usuario']) ?></td>
            <td><?= h($d['fecha']) ?></td>
            <td><?= h($d['dia_descanso']) ?></td>
            <td><?= h($d['asignador'] ?? '—') ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
</body>
</html>
