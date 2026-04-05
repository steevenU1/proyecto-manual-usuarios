<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

date_default_timezone_set('America/Mexico_City');
require 'db.php';

$hoy       = new DateTime('today');
$anioHoy   = (int)$hoy->format('Y');
$mesHoy    = (int)$hoy->format('n');
$diaHoy    = (int)$hoy->format('j');

$anio = isset($_GET['anio']) ? max(1970, (int)$_GET['anio']) : $anioHoy;
$mes  = isset($_GET['mes'])  ? min(12, max(1, (int)$_GET['mes'])) : $mesHoy;

$nombreMeses = [1 => "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];
$nombreMes = $nombreMeses[$mes];

function edadQueCumple(string $fnac, int $anio): int
{
  return $anio - (int)date('Y', strtotime($fnac));
}
function aniosServicioQueCumple(string $fing, int $anio): int
{
  return $anio - (int)date('Y', strtotime($fing));
}
function dia(string $f): int
{
  return (int)date('j', strtotime($f));
}
function placeholder($nombre = 'Colaborador')
{
  return 'https://ui-avatars.com/api/?name=' . urlencode($nombre) . '&background=E0F2FE&color=0F172A&bold=true';
}

// Iniciales (fallback cuando no hay foto)
function iniciales(string $nombre): string {
  $nombre = trim($nombre);
  if ($nombre === '') return '??';

  $nombre = preg_replace('/\s+/', ' ', $nombre);
  $parts = explode(' ', $nombre);

  // mb_* requiere ext-mbstring (normal en Laragon). Si no existiera, cae a substr.
  $mb = function_exists('mb_substr');
  $sub = function(string $s, int $start, int $len) use ($mb) {
    return $mb ? mb_substr($s, $start, $len, 'UTF-8') : substr($s, $start, $len);
  };

  $i1 = $sub($parts[0] ?? '', 0, 1);
  $i2 = '';
  if (count($parts) >= 2) {
    $i2 = $sub($parts[1] ?? '', 0, 1);
  } else {
    $i2 = $sub($parts[0] ?? '', 1, 1);
  }

  $ini = strtoupper($i1 . $i2);
  $ini = preg_replace('/[^A-ZÁÉÍÓÚÜÑ]/u', '', $ini);
  return $ini !== '' ? $ini : '??';
}
function fechaBonitaDiaMes(string $fecha, array $nombreMeses): string
{
  $d = (int)date('j', strtotime($fecha));
  $m = (int)date('n', strtotime($fecha));
  $mesNombre = $nombreMeses[$m] ?? '';
  return str_pad((string)$d, 2, '0', STR_PAD_LEFT) . " " . $mesNombre;
}

/* Cumpleaños */
$cumples = [];
$sql = "SELECT u.id, u.nombre, s.nombre AS sucursal, e.fecha_nacimiento, e.foto
        FROM usuarios u
        LEFT JOIN usuarios_expediente e ON e.usuario_id = u.id
        LEFT JOIN sucursales s ON s.id = u.id_sucursal
        WHERE u.activo=1 AND (e.fecha_baja IS NULL) AND e.fecha_nacimiento IS NOT NULL
          AND MONTH(e.fecha_nacimiento) = ?
        ORDER BY DAY(e.fecha_nacimiento), u.nombre";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $mes);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $cumples[] = $row;
$stmt->close();

/* Aniversarios */
$anv = [];
$sql = "SELECT u.id, u.nombre, s.nombre AS sucursal, e.fecha_ingreso, e.foto
        FROM usuarios u
        LEFT JOIN usuarios_expediente e ON e.usuario_id = u.id
        LEFT JOIN sucursales s ON s.id = u.id_sucursal
        WHERE u.activo=1 AND (e.fecha_baja IS NULL) AND e.fecha_ingreso IS NOT NULL
          AND MONTH(e.fecha_ingreso) = ?
        ORDER BY DAY(e.fecha_ingreso), u.nombre";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $mes);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $anv[] = $row;
$stmt->close();
?>
<!doctype html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <title>Celebraciones · <?= htmlspecialchars($nombreMes) . " " . $anio ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root {
      --bg: #f7f8fb;
      --ink: #0f172a;
      --muted: #64748b;
      --line: #e5e7eb;
      --pri: #2563eb;
      --today: #eaf3ff;
      --today-border: #c7dbff;
      --badge: #e9efff;
    }

    body {
      background: var(--bg);
      color: var(--ink)
    }

    .container {
      max-width: 1040px;
    }

    .page-title {
      font-weight: 800;
      letter-spacing: .2px;
      margin: 0;
      font-size: clamp(1.4rem, 2.1vw + .3rem, 2.1rem);
    }

    .subtle {
      color: var(--muted);
    }

    .filter-card {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 14px;
      box-shadow: 0 6px 16px rgba(16, 24, 40, .06);
    }

    .filter-card .form-select,
    .filter-card .form-control {
      height: 42px;
    }

    .btn-ghost {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 8px 12px;
    }

    .btn-ghost:hover {
      background: #f1f5f9;
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 12px;
    }

    .card-p {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 12px;
      transition: transform .06s ease, box-shadow .1s ease;
      cursor: pointer;
      position: relative;
    }

    .card-p:hover {
      transform: translateY(-1px);
      box-shadow: 0 8px 22px rgba(16, 24, 40, .08);
    }

    .card-p:active {
      transform: translateY(0px);
    }

    .card-p:focus-visible {
      outline: 3px solid rgba(37, 99, 235, .25);
      outline-offset: 2px;
    }

    .top {
      display: flex;
      gap: 12px;
      align-items: center;
    }

    .avatar {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid #fff;
      box-shadow: 0 1px 2px rgba(0, 0, 0, .06)
    }

    .name {
      font-weight: 800;
      line-height: 1.15;
    }

    .sub {
      color: var(--muted);
      font-size: 13px;
    }

    .badge-day {
      margin-left: auto;
      background: var(--badge);
      border: 1px solid #c7d2fe;
      border-radius: 999px;
      padding: 4px 9px;
      font-weight: 800;
      font-size: 12px;
      min-width: 34px;
      text-align: center;
    }

    .card-p.today {
      background: linear-gradient(180deg, #f3f8ff 0%, #eaf2ff 100%);
      border-color: var(--today-border);
    }

    .card-p.today::before {
      content: "HOY";
      position: absolute;
      top: -10px;
      left: 12px;
      background: #22c55e;
      color: #083d12;
      font-weight: 900;
      font-size: .72rem;
      padding: 3px 8px;
      border-radius: 999px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, .08);
    }

    .card-p.today .avatar {
      box-shadow: 0 0 0 3px #bae6fd, 0 0 0 6px #e0f2fe;
    }

    .card-p.today .badge-day {
      background: #1d4ed8;
      color: #fff;
      border-color: #1d4ed8;
    }

    @media (max-width:576px) {
      .navbar {
        --bs-navbar-padding-y: .65rem;
        font-size: 1rem;
      }

      .navbar .navbar-brand {
        font-size: 1.125rem;
        font-weight: 700;
      }

      .navbar .nav-link,
      .navbar .dropdown-item {
        font-size: 1rem;
        padding: .55rem .75rem;
      }

      .navbar .navbar-toggler {
        padding: .45rem .6rem;
        font-size: 1.1rem;
        border-width: 2px;
      }

      .navbar .bi {
        font-size: 1.1rem;
      }

      .container {
        padding-left: 12px;
        padding-right: 12px;
      }

      .grid {
        gap: 10px;
      }

      .avatar {
        width: 56px;
        height: 56px;
      }

      .badge-day {
        font-size: .8rem;
        padding: 4px 8px;
      }
    }

    @media (min-width:992px) {
      .filter-card {
        min-width: 280px;
      }
    }

    /* ===== Modal bonito ===== */
    .cele-modal .modal-content {
      border: 0;
      border-radius: 22px;
      overflow: hidden;
      box-shadow: 0 20px 60px rgba(15, 23, 42, .18);
    }

    .cele-head {
      padding: 18px 18px 14px 18px;
      background: radial-gradient(1200px 600px at 20% 0%, rgba(255, 255, 255, .35), transparent 50%),
        linear-gradient(135deg, #2563eb 0%, #7c3aed 55%, #f97316 120%);
      color: #fff;
    }

    .cele-title {
      font-weight: 900;
      letter-spacing: .2px;
      margin: 0;
      line-height: 1.1;
    }

    .cele-sub {
      opacity: .92;
    }

    .cele-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 999px;
      background: rgba(255, 255, 255, .16);
      border: 1px solid rgba(255, 255, 255, .28);
      font-weight: 800;
    }

    .cele-body {
      padding: 18px;
      background: #fff;
    }

    .cele-wrap {
      display: grid;
      grid-template-columns: 140px 1fr;
      gap: 16px;
      align-items: start;
    }

    .cele-photo {
      width: 140px;
      height: 140px;
      border-radius: 18px;
      object-fit: cover;
      box-shadow: 0 10px 24px rgba(15, 23, 42, .15);
      border: 3px solid #fff;
      background: #f1f5f9;
    }

    .cele-kv {
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      padding: 14px;
      background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    }

    .kv-row {
      display: flex;
      gap: 10px;
      align-items: flex-start;
      padding: 8px 0;
      border-bottom: 1px dashed #e5e7eb;
    }

    .kv-row:last-child {
      border-bottom: 0;
      padding-bottom: 0;
    }

    .kv-ico {
      width: 26px;
      height: 26px;
      border-radius: 8px;
      display: grid;
      place-items: center;
      background: #eef2ff;
      color: #3730a3;
      flex: 0 0 auto;
    }

    .kv-lbl {
      font-size: 12px;
      color: #64748b;
      margin: 0;
    }

    .kv-val {
      font-weight: 800;
      margin: 0;
      color: #0f172a;
    }

    .cele-foot {
      padding: 14px 18px 18px 18px;
      background: #fff;
      border-top: 1px solid #eef2f7;
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }

    @media (max-width:576px) {
      .cele-wrap {
        grid-template-columns: 1fr;
      }

      .cele-photo {
        width: 100%;
        height: 220px;
        border-radius: 18px;
      }
    }

    /* Para descarga PNG: mantenemos el área “capturable” */
    .capture-area {
      background: #fff;
      border-radius: 18px;
      overflow: hidden;
    }
  </style>
</head>

<body>

  <?php if (file_exists('navbar.php')) include 'navbar.php'; ?>

  <div class="container py-3">
    <div class="row g-3 align-items-end mb-2">
      <div class="col-lg">
        <h1 class="page-title">Cumpleaños & Aniversarios</h1>
        <div class="subtle">Mes: <strong><?= htmlspecialchars($nombreMes) ?></strong> · Año: <strong><?= (int)$anio ?></strong></div>
      </div>
      <div class="col-lg-4">
        <form class="filter-card p-3" method="get" action="">
          <div class="row g-2 align-items-center">
            <div class="col-7">
              <label class="form-label mb-1 small subtle">Mes</label>
              <select class="form-select" name="mes" aria-label="Mes">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                  <option value="<?= $m ?>" <?= $m === $mes ? 'selected' : '' ?>><?= $nombreMeses[$m] ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-5">
              <label class="form-label mb-1 small subtle">Año</label>
              <input type="number" class="form-control" name="anio" value="<?= (int)$anio ?>" min="1970" max="<?= (int)date('Y') + 1 ?>" aria-label="Año">
            </div>
            <div class="col-12 d-flex justify-content-end">
              <button class="btn btn-ghost"><i class="bi bi-funnel me-1"></i>Ver</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Cumpleaños -->
    <h5 class="mt-3 mb-2 fw-bold"><span class="me-2">🎂</span>Cumpleaños de <?= htmlspecialchars($nombreMes) ?></h5>
    <?php if (empty($cumples)): ?>
      <div class="alert alert-light border">No hay cumpleaños este mes.</div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($cumples as $p):
          $day  = dia($p['fecha_nacimiento']);
          $edad = edadQueCumple($p['fecha_nacimiento'], $anio);
          $fotoRaw = trim((string)($p['foto'] ?? ''));
      $foto    = $fotoRaw; // puede ser vacío
      $inic    = iniciales((string)$p['nombre']);
          $esHoy = ($mes == $mesHoy && $day == $diaHoy);

          $fechaStr = fechaBonitaDiaMes($p['fecha_nacimiento'], $nombreMeses);
          $titulo   = "Cumpleaños";
          $detalle  = "Cumple {$edad} años";
          $badge    = $esHoy ? "🎉 ¡Felicidades hoy!" : "🎂 {$edad} años";
        ?>
          <div class="card-p <?= $esHoy ? 'today' : '' ?>"
            tabindex="0"
            role="button"
            data-bs-toggle="modal"
            data-bs-target="#celeModal"
            data-tipo="<?= htmlspecialchars($titulo) ?>"
            data-nombre="<?= htmlspecialchars($p['nombre']) ?>"
            data-foto="<?= htmlspecialchars($foto) ?>"
            data-inic="<?= htmlspecialchars($inic) ?>"
            data-fecha="<?= htmlspecialchars($fechaStr) ?>"
            data-detalle="<?= htmlspecialchars($detalle) ?>"
            data-badge="<?= htmlspecialchars($badge) ?>"
            data-sucursal="<?= htmlspecialchars((string)($p['sucursal'] ?? '')) ?>"
            data-dia="<?= (int)$day ?>"
            data-es-hoy="<?= $esHoy ? '1' : '0' ?>">
            <div class="top">
              <?php $hasFoto = ($foto !== ''); ?>
              <div class="avatar-wrap <?= $hasFoto ? '' : 'noimg' ?>">
                <?php if ($hasFoto): ?>
                  <img src="<?= htmlspecialchars($foto) ?>" class="avatar" alt="foto" crossorigin="anonymous" referrerpolicy="no-referrer"
                       onerror="this.style.display='none'; this.parentElement.classList.add('noimg');">
                <?php endif; ?>
                <div class="avatar-fallback"><?= htmlspecialchars($inic) ?></div>
              </div>
              <div>
                <div class="name"><?= htmlspecialchars($p['nombre']) ?></div>
                <div class="sub">
                  <?= str_pad((string)$day, 2, '0', STR_PAD_LEFT) ?> <?= htmlspecialchars($nombreMes) ?> ·
                  Cumple <?= (int)$edad ?> años
                  <?php if ($esHoy): ?><span class="ms-2 badge bg-warning text-dark">🎉 ¡Felicidades!</span><?php endif; ?>
                </div>
                <?php if (!empty($p['sucursal'])): ?>
                  <div class="sub"><i class="bi bi-building me-1"></i><?= htmlspecialchars($p['sucursal']) ?></div>
                <?php endif; ?>
              </div>
              <div class="badge-day"><?= str_pad((string)$day, 2, '0', STR_PAD_LEFT) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Aniversarios -->
    <h5 class="mt-4 mb-2 fw-bold"><span class="me-2">🏅</span>Aniversarios de <?= htmlspecialchars($nombreMes) ?></h5>
    <?php if (empty($anv)): ?>
      <div class="alert alert-light border">No hay aniversarios este mes.</div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($anv as $p):
          $day  = dia($p['fecha_ingreso']);
          $ann  = aniosServicioQueCumple($p['fecha_ingreso'], $anio);
          $fotoRaw = trim((string)($p['foto'] ?? ''));
      $foto    = $fotoRaw; // puede ser vacío
      $inic    = iniciales((string)$p['nombre']);
          $esHoy = ($mes == $mesHoy && $day == $diaHoy);

          $fechaStr = fechaBonitaDiaMes($p['fecha_ingreso'], $nombreMeses);
          $titulo   = "Aniversario";
          $detalle  = "{$ann} año(s) en la empresa";
          $badge    = $esHoy ? "🎉 ¡Hoy!" : "🏅 {$ann} año(s)";
        ?>
          <div class="card-p <?= $esHoy ? 'today' : '' ?>"
            tabindex="0"
            role="button"
            data-bs-toggle="modal"
            data-bs-target="#celeModal"
            data-tipo="<?= htmlspecialchars($titulo) ?>"
            data-nombre="<?= htmlspecialchars($p['nombre']) ?>"
            data-foto="<?= htmlspecialchars($foto) ?>"
            data-inic="<?= htmlspecialchars($inic) ?>"
            data-fecha="<?= htmlspecialchars($fechaStr) ?>"
            data-detalle="<?= htmlspecialchars($detalle) ?>"
            data-badge="<?= htmlspecialchars($badge) ?>"
            data-sucursal="<?= htmlspecialchars((string)($p['sucursal'] ?? '')) ?>"
            data-dia="<?= (int)$day ?>"
            data-es-hoy="<?= $esHoy ? '1' : '0' ?>">
            <div class="top">
              <?php $hasFoto = ($foto !== ''); ?>
              <div class="avatar-wrap <?= $hasFoto ? '' : 'noimg' ?>">
                <?php if ($hasFoto): ?>
                  <img src="<?= htmlspecialchars($foto) ?>" class="avatar" alt="foto" crossorigin="anonymous" referrerpolicy="no-referrer"
                       onerror="this.style.display='none'; this.parentElement.classList.add('noimg');">
                <?php endif; ?>
                <div class="avatar-fallback"><?= htmlspecialchars($inic) ?></div>
              </div>
              <div>
                <div class="name"><?= htmlspecialchars($p['nombre']) ?></div>
                <div class="sub">
                  <?= str_pad((string)$day, 2, '0', STR_PAD_LEFT) ?> <?= htmlspecialchars($nombreMes) ?> ·
                  <?= (int)$ann ?> año(s) en la empresa
                  <?php if ($esHoy): ?><span class="ms-2 badge bg-warning text-dark">🎉 ¡Hoy!</span><?php endif; ?>
                </div>
                <?php if (!empty($p['sucursal'])): ?>
                  <div class="sub"><i class="bi bi-building me-1"></i><?= htmlspecialchars($p['sucursal']) ?></div>
                <?php endif; ?>
              </div>
              <div class="badge-day"><?= str_pad((string)$day, 2, '0', STR_PAD_LEFT) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>

  <!-- ===== MODAL ===== -->
  <div class="modal fade cele-modal" id="celeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content" id="celeModalContent">

        <div class="cele-head">
          <div class="d-flex align-items-start justify-content-between gap-3">
            <div>
              <div class="d-flex align-items-center gap-2 mb-2">
                <span class="cele-badge" id="mTipo"><i class="bi bi-stars"></i>Celebración</span>
                <span class="cele-badge" id="mBadge"><i class="bi bi-gift"></i></span>
              </div>
              <h2 class="cele-title" id="mNombre">Nombre</h2>
              <div class="cele-sub mt-1" id="mSub">Fecha · Detalle</div>
            </div>
            <button type="button" class="btn btn-light btn-sm rounded-pill" data-bs-dismiss="modal" aria-label="Cerrar">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>
        </div>

        <!-- Todo lo que se descarga como PNG va dentro de este contenedor -->
        <div class="cele-body">
          <div id="captureArea" class="capture-area p-0">
            <div class="p-3 p-md-4">
              <div class="cele-wrap">
                <img id="mFoto" class="cele-photo" src="" alt="foto grande" crossorigin="anonymous" referrerpolicy="no-referrer">

                <div class="cele-kv">
                  <div class="kv-row">
                    <div class="kv-ico"><i class="bi bi-calendar2-event"></i></div>
                    <div>
                      <p class="kv-lbl mb-1">Fecha</p>
                      <p class="kv-val mb-0" id="mFecha">--</p>
                    </div>
                  </div>

                  <div class="kv-row">
                    <div class="kv-ico"><i class="bi bi-award"></i></div>
                    <div>
                      <p class="kv-lbl mb-1">Detalle</p>
                      <p class="kv-val mb-0" id="mDetalle">--</p>
                    </div>
                  </div>

                  <div class="kv-row" id="rowSucursal" style="display:none;">
                    <div class="kv-ico"><i class="bi bi-building"></i></div>
                    <div>
                      <p class="kv-lbl mb-1">Sucursal</p>
                      <p class="kv-val mb-0" id="mSucursal">--</p>
                    </div>
                  </div>

                  <div class="mt-3 d-flex flex-wrap gap-2">
                    <span class="badge text-bg-primary rounded-pill" id="pillDia"><i class="bi bi-hash me-1"></i>Día</span>
                    <span class="badge text-bg-warning rounded-pill d-none" id="pillHoy"><i class="bi bi-emoji-smile me-1"></i>¡Es hoy!</span>
                    <span class="badge text-bg-light rounded-pill border" id="pillNota"><i class="bi bi-heart-fill text-danger me-1"></i>La Central</span>
                  </div>

                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="cele-foot">
          <button type="button" class="btn btn-outline-secondary rounded-pill" id="btnPrint">
            <i class="bi bi-printer me-1"></i>Imprimir
          </button>
          <button type="button" class="btn btn-primary rounded-pill" id="btnDownload">
            <i class="bi bi-download me-1"></i>Descargar
          </button>
        </div>

      </div>
    </div>
  </div>

  <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
  <script>
(function(){
  const modalEl = document.getElementById('celeModal');
  const $ = (id)=>document.getElementById(id);

  function initialsFromName(name){
    name = (name || '').trim();
    if(!name) return 'U';
    const parts = name.split(/\s+/).filter(Boolean);
    const a = parts[0]?.[0] || 'U';
    const b = (parts.length>1 ? parts[1][0] : (parts[0]?.[1] || '')) || '';
    return (a + b).toUpperCase();
  }
  function svgAvatarDataUrl(name){
    const ini = initialsFromName(name);
    const svg =
      `<svg xmlns="http://www.w3.org/2000/svg" width="320" height="320">
        <defs>
          <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#E0F2FE"/>
            <stop offset="1" stop-color="#EEF2FF"/>
          </linearGradient>
        </defs>
        <rect width="100%" height="100%" rx="36" ry="36" fill="url(#g)"/>
        <text x="50%" y="54%" text-anchor="middle" dominant-baseline="middle"
              font-family="system-ui, -apple-system, Segoe UI, Roboto, Arial"
              font-size="120" font-weight="800" fill="#0F172A">${ini}</text>
      </svg>`;
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  }


  const mTipo     = $('mTipo');
  const mBadge    = $('mBadge');
  const mNombre   = $('mNombre');
  const mSub      = $('mSub');
  const mFoto     = $('mFoto');
  const mFecha    = $('mFecha');
  const mDetalle  = $('mDetalle');
  const mSucursal = $('mSucursal');
  const rowSucursal = $('rowSucursal');
  const pillDia   = $('pillDia');
  const pillHoy   = $('pillHoy');

  // Rellena modal al abrir
  modalEl.addEventListener('show.bs.modal', function(event){
    const card = event.relatedTarget;
    if (!card) return;

    const tipo    = card.getAttribute('data-tipo') || 'Celebración';
    const nombre  = card.getAttribute('data-nombre') || 'Colaborador';
    const foto    = card.getAttribute('data-foto') || '';
    const inic    = card.getAttribute('data-inic') || '';

    const fecha   = card.getAttribute('data-fecha') || '--';
    const detalle = card.getAttribute('data-detalle') || '--';
    const badge   = card.getAttribute('data-badge') || '';
    const suc     = card.getAttribute('data-sucursal') || '';
    const dia     = card.getAttribute('data-dia') || '';
    const esHoy   = card.getAttribute('data-es-hoy') === '1';

    mTipo.innerHTML  = `<i class="bi bi-stars"></i>${tipo}`;
    mBadge.innerHTML = `<i class="bi bi-gift"></i>${badge}`;
    mNombre.textContent = nombre;
    mSub.textContent    = `${fecha} · ${detalle}`;

    if (foto.trim() !== '') {
      mFoto.src = foto;
    } else {
      mFoto.src = svgAvatarDataUrl(nombre || 'Colaborador');
    }

    // Fallback si la imagen no carga
    mFoto.onerror = function(){
      this.onerror = null;
      this.src = svgAvatarDataUrl(mNombre.textContent || 'Colaborador');
    };

    mFecha.textContent = fecha;
    mDetalle.textContent = detalle;

    if (suc.trim() !== '') {
      rowSucursal.style.display = '';
      mSucursal.textContent = suc;
    } else {
      rowSucursal.style.display = 'none';
      mSucursal.textContent = '';
    }

    pillDia.innerHTML = `<i class="bi bi-hash me-1"></i>Día ${String(dia).padStart(2,'0')}`;
    pillHoy.classList.toggle('d-none', !esHoy);
  });

  // Descargar PNG tal cual se ve el modal (con header y todo)
  $('btnDownload').addEventListener('click', async function(){
    const content = document.getElementById('celeModalContent');
    if (!content) return;

    const foot = content.querySelector('.cele-foot');
    const closeBtn = content.querySelector('[data-bs-dismiss="modal"]');

    const prevFootDisplay = foot ? foot.style.display : '';
    const prevCloseDisplay = closeBtn ? closeBtn.style.display : '';
    const prevOverflow = content.style.overflow;

    // Oculta botones para que el PNG salga limpio (opcional)
    if (foot) foot.style.display = 'none';
    if (closeBtn) closeBtn.style.display = 'none';

    // Evita cortes por overflow hidden
    content.style.overflow = 'visible';

    try{
      const canvas = await html2canvas(content, {
        backgroundColor: null,
        scale: 2,
        useCORS: true,
        scrollX: 0,
        scrollY: -window.scrollY,
        windowWidth: document.documentElement.clientWidth,
        windowHeight: document.documentElement.clientHeight
      });

      const a = document.createElement('a');
      const nombre = (mNombre.textContent || 'celebracion').trim().replace(/\s+/g,'_');
      const tipo = (mTipo.textContent || 'celebracion').trim().replace(/\s+/g,'_').toLowerCase();
      a.download = `${tipo}_${nombre}.png`;
      a.href = canvas.toDataURL('image/png');
      a.click();
    } finally {
      content.style.overflow = prevOverflow || '';
      if (foot) foot.style.display = prevFootDisplay || '';
      if (closeBtn) closeBtn.style.display = prevCloseDisplay || '';
    }
  });

  // Imprimir (abre ventana limpia con el contenido del modal)
  $('btnPrint').addEventListener('click', function(){
    const content = document.getElementById('celeModalContent');
    if (!content) return;

    const w = window.open('', '_blank', 'width=900,height=650');
    if (!w) return;

    const css = `
      <style>
        body{ font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial; padding:18px; background:#fff; }
        .box{ max-width: 980px; margin: 0 auto; }
        img{ max-width:100%; }
      </style>
    `;

    // Evita que el navegador cierre el <script> por accidente
    const printScript = '<scr'+'ipt>' +
      'window.onload=function(){window.focus();window.print();};' +
      '</scr'+'ipt>';

    w.document.open();
    w.document.write(
      `<html><head><title>Imprimir</title>${css}</head>` +
      `<body><div class="box">${content.outerHTML}</div>${printScript}</body></html>`
    );
    w.document.close();
  });

  // Bonus: abrir modal con Enter cuando está enfocado el card
  document.addEventListener('keydown', function(e){
    if (e.key !== 'Enter') return;
    const el = document.activeElement;
    if (el && el.classList && el.classList.contains('card-p') && el.getAttribute('data-bs-target') === '#celeModal') {
      el.click();
    }
  });
})();
</script>
</body>

</html>