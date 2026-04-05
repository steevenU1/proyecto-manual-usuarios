<?php
/* panel_operador.php
   - Tablero organizado con buscador, filtros y categorías
   - Muestra/oculta o deshabilita botones según rol
*/

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once 'db.php';
include 'navbar.php';

$rol        = $_SESSION['rol'] ?? '';
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$nombre     = $_SESSION['nombre'] ?? 'Usuario';

/* =========================================================
   Herramientas
   category = grupo visual
========================================================= */
$TOOLS = [
  [
    'key'        => 'kardex',
    'title'      => 'Kardex',
    'desc'       => 'Ver kardex de productos.',
    'href'       => 'kardex.php',
    'icon'       => 'bi-clipboard-data',
    'roles'      => ['Admin', 'Logistica'],
    'category'   => 'Inventario',
  ],
  [
    'key'        => 'nuevo_producto',
    'title'      => 'Nuevo Producto',
    'desc'       => 'Ingresa producto a inventario sin ingresar por compras.',
    'href'       => 'nuevo_producto.php',
    'icon'       => 'bi-box-seam',
    'roles'      => ['Admin', 'Logistica'],
    'category'   => 'Inventario',
  ],
  [
    'key'        => 'inventario_retiros',
    'title'      => 'Retirar inventario',
    'desc'       => 'Retira inventario colocando status "retirado".',
    'href'       => 'inventario_retiros.php',
    'icon'       => 'bi-box-arrow-up',
    'roles'      => ['Logistica'],
    'category'   => 'Inventario',
  ],
  [
    'key'        => 'retiro_sims',
    'title'      => 'Retiro de SIMs',
    'desc'       => 'Panel de retiro de SIMs por caja, unidad o lote.',
    'href'       => 'retiro_sims.php',
    'icon'       => 'bi-sim',
    'roles'      => ['Admin'],
    'category'   => 'Inventario',
  ],
  [
    'key'        => 'crear_codigo',
    'title'      => 'Crear Código Logística',
    'desc'       => 'Creación de códigos de productos.',
    'href'       => 'catalogo_modelos_admin.php',
    'icon'       => 'bi-upc-scan',
    'roles'      => ['Logistica'],
    'category'   => 'Inventario',
  ],
  [
    'key'        => 'corregir_codigo',
    'title'      => 'Corregir código',
    'desc'       => 'Corrección de códigos de productos por IMEI.',
    'href'       => 'productos_corregir_codigo.php',
    'icon'       => 'bi-qr-code-scan',
    'roles'      => ['Logistica', 'Admin'],
    'category'   => 'Inventario',
  ],
  [
    'key'        => 'actualizar_precios',
    'title'      => 'Actualizar Precios',
    'desc'       => 'Actualizar precios de lista por código.',
    'href'       => 'actualizar_precios_modelo.php',
    'icon'       => 'bi-tags',
    'roles'      => ['Logistica'],
    'category'   => 'Precios y Promos',
  ],
  [
    'key'        => 'comisiones_especiales',
    'title'      => 'Ajustar incentivos',
    'desc'       => 'Ajustar comisiones especiales por modelos.',
    'href'       => 'comisiones_especiales_equipos.php',
    'icon'       => 'bi-cash-stack',
    'roles'      => ['Admin', 'Logistica'],
    'category'   => 'Precios y Promos',
  ],
  [
    'key'        => 'promocional_recargas',
    'title'      => 'Crear Promoción Recargas',
    'desc'       => 'Generar promociones para portal de recargas promocionales.',
    'href'       => 'recargas_admin.php',
    'icon'       => 'bi-megaphone',
    'roles'      => ['Logistica', 'Admin'],
    'category'   => 'Precios y Promos',
  ],
  [
    'key'        => 'cupones_equipos',
    'title'      => 'Crear cupones por código',
    'desc'       => 'Generar cupones de descuento por códigos.',
    'href'       => 'cupones_descuento_admin.php',
    'icon'       => 'bi-ticket-perforated',
    'roles'      => ['Logistica', 'Admin'],
    'category'   => 'Precios y Promos',
  ],
  [
    'key'        => 'promo_2do_descuento',
    'title'      => 'Promo 2do Descuento',
    'desc'       => 'Configurar promociones de segundo descuento.',
    'href'       => 'promos_equipos_descuento_admin.php',
    'icon'       => 'bi-percent',
    'roles'      => ['Admin', 'Logistica'],
    'category'   => 'Precios y Promos',
  ],
  [
    'key'        => 'editar_cobros',
    'title'      => 'Editar Cobros',
    'desc'       => 'Corrige cobros siempre que no estén ligados a un corte.',
    'href'       => 'cobros_admin_editar.php',
    'icon'       => 'bi-cash-coin',
    'roles'      => ['Admin'],
    'category'   => 'Operación financiera',
  ],
  [
    'key'        => 'editar_cortes',
    'title'      => 'Editar Cortes de Caja',
    'desc'       => 'Ajusta totales, fechas y elimina cortes liberando sus cobros.',
    'href'       => 'cortes_admin_editar.php',
    'icon'       => 'bi-receipt-cutoff',
    'roles'      => ['Admin'],
    'category'   => 'Operación financiera',
  ],
  [
    'key'        => 'horarios_carga_rapida',
    'title'      => 'Horarios Sucursal',
    'desc'       => 'Corrige horarios de sucursales.',
    'href'       => 'horarios_carga_rapida.php',
    'icon'       => 'bi-calendar-week',
    'roles'      => ['Admin'],
    'category'   => 'Personal y sucursales',
  ],
  [
    'key'        => 'asistencias_editar',
    'title'      => 'Editar Asistencias',
    'desc'       => 'Editar asistencias.',
    'href'       => 'asistencias_admin_editar.php',
    'icon'       => 'bi-person-check',
    'roles'      => ['Admin'],
    'category'   => 'Personal y sucursales',
  ],
  [
    'key'        => 'alta_sucursal',
    'title'      => 'Alta sucursal',
    'desc'       => 'Crear nueva sucursal.',
    'href'       => 'alta_sucursal.php',
    'icon'       => 'bi-shop',
    'roles'      => ['Admin'],
    'category'   => 'Personal y sucursales',
  ],
  [
    'key'        => 'traspasos_resumen',
    'title'      => 'Traspasos Resumen',
    'desc'       => 'Resumen en vivo de traspasos en tránsito.',
    'href'       => 'traspasos_en_transito.php',
    'icon'       => 'bi-truck',
    'roles'      => ['Logistica', 'Admin'],
    'category'   => 'Logística',
  ],
  [
    'key'        => 'insumos_catalogo',
    'title'      => 'Catálogo de insumos',
    'desc'       => 'Gestión de catálogo de insumos.',
    'href'       => 'insumos_catalogo.php',
    'icon'       => 'bi-box2-heart',
    'roles'      => ['Logistica', 'Admin'],
    'category'   => 'Logística',
  ],
  [
    'key'        => 'accesorios_regalo',
    'title'      => 'Accesorios de regalo',
    'desc'       => 'Accesorios aprobados para ser ingresados como regalo.',
    'href'       => 'accesorios_regalo_admin.php',
    'icon'       => 'bi-gift',
    'roles'      => ['Admin', 'Logistica'],
    'category'   => 'Logística',
  ],
  [
    'key'        => 'admin_expedientes',
    'title'      => 'Panel de expedientes',
    'desc'       => 'Panel Administrador de expedientes de usuarios',
    'href'       => 'admin_expedientes.php',
    'icon'       => 'bi-gift',
    'roles'      => ['Admin', 'Logistica'],
    'category'   => 'Personal y sucursales',
  ],
];

/* =========================================================
   Helpers
========================================================= */
function can_use(array $toolRoles, string $userRole): bool {
  return in_array($userRole, $toolRoles, true);
}

function h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/* =========================================================
   Ordenar por categoría y título
========================================================= */
usort($TOOLS, function ($a, $b) {
  $cat = strcmp($a['category'], $b['category']);
  if ($cat !== 0) return $cat;
  return strcmp($a['title'], $b['title']);
});

/* =========================================================
   Sacar categorías únicas
========================================================= */
$categories = [];
foreach ($TOOLS as $tool) {
  $categories[$tool['category']] = true;
}
$categories = array_keys($categories);

/* =========================================================
   Contadores
========================================================= */
$totalTools = count($TOOLS);
$availableTools = 0;
foreach ($TOOLS as $tool) {
  if (can_use($tool['roles'], $rol)) $availableTools++;
}
?>
<!doctype html>
<html lang="es" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <title>Panel de Operador</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

  <style>
    :root{
      --ink:#0f172a;
      --muted:#64748b;
      --line:#e5e7eb;
      --bg:#f6f8fc;
      --card:#ffffff;
      --brand1:#0ea5e9;
      --brand2:#22c55e;
      --brand3:#6366f1;
      --soft:#eef2ff;
      --soft2:#ecfeff;
    }

    body{
      background:linear-gradient(180deg,#f8fafc 0%, #f3f6fb 100%);
      color:var(--ink);
    }

    .hero{
      background:linear-gradient(135deg,var(--brand1),var(--brand2));
      color:#fff;
      border-radius:22px;
      padding:22px 24px;
      margin:18px 0 16px;
      box-shadow:0 16px 40px rgba(2,6,23,.16);
    }

    .hero-title{
      font-weight:800;
      letter-spacing:.2px;
    }

    .badge-soft{
      background:rgba(255,255,255,.18);
      color:#fff;
      border:1px solid rgba(255,255,255,.28);
      font-weight:600;
      border-radius:999px;
      padding:.5rem .75rem;
    }

    .toolbar{
      background:rgba(255,255,255,.92);
      border:1px solid rgba(15,23,42,.06);
      border-radius:18px;
      padding:16px;
      box-shadow:0 10px 30px rgba(2,6,23,.06);
      position:sticky;
      top:82px;
      z-index:50;
      backdrop-filter: blur(10px);
    }

    .search-wrap{
      position:relative;
    }

    .search-wrap .bi{
      position:absolute;
      left:14px;
      top:50%;
      transform:translateY(-50%);
      color:#94a3b8;
      font-size:1rem;
    }

    .search-input{
      padding-left:40px;
      border-radius:14px;
      min-height:46px;
      border:1px solid #dbe3ef;
      box-shadow:none !important;
    }

    .filter-chip{
      border-radius:999px;
      font-weight:600;
      padding:.55rem .95rem;
      border:1px solid #dbe3ef;
      background:#fff;
      color:#334155;
      transition:all .15s ease;
    }

    .filter-chip:hover,
    .filter-chip.active{
      background:linear-gradient(135deg,var(--brand3),var(--brand1));
      color:#fff;
      border-color:transparent;
      box-shadow:0 8px 18px rgba(99,102,241,.22);
    }

    .section-block{
      margin-top:24px;
    }

    .section-header{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      margin-bottom:14px;
      padding-bottom:8px;
      border-bottom:1px solid rgba(15,23,42,.08);
    }

    .section-title{
      display:flex;
      align-items:center;
      gap:10px;
      margin:0;
      font-size:1.05rem;
      font-weight:800;
      color:#0f172a;
    }

    .section-title .section-icon{
      width:38px;
      height:38px;
      border-radius:12px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      background:linear-gradient(135deg,#e0f2fe,#eef2ff);
      color:#4338ca;
      box-shadow:inset 0 0 0 1px rgba(99,102,241,.08);
    }

    .tool-card{
      border:1px solid rgba(15,23,42,.06);
      border-radius:20px;
      background:var(--card);
      box-shadow:0 10px 30px rgba(2,6,23,.06);
      transition:transform .15s ease, box-shadow .15s ease, border-color .15s ease;
      overflow:hidden;
      min-height:100%;
    }

    .tool-card:hover{
      transform:translateY(-3px);
      box-shadow:0 16px 34px rgba(2,6,23,.10);
      border-color:rgba(99,102,241,.18);
    }

    .tool-card.disabled-card{
      opacity:.68;
      filter:saturate(.85);
    }

    .tool-card-top{
      padding:18px 18px 12px;
    }

    .tool-icon{
      width:52px;
      height:52px;
      border-radius:16px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      background:linear-gradient(135deg,var(--soft),var(--soft2));
      color:#3730a3;
      box-shadow:inset 0 0 0 1px rgba(99,102,241,.08);
      flex:0 0 52px;
    }

    .tool-icon i{
      font-size:1.28rem;
    }

    .tool-title{
      margin:0;
      font-size:1rem;
      font-weight:800;
      line-height:1.2;
    }

    .tool-desc{
      color:var(--muted);
      margin:.35rem 0 0;
      line-height:1.45;
      min-height:44px;
    }

    .tool-meta{
      display:flex;
      flex-wrap:wrap;
      gap:8px;
      margin-top:12px;
    }

    .mini-badge{
      display:inline-flex;
      align-items:center;
      gap:6px;
      border-radius:999px;
      padding:.38rem .68rem;
      font-size:.78rem;
      font-weight:700;
      background:#f8fafc;
      color:#334155;
      border:1px solid #e5e7eb;
    }

    .tool-card-bottom{
      padding:0 18px 18px;
    }

    .btn-tool{
      border-radius:12px;
      font-weight:700;
      padding:.62rem .95rem;
    }

    .btn-ghost{
      border-radius:12px;
      font-weight:700;
      background:#f8fafc;
      border:1px solid #e5e7eb;
      color:#334155;
    }

    .stats-pill{
      display:inline-flex;
      align-items:center;
      gap:8px;
      background:#fff;
      border:1px solid rgba(255,255,255,.25);
      color:#fff;
      border-radius:999px;
      padding:.5rem .8rem;
      font-weight:700;
      background:rgba(255,255,255,.16);
    }

    .empty-state{
      display:none;
      text-align:center;
      background:#fff;
      border:1px dashed #cbd5e1;
      border-radius:20px;
      padding:36px 20px;
      margin-top:24px;
      box-shadow:0 8px 24px rgba(2,6,23,.04);
    }

    .empty-state i{
      font-size:2.2rem;
      color:#94a3b8;
      display:block;
      margin-bottom:10px;
    }

    .count-badge{
      background:#eef2ff;
      color:#4338ca;
      font-size:.82rem;
      font-weight:800;
      border-radius:999px;
      padding:.35rem .65rem;
    }

    .hidden-by-filter{
      display:none !important;
    }

    @media (max-width: 768px){
      .toolbar{
        position:static;
        top:auto;
      }
      .tool-desc{
        min-height:auto;
      }
    }
  </style>
</head>
<body>
<div class="container py-3">

  <div class="hero">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div>
        <h1 class="hero-title h3 m-0">
          <i class="bi bi-grid-1x2-fill me-2"></i>Panel de Operador
        </h1>
        <div class="mt-2 opacity-90">
          Usuario <strong><?= h($nombre) ?></strong>
          <span class="mx-1">·</span>
          Rol <span class="badge-soft"><?= h($rol) ?></span>
          <span class="mx-1">·</span>
          Sucursal <strong>#<?= (int)$idSucursal ?></strong>
        </div>
      </div>

      <div class="d-flex flex-wrap gap-2 justify-content-end">
        <span class="stats-pill">
          <i class="bi bi-check2-circle"></i>
          Disponibles: <?= (int)$availableTools ?>
        </span>
        <span class="stats-pill">
          <i class="bi bi-grid"></i>
          Total: <?= (int)$totalTools ?>
        </span>
        <a class="btn btn-light btn-sm btn-tool" href="index.php">
          <i class="bi bi-house-door me-1"></i>Inicio
        </a>
      </div>
    </div>
  </div>

  <div class="toolbar mb-4">
    <div class="row g-3 align-items-center">
      <div class="col-12 col-lg-5">
        <label for="toolSearch" class="form-label fw-bold mb-2">Buscar herramienta</label>
        <div class="search-wrap">
          <i class="bi bi-search"></i>
          <input
            type="text"
            id="toolSearch"
            class="form-control search-input"
            placeholder="Escribe nombre, descripción o categoría..."
            autocomplete="off"
          >
        </div>
      </div>

      <div class="col-12 col-lg-7">
        <label class="form-label fw-bold mb-2">Filtrar por categoría</label>
        <div class="d-flex flex-wrap gap-2" id="categoryFilters">
          <button type="button" class="filter-chip active" data-category="all">
            <i class="bi bi-grid me-1"></i>Todas
          </button>
          <?php foreach ($categories as $cat): ?>
            <button type="button" class="filter-chip" data-category="<?= h($cat) ?>">
              <?= h($cat) ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <?php foreach ($categories as $category): ?>
    <section class="section-block category-section" data-category-section="<?= h($category) ?>">
      <div class="section-header">
        <h2 class="section-title">
          <span class="section-icon">
            <?php
              $sectionIcon = 'bi-folder2-open';
              if ($category === 'Inventario') $sectionIcon = 'bi-box-seam';
              elseif ($category === 'Precios y Promos') $sectionIcon = 'bi-tags';
              elseif ($category === 'Operación financiera') $sectionIcon = 'bi-cash-stack';
              elseif ($category === 'Personal y sucursales') $sectionIcon = 'bi-people';
              elseif ($category === 'Logística') $sectionIcon = 'bi-truck';
            ?>
            <i class="bi <?= h($sectionIcon) ?>"></i>
          </span>
          <?= h($category) ?>
        </h2>
        <span class="count-badge category-count">0</span>
      </div>

      <div class="row g-3">
        <?php foreach ($TOOLS as $t): ?>
          <?php if ($t['category'] !== $category) continue; ?>

          <?php
            $enabled   = can_use($t['roles'], $rol);
            $cardClass = $enabled ? '' : 'disabled-card';
            $btnAttrs  = $enabled ? 'href="'.h($t['href']).'"' : 'tabindex="-1" aria-disabled="true"';
            $btnClass  = $enabled ? 'btn-primary' : 'btn-outline-secondary disabled';
            $tooltip   = $enabled ? '' : 'data-bs-toggle="tooltip" data-bs-title="Sin permiso para tu rol"';
            $searchBlob = mb_strtolower(
              $t['title'].' '.$t['desc'].' '.$t['category'].' '.implode(' ', $t['roles']),
              'UTF-8'
            );
          ?>
          <div
            class="col-12 col-md-6 col-xl-4 tool-item"
            data-category="<?= h($t['category']) ?>"
            data-search="<?= h($searchBlob) ?>"
            data-enabled="<?= $enabled ? '1' : '0' ?>"
          >
            <div class="tool-card h-100 <?= $cardClass ?>">
              <div class="tool-card-top">
                <div class="d-flex gap-3 align-items-start">
                  <div class="tool-icon">
                    <i class="bi <?= h($t['icon']) ?>"></i>
                  </div>

                  <div class="flex-grow-1">
                    <h3 class="tool-title"><?= h($t['title']) ?></h3>
                    <p class="tool-desc"><?= h($t['desc']) ?></p>

                    <div class="tool-meta">
                      <span class="mini-badge">
                        <i class="bi bi-folder2-open"></i><?= h($t['category']) ?>
                      </span>
                      <span class="mini-badge">
                        <i class="bi bi-shield-check"></i><?= $enabled ? 'Disponible' : 'Sin acceso' ?>
                      </span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="tool-card-bottom">
                <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                  <a class="btn btn-sm <?= $btnClass ?> btn-tool" <?= $btnAttrs ?> <?= $tooltip ?>>
                    <i class="bi bi-box-arrow-in-right me-1"></i>Abrir
                  </a>

                  <button
                    type="button"
                    class="btn btn-sm btn-ghost"
                    data-bs-toggle="tooltip"
                    data-bs-title="Roles permitidos: <?= h(implode(', ', $t['roles'])) ?>"
                  >
                    <i class="bi bi-person-badge me-1"></i>Roles
                  </button>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <div id="emptyState" class="empty-state">
    <i class="bi bi-search-heart"></i>
    <h4 class="fw-bold mb-2">No encontré herramientas con ese filtro</h4>
    <p class="text-muted mb-0">
      Prueba con otro texto o cambia la categoría seleccionada.
    </p>
  </div>

</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
  (() => {
    const searchInput = document.getElementById('toolSearch');
    const filterButtons = document.querySelectorAll('#categoryFilters .filter-chip');
    const toolItems = document.querySelectorAll('.tool-item');
    const sections = document.querySelectorAll('.category-section');
    const emptyState = document.getElementById('emptyState');

    let activeCategory = 'all';

    function normalize(text) {
      return (text || '')
        .toString()
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '');
    }

    function updateCounts() {
      let totalVisible = 0;

      sections.forEach(section => {
        const category = section.getAttribute('data-category-section');
        const visibleItems = section.querySelectorAll('.tool-item:not(.hidden-by-filter)');
        const countBadge = section.querySelector('.category-count');

        countBadge.textContent = visibleItems.length;

        if (visibleItems.length === 0) {
          section.style.display = 'none';
        } else {
          section.style.display = '';
          totalVisible += visibleItems.length;
        }
      });

      emptyState.style.display = totalVisible === 0 ? 'block' : 'none';
    }

    function applyFilters() {
      const term = normalize(searchInput.value.trim());

      toolItems.forEach(item => {
        const itemCategory = item.getAttribute('data-category');
        const itemSearch = normalize(item.getAttribute('data-search'));

        const categoryMatch = activeCategory === 'all' || itemCategory === activeCategory;
        const textMatch = term === '' || itemSearch.includes(term);

        if (categoryMatch && textMatch) {
          item.classList.remove('hidden-by-filter');
        } else {
          item.classList.add('hidden-by-filter');
        }
      });

      updateCounts();
    }

    filterButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        filterButtons.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        activeCategory = btn.getAttribute('data-category');
        applyFilters();
      });
    });

    searchInput.addEventListener('input', applyFilters);

    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
      new bootstrap.Tooltip(el);
    });

    applyFilters();
  })();
</script>
</body>
</html>