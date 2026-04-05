<?php
/* venta_sim_pospago.php — Venta pospago + alta rápida de SIM (Central LUGA)
 *
 * Reglas de comisiones:
 * - EJECUTIVO (campo `comision`):
 *     Equipos: [1–3499]=75, [3500–5499]=100, [5500+]=150
 *     Módem/MiFi: 50
 *     Combo: 75 fijo
 * - GERENTE (campo `comision`):
 *     Tabla Gerente: [1–3499]=25, [3500–5499]=75, [5500+]=100, Módem=25
 * - `comision_gerente`:
 *     Normal: No combo → tabla Gerente; Combo → 75 fijo.
 *     ⚠️ Ajuste: SI el vendedor es GERENTE, entonces `comision_gerente = 0`.
 *
 * Para POSPAGO BAIT usamos comisiones fijas:
 * - Ejecutivo (si rol != Gerente):
 *     339: Con=220 / Sin=200
 *     289: Con=180 / Sin=150
 *     249: Con=140 / Sin=120
 *     199: Con=120 / Sin=100
 * - Gerente:
 *     339: Con=80 / Sin=70
 *     289: Con=60 / Sin=50
 *     249: Con=40 / Sin=30
 *     199: Con=30 / Sin=20
 */

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__.'/db.php';

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rolUsuario = (string)($_SESSION['rol'] ?? 'Ejecutivo'); // ← usado para comision_ejecutivo=0 si es Gerente
$nombreUser = trim($_SESSION['nombre'] ?? 'Usuario');
$mensaje    = '';
$selSimId   = isset($_GET['sel_sim']) ? (int)$_GET['sel_sim'] : 0; // para preseleccionar tras alta
$flash      = $_GET['msg'] ?? ''; // sim_ok, sim_dup, sim_err

// 🔹 Planes pospago visibles en el selector
$planesPospago = [
  "Plan Bait 199" => 199,
  "Plan Bait 249" => 249,
  "Plan Bait 289" => 289,
  "Plan Bait 339" => 339,
];

/* ===============================
   FUNCIONES AUXILIARES
================================ */
function tipos_mysqli(array $vals): string {
  $t = '';
  foreach ($vals as $v) {
    if (is_int($v))      { $t .= 'i'; }
    elseif (is_float($v)){ $t .= 'd'; }
    else                 { $t .= 's'; }
  }
  return $t;
}
function redir(string $msg, array $extra = []) {
  $qs = array_merge(['msg'=>$msg], $extra);
  $url = basename($_SERVER['PHP_SELF']).'?'.http_build_query($qs);
  header("Location: $url"); exit();
}
/** Comisión EJECUTIVO pospago (0 si rol=Gerente) */
function calcComisionEjecutivoPospago(string $rolUsuario, int $planMonto, string $modalidad): float {
  if (strcasecmp($rolUsuario, 'Gerente') === 0) return 0.0; // gerente no cobra como ejecutivo
  $mod = strtolower($modalidad);
  $con = (strpos($mod,'con') !== false);

  $tabla = [
    339 => ['con'=>220.0, 'sin'=>200.0],
    289 => ['con'=>180.0, 'sin'=>150.0],
    249 => ['con'=>140.0, 'sin'=>120.0],
    199 => ['con'=>120.0, 'sin'=>100.0],
  ];
  if (!isset($tabla[$planMonto])) return 0.0;
  return $con ? $tabla[$planMonto]['con'] : $tabla[$planMonto]['sin'];
}
/** Comisión GERENTE pospago */
function calcComisionGerentePospago(int $planMonto, string $modalidad): float {
  $mod = strtolower($modalidad);
  $con = (strpos($mod,'con') !== false);

  $tabla = [
    339 => ['con'=>80.0, 'sin'=>70.0],
    289 => ['con'=>60.0, 'sin'=>50.0],
    249 => ['con'=>40.0, 'sin'=>30.0],
    199 => ['con'=>30.0, 'sin'=>20.0],
  ];
  if (!isset($tabla[$planMonto])) return 0.0;
  return $con ? $tabla[$planMonto]['con'] : $tabla[$planMonto]['sin'];
}
/** Verifica columna (para detalle opcional, etc.) */
function columnExists(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = '$t'
      AND COLUMN_NAME  = '$c'
    LIMIT 1
  ";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

/* ===============================
   POST: Alta rápida de SIM (no venta)
================================ */
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['accion'] ?? '') === 'alta_sim')) {
  $iccid    = strtoupper(trim($_POST['iccid'] ?? ''));
  $operador = trim($_POST['operador'] ?? '');
  $dn       = trim($_POST['dn'] ?? '');
  $caja_id  = trim($_POST['caja_id'] ?? '');

  // Validaciones
  if (!preg_match('/^\d{19}[A-Z]$/', $iccid)) {
    redir('sim_err', ['e'=>'ICCID inválido. Debe ser 19 dígitos + 1 letra mayúscula (ej. ...1909F).']);
  }
  if (!in_array($operador, ['Bait','AT&T'], true)) {
    redir('sim_err', ['e'=>'Operador inválido. Elige Bait o AT&T.']);
  }
  if ($dn === '' || !preg_match('/^\d{10}$/', $dn)) {
    redir('sim_err', ['e'=>'El DN es obligatorio y debe tener 10 dígitos.']);
  }

  // Duplicado global con nombre de sucursal
  $stmt = $conn->prepare("
    SELECT i.id, i.id_sucursal, i.estatus, s.nombre AS sucursal_nombre
    FROM inventario_sims i
    JOIN sucursales s ON s.id = i.id_sucursal
    WHERE i.iccid=? LIMIT 1
  ");
  $stmt->bind_param('s', $iccid);
  $stmt->execute();
  $dup = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if ($dup) {
    if ((int)$dup['id_sucursal'] === $idSucursal && $dup['estatus'] === 'Disponible') {
      redir('sim_dup', ['sel_sim'=>(int)$dup['id']]);
    }
    $msg = "El ICCID ya existe (ID {$dup['id']}) en la sucursal {$dup['sucursal_nombre']} con estatus {$dup['estatus']}.";
    redir('sim_err', ['e'=>$msg]);
  }

  // Insert en inventario pospago
  $sql = "INSERT INTO inventario_sims (iccid, dn, operador, caja_id, tipo_plan, estatus, id_sucursal)
          VALUES (?,?,?,?, 'Pospago', 'Disponible', ?)";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('ssssi', $iccid, $dn, $operador, $caja_id, $idSucursal);

  try {
    $stmt->execute();
    $newId = (int)$stmt->insert_id;
    $stmt->close();
    redir('sim_ok', ['sel_sim'=>$newId]);
  } catch (mysqli_sql_exception $e) {
    redir('sim_err', ['e'=>'No se pudo guardar: '.$e->getMessage()]);
  }
}

/* ===============================
   POST: Procesar Venta (usa candado)
================================ */
require_once __DIR__ . '/candado_captura.php';
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['accion'] ?? '') === 'venta')) {
  abortar_si_captura_bloqueada();

  $esEsim         = isset($_POST['es_esim']) ? 1 : 0;
  $idSim          = (isset($_POST['id_sim']) && $_POST['id_sim'] !== '') ? (int)$_POST['id_sim'] : 0;
  $plan           = $_POST['plan'] ?? '';
  $modalidad      = $_POST['modalidad'] ?? 'Sin equipo';

  // 🔗 Venta de equipo relacionada: NULL si viene vacío para no romper FK
  $idVentaEquipo  = (isset($_POST['id_venta_equipo']) && $_POST['id_venta_equipo'] !== '')
                      ? (int)$_POST['id_venta_equipo']
                      : null;

  $comentarios    = trim($_POST['comentarios'] ?? '');

  // Cliente desde hidden (patrón nueva_venta)
  $idCliente       = (isset($_POST['id_cliente']) && $_POST['id_cliente'] !== '') ? (int)$_POST['id_cliente'] : 0;
  $nombreCliente   = trim($_POST['nombre_cliente'] ?? '');
  $telefonoCliente = trim($_POST['telefono_cliente'] ?? '');
  // Por compatibilidad, número_cliente puede venir por separado (desde hidden numero_cliente_hidden)
  $numeroCliente   = trim($_POST['numero_cliente'] ?? $telefonoCliente);

  $planesPospago = [
    "Plan Bait 199" => 199,
    "Plan Bait 249" => 249,
    "Plan Bait 289" => 289,
    "Plan Bait 339" => 339,
  ];
  $precioPlan = $planesPospago[$plan] ?? 0;

  if (!$plan || $precioPlan <= 0) {
    $mensaje = '<div class="alert alert-danger">Selecciona un plan válido.</div>';
  }
  // Cliente obligatorio para pospago
  if ($mensaje === '' && (!$idCliente || $nombreCliente === '' || $numeroCliente === '')) {
    $mensaje = '<div class="alert alert-danger">Debes seleccionar un cliente desde la base de datos (nombre y teléfono).</div>';
  }
  if ($mensaje === '' && !preg_match('/^\d{10}$/', $numeroCliente)) {
    $mensaje = '<div class="alert alert-danger">El teléfono del cliente debe tener exactamente 10 dígitos.</div>';
  }
  if ($mensaje === '' && !$esEsim && $idSim <= 0) {
    $mensaje = '<div class="alert alert-danger">Debes seleccionar una SIM física si no es eSIM.</div>';
  }
  if ($mensaje === '' && !$esEsim && $idSim > 0) {
    $sql = "SELECT id FROM inventario_sims
            WHERE id=? AND estatus='Disponible' AND id_sucursal=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $idSim, $idSucursal);
    $stmt->execute();
    $sim = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$sim) {
      $mensaje = '<div class="alert alert-danger">La SIM seleccionada no está disponible en esta sucursal.</div>';
    }
  }

  // Si la modalidad es "Con equipo" puedes forzar que traiga id_venta_equipo (opcional, por si quieres obligarlo):
  /*
  if ($mensaje === '' && $modalidad === 'Con equipo' && !$idVentaEquipo) {
    $mensaje = '<div class="alert alert-danger">Selecciona la venta de equipo relacionada.</div>';
  }
  */

  if ($mensaje === '') {
    // 💰 Comisiones fijas
    $comisionEjecutivo = calcComisionEjecutivoPospago($rolUsuario, (int)$precioPlan, $modalidad);
    $comisionGerente   = calcComisionGerentePospago((int)$precioPlan, $modalidad);

    // Como la columna id_venta_equipo tiene FK a ventas.id, mandamos NULL si no hay relación
    // (asegúrate de que la columna en BD permita NULL).
    if (!$idVentaEquipo) {
      $idVentaEquipo = null;
    }

    $sqlVenta = "INSERT INTO ventas_sims
      (tipo_venta, tipo_sim, comentarios, precio_total,
       comision_ejecutivo, comision_gerente,
       id_usuario, id_sucursal, fecha_venta,
       es_esim, modalidad, id_venta_equipo,
       id_cliente, numero_cliente, nombre_cliente)
      VALUES ('Pospago', 'Bait', ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)";

    $vals = [
      $comentarios,          // s
      $precioPlan,           // d
      $comisionEjecutivo,    // d
      $comisionGerente,      // d
      $idUsuario,            // i
      $idSucursal,           // i
      $esEsim,               // i
      $modalidad,            // s
      $idVentaEquipo,        // i (puede ser NULL)
      $idCliente,            // i
      $numeroCliente,        // s
      $nombreCliente         // s
    ];

    $types = tipos_mysqli($vals);
    $stmt  = $conn->prepare($sqlVenta);
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $idVenta = (int)$stmt->insert_id;
    $stmt->close();

    // Detalle + mover inventario si SIM física
    if (!$esEsim && $idSim > 0) {
      if (columnExists($conn, 'detalle_venta_sims', 'id_venta')) {
        $sqlDetalle = "INSERT INTO detalle_venta_sims (id_venta, id_sim, precio_unitario) VALUES (?,?,?)";
        $stmt = $conn->prepare($sqlDetalle);
        $stmt->bind_param("iid", $idVenta, $idSim, $precioPlan);
        $stmt->execute();
        $stmt->close();
      }
      $sqlUpdate = "UPDATE inventario_sims
                    SET estatus='Vendida', id_usuario_venta=?, fecha_venta=NOW()
                    WHERE id=?";
      $stmt = $conn->prepare($sqlUpdate);
      $stmt->bind_param("ii", $idUsuario, $idSim);
      $stmt->execute();
      $stmt->close();
    }

    $mensaje = '<div class="alert alert-success">✅ Venta pospago registrada. Comisiones — Ejecutivo: $'
      . number_format($comisionEjecutivo,2)
      . ' | Gerente: $' . number_format($comisionGerente,2) . '</div>';
  }
}

/* ===============================
   OBTENER NOMBRE DE SUCURSAL
================================ */
$nomSucursal = '—';
$stmtNS = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
$stmtNS->bind_param("i", $idSucursal);
$stmtNS->execute();
$rowNS = $stmtNS->get_result()->fetch_assoc();
if ($rowNS) { $nomSucursal = $rowNS['nombre']; }
$stmtNS->close();

/* ===============================
   LISTAR SIMs DISPONIBLES
================================ */
$sql = "SELECT id, iccid, caja_id, fecha_ingreso
        FROM inventario_sims
        WHERE estatus='Disponible' AND id_sucursal=?
        ORDER BY fecha_ingreso ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $idSucursal);
$stmt->execute();
$disponiblesRes = $stmt->get_result();
$disponibles = $disponiblesRes->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ===============================
   LISTAR VENTAS DE EQUIPO (HOY)
================================ */
$sqlEquipos = "
  SELECT v.id, v.fecha_venta, v.nombre_cliente,
         p.marca, p.modelo, p.color, dv.imei1
  FROM ventas v
  INNER JOIN (
      SELECT id_venta, MIN(id) AS min_detalle_id
      FROM detalle_venta GROUP BY id_venta
  ) dmin ON dmin.id_venta = v.id
  INNER JOIN detalle_venta dv ON dv.id = dmin.min_detalle_id
  INNER JOIN productos p ON p.id = dv.id_producto
  WHERE v.id_sucursal = ? AND v.id_usuario = ? AND DATE(v.fecha_venta) = CURDATE()
  ORDER BY v.fecha_venta DESC";
$stmt = $conn->prepare($sqlEquipos);
$stmt->bind_param("ii", $idSucursal, $idUsuario);
$stmt->execute();
$ventasEquipos = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Venta SIM Pospago</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<style>
  :root{ --brand:#0d6efd; --brand-100:rgba(13,110,253,.08); }
  body.bg-light{
    background:
      radial-gradient(1200px 400px at 100% -50%, var(--brand-100), transparent),
      radial-gradient(1200px 400px at -10% 120%, rgba(25,135,84,.06), transparent),
      #f8fafc;
  }
  .page-title{font-weight:700; letter-spacing:.3px;}
  .card-elev{border:0; box-shadow:0 10px 24px rgba(2,8,20,0.06), 0 2px 6px rgba(2,8,20,0.05); border-radius:1rem;}
  .section-title{font-size:.95rem; font-weight:700; color:#334155; text-transform:uppercase; letter-spacing:.8px; margin-bottom:.75rem; display:flex; align-items:center; gap:.5rem;}
  .help-text{font-size:.85rem; color:#64748b;}
  .select2-container .select2-selection--single { height: 38px; border-radius:.5rem; }
  .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 36px; }
  .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
  .btn-gradient{background:linear-gradient(90deg,#16a34a,#22c55e); border:0;}
  .btn-gradient:disabled{opacity:.7;}
  .badge-soft{background:#eef2ff; color:#1e40af; border:1px solid #dbeafe;}
  .list-compact{margin:0; padding-left:1rem;} .list-compact li{margin-bottom:.25rem;}
  .cliente-summary-label {
    font-size:.85rem;
    text-transform:uppercase;
    letter-spacing:.08em;
    color:#64748b;
    margin-bottom:.25rem;
  }
  .cliente-summary-main {
    font-weight:600;
    font-size:1.05rem;
    color:#111827;
  }
  .cliente-summary-sub {
    font-size:.9rem;
    color:#6b7280;
  }
</style>

</head>
<body class="bg-light">
<?php include 'navbar.php'; ?>

<div class="container my-4">

  <?php if ($flash === 'sim_ok'): ?>
    <div class="alert alert-success">✅ SIM agregada a tu inventario y preseleccionada.</div>
  <?php elseif ($flash === 'sim_dup'): ?>
    <div class="alert alert-info">ℹ️ Ese ICCID ya existía en tu inventario y quedó seleccionado.</div>
  <?php elseif ($flash === 'sim_err'): ?>
    <div class="alert alert-danger">❌ No se pudo agregar la SIM. <?= htmlspecialchars($_GET['e'] ?? '') ?></div>
  <?php endif; ?>

  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h2 class="page-title mb-1"><i class="bi bi-sim me-2"></i>Venta de SIM Pospago</h2>
      <div class="help-text">Completa los datos, selecciona el <strong>cliente desde la BD</strong> y confirma en el modal antes de enviar.</div>
    </div>
  </div>

  <!-- Contexto de sesión -->
  <div class="mb-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="badge rounded-pill text-bg-primary"><i class="bi bi-person-badge me-1"></i> Usuario: <?= htmlspecialchars($nombreUser) ?></span>
        <span class="badge rounded-pill text-bg-info"><i class="bi bi-shop me-1"></i> Tu sucursal: <?= htmlspecialchars($nomSucursal) ?></span>
        <span class="badge rounded-pill badge-soft"><i class="bi bi-shield-check me-1"></i> Sesión activa</span>
      </div>
    </div>
  </div>

  <?= $mensaje ?>

  <form method="POST" class="card card-elev p-3 mb-4" id="formPospago" novalidate>
    <input type="hidden" name="accion" value="venta">

    <!-- 🔗 Cliente seleccionado (patrón nueva_venta) -->
    <input type="hidden" name="id_cliente" id="id_cliente" value="">
    <input type="hidden" name="nombre_cliente" id="nombre_cliente" value="">
    <input type="hidden" name="telefono_cliente" id="telefono_cliente" value="">
    <!-- Por compatibilidad con campo numero_cliente en BD -->
    <input type="hidden" name="numero_cliente" id="numero_cliente_hidden" value="">
    <input type="hidden" name="correo_cliente" id="correo_cliente" value="">

    <div class="card-body">

      <div class="section-title"><i class="bi bi-collection"></i> Selección de SIM</div>

      <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="es_esim" name="es_esim" onchange="toggleSimSelect()">
        <label class="form-check-label">Es eSIM (no afecta inventario)</label>
      </div>

      <!-- SIM Física -->
      <div class="row g-3 mb-3" id="sim_fisica">
        <div class="col-md-8">
          <label class="form-label">SIM física disponible</label>
          <select name="id_sim" id="id_sim" class="form-select select2-sims">
            <option value="">-- Selecciona SIM --</option>
            <?php foreach($disponibles as $row): ?>
              <option value="<?= (int)$row['id'] ?>"
                      data-iccid="<?= htmlspecialchars($row['iccid']) ?>"
                      <?= ($selSimId && $selSimId==(int)$row['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($row['iccid']) ?> | Caja: <?= htmlspecialchars($row['caja_id']) ?> | Ing: <?= htmlspecialchars($row['fecha_ingreso']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Escribe ICCID, caja o fecha para filtrar.</div>
          <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAltaSim">
              <i class="bi bi-plus-circle me-1"></i> Agregar SIM (no está en inventario)
            </button>
          </div>
        </div>
      </div>

      <hr class="my-4">

      <div class="section-title"><i class="bi bi-receipt"></i> Datos de la venta</div>
      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <label class="form-label">Plan pospago</label>
          <select name="plan" id="plan" class="form-select" onchange="setPrecio()" required>
            <option value="">-- Selecciona plan --</option>
            <?php foreach($planesPospago as $planNombre => $precioP): ?>
              <option value="<?= htmlspecialchars($planNombre) ?>"><?= htmlspecialchars($planNombre) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Precio/Plan</label>
          <input type="number" step="0.01" id="precio" name="precio" class="form-control" readonly>
        </div>
        <div class="col-md-3">
          <label class="form-label">Modalidad</label>
          <select name="modalidad" id="modalidad" class="form-select" onchange="toggleEquipo()" required>
            <option value="Sin equipo">Sin equipo</option>
            <option value="Con equipo">Con equipo</option>
          </select>
        </div>

        <!-- Relacionar venta de equipo (solo del mismo día) -->
        <div class="col-md-4" id="venta_equipo" style="display:none;">
          <label class="form-label">Relacionar venta de equipo (hoy)</label>
          <select name="id_venta_equipo" id="id_venta_equipo" class="form-select select2-ventas">
            <option value="">-- Selecciona venta --</option>
            <?php while($ve = $ventasEquipos->fetch_assoc()): ?>
              <option value="<?= (int)$ve['id'] ?>"
                      data-descrip="#<?= (int)$ve['id'] ?> | <?= htmlspecialchars(($ve['nombre_cliente'] ?? 'N/D').' • '.$ve['marca'].' '.$ve['modelo'].' '.$ve['color'].' • IMEI '.$ve['imei1']) ?>">
                #<?= (int)$ve['id'] ?> | Cliente: <?= htmlspecialchars($ve['nombre_cliente'] ?? 'N/D') ?>
                | Equipo: <?= htmlspecialchars($ve['marca'].' '.$ve['modelo'].' '.$ve['color']) ?>
                | IMEI: <?= htmlspecialchars($ve['imei1']) ?>
                | <?= date('H:i', strtotime($ve['fecha_venta'])) ?>
              </option>
            <?php endwhile; ?>
          </select>
          <div class="form-text">Solo ventas de <b>hoy</b> en tu sucursal.</div>
        </div>
      </div>

      <hr class="my-4">

      <div class="section-title"><i class="bi bi-people"></i> Datos del cliente</div>
      <div class="row g-3 mb-3">
        <div class="col-md-8">
          <div class="border rounded-3 p-3 bg-light">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <div class="cliente-summary-label">Cliente seleccionado</div>
                <div class="cliente-summary-main" id="cliente_resumen_nombre">
                  Ninguno seleccionado
                </div>
                <div class="cliente-summary-sub" id="cliente_resumen_detalle">
                  Usa el botón <strong>Buscar / crear cliente</strong> para seleccionar uno.
                </div>
              </div>
              <div class="text-end">
                <span class="badge rounded-pill text-bg-secondary" id="badge_tipo_cliente">
                  <i class="bi bi-person-dash me-1"></i> Sin cliente
                </span>
              </div>
            </div>
          </div>
        </div>
        <div class="col-md-4 d-flex align-items-center justify-content-md-end">
          <button type="button" class="btn btn-outline-primary w-100" id="btn_open_modal_clientes">
            <i class="bi bi-search me-1"></i> Buscar / crear cliente
          </button>
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-12">
          <label class="form-label">Comentarios</label>
          <input type="text" name="comentarios" id="comentarios" class="form-control">
        </div>
      </div>

    </div>
    <div class="card-footer bg-white border-0 p-3">
      <button type="submit" class="btn btn-gradient text-white w-100 py-2" id="btn_submit">
        <i class="bi bi-check2-circle me-2"></i> Registrar Venta Pospago
      </button>
    </div>
  </form>
</div>

<!-- Modal: Alta rápida de SIM -->
<div class="modal fade" id="modalAltaSim" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-sim me-2 text-primary"></i>Alta de SIM a inventario</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <form method="POST" id="formAltaSim">
        <input type="hidden" name="accion" value="alta_sim">
        <div class="modal-body">
          <div class="alert alert-secondary py-2">
            Se agregará a tu inventario de <b><?= htmlspecialchars($nomSucursal) ?></b> como <b>Disponible</b>.
          </div>

          <div class="mb-3">
            <label class="form-label">ICCID</label>
            <input type="text" name="iccid" id="alta_iccid" class="form-control" placeholder="8952140063250341909F" maxlength="20" required>
            <div class="form-text">Formato: 19 dígitos + 1 letra mayúscula.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Operador</label>
            <select name="operador" id="alta_operador" class="form-select" required>
              <option value="">-- Selecciona --</option>
              <option value="Bait">Bait</option>
              <option value="AT&T">AT&T</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">DN (10 dígitos)</label>
            <input type="text" name="dn" id="alta_dn" class="form-control" placeholder="5512345678" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Caja ID (opcional)</label>
            <input type="text" name="caja_id" id="alta_caja" class="form-control" placeholder="Etiqueta/caja">
          </div>
          <?php if ($flash==='sim_err' && !empty($_GET['e'])): ?>
            <div class="text-danger small mt-2"><?= htmlspecialchars($_GET['e']) ?></div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar y usar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal de clientes: buscar / seleccionar / crear (patrón nueva_venta) -->
<div class="modal fade" id="modalClientes" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title">
          <i class="bi bi-people me-2 text-primary"></i>Buscar o crear cliente
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <!-- Buscador -->
        <div class="mb-3">
          <label class="form-label">Buscar por nombre, teléfono o código de cliente</label>
          <div class="input-group">
            <input type="text" class="form-control" id="cliente_buscar_q" placeholder="Ej. LUCIA, 5587967699 o CL-40-000001">
            <button class="btn btn-primary" type="button" id="btn_buscar_modal">
              <i class="bi bi-search"></i> Buscar
            </button>
          </div>
          <div class="form-text">
            La búsqueda se realiza a nivel <strong>global.</strong>
          </div>
        </div>

        <hr>

        <!-- Resultados -->
        <div class="mb-2 d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Resultados</span>
          <span class="text-muted small" id="lbl_resultados_clientes">Sin buscar aún.</span>
        </div>
        <div class="table-responsive mb-3">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Teléfono</th>
                <th>Correo</th>
                <th>Fecha alta</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="tbody_clientes">
              <!-- JS -->
            </tbody>
          </table>
        </div>

        <hr>

        <!-- Crear nuevo cliente -->
        <div class="mb-2">
          <button class="btn btn-outline-success btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNuevoCliente">
            <i class="bi bi-person-plus me-1"></i> Crear nuevo cliente
          </button>
        </div>
        <div class="collapse" id="collapseNuevoCliente">
          <div class="border rounded-3 p-3 bg-light">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Nombre completo</label>
                <input type="text" class="form-control" id="nuevo_nombre">
              </div>
              <div class="col-md-4">
                <label class="form-label">Teléfono (10 dígitos)</label>
                <input type="text" class="form-control" id="nuevo_telefono">
              </div>
              <div class="col-md-4">
                <label class="form-label">Correo</label>
                <input type="email" class="form-control" id="nuevo_correo">
              </div>
            </div>
            <div class="mt-3 text-end">
              <button type="button" class="btn btn-success" id="btn_guardar_nuevo_cliente">
                <i class="bi bi-check2-circle me-1"></i> Guardar y seleccionar
              </button>
            </div>
            <div class="form-text">
              El cliente se creará en la sucursal actual.
            </div>
          </div>
        </div>
        <div class="small text-danger mt-2" id="nuevo_cliente_error"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
          Cerrar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal de Confirmación de venta -->
<div class="modal fade" id="modalConfirmacion" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-patch-question me-2 text-primary"></i>Confirma la venta pospago</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info mb-3">
          <i class="bi bi-info-circle me-1"></i>
          <strong>Validación de identidad:</strong> confirma el <u>usuario</u>, la <u>sucursal</u> y el <u>cliente</u>.
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="section-title"><i class="bi bi-person-check"></i> Usuario y sucursal</div>
                <ul class="list-compact">
                  <li><strong>Usuario:</strong> <span id="conf_usuario"><?= htmlspecialchars($nombreUser) ?></span></li>
                  <li><strong>Sucursal:</strong> <span id="conf_sucursal"><?= htmlspecialchars($nomSucursal) ?></span></li>
                  <li><strong>Tipo SIM:</strong> <span id="conf_tipo_sim">—</span></li>
                  <li class="d-none" id="li_iccid"><strong>ICCID:</strong> <span id="conf_iccid">—</span></li>
                  <li><strong>Cliente en BD:</strong> <span id="conf_cliente_bd">No</span></li>
                </ul>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="section-title"><i class="bi bi-receipt"></i> Detalle de la venta</div>
                <ul class="list-compact">
                  <li><strong>Plan:</strong> <span id="conf_plan">—</span></li>
                  <li><strong>Precio/Plan:</strong> $<span id="conf_precio">0.00</span></li>
                  <li><strong>Modalidad:</strong> <span id="conf_modalidad">—</span></li>
                  <li class="d-none" id="li_equipo"><strong>Venta de equipo:</strong> <span id="conf_equipo">—</span></li>
                  <li><strong>Cliente:</strong> <span id="conf_cliente">—</span></li>
                  <li><strong>Número:</strong> <span id="conf_numero">—</span></li>
                  <li class="text-muted"><em>Comentarios:</em> <span id="conf_comentarios">—</span></li>
                </ul>
              </div>
            </div>
          </div>
        </div>

        <hr>
        <div class="help-text">
          Si algo está incorrecto, cierra el modal y corrige.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">
          <i class="bi bi-pencil-square me-1"></i> Corregir
        </button>
        <button class="btn btn-primary" id="btn_confirmar_envio">
          <i class="bi bi-send-check me-1"></i> Confirmar y enviar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- Se asume que Bootstrap JS ya está cargado (por navbar u otra parte) -->

<script>
function toggleSimSelect() {
  const isEsim = document.getElementById('es_esim').checked;
  const simWrap = document.getElementById('sim_fisica');
  const simSel  = document.getElementById('id_sim');
  if (isEsim) {
    simWrap.style.display = 'none';
    simSel.removeAttribute('required');
    $('#id_sim').val('').trigger('change');
  } else {
    simWrap.style.display = 'block';
    simSel.setAttribute('required','required');
  }
}
function toggleEquipo() {
  const modalidad = document.getElementById('modalidad').value;
  document.getElementById('venta_equipo').style.display = (modalidad === 'Con equipo') ? 'block' : 'none';
}
function setPrecio() {
  const plan = document.getElementById('plan').value;
  const precios = {"Plan Bait 199":199,"Plan Bait 249":249,"Plan Bait 289":289,"Plan Bait 339":339};
  document.getElementById('precio').value = precios[plan] || 0;
}

$(function(){
  const modalConfirm      = new bootstrap.Modal(document.getElementById('modalConfirmacion'));
  const modalClientes     = new bootstrap.Modal(document.getElementById('modalClientes'));

  const idSucursal = <?= (int)$idSucursal ?>;

  const $form      = $('#formPospago');
  const $esim      = $('#es_esim');
  const $simSel    = $('#id_sim');
  const $plan      = $('#plan');
  const $precio    = $('#precio');
  const $modal     = $('#modalidad');
  const $venta     = $('#id_venta_equipo');
  const $coment    = $('#comentarios');

  const $idCliente       = $('#id_cliente');
  const $nombreCliente   = $('#nombre_cliente');
  const $telCliente      = $('#telefono_cliente');
  const $telNumeroHidden = $('#numero_cliente_hidden');
  const $correoCliente   = $('#correo_cliente');

  const $clienteResumenNombre  = $('#cliente_resumen_nombre');
  const $clienteResumenDetalle = $('#cliente_resumen_detalle');
  const $badgeTipoCliente      = $('#badge_tipo_cliente');

  const $nuevoNombre   = $('#nuevo_nombre');
  const $nuevoTelefono = $('#nuevo_telefono');
  const $nuevoCorreo   = $('#nuevo_correo');
  const $nuevoErr      = $('#nuevo_cliente_error');

  $('.select2-sims').select2({
    placeholder:'-- Selecciona SIM --',
    width:'100%',
    language:{ noResults:()=> 'Sin resultados', searching:()=> 'Buscando…' }
  });
  $('.select2-ventas').select2({
    placeholder:'-- Selecciona venta --',
    width:'100%',
    language:{ noResults:()=> 'Sin resultados', searching:()=> 'Buscando…' }
  });

  <?php if ($selSimId): ?> $('#id_sim').trigger('change'); <?php endif; ?>

  toggleSimSelect();
  toggleEquipo();
  setPrecio();

  // ===== Helpers cliente (patrón nueva_venta) =====
  function limpiarCliente() {
    $idCliente.val('');
    $nombreCliente.val('');
    $telCliente.val('');
    $telNumeroHidden.val('');
    $correoCliente.val('');

    $clienteResumenNombre.text('Ninguno seleccionado');
    $clienteResumenDetalle.html('Usa el botón <strong>Buscar / crear cliente</strong> para seleccionar uno.');
    $badgeTipoCliente
      .removeClass('text-bg-success')
      .addClass('text-bg-secondary')
      .html('<i class="bi bi-person-dash me-1"></i> Sin cliente');
  }

  function setClienteSeleccionado(c) {
    const id   = c.id || '';
    const nom  = c.nombre || '';
    const tel  = c.telefono || '';
    const mail = c.correo || '';
    const cod  = c.codigo_cliente || '';

    $idCliente.val(id);
    $nombreCliente.val(nom);
    $telCliente.val(tel);
    $telNumeroHidden.val(tel); // compatibilidad con numero_cliente
    $correoCliente.val(mail);

    const detParts = [];
    if (tel)  detParts.push('Tel: ' + tel);
    if (cod)  detParts.push('Código: ' + cod);
    if (mail) detParts.push('Correo: ' + mail);

    $clienteResumenNombre.text(nom || '(Sin nombre)');
    $clienteResumenDetalle.text(detParts.join(' · ') || 'Sin más datos.');

    $badgeTipoCliente
      .removeClass('text-bg-secondary')
      .addClass('text-bg-success')
      .html('<i class="bi bi-person-check me-1"></i> Cliente seleccionado');
  }

  // Abrir modal clientes
  $('#btn_open_modal_clientes').on('click', function() {
    $('#cliente_buscar_q').val('');
    $('#tbody_clientes').empty();
    $('#lbl_resultados_clientes').text('Sin buscar aún.');
    $('#collapseNuevoCliente').removeClass('show');
    $nuevoErr.text('');
    modalClientes.show();
  });

  // Buscar clientes en modal
  $('#btn_buscar_modal').on('click', function() {
    const q = $('#cliente_buscar_q').val().trim();
    if (!q) {
      alert('Escribe algo para buscar (nombre, teléfono o código).');
      return;
    }

    $.post('ajax_clientes_buscar_modal.php', {
      q: q,
      id_sucursal: idSucursal
    }, function(res) {
      if (!res || !res.ok) {
        alert(res && res.message ? res.message : 'No se pudo buscar clientes.');
        return;
      }

      const clientes = res.clientes || [];
      const $tbody = $('#tbody_clientes');
      $tbody.empty();

      if (clientes.length === 0) {
        $('#lbl_resultados_clientes').text('Sin resultados. Puedes crear un cliente nuevo.');
        return;
      }

      $('#lbl_resultados_clientes').text('Se encontraron ' + clientes.length + ' cliente(s).');

      clientes.forEach(function(c) {
        const $tr = $('<tr>');
        $tr.append($('<td>').text(c.codigo_cliente || '—'));
        $tr.append($('<td>').text(c.nombre || ''));
        $tr.append($('<td>').text(c.telefono || ''));
        $tr.append($('<td>').text(c.correo || ''));
        $tr.append($('<td>').text(c.fecha_alta || ''));
        const $btnSel = $('<button type="button" class="btn btn-sm btn-primary">')
          .html('<i class="bi bi-check2-circle me-1"></i> Seleccionar')
          .data('cliente', c)
          .on('click', function() {
            const cliente = $(this).data('cliente');
            setClienteSeleccionado(cliente);
            modalClientes.hide();
          });
        $tr.append($('<td>').append($btnSel));
        $tbody.append($tr);
      });
    }, 'json').fail(function() {
      alert('Error al buscar en la base de clientes.');
    });
  });

  // Guardar nuevo cliente desde modal
  $('#btn_guardar_nuevo_cliente').on('click', function() {
    const nombre = $nuevoNombre.val().trim();
    let tel      = $nuevoTelefono.val().trim();
    const correo = $nuevoCorreo.val().trim();

    $nuevoErr.text('');

    if (!nombre) {
      $nuevoErr.text('Captura el nombre del cliente.');
      return;
    }
    tel = tel.replace(/\D+/g, '');
    if (!/^\d{10}$/.test(tel)) {
      $nuevoErr.text('El teléfono debe tener exactamente 10 dígitos.');
      return;
    }

    $('#btn_guardar_nuevo_cliente').prop('disabled', true).text('Guardando...');

    $.post('ajax_crear_cliente.php', {
      nombre: nombre,
      telefono: tel,
      correo: correo,
      id_sucursal: idSucursal
    }, function(res) {
      if (!res || !res.ok) {
        $nuevoErr.text(res && res.message ? res.message : 'No se pudo guardar el cliente.');
        $('#btn_guardar_nuevo_cliente').prop('disabled', false).text('Guardar y seleccionar');
        return;
      }

      const c = res.cliente || {};
      setClienteSeleccionado(c);
      modalClientes.hide();

      // Limpiar formulario de nuevo cliente
      $nuevoNombre.val('');
      $nuevoTelefono.val('');
      $nuevoCorreo.val('');
      $('#collapseNuevoCliente').removeClass('show');
      $('#btn_guardar_nuevo_cliente').prop('disabled', false).text('Guardar y seleccionar');

      alert(res.message || 'Cliente creado y vinculado.');
    }, 'json').fail(function(xhr) {
      $nuevoErr.text('Error al guardar el cliente: ' + (xhr.responseText || 'desconocido'));
      $('#btn_guardar_nuevo_cliente').prop('disabled', false).text('Guardar y seleccionar');
    });
  });

  // ===== Validación de formulario + modal de confirmación =====
  function validar(){
    const errs = [];
    const plan = $plan.val();
    const precio = parseFloat($precio.val());
    if (!plan) errs.push('Selecciona un plan.');
    if (isNaN(precio) || precio <= 0) errs.push('El precio/plan es inválido o 0.');

    // Cliente obligatorio
    const idCli = ($idCliente.val() || '').trim();
    const nomCli = ($nombreCliente.val() || '').trim();
    const telCli = ($telCliente.val() || '').trim();
    if (!idCli) errs.push('Debes seleccionar un cliente desde la base de datos.');
    if (!nomCli) errs.push('El cliente debe tener nombre.');
    if (!telCli) errs.push('El cliente debe tener teléfono.');
    if (telCli && !/^\d{10}$/.test(telCli)) errs.push('El teléfono del cliente debe tener 10 dígitos.');

    const isEsim = $esim.is(':checked');
    if (!isEsim) {
      const simVal = ($simSel.val() || '').toString().trim();
      if (!simVal) errs.push('Debes seleccionar una SIM física (no es eSIM).');
    }
    return errs;
  }

  function poblarModal(){
    const isEsim = $esim.is(':checked');
    $('#conf_tipo_sim').text(isEsim ? 'eSIM' : 'Física');
    if (!isEsim && $simSel.val()) {
      const iccid = $simSel.find(':selected').data('iccid') || '';
      $('#conf_iccid').text(iccid || '—');
      $('#li_iccid').removeClass('d-none');
    } else { $('#li_iccid').addClass('d-none'); }

    const planTxt = $plan.find(':selected').text() || '—';
    const precio  = parseFloat($precio.val()) || 0;
    $('#conf_plan').text(planTxt);
    $('#conf_precio').text(precio.toFixed(2));
    const modTxt = $modal.val() || '—';
    $('#conf_modalidad').text(modTxt);

    if (modTxt === 'Con equipo' && $venta.val()) {
      const descr = $venta.find(':selected').data('descrip') || ('#'+$venta.val());
      $('#conf_equipo').text(descr); $('#li_equipo').removeClass('d-none');
    } else { $('#li_equipo').addClass('d-none'); }

    const nomCli = $nombreCliente.val() || '—';
    const telCli = $telCliente.val() || '—';
    $('#conf_cliente').text(nomCli);
    $('#conf_numero').text(telCli);
    $('#conf_comentarios').text(($coment.val() || '—'));

    const idCli = ($idCliente.val() || '').trim();
    if (idCli) {
      $('#conf_cliente_bd').text('Sí (#' + idCli + ')');
    } else {
      $('#conf_cliente_bd').text('No');
    }
  }

  let allowSubmit = false;
  $form.on('submit', function(e){
    if (allowSubmit) return;
    e.preventDefault();
    const errs = validar();
    if (errs.length){
      alert('Corrige lo siguiente:\n• ' + errs.join('\n• '));
      return;
    }
    poblarModal();
    modalConfirm.show();
  });

  $('#btn_confirmar_envio').on('click', function(){
    $('#btn_submit').prop('disabled', true).text('Enviando...');
    allowSubmit = true;
    modalConfirm.hide();
    $form[0].submit();
  });

  // De inicio, sin cliente seleccionado
  limpiarCliente();
});
</script>
</body>
</html>
