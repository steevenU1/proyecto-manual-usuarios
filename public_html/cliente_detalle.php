<?php
// cliente_detalle.php — Ficha detallada de un cliente

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');

$idCliente = (int)($_GET['id_cliente'] ?? 0);
if ($idCliente <= 0) {
    die("Cliente inválido.");
}

// =========================
// Resumen del cliente
// =========================

$sqlResumen = "
SELECT
    c.id,
    c.codigo_cliente,
    c.nombre,
    c.telefono,
    c.correo,
    c.fecha_alta,
    c.activo,
    c.id_sucursal,
    s.nombre AS sucursal_nombre,

    COALESCE(e.compras_equipos, 0)      AS compras_equipos,
    COALESCE(sv.compras_sims, 0)        AS compras_sims,
    COALESCE(a.compras_accesorios, 0)   AS compras_accesorios,
    COALESCE(p.compras_payjoy, 0)       AS compras_payjoy,

    (COALESCE(e.compras_equipos, 0)
     + COALESCE(sv.compras_sims, 0)
     + COALESCE(a.compras_accesorios, 0)
     + COALESCE(p.compras_payjoy, 0))   AS total_compras,

    (COALESCE(e.monto_equipos, 0)
     + COALESCE(sv.monto_sims, 0)
     + COALESCE(a.monto_accesorios, 0)) AS monto_total,

    GREATEST(
        COALESCE(e.ultima_equipo,   '1970-01-01 00:00:00'),
        COALESCE(sv.ultima_sim,     '1970-01-01 00:00:00'),
        COALESCE(a.ultima_accesorio,'1970-01-01 00:00:00'),
        COALESCE(p.ultima_payjoy,   '1970-01-01 00:00:00')
    ) AS ultima_compra
FROM clientes c
LEFT JOIN sucursales s ON s.id = c.id_sucursal

LEFT JOIN (
    SELECT 
        v.id_cliente,
        COUNT(*)                        AS compras_equipos,
        COALESCE(SUM(v.precio_venta),0) AS monto_equipos,
        MAX(v.fecha_venta)              AS ultima_equipo
    FROM ventas v
    WHERE v.id_cliente IS NOT NULL AND v.id_cliente > 0
    GROUP BY v.id_cliente
) AS e ON e.id_cliente = c.id

LEFT JOIN (
    SELECT 
        vs.id_cliente,
        COUNT(*)                          AS compras_sims,
        COALESCE(SUM(vs.precio_total),0)  AS monto_sims,
        MAX(vs.fecha_venta)               AS ultima_sim
    FROM ventas_sims vs
    WHERE vs.id_cliente IS NOT NULL AND vs.id_cliente > 0
    GROUP BY vs.id_cliente
) AS sv ON sv.id_cliente = c.id

LEFT JOIN (
    SELECT 
        va.id_cliente,
        COUNT(*)                        AS compras_accesorios,
        COALESCE(SUM(va.total),0)       AS monto_accesorios,
        MAX(va.fecha_venta)             AS ultima_accesorio
    FROM ventas_accesorios va
    WHERE va.id_cliente IS NOT NULL AND va.id_cliente > 0
    GROUP BY va.id_cliente
) AS a ON a.id_cliente = c.id

LEFT JOIN (
    SELECT 
        vp.id_cliente,
        COUNT(*)            AS compras_payjoy,
        MAX(vp.fecha_venta) AS ultima_payjoy
    FROM ventas_payjoy_tc vp
    WHERE vp.id_cliente IS NOT NULL AND vp.id_cliente > 0
    GROUP BY vp.id_cliente
) AS p ON p.id_cliente = c.id

WHERE c.id = {$idCliente}
LIMIT 1
";

$resResumen = $conn->query($sqlResumen);
if (!$resResumen || $resResumen->num_rows === 0) {
    die("Cliente no encontrado.");
}
$cliente = $resResumen->fetch_assoc();

// Días sin compra
$hoy = new DateTime();
$diasSinCompra = null;
if ($cliente['ultima_compra'] && $cliente['ultima_compra'] !== '1970-01-01 00:00:00') {
    $dtUlt = new DateTime($cliente['ultima_compra']);
    $diff  = $hoy->diff($dtUlt);
    $diasSinCompra = $diff->days;
}

// =========================
// Última operación (de cualquier tipo)
// =========================

// 🔧 Forzamos todo a utf8mb4 con misma collation para evitar "Illegal mix of collations"
$sqlUltimaOp = "
SELECT * FROM (
    SELECT 
        CONVERT('EQUIPO' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS tipo,
        v.id,
        v.fecha_venta,
        v.precio_venta AS monto,
        CONVERT(v.tag USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS tag,
        CONVERT(s.nombre USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS sucursal
    FROM ventas v
    LEFT JOIN sucursales s ON s.id = v.id_sucursal
    WHERE v.id_cliente = {$idCliente}

    UNION ALL

    SELECT 
        CONVERT('SIM' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS tipo,
        vs.id,
        vs.fecha_venta,
        vs.precio_total AS monto,
        CONVERT(CONCAT('SIM #', vs.id) USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS tag,
        CONVERT(s2.nombre USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS sucursal
    FROM ventas_sims vs
    LEFT JOIN sucursales s2 ON s2.id = vs.id_sucursal
    WHERE vs.id_cliente = {$idCliente}

    UNION ALL

    SELECT 
        CONVERT('ACCESORIO' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS tipo,
        va.id,
        va.fecha_venta,
        va.total AS monto,
        CONVERT(va.tag USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS tag,
        CONVERT(s3.nombre USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS sucursal
    FROM ventas_accesorios va
    LEFT JOIN sucursales s3 ON s3.id = va.id_sucursal
    WHERE va.id_cliente = {$idCliente}

    UNION ALL

    SELECT 
        CONVERT('PAYJOY' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS tipo,
        vp.id,
        vp.fecha_venta,
        NULL AS monto,
        CONVERT(vp.tag USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS tag,
        CONVERT(s4.nombre USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS sucursal
    FROM ventas_payjoy_tc vp
    LEFT JOIN sucursales s4 ON s4.id = vp.id_sucursal
    WHERE vp.id_cliente = {$idCliente}
) AS t
ORDER BY t.fecha_venta DESC
LIMIT 1
";

$resUlt   = $conn->query($sqlUltimaOp);
$ultimaOp = $resUlt && $resUlt->num_rows > 0 ? $resUlt->fetch_assoc() : null;

// =========================
// Historial por tipo
// =========================

// Equipos
$sqlEquipos = "
SELECT 
    v.id,
    v.tag,
    v.fecha_venta,
    v.precio_venta,
    v.tipo_venta,
    v.financiera,
    s.nombre AS sucursal
FROM ventas v
LEFT JOIN sucursales s ON s.id = v.id_sucursal
WHERE v.id_cliente = {$idCliente}
ORDER BY v.fecha_venta DESC
";
$equipos = $conn->query($sqlEquipos);

// SIMs
$sqlSims = "
SELECT 
    vs.id,
    vs.tipo_venta,
    vs.tipo_sim,
    vs.precio_total,
    vs.fecha_venta,
    vs.modalidad,
    s.nombre AS sucursal
FROM ventas_sims vs
LEFT JOIN sucursales s ON s.id = vs.id_sucursal
WHERE vs.id_cliente = {$idCliente}
ORDER BY vs.fecha_venta DESC
";
$sims = $conn->query($sqlSims);

// Accesorios
$sqlAcc = "
SELECT 
    va.id,
    va.tag,
    va.total,
    va.forma_pago,
    va.fecha_venta,
    va.es_regalo,
    s.nombre AS sucursal
FROM ventas_accesorios va
LEFT JOIN sucursales s ON s.id = va.id_sucursal
WHERE va.id_cliente = {$idCliente}
ORDER BY va.fecha_venta DESC
";
$accesorios = $conn->query($sqlAcc);

// PayJoy
$sqlPayjoy = "
SELECT 
    vp.id,
    vp.tag,
    vp.comision,
    vp.comision_gerente,
    vp.fecha_venta,
    s.nombre AS sucursal
FROM ventas_payjoy_tc vp
LEFT JOIN sucursales s ON s.id = vp.id_sucursal
WHERE vp.id_cliente = {$idCliente}
ORDER BY vp.fecha_venta DESC
";
$payjoy = $conn->query($sqlPayjoy);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cliente: <?= htmlspecialchars($cliente['nombre'], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        .badge-estado {
            font-size: 0.75rem;
        }
        .table-sm td, .table-sm th {
            padding: 0.35rem 0.5rem;
        }
    </style>
</head>
<body>

<div class="container-fluid mt-3">

    <a href="catalogo_clientes.php" class="btn btn-sm btn-outline-secondary mb-3">&laquo; Volver al catálogo</a>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h5 class="card-title mb-1">
                        <?= htmlspecialchars($cliente['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                    </h5>
                    <div class="text-muted mb-2">
                        Código: <?= htmlspecialchars($cliente['codigo_cliente'] ?: '-', ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <p class="mb-1">
                        <strong>Teléfono:</strong>
                        <?= htmlspecialchars($cliente['telefono'] ?: '-', ENT_QUOTES, 'UTF-8'); ?><br>
                        <strong>Correo:</strong>
                        <?= htmlspecialchars($cliente['correo'] ?: '-', ENT_QUOTES, 'UTF-8'); ?><br>
                        <strong>Sucursal base:</strong>
                        <?= htmlspecialchars($cliente['sucursal_nombre'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                    <p class="mb-0">
                        <strong>Estado:</strong>
                        <?= $cliente['activo']
                            ? '<span class="badge bg-success badge-estado">Activo</span>'
                            : '<span class="badge bg-secondary badge-estado">Inactivo</span>'; ?>
                        <br>
                        <strong>Fecha alta:</strong>
                        <?= $cliente['fecha_alta'] ? date('Y-m-d H:i', strtotime($cliente['fecha_alta'])) : '—'; ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="card-title">Resumen de comportamiento</h6>
                    <div class="row mb-2">
                        <div class="col-md-3">
                            <div class="small text-muted">Total de compras</div>
                            <div class="h5 mb-0"><?= (int)$cliente['total_compras']; ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">Monto total vendido</div>
                            <div class="h5 mb-0">$<?= number_format((float)$cliente['monto_total'], 2); ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">Última compra</div>
                            <div class="h6 mb-0">
                                <?php
                                if ($cliente['ultima_compra'] && $cliente['ultima_compra'] !== '1970-01-01 00:00:00') {
                                    echo date('Y-m-d H:i', strtotime($cliente['ultima_compra']));
                                } else {
                                    echo '—';
                                }
                                ?>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">Días sin comprar</div>
                            <div class="h6 mb-0">
                                <?= $diasSinCompra !== null ? "{$diasSinCompra} días" : '—'; ?>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="row text-center">
                        <div class="col-md-3">
                            <div class="small text-muted">Equipos</div>
                            <div class="h6 mb-0"><?= (int)$cliente['compras_equipos']; ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">SIMs</div>
                            <div class="h6 mb-0"><?= (int)$cliente['compras_sims']; ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">Accesorios</div>
                            <div class="h6 mb-0"><?= (int)$cliente['compras_accesorios']; ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="small text-muted">PayJoy / TC</div>
                            <div class="h6 mb-0"><?= (int)$cliente['compras_payjoy']; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($ultimaOp): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h6 class="card-title mb-2">Última operación</h6>
                <div class="row">
                    <div class="col-md-3">
                        <div class="small text-muted">Tipo</div>
                        <div class="h6 mb-0"><?= htmlspecialchars($ultimaOp['tipo'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Fecha</div>
                        <div class="h6 mb-0"><?= date('Y-m-d H:i', strtotime($ultimaOp['fecha_venta'])); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Sucursal</div>
                        <div class="h6 mb-0"><?= htmlspecialchars($ultimaOp['sucursal'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="small text-muted">Monto</div>
                        <div class="h6 mb-0">
                            <?php
                            if ($ultimaOp['monto'] !== null) {
                                echo '$' . number_format((float)$ultimaOp['monto'], 2);
                            } else {
                                echo 'N/D';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                <div class="mt-2">
                    <span class="small text-muted">TAG / Referencia:</span>
                    <?= htmlspecialchars($ultimaOp['tag'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Historial por tipo -->
    <ul class="nav nav-tabs mb-3" id="tabHistorial" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-equipos" data-bs-toggle="tab"
                    data-bs-target="#panel-equipos" type="button" role="tab">
                Equipos
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-sims" data-bs-toggle="tab"
                    data-bs-target="#panel-sims" type="button" role="tab">
                SIMs
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-acc" data-bs-toggle="tab"
                    data-bs-target="#panel-acc" type="button" role="tab">
                Accesorios
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-payjoy" data-bs-toggle="tab"
                    data-bs-target="#panel-payjoy" type="button" role="tab">
                PayJoy / TC
            </button>
        </li>
    </ul>

    <div class="tab-content">

        <!-- Equipos -->
        <div class="tab-pane fade show active" id="panel-equipos" role="tabpanel">
            <div class="table-responsive mb-4">
                <table class="table table-striped table-hover table-sm align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Fecha</th>
                        <th>TAG</th>
                        <th>Sucursal</th>
                        <th>Tipo venta</th>
                        <th>Financiera</th>
                        <th class="text-end">Precio venta</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($equipos && $equipos->num_rows > 0): ?>
                        <?php while ($v = $equipos->fetch_assoc()): ?>
                            <tr>
                                <td><?= date('Y-m-d H:i', strtotime($v['fecha_venta'])); ?></td>
                                <td><?= htmlspecialchars($v['tag'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars($v['sucursal'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars($v['tipo_venta'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars($v['financiera'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-end">$<?= number_format((float)$v['precio_venta'], 2); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center text-muted">Sin ventas de equipos.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- SIMs -->
        <div class="tab-pane fade" id="panel-sims" role="tabpanel">
            <div class="table-responsive mb-4">
                <table class="table table-striped table-hover table-sm align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Fecha</th>
                        <th>Sucursal</th>
                        <th>Tipo venta</th>
                        <th>Tipo SIM</th>
                        <th>Modalidad</th>
                        <th class="text-end">Precio total</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($sims && $sims->num_rows > 0): ?>
                        <?php while ($v = $sims->fetch_assoc()): ?>
                            <tr>
                                <td><?= date('Y-m-d H:i', strtotime($v['fecha_venta'])); ?></td>
                                <td><?= htmlspecialchars($v['sucursal'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars($v['tipo_venta'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars($v['tipo_sim'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars($v['modalidad'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-end">$<?= number_format((float)$v['precio_total'], 2); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center text-muted">Sin ventas de SIMs.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Accesorios -->
        <div class="tab-pane fade" id="panel-acc" role="tabpanel">
            <div class="table-responsive mb-4">
                <table class="table table-striped table-hover table-sm align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Fecha</th>
                        <th>TAG</th>
                        <th>Sucursal</th>
                        <th>Forma pago</th>
                        <th>¿Regalo?</th>
                        <th class="text-end">Total</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($accesorios && $accesorios->num_rows > 0): ?>
                        <?php while ($v = $accesorios->fetch_assoc()): ?>
                            <tr>
                                <td><?= date('Y-m-d H:i', strtotime($v['fecha_venta'])); ?></td>
                                <td><?= htmlspecialchars($v['tag'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars($v['sucursal'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars($v['forma_pago'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= $v['es_regalo'] ? 'Sí' : 'No'; ?></td>
                                <td class="text-end">$<?= number_format((float)$v['total'], 2); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center text-muted">Sin ventas de accesorios.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- PayJoy / TC -->
        <div class="tab-pane fade" id="panel-payjoy" role="tabpanel">
            <div class="table-responsive mb-4">
                <table class="table table-striped table-hover table-sm align-middle">
                    <thead class="table-light">
                    <tr>
                        <th>Fecha</th>
                        <th>TAG</th>
                        <th>Sucursal</th>
                        <th class="text-end">Comisión ej.</th>
                        <th class="text-end">Comisión ger.</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($payjoy && $payjoy->num_rows > 0): ?>
                        <?php while ($v = $payjoy->fetch_assoc()): ?>
                            <tr>
                                <td><?= date('Y-m-d H:i', strtotime($v['fecha_venta'])); ?></td>
                                <td><?= htmlspecialchars($v['tag'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars($v['sucursal'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-end">$<?= number_format((float)$v['comision'], 2); ?></td>
                                <td class="text-end">$<?= number_format((float)$v['comision_gerente'], 2); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted">Sin operaciones PayJoy / TC.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
