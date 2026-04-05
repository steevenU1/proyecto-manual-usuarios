<?php
// admin_usuarios_areas.php — Asignar usuarios a áreas (Central)
// - Seleccionas un área y asignas usuarios (checkbox)
// - "Principal" opcional: solo 1 principal por usuario (global, no por área)
// Requiere: db.php, navbar.php, tablas: areas, usuarios, usuarios_areas

ob_start();

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function trimS($s){ return trim((string)$s); }

// CSRF
if (empty($_SESSION['csrf_tareas'])) $_SESSION['csrf_tareas'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_tareas'];

$mensaje = '';
$tipoMsg = 'info';

// Área seleccionada
$idArea = (int)($_GET['area'] ?? ($_POST['area'] ?? 0));

// Cargar áreas
$areas = [];
$rsA = $conn->query("SELECT id, nombre, activa FROM areas ORDER BY nombre");
while($r = $rsA->fetch_assoc()) $areas[] = $r;

// Si no hay área elegida, usa la primera activa
if ($idArea <= 0) {
  foreach($areas as $a){
    if ((int)$a['activa'] === 1) { $idArea = (int)$a['id']; break; }
  }
}

// Procesar guardado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrfPost = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($CSRF, $csrfPost)) {
    $mensaje = "Sesión inválida (CSRF). Recarga e inténtalo de nuevo.";
    $tipoMsg = "danger";
  } else {
    $idArea = (int)($_POST['area'] ?? 0);
    if ($idArea <= 0) {
      $mensaje = "Selecciona un área válida.";
      $tipoMsg = "danger";
    } else {
      // Arrays del form
      $asignados = $_POST['asignado'] ?? [];   // [id_usuario => "1"]
      $principales = $_POST['principal'] ?? []; // [id_usuario => "1"]

      // Normalizar a ints
      $asignadosIds = [];
      foreach($asignados as $k=>$v){ $asignadosIds[(int)$k] = true; }
      $principalIds = [];
      foreach($principales as $k=>$v){ $principalIds[(int)$k] = true; }

      $conn->begin_transaction();
      try {
        // 1) Para todos los usuarios del sistema, si están "principal" marcados, ponemos principal=1
        //    y si no, principal=0. Solo afecta a los que vinieron en la pantalla (activos).
        //    Esto garantiza "solo 1 principal por usuario" (global).
        //    Si quieres que "principal sea por área", lo cambiamos después.
        //
        // Nota: aquí solo aplicamos principal a usuarios que estén asignados al área.
        // Si no está asignado, principal se ignora.

        // Cargar lista de usuarios activos (los que mostramos)
        $usuarios = [];
        $rsU = $conn->query("SELECT id FROM usuarios WHERE activo=1");
        while($r = $rsU->fetch_assoc()) $usuarios[] = (int)$r['id'];

        // 2) Upsert de asignaciones para el área
        $stUp = $conn->prepare("
          INSERT INTO usuarios_areas (id_usuario, id_area, principal, activo)
          VALUES (?, ?, ?, 1)
          ON DUPLICATE KEY UPDATE
            activo=VALUES(activo),
            principal=VALUES(principal)
        ");

        // 3) Desactivar los que ya no estén asignados (en este área)
        $stOff = $conn->prepare("
          UPDATE usuarios_areas
          SET activo=0, principal=0
          WHERE id_area=? AND id_usuario=? LIMIT 1
        ");

        foreach($usuarios as $idU){
          $isAsig = isset($asignadosIds[$idU]);
          if ($isAsig) {
            $isPrincipal = isset($principalIds[$idU]) ? 1 : 0;
            $stUp->bind_param("iii", $idU, $idArea, $isPrincipal);
            $stUp->execute();
          } else {
            // si no está asignado, lo apagamos en esta área (si existía)
            $stOff->bind_param("ii", $idArea, $idU);
            $stOff->execute();
          }
        }

        $stUp->close();
        $stOff->close();

        $conn->commit();
        $mensaje = "Asignaciones guardadas ✅";
        $tipoMsg = "success";

      } catch (Throwable $e) {
        $conn->rollback();
        $mensaje = "No se pudo guardar: " . h($e->getMessage());
        $tipoMsg = "danger";
      }
    }
  }
}

// Cargar usuarios activos (para pintar tabla)
$users = [];
$rsU2 = $conn->query("SELECT id, nombre, rol, id_sucursal FROM usuarios WHERE activo=1 ORDER BY nombre");
while($r = $rsU2->fetch_assoc()) $users[] = $r;

// Cargar asignaciones actuales del área
$map = []; // id_usuario => ['activo'=>1/0, 'principal'=>1/0]
if ($idArea > 0) {
  $stM = $conn->prepare("SELECT id_usuario, activo, principal FROM usuarios_areas WHERE id_area=?");
  $stM->bind_param("i", $idArea);
  $stM->execute();
  $rsM = $stM->get_result();
  while($r = $rsM->fetch_assoc()){
    $map[(int)$r['id_usuario']] = [
      'activo' => (int)$r['activo'],
      'principal' => (int)$r['principal'],
    ];
  }
  $stM->close();
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Asignar usuarios a áreas</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .cardx{ border:1px solid rgba(0,0,0,.08); border-radius:16px; }
    .soft{ color:#6c757d; }
    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .sticky-head thead th { position: sticky; top: 0; background: #fff; z-index: 1; }
  </style>
</head>
<body class="bg-light">

<?php require_once __DIR__ . '/navbar.php'; ?>

<div class="container py-3">

  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
      <h4 class="mb-0">Usuarios por área</h4>
      <div class="soft small">Define quién pertenece a cada área para filtrar responsables en tareas.</div>
    </div>
    <a class="btn btn-outline-secondary" href="tablero_tareas.php">← Volver</a>
  </div>

  <?php if ($mensaje): ?>
    <div class="alert alert-<?=h($tipoMsg)?>"><?=$mensaje?></div>
  <?php endif; ?>

  <form method="get" class="cardx bg-white p-3 shadow-sm mb-3">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-6">
        <label class="form-label fw-semibold">Área</label>
        <select class="form-select" name="area" onchange="this.form.submit()">
          <?php foreach($areas as $a): ?>
            <?php
              $id = (int)$a['id'];
              $name = $a['nombre'];
              $flag = ((int)$a['activa']===1) ? '' : ' (inactiva)';
            ?>
            <option value="<?=$id?>" <?= $idArea===$id?'selected':'' ?>>
              <?=h($name.$flag)?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="soft small mt-1">Tip: puedes “inactivar” áreas en BD si ya no se usan.</div>
      </div>
      <div class="col-12 col-md-6 text-md-end">
        <div class="soft small">Área seleccionada: <span class="mono">#<?= (int)$idArea ?></span></div>
      </div>
    </div>
  </form>

  <form method="post" class="cardx bg-white p-3 shadow-sm">
    <input type="hidden" name="csrf" value="<?=h($CSRF)?>">
    <input type="hidden" name="area" value="<?= (int)$idArea ?>">

    <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-2">
      <div class="soft small">
        Marca “Asignado” para que el usuario salga cuando se elija esta área en tareas.
        <br>“Principal” es opcional (solo se guarda si está asignado).
      </div>
      <button class="btn btn-primary">Guardar cambios</button>
    </div>

    <div class="table-responsive sticky-head" style="max-height: 65vh;">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr class="small text-secondary">
            <th style="width:90px;">Asignado</th>
            <th style="width:90px;">Principal</th>
            <th>Usuario</th>
            <th>Rol</th>
            <th>Sucursal</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($users as $u): ?>
            <?php
              $idU = (int)$u['id'];
              $estado = $map[$idU]['activo'] ?? 0;
              $isAsig = ($estado == 1);
              $isPri  = (($map[$idU]['principal'] ?? 0) == 1);
            ?>
            <tr>
              <td>
                <input type="checkbox"
                       class="form-check-input"
                       name="asignado[<?=$idU?>]"
                       value="1"
                       <?= $isAsig ? 'checked' : '' ?>>
              </td>
              <td>
                <input type="checkbox"
                       class="form-check-input"
                       name="principal[<?=$idU?>]"
                       value="1"
                       <?= $isPri ? 'checked' : '' ?>
                       <?= $isAsig ? '' : 'disabled' ?>
                       onchange="
                         if(this.checked){
                           // opcional: desmarcar otros principales visualmente
                           document.querySelectorAll('input[name^=principal]').forEach(x=>{ if(x!==this) x.checked=false; });
                         }
                       ">
              </td>
              <td class="fw-semibold"><?= h($u['nombre']) ?></td>
              <td><span class="badge text-bg-light border"><?= h($u['rol']) ?></span></td>
              <td><span class="badge text-bg-light border">Suc <?= (int)$u['id_sucursal'] ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="soft small mt-2">
      Nota: si desmarcas “Asignado”, no borramos el registro, solo lo dejamos en <span class="mono">activo=0</span>.
    </div>
  </form>

</div>

<script>
  // UX: si quitas asignado, deshabilita principal
  document.querySelectorAll('input[name^="asignado"]').forEach(chk=>{
    chk.addEventListener('change', ()=>{
      const id = chk.name.match(/\[(\d+)\]/)?.[1];
      if(!id) return;
      const pri = document.querySelector(`input[name="principal[${id}]"]`);
      if(!pri) return;
      if(chk.checked){
        pri.disabled = false;
      }else{
        pri.checked = false;
        pri.disabled = true;
      }
    });
  });
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php ob_end_flush(); ?>
