<?php
session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';

$mensaje = '';

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function subtipoDesdePropiedad(string $propiedad): string {
  $p = trim($propiedad);
  if ($p === 'Luga') return 'Propia';
  if ($p === 'Subdistribuidor') return 'Subdistribuidor';
  if ($p === 'Master Admin') return 'Master Admin';
  return 'Propia';
}

/* =========================
   Subdistribuidores activos
   Tabla: subdistribuidores
   Campos: id, nombre_comercial, estatus, created_at
========================= */
$subdis = [];
$res = $conn->query("
  SELECT id, nombre_comercial
  FROM subdistribuidores
  WHERE estatus = 'Activo'
  ORDER BY nombre_comercial
");
if ($res) {
  $subdis = $res->fetch_all(MYSQLI_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $nombre        = trim($_POST['nombre'] ?? '');
  $tipo_sucursal = trim($_POST['tipo_sucursal'] ?? '');  // Tienda | Almacen
  $propiedad     = trim($_POST['propiedad'] ?? 'Luga');  // Luga | Subdistribuidor | Master Admin
  $cuota_semanal = isset($_POST['cuota_semanal']) ? (float)$_POST['cuota_semanal'] : 0.0;

  // Zona llega solo si aplica
  $zona = isset($_POST['zona']) ? trim($_POST['zona']) : '';

  // id_subdis
  $id_subdis = null;
  if (isset($_POST['id_subdis']) && $_POST['id_subdis'] !== '') {
    $id_subdis = (int)$_POST['id_subdis'];
    if ($id_subdis <= 0) $id_subdis = null;
  }

  // Normalizar acentos
  if ($tipo_sucursal === 'Almacén') $tipo_sucursal = 'Almacen';

  // Reglas base
  $esTienda  = ($tipo_sucursal === 'Tienda');
  $esAlmacen = ($tipo_sucursal === 'Almacen');

  // Zona SOLO para Tienda Luga
  $requiereZona = ($esTienda && $propiedad === 'Luga');
  if (!$requiereZona) {
    $zona = null; // se guarda NULL en BD
  }

  // Almacén: cuota forzada a 0
  if ($esAlmacen) {
    $cuota_semanal = 0.0;
  }

  // Subtipo derivado
  $subtipo = subtipoDesdePropiedad($propiedad);

  // Subdis obligatorio si propiedad != Luga (sea tienda o almacén)
  $requiereSubdis = ($propiedad !== 'Luga');
  if (!$requiereSubdis) {
    $id_subdis = null; // se guarda NULL
  }

  // Validaciones
  $errores = [];
  if ($nombre === '') $errores[] = "El nombre es obligatorio.";
  if (!in_array($tipo_sucursal, ['Tienda','Almacen'], true)) $errores[] = "Tipo de sucursal inválido.";
  if (!in_array($propiedad, ['Luga','Subdistribuidor','Master Admin'], true)) $errores[] = "Propiedad inválida.";
  if ($requiereZona && (!$zona || $zona === '')) $errores[] = "La zona es obligatoria para Tiendas de Luga.";
  if ($requiereSubdis && $id_subdis === null) $errores[] = "Selecciona el subdistribuidor (id_subdis).";

  if (count($errores) > 0) {
    $mensaje = "<div class='alert alert-danger'><b>❌ No se pudo guardar</b><br>"
      . implode("<br>", array_map('h', $errores)) . "</div>";
  } else {

    /**
     * Insert seguro con NULLs literales cuando aplique
     * Columnas esperadas en sucursales:
     * nombre, zona, propiedad, id_subdis, cuota_semanal, tipo_sucursal, subtipo
     */
    $cols   = ['nombre', 'zona', 'propiedad', 'id_subdis', 'cuota_semanal', 'tipo_sucursal', 'subtipo'];
    $vals   = [];
    $types  = '';
    $params = [];

    // nombre
    $vals[]  = '?'; $types .= 's'; $params[] = $nombre;

    // zona (NULL o ?)
    if ($zona === null) {
      $vals[] = 'NULL';
    } else {
      $vals[]  = '?'; $types .= 's'; $params[] = $zona;
    }

    // propiedad
    $vals[]  = '?'; $types .= 's'; $params[] = $propiedad;

    // id_subdis (NULL o ?)
    if ($id_subdis === null) {
      $vals[] = 'NULL';
    } else {
      $vals[]  = '?'; $types .= 'i'; $params[] = $id_subdis;
    }

    // cuota
    $vals[]  = '?'; $types .= 'd'; $params[] = $cuota_semanal;

    // tipo_sucursal
    $vals[]  = '?'; $types .= 's'; $params[] = $tipo_sucursal;

    // subtipo
    $vals[]  = '?'; $types .= 's'; $params[] = $subtipo;

    $sql = "INSERT INTO sucursales (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
      $mensaje = "<div class='alert alert-danger'>❌ Error preparando SQL: ".h($conn->error)."</div>";
    } else {

      if ($types !== '') {
        // bind_param requiere referencias
        $bind = [];
        $bind[] = $types;
        for ($i = 0; $i < count($params); $i++) $bind[] = &$params[$i];
        call_user_func_array([$stmt, 'bind_param'], $bind);
      }

      if ($stmt->execute()) {
        $mensaje = "<div class='alert alert-success'>
          ✅ Sucursal <b>".h($nombre)."</b> registrada correctamente.
          <div class='small text-muted mt-1'>
            Tipo: <b>".h($tipo_sucursal)."</b> | Propiedad: <b>".h($propiedad)."</b> | Subtipo: <b>".h($subtipo)."</b>"
            . ($zona === null ? " | Zona: <b>NULL</b>" : " | Zona: <b>".h($zona)."</b>")
            . ($id_subdis === null ? " | Subdis: <b>NULL</b>" : " | Subdis ID: <b>".(int)$id_subdis."</b>")
          ."</div>
        </div>";
      } else {
        $mensaje = "<div class='alert alert-danger'>❌ Error al guardar: ".h($stmt->error)."</div>";
      }
      $stmt->close();
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Alta de Sucursales</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    body{ background:#f6f7fb; }
    .wrap{ max-width: 980px; }
    .card-soft{ border:1px solid rgba(15,23,42,.08); border-radius:16px; }
    .chip{
      display:inline-flex; align-items:center; gap:.5rem;
      padding:.35rem .65rem; border-radius:999px;
      background:rgba(13,110,253,.08); color:#0d6efd; font-weight:600; font-size:.9rem;
    }
    .help{ font-size:.9rem; color:#6b7280; }
    .req::after{ content:" *"; color:#dc3545; font-weight:700; }
    .divider{ height:1px; background:rgba(15,23,42,.08); margin: 1rem 0; }
    .soft-note{ background: #eef6ff; border: 1px solid rgba(13,110,253,.15); }
  </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container py-4 wrap">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
      <div class="chip">🏢 Catálogo</div>
      <h3 class="mt-2 mb-0">Alta de Sucursales</h3>
      <div class="help">Zona solo aplica para <b>Tiendas de Luga</b>.</div>
    </div>
  </div>

  <?= $mensaje ?>

  <form method="POST" class="card card-soft shadow-sm">
    <div class="card-body p-4">
      <div class="row g-3">

        <div class="col-12">
          <label class="form-label req">Nombre</label>
          <input type="text" name="nombre" class="form-control form-control-lg" required placeholder="Ej. Luga Atlacomulco / Almacén FIBRAPAY">
        </div>

        <div class="col-md-4">
          <label class="form-label req">Tipo de Sucursal</label>
          <select name="tipo_sucursal" id="tipo_sucursal" class="form-select" required>
            <option value="">-- Selecciona --</option>
            <option value="Tienda">Tienda</option>
            <option value="Almacen">Almacén</option>
          </select>
          <div class="help mt-1">Almacén fuerza cuota=0. Puede ser Luga o Subdis.</div>
        </div>

        <div class="col-md-4">
          <label class="form-label req">Propiedad</label>
          <select name="propiedad" id="propiedad" class="form-select" required>
            <option value="Luga">Luga (Propia)</option>
            <option value="Subdistribuidor">Subdistribuidor</option>
            <option value="Master Admin">Master Admin</option>
          </select>
          
        </div>

        <div class="col-md-4">
          <label class="form-label req">Cuota Semanal ($)</label>
          <input type="number" name="cuota_semanal" id="cuota_semanal" class="form-control" value="0" min="0" step="0.01">
          <div class="help mt-1">En almacén se bloquea en 0.</div>
        </div>

        <div class="col-12"><div class="divider"></div></div>

        <div class="col-md-6" id="wrap_zona">
          <label class="form-label req">Zona (solo Tienda Luga)</label>
          <select name="zona" id="zona" class="form-select">
            <option value="">-- Selecciona Zona --</option>
            <option value="Zona 1">Zona 1</option>
            <option value="Zona 2">Zona 2</option>
          </select>
          <div class="help mt-1">Para subdis y almacenes se guarda NULL.</div>
        </div>

        <div class="col-md-6" id="wrap_subdis">
          <label class="form-label req">Subdistribuidor</label>
          <select name="id_subdis" id="id_subdis" class="form-select">
            <option value="">-- Selecciona subdistribuidor --</option>
            <?php foreach ($subdis as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre_comercial']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="help mt-1">Obligatorio si Propiedad ≠ Luga.</div>
        </div>

        <div class="col-12">
          <div class="alert soft-note mb-0">
            <b>Reglas:</b>
            <ul class="mb-0">
              <li><b>Zona</b> solo para <b>Tiendas de Luga</b>.</li>
              <li><b>Almacén</b> puede pertenecer a Subdis/Master, pero cuota siempre es <b>0</b>.</li>
              
            </ul>
          </div>
        </div>
            <div></div>
      </div>
    </div>

    <div class="card-footer bg-transparent p-4 pt-0 d-flex gap-2 justify-content-end">
      <a href="sucursales.php" class="btn btn-outline-secondary">Volver</a>
      <button type="submit" class="btn btn-primary btn-lg">Guardar</button>
    </div>
  </form>
</div>

<script>
  const tipo = document.getElementById('tipo_sucursal');
  const propiedad = document.getElementById('propiedad');
  const cuota = document.getElementById('cuota_semanal');

  const wrapZona = document.getElementById('wrap_zona');
  const zona = document.getElementById('zona');

  const wrapSubdis = document.getElementById('wrap_subdis');
  const idSubdis = document.getElementById('id_subdis');

  function aplicarReglasUI() {
    const esAlmacen = (tipo.value === 'Almacen');
    const esTienda  = (tipo.value === 'Tienda');
    const prop = propiedad.value;

    // Cuota: almacén siempre 0
    if (esAlmacen) {
      cuota.value = 0;
      cuota.setAttribute('readonly', 'readonly');
    } else {
      cuota.removeAttribute('readonly');
    }

    // Zona solo para Tienda Luga
    const requiereZona = (esTienda && prop === 'Luga');
    wrapZona.classList.toggle('d-none', !requiereZona);
    if (!requiereZona) {
      zona.value = '';
    }

    // Subdis obligatorio cuando propiedad != Luga
    const requiereSubdis = (prop !== 'Luga');
    wrapSubdis.classList.toggle('d-none', !requiereSubdis);
    if (!requiereSubdis) {
      idSubdis.value = '';
    }
  }

  tipo.addEventListener('change', aplicarReglasUI);
  propiedad.addEventListener('change', aplicarReglasUI);
  aplicarReglasUI();
</script>

</body>
</html>
