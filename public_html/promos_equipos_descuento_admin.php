<?php
// promos_equipos_descuento_admin.php — FULL FIX (incluye switches de modo + defaults)

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php'; // ✅ DB primero (sin navbar aún)

$ROL  = $_SESSION['rol'] ?? 'Ejecutivo';
$perm = in_array($ROL, ['Admin', 'Logistica'], true);
if (!$perm) { http_response_code(403); echo "Sin permiso"; exit(); }

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function normalize_code_keep_spaces(string $code): string {
  $code = str_replace("\r", "", $code);
  $code = str_replace("\t", " ", $code);
  return $code;
}

function split_codes_keep_spaces(string $bulk): array {
  $bulk = str_replace(["\r\n", "\r"], "\n", (string)$bulk);
  $lines = explode("\n", $bulk);

  $out = [];
  foreach ($lines as $ln) {
    $c = normalize_code_keep_spaces($ln);
    if ($c !== '' && preg_match('/\S/', $c)) {
      $out[$c] = true; // dedup exacto
    }
  }
  return array_keys($out);
}

function table_exists(mysqli $conn, string $table): bool {
  $sql = "SELECT 1
          FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
          LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param("s", $table);
  $st->execute();
  $st->store_result();
  $ok = ($st->num_rows > 0);
  $st->free_result();
  $st->close();
  return $ok;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param("ss", $table, $column);
  $st->execute();
  $st->store_result();
  $ok = ($st->num_rows > 0);
  $st->free_result();
  $st->close();
  return $ok;
}

function toNullDate($s){
  $s = trim((string)$s);
  return $s === '' ? null : $s;
}

function safeRedirect(string $url){
  if (!headers_sent()) {
    header("Location: ".$url);
    exit();
  }
  echo "<script>location.href=".json_encode($url).";</script>";
  echo '<noscript><meta http-equiv="refresh" content="0;url='.h($url).'"></noscript>';
  exit();
}

function modosHuman(mysqli $conn, array $p): string {
  $hasPermiteCombo = column_exists($conn, 'promos_equipos_descuento', 'permite_combo');
  $hasPermiteDV    = column_exists($conn, 'promos_equipos_descuento', 'permite_doble_venta');
  if (!$hasPermiteCombo || !$hasPermiteDV) return '—';

  $mods = [];
  if ((int)($p['permite_combo'] ?? 0) === 1) $mods[] = 'COMBO';
  if ((int)($p['permite_doble_venta'] ?? 0) === 1) $mods[] = 'DOBLE_VENTA';
  return count($mods) ? implode(' + ', $mods) : '—';
}

/* ============================
   Validar estructura mínima
============================ */
try {
  foreach (['promos_equipos_descuento','promos_equipos_descuento_principal','promos_equipos_descuento_combo'] as $t) {
    if (!table_exists($conn, $t)) throw new Exception("No existe la tabla {$t}.");
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo "<pre style='padding:16px;background:#fee2e2;border:1px solid #fecaca;border-radius:12px;white-space:pre-wrap;'>";
  echo "Error de estructura: " . h($e->getMessage());
  echo "</pre>";
  exit;
}

$err = '';
$msg = '';

/* ============================
   POST acciones (ANTES de navbar)
============================ */
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_promo') {
      $id     = (int)($_POST['id'] ?? 0);
      $nombre = trim((string)($_POST['nombre'] ?? ''));
      $pct    = (float)($_POST['porcentaje_descuento'] ?? 50);
      $activa = isset($_POST['activa']) ? 1 : 0;

      $permite_combo      = isset($_POST['permite_combo']) ? 1 : 0;
      $permite_dobleventa = isset($_POST['permite_doble_venta']) ? 1 : 0;

      $fi = toNullDate($_POST['fecha_inicio'] ?? '');
      $ff = toNullDate($_POST['fecha_fin'] ?? '');

      if ($nombre === '') throw new Exception("El nombre es obligatorio.");
      if ($pct <= 0 || $pct >= 100) throw new Exception("El % debe ser > 0 y < 100.");

      $hasPermiteCombo = column_exists($conn, 'promos_equipos_descuento', 'permite_combo');
      $hasPermiteDV    = column_exists($conn, 'promos_equipos_descuento', 'permite_doble_venta');
      if ($hasPermiteCombo && $hasPermiteDV) {
        if ($permite_combo === 0 && $permite_dobleventa === 0) {
          throw new Exception("Debes habilitar al menos un modo (COMBO o DOBLE_VENTA).");
        }
      }

      $fi2 = $fi ?? '';
      $ff2 = $ff ?? '';

      if ($id > 0) {
        if ($hasPermiteCombo && $hasPermiteDV) {
          $sql = "UPDATE promos_equipos_descuento
                  SET nombre=?,
                      porcentaje_descuento=?,
                      activa=?,
                      permite_combo=?,
                      permite_doble_venta=?,
                      fecha_inicio = NULLIF(?, ''),
                      fecha_fin    = NULLIF(?, '')
                  WHERE id=?";
          $st = $conn->prepare($sql);
          $st->bind_param("sdiiissi", $nombre, $pct, $activa, $permite_combo, $permite_dobleventa, $fi2, $ff2, $id);
        } else {
          $sql = "UPDATE promos_equipos_descuento
                  SET nombre=?,
                      porcentaje_descuento=?,
                      activa=?,
                      fecha_inicio = NULLIF(?, ''),
                      fecha_fin    = NULLIF(?, '')
                  WHERE id=?";
          $st = $conn->prepare($sql);
          $st->bind_param("sdissi", $nombre, $pct, $activa, $fi2, $ff2, $id);
        }
        $st->execute();
        $st->close();
        $msg = "Promo actualizada.";
      } else {
        if ($hasPermiteCombo && $hasPermiteDV) {
          $sql = "INSERT INTO promos_equipos_descuento
                  (nombre, porcentaje_descuento, activa, permite_combo, permite_doble_venta, fecha_inicio, fecha_fin)
                  VALUES
                  (?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''))";
          $st = $conn->prepare($sql);
          $st->bind_param("sdiiiss", $nombre, $pct, $activa, $permite_combo, $permite_dobleventa, $fi2, $ff2);
        } else {
          $sql = "INSERT INTO promos_equipos_descuento
                  (nombre, porcentaje_descuento, activa, fecha_inicio, fecha_fin)
                  VALUES
                  (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''))";
          $st = $conn->prepare($sql);
          $st->bind_param("sdiss", $nombre, $pct, $activa, $fi2, $ff2);
        }
        $st->execute();
        $id = (int)$conn->insert_id;
        $st->close();
        $msg = "Promo creada.";
      }

      safeRedirect("promos_equipos_descuento_admin.php?edit=".$id."&msg=".urlencode($msg)."#equipos");
    }

    if ($action === 'toggle_promo') {
      $id = (int)($_POST['id'] ?? 0);
      $v  = (int)($_POST['activa'] ?? 0);
      $st = $conn->prepare("UPDATE promos_equipos_descuento SET activa=? WHERE id=?");
      $st->bind_param("ii", $v, $id);
      $st->execute();
      $st->close();
      safeRedirect("promos_equipos_descuento_admin.php?msg=".urlencode("Estado actualizado."));
    }

    if ($action === 'add_item') {
      $promo_id = (int)($_POST['promo_id'] ?? 0);
      $tipo = trim((string)($_POST['tipo'] ?? 'principal'));
      if ($promo_id <= 0) throw new Exception("Promo inválida.");
      if (!in_array($tipo, ['principal','combo'], true)) throw new Exception("Tipo inválido.");

      $tabla = ($tipo === 'principal') ? 'promos_equipos_descuento_principal' : 'promos_equipos_descuento_combo';

      $bulk   = (string)($_POST['bulk_codigos'] ?? '');
      $single = (string)($_POST['codigo_producto'] ?? '');

      $codes = [];
      if ($bulk !== '' && preg_match('/\S/', $bulk)) {
        $codes = split_codes_keep_spaces($bulk);
      } else {
        $c = normalize_code_keep_spaces($single);
        if ($c !== '' && preg_match('/\S/', $c)) $codes = [$c];
      }

      if (count($codes) === 0) throw new Exception("No se detectaron códigos para agregar.");

      $st = $conn->prepare("INSERT INTO {$tabla} (promo_id, codigo_producto, activo)
                            VALUES (?, ?, 1)
                            ON DUPLICATE KEY UPDATE activo=1");

      $insOk = 0;
      foreach ($codes as $codigo) {
        $st->bind_param("is", $promo_id, $codigo);
        $st->execute();
        $insOk++;
      }
      $st->close();

      safeRedirect("promos_equipos_descuento_admin.php?edit=".$promo_id."&msg=".urlencode("Agregados: {$insOk} código(s).")."#equipos");
    }

    if ($action === 'remove_item') {
      $promo_id = (int)($_POST['promo_id'] ?? 0);
      $tipo     = trim((string)($_POST['tipo'] ?? 'principal'));
      $item_id  = (int)($_POST['item_id'] ?? 0);

      if ($promo_id <= 0 || $item_id <= 0) throw new Exception("Datos inválidos.");
      if (!in_array($tipo, ['principal','combo'], true)) throw new Exception("Tipo inválido.");

      $tabla = ($tipo === 'principal') ? 'promos_equipos_descuento_principal' : 'promos_equipos_descuento_combo';

      $st = $conn->prepare("DELETE FROM {$tabla} WHERE id=? AND promo_id=?");
      $st->bind_param("ii", $item_id, $promo_id);
      $st->execute();
      $st->close();

      safeRedirect("promos_equipos_descuento_admin.php?edit=".$promo_id."&msg=".urlencode("Código eliminado.")."#equipos");
    }
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

/* ✅ Ya podemos cargar navbar */
require_once __DIR__ . '/navbar.php';

/* ============================
   GET data
============================ */
$editId = (int)($_GET['edit'] ?? 0);
if (isset($_GET['msg'])) $msg = (string)$_GET['msg'];

$promoEdit = null;
if ($editId > 0) {
  $res = $conn->query("SELECT * FROM promos_equipos_descuento WHERE id={$editId} LIMIT 1");
  if ($res) $promoEdit = $res->fetch_assoc();
}

/* Detectar si existen columnas de modos */
$hasPermiteCombo = column_exists($conn, 'promos_equipos_descuento', 'permite_combo');
$hasPermiteDV    = column_exists($conn, 'promos_equipos_descuento', 'permite_doble_venta');

/* Defaults UI para nuevo */
$ui_perm_combo = (int)($promoEdit['permite_combo'] ?? 0);
$ui_perm_dv    = (int)($promoEdit['permite_doble_venta'] ?? 0);
if (!$promoEdit && $hasPermiteCombo && $hasPermiteDV) {
  // ✅ Default: COMBO activo para que no te bloquee la validación al crear
  $ui_perm_combo = 1;
  $ui_perm_dv    = 0;
}

$promos = $conn->query("
  SELECT p.*,
    (SELECT COUNT(*) FROM promos_equipos_descuento_principal x WHERE x.promo_id=p.id) AS cnt_principal,
    (SELECT COUNT(*) FROM promos_equipos_descuento_combo y WHERE y.promo_id=p.id) AS cnt_combo
  FROM promos_equipos_descuento p
  ORDER BY p.activa DESC, p.id DESC
")->fetch_all(MYSQLI_ASSOC);

$itemsPrincipal = [];
$itemsCombo = [];
try {
  if ($promoEdit) {
    $colsP = "id, codigo_producto, activo";
    if (column_exists($conn, 'promos_equipos_descuento_principal', 'creado_en')) $colsP .= ", creado_en";
    $colsC = "id, codigo_producto, activo";
    if (column_exists($conn, 'promos_equipos_descuento_combo', 'creado_en')) $colsC .= ", creado_en";

    $res = $conn->query("SELECT {$colsP} FROM promos_equipos_descuento_principal WHERE promo_id={$editId} ORDER BY id DESC");
    if ($res) $itemsPrincipal = $res->fetch_all(MYSQLI_ASSOC);

    $res = $conn->query("SELECT {$colsC} FROM promos_equipos_descuento_combo WHERE promo_id={$editId} ORDER BY id DESC");
    if ($res) $itemsCombo = $res->fetch_all(MYSQLI_ASSOC);
  }
} catch (Throwable $e) {
  $err = "Error cargando equipos: " . $e->getMessage();
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Promo: Equipo 2 con % descuento</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico?v=2">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    body { background:#f8fafc; }
    .card-elev { border:0; border-radius:16px; box-shadow:0 10px 24px rgba(2,8,20,.06), 0 2px 6px rgba(2,8,20,.05); }
    .mini { font-size:.9rem; color:#64748b; }
    .badge-soft { background:#eef2ff; color:#1e40af; border:1px solid #dbeafe; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    textarea.mono { min-height: 150px; }
    .codecell { white-space: pre; }
  </style>
</head>
<body>
<div class="container my-4">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-1"><i class="bi bi-sliders2 me-2"></i>Promo: Equipo 2 con % descuento</h3>
      <div class="mini">Editar, activar/inactivar y pegar códigos masivos tal cual Excel (con espacios).</div>
    </div>
    <a class="btn btn-outline-secondary" href="panel.php"><i class="bi bi-arrow-left"></i> Volver</a>
  </div>

  <?php if ($err !== ''): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= h($err) ?></div>
  <?php endif; ?>
  <?php if ($msg !== ''): ?>
    <div class="alert alert-success"><i class="bi bi-check2-circle me-1"></i><?= h($msg) ?></div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card card-elev">
        <div class="card-body">
          <div class="fw-semibold mb-2">Crear / Editar promo</div>

          <form method="post">
            <input type="hidden" name="action" value="save_promo">
            <input type="hidden" name="id" value="<?= (int)($promoEdit['id'] ?? 0) ?>">

            <div class="mb-2">
              <label class="form-label">Nombre</label>
              <input class="form-control" name="nombre" value="<?= h($promoEdit['nombre'] ?? '') ?>">
            </div>

            <div class="mb-2">
              <label class="form-label">% descuento</label>
              <input class="form-control" name="porcentaje_descuento" type="number" step="0.01" min="0.01" max="99.99"
                     value="<?= h($promoEdit['porcentaje_descuento'] ?? '50.00') ?>">
            </div>

            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">Fecha inicio</label>
                <input class="form-control" type="date" name="fecha_inicio" value="<?= h($promoEdit['fecha_inicio'] ?? '') ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Fecha fin</label>
                <input class="form-control" type="date" name="fecha_fin" value="<?= h($promoEdit['fecha_fin'] ?? '') ?>">
              </div>
            </div>

            <div class="form-check form-switch mt-3">
              <?php $a = (int)($promoEdit['activa'] ?? 1); ?>
              <input class="form-check-input" type="checkbox" role="switch" id="activa" name="activa" value="1" <?= $a? 'checked':'' ?>>
              <label class="form-check-label" for="activa">Activa</label>
            </div>

            <?php if ($hasPermiteCombo && $hasPermiteDV): ?>
              <!-- ✅ AQUÍ ESTÁN LOS “CHECKS” QUE TE FALTABAN -->
              <div class="mt-3 p-3 rounded-3" style="background:#f1f5f9;">
                <div class="fw-semibold mb-2"><i class="bi bi-toggles2 me-1"></i> Modos permitidos</div>
                <div class="mini mb-2">Debes habilitar al menos uno.</div>

                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" role="switch"
                         id="permite_combo" name="permite_combo" value="1"
                         <?= $ui_perm_combo ? 'checked':'' ?>>
                  <label class="form-check-label" for="permite_combo">COMBO</label>
                </div>

                <div class="form-check form-switch mt-2">
                  <input class="form-check-input" type="checkbox" role="switch"
                         id="permite_doble_venta" name="permite_doble_venta" value="1"
                         <?= $ui_perm_dv ? 'checked':'' ?>>
                  <label class="form-check-label" for="permite_doble_venta">DOBLE_VENTA</label>
                </div>
              </div>
            <?php endif; ?>

            <div class="d-grid mt-3">
              <button class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar promo</button>
              <?php if ($promoEdit): ?>
                <a class="btn btn-link" href="promos_equipos_descuento_admin.php">Crear nueva</a>
              <?php endif; ?>
            </div>
          </form>

        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card card-elev">
        <div class="card-body">
          <div class="fw-semibold">Promos existentes</div>
          <hr>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead class="table-light">
                <tr>
                  <th>ID</th><th>Nombre</th><th>%</th>
                  <?php if ($hasPermiteCombo && $hasPermiteDV): ?><th>Modo</th><?php endif; ?>
                  <th>Principal</th><th>Combo</th><th>Activa</th><th class="text-end">Acciones</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($promos as $p): ?>
                <tr>
                  <td class="mono"><?= (int)$p['id'] ?></td>
                  <td><?= h($p['nombre']) ?></td>
                  <td><?= number_format((float)$p['porcentaje_descuento'], 2) ?>%</td>
                  <?php if ($hasPermiteCombo && $hasPermiteDV): ?>
                    <td class="mini"><?= h(modosHuman($conn, $p)) ?></td>
                  <?php endif; ?>
                  <td><span class="badge badge-soft"><?= (int)$p['cnt_principal'] ?></span></td>
                  <td><span class="badge badge-soft"><?= (int)$p['cnt_combo'] ?></span></td>
                  <td>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="toggle_promo">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="activa" value="<?= (int)!((int)$p['activa']) ?>">
                      <button class="btn btn-sm <?= ((int)$p['activa']) ? 'btn-success':'btn-outline-secondary' ?>">
                        <?= ((int)$p['activa']) ? 'Sí' : 'No' ?>
                      </button>
                    </form>
                  </td>
                  <td class="text-end">
                    <a class="btn btn-sm btn-outline-primary" href="promos_equipos_descuento_admin.php?edit=<?= (int)$p['id'] ?>">
                      <i class="bi bi-pencil-square"></i> Editar
                    </a>
                    <a class="btn btn-sm btn-primary" href="promos_equipos_descuento_admin.php?edit=<?= (int)$p['id'] ?>#equipos">
                      <i class="bi bi-gear"></i> Configurar equipos
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (count($promos) === 0): ?>
                <tr><td colspan="8" class="text-center mini">Aún no hay promos.</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>

      <?php if ($promoEdit): ?>
        <div id="equipos" class="row g-3 mt-0">
          <div class="col-md-6">
            <div class="card card-elev">
              <div class="card-body">
                <div class="fw-semibold"><i class="bi bi-lightning-charge me-1"></i>Principales</div>
                <div class="mini">Pegado masivo (uno por línea). Se guarda tal cual.</div>
                <hr>

                <form method="post" class="row g-2">
                  <input type="hidden" name="action" value="add_item">
                  <input type="hidden" name="promo_id" value="<?= (int)$promoEdit['id'] ?>">
                  <input type="hidden" name="tipo" value="principal">
                  <div class="col-12">
                    <textarea class="form-control mono" name="bulk_codigos"></textarea>
                  </div>
                  <div class="col-12 d-grid">
                    <button class="btn btn-outline-primary"><i class="bi bi-plus-lg"></i> Cargar en bloque</button>
                  </div>
                </form>

                <div class="table-responsive mt-3">
                  <table class="table table-sm align-middle">
                    <thead class="table-light"><tr><th>Código</th><th></th></tr></thead>
                    <tbody>
                      <?php foreach ($itemsPrincipal as $it): ?>
                        <tr>
                          <td class="mono codecell"><?= h($it['codigo_producto']) ?></td>
                          <td class="text-end">
                            <form method="post" class="d-inline">
                              <input type="hidden" name="action" value="remove_item">
                              <input type="hidden" name="promo_id" value="<?= (int)$promoEdit['id'] ?>">
                              <input type="hidden" name="tipo" value="principal">
                              <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                              <button class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar este código?')">
                                <i class="bi bi-trash"></i>
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (count($itemsPrincipal) === 0): ?>
                        <tr><td colspan="2" class="mini text-center">Sin códigos aún.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>

              </div>
            </div>
          </div>

          <div class="col-md-6">
            <div class="card card-elev">
              <div class="card-body">
                <div class="fw-semibold"><i class="bi bi-percent me-1"></i>Combos</div>
                <div class="mini">Pegado masivo (uno por línea). Se guarda tal cual.</div>
                <hr>

                <form method="post" class="row g-2">
                  <input type="hidden" name="action" value="add_item">
                  <input type="hidden" name="promo_id" value="<?= (int)$promoEdit['id'] ?>">
                  <input type="hidden" name="tipo" value="combo">
                  <div class="col-12">
                    <textarea class="form-control mono" name="bulk_codigos"></textarea>
                  </div>
                  <div class="col-12 d-grid">
                    <button class="btn btn-outline-success"><i class="bi bi-plus-lg"></i> Cargar en bloque</button>
                  </div>
                </form>

                <div class="table-responsive mt-3">
                  <table class="table table-sm align-middle">
                    <thead class="table-light"><tr><th>Código</th><th></th></tr></thead>
                    <tbody>
                      <?php foreach ($itemsCombo as $it): ?>
                        <tr>
                          <td class="mono codecell"><?= h($it['codigo_producto']) ?></td>
                          <td class="text-end">
                            <form method="post" class="d-inline">
                              <input type="hidden" name="action" value="remove_item">
                              <input type="hidden" name="promo_id" value="<?= (int)$promoEdit['id'] ?>">
                              <input type="hidden" name="tipo" value="combo">
                              <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                              <button class="btn btn-sm btn-outline-danger" onclick="return confirm('¿Eliminar este código?')">
                                <i class="bi bi-trash"></i>
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (count($itemsCombo) === 0): ?>
                        <tr><td colspan="2" class="mini text-center">Sin códigos aún.</td></tr>
                      <?php endif; ?>
                    </tbody>
                  </table>
                </div>

              </div>
            </div>
          </div>

        </div>
      <?php endif; ?>

    </div>
  </div>

</div>
</body>
</html>
