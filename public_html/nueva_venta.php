<?php
// nueva_venta.php — Registrar Nueva Venta (Equipos)
// ✅ Bloqueo de PROMOS para Subdis_* (switch en promos_guard.php)
// ✅ Ajustado para permitir cupón en equipo principal y equipo combo

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guard_corte.php';
require_once __DIR__ . '/promos_guard.php';

$id_usuario           = (int)($_SESSION['id_usuario'] ?? 0);
$id_sucursal_usuario  = (int)($_SESSION['id_sucursal'] ?? 0);
$nombre_usuario       = trim($_SESSION['nombre'] ?? 'Usuario');

$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
$isSubdis = (stripos((string)$ROL, 'Subdis_') === 0) || (stripos((string)$ROL, 'subdis_') === 0);

$BLOQUEAR_PROMOS = subdis_bloquea_promos_luga();

$sql_suc = "SELECT id, nombre FROM sucursales ORDER BY nombre";
$sucursales = $conn->query($sql_suc)->fetch_all(MYSQLI_ASSOC);

$mapSuc = [];
foreach ($sucursales as $s) {
  $mapSuc[(int)$s['id']] = $s['nombre'];
}

$SUCURSALES_PRECIO_LIBRE = [];
$editablePrecioInicial = $isSubdis;

list($bloquearInicial, $motivoBloqueoInicial, $ayerCandado) = debe_bloquear_captura($conn, $id_sucursal_usuario);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$mensajeBloqueoInicial = '';
if ($bloquearInicial) {
  $mensajeBloqueoInicial = "<strong>Captura bloqueada.</strong> " . h($motivoBloqueoInicial);
  if (!empty($ayerCandado)) {
    $mensajeBloqueoInicial .= "<div class='small'>Genera el corte de <strong>" . h($ayerCandado) . "</strong> para continuar.</div>";
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Nueva Venta</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico?v=2">

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="ui_luga.css">
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

  <style>
    :root{ --brand:#0d6efd; --brand-100:rgba(13,110,253,.08); }
    body.bg-light{
      background:
        radial-gradient(1200px 400px at 100% -50%, var(--brand-100), transparent),
        radial-gradient(1200px 400px at -10% 120%, rgba(25, 135, 84, .06), transparent),
        #f8fafc;
    }
    #topbar,.navbar-luga{ font-size:16px; }
    @media (max-width:576px){
      #topbar,.navbar-luga{
        font-size:16px;
        --brand-font:1.00em; --nav-font:.95em; --drop-font:.95em;
        --icon-em:1.05em; --pad-y:.44em; --pad-x:.62em;
      }
      #topbar .navbar-brand img,.navbar-luga .navbar-brand img{ width:1.8em; height:1.8em; }
      #topbar .btn-asistencia,.navbar-luga .btn-asistencia{
        font-size:.95em; padding:.5em .9em !important; border-radius:12px;
      }
      #topbar .nav-avatar,#topbar .nav-initials,
      .navbar-luga .nav-avatar,.navbar-luga .nav-initials{ width:2.1em; height:2.1em; }
      #topbar .navbar-toggler,.navbar-luga .navbar-toggler{ padding:.45em .7em; }
    }
    @media (max-width:360px){
      #topbar,.navbar-luga{ font-size:15px; }
    }

    .page-title{ font-weight:700; letter-spacing:.3px; }
    .card-elev{
      border:0;
      box-shadow:0 10px 24px rgba(2,8,20,.06),0 2px 6px rgba(2,8,20,.05);
      border-radius:1rem;
    }
    .section-title{
      font-size:.95rem; font-weight:700; color:#334155;
      text-transform:uppercase; letter-spacing:.8px;
      margin-bottom:.75rem; display:flex; align-items:center; gap:.5rem;
    }
    .section-title .bi{ opacity:.85; }
    .req::after{ content:" *"; color:#dc3545; font-weight:600; }
    .help-text{ font-size:.85rem; color:#64748b; }

    .select2-container--default .select2-selection--single{ height:38px; border-radius:.5rem; }
    .select2-container--default .select2-selection__rendered{ line-height:38px; }
    .select2-container--default .select2-selection__arrow{ height:38px }

    .alert-sucursal{ border-left:4px solid #f59e0b; }
    .btn-gradient{ background:linear-gradient(90deg,#16a34a,#22c55e); border:0; }
    .btn-gradient:disabled{ opacity:.7; }
    .badge-soft{ background:#eef2ff; color:#1e40af; border:1px solid #dbeafe; }

    .list-compact{ margin:0; padding-left:1rem; }
    .list-compact li{ margin-bottom:.25rem; }

    .alert-candado{ border-left:6px solid #dc3545; }

    .cliente-summary-label{
      font-size:.85rem; text-transform:uppercase; letter-spacing:.08em;
      color:#64748b; margin-bottom:.25rem;
    }
    .cliente-summary-main{ font-weight:600; font-size:1.05rem; color:#111827; }
    .cliente-summary-sub{ font-size:.9rem; color:#6b7280; }
    .text-success-soft{ color:#15803d; }

    .select-lock{ background:#e9ecef !important; cursor:not-allowed !important; }
    .select-lock+.select2-container .select2-selection{ background:#e9ecef !important; cursor:not-allowed !important; }

    /* ===== UI Luga: Nueva Venta - polish premium ===== */
    .hero-sale{
      background:
        radial-gradient(800px 260px at 0% 0%, rgba(13,110,253,.11), transparent 60%),
        radial-gradient(650px 220px at 100% 0%, rgba(25,135,84,.08), transparent 60%),
        linear-gradient(135deg, #ffffff, #f8fbff);
      border:1px solid rgba(13,110,253,.08);
      border-radius:1.35rem;
      box-shadow:0 16px 38px rgba(15,23,42,.07),0 2px 8px rgba(15,23,42,.04);
    }
    .sale-pill{
      display:inline-flex; align-items:center; gap:.55rem; padding:.58rem 1rem;
      border-radius:999px; background:rgba(255,255,255,.94);
      box-shadow:0 8px 20px rgba(15,23,42,.05),0 2px 6px rgba(15,23,42,.04);
      font-weight:800; color:#0f172a;
    }
    .mini-stat{
      border:1px solid rgba(148,163,184,.18);
      border-radius:1rem;
      background:#fff;
      padding:1rem 1.05rem;
      height:100%;
      box-shadow:0 8px 20px rgba(15,23,42,.05),0 2px 6px rgba(15,23,42,.04);
    }
    .mini-stat .label{
      color:#64748b; font-size:.78rem; text-transform:uppercase; letter-spacing:.45px; font-weight:800; margin-bottom:.25rem;
    }
    .mini-stat .value{
      color:#0f172a; font-size:1.08rem; font-weight:900; line-height:1.15;
    }
    .card-elev{
      border:0;
      box-shadow:0 10px 24px rgba(2,8,20,.06),0 2px 6px rgba(2,8,20,.05);
      border-radius:1.15rem;
      overflow:hidden;
    }
    .card-elev .card-body{ padding:1.25rem; }
    .card-elev .card-footer{
      background:linear-gradient(180deg, rgba(248,250,252,.92), rgba(255,255,255,1)) !important;
      border-top:1px solid rgba(148,163,184,.12) !important;
    }
    .section-title{
      font-size:.90rem; font-weight:800; color:#334155;
      text-transform:uppercase; letter-spacing:.8px;
      margin-bottom:.85rem; display:flex; align-items:center; gap:.5rem;
    }
    .section-divider{
      height:1px; border:0; background:linear-gradient(90deg, rgba(148,163,184,.24), rgba(148,163,184,.06));
      margin:1.35rem 0;
    }
    .surface-soft{
      background:linear-gradient(180deg, #ffffff, #fbfdff);
      border:1px solid rgba(148,163,184,.14);
      border-radius:1rem;
      box-shadow: inset 0 1px 0 rgba(255,255,255,.8);
    }

    /* Botones con más cariño */
    .btn-luga{
      border-radius: .95rem !important;
      min-height: 46px;
      font-weight: 800;
      letter-spacing: .1px;
      padding: .72rem 1.1rem;
      transition: all .18s ease;
    }
    .btn-luga i{ margin-right:.35rem; }

    .btn-luga-soft-primary{
      background: linear-gradient(180deg, rgba(239,246,255,1), rgba(248,251,255,1));
      border: 1px solid rgba(13,110,253,.24);
      color: #0d6efd;
      box-shadow: 0 8px 18px rgba(13,110,253,.08);
    }
    .btn-luga-soft-primary:hover,
    .btn-luga-soft-primary:focus{
      background: linear-gradient(180deg, rgba(224,242,254,1), rgba(239,246,255,1));
      border-color: rgba(13,110,253,.34);
      color: #0a58ca;
      transform: translateY(-1px);
      box-shadow: 0 12px 22px rgba(13,110,253,.14);
    }

    .btn-luga-ghost{
      background:#fff;
      border:1px solid rgba(148,163,184,.24);
      color:#334155;
      box-shadow:0 6px 16px rgba(15,23,42,.05);
    }
    .btn-luga-ghost:hover,
    .btn-luga-ghost:focus{
      background:#f8fafc;
      border-color:rgba(100,116,139,.30);
      color:#0f172a;
      transform: translateY(-1px);
    }

    .btn-luga-success{
      background: linear-gradient(90deg, #16a34a, #22c55e);
      border: 0;
      color: #fff;
      box-shadow: 0 14px 28px rgba(34,197,94,.22), inset 0 1px 0 rgba(255,255,255,.18);
    }
    .btn-luga-success:hover,
    .btn-luga-success:focus{
      color:#fff;
      filter: brightness(.98);
      transform: translateY(-1px);
      box-shadow: 0 18px 32px rgba(34,197,94,.28), inset 0 1px 0 rgba(255,255,255,.18);
    }

    .btn-luga-warning{
      background: linear-gradient(180deg, rgba(255,248,225,1), rgba(255,243,205,1));
      border: 1px solid rgba(255,193,7,.30);
      color: #7c5a00;
      box-shadow: 0 8px 18px rgba(255,193,7,.12);
    }
    .btn-luga-warning:hover,
    .btn-luga-warning:focus{
      background: linear-gradient(180deg, rgba(255,245,193,1), rgba(255,239,177,1));
      color: #664700;
      transform: translateY(-1px);
    }

    .form-control, .form-select{
      border-radius:.90rem;
      min-height:48px;
      border-color:rgba(148,163,184,.24);
      box-shadow:none;
    }
    .form-control:focus, .form-select:focus{
      border-color:rgba(13,110,253,.38);
      box-shadow:0 0 0 .25rem rgba(13,110,253,.12);
    }

    .select2-container--default .select2-selection--single,
    .select2-container--default .select2-selection--multiple{
      min-height:48px;
      border-radius:.90rem;
      border-color:rgba(148,163,184,.24);
      background:#fff;
      box-shadow:none;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered{
      line-height:46px;
      padding-left:.9rem;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow{
      height:46px;
    }

    .sticky-submit{
      position: sticky;
      bottom: 12px;
      z-index: 4;
    }

    /* Modales */
    .modal-content{
      border:0;
      border-radius:1.35rem;
      box-shadow:0 28px 60px rgba(15,23,42,.14), 0 8px 18px rgba(15,23,42,.08);
      overflow:hidden;
    }
    .modal-header{
      border-bottom:1px solid rgba(148,163,184,.12);
      background:
        radial-gradient(500px 180px at 0% 0%, rgba(13,110,253,.08), transparent 60%),
        linear-gradient(180deg, rgba(248,250,252,.95), rgba(255,255,255,1));
    }
    .modal-title{
      font-weight:900;
      letter-spacing:-.01em;
      color:#0f172a;
    }
    .modal-footer{
      border-top:1px solid rgba(148,163,184,.12);
      background: linear-gradient(180deg, rgba(248,250,252,.92), rgba(255,255,255,1));
    }
    .modal-footer .btn{
      border-radius:.9rem;
      min-height:44px;
      font-weight:800;
      padding:.65rem 1rem;
    }

    .table-soft-wrap{
      border:1px solid rgba(148,163,184,.14);
      border-radius:1rem;
      overflow:hidden;
      background:#fff;
      box-shadow:0 8px 18px rgba(15,23,42,.04);
    }
    .table-soft-wrap .table{ margin-bottom:0; }
    .table-soft-wrap thead th{
      background:#0f172a;
      color:#fff;
      font-weight:800;
      border-bottom:0;
    }

    .badge-soft{
      background:linear-gradient(180deg, rgba(239,246,255,1), rgba(248,251,255,1));
      color:#0d6efd;
      border:1px solid rgba(13,110,253,.16);
    }
  </style>
</head>

<body class="bg-light ui-luga">

<?php include __DIR__ . '/navbar.php'; ?>

<?php
$mensajeError = isset($_GET['err']) ? trim($_GET['err']) : '';
$mensajeOk    = isset($_GET['msg']) ? trim($_GET['msg']) : '';
?>

<div class="container my-4">

  <?php if (!empty($mensajeError)): ?>
    <div class="alert alert-danger rounded-4 shadow-sm"><?= h($mensajeError) ?></div>
  <?php endif; ?>
  <?php if (!empty($mensajeOk)): ?>
    <div class="alert alert-success rounded-4 shadow-sm"><?= h($mensajeOk) ?></div>
  <?php endif; ?>

  <div id="errores" class="alert alert-danger rounded-4 shadow-sm d-none mb-4"></div>

  <div id="banner_candado" class="alert alert-danger alert-candado rounded-4 shadow-sm d-none mb-4">
    <div class="d-flex align-items-start gap-3">
      <div class="fs-3"><i class="bi bi-lock-fill"></i></div>
      <div>
        <div class="fw-bold mb-1">Captura bloqueada por corte pendiente</div>
        <div id="candado_msg">
          Debes generar el corte pendiente para continuar con la captura de ventas.
        </div>
      </div>
    </div>
  </div>

  <div class="hero-sale p-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div>
        <div class="sale-pill mb-3">
          <i class="bi bi-bag-check text-primary"></i>
          <span>Registro de ventas</span>
        </div>
        <h2 class="page-title mb-2">Registrar Nueva Venta</h2>
        <div class="text-muted">Selecciona el tipo de venta, valida cliente y equipos, y confirma antes de enviar.</div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <a href="historial_ventas.php" class="btn btn-luga btn-luga-ghost">
          <i class="bi bi-arrow-left"></i>Ir a Historial
        </a>
      </div>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-md-4">
        <div class="mini-stat">
          <div class="label">Usuario activo</div>
          <div class="value"><?= h($nombre_usuario ?? ($_SESSION['nombre'] ?? 'Usuario')) ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="mini-stat">
          <div class="label">Sucursal base</div>
          <div class="value"><?= h($mapSuc[$id_sucursal_usuario] ?? '—') ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="mini-stat">
          <div class="label">Estado</div>
          <div class="value">Sesión activa</div>
        </div>
      </div>
    </div>
  </div>

  <form method="POST" action="procesar_venta.php" id="form_venta" novalidate data-locked="<?= $bloquearInicial ? '1' : '0' ?>">
    <input type="text" name="username" autocomplete="username" style="display:none">
    <input type="password" name="password" autocomplete="current-password" style="display:none">
    <input type="hidden" name="id_usuario" value="<?= (int)$id_usuario ?>">

    <input type="hidden" name="id_cliente" id="id_cliente" value="">
    <input type="hidden" name="nombre_cliente" id="nombre_cliente" value="">
    <input type="hidden" name="telefono_cliente" id="telefono_cliente" value="">
    <input type="hidden" name="correo_cliente" id="correo_cliente" value="">

    <input type="hidden" name="monto_cupon" id="monto_cupon" value="0">
    <input type="hidden" name="monto_cupon_principal" id="monto_cupon_principal" value="0">
    <input type="hidden" name="monto_cupon_combo" id="monto_cupon_combo" value="0">

    <input type="hidden" name="es_regalo" id="es_regalo" value="0">
    <input type="hidden" name="id_promo_regalo" id="id_promo_regalo" value="">
    <input type="hidden" name="promo_regalo_aplicado" id="promo_regalo_aplicado" value="0">
    <input type="hidden" name="promo_regalo_id" id="promo_regalo_id" value="0">

    <input type="hidden" name="promo_descuento_aplicado" id="promo_descuento_aplicado" value="0">
    <input type="hidden" name="promo_descuento_id" id="promo_descuento_id" value="0">
    <input type="hidden" name="promo_descuento_modo" id="promo_descuento_modo" value="">
    <input type="hidden" name="promo_descuento_tag_origen" id="promo_descuento_tag_origen" value="">
    <input type="hidden" name="promo_descuento_porcentaje" id="promo_descuento_porcentaje" value="0">
    <input type="hidden" name="promo_descuento_doble_venta" id="promo_descuento_doble_venta" value="0">

    <div class="card card-elev mb-4">
      <div class="card-body">

        <div class="section-title"><i class="bi bi-phone"></i> Tipo de venta</div>
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label req">Tipo de Venta</label>
            <select name="tipo_venta" id="tipo_venta" class="form-control" required>
              <option value="">Seleccione...</option>
              <option value="Contado">Contado</option>
              <option value="Financiamiento">Financiamiento</option>
              <option value="Financiamiento+Combo">Financiamiento + Combo</option>
            </select>
          </div>
        </div>

        <hr class="section-divider">

        <div class="section-title"><i class="bi bi-geo-alt"></i> Datos de operación</div>
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label req">Sucursal de la Venta</label>
            <select name="id_sucursal" id="id_sucursal" class="form-control" required>
              <?php foreach ($sucursales as $sucursal): ?>
                <option value="<?= (int)$sucursal['id'] ?>" <?= (int)$sucursal['id'] === $id_sucursal_usuario ? 'selected' : '' ?>>
                  <?= h($sucursal['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Puedes registrar en otra sucursal si operaste ahí.</div>
          </div>
        </div>

        <div id="alerta_sucursal" class="alert alert-warning alert-sucursal rounded-4 shadow-sm d-none mt-3">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>
          Estás registrando la venta en una sucursal distinta a tu sucursal base. Verifica que sea correcta.
        </div>

        <hr class="section-divider">

        <div class="section-title"><i class="bi bi-people"></i> Datos del cliente</div>

        <div class="row g-3 mb-3">
          <div class="col-md-8">
            <div class="surface-soft p-3">
              <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                  <div class="cliente-summary-label">Cliente seleccionado</div>
                  <div class="cliente-summary-main" id="cliente_resumen_nombre">Ninguno seleccionado</div>
                  <div class="cliente-summary-sub" id="cliente_resumen_detalle">
                    Usa el botón <strong><i class="bi bi-search"></i>Buscar / crear cliente</strong> para seleccionar uno.
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
            <button type="button" class="btn btn-luga btn-luga-soft-primary w-100" id="btn_open_modal_clientes">
              <i class="bi bi-search me-1"></i> Buscar / crear cliente
            </button>
          </div>
        </div>

        <div class="row g-3 mb-2">
          <div class="col-md-4" id="tag_field">
            <label for="tag" class="form-label">TAG (ID del crédito)</label>
            <input
              type="text"
              name="tag"
              id="tag"
              class="form-control"
              placeholder="Ej. PJ123ABC"
              maxlength="15"
              inputmode="text"
              autocomplete="off"
              autocapitalize="characters"
              autocorrect="off"
              spellcheck="false">
            <div class="form-text">Máximo 15 caracteres. Solo letras y números, sin espacios.</div>
          </div>
        </div>

        <hr class="section-divider">

        <div class="section-title"><i class="bi bi-device-ssd"></i> Equipos</div>
        <div class="row g-3 mb-2">
          <div class="col-md-4">
            <label class="form-label req">Equipo Principal</label>
            <select name="equipo1" id="equipo1" class="form-control select2-equipo" required></select>
            <div class="form-text">Puedes buscar por modelo, <strong>IMEI1</strong> o <strong>IMEI2</strong>.</div>
          </div>
          <div class="col-md-4" id="combo" style="display:none;">
            <label class="form-label">Equipo Combo</label>
            <select name="equipo2" id="equipo2" class="form-control select2-equipo"></select>
          </div>
        </div>

        <div id="wrap_promo_regalo" class="mt-3 d-none">
          <div class="alert alert-success mb-2">
            <div class="d-flex align-items-start gap-2">
              <i class="bi bi-gift-fill fs-5"></i>
              <div class="w-100">
                <div class="fw-semibold">
                  Promo detectada: <span id="promo_regalo_nombre">—</span>
                </div>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" id="chk_regalo">
                  <label class="form-check-label fw-semibold" for="chk_regalo">
                    Entregar equipo de regalo (promo)
                  </label>
                </div>
                <div class="small text-muted mt-1" id="promo_regalo_hint">
                  Si activas esto, se forzará el equipo combo y contará como <strong>$0.00</strong>.
                </div>
              </div>
            </div>
          </div>
        </div>

        <div id="wrap_promo_descuento" class="mt-3 d-none">
          <div class="alert alert-primary mb-2">
            <div class="d-flex align-items-start gap-2">
              <i class="bi bi-percent fs-5"></i>
              <div class="w-100">
                <div class="fw-semibold">
                  Promo detectada: <span id="promo_descuento_nombre">—</span>
                  <span class="badge bg-light text-primary border ms-2" id="promo_descuento_porc_badge">—</span>
                </div>

                <div class="small text-muted mt-1" id="promo_descuento_hint">
                  Esta promo permite que el segundo equipo tenga descuento según configuración.
                </div>

                <div class="form-check mt-2 d-none" id="row_chk_desc_combo">
                  <input class="form-check-input" type="checkbox" id="chk_desc_combo">
                  <label class="form-check-label fw-semibold" for="chk_desc_combo">
                    Aplicar descuento al equipo combo en esta misma venta
                  </label>
                  <div class="small text-muted">
                    Si activas esto, el equipo combo se calculará con el descuento y se sumará al total.
                  </div>
                </div>

                <div class="mt-2 d-none" id="row_btn_desc_doble">
                  <button type="button" class="btn btn-luga btn-luga-soft-primary btn-sm" id="btn_open_desc_doble">
                    <i class="bi bi-link-45deg me-1"></i> Aplicar promoción en segunda venta (por TAG)
                  </button>
                  <div class="small text-muted mt-1">
                    Úsalo si vas a registrar el segundo equipo como otra venta. Se validará contra el TAG de la venta principal.
                  </div>
                </div>

                <div class="mt-2 d-none" id="descBadge"></div>
              </div>
            </div>
          </div>
        </div>

        <hr class="section-divider">

        <div class="section-title"><i class="bi bi-cash-coin"></i> Datos financieros</div>
        <div class="row g-3 mb-2">
          <div class="col-md-4">
            <label class="form-label req">Precio de Venta Total ($)</label>
            <input
              type="number"
              step="0.01"
              min="0.01"
              name="precio_venta"
              id="precio_venta"
              class="form-control"
              placeholder="0.00"
              required
              <?= $editablePrecioInicial ? '' : 'readonly' ?>
              data-precio-libre-rol="<?= $editablePrecioInicial ? '1' : '0' ?>"
              data-sucursales-libre="<?= h(implode(',', $SUCURSALES_PRECIO_LIBRE)) ?>">
            <div class="form-text <?= $editablePrecioInicial ? 'd-none' : '' ?>" id="txt_precio_auto">
              Se calcula automáticamente según los equipos seleccionados.
            </div>
            <div class="form-text d-none" id="txt_precio_manual">
              Puedes ajustar manualmente el precio de venta final (Subdistribuidor).
            </div>
            <div class="form-text text-success-soft d-none" id="txt_cupon_info">
              Cupón(s) aplicado(s): -$<span id="lbl_cupon_monto">0.00</span> MXN
              <span class="d-block small text-muted" id="lbl_cupon_detalle"></span>
            </div>
          </div>
          <div class="col-md-4" id="enganche_field">
            <label class="form-label">Enganche ($)</label>
            <input type="number" step="0.01" min="0" name="enganche" id="enganche" class="form-control" value="0" placeholder="0.00">
          </div>
          <div class="col-md-4">
            <label id="label_forma_pago" class="form-label req">Forma de Pago</label>
            <select name="forma_pago_enganche" id="forma_pago_enganche" class="form-control" required>
              <option value="Efectivo">Efectivo</option>
              <option value="Tarjeta">Tarjeta</option>
              <option value="Mixto">Mixto</option>
            </select>
          </div>
        </div>

        <div class="row g-3 mb-2" id="mixto_detalle" style="display:none;">
          <div class="col-md-6">
            <label class="form-label">Enganche Efectivo ($)</label>
            <input type="number" step="0.01" min="0" name="enganche_efectivo" id="enganche_efectivo" class="form-control" value="0" placeholder="0.00">
          </div>
          <div class="col-md-6">
            <label class="form-label">Enganche Tarjeta ($)</label>
            <input type="number" step="0.01" min="0" name="enganche_tarjeta" id="enganche_tarjeta" class="form-control" value="0" placeholder="0.00">
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-4" id="plazo_field">
            <label class="form-label">Plazo en Semanas</label>
            <input type="number" min="1" name="plazo_semanas" id="plazo_semanas" class="form-control" value="0" placeholder="Ej. 52">
          </div>
          <div class="col-md-4" id="pago_semanal_field" style="display:none;">
            <label class="form-label req">Pago Semanal ($)</label>
            <input type="number" step="0.01" min="0.01" name="pago_semanal" id="pago_semanal" class="form-control" placeholder="0.00">
            <div class="form-text text-warning" id="helper_pago_semanal">Se calcula automáticamente. Confirma si el pago es correcto o corrígelo.</div>
          </div>
          <div class="col-md-4" id="primer_pago_field" style="display:none;">
            <label class="form-label req">Primer Pago</label>
            <input type="date" name="primer_pago" id="primer_pago" class="form-control">
            <div class="form-text">Captura la fecha del primer pago del cliente.</div>
          </div>
          <div class="col-md-4" id="financiera_field">
            <label class="form-label">Financiera</label>
            <select name="financiera" id="financiera" class="form-control">
              <option value="">N/A</option>
              <option value="PayJoy">PayJoy</option>
              <option value="Krediya">Krediya</option>
              <option value="Innovación Movil">Innovación Movil</option>
              <option value="Plata Card">Plata Card</option>
              <option value="LesPago">LesPago</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Comentarios</label>
            <input
              type="text"
              name="comentarios"
              id="comentarios"
              class="form-control"
              placeholder="Notas adicionales (opcional)"
              autocomplete="off"
              autocapitalize="off"
              autocorrect="off"
              spellcheck="false" />
          </div>
        </div>
      </div>

      <div class="card-footer bg-white border-0 p-3 sticky-submit">
        <button class="btn btn-gradient text-white w-100 py-2" id="btn_submit">
          <i class="bi bi-check2-circle me-2"></i> Registrar Venta
        </button>
      </div>
    </div>
  </form>
</div>

<div class="modal fade" id="modalConfirmacion" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-patch-question me-2 text-primary"></i>Confirma los datos antes de enviar</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info mb-3">
          <i class="bi bi-info-circle me-1"></i>
          <strong>Validación de identidad:</strong> verifica que la venta se registrará con el <u>usuario correcto</u> y en la <u>sucursal correcta</u>.
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <div class="card card-elev h-100">
              <div class="card-body">
                <div class="section-title"><i class="bi bi-person-check"></i> Usuario y sucursal</div>
                <ul class="list-compact">
                  <li><strong>Usuario:</strong> <span id="conf_usuario"><?= h($nombre_usuario) ?></span></li>
                  <li><strong>Sucursal:</strong> <span id="conf_sucursal">—</span></li>
                </ul>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card card-elev h-100">
              <div class="card-body">
                <div class="section-title"><i class="bi bi-receipt"></i> Venta</div>
                <ul class="list-compact">
                  <li><strong>Tipo:</strong> <span id="conf_tipo">—</span></li>
                  <li><strong>Equipo principal:</strong> <span id="conf_equipo1">—</span></li>
                  <li class="d-none" id="li_equipo2"><strong>Equipo combo:</strong> <span id="conf_equipo2">—</span></li>
                  <li><strong>Precio total:</strong> $<span id="conf_precio">0.00</span></li>
                  <li class="d-none" id="li_enganche"><strong>Enganche:</strong> $<span id="conf_enganche">0.00</span></li>
                  <li class="d-none" id="li_pago_semanal"><strong>Pago semanal:</strong> $<span id="conf_pago_semanal">0.00</span></li>
                  <li class="d-none" id="li_primer_pago"><strong>Primer pago:</strong> <span id="conf_primer_pago">—</span></li>
                  <li class="d-none" id="li_financiera"><strong>Financiera:</strong> <span id="conf_financiera">—</span></li>
                  <li class="d-none" id="li_tag"><strong>TAG:</strong> <span id="conf_tag">—</span></li>
                </ul>
              </div>
            </div>
          </div>
        </div>

        <hr>

        <div class="mb-3">
          <label for="password_confirm" class="form-label">Confirma con tu contraseña de acceso</label>
          <input
            type="password"
            class="form-control"
            id="password_confirm"
            name="password_confirm"
            form="form_venta"
            placeholder="Escribe tu contraseña"
            autocomplete="off" />
          <div class="form-text">
            Esta venta se registrará a nombre de <strong><?= h($nombre_usuario) ?></strong>.
            Para continuar, confirma que eres tú ingresando tu contraseña.
          </div>
        </div>

        <div class="help-text">
          Si detectas un error, cierra este modal y corrige los datos. Si todo es correcto, confirma para enviar.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-luga btn-luga-ghost" data-bs-dismiss="modal">
          <i class="bi bi-pencil-square me-1"></i> Corregir
        </button>
        <button class="btn btn-luga btn-luga-soft-primary" id="btn_confirmar_envio">
          <i class="bi bi-send-check me-1"></i> Confirmar y enviar
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalCupon" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title">
          <i class="bi bi-ticket-perforated text-success me-2"></i>
          Cupón de descuento disponible
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">
          El producto seleccionado (<strong><span id="modal_cupon_equipo">—</span></strong>) tiene un
          <strong>cupón de descuento</strong> por:
        </p>
        <h4 class="text-success">$<span id="modal_cupon_monto">0.00</span> MXN</h4>
        <p class="mt-3 mb-0 small text-muted">
          Si aplicas el cupón, el total de la venta se actualizará con este descuento.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-luga btn-luga-ghost" id="btn_no_aplicar_cupon" data-bs-dismiss="modal">
          No aplicar
        </button>
        <button type="button" class="btn btn-luga btn-luga-success" id="btn_aplicar_cupon">
          Aplicar cupón
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalEngancheInfo" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-light py-2">
        <h6 class="modal-title">
          <i class="bi bi-exclamation-circle text-warning me-1"></i>
          Enganche
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0 small">
          <strong>Recuerda:</strong><br>
          El enganche que se captura debe ser el <strong>COBRADO AL CLIENTE</strong>, NO el solicitado por la financiera.
        </p>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-luga btn-luga-soft-primary btn-sm" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

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

        <div class="mb-3">
          <label class="form-label">Buscar por nombre, teléfono o código de cliente</label>
          <div class="input-group">
            <input type="text" class="form-control" id="cliente_buscar_q" placeholder="Ej. LUCIA, 5587967699 o CL-40-000001">
            <button class="btn btn-luga btn-luga-soft-primary" type="button" id="btn_buscar_modal">
              <i class="bi bi-search"></i> Buscar
            </button>
          </div>
          <div class="form-text">La búsqueda se realiza a nivel <strong>global.</strong></div>
        </div>

        <hr>

        <div class="mb-2 d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Resultados</span>
          <span class="text-muted small" id="lbl_resultados_clientes">Sin buscar aún.</span>
        </div>
        <div class="table-responsive mb-3">
          <div class="table-soft-wrap"><table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Teléfono</th>
                <th>Correo</th>
                <th>Fecha alta</th>
                <th>Sucursal</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="tbody_clientes"></tbody>
          </table></div>
        </div>

        <hr>

        <div class="mb-2">
          <button class="btn btn-outline-success btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNuevoCliente">
            <i class="bi bi-person-plus me-1"></i> Crear nuevo cliente
          </button>
        </div>

        <div class="collapse" id="collapseNuevoCliente">
          <div class="surface-soft p-3">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label req">Nombre completo</label>
                <input type="text" class="form-control" id="nuevo_nombre">
              </div>
              <div class="col-md-4">
                <label class="form-label req">Teléfono (10 dígitos)</label>
                <input type="text" class="form-control" id="nuevo_telefono">
              </div>
              <div class="col-md-4">
                <label class="form-label">Correo</label>
                <input type="email" class="form-control" id="nuevo_correo">
              </div>
            </div>
            <div class="mt-3 text-end">
              <button type="button" class="btn btn-luga btn-luga-success" id="btn_guardar_nuevo_cliente">
                <i class="bi bi-check2-circle me-1"></i> Guardar y seleccionar
              </button>
            </div>
            <div class="form-text">El cliente se creará en la sucursal seleccionada en el formulario.</div>
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button class="btn btn-luga btn-luga-ghost btn-sm" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalPromoDescuentoDoble" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title">
          <i class="bi bi-percent text-primary me-2"></i>Promoción: segundo equipo con descuento
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info mb-3">
          <i class="bi bi-info-circle me-1"></i>
          Captura el <strong>TAG</strong> de la venta principal para validar que incluye un equipo participante.
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label req">TAG de la venta principal</label>
            <input
              type="text"
              class="form-control"
              id="desc_tag_origen"
              placeholder="Ej. PJ123ABC"
              maxlength="15"
              inputmode="text"
              autocomplete="off"
              autocapitalize="characters"
              autocorrect="off"
              spellcheck="false">
            <div class="form-text">Máximo 15 caracteres. Solo letras y números, sin espacios.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Promo detectada</label>
            <div class="surface-soft p-2">
              <div class="fw-semibold" id="desc_promo_nombre">—</div>
              <div class="small text-muted" id="desc_promo_detalle">Ingresa un TAG para validar.</div>
            </div>
          </div>
        </div>

        <hr>

        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label req">Equipo con descuento</label>
            <input type="text" id="desc_equipo_label" class="form-control" value="" readonly>
            <input type="hidden" id="desc_equipo" value="">
            <div class="form-text">Usaremos el equipo que ya seleccionaste en el formulario principal.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Precio con descuento</label>
            <input type="text" class="form-control" id="desc_precio" value="0.00" readonly>
            <div class="form-text">Se calculará desde precio_lista con el porcentaje de la promo.</div>
          </div>
        </div>

        <div class="alert alert-warning mt-3">
          <i class="bi bi-exclamation-triangle me-1"></i>
          Esto prepara la venta actual como <strong>Financiamiento</strong> de un solo equipo con precio con descuento, y guardará el <strong>TAG origen</strong> para validar en backend.
        </div>

        <div id="desc_err" class="alert alert-danger d-none"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-luga btn-luga-ghost" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-luga btn-luga-soft-primary" id="btnAplicarDescDoble">
          <i class="bi bi-check2-circle me-1"></i> Aplicar a esta venta
        </button>
      </div>
    </div>
  </div>
</div>

<script>
$(document).ready(function() {
  const idSucursalUsuario = <?= (int)$id_sucursal_usuario ?>;
  const mapaSucursales = <?= json_encode($mapSuc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  const modalConfirm = new bootstrap.Modal(document.getElementById('modalConfirmacion'));
  const modalClientes = new bootstrap.Modal(document.getElementById('modalClientes'));
  const modalCupon = new bootstrap.Modal(document.getElementById('modalCupon'));
  const modalEngancheInfo = new bootstrap.Modal(document.getElementById('modalEngancheInfo'));
  const modalDescDoble = new bootstrap.Modal(document.getElementById('modalPromoDescuentoDoble'));

  const BLOQUEAR_PROMOS = <?= $BLOQUEAR_PROMOS ? 'true' : 'false' ?>;

  const precioLibrePorRol = ($('#precio_venta').data('precio-libre-rol') == 1);

  function normalizarTAG(valor) {
    return String(valor || '')
      .toUpperCase()
      .replace(/\s+/g, '')
      .replace(/[^A-Z0-9]/g, '')
      .slice(0, 15);
  }

  function bindTagSanitizer(selector) {
    $(selector).on('input', function() {
      const limpio = normalizarTAG($(this).val());
      if ($(this).val() !== limpio) {
        $(this).val(limpio);
      }
    });

    $(selector).on('paste', function() {
      const el = this;
      setTimeout(function() {
        $(el).val(normalizarTAG($(el).val()));
      }, 0);
    });

    $(selector).on('blur', function() {
      $(this).val(normalizarTAG($(this).val()));
    });
  }

  bindTagSanitizer('#tag');
  bindTagSanitizer('#desc_tag_origen');

  function puedeEditarPrecio(){ return !!precioLibrePorRol; }

  const sucursalesPrecioLibre = (String($('#precio_venta').data('sucursales-libre') || ''))
    .split(',')
    .map(s => parseInt(s.trim(), 10))
    .filter(n => !isNaN(n));

  function esSucursalPrecioLibre(idSucursal){
    const id = parseInt(idSucursal, 10);
    if (isNaN(id)) return false;
    return sucursalesPrecioLibre.includes(id);
  }

  let precioEditadoManualmente = false;
  $('#precio_venta').on('input', function(){
    if (puedeEditarPrecio()) precioEditadoManualmente = true;
  });

  let engancheModalShown = false;
  $('#enganche').on('focus', function(){
    if (!engancheModalShown) {
      engancheModalShown = true;
      modalEngancheInfo.show();
    }
  });

  let cuponPrincipalDisponible = 0;
  let cuponPrincipalAplicado = false;

  let cuponComboDisponible = 0;
  let cuponComboAplicado = false;

  let cuponTargetActual = null;

  let promoRegaloAplica = false;
  let promoRegaloId = null;
  let promoRegaloNombre = '';
  let tipoVentaPrevio = '';

  let promoDescAplica = false;
  let promoDesc = null;
  let descComboOn = false;

  function resetPromoDescuentoUI() {
    promoDescAplica = false;
    promoDesc = null;
    descComboOn = false;

    $('#wrap_promo_descuento').addClass('d-none');
    $('#promo_descuento_nombre').text('—');
    $('#promo_descuento_porc_badge').text('—');
    $('#promo_descuento_hint').text('Esta promo permite que el segundo equipo tenga descuento según configuración.');

    $('#row_chk_desc_combo').addClass('d-none');
    $('#chk_desc_combo').prop('checked', false);

    $('#row_btn_desc_doble').addClass('d-none');

    $('#promo_descuento_aplicado').val('0');
    $('#promo_descuento_id').val('0');
    $('#promo_descuento_modo').val('');
    $('#promo_descuento_tag_origen').val('');
    $('#promo_descuento_porcentaje').val('0');
    $('#promo_descuento_doble_venta').val('0');

    $('#descBadge').addClass('d-none').html('');
  }

  function resetPromoRegaloUI() {
    promoRegaloAplica = false;
    promoRegaloId = null;
    promoRegaloNombre = '';

    $('#wrap_promo_regalo').addClass('d-none');
    $('#promo_regalo_nombre').text('—');
    $('#chk_regalo').prop('checked', false);

    $('#es_regalo').val('0');
    $('#id_promo_regalo').val('');
    $('#promo_regalo_aplicado').val('0');
    $('#promo_regalo_id').val('0');

    $('#tipo_venta').removeClass('select-lock').removeAttr('data-locked');
    tipoVentaPrevio = '';
  }

  function isFinanciamiento(){
    const tipo = $('#tipo_venta').val();
    return (tipo === 'Financiamiento' || tipo === 'Financiamiento+Combo');
  }

  function isFinanciamientoCombo(){
    return $('#tipo_venta').val() === 'Financiamiento+Combo';
  }

  function actualizarInfoCupon() {
    const montoPrincipal = (cuponPrincipalAplicado && cuponPrincipalDisponible > 0) ? cuponPrincipalDisponible : 0;
    const montoCombo     = (cuponComboAplicado && cuponComboDisponible > 0) ? cuponComboDisponible : 0;
    const totalCupon     = montoPrincipal + montoCombo;

    $('#monto_cupon_principal').val(montoPrincipal.toFixed(2));
    $('#monto_cupon_combo').val(montoCombo.toFixed(2));
    $('#monto_cupon').val(totalCupon.toFixed(2));

    if (totalCupon > 0) {
      const partes = [];
      if (montoPrincipal > 0) partes.push(`Principal: -$${montoPrincipal.toFixed(2)}`);
      if (montoCombo > 0) partes.push(`Combo: -$${montoCombo.toFixed(2)}`);

      $('#txt_cupon_info').removeClass('d-none');
      $('#lbl_cupon_monto').text(totalCupon.toFixed(2));
      $('#lbl_cupon_detalle').text(partes.join(' · '));
    } else {
      $('#txt_cupon_info').addClass('d-none');
      $('#lbl_cupon_monto').text('0.00');
      $('#lbl_cupon_detalle').text('');
    }
  }

  function resetCuponPrincipal() {
    cuponPrincipalDisponible = 0;
    cuponPrincipalAplicado = false;
    actualizarInfoCupon();
  }

  function resetCuponCombo() {
    cuponComboDisponible = 0;
    cuponComboAplicado = false;
    actualizarInfoCupon();
  }

  function resetTodosLosCupones() {
    resetCuponPrincipal();
    resetCuponCombo();
  }

  function obtenerLabelEquipo($select) {
    let label = $select.find('option:selected').text() || 'Equipo';
    try {
      const d = $select.select2('data');
      if (d && d[0] && d[0].text) label = d[0].text;
    } catch(e){}
    return label;
  }

  function consultarCuponEquipo(idInventario, target, nombreEquipo) {
    if (!idInventario) return;

    $.ajax({
      url: 'ajax_cupon_producto.php',
      method: 'POST',
      dataType: 'json',
      data: { id_inventario: idInventario },
      success: function(res) {
        let monto = 0;
        if (res && res.ok) monto = parseFloat(res.monto_cupon) || 0;

        if (target === 'principal') {
          cuponPrincipalDisponible = monto;
          cuponPrincipalAplicado = false;
        } else if (target === 'combo') {
          cuponComboDisponible = monto;
          cuponComboAplicado = false;
        }

        actualizarInfoCupon();
        recalcPrecioVenta();

        if (monto > 0) {
          cuponTargetActual = target;
          $('#modal_cupon_equipo').text(nombreEquipo || 'Equipo');
          $('#modal_cupon_monto').text(monto.toFixed(2));
          modalCupon.show();
        }
      },
      error: function() {
        if (target === 'principal') {
          cuponPrincipalDisponible = 0;
          cuponPrincipalAplicado = false;
        } else if (target === 'combo') {
          cuponComboDisponible = 0;
          cuponComboAplicado = false;
        }

        actualizarInfoCupon();
        recalcPrecioVenta();
      }
    });
  }

  function aplicarEstadoDescComboUI(isOn) {
    if ($('#es_regalo').val() === '1') {
      $('#chk_desc_combo').prop('checked', false);
      descComboOn = false;
      return;
    }
    if (!promoDescAplica || !promoDesc || !promoDesc.id) {
      $('#chk_desc_combo').prop('checked', false);
      descComboOn = false;
      return;
    }

    descComboOn = !!isOn;

    if (descComboOn) {
      if ($('#tipo_venta').val() !== 'Financiamiento+Combo') {
        $('#tipo_venta').val('Financiamiento+Combo').trigger('change');
      }
      $('#combo').show();

      $('#promo_descuento_aplicado').val('1');
      $('#promo_descuento_id').val(String(promoDesc.id));
      $('#promo_descuento_modo').val('COMBO');
      $('#promo_descuento_tag_origen').val('');
      $('#promo_descuento_porcentaje').val(String(promoDesc.porcentaje_descuento || 50));
      $('#promo_descuento_doble_venta').val('0');

      const suc = $('#id_sucursal').val();
      const invPrincipal = $('#equipo1').val();

      $.ajax({
        url: 'ajax_promo_descuento_combos.php',
        method: 'POST',
        data: { promo_id: promoDesc.id, id_sucursal: suc, exclude_inventario: invPrincipal },
        success: function(html){
          $('#equipo2').html(html).val('').trigger('change');
          refreshEquipoLocks();
          recalcPrecioVenta();
        },
        error: function(xhr){
          console.warn('No se pudo cargar combos con descuento:', xhr.responseText || xhr.statusText);
        }
      });

    } else {
      $('#promo_descuento_aplicado').val('0');
      $('#promo_descuento_id').val('0');
      $('#promo_descuento_modo').val('');
      $('#promo_descuento_tag_origen').val('');
      $('#promo_descuento_porcentaje').val('0');
      $('#promo_descuento_doble_venta').val('0');

      if (isFinanciamientoCombo()) {
        cargarEquipos($('#id_sucursal').val());
      }
    }

    refreshEquipoLocks();
    recalcPrecioVenta();
  }

  $('#chk_desc_combo').on('change', function(){
    aplicarEstadoDescComboUI($(this).is(':checked'));
  });

  function resetModalDescDoble(){
    $('#desc_tag_origen').val('');
    $('#desc_promo_nombre').text('—');
    $('#desc_promo_detalle').text('Ingresa el TAG para validar la venta principal.');
    $('#desc_equipo').val('');
    $('#desc_equipo_label').val('');
    $('#desc_precio').val('0.00');
    $('#desc_err').addClass('d-none').text('');
    $('#btnAplicarDescDoble').prop('disabled', true);

    $('#promo_descuento_aplicado').val('0');
    $('#promo_descuento_id').val('0');
    $('#promo_descuento_tag_origen').val('');
    $('#promo_descuento_porcentaje').val('0');
    $('#promo_descuento_doble_venta').val('0');
    $('#promo_descuento_modo').val('');
  }

  $('#btn_open_desc_doble').on('click', function(){
    if (BLOQUEAR_PROMOS) return;
    var inv = parseInt($('#equipo1').val() || '0', 10);
    if (!inv) { alert('Primero selecciona el equipo que vas a vender (Equipo Principal).'); return; }

    var label = $('#equipo1 option:selected').text();
    try {
      var d = $('#equipo1').select2('data');
      if (d && d[0] && d[0].text) label = d[0].text;
    } catch(e){}

    resetModalDescDoble();
    $('#desc_equipo').val(String(inv));
    $('#desc_equipo_label').val(label || ('Inventario #' + inv));
    $('#desc_promo_detalle').text('Ingresa el TAG de la venta principal para validar la promo y este equipo.');
    modalDescDoble.show();
  });

  $('#desc_tag_origen').on('keydown', function(e){
    if (e.key === 'Enter') { e.preventDefault(); $(this).trigger('blur'); }
  });

  $('#desc_tag_origen').on('blur', function(){
    if (BLOQUEAR_PROMOS) return;
    var tag = normalizarTAG($('#desc_tag_origen').val() || '');
    $('#desc_tag_origen').val(tag);

    if (!tag) return;

    var inv = parseInt($('#desc_equipo').val() || '0', 10);
    if (!inv) {
      $('#desc_err').removeClass('d-none').text('Primero selecciona el equipo en el formulario principal.');
      $('#btnAplicarDescDoble').prop('disabled', true);
      return;
    }

    $('#desc_err').addClass('d-none').text('');
    $('#btnAplicarDescDoble').prop('disabled', true);

    $.post('ajax_promo_descuento_validar_tag.php', { tag_origen: tag }, function(resp){
      if (!resp || !resp.ok) {
        $('#desc_err').removeClass('d-none').text((resp && resp.message) ? resp.message : 'No se pudo validar el TAG.');
        return;
      }

      const promo = resp.promo || {};
      const promoNombre = promo.nombre || resp.promo_nombre || '—';
      const promoId = parseInt((promo.id ?? resp.promo_id ?? 0), 10);
      const porc = parseFloat((promo.porcentaje_descuento ?? resp.porcentaje_descuento ?? 50));
      const principalCodigo = resp.principal_codigo || '—';

      $('#desc_promo_nombre').text(promoNombre);
      $('#desc_promo_detalle').text('Descuento: ' + (isFinite(porc) ? porc : 50) + '% · Principal: ' + principalCodigo);

      if (!resp.aplica || !promoId) {
        $('#desc_err').removeClass('d-none').text(resp.message || 'No se detectó una promo activa en la venta principal.');
        return;
      }

      $.post('ajax_promo_descuento_validar_equipo.php', { promo_id: promoId, id_inventario: inv }, function(r2){
        if (!r2 || !r2.ok) {
          $('#desc_err').removeClass('d-none').text((r2 && r2.message) ? r2.message : 'No se pudo validar el equipo.');
          return;
        }
        if (!r2.elegible) {
          $('#desc_err').removeClass('d-none').text('El equipo seleccionado NO participa como segundo equipo con descuento para esta promo.');
          $('#desc_precio').val('0.00');
          return;
        }

        $('#desc_precio').val(parseFloat(r2.precio_descuento || 0).toFixed(2));

        $('#promo_descuento_aplicado').val('1');
        $('#promo_descuento_id').val(String(promoId));
        $('#promo_descuento_tag_origen').val(tag);
        $('#promo_descuento_porcentaje').val(String(porc));

        $('#btnAplicarDescDoble').prop('disabled', false);
      }, 'json').fail(function(xhr){
        $('#desc_err').removeClass('d-none').text(xhr.responseText || 'Error validando el equipo.');
      });

    }, 'json').fail(function(xhr){
      $('#desc_err').removeClass('d-none').text(xhr.responseText || 'Error validando el TAG.');
    });
  });

  $('#btnAplicarDescDoble').on('click', function(){
    if (BLOQUEAR_PROMOS) return;
    var tag = normalizarTAG($('#desc_tag_origen').val() || '');
    $('#desc_tag_origen').val(tag);

    if (!tag) { $('#desc_err').removeClass('d-none').text('Captura el TAG de la venta principal.'); return; }

    var inv = parseInt($('#desc_equipo').val() || '0', 10);
    if (!inv) { $('#desc_err').removeClass('d-none').text('Primero selecciona el equipo en el formulario principal.'); return; }

    var promoId = ($('#promo_descuento_id').val() || '').trim();
    if (!promoId) { $('#desc_err').removeClass('d-none').text('Primero valida el TAG para detectar la promo.'); return; }

    $('#tipo_venta').val('Financiamiento').trigger('change');
    $('#equipo2').val(null).trigger('change');

    $('#promo_descuento_doble_venta').val('1');
    $('#promo_descuento_modo').val('DOBLE_VENTA');

    var precioDesc = parseFloat($('#desc_precio').val() || '0');
    if (precioDesc > 0) $('#precio_venta').val(precioDesc.toFixed(2));

    $('#descBadge').removeClass('d-none')
      .html('✅ Descuento en segunda venta aplicado. TAG origen: <b>' + $('<div/>').text(tag).html() + '</b>');

    modalDescDoble.hide();
  });

  function aplicarEstadoRegaloUI(isOn) {
    if (BLOQUEAR_PROMOS) {
      $('#chk_regalo').prop('checked', false);
      resetPromoRegaloUI();
      return;
    }

    if (isOn) {
      if ($('#tipo_venta').val() !== 'Financiamiento+Combo') tipoVentaPrevio = $('#tipo_venta').val() || '';

      $('#tipo_venta').val('Financiamiento+Combo').trigger('change');
      $('#tipo_venta').addClass('select-lock').attr('data-locked', '1');
      $('#combo').show();

      $('#es_regalo').val('1');
      $('#id_promo_regalo').val(promoRegaloId ? String(promoRegaloId) : '');
      $('#promo_regalo_aplicado').val('1');
      $('#promo_regalo_id').val(promoRegaloId ? String(promoRegaloId) : '0');

      const suc = $('#id_sucursal').val();
      const invPrincipal = $('#equipo1').val();

      $.ajax({
        url: 'ajax_promo_regalo_combos.php',
        method: 'POST',
        data: { promo_id: promoRegaloId, id_sucursal: suc, exclude_inventario: invPrincipal },
        success: function(html){
          $('#equipo2').html(html).val('').trigger('change');
          refreshEquipoLocks();
          recalcPrecioVenta();
        },
        error: function(xhr){
          console.warn('No se pudo filtrar combos promo:', xhr.responseText || xhr.statusText);
        }
      });

    } else {
      cargarEquipos($('#id_sucursal').val());

      $('#es_regalo').val('0');
      $('#id_promo_regalo').val('');
      $('#promo_regalo_aplicado').val('0');
      $('#promo_regalo_id').val('0');

      $('#tipo_venta').removeClass('select-lock').removeAttr('data-locked');
      if (tipoVentaPrevio) $('#tipo_venta').val(tipoVentaPrevio).trigger('change');
      tipoVentaPrevio = '';
    }

    refreshEquipoLocks();
    recalcPrecioVenta();
  }

  $('#chk_regalo').on('change', function(){
    const on = $(this).is(':checked');
    if (on) {
      if (BLOQUEAR_PROMOS || !promoRegaloAplica || !promoRegaloId) {
        $(this).prop('checked', false);
        return;
      }
    }
    aplicarEstadoRegaloUI(on);
  });

  function setLockedUI(locked, msgHtml){
    const $banner = $('#banner_candado');
    const $msg = $('#candado_msg');
    const $form = $('#form_venta');
    const $btn = $('#btn_submit');

    if (locked) {
      if ($banner.length) {
        $banner.removeClass('d-none').addClass('d-block');
        if (msgHtml && $msg.length) $msg.html(msgHtml);
      }

      $form.attr('data-locked', '1');
      $form.find('input,select,textarea,button').prop('disabled', true);

      $btn.prop('disabled', true).html('<i class="bi bi-lock-fill me-2"></i> Bloqueado por corte pendiente');
    } else {
      if ($banner.length) {
        $banner.removeClass('d-block').addClass('d-none');
      }

      $form.attr('data-locked', '0');
      $form.find('input,select,textarea,button').prop('disabled', false);

      $btn.prop('disabled', false).html('<i class="bi bi-check2-circle me-2"></i> Registrar Venta');
    }
  }

  <?php if ($bloquearInicial): ?>
    setLockedUI(true, <?= json_encode($mensajeBloqueoInicial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
  <?php else: ?>
    setLockedUI(false);
  <?php endif; ?>

  $('.select2-equipo').select2({
    placeholder: "Buscar por modelo, IMEI1 o IMEI2",
    allowClear: true,
    width: '100%'
  });

  function limpiarCliente() {
    $('#id_cliente').val('');
    $('#nombre_cliente').val('');
    $('#telefono_cliente').val('');
    $('#correo_cliente').val('');

    $('#cliente_resumen_nombre').text('Ninguno seleccionado');
    $('#cliente_resumen_detalle').html('Usa el botón <strong><i class="bi bi-search"></i>Buscar / crear cliente</strong> para seleccionar uno.');
    $('#badge_tipo_cliente')
      .removeClass('text-bg-success')
      .addClass('text-bg-secondary')
      .html('<i class="bi bi-person-dash me-1"></i> Sin cliente');
  }

  function setClienteSeleccionado(c) {
    $('#id_cliente').val(c.id || '');
    $('#nombre_cliente').val(c.nombre || '');
    $('#telefono_cliente').val(c.telefono || '');
    $('#correo_cliente').val(c.correo || '');

    const nombre = c.nombre || '(Sin nombre)';
    const detParts = [];
    if (c.telefono) detParts.push('Tel: ' + c.telefono);
    if (c.codigo_cliente) detParts.push('Código: ' + c.codigo_cliente);
    if (c.correo) detParts.push('Correo: ' + c.correo);

    $('#cliente_resumen_nombre').text(nombre);
    $('#cliente_resumen_detalle').text(detParts.join(' · ') || 'Sin más datos.');

    $('#badge_tipo_cliente')
      .removeClass('text-bg-secondary')
      .addClass('text-bg-success')
      .html('<i class="bi bi-person-check me-1"></i> Cliente seleccionado');
  }

  $('#btn_open_modal_clientes').on('click', function(){
    $('#cliente_buscar_q').val('');
    $('#tbody_clientes').empty();
    $('#lbl_resultados_clientes').text('Sin buscar aún.');
    $('#collapseNuevoCliente').removeClass('show');
    modalClientes.show();
  });

  $('#btn_buscar_modal').on('click', function(){
    const q = $('#cliente_buscar_q').val().trim();
    const idSucursal = $('#id_sucursal').val();

    if (!q) { alert('Escribe algo para buscar (nombre, teléfono o código).'); return; }

    $.post('ajax_clientes_buscar_modal.php', { q:q, id_sucursal:idSucursal }, function(res){
      if (!res || !res.ok) { alert(res && res.message ? res.message : 'No se pudo buscar clientes.'); return; }

      const clientes = res.clientes || [];
      const $tbody = $('#tbody_clientes');
      $tbody.empty();

      if (clientes.length === 0) {
        $('#lbl_resultados_clientes').text('Sin resultados. Puedes crear un cliente nuevo.');
        return;
      }

      $('#lbl_resultados_clientes').text('Se encontraron ' + clientes.length + ' cliente(s).');

      clientes.forEach(function(c){
        const $tr = $('<tr>');
        if (parseInt(c.id_sucursal, 10) === idSucursalUsuario) $tr.addClass('table-success');

        $tr.append($('<td>').text(c.codigo_cliente || '—'));
        $tr.append($('<td>').text(c.nombre || ''));
        $tr.append($('<td>').text(c.telefono || ''));
        $tr.append($('<td>').text(c.correo || ''));
        $tr.append($('<td>').text(c.fecha_alta || ''));
        $tr.append($('<td>').text(c.sucursal_nombre || '—'));

        const $btnSel = $('<button type="button" class="btn btn-sm btn-primary">')
          .html('<i class="bi bi-check2-circle me-1"></i> Seleccionar')
          .data('cliente', c)
          .on('click', function(){
            const cliente = $(this).data('cliente');
            setClienteSeleccionado(cliente);
            modalClientes.hide();
          });

        $tr.append($('<td>').append($btnSel));
        $tbody.append($tr);
      });
    }, 'json').fail(function(){
      alert('Error al buscar en la base de clientes.');
    });
  });

  $('#cliente_buscar_q').on('keydown', function(e){
    if (e.key === 'Enter') { e.preventDefault(); $('#btn_buscar_modal').click(); }
  });

  $('#btn_guardar_nuevo_cliente').on('click', function(){
    const nombre = $('#nuevo_nombre').val().trim();
    let tel = $('#nuevo_telefono').val().trim();
    const correo = $('#nuevo_correo').val().trim();
    const idSucursal = $('#id_sucursal').val();

    if (!nombre) { alert('Captura el nombre del cliente.'); return; }
    tel = tel.replace(/\D+/g, '');
    if (!/^\d{10}$/.test(tel)) { alert('El teléfono debe tener exactamente 10 dígitos.'); return; }

    $.post('ajax_crear_cliente.php', { nombre:nombre, telefono:tel, correo:correo, id_sucursal:idSucursal }, function(res){
      if (!res || !res.ok) { alert(res && res.message ? res.message : 'No se pudo guardar el cliente.'); return; }

      const c = res.cliente || {};
      setClienteSeleccionado(c);
      modalClientes.hide();

      $('#nuevo_nombre').val('');
      $('#nuevo_telefono').val('');
      $('#nuevo_correo').val('');
      $('#collapseNuevoCliente').removeClass('show');

      alert(res.message || 'Cliente creado y vinculado.');
    }, 'json').fail(function(xhr){
      alert('Error al guardar el cliente: ' + (xhr.responseText || 'desconocido'));
    });
  });

  function actualizarBloqueoPrecio() {
    const esLibre = puedeEditarPrecio();
    $('#precio_venta').prop('readonly', !esLibre);

    if (esLibre) {
      $('#txt_precio_auto').addClass('d-none');
      $('#txt_precio_manual').removeClass('d-none');
    } else {
      $('#txt_precio_auto').removeClass('d-none');
      $('#txt_precio_manual').addClass('d-none');
      precioEditadoManualmente = false;
    }
  }

  function recalcPrecioVenta() {
    let total = 0;

    const $opt1 = $('#equipo1').find('option:selected');
    if ($opt1.length && $opt1.val()) {
      const pLista1 = parseFloat($opt1.data('precio-lista')) || 0;
      total += pLista1;
    }

    if (isFinanciamientoCombo()) {
      const $opt2 = $('#equipo2').find('option:selected');
      if ($opt2.length && $opt2.val()) {
        const esRegalo = ($('#es_regalo').val() === '1');
        const descOn = (descComboOn === true) && ($('#promo_descuento_aplicado').val() === '1') && ($('#promo_descuento_modo').val() === 'COMBO');

        if (esRegalo) {
          total += 0;
        } else if (descOn) {
          const pDesc = parseFloat($opt2.data('precio-desc'));
          if (!isNaN(pDesc)) {
            total += pDesc;
          } else {
            const pLista2 = parseFloat($opt2.data('precio-lista')) || 0;
            total += (pLista2 * 0.5);
          }
        } else {
          const pLista2 = parseFloat($opt2.data('precio-lista')) || 0;
          const pCombo2 = parseFloat($opt2.data('precio-combo'));
          const precio2 = (!isNaN(pCombo2) && pCombo2 > 0) ? pCombo2 : pLista2;
          total += precio2;
        }
      }
    }

    const descuentoPrincipal = (cuponPrincipalAplicado && cuponPrincipalDisponible > 0) ? cuponPrincipalDisponible : 0;
    const descuentoCombo     = (cuponComboAplicado && cuponComboDisponible > 0) ? cuponComboDisponible : 0;

    total = total - descuentoPrincipal - descuentoCombo;

    if (total < 0) total = 0;

    const esLibre = puedeEditarPrecio();
    if (!esLibre || !precioEditadoManualmente) {
      if (total > 0) $('#precio_venta').val(total.toFixed(2));
      else $('#precio_venta').val('');
    }

    const precio = parseFloat($('#precio_venta').val()) || 0;
    $('#conf_precio').text(precio.toFixed(2));
    recalcularPagoSemanal();
  }

  $('#tipo_venta').on('change', function(){
    if ($(this).attr('data-locked') === '1') {
      $(this).val('Financiamiento+Combo');
      return;
    }

    $('#combo').toggle(isFinanciamientoCombo());
    if (!isFinanciamientoCombo()) {
      $('#equipo2').val(null).trigger('change');
      $('#equipo1 option, #equipo2 option').prop('disabled', false);
      resetCuponCombo();

      if ($('#es_regalo').val() === '1') {
        resetPromoRegaloUI();
        resetPromoDescuentoUI();
      }
    }

    toggleVenta();
    refreshEquipoLocks();
    recalcPrecioVenta();
  });

  $('#forma_pago_enganche').on('change', function(){
    $('#mixto_detalle').toggle($(this).val() === 'Mixto' && isFinanciamiento());
  });

  $('#precio_venta, #enganche, #plazo_semanas').on('input change', function(){
    recalcularPagoSemanal();
  });

  function recalcularPagoSemanal() {
    const esFin = isFinanciamiento();
    if (!esFin) return;

    const precio = parseFloat($('#precio_venta').val()) || 0;
    const enganche = parseFloat($('#enganche').val()) || 0;
    const plazo = parseInt($('#plazo_semanas').val(), 10) || 0;

    if (plazo > 0) {
      let restante = precio - enganche;
      if (restante < 0) restante = 0;
      const pago = restante / plazo;
      $('#pago_semanal').val(pago.toFixed(2));
    } else {
      $('#pago_semanal').val('');
    }
  }

  function toggleVenta() {
    const esFin = isFinanciamiento();
    $('#tag_field, #enganche_field, #plazo_field, #pago_semanal_field, #primer_pago_field, #financiera_field').toggle(esFin);
    $('#mixto_detalle').toggle(esFin && $('#forma_pago_enganche').val() === 'Mixto');
    $('#label_forma_pago').text(esFin ? 'Forma de Pago Enganche' : 'Forma de Pago');

    $('#tag').prop('required', esFin);
    $('#enganche').prop('required', esFin);
    $('#plazo_semanas').prop('required', esFin);
    $('#pago_semanal').prop('required', esFin);
    $('#primer_pago').prop('required', esFin);
    $('#financiera').prop('required', esFin);

    $('#precio_venta').prop('required', true);
    $('#forma_pago_enganche').prop('required', true);

    if (!esFin) {
      $('#tag').val('');
      $('#enganche').val(0);
      $('#plazo_semanas').val(0);
      $('#pago_semanal').val('');
      $('#primer_pago').val('');
      $('#financiera').val('');
      $('#enganche_efectivo').val(0);
      $('#enganche_tarjeta').val(0);
    } else {
      $('#tag').val(normalizarTAG($('#tag').val()));
      recalcularPagoSemanal();
    }
  }
  toggleVenta();

  function refreshEquipoLocks() {
    const v1 = $('#equipo1').val();
    const v2 = $('#equipo2').val();

    $('#equipo1 option, #equipo2 option').prop('disabled', false);
    if (v1) $('#equipo2 option[value="' + v1 + '"]').prop('disabled', true);
    if (v2) $('#equipo1 option[value="' + v2 + '"]').prop('disabled', true);

    if (v1 && v2 && v1 === v2) $('#equipo2').val(null).trigger('change');
  }

  function cargarEquipos(sucursalId){}

  $('#id_sucursal').on('change', function(){
    const seleccionada = parseInt($(this).val());
    if (seleccionada !== idSucursalUsuario) $('#alerta_sucursal').removeClass('d-none');
    else $('#alerta_sucursal').addClass('d-none');

    cargarEquipos(seleccionada);

    $.post('ajax_check_corte.php', { id_sucursal: seleccionada }, function(res){
      if (!res || !res.ok) return;
      if (res.bloquear) {
        const html = `<strong>Captura bloqueada.</strong> ${res.motivo}
          <div class="small">Genera el corte de <strong>${res.ayer}</strong> para continuar.</div>`;
        setLockedUI(true, html);
      } else {
        setLockedUI(false);
      }
    }, 'json').fail(function(){
      console.warn('No se pudo verificar el candado por AJAX. El back-end seguirá validando.');
    });

    actualizarBloqueoPrecio();
    precioEditadoManualmente = false;

    resetTodosLosCupones();
    resetPromoRegaloUI();
    resetPromoDescuentoUI();

    recalcPrecioVenta();
  });

  let permitSubmit = false;

  function validarFormulario() {
    const errores = [];
    const esFin = isFinanciamiento();

    const idCliente = $('#id_cliente').val();
    const tel = $('#telefono_cliente').val().trim();
    const tag = normalizarTAG($('#tag').val() || '');
    $('#tag').val(tag);

    const tipo = $('#tipo_venta').val();

    const precio = parseFloat($('#precio_venta').val());
    const eng = parseFloat($('#enganche').val());
    const forma = $('#forma_pago_enganche').val();
    const plazo = parseInt($('#plazo_semanas').val(), 10);
    const finan = $('#financiera').val();

    if (!tipo) errores.push('Selecciona el tipo de venta.');
    if (!precio || precio <= 0) errores.push('El precio de venta debe ser mayor a 0.');
    if (!forma) errores.push('Selecciona la forma de pago.');
    if (!$('#equipo1').val()) errores.push('Selecciona el equipo principal.');

    if (!idCliente) errores.push('Debes seleccionar un cliente antes de registrar la venta.');
    if (!tel) errores.push('El cliente seleccionado debe tener teléfono.');
    else if (!/^\d{10}$/.test(tel)) errores.push('El teléfono del cliente debe tener 10 dígitos.');

    if (isFinanciamientoCombo()) {
      const v1 = $('#equipo1').val();
      const v2 = $('#equipo2').val();
      if (!v2) errores.push('Selecciona el equipo combo.');
      if (v1 && v2 && v1 === v2) errores.push('El equipo combo debe ser distinto del principal.');
    }

    const esRegalo = ($('#es_regalo').val() === '1');
    if (esRegalo && !$('#equipo2').val()) errores.push('La promo de regalo requiere seleccionar el equipo combo (regalo).');

    if (esFin) {
      if (!tag) {
        errores.push('El TAG (ID del crédito) es obligatorio.');
      } else if (!/^[A-Z0-9]{1,15}$/.test(tag)) {
        errores.push('El TAG debe tener máximo 15 caracteres y solo puede contener letras y números, sin espacios.');
      }

      if (isNaN(eng) || eng < 0) errores.push('El enganche es obligatorio (puede ser 0, no negativo).');
      if (!plazo || plazo <= 0) errores.push('El plazo en semanas debe ser mayor a 0.');
      if (!finan) errores.push('Selecciona una financiera (no N/A).');

      if (forma === 'Mixto') {
        const ef = parseFloat($('#enganche_efectivo').val()) || 0;
        const tj = parseFloat($('#enganche_tarjeta').val()) || 0;
        if (ef <= 0 && tj <= 0) errores.push('En Mixto, al menos uno de los montos debe ser > 0.');
        if ((eng || 0).toFixed(2) !== (ef + tj).toFixed(2)) errores.push('Efectivo + Tarjeta debe igualar al Enganche.');
      }
    }

    return errores;
  }

  function poblarModal() {
    const idSucSel = $('#id_sucursal').val();
    const sucNom = mapaSucursales[idSucSel] || '—';
    $('#conf_sucursal').text(sucNom);

    const tipo = $('#tipo_venta').val() || '—';
    $('#conf_tipo').text(tipo);

    const equipo1Text = $('#equipo1').find('option:selected').text() || '—';
    const equipo2Text = $('#equipo2').find('option:selected').text() || '';

    $('#conf_equipo1').text(equipo1Text);
    if ($('#combo').is(':visible') && $('#equipo2').val()) {
      $('#conf_equipo2').text(equipo2Text);
      $('#li_equipo2').removeClass('d-none');
    } else {
      $('#li_equipo2').addClass('d-none');
    }

    const precio = parseFloat($('#precio_venta').val()) || 0;
    $('#conf_precio').text(precio.toFixed(2));

    const esFin = isFinanciamiento();
    if (esFin) {
      const eng = parseFloat($('#enganche').val()) || 0;
      $('#conf_enganche').text(eng.toFixed(2));
      $('#li_enganche').removeClass('d-none');

      const pagoSemanal = parseFloat($('#pago_semanal').val()) || 0;
      const primerPago = $('#primer_pago').val() || '—';
      $('#conf_pago_semanal').text(pagoSemanal.toFixed(2));
      $('#conf_primer_pago').text(primerPago);
      $('#li_pago_semanal').removeClass('d-none');
      $('#li_primer_pago').removeClass('d-none');

      const finan = $('#financiera').val() || '—';
      $('#conf_financiera').text(finan);
      $('#li_financiera').removeClass('d-none');

      const tag = normalizarTAG($('#tag').val() || '');
      $('#tag').val(tag);

      if (tag) { $('#conf_tag').text(tag); $('#li_tag').removeClass('d-none'); }
      else { $('#li_tag').addClass('d-none'); }
    } else {
      $('#li_enganche, #li_pago_semanal, #li_primer_pago, #li_financiera, #li_tag').addClass('d-none');
    }
  }

  $('#form_venta').on('submit', function(e){
    if ($('#form_venta').attr('data-locked') === '1') {
      e.preventDefault();
      $('html, body').animate({ scrollTop: 0 }, 300);
      return;
    }
    if (permitSubmit) return;

    e.preventDefault();
    const errores = validarFormulario();
    if (errores.length > 0) {
      $('#errores').removeClass('d-none')
        .html('<strong>Corrige lo siguiente:</strong><ul class="mb-0"><li>' + errores.join('</li><li>') + '</li></ul>');
      window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }

    $('#errores').addClass('d-none').empty();
    poblarModal();

    $('#password_confirm').val('');
    $('#btn_confirmar_envio').prop('disabled', false)
      .html('<i class="bi bi-send-check me-1"></i> Confirmar y enviar');

    modalConfirm.show();
  });

  $('#btn_confirmar_envio').on('click', function(){
    const pwd = $('#password_confirm').val().trim();
    if (!pwd) { alert('Para confirmar la venta, escribe tu contraseña.'); $('#password_confirm').focus(); return; }

    $('#btn_confirmar_envio').prop('disabled', true).text('Confirmando...');
    $('#btn_submit').prop('disabled', true).text('Enviando...');

    permitSubmit = true;
    modalConfirm.hide();
    $('#form_venta')[0].submit();
  });

  function initEquipos() {
    cargarEquipos = function(sucursalId) {
      $.ajax({
        url: 'ajax_productos_por_sucursal.php',
        method: 'POST',
        data: { id_sucursal: sucursalId },
        success: function(response) {
          $('#equipo1, #equipo2').html(response).val('').trigger('change');

          resetTodosLosCupones();
          resetPromoRegaloUI();
          resetPromoDescuentoUI();

          refreshEquipoLocks();
          recalcPrecioVenta();
        },
        error: function(xhr) {
          const msg = xhr.responseText || 'Error cargando inventario';
          $('#equipo1, #equipo2').html('<option value="">' + msg + '</option>').trigger('change');

          resetTodosLosCupones();
          resetPromoRegaloUI();
          resetPromoDescuentoUI();

          refreshEquipoLocks();
          recalcPrecioVenta();
        }
      });
    };

    cargarEquipos($('#id_sucursal').val());
    refreshEquipoLocks();
    recalcPrecioVenta();
  }
  initEquipos();

  actualizarBloqueoPrecio();
  limpiarCliente();

  $('#equipo1').on('change', function() {
    const idInv = $('#equipo1').val();

    resetCuponPrincipal();
    resetPromoRegaloUI();
    resetPromoDescuentoUI();

    if (!idInv) {
      refreshEquipoLocks();
      recalcPrecioVenta();
      return;
    }

    const nombreEquipo = obtenerLabelEquipo($('#equipo1'));

    consultarCuponEquipo(idInv, 'principal', nombreEquipo);

    if (BLOQUEAR_PROMOS) {
      resetPromoRegaloUI();
      resetPromoDescuentoUI();
      refreshEquipoLocks();
      recalcPrecioVenta();
      return;
    }

    $.ajax({
      url: 'ajax_promo_regalo_check.php',
      method: 'POST',
      dataType: 'json',
      data: { id_inventario: idInv },
      success: function(res) {
        if (res && res.ok && res.aplica && res.promo_id) {
          promoRegaloAplica = true;
          promoRegaloId = parseInt(res.promo_id, 10);
          promoRegaloNombre = res.nombre || 'Promo';

          $('#promo_regalo_nombre').text(promoRegaloNombre);
          $('#wrap_promo_regalo').removeClass('d-none');
        } else {
          resetPromoRegaloUI();
        }
      },
      error: function() { resetPromoRegaloUI(); }
    });

    $.ajax({
      url: 'ajax_promo_descuento_check.php',
      method: 'POST',
      dataType: 'json',
      data: { id_inventario: idInv },
      success: function(res) {
        if (res && res.ok && res.aplica && res.promo && res.promo.id) {
          promoDescAplica = true;
          promoDesc = {
            id: parseInt(res.promo.id, 10),
            nombre: res.promo.nombre || 'Promo',
            modo: res.promo.modo || '',
            porcentaje_descuento: parseFloat(res.promo.porcentaje_descuento || 50),
            permite_combo: parseInt(res.promo.permite_combo || 0, 10),
            permite_doble_venta: parseInt(res.promo.permite_doble_venta || 0, 10)
          };

          $('#promo_descuento_nombre').text(promoDesc.nombre);
          $('#promo_descuento_porc_badge').text((promoDesc.porcentaje_descuento || 50).toFixed(0) + '%');
          $('#wrap_promo_descuento').removeClass('d-none');

          if (promoDesc.permite_combo === 1) $('#row_chk_desc_combo').removeClass('d-none');
          else {
            $('#row_chk_desc_combo').addClass('d-none');
            $('#chk_desc_combo').prop('checked', false);
            descComboOn = false;
          }

          if (promoDesc.permite_doble_venta === 1) $('#row_btn_desc_doble').removeClass('d-none');
          else $('#row_btn_desc_doble').addClass('d-none');

          $('#promo_descuento_aplicado').val('0');
          $('#promo_descuento_id').val('0');
          $('#promo_descuento_modo').val('');
          $('#promo_descuento_tag_origen').val('');
        } else {
          resetPromoDescuentoUI();
        }
      },
      error: function() { resetPromoDescuentoUI(); }
    });

    refreshEquipoLocks();
    recalcPrecioVenta();
  });

  $('#equipo2').on('change', function(){
    const idInv = $('#equipo2').val();

    resetCuponCombo();

    if (!idInv) {
      refreshEquipoLocks();
      recalcPrecioVenta();
      return;
    }

    const nombreEquipo = obtenerLabelEquipo($('#equipo2'));
    consultarCuponEquipo(idInv, 'combo', nombreEquipo);

    refreshEquipoLocks();
    recalcPrecioVenta();
  });

  $('#equipo2').on('select2:select', function(e){
    const v1 = $('#equipo1').val();
    const elegido = e.params.data.id;
    if (v1 && elegido === v1) $(this).val(null).trigger('change');
    refreshEquipoLocks();
    recalcPrecioVenta();
  });

  $('#equipo1').on('select2:select', function(){
    refreshEquipoLocks();
    recalcPrecioVenta();
  });

  $('#btn_aplicar_cupon').on('click', function(){
    if (cuponTargetActual === 'principal' && cuponPrincipalDisponible > 0) {
      cuponPrincipalAplicado = true;
    } else if (cuponTargetActual === 'combo' && cuponComboDisponible > 0) {
      cuponComboAplicado = true;
    }

    actualizarInfoCupon();
    recalcPrecioVenta();
    modalCupon.hide();
  });

  $('#btn_no_aplicar_cupon').on('click', function(){
    if (cuponTargetActual === 'principal') {
      cuponPrincipalAplicado = false;
    } else if (cuponTargetActual === 'combo') {
      cuponComboAplicado = false;
    }

    actualizarInfoCupon();
    recalcPrecioVenta();
  });

  if (BLOQUEAR_PROMOS) {
    resetPromoRegaloUI();
    resetPromoDescuentoUI();
  }
});
</script>

</body>
</html>