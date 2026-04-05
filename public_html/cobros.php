<?php
/* cobros.php — Innovación Móvil + Ticket térmico 80mm + QR verificable (URL)
   - Motivos nuevos:
     * Pago inicial Pospago
     * Enganche Innovacion Movil
     * Pago Innovacion Movil
   - Modal de cliente para Innovación Móvil (nombre/teléfono) obligatorio.
   - Ticket en modal con impresión limpia 80mm.
   - QR apunta a ticket_verificar.php?uid=... (&total,&ts,&sig si hay HMAC).
*/

session_start();

$rol = strtolower(trim((string)($_SESSION['rol'] ?? '')));

$allowed = [
  'ejecutivo',
  'gerente',
  'admin',
  'subdis_admin',
  'subdis_gerente',
  'subdis_ejecutivo',
];

if (!isset($_SESSION['id_usuario']) || !in_array($rol, $allowed, true)) {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/navbar.php';
require_once __DIR__ . '/candado_captura.php';
abortar_si_captura_bloqueada(); // bloquea mutaciones si MODO_CAPTURA=false

$id_usuario   = (int)($_SESSION['id_usuario']  ?? 0);
$id_sucursal  = (int)($_SESSION['id_sucursal'] ?? 0);
$nombre_usr   = trim($_SESSION['nombre'] ?? 'Usuario');

/* ---------- Multi-tenant (Propiedad / Subdis) ---------- */
function column_exists(mysqli $conn, string $table, string $column): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  if (!$stmt = $conn->prepare($sql)) return false;
  $stmt->bind_param('ss', $table, $column);
  $stmt->execute();
  $res = $stmt->get_result();
  $ok = ($res && $res->num_rows > 0);
  $stmt->close();
  return $ok;
}

// bind_param dinámico (evita repetir firmas)
function bind_params(mysqli_stmt $stmt, string $types, array $params): void {
  $refs = [];
  foreach ($params as $k => $v) { $refs[$k] = &$params[$k]; }
  array_unshift($refs, $types);
  call_user_func_array([$stmt, 'bind_param'], $refs);
}

$TENANT_PROPIEDAD = $_SESSION['propiedad'] ?? '';
$TENANT_ID_SUBDIS = (int)($_SESSION['id_subdis'] ?? 0);

// Normaliza propiedad si no viene en sesión
if ($TENANT_PROPIEDAD !== 'LUGA' && $TENANT_PROPIEDAD !== 'SUBDIS') {
  $TENANT_PROPIEDAD = ($TENANT_ID_SUBDIS > 0) ? 'SUBDIS' : 'LUGA';
}
if ($TENANT_PROPIEDAD === 'LUGA') {
  $TENANT_ID_SUBDIS = 0;
}

// Detecta columnas (modo compatible si aún no existen)
$HAS_COBROS_PROPIEDAD = column_exists($conn, 'cobros', 'propiedad');
$HAS_COBROS_SUBDIS    = column_exists($conn, 'cobros', 'id_subdis');

/* ---------- Helpers ---------- */
if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle) {
    return $needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
  }
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function base_origin(){
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $scheme  = $isHttps ? 'https://' : 'http://';
  $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme . $host;
}

function ticket_secret(){
  if (defined('TICKET_SECRET') && TICKET_SECRET) return TICKET_SECRET;
  $env = getenv('TICKET_SECRET');
  return $env && strlen($env) >= 16 ? $env : null;
}

/* Logo robusto: URL absoluta (https), fallbacks locales y cache-busting */
function ticket_logo_src(): ?string {
  $cands = [];
  if (defined('TICKET_LOGO_URL') && TICKET_LOGO_URL) $cands[] = TICKET_LOGO_URL;

  $base = (defined('BASE_URL') && BASE_URL) ? rtrim(BASE_URL, '/') : base_origin();
  // fallbacks locales comunes
  $cands[] = $base . '/static/logo_ticket.png';
  $cands[] = $base . '/assets/logo_ticket.png';
  $cands[] = $base . '/logo.png';

  foreach ($cands as $u) {
    if (!$u) continue;
    // normaliza dobles slashes (no toca el esquema)
    $u = preg_replace('#(^https?://)|/{2,}#', '$1/', $u);
    // data URI → directo
    if (strpos($u, 'data:') === 0) return $u;

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($isHttps && str_starts_with($u, 'http://')) $u = 'https://' . substr($u, 7);

    // cache-busting simple por fecha
    $u .= (strpos($u, '?') !== false ? '&' : '?') . 'v=' . date('Ymd');
    return $u;
  }
  return null;
}

/* ---------- Reglas por sucursal ---------- */
/**
 * Sucursal que solo puede capturar cobros relacionados con Innovación Móvil.
 * (Ajusta el 42 si cambias el ID)
 */
$SUCURSAL_SOLO_INNOVACION = ($id_sucursal === 42);

/**
 * Motivo válido de Innovación Móvil:
 * cualquier texto que contenga "innovacion movil" (case-insensitive).
 */
function es_motivo_innovacion(string $motivo): bool {
  $m = mb_strtolower(trim($motivo), 'UTF-8');
  return $m !== '' && strpos($m, 'innovacion movil') !== false;
}

/* Token anti doble-submit */
if (empty($_SESSION['cobro_token'])) {
  $_SESSION['cobro_token'] = bin2hex(random_bytes(16));
}

/* Nombre sucursal */
$nombre_sucursal = "Sucursal #$id_sucursal";
try {
  $stmtSuc = $conn->prepare("SELECT nombre FROM sucursales WHERE id = ? LIMIT 1");
  $stmtSuc->bind_param("i", $id_sucursal);
  $stmtSuc->execute();
  $stmtSuc->bind_result($tmpNombre);
  if ($stmtSuc->fetch() && !empty($tmpNombre)) {
    $nombre_sucursal = $tmpNombre;
  }
  $stmtSuc->close();
} catch (Throwable $e) {}

/* Datos empresa (ajusta a tu razón social real) */
$empresa = [
  'nombre'   => 'Luga PH S.A. de C.V.',
  'rfc'      => 'LUGA123456XXX',
  'direccion' => 'Calle Empresa 123, CDMX',
  'telefono' => '55-0000-0000',
];

/* ===========================
   Procesar POST
   =========================== */
$msg = '';
$lock = (defined('MODO_CAPTURA') && MODO_CAPTURA === false); // ya no bloqueamos por sucursal completa

$ticket_ready = false;
$ticket = [
  'id' => null,
  'uid' => null,
  'fecha' => null,
  'hora' => null,
  'motivo' => null,
  'tipo_pago' => null,
  'total' => 0.00,
  'efectivo' => 0.00,
  'tarjeta' => 0.00,
  'cliente' => null,
  'telefono' => null,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $posted_token = $_POST['cobro_token'] ?? '';
  if (!hash_equals($_SESSION['cobro_token'] ?? '', $posted_token)) {
    $msg = "<div class='alert alert-warning mb-3'>⚠ Sesión expirada o envío duplicado. Recarga la página e intenta de nuevo.</div>";
  } else {
    $motivo         = trim($_POST['motivo'] ?? '');
    $tipo_pago      = $_POST['tipo_pago'] ?? '';
    $monto_total    = (float)($_POST['monto_total'] ?? 0);
    $monto_efectivo = (float)($_POST['monto_efectivo'] ?? 0);
    $monto_tarjeta  = (float)($_POST['monto_tarjeta'] ?? 0);

    $nombre_cliente   = trim($_POST['nombre_cliente']   ?? '');
    $telefono_cliente = trim($_POST['telefono_cliente'] ?? '');

    // Validación multi-tenant: si es SUBDIS debe existir id_subdis en sesión
    $abortTenant = false;
    if ($TENANT_PROPIEDAD === 'SUBDIS' && $HAS_COBROS_SUBDIS && $TENANT_ID_SUBDIS <= 0) {
      $msg = "<div class='alert alert-danger mb-3 fw-semibold'>❌ Sesión SUBDIS inválida: falta <strong>id_subdis</strong>. Cierra sesión e ingresa de nuevo.</div>";
      $abortTenant = true;
    }

    if (!$abortTenant) {

      // Regla especial: sucursal solo Innovación → solo se permiten motivos Innovación Móvil
      if ($SUCURSAL_SOLO_INNOVACION && !es_motivo_innovacion($motivo)) {
        $msg = "<div class='alert alert-danger mb-3 fw-semibold'>
                  ❌ En tu sucursal solo se pueden registrar cobros de <strong>Innovación Móvil</strong>.
                </div>";
      } else {
        // Redondeo
        $monto_total    = round($monto_total, 2);
        $monto_efectivo = round($monto_efectivo, 2);
        $monto_tarjeta  = round($monto_tarjeta, 2);

        // Normaliza por tipo de pago
        if ($tipo_pago === 'Efectivo') {
          $monto_efectivo = $monto_total;
          $monto_tarjeta  = 0.00;
        } elseif ($tipo_pago === 'Tarjeta') {
          $monto_tarjeta  = $monto_total;
          $monto_efectivo = 0.00;
        } elseif ($tipo_pago !== 'Mixto') {
          $monto_efectivo = 0.00;
          $monto_tarjeta  = 0.00;
        }

        // Comisión especial (solo Abono PayJoy/Krediya, no tarjeta)
        $esAbono = in_array($motivo, ['Abono PayJoy', 'Abono Krediya'], true);
        $comision_especial = ($esAbono && $tipo_pago !== 'Tarjeta') ? 10.00 : 0.00;

        // Validaciones
        if ($motivo === '' || $tipo_pago === '' || $monto_total <= 0) {
          $msg = "<div class='alert alert-warning mb-3'>⚠ Debes llenar todos los campos obligatorios.</div>";
        } else {
          $esInnovacion = es_motivo_innovacion($motivo);
          if ($esInnovacion && ($nombre_cliente === '' || $telefono_cliente === '')) {
            $msg = "<div class='alert alert-warning mb-3'>⚠ Para $motivo debes capturar nombre y teléfono del cliente.</div>";
          } else {
            $valido = false;
            if ($tipo_pago === 'Efectivo' && abs($monto_efectivo - $monto_total) < 0.01) $valido = true;
            if ($tipo_pago === 'Tarjeta'  && abs($monto_tarjeta  - $monto_total) < 0.01) $valido = true;
            if ($tipo_pago === 'Mixto'    && abs(($monto_efectivo + $monto_tarjeta) - $monto_total) < 0.01) $valido = true;

            if (!$valido) {
              $msg = "<div class='alert alert-danger mb-3'>⚠ Los montos no cuadran con el tipo de pago seleccionado.</div>";
            } else {
              $ticket_uid = bin2hex(random_bytes(16)); // 32 chars

              // INSERT compatible (con o sin columnas multi-tenant)
              $cols  = ['id_usuario', 'id_sucursal'];
              $types = 'ii';
              $vals  = [$id_usuario, $id_sucursal];

              if ($HAS_COBROS_PROPIEDAD) {
                $cols[] = 'propiedad';
                $types .= 's';
                $vals[]  = $TENANT_PROPIEDAD;
              }
              // id_subdis solo se inserta si aplica (SUBDIS). Para LUGA dejamos NULL por defecto.
              if ($HAS_COBROS_SUBDIS && $TENANT_PROPIEDAD === 'SUBDIS') {
                $cols[] = 'id_subdis';
                $types .= 'i';
                $vals[]  = $TENANT_ID_SUBDIS;
              }

              $cols = array_merge($cols, [
                'motivo','tipo_pago',
                'monto_total','monto_efectivo','monto_tarjeta','comision_especial',
                'nombre_cliente','telefono_cliente','ticket_uid',
                'fecha_cobro','id_corte','corte_generado'
              ]);

              // placeholders para las columnas con parámetro (sin NOW/NULL/0)
              $phCount = count($vals) + 9; // dinámicas + (motivo,tipo,4 montos,cliente,tel,uid)
              $ph = implode(', ', array_fill(0, $phCount, '?'));

              $sqlIns = "INSERT INTO cobros (" . implode(', ', $cols) . ")
                        VALUES ($ph, NOW(), NULL, 0)";

              $stmt = $conn->prepare($sqlIns);
              if (!$stmt) {
                $msg = "<div class='alert alert-danger mb-3'>❌ Error al preparar INSERT.</div>";
              } else {
                $types .= 'ssddddsss';
                $vals = array_merge($vals, [
                  $motivo,
                  $tipo_pago,
                  $monto_total,
                  $monto_efectivo,
                  $monto_tarjeta,
                  $comision_especial,
                  $nombre_cliente,
                  $telefono_cliente,
                  $ticket_uid
                ]);

                bind_params($stmt, $types, $vals);

                if ($stmt->execute()) {
                  $insert_id = $stmt->insert_id;
                  $msg = "<div class='alert alert-success mb-3'>✅ Cobro #$insert_id registrado correctamente.</div>";
                  $_SESSION['cobro_token'] = bin2hex(random_bytes(16));
                  $_POST = [];

                  if ($esInnovacion) {
                    $ticket_ready = true;
                    $dt = new DateTime('now', new DateTimeZone('America/Mexico_City'));
                    $ticket = [
                      'id'        => $insert_id,
                      'uid'       => $ticket_uid,
                      'fecha'     => $dt->format('d/m/Y'),
                      'hora'      => $dt->format('H:i'),
                      'motivo'    => $motivo,
                      'tipo_pago' => $tipo_pago,
                      'total'     => $monto_total,
                      'efectivo'  => $monto_efectivo,
                      'tarjeta'   => $monto_tarjeta,
                      'cliente'   => $nombre_cliente,
                      'telefono'  => $telefono_cliente,
                    ];
                  }
                } else {
                  $msg = "<div class='alert alert-danger mb-3'>❌ Error al registrar cobro.</div>";
                }
                $stmt->close();
              }
            }
          }
        }
      }
    }
  }
}

/* =========================================================
   Cobros de HOY (sucursal actual)  ✅ FIX: orden de parámetros
   ========================================================= */
$tz = new DateTimeZone('America/Mexico_City');
$inicio = (new DateTime('today', $tz))->format('Y-m-d 00:00:00');
$fin    = (new DateTime('tomorrow', $tz))->format('Y-m-d 00:00:00');

$cobros_hoy = [];
$tot_total = $tot_efectivo = $tot_tarjeta = $tot_comision = 0.0;

try {
  $whereTenant = '';
  $bindTypes   = 'i';
  $bindVals    = [$id_sucursal];

  // Scope por propiedad/subdis (si columnas existen)
  if ($HAS_COBROS_PROPIEDAD) {
    if ($TENANT_PROPIEDAD === 'SUBDIS') {
      $whereTenant .= " AND c.propiedad = 'SUBDIS' ";
      if ($HAS_COBROS_SUBDIS) {
        $whereTenant .= " AND c.id_subdis = ? ";
        $bindTypes   .= 'i';
        $bindVals[]   = $TENANT_ID_SUBDIS; // ✅ ANTES de fechas
      }
    } else { // LUGA
      $whereTenant .= " AND (c.propiedad = 'LUGA' OR c.propiedad IS NULL) ";
      if ($HAS_COBROS_SUBDIS) {
        $whereTenant .= " AND (c.id_subdis IS NULL OR c.id_subdis = 0) ";
      }
    }
  } elseif ($HAS_COBROS_SUBDIS && $TENANT_PROPIEDAD === 'SUBDIS') {
    $whereTenant .= " AND c.id_subdis = ? ";
    $bindTypes   .= 'i';
    $bindVals[]   = $TENANT_ID_SUBDIS; // ✅ ANTES de fechas
  }

  // ✅ Fechas al final, porque en el SQL van al final
  $bindTypes .= 'ss';
  $bindVals[] = $inicio;
  $bindVals[] = $fin;

  $sql = "
    SELECT c.id, c.fecha_cobro, c.motivo, c.tipo_pago,
           c.monto_total, c.monto_efectivo, c.monto_tarjeta, c.comision_especial,
           c.nombre_cliente, c.telefono_cliente,
           u.nombre AS usuario
    FROM cobros c
    JOIN usuarios u ON u.id = c.id_usuario
    WHERE c.id_sucursal = ?
      $whereTenant
      AND c.fecha_cobro >= ? AND c.fecha_cobro < ?
    ORDER BY c.fecha_cobro DESC
    LIMIT 100
  ";

  $stmt = $conn->prepare($sql);
  bind_params($stmt, $bindTypes, $bindVals);
  $stmt->execute();

  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $cobros_hoy[] = $row;
    $tot_total    += (float)$row['monto_total'];
    $tot_efectivo += (float)$row['monto_efectivo'];
    $tot_tarjeta  += (float)$row['monto_tarjeta'];
    $tot_comision += (float)$row['comision_especial'];
  }
  $stmt->close();
} catch (Throwable $e) {
  // opcional debug:
  // error_log("Cobros hoy error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Registrar Cobro</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    .page-hero { background: linear-gradient(135deg, #0b5ed7 0%, #0ea5e9 45%, #22c55e 100%); color: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 8px 24px rgba(2, 6, 23, .15) }
    .card-soft { border: 1px solid rgba(0, 0, 0, .06); border-radius: 16px; box-shadow: 0 8px 24px rgba(2, 6, 23, .06) }
    .label-req::after { content:" *"; color:#ef4444; font-weight:700 }
    .form-help { font-size:.9rem; color:#64748b }
    .summary-row { display:flex; justify-content:space-between; align-items:center; padding:.35rem 0; border-bottom:1px dashed #e2e8f0; font-size:.95rem }
    .summary-row:last-child { border-bottom:0 }
    .currency-prefix { min-width:44px }
    .sticky-actions { position:sticky; bottom:0; background:#fff; padding-top:.5rem; margin-top:1rem; border-top:1px solid #e2e8f0 }
    .table thead th { white-space:nowrap }
    .badge-soft { background:#eef2ff; color:#3730a3 }
    @page { size: 80mm auto; margin: 0; }
    @media print {
      body * { visibility:hidden !important; }
      #ticketModal, #ticketModal * { visibility:visible !important; }
      .modal-backdrop { display:none !important; }
      .modal { position:static !important; }
      .modal-dialog { margin:0 !important; max-width:80mm !important; }
      .modal-content { border:0 !important; box-shadow:none !important; }
      #ticketContent { width:72mm !important; margin:0 auto !important; font-size:12px !important; }
      #ticketContent h6 { font-size:14px !important; }
      .no-print { display:none !important; }
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
    #ticketContent { width:100%; }
    #ticketContent h6 { margin:0; font-size:14px; text-align:center; }
    #ticketContent .line { border-top:1px dashed #999; margin:6px 0; }
    #ticketContent table { width:100%; font-size:12px; border-collapse:collapse; }
    #ticketContent td { padding:2px 0; vertical-align:top; }
  </style>
</head>

<body class="bg-light">
  <div class="container py-4">

    <?php if ($lock): ?>
      <div class="alert alert-warning d-flex align-items-center mb-3" role="alert">
        <i class="bi bi-lock-fill me-2"></i>
        <div><strong>Captura deshabilitada temporalmente.</strong> Podrás registrar cobros cuando el admin lo habilite.</div>
      </div>
    <?php endif; ?>

    <?php if ($SUCURSAL_SOLO_INNOVACION): ?>
      <div class="alert alert-info d-flex align-items-center mb-3" role="alert" style="border-width:2px">
        <i class="bi bi-info-circle fs-3 me-2"></i>
        <div>
          <div class="fw-bold">Para tu sucursal solo está permitida la captura de cobros de Innovación Móvil.</div>
          <div class="small opacity-75">Solo verás habilitados motivos que contengan “Innovacion Movil”.</div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Hero -->
    <div class="page-hero mb-4">
      <div class="d-flex align-items-center">
        <div class="me-3" style="font-size:2rem"><i class="bi bi-cash-coin"></i></div>
        <div>
          <h2 class="h3 mb-0">Registrar Cobro</h2>
          <div class="opacity-75">Captura rápida y validada • <?= h($nombre_sucursal) ?>
            <?php if ($TENANT_PROPIEDAD === 'SUBDIS'): ?>
              <span class="badge rounded-pill bg-dark ms-2">SUBDIS #<?= (int)$TENANT_ID_SUBDIS ?></span>
            <?php else: ?>
              <span class="badge rounded-pill bg-light text-dark ms-2">LUGA</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <?= $msg ?>

    <div class="row g-4">
      <!-- Formulario -->
      <div class="col-12 col-lg-7">
        <form method="POST" class="card card-soft p-3 p-md-4" id="formCobro" novalidate>
          <input type="hidden" name="cobro_token" value="<?= h($_SESSION['cobro_token']) ?>">

          <!-- hidden para datos de cliente (los llena el modal) -->
          <input type="hidden" name="nombre_cliente" id="nombre_cliente_hidden">
          <input type="hidden" name="telefono_cliente" id="telefono_cliente_hidden">

          <!-- Motivo -->
          <div class="mb-3">
            <label class="form-label label-req"><i class="bi bi-clipboard2-check me-1"></i>Motivo del cobro</label>
            <select name="motivo" id="motivo" class="form-select" required>
              <option value="">-- Selecciona --</option>
              <?php
              $motivoSel = $_POST['motivo'] ?? '';
              $motivos = [
                'Enganche',
                'Equipo de contado',
                'Venta SIM',
                'Recarga Tiempo Aire',
                'Abono LesPago',
                'Enganche LesPago',
                'Abono PayJoy',
                'Abono Krediya',
                'Pago inicial Pospago',
                'Enganche Innovacion Movil',
                'Pago Innovacion Movil'
              ];
              foreach ($motivos as $m) {
                $sel = ($motivoSel === $m) ? 'selected' : '';
                echo "<option $sel>" . h($m) . "</option>";
              }
              ?>
            </select>
            <div class="form-help">
              Para <strong>Abono PayJoy/Krediya</strong> se suma comisión especial automática
              <em>(no aplica si el pago es con tarjeta)</em>.
            </div>
          </div>

          <!-- Tipo de pago + total -->
          <div class="mb-3">
            <label class="form-label label-req"><i class="bi bi-credit-card-2-front me-1"></i>Tipo de pago</label>
            <div class="row g-2">
              <div class="col-12 col-sm-6">
                <select name="tipo_pago" id="tipo_pago" class="form-select" required>
                  <?php
                  $tipoSel = $_POST['tipo_pago'] ?? '';
                  $opts = ['' => '-- Selecciona --', 'Efectivo' => 'Efectivo', 'Tarjeta' => 'Tarjeta', 'Mixto' => 'Mixto'];
                  foreach ($opts as $val => $txt) {
                    $sel = ($tipoSel === $val) ? 'selected' : '';
                    echo "<option value='" . h($val) . "' $sel>" . h($txt) . "</option>";
                  }
                  ?>
                </select>
              </div>
              <div class="col-12 col-sm-6">
                <div class="input-group">
                  <span class="input-group-text currency-prefix">$</span>
                  <input type="number" step="0.01" min="0" name="monto_total" id="monto_total"
                    class="form-control" placeholder="0.00" required
                    value="<?= h((string)($_POST['monto_total'] ?? '')) ?>">
                </div>
                <div class="form-help">Monto total del cobro.</div>
              </div>
            </div>
          </div>

          <!-- Campos condicionales -->
          <div class="row g-3">
            <div class="col-12 col-md-6 pago-efectivo d-none">
              <label class="form-label"><i class="bi bi-cash me-1"></i>Monto en efectivo</label>
              <div class="input-group">
                <span class="input-group-text currency-prefix">$</span>
                <input type="number" step="0.01" min="0" name="monto_efectivo" id="monto_efectivo"
                  class="form-control" placeholder="0.00"
                  value="<?= h((string)($_POST['monto_efectivo'] ?? '')) ?>">
              </div>
            </div>

            <div class="col-12 col-md-6 pago-tarjeta d-none">
              <label class="form-label"><i class="bi bi-credit-card me-1"></i>Monto con tarjeta</label>
              <div class="input-group">
                <span class="input-group-text currency-prefix">$</span>
                <input type="number" step="0.01" min="0" name="monto_tarjeta" id="monto_tarjeta"
                  class="form-control" placeholder="0.00"
                  value="<?= h((string)($_POST['monto_tarjeta'] ?? '')) ?>">
              </div>
            </div>
          </div>

          <div class="mt-3 small text-muted">
            Los importes deben cuadrar con el tipo de pago: efectivo = total, tarjeta = total, mixto = efectivo + tarjeta = total.
          </div>

          <div class="sticky-actions">
            <div class="d-grid mt-3">
              <button type="submit" class="btn btn-success btn-lg" id="btnGuardar" <?= $lock ? 'disabled' : '' ?>>
                <i class="bi bi-save me-2"></i>Guardar Cobro
              </button>
            </div>
            <?php if ($lock): ?>
              <div class="text-center text-muted mt-2">
                <i class="bi bi-info-circle me-1"></i>El administrador habilitará la captura pronto.
              </div>
            <?php endif; ?>
          </div>
        </form>
      </div>

      <!-- Resumen -->
      <div class="col-12 col-lg-5">
        <div class="card card-soft p-3 p-md-4">
          <div class="d-flex align-items-center mb-2">
            <i class="bi bi-receipt-cutoff me-2 fs-4"></i>
            <h5 class="mb-0">Resumen del cobro</h5>
          </div>
          <div class="summary-row"><span class="text-muted">Motivo</span><strong id="r_motivo">—</strong></div>
          <div class="summary-row"><span class="text-muted">Tipo de pago</span><strong id="r_tipo">—</strong></div>
          <div class="summary-row"><span class="text-muted">Total</span><strong id="r_total">$0.00</strong></div>
          <div class="summary-row"><span class="text-muted">Efectivo</span><strong id="r_efectivo">$0.00</strong></div>
          <div class="summary-row"><span class="text-muted">Tarjeta</span><strong id="r_tarjeta">$0.00</strong></div>
          <div class="summary-row"><span class="text-muted">Comisión especial</span><strong id="r_comision">$0.00</strong></div>
          <div id="r_status" class="mt-3"></div>
          <div class="mt-3 small text-muted"><i class="bi bi-shield-check me-1"></i>Validación en tiempo real.</div>
        </div>
      </div>
    </div>

    <!-- Tabla cobros de hoy -->
    <div class="card card-soft p-3 p-md-4 mt-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
        <h5 class="mb-2 mb-sm-0">
          Cobros de hoy — <span class="badge badge-soft"><?= h($nombre_sucursal) ?></span>
        </h5>
        <div class="d-flex gap-2">
          <input type="text" id="filtroTabla" class="form-control" placeholder="Buscar en tabla (motivo, usuario, tipo)" />
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle" id="tablaCobros">
          <thead class="table-light">
            <tr>
              <th>Hora</th>
              <th>Usuario</th>
              <th>Motivo</th>
              <th>Tipo de pago</th>
              <th class="text-end">Total</th>
              <th class="text-end">Efectivo</th>
              <th class="text-end">Tarjeta</th>
              <th class="text-end">Comisión</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($cobros_hoy) === 0): ?>
              <tr>
                <td colspan="8" class="text-center text-muted">Sin cobros registrados hoy en esta sucursal.</td>
              </tr>
            <?php else: foreach ($cobros_hoy as $r): ?>
              <tr>
                <td><?= h((new DateTime($r['fecha_cobro']))->format('H:i')) ?></td>
                <td><?= h($r['usuario'] ?? '') ?></td>
                <td>
                  <?= h($r['motivo'] ?? '') ?>
                  <?php if (!empty($r['nombre_cliente'])): ?>
                    <div class="small text-muted">Cliente: <?= h($r['nombre_cliente']) ?> (<?= h($r['telefono_cliente']) ?>)</div>
                  <?php endif; ?>
                </td>
                <td><?= h($r['tipo_pago'] ?? '') ?></td>
                <td class="text-end"><?= number_format((float)$r['monto_total'], 2) ?></td>
                <td class="text-end"><?= number_format((float)$r['monto_efectivo'], 2) ?></td>
                <td class="text-end"><?= number_format((float)$r['monto_tarjeta'], 2) ?></td>
                <td class="text-end"><?= number_format((float)$r['comision_especial'], 2) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
          <?php if (count($cobros_hoy) > 0): ?>
            <tfoot>
              <tr class="fw-semibold">
                <td colspan="4" class="text-end">Totales</td>
                <td class="text-end"><?= number_format($tot_total, 2) ?></td>
                <td class="text-end"><?= number_format($tot_efectivo, 2) ?></td>
                <td class="text-end"><?= number_format($tot_tarjeta, 2) ?></td>
                <td class="text-end"><?= number_format($tot_comision, 2) ?></td>
              </tr>
            </tfoot>
          <?php endif; ?>
        </table>
      </div>
      <div class="small text-muted">Ventana: hoy <?= h((new DateTime('today', $tz))->format('d/m/Y')) ?> — registros más recientes primero (máx. 100).</div>
    </div>

  </div>

  <!-- Modal Datos del Cliente (solo Innovación Móvil) -->
  <div class="modal fade" id="clienteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-person-vcard me-2"></i>Datos del cliente</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Nombre del cliente</label>
            <input type="text" class="form-control" id="nombre_cliente_modal" maxlength="120" placeholder="Nombre y apellidos">
          </div>
          <div class="mb-1">
            <label class="form-label">Teléfono del cliente</label>
            <input type="tel" class="form-control" id="telefono_cliente_modal" maxlength="25" placeholder="10 dígitos">
          </div>
          <div class="small text-muted">Estos datos quedarán ligados al cobro para Innovación Móvil.</div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary" id="btnGuardarCliente"><i class="bi bi-check2-circle me-1"></i>Continuar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Ticket -->
  <div class="modal fade" id="ticketModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:380px">
      <div class="modal-content">
        <div class="modal-header no-print">
          <h5 class="modal-title"><i class="bi bi-receipt me-2"></i>Ticket de cobro</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body" id="ticketContent">
          <?php $logoSrc = ticket_logo_src(); ?>
          <?php if ($logoSrc): ?>
            <div class="logo-wrap" style="text-align:center;margin-bottom:6px;">
              <img
                id="ticketLogo"
                src="<?= h($logoSrc) ?>"
                alt="Logo"
                style="max-width:80px;max-height:60px;object-fit:contain;display:inline-block;"
                crossorigin="anonymous"
                referrerpolicy="no-referrer"
                onerror="this.closest('.logo-wrap')?.remove();"
              >
            </div>
          <?php endif; ?>

          <h6><?= h($empresa['nombre']) ?></h6>
          <div style="text-align:center;font-size:11px">
            <?= h($empresa['direccion']) ?> • Tel: <?= h($empresa['telefono']) ?><br>
            <?= h($nombre_sucursal) ?>
          </div>
          <div class="line"></div>
          <table>
            <tr><td>Folio:</td><td style="text-align:right">#<?= h((string)$ticket['id']) ?></td></tr>
            <tr><td>Fecha:</td><td style="text-align:right"><?= h((string)$ticket['fecha']) ?> <?= h((string)$ticket['hora']) ?></td></tr>
            <tr><td>Atendió:</td><td style="text-align:right"><?= h($nombre_usr) ?></td></tr>
            <tr><td>Motivo:</td><td style="text-align:right"><?= h((string)$ticket['motivo']) ?></td></tr>
            <tr><td>Tipo de pago:</td><td style="text-align:right"><?= h((string)$ticket['tipo_pago']) ?></td></tr>
          </table>

          <?php if (!empty($ticket['cliente'])): ?>
            <div class="line"></div>
            <div><strong>Cliente:</strong> <?= h($ticket['cliente']) ?></div>
            <div><strong>Teléfono:</strong> <?= h($ticket['telefono']) ?></div>
          <?php endif; ?>

          <div class="line"></div>
          <table>
            <tr><td>Total</td><td style="text-align:right">$<?= number_format((float)$ticket['total'], 2) ?></td></tr>
            <tr><td>Efectivo</td><td style="text-align:right">$<?= number_format((float)$ticket['efectivo'], 2) ?></td></tr>
            <tr><td>Tarjeta</td><td style="text-align:right">$<?= number_format((float)$ticket['tarjeta'], 2) ?></td></tr>
          </table>
          <div class="line"></div>
          <div id="qrcode" style="display:flex;justify-content:center;margin-top:6px;"></div>
          <div style="text-align:center;font-size:11px;margin-top:6px">
            <?= h($ticket['uid'] ?? '') ?>
          </div>
          <div style="text-align:center;margin-top:8px">¡Gracias por su preferencia!</div>
        </div>
        <div class="modal-footer no-print">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
          <button class="btn btn-primary" onclick="printTicketClean()">
            <i class="bi bi-printer me-1"></i>Imprimir / PDF (80 mm)
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- JS -->
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <!-- ✅ Bootstrap Bundle (necesario para Modals) -->
  <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
  <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

  <script>
    (function() {
      const $motivo = $("#motivo"),
            $tipo = $("#tipo_pago"),
            $total = $("#monto_total"),
            $efectivo = $("#monto_efectivo"),
            $tarjeta = $("#monto_tarjeta");

      // Flag desde PHP: sucursal solo Innovación
      const sucursalSoloInnovacion = <?= $SUCURSAL_SOLO_INNOVACION ? 'true' : 'false' ?>;

      // Motivos Innovación usados tanto para UI como para modal
      const motivosInnovacion = new Set(['Enganche Innovacion Movil', 'Pago Innovacion Movil']);

      // Si es sucursal solo Innovación, ocultamos opciones que no sean Innovación
      if (sucursalSoloInnovacion) {
        $("#motivo option").each(function() {
          const val = $(this).text().trim();
          if (val && val !== '-- Selecciona --' && !motivosInnovacion.has(val)) {
            $(this).remove();
          }
        });
      }

      function toggleCampos() {
        const t = $tipo.val();
        $(".pago-efectivo, .pago-tarjeta").addClass("d-none");
        if (t === "Efectivo") $(".pago-efectivo").removeClass("d-none");
        if (t === "Tarjeta") $(".pago-tarjeta").removeClass("d-none");
        if (t === "Mixto") $(".pago-efectivo, .pago-tarjeta").removeClass("d-none");

        if (t === "Efectivo") {
          $tarjeta.prop("disabled", true).val("");
          $efectivo.prop("disabled", false);
        } else if (t === "Tarjeta") {
          $efectivo.prop("disabled", true).val("");
          $tarjeta.prop("disabled", false);
        } else if (t === "Mixto") {
          $efectivo.prop("disabled", false);
          $tarjeta.prop("disabled", false);
        } else {
          $efectivo.prop("disabled", true).val("");
          $tarjeta.prop("disabled", true).val("");
        }
        validar();
      }

      function comisionEspecial(m, t) {
        return ((m === "Abono PayJoy" || m === "Abono Krediya") && t !== "Tarjeta") ? 10 : 0;
      }

      const fmt = n => "$" + (isFinite(n) ? Number(n) : 0).toFixed(2);

      function validar() {
        const m = ($motivo.val() || "").trim(),
              t = $tipo.val() || "",
              tot = parseFloat($total.val() || 0) || 0,
              ef = parseFloat($efectivo.val() || 0) || 0,
              tj = parseFloat($tarjeta.val() || 0) || 0,
              com = comisionEspecial(m, t);

        $("#r_motivo").text(m || "—");
        $("#r_tipo").text(t || "—");
        $("#r_total").text(fmt(tot));
        $("#r_efectivo").text(fmt(ef));
        $("#r_tarjeta").text(fmt(tj));
        $("#r_comision").text(fmt(com));

        let ok = false;
        if (t === "Efectivo") ok = Math.abs(ef - tot) < 0.01;
        if (t === "Tarjeta") ok = Math.abs(tj - tot) < 0.01;
        if (t === "Mixto") ok = Math.abs((ef + tj) - tot) < 0.01;

        const $s = $("#r_status");
        if (!t || tot <= 0) {
          $s.html(`<div class="alert alert-secondary py-2 mb-0"><i class="bi bi-info-circle me-1"></i>Completa el tipo de pago y el total.</div>`);
          return;
        }
        $s.html(ok
          ? `<div class="alert alert-success py-2 mb-0"><i class="bi bi-check-circle me-1"></i>Montos correctos.</div>`
          : `<div class="alert alert-warning py-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Los montos no cuadran.</div>`);
      }

      $("#tipo_pago").on("change", toggleCampos);
      $("#motivo, #monto_total, #monto_efectivo, #monto_tarjeta").on("input change", validar);
      $("#motivo").trigger("focus");
      toggleCampos(); validar();

      // Filtro tabla
      $("#filtroTabla").on("input", function(){
        const q = $(this).val().toLowerCase();
        $("#tablaCobros tbody tr").each(function(){
          const t = $(this).text().toLowerCase();
          $(this).toggle(t.indexOf(q) !== -1);
        });
      });

      // Modal previo (Innovación Móvil)
      document.getElementById('btnGuardar').addEventListener('click', function(ev){
        const motivo = ($motivo.val() || '').trim();
        if (motivosInnovacion.has(motivo)) {
          const haveHidden = (document.getElementById('nombre_cliente_hidden').value.trim() !== '' &&
                              document.getElementById('telefono_cliente_hidden').value.trim() !== '');
          if (!haveHidden) {
            ev.preventDefault();
            ev.stopPropagation();
            new bootstrap.Modal(document.getElementById('clienteModal')).show();
          }
        }
      });

      document.getElementById('btnGuardarCliente').addEventListener('click', function(){
        const n = document.getElementById('nombre_cliente_modal').value.trim();
        const t = document.getElementById('telefono_cliente_modal').value.trim();
        if (n.length < 3) { alert('Nombre del cliente inválido.'); return; }
        if (t.length < 8) { alert('Teléfono del cliente inválido.'); return; }
        document.getElementById('nombre_cliente_hidden').value = n;
        document.getElementById('telefono_cliente_hidden').value = t;
        bootstrap.Modal.getInstance(document.getElementById('clienteModal')).hide();
        document.getElementById('formCobro').submit();
      });

      <?php if ($ticket_ready): ?>
        // Abrir ticket y generar QR como URL de verificación (con HMAC si hay secreto)
        const tModal = new bootstrap.Modal(document.getElementById('ticketModal'));
        tModal.show();
        <?php
          $base = base_origin();
          $uid  = (string)$ticket['uid'];
          $amount = number_format((float)$ticket['total'], 2, '.', '');
          $ts   = time();
          $sec  = ticket_secret();
          if ($sec) {
            $sig = hash_hmac('sha256', $uid . '|' . $amount . '|' . $ts, $sec);
            $verifyUrl = $base . '/ticket_verificar.php?uid=' . urlencode($uid)
              . '&total=' . urlencode($amount)
              . '&ts='    . urlencode((string)$ts)
              . '&sig='   . urlencode($sig);
          } else {
            $verifyUrl = $base . '/ticket_verificar.php?uid=' . urlencode($uid);
          }
        ?>
        new QRCode(document.getElementById("qrcode"), {
          text: <?= json_encode($verifyUrl) ?>,
          width: 120,
          height: 120
        });
      <?php endif; ?>
    })();
  </script>

  <script>
    // Impresión limpia (popup 80mm) y QR como IMG (para que se imprima siempre)
    function printTicketClean() {
      const ticketEl = document.getElementById('ticketContent').cloneNode(true);
      const qrCanvas = document.querySelector('#qrcode canvas');
      if (qrCanvas) {
        const dataURL = qrCanvas.toDataURL('image/png');
        const img = document.createElement('img');
        img.src = dataURL;
        img.style.display = 'block';
        img.style.margin = '6px auto 0';
        const qrHost = ticketEl.querySelector('#qrcode');
        if (qrHost) { qrHost.innerHTML = ''; qrHost.appendChild(img); }
      }
      const css = `
        <style>
          @page { size: 80mm auto; margin: 0; }
          body { margin: 0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; }
          #ticketContent { width: 72mm; margin: 0 auto; font-size: 12px; }
          #ticketContent h6 { margin: 0; font-size: 14px; text-align: center; }
          #ticketContent .line { border-top:1px dashed #999; margin:6px 0; }
          #ticketContent table { width:100%; font-size:12px; border-collapse:collapse; }
          #ticketContent td { padding:2px 0; vertical-align: top; }
          * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        </style>
      `;
      const w = window.open('', 'ticket', 'width=420,height=700');
      w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Ticket</title>${css}</head><body>${ticketEl.outerHTML}</body></html>`);
      w.document.close();
      setTimeout(() => { w.focus(); w.print(); w.close(); }, 200);
    }
  </script>
</body>
</html>