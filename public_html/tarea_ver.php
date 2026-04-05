<?php
// tarea_ver.php — Ver y gestionar tarea (Central)
// - Cambia estatus con timestamps automáticos
// - Muestra dependencias y bloqueos
// - Muestra asignaciones por rol_en_tarea
// - Comentarios opcionales si existe tabla tarea_comentarios
// Requiere: db.php, navbar.php

ob_start();

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt($dt){
  if (!$dt) return '—';
  try { return (new DateTime($dt))->format('d/m/Y H:i'); } catch(Throwable $e){ return '—'; }
}
function buildUrl($id, $extra=[]){
  $q = array_merge(['id'=>$id], $extra);
  return basename(__FILE__).'?'.http_build_query($q);
}

$idTarea = (int)($_GET['id'] ?? 0);
if ($idTarea <= 0) { header("Location: tablero_tareas.php"); exit(); }

// CSRF
if (empty($_SESSION['csrf_tareas'])) $_SESSION['csrf_tareas'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_tareas'];

$mensaje = '';
$tipoMsg = 'info';

// ===== Helper: existe tabla =====
function tableExists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $rs = $conn->query("SHOW TABLES LIKE '{$t}'");
  return $rs && $rs->num_rows > 0;
}
$hasComentarios = tableExists($conn, 'tarea_comentarios');

// ===== Cargar tarea =====
$stmt = $conn->prepare("
  SELECT
    t.*,
    a.nombre AS area_nombre,
    u.nombre AS creador_nombre,
    (SELECT COUNT(*)
     FROM tarea_dependencias td
     JOIN tareas tdep ON tdep.id = td.depende_de
     WHERE td.id_tarea = t.id AND tdep.estatus <> 'Terminada'
    ) AS deps_abiertas
  FROM tareas t
  JOIN areas a ON a.id=t.id_area
  JOIN usuarios u ON u.id=t.creado_por
  WHERE t.id=?
  LIMIT 1
");
$stmt->bind_param("i", $idTarea);
$stmt->execute();
$tarea = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$tarea) { header("Location: tablero_tareas.php"); exit(); }

$depsAbiertas = (int)($tarea['deps_abiertas'] ?? 0);

// ===== Acciones POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrfPost = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($CSRF, $csrfPost)) {
    $mensaje = "Sesión inválida (CSRF). Recarga e intenta de nuevo.";
    $tipoMsg = "danger";
  } else {
    $action = (string)($_POST['action'] ?? '');

    try {
      if ($action === 'set_status') {
        $nuevo = (string)($_POST['estatus'] ?? '');
        $allowed = ['Nueva','En_proceso','Bloqueada','En_revision','Terminada','Cancelada'];
        if (!in_array($nuevo, $allowed, true)) throw new Exception("Estatus inválido.");

        // Reglas de timestamps
        // - Al pasar a En_proceso: set fecha_inicio_real si está null
        // - Al pasar a Terminada o Cancelada: set fecha_fin_real si está null
        if ($nuevo === 'En_proceso') {
          $conn->query("UPDATE tareas
                        SET estatus='En_proceso',
                            fecha_inicio_real = IF(fecha_inicio_real IS NULL, NOW(), fecha_inicio_real)
                        WHERE id=".(int)$idTarea." LIMIT 1");
        } elseif ($nuevo === 'Terminada' || $nuevo === 'Cancelada') {
          $conn->query("UPDATE tareas
                        SET estatus='". $conn->real_escape_string($nuevo) ."',
                            fecha_fin_real = IF(fecha_fin_real IS NULL, NOW(), fecha_fin_real)
                        WHERE id=".(int)$idTarea." LIMIT 1");
        } else {
          $conn->query("UPDATE tareas
                        SET estatus='". $conn->real_escape_string($nuevo) ."'
                        WHERE id=".(int)$idTarea." LIMIT 1");
        }

        $mensaje = "Estatus actualizado a: ".$nuevo;
        $tipoMsg = "success";

      } elseif ($action === 'add_comment') {
        if (!$hasComentarios) throw new Exception("Comentarios no habilitados (tabla tarea_comentarios no existe).");

        $txt = trim((string)($_POST['comentario'] ?? ''));
        if ($txt === '') throw new Exception("Escribe un comentario.");

        $stc = $conn->prepare("INSERT INTO tarea_comentarios (id_tarea, id_usuario, comentario) VALUES (?, ?, ?)");
        $stc->bind_param("iis", $idTarea, $ID_USUARIO, $txt);
        $stc->execute();
        $stc->close();

        $mensaje = "Comentario agregado.";
        $tipoMsg = "success";
      }

    } catch (Throwable $e) {
      $mensaje = "No se pudo completar la acción: ".h($e->getMessage());
      $tipoMsg = "danger";
    }

    // Recargar para reflejar
    header("Location: ".buildUrl($idTarea, ['msg'=>$tipoMsg, 'txt'=>base64_encode($mensaje)]));
    exit();
  }
}

// Mensaje por querystring
if (!$mensaje && isset($_GET['txt'], $_GET['msg'])) {
  $mensaje = base64_decode((string)$_GET['txt']) ?: '';
  $tipoMsg = (string)$_GET['msg'];
  if (!in_array($tipoMsg, ['success','info','warning','danger'], true)) $tipoMsg='info';
}
$created = isset($_GET['created']) && $_GET['created']=='1';

// ===== Cargar asignaciones =====
$asig = [
  'responsable'=>[],
  'colaborador'=>[],
  'observador'=>[],
  'aprobador'=>[],
];

$sta = $conn->prepare("
  SELECT tu.rol_en_tarea, u.id, u.nombre, u.rol, u.id_sucursal
  FROM tarea_usuarios tu
  JOIN usuarios u ON u.id=tu.id_usuario
  WHERE tu.id_tarea=?
  ORDER BY tu.rol_en_tarea, u.nombre
");
$sta->bind_param("i", $idTarea);
$sta->execute();
$rsA = $sta->get_result();
while($r = $rsA->fetch_assoc()){
  $k = (string)$r['rol_en_tarea'];
  if (!isset($asig[$k])) $asig[$k] = [];
  $asig[$k][] = $r;
}
$sta->close();

// ===== Dependencias (lo que depende esta tarea) =====
$depList = [];
$sd = $conn->prepare("
  SELECT tdep.id, tdep.titulo, tdep.estatus, a.nombre AS area_nombre, tdep.fecha_fin_compromiso
  FROM tarea_dependencias td
  JOIN tareas tdep ON tdep.id = td.depende_de
  JOIN areas a ON a.id = tdep.id_area
  WHERE td.id_tarea=?
  ORDER BY tdep.id DESC
");
$sd->bind_param("i", $idTarea);
$sd->execute();
$rsD = $sd->get_result();
while($r = $rsD->fetch_assoc()) $depList[] = $r;
$sd->close();

// ===== Reverse deps (quién depende de esta tarea) =====
$revList = [];
$sr = $conn->prepare("
  SELECT t.id, t.titulo, t.estatus, a.nombre AS area_nombre, t.fecha_fin_compromiso
  FROM tarea_dependencias td
  JOIN tareas t ON t.id = td.id_tarea
  JOIN areas a ON a.id = t.id_area
  WHERE td.depende_de=?
  ORDER BY t.id DESC
");
$sr->bind_param("i", $idTarea);
$sr->execute();
$rsR = $sr->get_result();
while($r = $rsR->fetch_assoc()) $revList[] = $r;
$sr->close();

// ===== Comentarios (si existe tabla) =====
$comentarios = [];
if ($hasComentarios) {
  $sc = $conn->prepare("
    SELECT c.id, c.comentario, c.fecha, u.nombre
    FROM tarea_comentarios c
    JOIN usuarios u ON u.id=c.id_usuario
    WHERE c.id_tarea=?
    ORDER BY c.id DESC
    LIMIT 100
  ");
  $sc->bind_param("i", $idTarea);
  $sc->execute();
  $rc = $sc->get_result();
  while($r = $rc->fetch_assoc()) $comentarios[] = $r;
  $sc->close();
}

// ===== UI helpers =====
$estCls = [
  'Nueva'       => 'secondary',
  'En_proceso'  => 'primary',
  'Bloqueada'   => 'warning',
  'En_revision' => 'info',
  'Terminada'   => 'success',
  'Cancelada'   => 'dark',
][$tarea['estatus']] ?? 'secondary';

$prioCls = [
  'Baja'  => 'secondary',
  'Media' => 'primary',
  'Alta'  => 'danger',
][$tarea['prioridad']] ?? 'secondary';

function chipUser($u){
  $txt = $u['nombre']." • ".$u['rol']." • Suc ".$u['id_sucursal'];
  return '<span class="badge text-bg-light border me-1 mb-1">'.h($txt).'</span>';
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tarea #<?= (int)$idTarea ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .cardx{ border:1px solid rgba(0,0,0,.08); border-radius:16px; }
    .soft{ color:#6c757d; }
    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
</head>
<body class="bg-light">

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="container py-3">

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
      <div class="soft small">Tarea</div>
      <h4 class="mb-0">#<?= (int)$idTarea ?> • <?= h($tarea['titulo']) ?></h4>
      <div class="soft small">
        Área: <span class="mono"><?= h($tarea['area_nombre']) ?></span> •
        Creada por: <span class="mono"><?= h($tarea['creador_nombre']) ?></span> •
        <?= fmt($tarea['fecha_creacion']) ?>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="tablero_tareas.php">← Volver</a>
    </div>
  </div>

  <?php if ($created): ?>
    <div class="alert alert-success">Tarea creada ✅</div>
  <?php endif; ?>

  <?php if ($mensaje): ?>
    <div class="alert alert-<?=h($tipoMsg)?>"><?=$mensaje?></div>
  <?php endif; ?>

  <!-- Resumen -->
  <div class="row g-2 mb-3">
    <div class="col-12 col-lg-8">
      <div class="cardx bg-white p-3 shadow-sm">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
          <div>
            <span class="badge bg-<?=$estCls?>"><?= h($tarea['estatus']) ?></span>
            <span class="badge bg-<?=$prioCls?>"><?= h($tarea['prioridad']) ?></span>
            <?php if ($depsAbiertas > 0): ?>
              <span class="badge bg-warning text-dark">Bloqueada por <?= (int)$depsAbiertas ?> dependencias</span>
            <?php endif; ?>
          </div>
          <div class="soft small">
            Compromiso: <b><?= fmt($tarea['fecha_fin_compromiso']) ?></b>
          </div>
        </div>

        <hr>

        <div class="row g-2 small">
          <div class="col-12 col-md-6">
            <div class="soft">Inicio planeado</div>
            <div class="mono"><?= fmt($tarea['fecha_inicio_planeada']) ?></div>
          </div>
          <div class="col-12 col-md-6">
            <div class="soft">Inicio real</div>
            <div class="mono"><?= fmt($tarea['fecha_inicio_real']) ?></div>
          </div>
          <div class="col-12 col-md-6">
            <div class="soft">Fin real</div>
            <div class="mono"><?= fmt($tarea['fecha_fin_real']) ?></div>
          </div>
          <div class="col-12 col-md-6">
            <div class="soft">Descripción</div>
            <div><?= nl2br(h($tarea['descripcion'] ?? '—')) ?></div>
          </div>
        </div>

        <hr>

        <!-- Acciones estatus -->
        <form method="post" class="d-flex flex-wrap gap-2">
          <input type="hidden" name="csrf" value="<?=h($CSRF)?>">
          <input type="hidden" name="action" value="set_status">

          <?php
            // Botones contextualizados
            $st = (string)$tarea['estatus'];
            $btn = function($label,$status,$cls,$title='') {
              $t = $title ? 'title="'.h($title).'"' : '';
              echo '<button class="btn '.$cls.' btn-sm" name="estatus" value="'.h($status).'" '.$t.'>'.h($label).'</button>';
            };

            if ($st === 'Nueva') {
              $btn('Iniciar', 'En_proceso', 'btn-primary', 'Marca inicio real si no existe');
              $btn('Bloquear', 'Bloqueada', 'btn-outline-warning', 'Por dependencia o falta de insumo');
              $btn('Revisión', 'En_revision', 'btn-outline-info', 'Pendiente de validación');
              $btn('Cancelar', 'Cancelada', 'btn-outline-dark', 'Cierra como cancelada');
            } elseif ($st === 'En_proceso') {
              $btn('Bloquear', 'Bloqueada', 'btn-outline-warning');
              $btn('Revisión', 'En_revision', 'btn-outline-info');
              $btn('Terminar', 'Terminada', 'btn-success', 'Cierra y guarda fin real');
              $btn('Cancelar', 'Cancelada', 'btn-outline-dark');
            } elseif ($st === 'Bloqueada' || $st === 'En_revision') {
              $btn('Reanudar', 'En_proceso', 'btn-primary');
              $btn('Terminar', 'Terminada', 'btn-success');
              $btn('Cancelar', 'Cancelada', 'btn-outline-dark');
            } elseif ($st === 'Terminada' || $st === 'Cancelada') {
              $btn('Reabrir', 'En_proceso', 'btn-primary', 'Reabre (mantiene fin real ya guardado)');
            }
          ?>
        </form>

        <?php if ($depsAbiertas > 0): ?>
          <div class="alert alert-warning mt-3 mb-0 small">
            Esta tarea tiene dependencias sin terminar. Si quieres forzar avance, puedes “Reanudar” igual, pero el tablero seguirá marcando bloqueo por dependencias.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Columna derecha: asignaciones -->
    <div class="col-12 col-lg-4">
      <div class="cardx bg-white p-3 shadow-sm">
        <div class="fw-semibold mb-2">Asignados</div>

        <div class="mb-2">
          <div class="soft small">Responsables</div>
          <div>
            <?php if (empty($asig['responsable'])): ?>
              <span class="soft small">—</span>
            <?php else: foreach($asig['responsable'] as $u) echo chipUser($u); endif; ?>
          </div>
        </div>

        <div class="mb-2">
          <div class="soft small">Colaboradores</div>
          <div>
            <?php if (empty($asig['colaborador'])): ?>
              <span class="soft small">—</span>
            <?php else: foreach($asig['colaborador'] as $u) echo chipUser($u); endif; ?>
          </div>
        </div>

        <div class="mb-2">
          <div class="soft small">Observadores</div>
          <div>
            <?php if (empty($asig['observador'])): ?>
              <span class="soft small">—</span>
            <?php else: foreach($asig['observador'] as $u) echo chipUser($u); endif; ?>
          </div>
        </div>

        <div class="mb-0">
          <div class="soft small">Aprobadores</div>
          <div>
            <?php if (empty($asig['aprobador'])): ?>
              <span class="soft small">—</span>
            <?php else: foreach($asig['aprobador'] as $u) echo chipUser($u); endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Dependencias -->
  <div class="row g-2 mb-3">
    <div class="col-12 col-lg-6">
      <div class="cardx bg-white p-3 shadow-sm">
        <div class="fw-semibold mb-2">Depende de</div>
        <?php if (empty($depList)): ?>
          <div class="soft small">Sin dependencias.</div>
        <?php else: ?>
          <div class="list-group">
            <?php foreach($depList as $d): ?>
              <a class="list-group-item list-group-item-action" href="tarea_ver.php?id=<?= (int)$d['id'] ?>">
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <div class="fw-semibold">#<?= (int)$d['id'] ?> • <?= h($d['titulo']) ?></div>
                    <div class="soft small"><?= h($d['area_nombre']) ?> • Compromiso: <?= fmt($d['fecha_fin_compromiso'] ?? null) ?></div>
                  </div>
                  <span class="badge bg-secondary"><?= h($d['estatus']) ?></span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="cardx bg-white p-3 shadow-sm">
        <div class="fw-semibold mb-2">Tareas que dependen de esta</div>
        <?php if (empty($revList)): ?>
          <div class="soft small">Nadie depende de esta tarea.</div>
        <?php else: ?>
          <div class="list-group">
            <?php foreach($revList as $r): ?>
              <a class="list-group-item list-group-item-action" href="tarea_ver.php?id=<?= (int)$r['id'] ?>">
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <div class="fw-semibold">#<?= (int)$r['id'] ?> • <?= h($r['titulo']) ?></div>
                    <div class="soft small"><?= h($r['area_nombre']) ?> • Compromiso: <?= fmt($r['fecha_fin_compromiso'] ?? null) ?></div>
                  </div>
                  <span class="badge bg-secondary"><?= h($r['estatus']) ?></span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Comentarios -->
  <div class="cardx bg-white p-3 shadow-sm">
    <div class="d-flex align-items-center justify-content-between">
      <div class="fw-semibold">Comentarios</div>
      <?php if (!$hasComentarios): ?>
        <span class="badge text-bg-light border">No habilitado</span>
      <?php endif; ?>
    </div>

    <?php if ($hasComentarios): ?>
      <form method="post" class="mt-2">
        <input type="hidden" name="csrf" value="<?=h($CSRF)?>">
        <input type="hidden" name="action" value="add_comment">
        <div class="input-group">
          <input class="form-control" name="comentario" placeholder="Escribe un comentario..." maxlength="500">
          <button class="btn btn-primary">Enviar</button>
        </div>
        <div class="soft small mt-1">Máx 500 caracteres.</div>
      </form>

      <hr>

      <?php if (empty($comentarios)): ?>
        <div class="soft small">Sin comentarios aún.</div>
      <?php else: ?>
        <div class="list-group">
          <?php foreach($comentarios as $c): ?>
            <div class="list-group-item">
              <div class="d-flex justify-content-between">
                <div class="fw-semibold"><?= h($c['nombre']) ?></div>
                <div class="soft small"><?= fmt($c['fecha'] ?? null) ?></div>
              </div>
              <div><?= nl2br(h($c['comentario'])) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="soft small mt-2">
        Si quieres habilitar comentarios, creamos la tabla <span class="mono">tarea_comentarios</span> y listo.
      </div>
    <?php endif; ?>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php ob_end_flush(); ?>
