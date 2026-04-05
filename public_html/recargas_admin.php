<?php
// recargas_admin.php — Admin/Logística: crear promo y ver/promociones
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

$ROL = $_SESSION['rol'] ?? '';
if (!in_array($ROL, ['Admin', 'Logistica'], true)) { header("Location: 403.php"); exit(); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ----- Mensajes flash -----
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// ----- Crear promo -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_promo'])) {
    $nombre = trim($_POST['nombre'] ?? '');
    $origen = $_POST['origen'] ?? 'LUGA';
    $fi     = $_POST['fecha_inicio'] ?? '';
    $ff     = $_POST['fecha_fin'] ?? '';
    $activa = isset($_POST['activa']) ? 1 : 0;

    // Validación básica
    $ok = true; $err = [];
    if ($nombre === '') { $ok = false; $err[] = 'Falta el nombre.'; }
    $dFi = DateTime::createFromFormat('Y-m-d', $fi);
    $dFf = DateTime::createFromFormat('Y-m-d', $ff);
    if (!$dFi || $dFi->format('Y-m-d') !== $fi) { $ok = false; $err[] = 'Fecha de inicio inválida.'; }
    if (!$dFf || $dFf->format('Y-m-d') !== $ff) { $ok = false; $err[] = 'Fecha de fin inválida.'; }
    if ($ok && $dFi > $dFf) { $ok = false; $err[] = 'El inicio no puede ser mayor que el fin.'; }

    if ($ok) {
        $stmt = $conn->prepare(
            "INSERT INTO recargas_promo (nombre, origen, fecha_inicio, fecha_fin, activa)
             VALUES (?,?,?,?,?)"
        );
        // 4 strings + 1 int  => 'ssssi'
        $stmt->bind_param('ssssi', $nombre, $origen, $fi, $ff, $activa);
        if ($stmt->execute()) {
            $_SESSION['flash'] = "<div class='alert alert-success'>✅ Promo creada correctamente.</div>";
        } else {
            $_SESSION['flash'] = "<div class='alert alert-danger'>Error al crear: ".h($stmt->error)."</div>";
        }
        $stmt->close();
        header("Location: recargas_admin.php"); exit();
    } else {
        $_SESSION['flash'] = "<div class='alert alert-danger'>".implode('<br>', array_map('h',$err))."</div>";
        header("Location: recargas_admin.php"); exit();
    }
}

// ----- Toggle activa/pausa -----
if (isset($_GET['toggle'], $_GET['id'])) {
    $id = (int)$_GET['id'];
    $conn->query("UPDATE recargas_promo SET activa = 1 - activa WHERE id = $id");
    header("Location: recargas_admin.php"); exit();
}

// Navbar si existe
if (file_exists(__DIR__.'/navbar.php')) require_once __DIR__.'/navbar.php';
?>
<div class="container mt-3">
  <h3>Recargas Promocionales — Admin</h3>
  <?= $flash ?>

  <form method="post" class="row g-2 border rounded p-3 bg-light">
    <input type="hidden" name="crear_promo" value="1">
    <div class="col-md-3">
      <label class="form-label">Nombre</label>
      <input name="nombre" class="form-control" maxlength="100" required>
    </div>
    <div class="col-md-2">
      <label class="form-label">Central</label>
      <select name="origen" class="form-select">
        <option value="LUGA">LUGA</option>
        <option value="NANO">NANO</option>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Inicio</label>
      <input type="date" name="fecha_inicio" class="form-control" required>
    </div>
    <div class="col-md-2">
      <label class="form-label">Fin</label>
      <input type="date" name="fecha_fin" class="form-control" required>
    </div>
    <div class="col-md-2 d-flex align-items-end">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="activa" id="activa" checked>
        <label class="form-check-label" for="activa">Activa</label>
      </div>
    </div>
    <div class="col-md-1 d-flex align-items-end">
      <button class="btn btn-primary w-100">Crear</button>
    </div>
  </form>

  <hr>
  <h5>Promociones</h5>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>ID</th>
          <th>Nombre</th>
          <th>Central</th>
          <th>Rango</th>
          <th>Activa</th>
          <th>Base</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php
      $rs = $conn->query("SELECT * FROM recargas_promo ORDER BY id DESC");
      while ($r = $rs->fetch_assoc()):
        $badge = ((int)$r['activa'] === 1)
          ? "<span class='badge bg-success'>Activa</span>"
          : "<span class='badge bg-secondary'>Pausada</span>";
        $rango = h($r['fecha_inicio'])." → ".h($r['fecha_fin']);
      ?>
        <tr>
          <td><?= (int)$r['id'] ?></td>
          <td><?= h($r['nombre']) ?></td>
          <td><?= h($r['origen']) ?></td>
          <td><?= $rango ?></td>
          <td><?= $badge ?></td>
          <td>
            <form method="post" action="recargas_base_generar.php" class="d-inline">
              <input type="hidden" name="id_promo" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-outline-primary btn-sm">Generar/Actualizar</button>
            </form>
          </td>
          <td>
            <a class="btn btn-sm btn-outline-warning" href="recargas_admin.php?toggle=1&id=<?= (int)$r['id'] ?>">
              Activar/pausar
            </a>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
