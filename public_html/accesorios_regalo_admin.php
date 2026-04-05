<?php
// accesorios_regalo_admin.php — Admin/Logística: modelos elegibles para REGALO
// Ahora trabaja por codigo_producto (modelo de accesorio), no por id de pieza.

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['id_usuario'])) { header('Location: index.php'); exit(); }

require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
if (!in_array($ROL, ['Admin','Logistica'], true)) { header('Location: 403.php'); exit(); }

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function jexit($arr){
  while (ob_get_level() > 0) { ob_end_clean(); }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}
function ok($extra=[]){ jexit(array_merge(['ok'=>true], $extra)); }
function bad($msg){ jexit(['ok'=>false, 'error'=>$msg]); }
function column_exists(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $rs = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $rs && $rs->num_rows > 0;
}
function index_exists(mysqli $conn, string $table, string $index): bool {
  $t = $conn->real_escape_string($table);
  $i = $conn->real_escape_string($index);
  $rs = $conn->query("SHOW INDEX FROM `{$t}` WHERE Key_name = '{$i}'");
  return $rs && $rs->num_rows > 0;
}

/* ===== DDL SEGURA: tabla por codigo_producto ===== */
$conn->query("CREATE TABLE IF NOT EXISTS accesorios_regalo_modelos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_producto INT NULL,
  codigo_producto VARCHAR(100) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  codigos_equipos TEXT NULL,
  vender TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if (!column_exists($conn, 'accesorios_regalo_modelos', 'codigo_producto')) {
  $conn->query("ALTER TABLE accesorios_regalo_modelos ADD COLUMN codigo_producto VARCHAR(100) NULL AFTER id_producto");
}

/// backfill básico (por si viene de una versión previa)
@$conn->query("
  UPDATE accesorios_regalo_modelos arm
  JOIN productos p ON p.id = arm.id_producto
  SET arm.codigo_producto = p.codigo_producto
  WHERE (arm.codigo_producto IS NULL OR arm.codigo_producto = '')
    AND p.codigo_producto IS NOT NULL AND p.codigo_producto <> ''
");

/// 🔧 Quitar CUALQUIER índice único viejo sobre id_producto
$rsIdx = $conn->query("
  SHOW INDEX FROM accesorios_regalo_modelos
  WHERE Column_name = 'id_producto' AND Non_unique = 0
");
if ($rsIdx && $rsIdx->num_rows > 0) {
  while ($idx = $rsIdx->fetch_assoc()) {
    $key = preg_replace('/[^A-Za-z0-9_]/', '', $idx['Key_name']);
    if ($key !== '' && $key !== 'PRIMARY') {
      @$conn->query("ALTER TABLE accesorios_regalo_modelos DROP INDEX `$key`");
    }
  }
}

/// índice único por codigo_producto (una promo por modelo de accesorio)
if (!index_exists($conn, 'accesorios_regalo_modelos', 'uniq_codigo_producto')) {
  $conn->query("ALTER TABLE accesorios_regalo_modelos ADD UNIQUE KEY uniq_codigo_producto (codigo_producto)");
}

if (!column_exists($conn, 'accesorios_regalo_modelos', 'codigos_equipos')) {
  $conn->query("ALTER TABLE accesorios_regalo_modelos ADD COLUMN codigos_equipos TEXT NULL AFTER activo");
}
if (!column_exists($conn, 'accesorios_regalo_modelos', 'vender')) {
  $conn->query("ALTER TABLE accesorios_regalo_modelos ADD COLUMN vender TINYINT(1) NOT NULL DEFAULT 1 AFTER codigos_equipos");
}

/* ===== Endpoints AJAX ===== */
$action = $_GET['action'] ?? $_POST['action'] ?? null;

/**
 * search_products:
 *   devuelve modelos de ACCESORIOS (o sin IMEI) agrupados por codigo_producto.
 *   Muestra descripcion primero, si no existe usa marca+modelo+color.
 */
if ($action === 'search_products') {
  $q = trim($_GET['q'] ?? '');
  if ($q === '') bad('Término vacío');

  $hasMarca       = column_exists($conn, 'productos', 'marca');
  $hasModelo      = column_exists($conn, 'productos', 'modelo');
  $hasColor       = column_exists($conn, 'productos', 'color');
  $hasTipo        = column_exists($conn, 'productos', 'tipo_producto');
  $hasDescripcion = column_exists($conn, 'productos', 'descripcion');

  // Nombre visible: primero descripcion, si no existe entonces marca+modelo+color
  $nombreFallbackParts = [];
  if ($hasMarca)  $nombreFallbackParts[] = "MAX(p.marca)";
  if ($hasModelo) $nombreFallbackParts[] = "MAX(p.modelo)";
  if ($hasColor)  $nombreFallbackParts[] = "MAX(COALESCE(p.color,''))";

  $nombreFallback = empty($nombreFallbackParts)
    ? "CONCAT('Producto #', MIN(p.id))"
    : "TRIM(CONCAT(" . implode(", ' ', ", $nombreFallbackParts) . "))";

  if ($hasDescripcion) {
    $nombreExpr = "COALESCE(NULLIF(MAX(p.descripcion),''), $nombreFallback)";
  } else {
    $nombreExpr = $nombreFallback;
  }

  $filtrosTipo = [];
  if ($hasTipo) { $filtrosTipo[] = "UPPER(p.tipo_producto) IN ('ACCESORIO','ACCESORIOS')"; }
  // Permitimos también los que no tienen IMEI (accesorios sin serie)
  $filtrosTipo[] = "(COALESCE(p.imei1,'')='' AND COALESCE(p.imei2,'')='')";
  $whereTipo = "(" . implode(" OR ", $filtrosTipo) . ")";

  $likes = [];
  if ($hasDescripcion) $likes[] = "p.descripcion LIKE CONVERT(? USING utf8mb4)";
  if ($hasMarca)       $likes[] = "p.marca       LIKE CONVERT(? USING utf8mb4)";
  if ($hasModelo)      $likes[] = "p.modelo      LIKE CONVERT(? USING utf8mb4)";
  if ($hasColor)       $likes[] = "p.color       LIKE CONVERT(? USING utf8mb4)";

  // siempre trabajamos por codigo_producto
  if (empty($likes)) {
    $sql = "SELECT DISTINCT
              p.codigo_producto AS id_producto,
              $nombreExpr AS nombre
            FROM productos p
            WHERE $whereTipo
              AND p.codigo_producto IS NOT NULL
              AND p.codigo_producto <> ''
              AND p.codigo_producto = ?
            ORDER BY nombre ASC
            LIMIT 25";
    $st = $conn->prepare($sql);
    if (!$st) bad('Error SQL (prep/fallback): '.$conn->error);
    $st->bind_param('s', $q);
  } else {
    $sql = "SELECT
              p.codigo_producto AS id_producto,
              $nombreExpr AS nombre
            FROM productos p
            WHERE $whereTipo
              AND p.codigo_producto IS NOT NULL
              AND p.codigo_producto <> ''
              AND (" . implode(" OR ", $likes) . ")
            GROUP BY p.codigo_producto
            ORDER BY nombre ASC
            LIMIT 25";
    $st = $conn->prepare($sql);
    if (!$st) bad('Error SQL (prepare): '.$conn->error);
    $qLike = '%'.$q.'%';
    $binds = array_fill(0, count($likes), $qLike);
    $types = str_repeat('s', count($binds));
    $st->bind_param($types, ...$binds);
  }

  if (!$st->execute()) bad('Error SQL (exec): '.$st->error);
  $rs = $st->get_result();
  if (!$rs) bad('Error SQL (result): '.$st->error);
  $items = $rs->fetch_all(MYSQLI_ASSOC);
  ok(['items'=>$items]);
}

/**
 * add_or_activate:
 *   inserta / reactiva por codigo_producto (NO por id_producto).
 */
if ($action === 'add_or_activate') {
  $codigo = trim($_POST['id_producto'] ?? '');
  if ($codigo === '') {
    bad('Código de producto inválido');
  }
  $codigo = trim($codigo);

  try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    // id_producto lo dejamos NULL a propósito; ya no es relevante
    $sql = "
      INSERT INTO accesorios_regalo_modelos (id_producto, codigo_producto, activo)
      VALUES (NULL, ?, 1)
      ON DUPLICATE KEY UPDATE
        activo = VALUES(activo),
        updated_at = CURRENT_TIMESTAMP
    ";
    $st = $conn->prepare($sql);
    if (!$st) {
      mysqli_report(MYSQLI_REPORT_OFF);
      bad('Error preparando alta: '.$conn->error);
    }

    $st->bind_param('s', $codigo);
    $st->execute();
    $st->close();

    mysqli_report(MYSQLI_REPORT_OFF);
    ok();

  } catch (Throwable $e) {
    mysqli_report(MYSQLI_REPORT_OFF);
    bad('Error en add_or_activate: '.$e->getMessage());
  }
}

/**
 * toggle: activa/inactiva como REGALO por codigo_producto
 */
if ($action === 'toggle') {
  $codigo = trim($_POST['id_producto'] ?? '');
  $val    = isset($_POST['activo']) ? (int)$_POST['activo'] : -1;
  if ($codigo === '' || ($val !== 0 && $val !== 1)) bad('Parámetros inválidos');

  $st = $conn->prepare("UPDATE accesorios_regalo_modelos
                        SET activo=?, updated_at=CURRENT_TIMESTAMP
                        WHERE codigo_producto=?");
  if (!$st) bad('Error preparando toggle: '.$conn->error);
  $st->bind_param('is', $val, $codigo);
  if (!$st->execute()) bad('Error al actualizar: '.$st->error);
  ok();
}

/**
 * toggle_vender: permite / bloquea VENTA normal por codigo_producto
 */
if ($action === 'toggle_vender') {
  $codigo = trim($_POST['id_producto'] ?? '');
  $val    = isset($_POST['vender']) ? (int)$_POST['vender'] : -1;
  if ($codigo === '' || ($val !== 0 && $val !== 1)) bad('Parámetros inválidos');

  $st = $conn->prepare("UPDATE accesorios_regalo_modelos
                        SET vender=?, updated_at=CURRENT_TIMESTAMP
                        WHERE codigo_producto=?");
  if (!$st) bad('Error preparando toggle_vender: '.$conn->error);
  $st->bind_param('is', $val, $codigo);
  if (!$st->execute()) bad('Error al actualizar: '.$st->error);
  ok();
}

/**
 * remove: elimina por codigo_producto
 */
if ($action === 'remove') {
  $codigo = trim($_POST['id_producto'] ?? '');
  if ($codigo === '') bad('Código inválido');
  $st = $conn->prepare("DELETE FROM accesorios_regalo_modelos WHERE codigo_producto=?");
  if (!$st) bad('Error preparando eliminación: '.$conn->error);
  $st->bind_param('s', $codigo);
  if (!$st->execute()) bad('Error al eliminar: '.$st->error);
  ok();
}

/**
 * list: listado general, agrupado por codigo_producto
 */
if ($action === 'list') {
  $only = isset($_GET['solo_activos']) ? (int)$_GET['solo_activos'] : -1;
  $where = ($only === 1) ? " WHERE arm.activo=1 " : "";

  $sql = "SELECT
            arm.codigo_producto AS id_producto,
            arm.activo,
            arm.codigos_equipos,
            arm.vender,
            COALESCE(
              NULLIF(MAX(p.descripcion),''),
              TRIM(CONCAT(
                COALESCE(MAX(p.marca),''), ' ',
                COALESCE(MAX(p.modelo),''), ' ',
                COALESCE(MAX(p.color),'')
              ))
            ) AS nombre
          FROM accesorios_regalo_modelos arm
          LEFT JOIN productos p
            ON p.codigo_producto COLLATE utf8mb4_unicode_ci
             = arm.codigo_producto COLLATE utf8mb4_unicode_ci
          $where
          GROUP BY arm.codigo_producto, arm.activo, arm.codigos_equipos, arm.vender
          ORDER BY arm.activo DESC, nombre ASC";

  $rs = $conn->query($sql);
  $rows = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];

  foreach ($rows as &$r){
    $raw = trim((string)($r['codigos_equipos'] ?? ''));
    $cnt = 0;
    if ($raw !== ''){
      if (preg_match('/^\s*\[/', $raw)) {
        $arr = json_decode($raw, true);
        if (is_array($arr)) $cnt = count(array_filter($arr, fn($x)=>trim((string)$x) !== ''));
      } else {
        $parts = array_filter(array_map('trim', explode(',', $raw)), fn($x)=>$x!=='');
        $cnt = count($parts);
      }
    }
    $r['codes_count'] = $cnt;
    $r['vender'] = (int)($r['vender'] ?? 1);
  }
  ok(['rows'=>$rows]);
}

/**
 * get_codes / save_codes: usan codigo_producto
 */
if ($action === 'get_codes') {
  $codigo = trim($_GET['id_producto'] ?? '');
  if ($codigo === '') bad('Código inválido');
  $st = $conn->prepare("SELECT codigos_equipos FROM accesorios_regalo_modelos WHERE codigo_producto=? LIMIT 1");
  if (!$st) bad('Error preparando lectura: '.$conn->error);
  $st->bind_param('s', $codigo);
  if (!$st->execute()) bad('Error al leer: '.$st->error);
  $row = $st->get_result()->fetch_assoc();
  ok(['codigos' => (string)($row['codigos_equipos'] ?? '')]);
}

if ($action === 'save_codes') {
  $codigo = trim($_POST['id_producto'] ?? '');
  $raw    = (string)($_POST['codigos'] ?? '');
  if ($codigo === '') bad('Código inválido');

  $txt = trim($raw);
  if ($txt !== '') {
    if (preg_match('/^\s*\[/', $txt)) {
      $arr = json_decode($txt, true);
      if (!is_array($arr)) bad('JSON inválido en la lista de códigos.');
      $arr = array_values(array_unique(array_filter(array_map(fn($x)=>trim((string)$x), $arr), fn($x)=>$x!=='')));
      $txt = json_encode($arr, JSON_UNESCAPED_UNICODE);
    } else {
      $txt = str_replace(["\r\n","\r"], "\n", $txt);
      $parts = preg_split('/[\n,]+/', $txt);
      $parts = array_values(array_unique(array_filter(array_map('trim', $parts), fn($x)=>$x!=='')));
      $txt = implode(',', $parts);
    }
    if (strlen($txt) > 65534) bad('La lista es demasiado grande.');
  }

  $st = $conn->prepare("UPDATE accesorios_regalo_modelos
                        SET codigos_equipos=?, updated_at=CURRENT_TIMESTAMP
                        WHERE codigo_producto=?");
  if (!$st) bad('Error preparando guardado: '.$conn->error);
  $st->bind_param('ss', $txt, $codigo);
  if (!$st->execute()) bad('Error al guardar: '.$st->error);
  ok();
}

/* ===== Render HTML (no AJAX) ===== */
require_once __DIR__.'/navbar.php';
$primerListado = [];
$init = $conn->query("
  SELECT
    arm.codigo_producto AS id_producto,
    arm.activo,
    arm.codigos_equipos,
    arm.vender,
    COALESCE(
      NULLIF(MAX(p.descripcion),''),
      TRIM(CONCAT(
        COALESCE(MAX(p.marca),''), ' ',
        COALESCE(MAX(p.modelo),''), ' ',
        COALESCE(MAX(p.color),'')
      ))
    ) AS nombre
  FROM accesorios_regalo_modelos arm
  LEFT JOIN productos p
    ON p.codigo_producto COLLATE utf8mb4_unicode_ci
     = arm.codigo_producto COLLATE utf8mb4_unicode_ci
  GROUP BY arm.codigo_producto, arm.activo, arm.codigos_equipos, arm.vender
  ORDER BY arm.activo DESC, nombre ASC
");
if ($init) { $primerListado = $init->fetch_all(MYSQLI_ASSOC); }
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Modelos elegibles para regalo</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{background:linear-gradient(135deg,#f6f9fc 0%,#edf2f7 100%)}
    .card-ghost{backdrop-filter:saturate(140%) blur(6px); border:1px solid rgba(0,0,0,.06); box-shadow:0 10px 25px rgba(0,0,0,.06)}
    .badge-soft{background:#0d6efd14;border:1px solid #0d6efd2e}
    .muted{color:#6c757d}
    .portal{position:static; z-index:1000; background:#fff; border:1px solid #dee2e6; border-radius:.5rem; box-shadow:0 12px 24px rgba(0,0,0,.12); display:none; max-height:260px; overflow:auto;}
    .portal-item{padding:.45rem .6rem; cursor:pointer}
    .portal-item:hover{background:#0d6efd10}
    .table-sticky thead th{position:sticky; top:0; background:#fff; z-index:1}
    .w-35{width:32%}
    .w-15{width:15%}
    .w-10{width:10%}
  </style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-0">Modelos elegibles para <span class="text-success">regalo</span></h3>
      <span class="badge rounded-pill text-secondary badge-soft">Acceso: <?= h($ROL) ?></span>
    </div>
    <div><a href="venta_accesorios.php" class="btn btn-outline-secondary btn-sm">Ir a venta</a></div>
  </div>

  <div class="card card-ghost p-3 mb-3">
    <h5 class="mb-3">Agregar accesorio a la lista (por código de producto)</h5>
    <div class="row g-2 align-items-end position-relative" id="searchRow">
      <div class="col-md-8 position-relative">
        <label class="form-label">Buscar accesorio (descripción, marca, modelo o color)</label>
        <input type="text" id="q" class="form-control" autocomplete="off" placeholder="Ej. 'cable lightning'">
        <div id="portal" class="portal"></div>
        <div class="form-text">
          Se guarda una sola vez por <b>codigo_producto</b>, aunque existan muchas piezas con serie distinta.
        </div>
      </div>
      <div class="col-md-2">
        <button id="btnAdd" class="btn btn-primary w-100" type="button" disabled>Agregar</button>
      </div>
      <div class="col-md-2">
        <div class="form-check mt-4">
          <input class="form-check-input" type="checkbox" id="cbSoloActivos">
          <label class="form-check-label" for="cbSoloActivos">Ver solo activos</label>
        </div>
      </div>
      <input type="hidden" id="selIdProducto" value="">
    </div>
  </div>

  <div class="card card-ghost p-3">
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h5 class="mb-0">Listado</h5>
      <small class="muted">Activa/desactiva REGALO, bloquea/permite VENTA, quita o edita códigos de equipos.</small>
    </div>
    <div class="table-responsive">
      <table class="table table-sm align-middle table-sticky" id="tbl">
        <thead class="table-light">
          <tr>
            <th class="w-35">Accesorio (modelo)</th>
            <th class="w-15">Regalo</th>
            <th class="w-15">Vender</th>
            <th class="w-15 text-center">Códigos</th>
            <th class="w-10 text-center">Editar</th>
            <th class="w-10 text-center">Quitar</th>
          </tr>
        </thead>
        <tbody id="tbody">
          <?php if (empty($primerListado)): ?>
            <tr><td colspan="6" class="text-center text-muted">Sin modelos configurados todavía.</td></tr>
          <?php else: foreach ($primerListado as $r):
            $nombre = trim($r['nombre']) !== '' ? $r['nombre'] : ('Código '.h($r['id_producto']));
            $raw = trim((string)($r['codigos_equipos'] ?? ''));
            $cnt = 0;
            if ($raw !== '') {
              if (preg_match('/^\s*\[/', $raw)) {
                $arr = json_decode($raw, true);
                if (is_array($arr)) $cnt = count(array_filter($arr, fn($x)=>trim((string)$x) !== ''));
              } else {
                $parts = array_filter(array_map('trim', explode(',', $raw)), fn($x)=>$x!=='');
                $cnt = count($parts);
              }
            }
            $vender = (int)($r['vender'] ?? 1);
          ?>
            <tr data-id="<?= h($r['id_producto']) ?>">
              <td><?= h($nombre) ?><br><small class="text-muted">Código: <?= h($r['id_producto']) ?></small></td>
              <td>
                <?php if ((int)$r['activo']===1): ?>
                  <button class="btn btn-outline-warning btn-sm btnToggle" data-val="0">Desactivar</button>
                <?php else: ?>
                  <button class="btn btn-outline-success btn-sm btnToggle" data-val="1">Activar</button>
                <?php endif; ?>
              </td>
              <td>
                <div class="form-check form-switch">
                  <input class="form-check-input swVender" type="checkbox" <?= $vender? 'checked':'' ?> />
                  <label class="form-check-label small"><?= $vender? 'Permitida' : 'Bloqueada' ?></label>
                </div>
              </td>
              <td class="text-center"><span class="badge bg-secondary-subtle text-dark"><?= $cnt ?> códigos</span></td>
              <td class="text-center">
                <button class="btn btn-outline-primary btn-sm btnEditCodes">Editar códigos</button>
              </td>
              <td class="text-center">
                <button class="btn btn-outline-danger btn-sm btnRemove">Quitar</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: editar códigos -->
<div class="modal fade" id="codesModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Códigos de equipos habilitadores</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">
          Ingresa los <b>codigo_producto</b> de los equipos que habilitan el regalo para este accesorio.
          Puedes usar <b>CSV</b> (separados por coma) o <b>JSON</b> (<code>["A12-128","SM-A15"]</code>),
          o uno por línea.
        </p>
        <textarea id="codesText" class="form-control" rows="8" placeholder="A12-128,SM-A15,IP12-64"></textarea>
        <div class="d-flex justify-content-between mt-2">
          <small id="codesCount" class="text-muted">0 códigos</small>
          <div class="btn-group btn-group-sm">
            <button type="button" id="btnToCSV" class="btn btn-outline-secondary">A CSV</button>
            <button type="button" id="btnToJSON" class="btn btn-outline-secondary">A JSON</button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" id="btnSaveCodes">Guardar</button>
      </div>
    </div>
  </div>
</div>

<script>
const $ = sel => document.querySelector(sel);
async function jfetch(url, opt){
  const r = await fetch(url, opt);
  const t = await r.text();
  try { return JSON.parse(t); } catch(e){ throw new Error(t || 'Respuesta inválida'); }
}
function escHtml(str){
  return String(str ?? '').replace(/[&<>"']/g, s => ({
    '&':'&amp;',
    '<':'&lt;',
    '>':'&gt;',
    '"':'&quot;',
    "'":'&#039;'
  }[s]));
}
function rowHTML(r){
  const activo = Number(r.activo)===1;
  const vender = Number(r.vender)===1;
  const nombre = (r.nombre && r.nombre.trim()) ? r.nombre : ('Código '+r.id_producto);
  const cnt = Number(r.codes_count||0);
  return `
    <tr data-id="${escHtml(r.id_producto)}">
      <td>${escHtml(nombre)}<br><small class="text-muted">Código: ${escHtml(r.id_producto)}</small></td>
      <td>
        ${activo
          ? '<button class="btn btn-outline-warning btn-sm btnToggle" data-val="0">Desactivar</button>'
          : '<button class="btn btn-outline-success btn-sm btnToggle" data-val="1">Activar</button>'}
      </td>
      <td>
        <div class="form-check form-switch">
          <input class="form-check-input swVender" type="checkbox" ${vender? 'checked':''} />
          <label class="form-check-label small">${vender? 'Permitida' : 'Bloqueada'}</label>
        </div>
      </td>
      <td class="text-center"><span class="badge bg-secondary-subtle text-dark">${cnt} códigos</span></td>
      <td class="text-center">
        <button class="btn btn-outline-primary btn-sm btnEditCodes">Editar códigos</button>
      </td>
      <td class="text-center">
        <button class="btn btn-outline-danger btn-sm btnRemove">Quitar</button>
      </td>
    </tr>`;
}

// Typeahead (agregar accesorio)
const q = $('#q'), portal = $('#portal'), btnAdd = $('#btnAdd'), selHidden = $('#selIdProducto');
let debounceTimer = null;
function closePortal(){ portal.style.display='none'; }
const searchRow = document.getElementById('searchRow');

function openPortal(){
  const rInput = q.getBoundingClientRect();
  const rRow   = searchRow.getBoundingClientRect();
  portal.style.minWidth = rInput.width + 'px';
  portal.style.left = (rInput.left - rRow.left) + 'px';
  portal.style.top  = (rInput.bottom - rRow.top) + 'px';
  portal.style.display = 'block';
}

q.addEventListener('input', ()=>{
  selHidden.value=''; btnAdd.disabled = true;
  const term = q.value.trim();
  clearTimeout(debounceTimer);
  if (term.length < 2){ closePortal(); return; }
  debounceTimer = setTimeout(async ()=>{
    try{
      const res = await jfetch('accesorios_regalo_admin.php?action=search_products&q='+encodeURIComponent(term));
      const items = res.items || [];
      if (items.length===0){
        portal.innerHTML = '<div class="portal-item text-muted">Sin coincidencias</div>';
        openPortal(); return;
      }
      portal.innerHTML = items.map(it => `
        <div class="portal-item" data-id="${escHtml(it.id_producto)}" data-nombre="${escHtml(it.nombre || it.id_producto)}">
          ${escHtml(it.nombre || it.id_producto)}
          <br><small class="text-muted">Código: ${escHtml(it.id_producto)}</small>
        </div>`).join('');
      openPortal();
    }catch(e){
      portal.innerHTML = `<div class="portal-item text-danger">${escHtml(e.message || 'Error en búsqueda')}</div>`;
      openPortal();
    }
  }, 250);
});
document.addEventListener('click', (ev)=>{
  if (portal.contains(ev.target)){
    const it = ev.target.closest('.portal-item[data-id]');
    if (it){
      selHidden.value = it.dataset.id;
      q.value = it.dataset.nombre || it.textContent.trim();
      btnAdd.disabled = false;
      closePortal();
    }
  } else if (ev.target !== q){ closePortal(); }
});
btnAdd.addEventListener('click', async ()=>{
  const idp = (selHidden.value || '').trim();
  if (!idp){ alert('Selecciona un accesorio de la lista.'); return; }
  btnAdd.disabled = true;
  try{
    const fd = new FormData();
    fd.append('action','add_or_activate');
    fd.append('id_producto', idp);
    const res = await jfetch('accesorios_regalo_admin.php', { method:'POST', body: fd });
    if (!res.ok) throw new Error(res.error||'No se pudo agregar');
    q.value=''; selHidden.value=''; await reloadList();
  }catch(e){ alert(e.message); }
  finally{ btnAdd.disabled = false; }
});

// Listado dinámico
const cbSoloActivos = $('#cbSoloActivos');
const tbody = document.querySelector('#tbody');
async function reloadList(){
  try{
    const url = 'accesorios_regalo_admin.php?action=list' + (cbSoloActivos.checked ? '&solo_activos=1' : '');
    const res = await jfetch(url);
    const rows = res.rows || [];
    if (rows.length===0){
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Sin modelos configurados.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(rowHTML).join('');
  }catch(e){ alert('Error cargando listado: '+e.message); }
}
cbSoloActivos.addEventListener('change', reloadList);

// Modal de códigos
let EDIT_ID = '';
const codesModalEl = document.getElementById('codesModal');
let codesModal = null;
const codesText  = document.getElementById('codesText');
const codesCount = document.getElementById('codesCount');
const btnSaveCodes = document.getElementById('btnSaveCodes');
const btnToCSV  = document.getElementById('btnToCSV');
const btnToJSON = document.getElementById('btnToJSON');

function countCodes(txt){
  txt = (txt||'').trim(); if (txt==='') return 0;
  if (/^\s*\[/.test(txt)){
    try{
      const arr = JSON.parse(txt);
      if (Array.isArray(arr)) return arr.filter(x => String(x).trim()!=='').length;
    }catch(_){}
    return 0;
  }
  const parts = txt.replace(/\r/g,'').split(/[\n,]+/).map(s=>s.trim()).filter(Boolean);
  return parts.length;
}
codesText.addEventListener('input', ()=>{ codesCount.textContent = countCodes(codesText.value)+' códigos'; });
btnToCSV.addEventListener('click', ()=>{
  let t = codesText.value || '';
  if (/^\s*\[/.test(t)){
    try{
      const arr = JSON.parse(t);
      if (Array.isArray(arr)){
        const parts = arr.map(x=>String(x).trim()).filter(Boolean);
        codesText.value = parts.join(',');
      }
    }catch(_){}
  } else {
    t = t.replace(/\r/g,'');
    const parts = t.split(/[\n,]+/).map(s=>s.trim()).filter(Boolean);
    codesText.value = parts.join(',');
  }
  codesText.dispatchEvent(new Event('input'));
});
btnToJSON.addEventListener('click', ()=>{
  let t = codesText.value || '';
  if (!/^\s*\[/.test(t)){
    t = t.replace(/\r/g,'');
    const parts = t.split(/[\n,]+/).map(s=>s.trim()).filter(Boolean);
    codesText.value = JSON.stringify(parts);
  }
  codesText.dispatchEvent(new Event('input'));
});

tbody.addEventListener('click', async (e)=>{
  const tr = e.target.closest('tr[data-id]'); if (!tr) return;
  const idp = (tr.dataset.id || '').trim();

  if (e.target.classList.contains('btnToggle')){
    const val = Number(e.target.dataset.val||0);
    const fd = new FormData();
    fd.append('action','toggle');
    fd.append('id_producto', idp);
    fd.append('activo', String(val));
    try{
      const res = await jfetch('accesorios_regalo_admin.php', { method:'POST', body: fd });
      if (!res.ok) throw new Error(res.error||'No se pudo cambiar el estado');
      await reloadList();
    } catch(err){ alert(err.message); }
    return;
  }

  if (e.target.classList.contains('btnRemove')){
    if (!confirm('¿Quitar este modelo de accesorio de la lista de regalo?')) return;
    const fd = new FormData();
    fd.append('action','remove');
    fd.append('id_producto', idp);
    try{
      const res = await jfetch('accesorios_regalo_admin.php', { method:'POST', body: fd });
      if (!res.ok) throw new Error(res.error||'No se pudo quitar');
      await reloadList();
    } catch(err){ alert(err.message); }
    return;
  }

  if (e.target.classList.contains('btnEditCodes')){
    EDIT_ID = idp;
    try{
      const res = await jfetch('accesorios_regalo_admin.php?action=get_codes&id_producto='+encodeURIComponent(idp));
      codesText.value = res.codigos || '';
      codesText.dispatchEvent(new Event('input'));
      if (!codesModal){
        try{ codesModal = new bootstrap.Modal(codesModalEl); }catch(_){}
      }
      if (codesModal && codesModal.show){ codesModal.show(); }
      else { codesModalEl.style.display='block'; codesModalEl.classList.add('show'); }
    }catch(err){ alert('No se pudieron cargar los códigos: '+(err.message||err)); }
  }
});

// Switch VENDER
tbody.addEventListener('change', async (e)=>{
  if (!e.target.classList.contains('swVender')) return;
  const tr = e.target.closest('tr[data-id]'); if (!tr) return;
  const idp = (tr.dataset.id || '').trim();
  const val = e.target.checked ? 1 : 0;
  const fd = new FormData();
  fd.append('action','toggle_vender');
  fd.append('id_producto', idp);
  fd.append('vender', String(val));
  try{
    const res = await jfetch('accesorios_regalo_admin.php', { method:'POST', body: fd });
    if (!res.ok) throw new Error(res.error||'No se pudo actualizar el permiso de venta');
    const lbl = tr.querySelector('.swVender + .form-check-label');
    if (lbl) lbl.textContent = val ? 'Permitida' : 'Bloqueada';
  }catch(err){
    alert(err.message);
    e.target.checked = !e.target.checked;
  }
});

// Guardar códigos
btnSaveCodes.addEventListener('click', async ()=>{
  if (!EDIT_ID){ alert('Modelo no válido'); return; }
  const fd = new FormData();
  fd.append('action','save_codes');
  fd.append('id_producto', EDIT_ID);
  fd.append('codigos', (codesText.value || ''));
  btnSaveCodes.disabled = true;
  try{
    const res = await jfetch('accesorios_regalo_admin.php', { method:'POST', body: fd });
    if (!res.ok) throw new Error(res.error||'No se pudieron guardar los códigos');
    if (window.bootstrap){
      const m = bootstrap.Modal.getInstance(codesModalEl);
      if (m) m.hide();
    }
    codesModalEl.classList.remove('show');
    codesModalEl.style.display='none';
    await reloadList();
  }catch(err){ alert(err.message); }
  finally{ btnSaveCodes.disabled = false; }
});

// Carga inicial
setTimeout(reloadList, 0);
</script>
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>