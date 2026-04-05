<?php
// cortes_zona.php
// Vista para GerenteZona: monitoreo de cortes de caja por sucursal/día.
// Regla principal: si hay cobros en el día debe existir corte de caja.
// Ahora los comentarios de administración se toman del DEPÓSITO (depositos_sucursal.comentario_admin).

session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

$rol = $_SESSION['rol'] ?? '';

if (!in_array($rol, ['GerenteZona', 'Admin'], true)) {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n) { return number_format((float)$n, 2); }

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);

/* ============================================================
   1) Obtener zona del GerenteZona (o zona seleccionada si Admin)
   ============================================================ */

$zonaGerente = null;

if ($rol === 'GerenteZona') {
    $sqlZona = "
        SELECT s.zona
        FROM usuarios u
        JOIN sucursales s ON s.id = u.id_sucursal
        WHERE u.id = ?
        LIMIT 1
    ";
    $stZona = $conn->prepare($sqlZona);
    $stZona->bind_param("i", $idUsuario);
    $stZona->execute();
    $resZona = $stZona->get_result();
    if ($rowZona = $resZona->fetch_assoc()) {
        $zonaGerente = $rowZona['zona'];
    }
    $stZona->close();

    if (!$zonaGerente) {
        ?>
        <div class="container mt-4">
            <div class="alert alert-danger">
                No se encontró la zona asignada para tu usuario. Contacta a Administración.
            </div>
        </div>
        <?php
        exit();
    }
} else {
    // Admin puede elegir zona vía GET
    $zonaGerente = $_GET['zona'] ?? 'Zona 1';
}

/* =========================================
   2) Fechas filtro (default últimos 7 días)
   ========================================= */

$hoy      = new DateTimeImmutable('today');
$porDefectoDesde = $hoy->modify('-7 days')->format('Y-m-d');
$porDefectoHasta = $hoy->format('Y-m-d');

$desde = $_GET['desde'] ?? $porDefectoDesde;
$hasta = $_GET['hasta'] ?? $porDefectoHasta;

try {
    $tmpDesde = new DateTime($desde);
    $tmpHasta = new DateTime($hasta);
    $desde = $tmpDesde->format('Y-m-d');
    $hasta = $tmpHasta->format('Y-m-d');
} catch (Exception $e) {
    $desde = $porDefectoDesde;
    $hasta = $porDefectoHasta;
}

$soloPendientes = isset($_GET['solo_pendientes']) ? 1 : 0;

$inicioDateTime = $desde . ' 00:00:00';
$finDateTime    = $hasta . ' 23:59:59';

/* ============================================================
   3) Query principal
   - Une días donde hubo cobros o cortes.
   - Totales de cobros y cortes.
   - Estado del corte.
   - Estado del depósito + comentario_admin del depósito.
   ============================================================ */

$sql = "
SELECT
    s.id              AS id_sucursal,
    s.nombre          AS sucursal,
    s.zona,
    ufechas.fecha     AS fecha,
    COALESCE(cb.cnt_cobros,0)       AS cnt_cobros,
    COALESCE(cb.total_cobros,0.00)  AS total_cobros,
    COALESCE(ct.cnt_cortes,0)       AS cnt_cortes,
    COALESCE(ct.total_cortes,0.00)  AS total_cortes,
    ct.ultimo_estado,
    ct.deposito_estado,
    ct.comentario_deposito
FROM sucursales s
JOIN (
    -- Unión de días con cobros válidos y/o cortes
    SELECT x.id_sucursal, x.fecha
    FROM (
        SELECT id_sucursal, DATE(fecha_cobro) AS fecha
        FROM cobros
        WHERE estado = 'valido'
          AND fecha_cobro BETWEEN ? AND ?
        GROUP BY id_sucursal, DATE(fecha_cobro)

        UNION

        SELECT id_sucursal, fecha_operacion AS fecha
        FROM cortes_caja
        WHERE fecha_operacion BETWEEN ? AND ?
        GROUP BY id_sucursal, fecha_operacion
    ) AS x
) AS ufechas
  ON ufechas.id_sucursal = s.id
LEFT JOIN (
    -- Totales de cobros por sucursal/día
    SELECT
        id_sucursal,
        DATE(fecha_cobro) AS fecha,
        COUNT(*)           AS cnt_cobros,
        SUM(monto_total)   AS total_cobros
    FROM cobros
    WHERE estado = 'valido'
      AND fecha_cobro BETWEEN ? AND ?
    GROUP BY id_sucursal, DATE(fecha_cobro)
) AS cb
  ON cb.id_sucursal = s.id
 AND cb.fecha       = ufechas.fecha
LEFT JOIN (
    -- Totales de cortes + info de depósito por sucursal/día
    SELECT
        c.id_sucursal,
        c.fecha_operacion AS fecha,
        COUNT(*)            AS cnt_cortes,
        SUM(c.total_general) AS total_cortes,
        MAX(c.estado)        AS ultimo_estado,
        MAX(d.estado)        AS deposito_estado,
        MAX(d.comentario_admin) AS comentario_deposito
    FROM cortes_caja c
    LEFT JOIN depositos_sucursal d
           ON d.id_corte = c.id
    WHERE c.fecha_operacion BETWEEN ? AND ?
    GROUP BY c.id_sucursal, c.fecha_operacion
) AS ct
  ON ct.id_sucursal = s.id
 AND ct.fecha       = ufechas.fecha
WHERE s.zona = ?
  AND s.tipo_sucursal = 'Tienda'
  AND (s.subtipo IS NULL OR s.subtipo = 'Propia')
ORDER BY ufechas.fecha DESC, s.nombre ASC
";

$st = $conn->prepare($sql);

/*
   9 parámetros: 8 de fechas + 1 de zona
*/
$st->bind_param(
    "sssssssss",
    $inicioDateTime, // 1 cobros (union)
    $finDateTime,    // 2
    $desde,          // 3 cortes (union)
    $hasta,          // 4
    $inicioDateTime, // 5 cobros (detalle)
    $finDateTime,    // 6
    $desde,          // 7 cortes (detalle)
    $hasta,          // 8
    $zonaGerente     // 9 zona
);

$st->execute();
$res = $st->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
    $cntCobros = (int)$r['cnt_cobros'];
    $cntCortes = (int)$r['cnt_cortes'];

    if ($cntCobros > 0 && $cntCortes === 0) {
        $estatus = 'Falta corte';
    } elseif ($cntCobros > 0 && $cntCortes > 0) {
        $estatus = 'Al día';
    } elseif ($cntCobros === 0 && $cntCortes > 0) {
        $estatus = 'Corte sin cobros';
    } else {
        $estatus = 'Sin movimientos';
    }

    $r['estatus'] = $estatus;
    $rows[] = $r;
}
$st->close();

/* Filtro solo pendientes en PHP */
if ($soloPendientes) {
    $rows = array_filter($rows, function($r) {
        return in_array($r['estatus'], ['Falta corte', 'Corte sin cobros'], true);
    });
}

/* Helper para CSS de estatus corte */
function badgeClass($status) {
    switch ($status) {
        case 'Al día':           return 'badge bg-success';
        case 'Falta corte':      return 'badge bg-danger';
        case 'Corte sin cobros': return 'badge bg-warning text-dark';
        default:                 return 'badge bg-secondary';
    }
}

/* Helper para CSS de estado de depósito */
function depositoBadgeClass($estado) {
    switch ($estado) {
        case 'Validado': return 'badge bg-success';
        case 'Parcial':  return 'badge bg-warning text-dark';
        case 'Pendiente':return 'badge bg-secondary';
        default:         return 'badge bg-light text-muted';
    }
}

?>
<div class="container mt-4 mb-4">
    <h3>Monitoreo de cortes de caja — <?php echo h($zonaGerente); ?></h3>
    <p class="text-muted mb-2">
        Si hay cobros en el día, debe existir un corte de caja cerrado para esa sucursal.
        Los comentarios los captura Administración en el depósito de efectivo del corte.
    </p>

    <form class="row g-2 mb-3" method="get">
        <div class="col-auto">
            <label for="desde" class="form-label mb-0">Desde</label>
            <input type="date" class="form-control" id="desde" name="desde" value="<?php echo h($desde); ?>">
        </div>
        <div class="col-auto">
            <label for="hasta" class="form-label mb-0">Hasta</label>
            <input type="date" class="form-control" id="hasta" name="hasta" value="<?php echo h($hasta); ?>">
        </div>

        <?php if ($rol === 'Admin'): ?>
        <div class="col-auto">
            <label for="zona" class="form-label mb-0">Zona</label>
            <select class="form-select" id="zona" name="zona">
                <option value="Zona 1" <?php echo ($zonaGerente === 'Zona 1') ? 'selected' : ''; ?>>Zona 1</option>
                <option value="Zona 2" <?php echo ($zonaGerente === 'Zona 2') ? 'selected' : ''; ?>>Zona 2</option>
            </select>
        </div>
        <?php endif; ?>

        <div class="col-auto d-flex align-items-end">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="solo_pendientes" name="solo_pendientes"
                    <?php echo $soloPendientes ? 'checked' : ''; ?>>
                <label class="form-check-label" for="solo_pendientes">
                    Solo pendientes / con problema
                </label>
            </div>
        </div>

        <div class="col-auto d-flex align-items-end">
            <button type="submit" class="btn btn-primary">Filtrar</button>
        </div>
    </form>

    <div class="mb-2">
        <span class="badge bg-success">Al día</span>
        <span class="badge bg-danger ms-1">Falta corte</span>
        <span class="badge bg-warning text-dark ms-1">Corte sin cobros</span>
        <span class="badge bg-secondary ms-1">Sin movimientos</span>
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Sucursal</th>
                    <th>Cobros (cnt / $)</th>
                    <th>Cortes (cnt / $)</th>
                    <th>Estatus corte</th>
                    <th>Depósito / comentarios admin</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted">
                        No hay registros en el rango seleccionado.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo h($r['fecha']); ?></td>
                        <td><?php echo h($r['sucursal']); ?></td>
                        <td>
                            <?php echo (int)$r['cnt_cobros']; ?>
                            /
                            $<?php echo money($r['total_cobros']); ?>
                        </td>
                        <td>
                            <?php echo (int)$r['cnt_cortes']; ?>
                            /
                            $<?php echo money($r['total_cortes']); ?>
                        </td>
                        <td>
                            <span class="<?php echo badgeClass($r['estatus']); ?>">
                                <?php echo h($r['estatus']); ?>
                            </span>
                            <?php if (!empty($r['ultimo_estado'])): ?>
                                <div class="small text-muted">
                                    Corte: <?php echo h($r['ultimo_estado']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="max-width: 340px;">
                            <?php if (!empty($r['deposito_estado'])): ?>
                                <span class="<?php echo depositoBadgeClass($r['deposito_estado']); ?>">
                                    Depósito: <?php echo h($r['deposito_estado']); ?>
                                </span>
                                <?php if (!empty($r['comentario_deposito'])): ?>
                                    <div class="small mt-1">
                                        <?php echo nl2br(h($r['comentario_deposito'])); ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-secondary">Sin depósito registrado</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
