<?php
// traspasos_en_transito.php — Resumen global de traspasos en tránsito (SOLO Pendiente)
// Visible para Admin y Logística

session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

$rol = $_SESSION['rol'] ?? '';
$rolesPermitidos = ['Admin', 'Logistica', 'Logística'];
if (!in_array($rol, $rolesPermitidos, true)) {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Mexico_City');
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function hasColumn(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = '{$t}'
              AND COLUMN_NAME  = '{$c}' LIMIT 1";
    $res = $conn->query($sql);
    return ($res && $res->num_rows > 0);
}

/* =========================================================
   AJAX: Detalle del traspaso para Modal (JSON puro)
========================================================= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detalle') {
    if (ob_get_length()) { @ob_clean(); }
    header('Content-Type: application/json; charset=utf-8');

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        echo json_encode(['ok'=>false,'error'=>'ID inválido']); exit();
    }

    try {
        // Cabecera
        $sqlH = "
            SELECT
                t.*,
                so.nombre AS sucursal_origen,
                sd.nombre AS sucursal_destino,
                uc.nombre AS usuario_creo_nombre,
                ur.nombre AS usuario_recibio_nombre
            FROM traspasos t
            LEFT JOIN sucursales so ON so.id = t.id_sucursal_origen
            LEFT JOIN sucursales sd ON sd.id = t.id_sucursal_destino
            LEFT JOIN usuarios   uc ON uc.id = t.usuario_creo
            LEFT JOIN usuarios   ur ON ur.id = t.usuario_recibio
            WHERE t.id = {$id}
            LIMIT 1
        ";
        $rh = $conn->query($sqlH);
        $head = $rh ? $rh->fetch_assoc() : null;
        if (!$head) {
            echo json_encode(['ok'=>false,'error'=>'Traspaso no encontrado']); exit();
        }

        // Detectar columnas en detalle_traspaso
        $hasIdInv    = hasColumn($conn, 'detalle_traspaso', 'id_inventario');
        $hasRes      = hasColumn($conn, 'detalle_traspaso', 'resultado');
        $hasFechaRes = hasColumn($conn, 'detalle_traspaso', 'fecha_resultado');

        // Detectar columnas en inventario
        $invHasImei1      = hasColumn($conn, 'inventario', 'imei1');
        $invHasImei2      = hasColumn($conn, 'inventario', 'imei2');
        $invHasMarca      = hasColumn($conn, 'inventario', 'marca');
        $invHasModelo     = hasColumn($conn, 'inventario', 'modelo');
        $invHasColor      = hasColumn($conn, 'inventario', 'color');
        $invHasCapacidad  = hasColumn($conn, 'inventario', 'capacidad');
        $invHasCodigoProd = hasColumn($conn, 'inventario', 'codigo_producto');
        $invHasIdProd     = hasColumn($conn, 'inventario', 'id_producto');
        $invHasSucursal   = hasColumn($conn, 'inventario', 'id_sucursal'); // por si quieres mostrarla luego

        // Detectar columnas en productos (opcional)
        $prodHasMarca  = hasColumn($conn, 'productos', 'marca');
        $prodHasModelo = hasColumn($conn, 'productos', 'modelo');
        $prodHasColor  = hasColumn($conn, 'productos', 'color');
        $prodHasCap    = hasColumn($conn, 'productos', 'capacidad');
        $prodHasCod    = hasColumn($conn, 'productos', 'codigo_producto');
        $prodHasImei1  = hasColumn($conn, 'productos', 'imei1');
        $prodHasImei2  = hasColumn($conn, 'productos', 'imei2');

        // SELECT base
        $select = ["d.id"];

        if ($hasIdInv)    $select[] = "d.id_inventario";
        if ($hasRes)      $select[] = "d.resultado";
        if ($hasFechaRes) $select[] = "d.fecha_resultado";

        // JOIN inventario
        $joinInv = "";
        if ($hasIdInv) {
            $joinInv = "LEFT JOIN inventario inv ON inv.id = d.id_inventario";

            if ($invHasImei1)      $select[] = "inv.imei1 AS inv_imei1";
            if ($invHasImei2)      $select[] = "inv.imei2 AS inv_imei2";
            if ($invHasMarca)      $select[] = "inv.marca AS inv_marca";
            if ($invHasModelo)     $select[] = "inv.modelo AS inv_modelo";
            if ($invHasColor)      $select[] = "inv.color AS inv_color";
            if ($invHasCapacidad)  $select[] = "inv.capacidad AS inv_capacidad";
            if ($invHasCodigoProd) $select[] = "inv.codigo_producto AS inv_codigo_producto";
            if ($invHasIdProd)     $select[] = "inv.id_producto AS inv_id_producto";
            if ($invHasSucursal)   $select[] = "inv.id_sucursal AS inv_id_sucursal";
        }

        // JOIN productos si inventario trae id_producto
        $joinProd = "";
        if ($hasIdInv && $invHasIdProd) {
            $joinProd = "LEFT JOIN productos p ON p.id = inv.id_producto";
            if ($prodHasMarca)  $select[] = "p.marca AS prod_marca";
            if ($prodHasModelo) $select[] = "p.modelo AS prod_modelo";
            if ($prodHasColor)  $select[] = "p.color AS prod_color";
            if ($prodHasCap)    $select[] = "p.capacidad AS prod_capacidad";
            if ($prodHasCod)    $select[] = "p.codigo_producto AS prod_codigo_producto";
            if ($prodHasImei1)  $select[] = "p.imei1 AS prod_imei1";
            if ($prodHasImei2)  $select[] = "p.imei2 AS prod_imei2";
        }

        $sqlD = "
            SELECT " . implode(", ", $select) . "
            FROM detalle_traspaso d
            {$joinInv}
            {$joinProd}
            WHERE d.id_traspaso = {$id}
            ORDER BY d.id ASC
        ";
        $rd = $conn->query($sqlD);
        $items = $rd ? $rd->fetch_all(MYSQLI_ASSOC) : [];

        // Totales
        $totales = ['tot'=>count($items),'pend'=>0,'rec'=>0,'rech'=>0];
        foreach ($items as $it) {
            $r = $it['resultado'] ?? '';
            if ($r === 'Pendiente') $totales['pend']++;
            elseif ($r === 'Recibido') $totales['rec']++;
            elseif ($r === 'Rechazado') $totales['rech']++;
        }

        echo json_encode(['ok'=>true,'head'=>$head,'items'=>$items,'totales'=>$totales]);
        exit();

    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'error'=>'Error servidor: '.$e->getMessage()]);
        exit();
    }
}

/* =========================================================
   Ya podemos imprimir HTML (navbar)
========================================================= */
require_once __DIR__ . '/navbar.php';

/* =========================================================
   Filtros (Parcial NO debe aparecer aquí)
========================================================= */
$estatus = $_GET['estatus'] ?? 'Pendiente'; // Pendiente | Todos
$buscar  = trim((string)($_GET['q'] ?? ''));

$where = "t.estatus = 'Pendiente'"; // Solo en tránsito real
if ($estatus === 'Todos') {
    // Parcial se queda fuera por regla de negocio
    $where = "t.estatus IN ('Pendiente','Completado','Rechazado')";
}

$whereBuscar = "";
if ($buscar !== '') {
    $q = $conn->real_escape_string($buscar);
    $whereBuscar = " AND (
        t.id = '{$q}'
        OR so.nombre LIKE '%{$q}%'
        OR sd.nombre LIKE '%{$q}%'
        OR uc.nombre LIKE '%{$q}%'
        OR ur.nombre LIKE '%{$q}%'
    )";
}

/* =========================================================
   Consulta principal (resumen)
========================================================= */
$sql = "
    SELECT
        t.id,
        t.id_sucursal_origen,
        t.id_sucursal_destino,
        t.fecha_traspaso,
        t.fecha_recepcion,
        t.estatus,
        t.usuario_creo,
        t.usuario_recibio,

        so.nombre AS sucursal_origen,
        sd.nombre AS sucursal_destino,
        uc.nombre AS usuario_creo_nombre,
        ur.nombre AS usuario_recibio_nombre,

        COUNT(d.id) AS piezas_totales,
        SUM(CASE WHEN d.resultado = 'Pendiente' THEN 1 ELSE 0 END) AS piezas_pendientes,
        SUM(CASE WHEN d.resultado = 'Recibido'  THEN 1 ELSE 0 END) AS piezas_recibidas,
        SUM(CASE WHEN d.resultado = 'Rechazado' THEN 1 ELSE 0 END) AS piezas_rechazadas
    FROM traspasos t
    LEFT JOIN detalle_traspaso d ON d.id_traspaso = t.id
    LEFT JOIN sucursales so ON so.id = t.id_sucursal_origen
    LEFT JOIN sucursales sd ON sd.id = t.id_sucursal_destino
    LEFT JOIN usuarios   uc ON uc.id = t.usuario_creo
    LEFT JOIN usuarios   ur ON ur.id = t.usuario_recibio
    WHERE {$where} {$whereBuscar}
    GROUP BY t.id
    ORDER BY t.fecha_traspaso DESC, t.id DESC
";
$res = $conn->query($sql);
$traspasos = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

/* =========================================================
   KPIs rápidos
========================================================= */
$kpi_total = count($traspasos);
$kpi_vencido_1d = 0;
$kpi_vencido_3d = 0;
$kpi_piezas_pend = 0;

$now = time();
foreach ($traspasos as $t) {
    $ts = strtotime((string)$t['fecha_traspaso']);
    $diff = max(0, $now - $ts);
    $dias = (int)floor($diff / 86400);

    if ($dias >= 1) $kpi_vencido_1d++;
    if ($dias >= 3) $kpi_vencido_3d++;
    $kpi_piezas_pend += (int)($t['piezas_pendientes'] ?? 0);
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Traspasos en tránsito</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f5f5f5; }
    .page-title { font-size: 1.4rem; font-weight: 700; }
    .badge-status { font-size: .75rem; }
    .table-smaller td, .table-smaller th { padding: .35rem .4rem; font-size: .85rem; vertical-align: middle; }
    tr[data-id] { cursor: pointer; }
    .kpi-card { border:0; }
    .kpi-num { font-size: 1.25rem; font-weight: 800; }
    .muted { color:#6c757d; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
</head>
<body>

<div class="container-fluid mt-3 mb-4">
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
    <div>
      <div class="page-title">Traspasos en tránsito</div>
      <small class="text-muted">
        Vista global para logística / admin · Parciales ocultos por regla de negocio ✅
      </small>
    </div>

    <form class="d-flex flex-wrap gap-2 align-items-center" method="get">
      <div class="d-flex align-items-center">
        <label class="me-2 small text-muted">Estatus:</label>
        <select name="estatus" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="Pendiente" <?php if($estatus==='Pendiente') echo 'selected'; ?>>
            Solo en tránsito (Pendiente)
          </option>
          <option value="Todos" <?php if($estatus==='Todos') echo 'selected'; ?>>
            Histórico (Pendiente + Completado + Rechazado)
          </option>
        </select>
      </div>

      <input type="text" class="form-control form-control-sm" name="q" placeholder="Buscar: ID, sucursal o usuario"
             value="<?php echo h($buscar); ?>" style="min-width:260px">

      <button class="btn btn-sm btn-primary" type="submit">Filtrar</button>
      <a class="btn btn-sm btn-outline-secondary" href="traspasos_en_transito.php">Limpiar</a>
    </form>
  </div>

  <!-- KPIs -->
  <div class="row g-2 mb-3">
    <div class="col-6 col-lg-3">
      <div class="card kpi-card shadow-sm">
        <div class="card-body py-2">
          <div class="muted small">Traspasos listados</div>
          <div class="kpi-num"><?php echo (int)$kpi_total; ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card kpi-card shadow-sm">
        <div class="card-body py-2">
          <div class="muted small">Piezas pendientes</div>
          <div class="kpi-num"><?php echo (int)$kpi_piezas_pend; ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card kpi-card shadow-sm">
        <div class="card-body py-2">
          <div class="muted small">Vencidos (≥ 1 día)</div>
          <div class="kpi-num"><?php echo (int)$kpi_vencido_1d; ?></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-lg-3">
      <div class="card kpi-card shadow-sm">
        <div class="card-body py-2">
          <div class="muted small">Críticos (≥ 3 días)</div>
          <div class="kpi-num"><?php echo (int)$kpi_vencido_3d; ?></div>
        </div>
      </div>
    </div>
  </div>

  <?php if (empty($traspasos)): ?>
    <div class="alert alert-success shadow-sm">
      No hay traspasos con el filtro seleccionado. 🌈
    </div>
  <?php else: ?>

    <div class="card shadow-sm">
      <div class="card-body p-2 p-sm-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <small class="text-muted">Tip: da clic en un renglón para ver el detalle en modal 👇</small>
          <small class="text-muted mono"><?php echo h(date('Y-m-d H:i')); ?></small>
        </div>

        <div class="table-responsive">
          <table class="table table-striped table-hover table-smaller align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Origen</th>
                <th>Destino</th>
                <th>Fecha traspaso</th>
                <th>Edad</th>
                <th>Pzas</th>
                <th>Pend.</th>
                <th>Recib.</th>
                <th>Rech.</th>
                <th>Estatus</th>
                <th>Creó</th>
                <th>Recibió</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($traspasos as $t): ?>
              <?php
                $fechaTraspaso = $t['fecha_traspaso'];
                $tsTraspaso    = strtotime((string)$fechaTraspaso);
                $diffSeg       = max(0, time() - $tsTraspaso);
                $dias          = (int)floor($diffSeg / 86400);
                $horas         = (int)floor(($diffSeg % 86400) / 3600);
                $edadTexto     = $dias > 0 ? "{$dias} día(s) {$horas} h" : "{$horas} h";

                $rowClass = '';
                if ($dias >= 3) $rowClass = 'table-danger';
                elseif ($dias >= 1) $rowClass = 'table-warning';

                $badgeClass = 'bg-secondary';
                if ($t['estatus'] === 'Pendiente') $badgeClass = 'bg-warning text-dark';
                elseif ($t['estatus'] === 'Completado') $badgeClass = 'bg-success';
                elseif ($t['estatus'] === 'Rechazado') $badgeClass = 'bg-danger';
              ?>
              <tr class="<?php echo $rowClass; ?>" data-id="<?php echo (int)$t['id']; ?>">
                <td class="mono"><?php echo (int)$t['id']; ?></td>
                <td><?php echo h($t['sucursal_origen'] ?: 'N/D'); ?></td>
                <td><?php echo h($t['sucursal_destino'] ?: 'N/D'); ?></td>
                <td><?php echo h(date('Y-m-d H:i', strtotime((string)$fechaTraspaso))); ?></td>
                <td><?php echo h($edadTexto); ?></td>
                <td><?php echo (int)$t['piezas_totales']; ?></td>
                <td class="fw-bold"><?php echo (int)$t['piezas_pendientes']; ?></td>
                <td><?php echo (int)$t['piezas_recibidas']; ?></td>
                <td><?php echo (int)$t['piezas_rechazadas']; ?></td>
                <td><span class="badge badge-status <?php echo $badgeClass; ?>"><?php echo h($t['estatus']); ?></span></td>
                <td><?php echo h($t['usuario_creo_nombre'] ?: 'N/D'); ?></td>
                <td><?php echo h($t['usuario_recibio_nombre'] ?: '—'); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>

  <?php endif; ?>
</div>

<!-- Modal Detalle -->
<div class="modal fade" id="modalDetalle" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Detalle de traspaso <span class="mono" id="md_id">#</span></h5>
          <div class="small text-muted" id="md_sub">Cargando…</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div id="md_loading" class="text-center py-4">
          <div class="spinner-border" role="status" aria-hidden="true"></div>
          <div class="mt-2 text-muted">Cargando detalle…</div>
        </div>

        <div id="md_content" class="d-none">
          <div class="row g-2 mb-3">
            <div class="col-12 col-lg-6">
              <div class="card shadow-sm">
                <div class="card-body py-2">
                  <div class="small text-muted">Ruta</div>
                  <div class="fw-semibold" id="md_ruta">—</div>
                  <div class="small text-muted mt-2">Fechas</div>
                  <div class="mono" id="md_fechas">—</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-lg-6">
              <div class="card shadow-sm">
                <div class="card-body py-2">
                  <div class="small text-muted">Usuarios</div>
                  <div id="md_users">—</div>
                  <div class="small text-muted mt-2">Resumen</div>
                  <div id="md_resumen" class="mono">—</div>
                </div>
              </div>
            </div>
          </div>

          <div class="card shadow-sm">
            <div class="card-body p-2">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-semibold">Detalle de piezas</div>
                
              </div>
              <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                  <thead class="table-light">
                    <tr>
                      <th>#</th>
                      <th>Identificador</th>
                      <th>Descripción</th>
                      <th>Resultado</th>
                      <th>Notas</th>
                    </tr>
                  </thead>
                  <tbody id="md_items"></tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div id="md_error" class="alert alert-danger d-none mt-2"></div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
(function(){
  const modalEl = document.getElementById('modalDetalle');
  const modal = new bootstrap.Modal(modalEl);
  const $ = (id) => document.getElementById(id);

  function showLoading(id) {
    $('md_id').textContent = '#' + id;
    $('md_sub').textContent = 'Cargando información…';
    $('md_loading').classList.remove('d-none');
    $('md_content').classList.add('d-none');
    $('md_error').classList.add('d-none');
    $('md_error').textContent = '';
  }

  function showError(msg) {
    $('md_loading').classList.add('d-none');
    $('md_content').classList.add('d-none');
    $('md_error').classList.remove('d-none');
    $('md_error').textContent = msg || 'Error al cargar detalle';
  }

  function safe(val){ return (val === null || val === undefined || val === '') ? '—' : String(val); }

  async function loadDetalle(id) {
    showLoading(id);
    modal.show();

    try {
      const url = new URL(window.location.href);
      url.searchParams.set('ajax', 'detalle');
      url.searchParams.set('id', id);

      const res = await fetch(url.toString(), {
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      });

      const ct = (res.headers.get('content-type') || '').toLowerCase();

      if (!res.ok) {
        const txt = await res.text().catch(()=> '');
        showError(`HTTP ${res.status}. ${txt ? txt.slice(0, 300) : 'Sin respuesta.'}`);
        return;
      }

      if (!ct.includes('application/json')) {
        const txt = await res.text().catch(()=> '');
        showError(`No es JSON (content-type: ${ct}). Resp: ${txt.slice(0, 300)}`);
        return;
      }

      const data = await res.json();

      if (!data.ok) {
        showError(data.error || 'No se pudo cargar el traspaso');
        return;
      }

      const h = data.head || {};
      const tot = data.totales || {};
      const items = data.items || [];

      $('md_sub').textContent = safe(h.estatus) + ' · ' + safe(h.sucursal_origen) + ' → ' + safe(h.sucursal_destino);
      $('md_ruta').textContent = safe(h.sucursal_origen) + ' → ' + safe(h.sucursal_destino);
      $('md_fechas').textContent = 'Traspaso: ' + safe(h.fecha_traspaso) + ' | Recepción: ' + safe(h.fecha_recepcion);

      $('md_users').innerHTML =
        '<div><span class="text-muted small">Creó:</span> <b>' + safe(h.usuario_creo_nombre) + '</b></div>' +
        '<div><span class="text-muted small">Recibió:</span> <b>' + safe(h.usuario_recibio_nombre) + '</b></div>';

      $('md_resumen').textContent =
        'Tot: ' + safe(tot.tot) + ' | Pend: ' + safe(tot.pend) + ' | Rec: ' + safe(tot.rec) + ' | Rech: ' + safe(tot.rech);

      const tbody = $('md_items');
      tbody.innerHTML = '';

      items.forEach((it, idx) => {
        // Identificador: IMEI (inventario) o id_inventario
        const ident =
          it.inv_imei1 ?? it.inv_imei2 ?? it.prod_imei1 ?? it.prod_imei2 ?? it.id_inventario ?? it.inv_id_producto ?? it.id ?? '';

        // Descripción: codigo + marca + modelo + capacidad + color
        const marca  = it.inv_marca  ?? it.prod_marca;
        const modelo = it.inv_modelo ?? it.prod_modelo;
        const color  = it.inv_color  ?? it.prod_color;
        const cap    = it.inv_capacidad ?? it.prod_capacidad;
        const cod    = it.inv_codigo_producto ?? it.prod_codigo_producto;

        const descParts = [];
        if (cod) descParts.push(cod);
        if (marca) descParts.push(marca);
        if (modelo) descParts.push(modelo);
        if (cap) descParts.push(cap);
        if (color) descParts.push(color);

        const desc = descParts.join(' ') || '—';
        const resu = it.resultado ?? '—';

        const notas = [];
        if (it.fecha_resultado) notas.push('Fecha: ' + it.fecha_resultado);
        const notasTxt = notas.join(' · ') || '—';

        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td class="text-muted">${idx + 1}</td>
          <td class="mono">${safe(ident)}</td>
          <td>${safe(desc)}</td>
          <td><span class="badge ${resu==='Pendiente'?'bg-warning text-dark':(resu==='Recibido'?'bg-success':(resu==='Rechazado'?'bg-danger':'bg-secondary'))}">${safe(resu)}</span></td>
          <td class="small text-muted">${safe(notasTxt)}</td>
        `;
        tbody.appendChild(tr);
      });

      $('md_loading').classList.add('d-none');
      $('md_content').classList.remove('d-none');

    } catch (e) {
      showError('Error de red o respuesta inválida del servidor.');
    }
  }

  document.querySelectorAll('tr[data-id]').forEach(row => {
    row.addEventListener('click', () => {
      const id = row.getAttribute('data-id');
      if (id) loadDetalle(id);
    });
  });

})();
</script>

<script>
  // Auto refresh cada 60s SOLO si no hay modal abierto
  setInterval(() => {
    const modalOpen = document.querySelector('.modal.show');
    if (!modalOpen) window.location.reload();
  }, 60000);
</script>

</body>
</html>