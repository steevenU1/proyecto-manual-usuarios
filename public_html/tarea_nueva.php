<?php
// tarea_nueva.php — Crear nueva tarea (Central) [con filtro usuarios por área]
// Requiere: db.php, navbar.php, tablas del módulo + usuarios_areas, api_usuarios_por_area.php

ob_start();

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function trimS($s){ return trim((string)$s); }

function toMysqlDT($input){
  $input = trimS($input);
  if ($input === '') return null;
  $input = str_replace('T',' ', $input);
  if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $input)) $input .= ':00';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $input)) return null;
  return $input;
}

function uniqInts($arr){
  $out = [];
  foreach((array)$arr as $v){
    $n = (int)$v;
    if ($n > 0) $out[$n] = true;
  }
  return array_keys($out);
}

// CSRF
if (empty($_SESSION['csrf_tareas'])) $_SESSION['csrf_tareas'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_tareas'];

// Cargar áreas (ya tienes columna activa)
$areas = [];
$resA = $conn->query("SELECT id, nombre FROM areas WHERE activa=1 ORDER BY nombre");
while($row = $resA->fetch_assoc()) $areas[] = $row;

// Usuarios (lista inicial: TODOS; luego JS filtrará por área)
$usuarios = [];
$resU = $conn->query("SELECT id, nombre, rol, id_sucursal FROM usuarios WHERE activo=1 ORDER BY nombre");
while($row = $resU->fetch_assoc()) $usuarios[] = $row;

// Dependencias
$deps = [];
$resD = $conn->query("
  SELECT t.id, t.titulo, a.nombre AS area_nombre, t.estatus, t.fecha_fin_compromiso
  FROM tareas t
  JOIN areas a ON a.id=t.id_area
  WHERE t.estatus <> 'Terminada' AND t.estatus <> 'Cancelada'
  ORDER BY t.id DESC
  LIMIT 250
");
while($row = $resD->fetch_assoc()) $deps[] = $row;

// Valores
$val = [
  'titulo' => '',
  'descripcion' => '',
  'id_area' => 0,
  'prioridad' => 'Media',
  'fecha_inicio_planeada' => '',
  'fecha_fin_compromiso' => '',
  'responsables' => [],
  'colaboradores' => [],
  'observadores' => [],
  'aprobadores' => [],
  'dependencias' => [],
];

$mensaje = '';
$tipoMsg = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrfPost = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($CSRF, $csrfPost)) {
    $mensaje = "Sesión inválida (CSRF). Recarga e inténtalo de nuevo.";
    $tipoMsg = "danger";
  } else {
    $val['titulo'] = trimS($_POST['titulo'] ?? '');
    $val['descripcion'] = trimS($_POST['descripcion'] ?? '');
    $val['id_area'] = (int)($_POST['id_area'] ?? 0);
    $val['prioridad'] = trimS($_POST['prioridad'] ?? 'Media');
    $val['fecha_inicio_planeada'] = (string)($_POST['fecha_inicio_planeada'] ?? '');
    $val['fecha_fin_compromiso']  = (string)($_POST['fecha_fin_compromiso'] ?? '');

    $val['responsables']  = uniqInts($_POST['responsables'] ?? []);
    $val['colaboradores'] = uniqInts($_POST['colaboradores'] ?? []);
    $val['observadores']  = uniqInts($_POST['observadores'] ?? []);
    $val['aprobadores']   = uniqInts($_POST['aprobadores'] ?? []);
    $val['dependencias']  = uniqInts($_POST['dependencias'] ?? []);

    $errs = [];
    if ($val['titulo'] === '') $errs[] = "El título es obligatorio.";
    if ($val['id_area'] <= 0) $errs[] = "Selecciona un área.";
    if (!in_array($val['prioridad'], ['Baja','Media','Alta'], true)) $errs[] = "Prioridad inválida.";
    if (count($val['responsables']) < 1) $errs[] = "Debes asignar al menos 1 responsable.";

    $inicioPlan = toMysqlDT($val['fecha_inicio_planeada']);
    $finComp    = toMysqlDT($val['fecha_fin_compromiso']);

    if (!$finComp) $errs[] = "La fecha de compromiso (fin) es obligatoria y válida.";
    if ($inicioPlan && $finComp && strtotime($inicioPlan) > strtotime($finComp)) {
      $errs[] = "Inicio planeado no puede ser mayor a compromiso.";
    }

    if ($errs) {
      $mensaje = implode("<br>", array_map('h', $errs));
      $tipoMsg = "danger";
    } else {
      $conn->begin_transaction();
      try {
        $desc = ($val['descripcion'] !== '') ? $val['descripcion'] : null;

        if ($inicioPlan === null) {
          $stmt = $conn->prepare("
            INSERT INTO tareas (titulo, descripcion, id_area, prioridad, estatus, fecha_inicio_planeada, fecha_fin_compromiso, creado_por)
            VALUES (?, ?, ?, ?, 'Nueva', NULL, ?, ?)
          ");
          $stmt->bind_param("ssissi", $val['titulo'], $desc, $val['id_area'], $val['prioridad'], $finComp, $ID_USUARIO);
        } else {
          $stmt = $conn->prepare("
            INSERT INTO tareas (titulo, descripcion, id_area, prioridad, estatus, fecha_inicio_planeada, fecha_fin_compromiso, creado_por)
            VALUES (?, ?, ?, ?, 'Nueva', ?, ?, ?)
          ");
          $stmt->bind_param("ssisssi", $val['titulo'], $desc, $val['id_area'], $val['prioridad'], $inicioPlan, $finComp, $ID_USUARIO);
        }

        $stmt->execute();
        $idTarea = (int)$conn->insert_id;
        $stmt->close();

        $insTU = $conn->prepare("INSERT IGNORE INTO tarea_usuarios (id_tarea, id_usuario, rol_en_tarea) VALUES (?, ?, ?)");

        $push = function(array $ids, string $rol) use ($insTU, $idTarea){
          foreach($ids as $idU){
            $idUser = (int)$idU;
            $rolLocal = $rol;
            $insTU->bind_param("iis", $idTarea, $idUser, $rolLocal);
            $insTU->execute();
          }
        };

        $push($val['responsables'],  'responsable');
        $push($val['colaboradores'], 'colaborador');
        $push($val['observadores'],  'observador');
        $push($val['aprobadores'],   'aprobador');

        $insTU->close();

        if (!empty($val['dependencias'])) {
          $insTD = $conn->prepare("INSERT IGNORE INTO tarea_dependencias (id_tarea, depende_de) VALUES (?, ?)");
          foreach($val['dependencias'] as $depId){
            $depId = (int)$depId;
            if ($depId > 0 && $depId !== $idTarea){
              $insTD->bind_param("ii", $idTarea, $depId);
              $insTD->execute();
            }
          }
          $insTD->close();
        }

        $conn->commit();
        header("Location: tarea_ver.php?id=".$idTarea."&created=1");
        exit();

      } catch (Throwable $e) {
        $conn->rollback();
        $mensaje = "No se pudo guardar la tarea: " . h($e->getMessage());
        $tipoMsg = "danger";
      }
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Nueva tarea</title>
  <style>
    .cardx{ border:1px solid rgba(0,0,0,.08); border-radius:16px; }
    .hint{ font-size:.85rem; color:#6c757d; }
    .soft{ color:#6c757d; }
  </style>
</head>
<body class="bg-light">

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="container py-3">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
      <h4 class="mb-0">Nueva tarea</h4>
      <div class="hint">Selecciona un área y el sistema filtrará usuarios asignados a esa área (si hay mapeo).</div>
    </div>
    <a class="btn btn-outline-secondary" href="tablero_tareas.php">← Volver</a>
  </div>

  <?php if ($mensaje): ?>
    <div class="alert alert-<?=$tipoMsg?>"><?=$mensaje?></div>
  <?php endif; ?>

  <form method="post" class="cardx bg-white p-3 shadow-sm">
    <input type="hidden" name="csrf" value="<?=h($CSRF)?>">

    <div class="row g-3">
      <div class="col-12 col-lg-8">
        <label class="form-label fw-semibold">Título *</label>
        <input class="form-control" name="titulo" value="<?=h($val['titulo'])?>" maxlength="160" required>
      </div>

      <div class="col-12 col-lg-4">
        <label class="form-label fw-semibold">Área *</label>
        <select class="form-select" name="id_area" id="id_area" required>
          <option value="0">Selecciona...</option>
          <?php foreach($areas as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= $val['id_area']===(int)$a['id']?'selected':'' ?>>
              <?= h($a['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div id="areaHint" class="hint mt-1 soft"></div>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Descripción</label>
        <textarea class="form-control" rows="3" name="descripcion"><?=h($val['descripcion'])?></textarea>
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label fw-semibold">Prioridad</label>
        <select class="form-select" name="prioridad">
          <?php foreach(['Baja','Media','Alta'] as $p): ?>
            <option value="<?=h($p)?>" <?= $val['prioridad']===$p?'selected':'' ?>><?=h($p)?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label fw-semibold">Inicio planeado</label>
        <input class="form-control" type="datetime-local" name="fecha_inicio_planeada" value="<?=h($val['fecha_inicio_planeada'])?>">
      </div>

      <div class="col-12 col-md-4">
        <label class="form-label fw-semibold">Compromiso (fin) *</label>
        <input class="form-control" type="datetime-local" name="fecha_fin_compromiso" value="<?=h($val['fecha_fin_compromiso'])?>" required>
      </div>

      <hr class="my-1">

      <div class="col-12 col-lg-6">
        <label class="form-label fw-semibold">Responsables *</label>
        <select class="form-select user-select" name="responsables[]" id="responsables" multiple size="8" required>
          <?php foreach($usuarios as $u): ?>
            <?php
              $id = (int)$u['id'];
              $txt = $u['nombre']." • ".$u['rol']." • Suc ".$u['id_sucursal'];
              $sel = in_array($id, $val['responsables'], true) ? 'selected' : '';
            ?>
            <option value="<?=$id?>" <?=$sel?>><?=h($txt)?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint mt-1">Ctrl/Shift para seleccionar varios.</div>
      </div>

      <div class="col-12 col-lg-6">
        <label class="form-label fw-semibold">Colaboradores</label>
        <select class="form-select user-select" name="colaboradores[]" id="colaboradores" multiple size="8">
          <?php foreach($usuarios as $u): ?>
            <?php
              $id = (int)$u['id'];
              $txt = $u['nombre']." • ".$u['rol']." • Suc ".$u['id_sucursal'];
              $sel = in_array($id, $val['colaboradores'], true) ? 'selected' : '';
            ?>
            <option value="<?=$id?>" <?=$sel?>><?=h($txt)?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-6">
        <label class="form-label fw-semibold">Observadores</label>
        <select class="form-select user-select" name="observadores[]" id="observadores" multiple size="7">
          <?php foreach($usuarios as $u): ?>
            <?php
              $id = (int)$u['id'];
              $txt = $u['nombre']." • ".$u['rol']." • Suc ".$u['id_sucursal'];
              $sel = in_array($id, $val['observadores'], true) ? 'selected' : '';
            ?>
            <option value="<?=$id?>" <?=$sel?>><?=h($txt)?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-lg-6">
        <label class="form-label fw-semibold">Aprobadores</label>
        <select class="form-select user-select" name="aprobadores[]" id="aprobadores" multiple size="7">
          <?php foreach($usuarios as $u): ?>
            <?php
              $id = (int)$u['id'];
              $txt = $u['nombre']." • ".$u['rol']." • Suc ".$u['id_sucursal'];
              $sel = in_array($id, $val['aprobadores'], true) ? 'selected' : '';
            ?>
            <option value="<?=$id?>" <?=$sel?>><?=h($txt)?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <hr class="my-1">

      <div class="col-12">
        <label class="form-label fw-semibold">Dependencias</label>
        <select class="form-select" name="dependencias[]" multiple size="8">
          <?php foreach($deps as $d): ?>
            <?php
              $id = (int)$d['id'];
              $fin = $d['fecha_fin_compromiso'] ? date('d/m/Y H:i', strtotime($d['fecha_fin_compromiso'])) : '—';
              $txt = "#".$id." • ".$d['titulo']." • ".$d['area_nombre']." • ".$d['estatus']." • Fin: ".$fin;
              $sel = in_array($id, $val['dependencias'], true) ? 'selected' : '';
            ?>
            <option value="<?=$id?>" <?=$sel?>><?=h($txt)?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 d-flex flex-wrap gap-2 justify-content-end mt-2">
        <a class="btn btn-outline-secondary" href="tablero_tareas.php">Cancelar</a>
        <button class="btn btn-primary">Guardar tarea</button>
      </div>
    </div>
  </form>
</div>

<script>
(function(){
  const selArea = document.getElementById('id_area');
  const hint = document.getElementById('areaHint');

  const selects = [
    document.getElementById('responsables'),
    document.getElementById('colaboradores'),
    document.getElementById('observadores'),
    document.getElementById('aprobadores'),
  ];

  function getSelectedValues(selectEl){
    return Array.from(selectEl.options).filter(o => o.selected).map(o => o.value);
  }

  function setOptions(selectEl, users, keepSelected){
    const selectedSet = new Set(keepSelected || []);
    selectEl.innerHTML = '';
    users.forEach(u => {
      const opt = document.createElement('option');
      opt.value = u.id;
      opt.textContent = `${u.nombre} • ${u.rol} • Suc ${u.id_sucursal}`;
      if (selectedSet.has(String(u.id))) opt.selected = true;
      selectEl.appendChild(opt);
    });
  }

  async function reloadUsersByArea(){
    const areaId = parseInt(selArea.value || '0', 10);

    // Guardar lo que estaba seleccionado antes
    const keep = selects.map(s => getSelectedValues(s));

    hint.textContent = 'Cargando usuarios del área...';

    try {
      const res = await fetch(`api_usuarios_por_area.php?id_area=${areaId}`, { credentials: 'same-origin' });
      const data = await res.json();

      if (!data.ok) {
        hint.textContent = 'No se pudo cargar usuarios.';
        return;
      }

      // Repintar selects
      selects.forEach((s, i) => setOptions(s, data.users, keep[i]));

      if (data.mode === 'filtered') {
        hint.textContent = 'Mostrando usuarios asignados a esta área.';
      } else {
        hint.textContent = 'Esta área aún no tiene usuarios asignados. Mostrando todos por ahora.';
      }

    } catch (e) {
      hint.textContent = 'Error cargando usuarios (revisa endpoint).';
    }
  }

  // Cuando cambie el área, filtra
  selArea.addEventListener('change', reloadUsersByArea);

  // Si ya viene un área seleccionada (por POST con error), filtra al cargar
  if (parseInt(selArea.value || '0', 10) > 0) {
    reloadUsersByArea();
  } else {
    hint.textContent = 'Selecciona un área para filtrar usuarios (si hay mapeo).';
  }
})();
</script>

</body>
</html>
<?php ob_end_flush(); ?>
