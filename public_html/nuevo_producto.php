<?php
// producto_nuevo_individual.php — con validación Luhn de IMEI (front + back)
session_start();
$ROL = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id_usuario']) || !in_array($ROL, ['Admin','Logistica'], true)) {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';

// ---------- Helpers ----------
function esc($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function norm($s){ return trim((string)$s); }
function n2($v){ return number_format((float)$v, 2, '.', ''); }

/** Luhn para IMEI de 15 dígitos */
function is_valid_imei_luhn(string $s): bool {
  $s = preg_replace('/\D+/', '', $s ?? '');
  if (strlen($s) !== 15) return false;
  $sum = 0;
  // Recorre de derecha a izquierda; duplica cada segundo dígito (Luhn)
  for ($i = 0; $i < 15; $i++) {
    $digit = (int)$s[14 - $i];
    if ($i % 2 === 1) { // posiciones pares desde la derecha (segundo, cuarto, etc.)
      $digit *= 2;
      if ($digit > 9) $digit -= 9;
    }
    $sum += $digit;
  }
  return $sum % 10 === 0;
}

/** Lee valores ENUM desde INFORMATION_SCHEMA */
function getEnumOptions(mysqli $conn, string $table, string $column): array {
  $sql = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $stmt = $conn->prepare($sql);
  if (!$stmt) return [];
  $stmt->bind_param("ss", $table, $column);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  if ($res) $res->free();
  $stmt->close();
  if (!$row) return [];
  $colType = $row['COLUMN_TYPE']; // ej: enum('A','B','C')
  if (preg_match("/^enum\\((.*)\\)$/i", $colType, $m)) {
    $parts = str_getcsv($m[1], ',', "'");
    return array_map('trim', $parts);
  }
  return [];
}

/* ============================================================
   ENDPOINT AJAX: buscar en catalogo_modelos por codigo_producto
   ============================================================ */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'modelo') {
  header('Content-Type: application/json; charset=utf-8');

  $codigo = norm($_GET['codigo'] ?? '');
  if ($codigo === '') { echo json_encode(['ok'=>false,'error'=>'Código vacío']); exit; }

  $sql = "SELECT 
            marca, modelo, color, ram, capacidad, codigo_producto, descripcion,
            nombre_comercial, compania, financiera, fecha_lanzamiento, precio_lista,
            tipo_producto, subtipo, gama, ciclo_vida, abc, operador, resurtible
          FROM catalogo_modelos
          WHERE codigo_producto = ?
            AND (activo = 1 OR activo IS NULL)
          LIMIT 1";

  if ($st = $conn->prepare($sql)) {
    $st->bind_param('s', $codigo);
    $st->execute();

    $res = @($st->get_result());
    if ($res !== null && $res !== false) {
      $row = $res->fetch_assoc();
      $res->free();
      $st->close();
    } else {
      $st->store_result();
      $row = null;
      $st->bind_result(
        $marca, $modelo, $color, $ram, $capacidad, $codigo_producto, $descripcion,
        $nombre_comercial, $compania, $financiera, $fecha_lanzamiento, $precio_lista,
        $tipo_producto, $subtipo, $gama, $ciclo_vida, $abc, $operador, $resurtible
      );
      if ($st->num_rows > 0 && $st->fetch()) {
        $row = [
          'marca'=>$marca,'modelo'=>$modelo,'color'=>$color,'ram'=>$ram,'capacidad'=>$capacidad,
          'codigo_producto'=>$codigo_producto,'descripcion'=>$descripcion,'nombre_comercial'=>$nombre_comercial,
          'compania'=>$compania,'financiera'=>$financiera,'fecha_lanzamiento'=>$fecha_lanzamiento,
          'precio_lista'=>$precio_lista,'tipo_producto'=>$tipo_producto,'subtipo'=>$subtipo,
          'gama'=>$gama,'ciclo_vida'=>$ciclo_vida,'abc'=>$abc,'operador'=>$operador,'resurtible'=>$resurtible,
        ];
      }
      $st->free_result();
      $st->close();
    }

    if ($row) {
      $row['precio_lista'] = ($row['precio_lista'] !== null) ? (float)$row['precio_lista'] : null;
      echo json_encode(['ok'=>true,'data'=>$row]); exit;
    } else {
      echo json_encode(['ok'=>false,'error'=>'No se encontró el código en catalogo_modelos']); exit;
    }
  }

  echo json_encode(['ok'=>false,'error'=>'Error al preparar consulta']); exit;
}

// 👇 UI normal
require_once __DIR__ . '/navbar.php';

// ================== UI vars ==================
$mensaje = "";
$alertCls = "info";

// Traer sucursales
$sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre");

// Opciones dinámicas
$optsTipo       = getEnumOptions($conn, 'productos', 'tipo_producto');
$optsGama       = getEnumOptions($conn, 'productos', 'gama');
$optsCicloVida  = getEnumOptions($conn, 'productos', 'ciclo_vida');
$optsResurtible = getEnumOptions($conn, 'productos', 'resurtible');

if (!$optsTipo)       $optsTipo       = ['Equipo','Modem','Accesorio'];
if (!$optsCicloVida)  $optsCicloVida  = ['Nuevo','Línea','Fin de vida'];
if (!$optsResurtible) $optsResurtible = ['Sí','No'];

// ---------- POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // A) Datos básicos de producto
  $codigo_producto = norm($_POST['codigo_producto'] ?? '');
  $marca           = norm($_POST['marca'] ?? '');
  $modelo          = norm($_POST['modelo'] ?? '');
  $color           = norm($_POST['color'] ?? '');
  $ram             = norm($_POST['ram'] ?? '');
  $capacidad       = norm($_POST['capacidad'] ?? '');
  $imei1           = preg_replace('/\D+/', '', $_POST['imei1'] ?? '');
  $imei2           = preg_replace('/\D+/', '', $_POST['imei2'] ?? '');
  $costo           = (float)($_POST['costo'] ?? 0);
  $costo_con_iva   = $_POST['costo_con_iva'] !== '' ? (float)$_POST['costo_con_iva'] : 0.0;
  $precio_lista    = (float)($_POST['precio_lista'] ?? 0);
  $descripcion     = norm($_POST['descripcion'] ?? '');
  $nombre_comercial= norm($_POST['nombre_comercial'] ?? '');
  $compania        = norm($_POST['compania'] ?? '');
  $financiera      = norm($_POST['financiera'] ?? '');
  $fecha_lanz      = norm($_POST['fecha_lanzamiento'] ?? ''); // YYYY-MM-DD
  $tipo_producto   = norm($_POST['tipo_producto'] ?? 'Equipo');
  $subtipo         = norm($_POST['subtipo'] ?? '');
  $gama            = norm($_POST['gama'] ?? '');
  $ciclo_vida      = norm($_POST['ciclo_vida'] ?? '');
  $abc             = norm($_POST['abc'] ?? '');
  $operador        = norm($_POST['operador'] ?? '');
  $resurtible      = norm($_POST['resurtible'] ?? '');
  $proveedor       = norm($_POST['proveedor'] ?? '');

  // B) Sucursal destino
  $id_sucursal     = (int)($_POST['id_sucursal'] ?? 0);

  // C) Reglas rápidas
  if (!$codigo_producto) {
    $slug = strtoupper(preg_replace('/[^A-Z0-9]+/', '', str_replace(' ','', $tipo_producto.'-'.$marca.'-'.$modelo.'-'.$capacidad.'-'.$color)));
    $codigo_producto = substr($slug, 0, 50);
  }
  if ($costo > 0 && $costo_con_iva <= 0) {
    $costo_con_iva = round($costo * 1.16, 2);
  }

  // ===== Validaciones obligatorias mínimas + Luhn =====
  if (!$marca || !$modelo || !$imei1 || $costo <= 0 || $precio_lista <= 0 || $id_sucursal <= 0) {
    $mensaje  = "⚠️ Completa Marca, Modelo, IMEI1, Costo, Precio lista y Sucursal.";
    $alertCls = "warning";
  } elseif (!is_valid_imei_luhn($imei1)) {
    $mensaje  = "❌ IMEI1 inválido. Debe tener 15 dígitos y pasar Luhn.";
    $alertCls = "danger";
  } elseif ($imei2 !== '' && !is_valid_imei_luhn($imei2)) {
    $mensaje  = "❌ IMEI2 inválido. Si lo capturas, debe tener 15 dígitos y pasar Luhn.";
    $alertCls = "danger";
  } else {
    // IMEI duplicado (en imei1 o imei2)
    $stmt = $conn->prepare("SELECT id FROM productos WHERE imei1=? OR imei2=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("ss", $imei1, $imei1);
      $stmt->execute();

      $dup = null;
      $resDup = @($stmt->get_result());
      if ($resDup !== false && $resDup !== null) {
        $dup = $resDup->fetch_assoc();
        $resDup->free();
      } else {
        $stmt->store_result();
        $stmt->bind_result($dummyId);
        if ($stmt->num_rows > 0 && $stmt->fetch()) $dup = ['id'=>$dummyId];
        $stmt->free_result();
      }
      $stmt->close();
    } else {
      $dup = null;
    }

    if ($dup) {
      $mensaje  = "❌ Ya existe un producto con IMEI $imei1.";
      $alertCls = "danger";
    } else {
      // Insert de producto
      $sql = "INSERT INTO productos
        (codigo_producto, marca, modelo, color, ram, capacidad, imei1, imei2, costo, costo_con_iva, proveedor,
         precio_lista, descripcion, nombre_comercial, compania, financiera, fecha_lanzamiento,
         tipo_producto, subtipo, gama, ciclo_vida, abc, operador, resurtible)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

      $stmt = $conn->prepare($sql);
      if ($stmt) {
        $stmt->bind_param(
          "ssssssssddsdssssssssssss",
          $codigo_producto, $marca, $modelo, $color, $ram, $capacidad, $imei1, $imei2, $costo, $costo_con_iva, $proveedor,
          $precio_lista, $descripcion, $nombre_comercial, $compania, $financiera, $fecha_lanz,
          $tipo_producto, $subtipo, $gama, $ciclo_vida, $abc, $operador, $resurtible
        );

        if ($stmt->execute()) {
          $id_producto = $stmt->insert_id;
          $stmt->close();

          // Inventario
          $stmt2 = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, estatus) VALUES (?,?, 'Disponible')");
          if ($stmt2) {
            $stmt2->bind_param("ii", $id_producto, $id_sucursal);
            $stmt2->execute();
            $stmt2->close();
          }

          $mensaje  = "✅ Producto {$marca} {$modelo} registrado y cargado a inventario de la sucursal seleccionada.";
          $alertCls = "success";
          $_POST = []; // limpiar form
        } else {
          $err = esc($conn->error ?: 'Error desconocido');
          $mensaje  = "❌ Error al registrar el producto: $err";
          $alertCls = "danger";
        }
      } else {
        $err = esc($conn->error ?: 'Error al preparar consulta');
        $mensaje  = "❌ Error al registrar el producto: $err";
        $alertCls = "danger";
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Nuevo Producto Individual</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .autofilled { box-shadow: 0 0 0 0.2rem rgba(13,110,253,.15); }
    .is-invalid + .invalid-feedback{ display:block; }
  </style>
</head>
<body class="bg-light">
<div class="container my-4" style="max-width: 980px;">
  <div class="d-flex align-items-center mb-3">
    <h3 class="mb-0">📦 Registrar Producto Individual</h3>
    <span class="ms-2 badge text-bg-secondary">Carga directa a inventario</span>
  </div>
  <p class="text-muted">Escribe el <strong>código de producto</strong> y autocompleta desde <code>catalogo_modelos</code>. Los IMEIs se validan (15 dígitos + Luhn).</p>

  <?php if ($mensaje): ?>
    <div class="alert alert-<?= esc($alertCls) ?> shadow-sm"><?= $mensaje ?></div>
  <?php endif; ?>

  <form method="POST" class="card shadow-sm p-3 p-md-4 bg-white" id="formProducto" novalidate>
    <!-- Identificación -->
    <h5 class="fw-semibold mb-3">Identificación</h5>
    <div class="row g-3 mb-3">
      <div class="col-md-6">
        <label class="form-label">Código de producto</label>
        <div class="input-group">
          <input id="codigo_producto" type="text" name="codigo_producto" maxlength="50" class="form-control"
                 value="<?= esc($_POST['codigo_producto'] ?? '') ?>" placeholder="Ej. SM-A155M-128GG-BLK">
          <button type="button" id="btnLookup" class="btn btn-outline-primary">Autocompletar</button>
        </div>
        <div class="form-text" id="lookupMsg"></div>
        <div class="form-check mt-1">
          <input class="form-check-input" type="checkbox" id="overwriteFields">
          <label class="form-check-label" for="overwriteFields">Sobrescribir campos existentes</label>
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Marca *</label>
        <input type="text" name="marca" class="form-control" required value="<?= esc($_POST['marca'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Modelo *</label>
        <input type="text" name="modelo" class="form-control" required value="<?= esc($_POST['modelo'] ?? '') ?>">
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Color</label>
        <input type="text" name="color" class="form-control" value="<?= esc($_POST['color'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">RAM</label>
        <input type="text" name="ram" class="form-control" value="<?= esc($_POST['ram'] ?? '') ?>" placeholder="Ej. 4GB">
      </div>
      <div class="col-md-4">
        <label class="form-label">Capacidad</label>
        <input type="text" name="capacidad" class="form-control" value="<?= esc($_POST['capacidad'] ?? '') ?>" placeholder="Ej. 128GB">
      </div>
    </div>

    <!-- IMEIs -->
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label">IMEI 1 *</label>
        <input type="text" name="imei1" id="imei1" class="form-control" required
               inputmode="numeric" pattern="\d{15}" maxlength="15"
               value="<?= esc($_POST['imei1'] ?? '') ?>" placeholder="15 dígitos">
        <div class="invalid-feedback">Ingresa 15 dígitos y que sea válido por Luhn.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label">IMEI 2</label>
        <input type="text" name="imei2" id="imei2" class="form-control"
               inputmode="numeric" pattern="\d{15}" maxlength="15"
               value="<?= esc($_POST['imei2'] ?? '') ?>" placeholder="Opcional (15 dígitos)">
        <div class="invalid-feedback">Si lo capturas, deben ser 15 dígitos Luhn-válido.</div>
      </div>
    </div>

    <!-- Económicos -->
    <h5 class="fw-semibold mb-3">Económicos</h5>
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Costo ($) *</label>
        <input type="number" step="0.01" name="costo" class="form-control" required value="<?= esc($_POST['costo'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Costo con IVA ($)</label>
        <input type="number" step="0.01" name="costo_con_iva" class="form-control" value="<?= esc($_POST['costo_con_iva'] ?? '') ?>" placeholder="Se calcula 16% si lo dejas vacío">
      </div>
      <div class="col-md-4">
        <label class="form-label">Precio lista ($) *</label>
        <input type="number" step="0.01" name="precio_lista" class="form-control" required value="<?= esc($_POST['precio_lista'] ?? '') ?>">
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label">Proveedor</label>
        <input type="text" name="proveedor" class="form-control" value="<?= esc($_POST['proveedor'] ?? '') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Nombre comercial</label>
        <input type="text" name="nombre_comercial" class="form-control" value="<?= esc($_POST['nombre_comercial'] ?? '') ?>">
      </div>
    </div>

    <!-- Clasificación -->
    <h5 class="fw-semibold mb-3">Clasificación</h5>
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Tipo de producto *</label>
        <select name="tipo_producto" class="form-select" required>
          <?php
            $valTP = $_POST['tipo_producto'] ?? 'Equipo';
            foreach ($optsTipo as $opt) {
              $sel = ($valTP === $opt) ? 'selected' : '';
              echo "<option value=\"".esc($opt)."\" $sel>".esc($opt)."</option>";
            }
          ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Subtipo</label>
        <input type="text" name="subtipo" class="form-control" value="<?= esc($_POST['subtipo'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Gama</label>
        <select name="gama" class="form-select">
          <option value="">—</option>
          <?php
            $valG = $_POST['gama'] ?? '';
            foreach ($optsGama as $opt) {
              $sel = ($valG === $opt) ? 'selected' : '';
              echo "<option value=\"".esc($opt)."\" $sel>".esc($opt)."</option>";
            }
          ?>
        </select>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Ciclo de vida</label>
        <select name="ciclo_vida" class="form-select">
          <option value="">—</option>
          <?php
            $valCV = $_POST['ciclo_vida'] ?? '';
            foreach ($optsCicloVida as $opt) {
              $sel = ($valCV === $opt) ? 'selected' : '';
              echo "<option value=\"".esc($opt)."\" $sel>".esc($opt)."</option>";
            }
          ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">ABC</label>
        <input type="text" name="abc" maxlength="1" class="form-control" value="<?= esc($_POST['abc'] ?? '') ?>" placeholder="A/B/C">
      </div>
      <div class="col-md-4">
        <label class="form-label">Resurtible</label>
        <select name="resurtible" class="form-select">
          <option value="">—</option>
          <?php
            $valR = $_POST['resurtible'] ?? '';
            foreach ($optsResurtible as $opt) {
              $sel = ($valR === $opt) ? 'selected' : '';
              echo "<option value=\"".esc($opt)."\" $sel>".esc($opt)."</option>";
            }
          ?>
        </select>
      </div>
    </div>

    <!-- Otros -->
    <div class="row g-3 mb-3">
      <div class="col-md-4">
        <label class="form-label">Compañía</label>
        <input type="text" name="compania" class="form-control" value="<?= esc($_POST['compania'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Financiera</label>
        <input type="text" name="financiera" class="form-control" value="<?= esc($_POST['financiera'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Fecha lanzamiento</label>
        <input type="date" name="fecha_lanzamiento" class="form-control" value="<?= esc($_POST['fecha_lanzamiento'] ?? '') ?>">
      </div>
    </div>

    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label">Operador</label>
        <input type="text" name="operador" class="form-control" value="<?= esc($_POST['operador'] ?? '') ?>" placeholder="Solo aplica si se requiere">
      </div>
      <div class="col-md-6">
        <label class="form-label">Descripción</label>
        <textarea name="descripcion" rows="2" class="form-control"><?= esc($_POST['descripcion'] ?? '') ?></textarea>
      </div>
    </div>

    <!-- Sucursal destino -->
    <h5 class="fw-semibold mb-3">Inventario destino</h5>
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <label class="form-label">Sucursal *</label>
        <select name="id_sucursal" class="form-select" required>
          <option value="">Seleccione sucursal…</option>
          <?php
          if ($sucursales && $sucursales->num_rows) {
            $valSuc = (int)($_POST['id_sucursal'] ?? 0);
            while($s = $sucursales->fetch_assoc()){
              $sel = ($valSuc === (int)$s['id']) ? 'selected' : '';
              echo '<option value="'.(int)$s['id'].'" '.$sel.'>'.esc($s['nombre']).'</option>';
            }
          }
          ?>
        </select>
      </div>
    </div>

    <div class="d-flex justify-content-end gap-2">
      <a href="inventario_global.php" class="btn btn-outline-secondary">Volver</a>
      <button class="btn btn-primary">Registrar producto</button>
    </div>
  </form>
</div>

<!-- JS: Autocompletar + Validación Luhn -->
<script>
(function(){
  // ===== Autocompletar desde catalogo_modelos =====
  const $codigo = document.getElementById('codigo_producto');
  const $msg = document.getElementById('lookupMsg');
  const $btn = document.getElementById('btnLookup');
  const $overwrite = document.getElementById('overwriteFields');

  const map = [
    'marca','modelo','color','ram','capacidad','precio_lista','descripcion','nombre_comercial',
    'compania','financiera','fecha_lanzamiento','tipo_producto','subtipo','gama','ciclo_vida',
    'abc','operador','resurtible'
  ];

  function setVal(name, value, overwrite=false){
    const el = document.querySelector(`[name="${name}"]`);
    if (!el) return;
    const isSelect = el.tagName === 'SELECT';
    const current = (el.value || '').trim();
    if (!overwrite && current) return;
    if (isSelect) {
      let found = false;
      const valLower = (value ?? '').toString().toLowerCase();
      Array.from(el.options).forEach(opt=>{
        if (opt.value.toLowerCase() === valLower || opt.text.toLowerCase() === valLower) {
          opt.selected = true; found = true;
        }
      });
      if (!found && value != null && value !== '') el.value = value;
    } else {
      el.value = (value ?? '');
    }
    el.classList.add('autofilled');
  }

  function clearHighlights(){
    document.querySelectorAll('.autofilled').forEach(el=>el.classList.remove('autofilled'));
  }

  async function lookup(){
    clearHighlights();
    const code = ($codigo.value || '').trim();
    if (!code) { $msg.textContent = 'Escribe un código de producto.'; $msg.className = 'form-text text-muted'; return; }
    $msg.textContent = 'Buscando en catálogo…';
    $msg.className = 'form-text text-primary';
    try {
      const res = await fetch(`<?= esc(basename(__FILE__)) ?>?ajax=modelo&codigo=${encodeURIComponent(code)}`, {cache:'no-store'});
      const data = await res.json();
      if (!data.ok) { $msg.textContent = data.error || 'No se encontró el código.'; $msg.className = 'form-text text-danger'; return; }
      const d = data.data || {};
      map.forEach(k => setVal(k, d[k], $overwrite.checked));
      if (!document.querySelector('[name="codigo_producto"]').value && d.codigo_producto) {
        document.querySelector('[name="codigo_producto"]').value = d.codigo_producto;
      }
      const desc = [d.marca, d.modelo, d.capacidad, d.color].filter(Boolean).join(' ');
      $msg.textContent = `Modelo cargado${desc ? ': ' + desc : ''}. Revisa los campos resaltados.`;
      $msg.className = 'form-text text-success';
    } catch (e) {
      console.error(e);
      $msg.textContent = 'Error al consultar el catálogo.';
      $msg.className = 'form-text text-danger';
    }
  }

  $btn?.addEventListener('click', lookup);
  $codigo?.addEventListener('change', lookup);
  $codigo?.addEventListener('blur', (e)=>{ if ((e.target.value||'').trim()) lookup(); });
  $codigo?.addEventListener('keydown', (e)=>{ if (e.key === 'Enter') { e.preventDefault(); lookup(); } });

  // ===== Validación Luhn para IMEI =====
  function luhnIMEI(val){
    const s = (val||'').replace(/\D+/g,'');
    if (s.length !== 15) return false;
    let sum = 0;
    // derecha → izquierda; duplicar cada segundo dígito
    for (let i=0;i<15;i++){
      let d = parseInt(s[14 - i],10);
      if (i % 2 === 1) { d = d*2; if (d>9) d -= 9; }
      sum += d;
    }
    return sum % 10 === 0;
  }

  function attachImeiValidation(id, required){
    const el = document.getElementById(id);
    if (!el) return;
    const validate = ()=>{
      const raw = (el.value||'').trim();
      const digits = raw.replace(/\D+/g,'');
      let ok = true;
      if (required || digits.length>0){
        ok = /^\d{15}$/.test(digits) && luhnIMEI(digits);
      }
      if (!ok){
        el.classList.add('is-invalid');
        el.setCustomValidity('IMEI inválido');
      } else {
        el.classList.remove('is-invalid');
        el.setCustomValidity('');
      }
    };
    el.addEventListener('input', validate);
    el.addEventListener('blur', validate);
    validate();
  }

  attachImeiValidation('imei1', true);
  attachImeiValidation('imei2', false);

  // Evitar submit si hay inválidos
  const form = document.getElementById('formProducto');
  form?.addEventListener('submit', (e)=>{
    // dispara validación de IMEIs por si no se tocó el input
    ['imei1','imei2'].forEach(id=>{
      const el = document.getElementById(id);
      if (el) el.dispatchEvent(new Event('input'));
    });
    if (!form.checkValidity()){
      e.preventDefault();
      e.stopPropagation();
      form.classList.add('was-validated');
      // scroll al primer inválido
      const bad = form.querySelector('.is-invalid, :invalid');
      if (bad) bad.scrollIntoView({behavior:'smooth',block:'center'});
    }
  });
})();
</script>
</body>
</html>
