<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: 403.php"); exit(); }

$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','GerenteZona','GerenteSucursal','Gerente']; // ajusta a tus roles
if (!in_array($ROL, $ALLOWED, true)) { header("Location: 403.php"); exit(); }

require_once __DIR__.'/db.php';

$idUsuario   = (int)$_SESSION['id_usuario'];
$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);

// 🔹 Nombre de la sucursal (si viene en sesión; si no, lo consultamos)
$sucursalNombre = $_SESSION['sucursal_nombre'] ?? '';
if ($idSucursal > 0 && $sucursalNombre === '') {
  $stNom = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
  $stNom->bind_param("i", $idSucursal);
  $stNom->execute();
  $stNom->bind_result($sucursalNombre);
  $stNom->fetch();
  $stNom->close();
}

// --- Config de uploads
$UPLOAD_DIR = __DIR__.'/uploads/mantenimiento';
$URL_BASE   = 'uploads/mantenimiento';
if (!is_dir($UPLOAD_DIR)) { @mkdir($UPLOAD_DIR, 0775, true); }

$ALLOWED_MIME = ['image/jpeg','image/png','image/webp','application/pdf'];
$MAX_PER_FILE = 10 * 1024 * 1024;   // 10MB
$MAX_FILES    = 6;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function badgeEstatus($e) {
  switch ($e) {
    case 'Nueva': return 'secondary';
    case 'En revisión': return 'info';
    case 'Aprobada': return 'primary';
    case 'En proceso': return 'warning';
    case 'Resuelta': return 'success';
    case 'Rechazada': return 'danger';
    default: return 'secondary';
  }
}

/* =========================================================
   HANDLERS LIGEROS EN EL MISMO ARCHIVO
   - ?view=detalle&id=123   -> HTML para modal de seguimiento
   - ?view=folio&id=123     -> página imprimible (folio)
========================================================= */
if (isset($_GET['view']) && isset($_GET['id'])) {
  $view = $_GET['view'];
  $idSol = (int)$_GET['id'];

  // Seguridad básica: mostramos SOLO solicitudes del propio usuario.
  // (Si deseas que Gerente/Admin vean cualquier solicitud, quita la cláusula ms.id_usuario=? y maneja permisos por rol)
  $sqlSol = "
    SELECT ms.*, s.nombre AS sucursal, u.nombre AS solicitante
    FROM mantenimiento_solicitudes ms
    JOIN sucursales s ON s.id = ms.id_sucursal
    JOIN usuarios   u ON u.id = ms.id_usuario
    WHERE ms.id = ? AND ms.id_usuario = ?
    LIMIT 1
  ";
  $st = $conn->prepare($sqlSol);
  $st->bind_param("ii", $idSol, $idUsuario);
  $st->execute();
  $sol = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$sol) { http_response_code(404); echo "<div class='text-danger p-3'>No encontrado o sin permisos.</div>"; exit; }

  // Adjuntos
  $files = [];
  $qf = $conn->prepare("SELECT ruta_relativa, nombre_archivo, mime_type FROM mantenimiento_adjuntos WHERE id_solicitud=? ORDER BY id");
  $qf->bind_param("i", $idSol);
  $qf->execute();
  $rf = $qf->get_result();
  while($f=$rf->fetch_assoc()) $files[]=$f;
  $qf->close();

  // Mapa de etapas para timeline
  $etapas = ['Nueva','En revisión','Aprobada','En proceso','Resuelta'];
  // Si está Rechazada, la consideramos etapa final alternativa
  $esRech = ($sol['estatus']==='Rechazada');
  $idx = array_search($sol['estatus'], $etapas, true);
  if ($idx === false) { $idx = 0; }
  $pct = $esRech ? 100 : (int)round(($idx) / (count($etapas)-1) * 100);

  if ($view === 'detalle') {
    // Fragmento HTML para inyectar en el modal
    ?>
    <style>
      .timeline { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; }
      .t-step { display:flex; align-items:center; gap:.5rem; }
      .t-dot { width:12px; height:12px; border-radius:50%; background:#d1d5db; }
      .t-step.active .t-dot { background:#0d6efd; }
      .t-step.done   .t-dot { background:#22c55e; }
      .t-label { font-size:.9rem; }
      .progress{ height:10px; border-radius:999px; }
      .file-pill{ display:inline-flex; align-items:center; gap:.4rem; padding:.3rem .6rem; border:1px solid #e5e7eb; border-radius:999px; margin:.2rem .25rem .2rem 0; background:#fff; }
    </style>
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <h5 class="mb-1">Solicitud #<?= (int)$sol['id'] ?> — <?= h($sol['titulo']) ?></h5>
        <div class="text-muted small">Sucursal: <b><?= h($sol['sucursal']) ?></b> · Solicitante: <b><?= h($sol['solicitante']) ?></b></div>
      </div>
      <div class="text-end">
        <div>
          <span class="badge text-bg-<?= badgeEstatus($sol['estatus']) ?>"><?= h($sol['estatus']) ?></span>
          <span class="badge <?= $sol['prioridad']=='Alta'?'text-bg-danger':($sol['prioridad']=='Media'?'text-bg-warning text-dark':'text-bg-secondary') ?>">
            <?= h($sol['prioridad']) ?>
          </span>
        </div>
        <a class="btn btn-sm btn-outline-secondary mt-2" target="_blank" href="mantenimiento_solicitar.php?view=folio&id=<?= (int)$sol['id'] ?>">
          <i class="bi bi-printer"></i> Imprimir folio
        </a>
      </div>
    </div>

    <div class="mt-3">
      <div class="timeline mb-2">
        <?php
          $to = $esRech ? ['Nueva','En revisión','Rechazada'] : $etapas;
          foreach ($to as $i=>$et):
            $cl = '';
            if ($esRech) {
              if ($et==='Rechazada') { $cl='active'; }
              else { $cl='done'; }
            } else {
              if ($i < $idx) $cl='done';
              elseif ($i === $idx) $cl='active';
            }
        ?>
          <div class="t-step <?= $cl ?>">
            <div class="t-dot"></div>
            <div class="t-label"><?= h($et) ?></div>
          </div>
          <?php if ($i < count($to)-1): ?>
            <div class="flex-grow-1" style="height:2px; background:#e5e7eb; min-width:20px;"></div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <div class="progress"><div class="progress-bar" role="progressbar" style="width: <?= (int)$pct ?>%;"></div></div>
    </div>

    <div class="mt-3">
      <h6 class="mb-1">Descripción</h6>
      <p class="mb-2"><?= nl2br(h($sol['descripcion'])) ?></p>
      <?php if (!empty($sol['contacto'])): ?>
        <div class="small text-muted">Contacto en sucursal: <?= h($sol['contacto']) ?></div>
      <?php endif; ?>
      <div class="small text-muted">Fecha: <?= h(date('Y-m-d H:i', strtotime($sol['fecha_solicitud']))) ?></div>
    </div>

    <div class="mt-3">
      <h6 class="mb-2">Adjuntos</h6>
      <?php if (count($files)===0): ?>
        <span class="text-muted">— Sin adjuntos —</span>
      <?php else: foreach ($files as $f): 
        $isImg = isset($f['mime_type']) && strpos($f['mime_type'],'image/')===0;
      ?>
        <a class="file-pill" target="_blank" href="<?= h($f['ruta_relativa']) ?>">
          <i class="bi <?= $isImg ? 'bi-image' : 'bi-file-earmark-pdf' ?>"></i>
          <span class="text-truncate" style="max-width:240px;"><?= h($f['nombre_archivo']) ?></span>
        </a>
      <?php endforeach; endif; ?>
    </div>
    <?php
    exit;
  }

  if ($view === 'folio') {
    // Página imprimible
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
      <meta charset="UTF-8">
      <title>Folio Solicitud #<?= (int)$sol['id'] ?></title>
      <meta name="viewport" content="width=device-width, initial-scale=1" />
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
      <style>
        @media print { .no-print { display:none !important; } }
        body{ background:#fff; }
        .folio{ max-width:900px; margin:24px auto; padding:24px; border:1px solid #e5e7eb; border-radius:12px; }
        .muted{ color:#6b7280; }
        .lh-tight{ line-height:1.25; }
      </style>
    </head>
    <body>
      <div class="folio">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <h3 class="mb-1">Folio de Mantenimiento</h3>
            <div class="muted">Solicitud #<?= (int)$sol['id'] ?> · <?= h(date('Y-m-d H:i', strtotime($sol['fecha_solicitud']))) ?></div>
          </div>
          <div class="text-end">
            <div class="lh-tight"><b>Sucursal:</b> <?= h($sol['sucursal']) ?></div>
            <div class="lh-tight"><b>Solicitante:</b> <?= h($sol['solicitante']) ?></div>
            <div class="lh-tight"><b>Estatus:</b> <?= h($sol['estatus']) ?></div>
            <div class="lh-tight"><b>Prioridad:</b> <?= h($sol['prioridad']) ?></div>
          </div>
        </div>
        <hr>
        <h5 class="mb-2"><?= h($sol['titulo']) ?></h5>
        <p><?= nl2br(h($sol['descripcion'])) ?></p>
        <?php if (!empty($sol['contacto'])): ?>
          <p class="muted"><b>Contacto:</b> <?= h($sol['contacto']) ?></p>
        <?php endif; ?>
        <hr>
        <h6>Adjuntos</h6>
        <?php if (count($files)===0): ?>
          <div class="muted">— Sin adjuntos —</div>
        <?php else: ?>
          <ul class="mb-0">
            <?php foreach ($files as $f): ?>
              <li><a href="<?= h($f['ruta_relativa']) ?>" target="_blank"><?= h($f['nombre_archivo']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <div class="mt-4 no-print">
          <button class="btn btn-primary" onclick="window.print()">Imprimir</button>
          <a class="btn btn-outline-secondary" href="mantenimiento_solicitar.php">Cerrar</a>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
  }

  // Vista desconocida
  http_response_code(400);
  echo "<div class='text-danger p-3'>Vista no válida.</div>";
  exit;
}

/* ============================
   PROCESAR POST (PRG)
============================ */
require_once __DIR__ . '/candado_captura.php';
abortar_si_captura_bloqueada(); // respeta tu candado

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='crear') {
  $categoria   = trim($_POST['categoria'] ?? 'Otro');
  $titulo      = trim($_POST['titulo'] ?? '');
  $descripcion = trim($_POST['descripcion'] ?? '');
  $prioridad   = $_POST['prioridad'] ?? 'Media';
  $contacto    = trim($_POST['contacto'] ?? '');

  $idSucursalForm = (int)($_POST['id_sucursal'] ?? $idSucursal);
  if ($idSucursalForm > 0) { $idSucursal = $idSucursalForm; }

  if (!$titulo || !$descripcion || !$idSucursal) {
    $_SESSION['flash_err'] = "Faltan datos obligatorios (Sucursal, Título, Descripción).";
    header("Location: mantenimiento_solicitar.php");
    exit;
  }

  $stmt = $conn->prepare("INSERT INTO mantenimiento_solicitudes
    (id_sucursal,id_usuario,categoria,titulo,descripcion,prioridad,contacto)
    VALUES (?,?,?,?,?,?,?)");
  $stmt->bind_param("iisssss", $idSucursal, $idUsuario, $categoria, $titulo, $descripcion, $prioridad, $contacto);

  if ($stmt->execute()) {
    $idSol = $stmt->insert_id;
    $stmt->close();

    // Adjuntos
    $filesGuardados = 0;
    if (!empty($_FILES['adjuntos']['name'][0])) {
      $c = min(count($_FILES['adjuntos']['name']), $MAX_FILES);
      for ($i=0; $i<$c; $i++) {
        $name = $_FILES['adjuntos']['name'][$i];
        $tmp  = $_FILES['adjuntos']['tmp_name'][$i];
        $size = (int)$_FILES['adjuntos']['size'][$i];

        if (!$tmp || !is_uploaded_file($tmp)) continue;
        $type = function_exists('mime_content_type') ? (mime_content_type($tmp) ?: ($_FILES['adjuntos']['type'][$i] ?? '')) : ($_FILES['adjuntos']['type'][$i] ?? '');
        if ($size <= 0 || $size > $MAX_PER_FILE) continue;
        if (!in_array($type, $ALLOWED_MIME, true)) continue;

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $safeName = preg_replace('/[^a-zA-Z0-9_\.-]/','_', pathinfo($name, PATHINFO_FILENAME));
        $destFile = $safeName.'__'.date('Ymd_His').'__'.bin2hex(random_bytes(3)).'.'.$ext;

        $dirSol = $UPLOAD_DIR.'/sol_'.$idSol;
        if (!is_dir($dirSol)) { @mkdir($dirSol, 0775, true); }

        $destPath = $dirSol.'/'.$destFile;
        if (move_uploaded_file($tmp, $destPath)) {
          $rutaRel = $URL_BASE.'/sol_'.$idSol.'/'.$destFile;

          $stA = $conn->prepare("INSERT INTO mantenimiento_adjuntos
            (id_solicitud,nombre_archivo,ruta_relativa,mime_type,tam_bytes,subido_por)
            VALUES (?,?,?,?,?,?)");
          $stA->bind_param("isssii", $idSol, $name, $rutaRel, $type, $size, $idUsuario);
          $stA->execute();
          $stA->close();

          $filesGuardados++;
        }
      }
    }

    $_SESSION['flash_ok'] = "Solicitud #$idSol creada correctamente".($filesGuardados? " ({$filesGuardados} adjunto(s))." : ".");
    header("Location: mantenimiento_solicitar.php");
    exit;

  } else {
    $stmt->close();
    $_SESSION['flash_err'] = "Error al guardar la solicitud.";
    header("Location: mantenimiento_solicitar.php");
    exit;
  }
}

// Traer mensajes flash
$msgOK  = $_SESSION['flash_ok']  ?? '';
$msgErr = $_SESSION['flash_err'] ?? '';
unset($_SESSION['flash_ok'], $_SESSION['flash_err']);

/* ============================
   RENDER (tabla + modales)
============================ */
require_once __DIR__.'/navbar.php';

// Catálogo de sucursales (si NO hay id en sesión)
$catSuc = $conn->query("SELECT id, nombre FROM sucursales WHERE tipo_sucursal='Tienda' ORDER BY nombre");

// Mis solicitudes (solo tabla)
$stList = $conn->prepare("
  SELECT ms.*, s.nombre AS sucursal
  FROM mantenimiento_solicitudes ms
  INNER JOIN sucursales s ON s.id = ms.id_sucursal
  WHERE ms.id_usuario = ?
  ORDER BY ms.fecha_solicitud DESC
  LIMIT 200
");
$stList->bind_param("i", $idUsuario);
$stList->execute();
$resList = $stList->get_result();
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <title>Mantenimiento - Solicitudes</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <style>
    :root{ --surface:#ffffff; --muted:#6b7280; }
    body{ background:#f6f7fb; }
    .page-header{ display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-top:1rem; }
    .page-title{ font-weight:700; margin:0; letter-spacing:.2px; }
    .small-muted{ color:var(--muted); font-size:.92rem; }
    .card-surface{ background:var(--surface); border:1px solid rgba(0,0,0,.05); box-shadow:0 6px 16px rgba(16,24,40,.06); border-radius:18px; }
    .tbl-wrap{ overflow:auto; border-radius:14px; }
    .table thead th{ position:sticky; top:0; z-index:1; }
    .btn-soft{ border:1px solid rgba(0,0,0,.08); background:#fff; }
    .btn-soft:hover{ background:#f9fafb; }
  </style>
</head>
<body>

<div class="container py-3">
  <!-- Header -->
  <div class="page-header">
    <div>
      <h1 class="page-title">🛠️ Solicitudes de Mantenimiento</h1>
      <div class="small-muted">
        Usuario: <strong><?= h($_SESSION['nombre'] ?? '') ?></strong>
        <?php if ($idSucursal): ?> · Sucursal: <strong><?= h($sucursalNombre) ?></strong><?php endif; ?>
      </div>
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNueva">
        <i class="bi bi-plus-circle"></i> Nueva solicitud
      </button>
    </div>
  </div>

  <?php if ($msgOK): ?>
    <div class="alert alert-success card-surface mt-2"><?= h($msgOK) ?></div>
  <?php endif; ?>
  <?php if ($msgErr): ?>
    <div class="alert alert-danger card-surface mt-2"><?= h($msgErr) ?></div>
  <?php endif; ?>

  <!-- Tabla -->
  <div class="card card-surface mt-3">
    <div class="p-3 pb-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
      <h5 class="m-0"><i class="bi bi-table me-2"></i>Mis últimas solicitudes</h5>
      <input id="qFront" class="form-control" placeholder="Buscar en la tabla…" style="height:42px; width:280px;">
    </div>
    <div class="p-3 pt-2 tbl-wrap">
      <table id="tablaSolicitudes" class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Sucursal</th>
            <th>Categoría</th>
            <th>Título</th>
            <th>Prioridad</th>
            <th>Estatus</th>
            <th>Fecha</th>
            <th class="text-end">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php while($row = $resList->fetch_assoc()): ?>
          <tr>
            <td><span class="badge text-bg-secondary">#<?= (int)$row['id'] ?></span></td>
            <td><?= h($row['sucursal']) ?></td>
            <td><?= h($row['categoria']) ?></td>
            <td><?= h($row['titulo']) ?></td>
            <td>
              <span class="badge <?= $row['prioridad']=='Alta'?'text-bg-danger':($row['prioridad']=='Media'?'text-bg-warning text-dark':'text-bg-secondary') ?>">
                <?= h($row['prioridad']) ?>
              </span>
            </td>
            <td><span class="badge text-bg-<?= badgeEstatus($row['estatus']) ?>"><?= h($row['estatus']) ?></span></td>
            <td><?= h(date('Y-m-d H:i', strtotime($row['fecha_solicitud']))) ?></td>
            <td class="text-end">
              <button type="button" class="btn btn-soft btn-sm verSeguimiento" data-id="<?= (int)$row['id'] ?>">
                <i class="bi bi-search"></i> Seguimiento
              </button>
            </td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- MODAL: Nueva solicitud -->
<div class="modal fade" id="modalNueva" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <form method="POST" enctype="multipart/form-data" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-wrench-adjustable-circle me-2"></i>Nueva solicitud de mantenimiento</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="action" value="crear">
        <div class="row g-3">
          <?php if (!$idSucursal): ?>
            <div class="col-md-6">
              <label class="form-label">Sucursal</label>
              <select name="id_sucursal" class="form-select" required>
                <option value="">Selecciona…</option>
                <?php while($s=$catSuc->fetch_assoc()): ?>
                  <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>
          <?php else: ?>
            <div class="col-md-6">
              <label class="form-label">Sucursal</label>
              <input class="form-control" value="<?= h($sucursalNombre) ?>" readonly>
            </div>
          <?php endif; ?>

          <div class="col-md-3">
            <label class="form-label">Categoría</label>
            <select name="categoria" class="form-select" required>
              <option>Fachada</option>
              <option>Electricidad</option>
              <option>Plomería</option>
              <option>Limpieza</option>
              <option>Seguridad</option>
              <option>Infraestructura</option>
              <option>Otro</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Prioridad</label>
            <select name="prioridad" class="form-select" required>
              <option>Media</option>
              <option>Baja</option>
              <option>Alta</option>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">Título</label>
            <input type="text" name="titulo" class="form-control" maxlength="150" required placeholder="Ej. Pintura de fachada dañada">
          </div>

          <div class="col-12">
            <label class="form-label">Descripción</label>
            <textarea name="descripcion" class="form-control" rows="4" required
              placeholder="Describe el problema, ubicación dentro de la sucursal, horarios, etc."></textarea>
          </div>

          <div class="col-md-6">
            <label class="form-label">Contacto en sucursal (opcional)</label>
            <input type="text" name="contacto" class="form-control" maxlength="120" placeholder="Nombre y/o teléfono">
          </div>

          <div class="col-md-6">
            <label class="form-label">Adjuntos (imágenes o PDF)</label>
            <input type="file" name="adjuntos[]" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf" multiple>
            <div class="form-text">Máximo <?= (int)$MAX_FILES ?> archivos, hasta <?= (int)($MAX_PER_FILE/1024/1024) ?>MB c/u.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-send"></i> Enviar solicitud
        </button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: Seguimiento (contenido dinámico por fetch) -->
<div class="modal fade" id="modalSeguimiento" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-activity me-2"></i>Seguimiento de solicitud</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body" id="seguimientoBody">
        <div class="text-muted">Cargando…</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal" type="button">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
// Búsqueda rápida en tabla
(() => {
  const input = document.getElementById('qFront');
  const rows  = Array.from(document.querySelectorAll('#tablaSolicitudes tbody tr'));
  const norm  = s => (s||'').toString().toLowerCase().normalize('NFD').replace(/\p{Diacritic}/gu,'');
  function filtra(){
    const q = norm(input.value);
    rows.forEach(tr => {
      tr.style.display = !q || norm(tr.innerText).includes(q) ? '' : 'none';
    });
  }
  input?.addEventListener('input', filtra);
})();

// Seguimiento (fetch al mismo archivo ?view=detalle&id=...)
(() => {
  const seguimientoModal = new bootstrap.Modal(document.getElementById('modalSeguimiento'));
  document.querySelectorAll('.verSeguimiento').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-id');
      const body = document.getElementById('seguimientoBody');
      body.innerHTML = '<div class="text-muted">Cargando…</div>';
      try {
        const res = await fetch(`mantenimiento_solicitar.php?view=detalle&id=${encodeURIComponent(id)}`, {credentials:'same-origin'});
        const html = await res.text();
        body.innerHTML = html;
      } catch(e) {
        body.innerHTML = '<div class="text-danger">No se pudo cargar el seguimiento.</div>';
      }
      seguimientoModal.show();
    });
  });
})();
</script>
</body>
</html>
