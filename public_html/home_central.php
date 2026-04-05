<?php
// home_central.php — Home / Índice touch de Central 2.0 (REDISEÑO CLARO)
// - Menú touch principal tipo "launcher"
// - Favoritos en carrusel horizontal
// - Secciones con accordion
// - Búsqueda + chips
// - Reorden según navbar (sin Operativos)

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');

$rolUsuario    = $_SESSION['rol'] ?? 'Ejecutivo';
$nombreUsuario = trim($_SESSION['nombre'] ?? 'Usuario');
$idUsuario     = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal    = (int)($_SESSION['id_sucursal'] ?? 0);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fexists($file){ return file_exists(__DIR__ . '/' . ltrim($file, '/')); }

/* ===== Badges ===== */
$badgeEquip = 0;
$badgeSims  = 0;

if ($idSucursal > 0) {
  if ($st = $conn->prepare("SELECT COUNT(*) FROM traspasos WHERE id_sucursal_destino=? AND estatus='Pendiente'")) {
    $st->bind_param("i", $idSucursal);
    $st->execute();
    $st->bind_result($badgeEquip);
    $st->fetch();
    $st->close();
  }
  if ($st = $conn->prepare("SELECT COUNT(*) FROM traspasos_sims WHERE id_sucursal_destino=? AND estatus='Pendiente'")) {
    $st->bind_param("i", $idSucursal);
    $st->execute();
    $st->bind_result($badgeSims);
    $st->fetch();
    $st->close();
  }
}

$esAdmin = in_array($rolUsuario, ['Admin','Super'], true);
$esLog   = ($rolUsuario === 'Logistica');
$esGZ    = ($rolUsuario === 'GerenteZona');

$tiles = [];

$add = function($id, $title, $subtitle, $href, $icon, $group, $show=true, $badge=0, $tags=[]) use (&$tiles) {
  $tiles[] = [
    'id'      => $id,
    'title'   => $title,
    'subtitle'=> $subtitle,
    'href'    => $href,
    'icon'    => $icon,
    'group'   => $group,
    'show'    => (bool)$show,
    'badge'   => (int)$badge,
    'tags'    => implode(' ', $tags),
  ];
};

/* ==========================
   FAVORITOS (touch row)
========================== */
if (in_array($rolUsuario, ['Ejecutivo','Gerente'], true)) {
  $add('fav_venta','Venta equipos','Captura rápida','nueva_venta.php','bi-phone','Favoritos', true, 0, ['ventas','equipos','captura']);
  $add('fav_hist','Historial','Ventas y filtros','historial_ventas.php','bi-receipt-cutoff','Favoritos', true, 0, ['historial','ventas']);
  $add('fav_inv','Inventario sucursal','Disponible tienda','panel.php','bi-box-seam','Favoritos', true, 0, ['inventario','sucursal']);
  $add('fav_nom','Mi nómina','Semana Mar→Lun','nomina_mi_semana_v2.php','bi-cash-stack','Favoritos', true, 0, ['nomina']);
} else {
  $add('fav_dash','Dashboard semanal','Vista central','dashboard_unificado.php','bi-speedometer2','Favoritos', true, 0, ['dashboard']);
  if ($esAdmin) $add('fav_rh','Reporte nómina','Admin / RH','reporte_nomina_v2.php','bi-people','Favoritos', true, 0, ['rh','nomina']);
  if ($esAdmin || $esLog) $add('fav_tickets','Tickets Central','Operación','tickets_nuevo_luga.php','bi-ticket-detailed','Favoritos', true, 0, ['tickets','operacion']);
}

/* ==========================
   DASHBOARD
========================== */
$add('dash_diario','Dashboard diario','Productividad del día','productividad_dia.php','bi-graph-up','Dashboard', true, 0, ['dashboard','diario']);
$add('dash_semanal','Dashboard semanal','Mar→Lun','dashboard_unificado.php','bi-speedometer2','Dashboard', true, 0, ['dashboard','semanal']);
$add('dash_mensual','Dashboard mensual','Cumplimiento mensual','dashboard_mensual.php','bi-calendar3','Dashboard', true, 0, ['dashboard','mensual']);

/* ==========================
   VENTAS (incluye Recargas Promo)
========================== */
if (!$esLog) {
  $add('v_equipos','Venta equipos','Nueva venta de equipo','nueva_venta.php','bi-bag-check','Ventas', true, 0, ['venta','equipos']);
  $add('v_prepago','Venta SIM prepago','Captura SIM prepago','venta_sim_prepago.php','bi-sim','Ventas', true, 0, ['venta','sim','prepago']);
  $add('v_pospago','Venta SIM pospago','Captura SIM pospago','venta_sim_pospago.php','bi-sim-fill','Ventas', true, 0, ['venta','sim','pospago']);
  $add('v_payjoy','PayJoy TC','Nueva venta TC','payjoy_tc_nueva.php','bi-credit-card-2-front','Ventas', true, 0, ['payjoy','tc']);
  $add('v_acc','Venta accesorios','Accesorios','venta_accesorios.php','bi-bag-plus','Ventas', true, 0, ['venta','accesorios']);

  // ✅ Movido desde Operación → Ventas
  $add('v_recargas','Recargas Promo','Portal recargas','recargas_portal.php','bi-lightning','Ventas', fexists('recargas_portal.php'), 0, ['recargas','promo','ventas']);
}

$add('h_ventas','Historial ventas','Filtros y export','historial_ventas.php','bi-clock-history','Ventas', true, 0, ['historial','ventas']);
$add('h_sims','Historial SIMs','Prepago/Pospago','historial_ventas_sims.php','bi-clock','Ventas', true, 0, ['historial','sims']);
$add('h_payjoy','Historial PayJoy TC','Reporte y export','historial_payjoy_tc.php','bi-journal-text','Ventas', true, 0, ['historial','payjoy']);
$add('h_acc','Historial accesorios','Reporte','historial_ventas_accesorios.php','bi-list-check','Ventas', true, 0, ['historial','accesorios']);

/* ==========================
   INVENTARIO (incluye Lista de precios)
========================== */
if (in_array($rolUsuario, ['Ejecutivo','Gerente'], true)) {
  $add('inv_suc','Inventario sucursal','Tu tienda','panel.php','bi-box-seam','Inventario', true, 0, ['inventario','sucursal']);
  $add('inv_res','Resumen global','Modelos / sucursales','inventario_resumen.php','bi-diagram-3','Inventario', true, 0, ['inventario','resumen']);
}
if (in_array($rolUsuario, ['Gerente','Admin','Logistica'], true)) {
  $add('inv_sims','Inventario SIMs','Resumen por operador','inventario_sims_resumen.php','bi-sd-card','Inventario', true, 0, ['inventario','sims']);
}
if (in_array($rolUsuario, ['Admin','GerenteZona','Super'], true)) {
  $add('inv_global','Inventario global','Vista completa','inventario_global.php','bi-globe','Inventario', true, 0, ['inventario','global']);
}
if ($esAdmin) {
  $add('inv_eul','Inventario Eulalia','Almacén','inventario_eulalia.php','bi-building','Inventario', true, 0, ['eulalia']);
  $add('inv_ret','Retiros inventario','Control admin','inventario_retiros.php','bi-exclamation-octagon','Inventario', true, 0, ['retiros']);
}

// ✅ Movido desde Operación → Inventario
$add('inv_precios','Lista de precios','Consulta por modelo','lista_precios.php','bi-tags','Inventario', fexists('lista_precios.php'), 0, ['precios','lista','inventario']);

/* ==========================
   TRASPASOS
========================== */
$puedeTraspasos = in_array($rolUsuario, ['Gerente','GerenteSucursal','Admin','Super'], true) || ($rolUsuario === 'Ejecutivo');
$add('t_pend','Traspasos entrantes','Pendientes equipos','traspasos_pendientes.php','bi-arrow-left-right','Traspasos', $puedeTraspasos, $badgeEquip, ['traspasos','pendientes','equipos']);
$add('t_sim_pend','SIMs pendientes','Pendientes SIM','traspasos_sims_pendientes.php','bi-sim','Traspasos', $puedeTraspasos, $badgeSims, ['traspasos','sims','pendientes']);
$add('t_sim_sal','SIMs salientes','Historial salidas','traspasos_sims_salientes.php','bi-box-arrow-up-right','Traspasos', $puedeTraspasos, 0, ['traspasos','sims']);
$add('t_sal','Traspasos salientes','Historial','traspasos_salientes.php','bi-box-arrow-right','Traspasos', $puedeTraspasos, 0, ['traspasos','salientes']);
$add('t_gen_sims','Generar traspaso SIMs','Enviar a sucursal','generar_traspaso_sims.php','bi-send','Traspasos', $puedeTraspasos, 0, ['traspasos','generar','sims']);
if ($esAdmin) $add('t_gen_eul','Traspaso desde Eulalia','Admin','generar_traspaso.php','bi-truck','Traspasos', true, 0, ['traspasos','eulalia']);

/* ==========================
   OPERACIÓN (sin Operativos)
   - Panel Operador
   - Administrar insumos
   - Tickets Central
   - Tickets Admin
   - Tickets Mantenimiento (rename de "Administrar solicitudes", solo si existe)
========================== */
if (in_array($rolUsuario, ['Ejecutivo','Gerente'], true)) {
  $add('op_pros','Prospectos','Seguimiento','prospectos.php','bi-person-lines-fill','Operación', fexists('prospectos.php'), 0, ['prospectos','operacion']);
  $add('op_nom','Mi nómina','Semana','nomina_mi_semana_v2.php','bi-cash-coin','Operación', true, 0, ['nomina','operacion']);
}
if ($esAdmin || $esLog) {
  $add('op_panelop','Panel Operador','Operación central','panel_operador.php','bi-person-gear','Operación', fexists('panel_operador.php'), 0, ['operador','operacion']);
  // Administrar insumos (según tu menú: se queda)
  $add('op_insumos_admin','Administrar insumos','Control y reglas','insumos_limites_admin.php','bi-box2-heart','Operación', fexists('insumos_limites_admin.php'), 0, ['insumos','admin','operacion']);
}

// Tickets Central (ya lo tenías)
if ($esAdmin || $esLog) {
  $add('op_tickets_central','Tickets Central','Soporte','tickets_nuevo_luga.php','bi-ticket-detailed','Operación', fexists('tickets_nuevo_luga.php'), 0, ['tickets','central','operacion']);
  // Tickets Admin (no sé tu archivo exacto, lo detecto)
  $ticketsAdminFile = null;
  foreach (['tickets_admin.php','tickets_admin_luga.php','tickets_admin_central.php'] as $cand) {
    if (fexists($cand)) { $ticketsAdminFile = $cand; break; }
  }
  $add('op_tickets_admin','Tickets Admin','Backoffice',''.($ticketsAdminFile ?: '#').'','bi-shield-lock','Operación', (bool)$ticketsAdminFile, 0, ['tickets','admin','operacion']);

  // Tickets Mantenimiento (renombre de "Administrar solicitudes")
  $mantFile = null;
  foreach (['tickets_mantenimiento.php','solicitudes_admin.php','admin_solicitudes.php'] as $cand) {
    if (fexists($cand)) { $mantFile = $cand; break; }
  }
  $add('op_tickets_mant','Tickets Mantenimiento','Solicitudes',''.($mantFile ?: '#').'','bi-tools','Operación', (bool)$mantFile, 0, ['tickets','mantenimiento','operacion']);
}

/* ==========================
   RH (incluye Celebraciones + Gestionar usuarios movido aquí)
========================== */
if ($esAdmin) {
  $add('rh_nom','Reporte nómina','Semana Mar→Lun','reporte_nomina_v2.php','bi-people','RH', true, 0, ['rh','nomina']);
  $add('rh_asist','Asistencias (Admin)','Matriz','admin_asistencias.php','bi-calendar2-check','RH', fexists('admin_asistencias.php'), 0, ['rh','asistencias']);
  $add('rh_exp','Expedientes','Panel','admin_expedientes.php','bi-folder2-open','RH', fexists('admin_expedientes.php'), 0, ['rh','expedientes']);

  // Gestionar usuarios movido a RH
  $add('rh_users','Gestionar usuarios','Alta y gestión','gestionar_usuarios.php','bi-person-badge','RH', fexists('gestionar_usuarios.php'), 0, ['usuarios','rh']);

  // Celebraciones movido a RH (detecta nombre)
  $celeFile = null;
  foreach (['celebraciones.php','cumpleanios_aniversarios.php','cumples.php'] as $cand) {
    if (fexists($cand)) { $celeFile = $cand; break; }
  }
  $add('rh_cele','Celebraciones','Cumpleaños y aniversarios',''.($celeFile ?: '#').'','bi-balloon','RH', (bool)$celeFile, 0, ['celebraciones','rh']);
}

/* ==========================
   ADMIN (catálogos y compras)
========================== */
if ($esAdmin) {
  $add('adm_compras','Compras','Facturas y entradas','compras_resumen.php','bi-cart-check','Admin', fexists('compras_resumen.php'), 0, ['compras']);
  $add('adm_modelos','Modelos','Catálogo','modelos.php','bi-collection','Admin', fexists('modelos.php'), 0, ['modelos']);
  $add('adm_prov','Proveedores','Catálogo','proveedores.php','bi-truck-flatbed','Admin', fexists('proveedores.php'), 0, ['proveedores']);
}

/* ==========================
   Agrupar visibles
========================== */
$groups = [];
foreach ($tiles as $t) {
  if (empty($t['show'])) continue;
  $groups[$t['group']][] = $t;
}

// Orden final (sin Operativos)
$order = ['Favoritos','Dashboard','Ventas','Inventario','Traspasos','Operación','RH','Admin'];

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Central 2.0 · Inicio</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    :root{
      --bg: #f6f8fb;
      --card: #ffffff;
      --text: #111827;
      --muted: rgba(17,24,39,.68);
      --border: rgba(17,24,39,.10);
      --shadow: 0 10px 26px rgba(17,24,39,.08);
      --shadow2: 0 6px 18px rgba(17,24,39,.06);
      --pri: #0d6efd;
      --priSoft: rgba(13,110,253,.10);
      --dangerSoft: rgba(220,53,69,.10);
      --danger: #b42318;
      --radius: 18px;
    }

    body{
      background:
        radial-gradient(900px 420px at 10% -10%, rgba(13,110,253,.10), rgba(255,255,255,0)),
        radial-gradient(900px 420px at 90% 0%, rgba(25,135,84,.08), rgba(255,255,255,0)),
        var(--bg);
      color: var(--text);
    }

    .page-wrap{ padding: 18px 14px 46px; max-width: 1200px; }
    .home-title{ font-weight: 900; letter-spacing:.2px; margin-bottom:.2rem; }
    .home-sub{ color: rgba(17,24,39,.68); }

    .searchbox .form-control{
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: .95rem 1rem;
      box-shadow: var(--shadow2);
    }
    .searchbox .form-control:focus{
      box-shadow: 0 0 0 .2rem rgba(13,110,253,.14), var(--shadow2);
      border-color: rgba(13,110,253,.35);
    }

    .chips{
      display:flex;
      gap: .5rem;
      flex-wrap: wrap;
      margin-top: .75rem;
    }
    .chip{
      border: 1px solid var(--border);
      background: rgba(255,255,255,.7);
      backdrop-filter: blur(6px);
      border-radius: 999px;
      padding: .38rem .72rem;
      font-weight: 700;
      font-size: .86rem;
      color: rgba(17,24,39,.78);
      cursor:pointer;
      user-select:none;
      transition: transform .08s ease, border-color .12s ease;
    }
    .chip:hover{ transform: translateY(-1px); border-color: rgba(13,110,253,.25); }
    .chip.active{
      border-color: rgba(13,110,253,.35);
      background: rgba(13,110,253,.10);
      color: rgba(13,110,253,.95);
    }

    /* Favoritos row (launcher) */
    .fav-row{
      margin-top: 14px;
      display:flex;
      gap: 12px;
      overflow-x: auto;
      padding-bottom: 6px;
      scroll-snap-type: x mandatory;
    }
    .fav-row::-webkit-scrollbar{ height: 8px; }
    .fav-row::-webkit-scrollbar-thumb{ background: rgba(17,24,39,.12); border-radius: 999px; }

    .fav-tile{
      min-width: 220px;
      max-width: 240px;
      scroll-snap-align: start;
      display:block;
      text-decoration:none;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 14px 14px;
      box-shadow: var(--shadow2);
      position:relative;
      transition: transform .08s ease, box-shadow .12s ease, border-color .12s ease;
    }
    .fav-tile:hover{
      transform: translateY(-1px);
      box-shadow: var(--shadow);
      border-color: rgba(13,110,253,.20);
    }

    .tile{
      display:block;
      text-decoration:none;
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 14px 14px;
      box-shadow: var(--shadow2);
      min-height: 96px;
      position:relative;
      transition: transform .08s ease, box-shadow .12s ease, border-color .12s ease;
    }
    .tile:hover{
      transform: translateY(-1px);
      box-shadow: var(--shadow);
      border-color: rgba(13,110,253,.20);
    }

    .icon{
      width: 44px;
      height: 44px;
      border-radius: 14px;
      display:flex;
      align-items:center;
      justify-content:center;
      background: var(--priSoft);
      border: 1px solid rgba(13,110,253,.18);
      flex: 0 0 auto;
    }
    .icon i{ font-size: 1.25rem; color: var(--pri); }

    .t-title{
      font-weight: 900;
      margin:0;
      color: var(--text);
      line-height: 1.1;
    }
    .t-sub{
      margin:0;
      margin-top:4px;
      color: rgba(17,24,39,.68);
      font-size: .88rem;
      line-height: 1.2;
    }

    .badge-float{
      position:absolute;
      top:10px;
      right:10px;
      font-weight:900;
      border-radius: 999px;
      padding: .28rem .58rem;
      font-size: .78rem;
      background: var(--dangerSoft);
      border: 1px solid rgba(220,53,69,.25);
      color: var(--danger);
    }

    .section-head{
      display:flex;
      align-items:center;
      justify-content: space-between;
      gap: 12px;
      padding: 14px 14px;
      border-radius: 16px;
      border: 1px solid var(--border);
      background: rgba(255,255,255,.70);
      box-shadow: var(--shadow2);
    }
    .section-title{
      font-weight: 900;
      margin:0;
    }
    .section-meta{
      color: rgba(17,24,39,.62);
      font-size: .88rem;
      font-weight: 700;
    }

    .accordion-button{
      background: transparent !important;
      box-shadow: none !important;
      padding: 0;
    }
    .accordion-item{
      background: transparent;
      border: none;
      margin-top: 12px;
    }
    .accordion-body{
      padding: 12px 0 0 0;
    }

    @media (min-width: 576px){
      .tile{ min-height: 104px; }
    }
  </style>
</head>
<body>

<div class="page-wrap container-fluid">
  <div class="d-flex flex-wrap align-items-end justify-content-between gap-2">
    <div>
      <h3 class="home-title mb-1">Inicio</h3>
      <div class="home-sub">Accesos rápidos para <strong><?= h($rolUsuario) ?></strong></div>
    </div>
    <div class="text-end small home-sub">
      <?= h($nombreUsuario) ?>
    </div>
  </div>

  <div class="searchbox mt-3">
    <input id="q" type="search" class="form-control" placeholder="Buscar: ventas, traspasos, nómina, inventario, tickets…">
    <div class="chips" id="chips">
      <span class="chip" data-chip="ventas">Ventas</span>
      <span class="chip" data-chip="inventario">Inventario</span>
      <span class="chip" data-chip="traspasos">Traspasos</span>
      <span class="chip" data-chip="tickets">Tickets</span>
      <span class="chip" data-chip="nomina">Nómina</span>
      <span class="chip" data-chip="dashboard">Dashboard</span>
    </div>
  </div>

  <?php if (!empty($groups['Favoritos'])): ?>
    <div class="mt-3 d-flex align-items-center justify-content-between">
      <div class="fw-bold" style="letter-spacing:.2px;">Favoritos</div>
      <div class="small" style="color:rgba(17,24,39,.55); font-weight:700;">desliza →</div>
    </div>

    <div class="fav-row" data-group="Favoritos">
      <?php foreach ($groups['Favoritos'] as $t): ?>
        <?php $badge = (int)$t['badge']; ?>
        <a class="fav-tile tile-wrap"
           href="<?= h($t['href']) ?>"
           data-search="<?= h(mb_strtolower($t['title'].' '.$t['subtitle'].' '.$t['tags'].' '.$t['group'], 'UTF-8')) ?>">
          <?php if ($badge > 0): ?><span class="badge-float"><?= $badge ?></span><?php endif; ?>
          <div class="d-flex gap-3 align-items-start">
            <div class="icon"><i class="bi <?= h($t['icon']) ?>"></i></div>
            <div>
              <p class="t-title"><?= h($t['title']) ?></p>
              <p class="t-sub"><?= h($t['subtitle']) ?></p>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="accordion" id="accMain">
    <?php
      $secIndex = 0;
      foreach ($order as $g):
        if ($g === 'Favoritos') continue;
        if (empty($groups[$g])) continue;
        $secIndex++;
        $collapseId = 'c_'.$secIndex;
        $headingId  = 'h_'.$secIndex;

        // Conteo visible
        $count = count($groups[$g]);
        // Auto-open: Dashboard y Ventas un poquito más arriba (pero sin forzar demasiado)
        $openByDefault = in_array($g, ['Dashboard','Ventas'], true);
    ?>
    <div class="accordion-item" data-group="<?= h($g) ?>">
      <h2 class="accordion-header" id="<?= h($headingId) ?>">
        <button class="accordion-button <?= $openByDefault ? '' : 'collapsed' ?>" type="button"
                data-bs-toggle="collapse" data-bs-target="#<?= h($collapseId) ?>"
                aria-expanded="<?= $openByDefault ? 'true' : 'false' ?>" aria-controls="<?= h($collapseId) ?>">
          <div class="section-head w-100">
            <div>
              <p class="section-title mb-0"><?= h($g) ?></p>
              <div class="section-meta"><?= $count ?> opción<?= $count === 1 ? '' : 'es' ?></div>
            </div>
            <i class="bi bi-chevron-down" style="font-size:1.05rem; color:rgba(17,24,39,.65)"></i>
          </div>
        </button>
      </h2>

      <div id="<?= h($collapseId) ?>" class="accordion-collapse collapse <?= $openByDefault ? 'show' : '' ?>"
           aria-labelledby="<?= h($headingId) ?>" data-bs-parent="#accMain">
        <div class="accordion-body">
          <div class="row g-3">
            <?php foreach ($groups[$g] as $t): ?>
              <?php $badge = (int)$t['badge']; ?>
              <div class="col-12 col-sm-6 col-lg-3 tile-wrap"
                   data-search="<?= h(mb_strtolower($t['title'].' '.$t['subtitle'].' '.$t['tags'].' '.$t['group'], 'UTF-8')) ?>">
                <a class="tile" href="<?= h($t['href']) ?>">
                  <?php if ($badge > 0): ?><span class="badge-float"><?= $badge ?></span><?php endif; ?>
                  <div class="d-flex gap-3 align-items-start">
                    <div class="icon"><i class="bi <?= h($t['icon']) ?>"></i></div>
                    <div>
                      <p class="t-title"><?= h($t['title']) ?></p>
                      <p class="t-sub"><?= h($t['subtitle']) ?></p>
                    </div>
                  </div>
                </a>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="mt-4 small text-center" style="color:rgba(17,24,39,.55); font-weight:700;">
    Central 2.0 · Home touch (tema claro)
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const q = document.getElementById('q');
  const items = Array.from(document.querySelectorAll('.tile-wrap'));
  const chips = Array.from(document.querySelectorAll('.chip'));

  function setChipActive(chip, on){
    if (!chip) return;
    chip.classList.toggle('active', !!on);
  }

  function getActiveChip(){
    const c = chips.find(x => x.classList.contains('active'));
    return c ? (c.getAttribute('data-chip') || '') : '';
  }

  function apply(){
    const term = (q.value || '').trim().toLowerCase();
    const chip = getActiveChip();

    // Filtrar tiles
    items.forEach(el => {
      const hay = (el.getAttribute('data-search') || '');
      const okTerm = !term || hay.includes(term);
      const okChip = !chip || hay.includes(chip);
      el.style.display = (okTerm && okChip) ? '' : 'none';
    });

    // Ocultar secciones vacías (accordion-item completo)
    document.querySelectorAll('.accordion-item').forEach(sec => {
      const visible = Array.from(sec.querySelectorAll('.tile-wrap')).some(x => x.style.display !== 'none');
      sec.style.display = visible ? '' : 'none';
    });

    // Favoritos row: si queda vacío, ocultarlo
    const favRow = document.querySelector('.fav-row');
    if (favRow) {
      const anyFav = Array.from(favRow.querySelectorAll('.tile-wrap')).some(x => x.style.display !== 'none');
      favRow.style.display = anyFav ? '' : 'none';
      const favHeader = favRow.previousElementSibling; // el header "Favoritos"
      if (favHeader) favHeader.style.display = anyFav ? '' : 'none';
    }
  }

  q.addEventListener('input', apply);

  chips.forEach(ch => {
    ch.addEventListener('click', () => {
      const isOn = ch.classList.contains('active');
      chips.forEach(x => setChipActive(x, false));
      setChipActive(ch, !isOn);
      apply();
    });
  });

  apply();
})();
</script>

</body>
</html>