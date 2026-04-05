<?php
// catalogo_clientes.php — Catálogo de clientes con resumen de comportamiento y filtros
// + Plazo de última venta (equipos) + Candidato a recompra (faltan <= 2 semanas)
// + Filtro "Solo candidatos a recompra"
// - Oculta columnas Equipos/SIMs/Acc/PayJoy en la vista (se mantienen para export futuro)

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

// =========================
// Helpers compat (column/table exists)
// =========================
function column_exists(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) return false;
    $st->bind_param('ss', $table, $column);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_row();
    $st->close();
    return $ok;
}
function table_exists(mysqli $conn, string $table): bool {
    $st = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? LIMIT 1");
    if (!$st) return false;
    $st->bind_param('s', $table);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_row();
    $st->close();
    return $ok;
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// =========================
// Contexto usuario
// =========================
$rolUsuario   = $_SESSION['rol'] ?? '';
$rolN         = strtolower(trim((string)$rolUsuario));
$idUsuario    = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursalMe = (int)($_SESSION['id_sucursal'] ?? 0);
$idSubdisMe   = (int)($_SESSION['id_subdis'] ?? 0);

// Roles subdis (tolerante a variantes)
$isSubdisAdmin     = in_array($rolN, ['subdis_admin','subdisadmin','subdis-admin','subdistribuidor_admin'], true);
$isSubdisGerente   = in_array($rolN, ['subdis_gerente','subdisgerente','subdis-gerente'], true);
$isSubdisEjecutivo = in_array($rolN, ['subdis_ejecutivo','subdisejecutivo','subdis-ejecutivo'], true);
$isSubdisRole      = ($isSubdisAdmin || $isSubdisGerente || $isSubdisEjecutivo);

// =========================
// Detectar columnas multi-tenant por tabla (si existen)
// =========================
$VENTAS_HAS_PROP    = table_exists($conn,'ventas') && column_exists($conn,'ventas','propiedad');
$VENTAS_HAS_SUBDIS  = table_exists($conn,'ventas') && column_exists($conn,'ventas','id_subdis');
$SIMS_HAS_PROP      = table_exists($conn,'ventas_sims') && column_exists($conn,'ventas_sims','propiedad');
$SIMS_HAS_SUBDIS    = table_exists($conn,'ventas_sims') && column_exists($conn,'ventas_sims','id_subdis');
$ACC_HAS_PROP       = table_exists($conn,'ventas_accesorios') && column_exists($conn,'ventas_accesorios','propiedad');
$ACC_HAS_SUBDIS     = table_exists($conn,'ventas_accesorios') && column_exists($conn,'ventas_accesorios','id_subdis');
$PAY_HAS_PROP       = table_exists($conn,'ventas_payjoy_tc') && column_exists($conn,'ventas_payjoy_tc','propiedad');
$PAY_HAS_SUBDIS     = table_exists($conn,'ventas_payjoy_tc') && column_exists($conn,'ventas_payjoy_tc','id_subdis');

// =========================
// Filtros de búsqueda
// =========================
$q               = trim($_GET['q'] ?? '');
$soloActivos     = isset($_GET['solo_activos']) ? 1 : 0;
$idSucursalFiltro= (int)($_GET['id_sucursal'] ?? 0);
$soloDormidos    = isset($_GET['solo_dormidos']) ? 1 : 0;
$soloRecompra    = isset($_GET['solo_recompra']) ? 1 : 0; // ✅ nuevo
$ultimaDesde     = trim($_GET['ultima_desde'] ?? '');
$ultimaHasta     = trim($_GET['ultima_hasta'] ?? '');

// Normalizamos fechas a objetos DateTime (solo a nivel PHP)
$ultimaDesdeDt = $ultimaDesde !== '' ? new DateTime($ultimaDesde . ' 00:00:00') : null;
$ultimaHastaDt = $ultimaHasta !== '' ? new DateTime($ultimaHasta . ' 23:59:59') : null;

// Escapar búsqueda para LIKE
$qEsc = $conn->real_escape_string($q);

// =========================
// Ajuste de filtro de sucursal según rol
// =========================
if (in_array($rolUsuario, ['Ejecutivo','Gerente'], true) || $isSubdisGerente || $isSubdisEjecutivo) {
    $idSucursalFiltro = $idSucursalMe;
}

// Traer sucursales para el filtro
$sqlSuc = "SELECT id, nombre FROM sucursales ORDER BY nombre";
$rsSuc  = $conn->query($sqlSuc);
$sucursales = $rsSuc ? $rsSuc->fetch_all(MYSQLI_ASSOC) : [];

// =========================
// Condiciones para "última venta de equipo (plazo)" según visibilidad
// =========================
$condUltEquipo = "v.id_cliente IS NOT NULL AND v.id_cliente > 0";

if ($isSubdisRole) {
    if ($VENTAS_HAS_PROP)   $condUltEquipo .= " AND v.propiedad = 'Subdistribuidor'";
    if ($VENTAS_HAS_SUBDIS && $idSubdisMe > 0) $condUltEquipo .= " AND v.id_subdis = {$idSubdisMe}";

    if ($isSubdisGerente || $isSubdisEjecutivo) {
        $condUltEquipo .= " AND v.id_sucursal = {$idSucursalMe}";
    }
    if ($isSubdisEjecutivo) {
        $condUltEquipo .= " AND v.id_usuario = {$idUsuario}";
    }
} else {
    if ($rolUsuario === 'Ejecutivo') {
        $condUltEquipo .= " AND v.id_usuario = {$idUsuario}";
    } elseif ($rolUsuario === 'Gerente') {
        $condUltEquipo .= " AND v.id_sucursal = {$idSucursalMe}";
    }
}

// =========================
// Query principal
// =========================
$sql = "
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

    ue.ultima_equipo_fecha,
    ue.ultima_equipo_plazo,

    -- Se mantienen para export (aunque ya no se muestran en la tabla)
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

-- Última venta de EQUIPO (para plazo + vencimiento)
LEFT JOIN (
    SELECT t.id_cliente,
           t.fecha_venta AS ultima_equipo_fecha,
           t.plazo_semanas AS ultima_equipo_plazo
    FROM (
        SELECT
            v.id_cliente,
            v.fecha_venta,
            v.plazo_semanas,
            ROW_NUMBER() OVER (PARTITION BY v.id_cliente ORDER BY v.fecha_venta DESC, v.id DESC) AS rn
        FROM ventas v
        WHERE {$condUltEquipo}
    ) t
    WHERE t.rn = 1
) ue ON ue.id_cliente = c.id

-- Equipos (agregados generales)
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

-- SIMs
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

-- Accesorios
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

-- PayJoy / TC
LEFT JOIN (
    SELECT 
        vp.id_cliente,
        COUNT(*)            AS compras_payjoy,
        MAX(vp.fecha_venta) AS ultima_payjoy
    FROM ventas_payjoy_tc vp
    WHERE vp.id_cliente IS NOT NULL AND vp.id_cliente > 0
    GROUP BY vp.id_cliente
) AS p ON p.id_cliente = c.id

WHERE 1 = 1
";

// =========================
// ✅ Filtro por rol (VISIBILIDAD)
// =========================
if ($isSubdisRole) {

    $condVentasSubdis = "1=1";
    if ($VENTAS_HAS_PROP)   $condVentasSubdis .= " AND v2.propiedad = 'Subdistribuidor'";
    if ($VENTAS_HAS_SUBDIS && $idSubdisMe > 0) $condVentasSubdis .= " AND v2.id_subdis = {$idSubdisMe}";

    $condSimsSubdis = "1=1";
    if ($SIMS_HAS_PROP)     $condSimsSubdis .= " AND vs2.propiedad = 'Subdistribuidor'";
    if ($SIMS_HAS_SUBDIS && $idSubdisMe > 0) $condSimsSubdis .= " AND vs2.id_subdis = {$idSubdisMe}";

    $condAccSubdis = "1=1";
    if ($ACC_HAS_PROP)      $condAccSubdis .= " AND va2.propiedad = 'Subdistribuidor'";
    if ($ACC_HAS_SUBDIS && $idSubdisMe > 0) $condAccSubdis .= " AND va2.id_subdis = {$idSubdisMe}";

    $condPaySubdis = "1=1";
    if ($PAY_HAS_PROP)      $condPaySubdis .= " AND vp2.propiedad = 'Subdistribuidor'";
    if ($PAY_HAS_SUBDIS && $idSubdisMe > 0) $condPaySubdis .= " AND vp2.id_subdis = {$idSubdisMe}";

    $sql .= "
      AND (
        EXISTS (SELECT 1 FROM ventas v2                 WHERE v2.id_cliente = c.id AND {$condVentasSubdis})
        OR EXISTS (SELECT 1 FROM ventas_sims vs2        WHERE vs2.id_cliente = c.id AND {$condSimsSubdis})
        OR EXISTS (SELECT 1 FROM ventas_accesorios va2  WHERE va2.id_cliente = c.id AND {$condAccSubdis})
        OR EXISTS (SELECT 1 FROM ventas_payjoy_tc vp2   WHERE vp2.id_cliente = c.id AND {$condPaySubdis})
      )
    ";

    if ($isSubdisAdmin) {
        // todo su subdis
    } elseif ($isSubdisGerente) {
        $sql .= "
          AND (
            EXISTS (SELECT 1 FROM ventas v3                 WHERE v3.id_cliente = c.id AND v3.id_sucursal = {$idSucursalMe} AND {$condVentasSubdis})
            OR EXISTS (SELECT 1 FROM ventas_sims vs3        WHERE vs3.id_cliente = c.id AND vs3.id_sucursal = {$idSucursalMe} AND {$condSimsSubdis})
            OR EXISTS (SELECT 1 FROM ventas_accesorios va3  WHERE va3.id_cliente = c.id AND va3.id_sucursal = {$idSucursalMe} AND {$condAccSubdis})
            OR EXISTS (SELECT 1 FROM ventas_payjoy_tc vp3   WHERE vp3.id_cliente = c.id AND vp3.id_sucursal = {$idSucursalMe} AND {$condPaySubdis})
          )
        ";
    } else {
        $sql .= "
          AND (
            EXISTS (SELECT 1 FROM ventas v4                 WHERE v4.id_cliente = c.id AND v4.id_usuario = {$idUsuario} AND {$condVentasSubdis})
            OR EXISTS (SELECT 1 FROM ventas_sims vs4        WHERE vs4.id_cliente = c.id AND vs4.id_usuario = {$idUsuario} AND {$condSimsSubdis})
            OR EXISTS (SELECT 1 FROM ventas_accesorios va4  WHERE va4.id_cliente = c.id AND va4.id_usuario = {$idUsuario} AND {$condAccSubdis})
            OR EXISTS (SELECT 1 FROM ventas_payjoy_tc vp4   WHERE vp4.id_cliente = c.id AND vp4.id_usuario = {$idUsuario} AND {$condPaySubdis})
          )
        ";
    }

} else {

    if ($rolUsuario === 'Ejecutivo') {
        $sql .= "
          AND (
            EXISTS (SELECT 1 FROM ventas v2                 WHERE v2.id_cliente = c.id AND v2.id_usuario = {$idUsuario})
            OR EXISTS (SELECT 1 FROM ventas_sims vs2        WHERE vs2.id_cliente = c.id AND vs2.id_usuario = {$idUsuario})
            OR EXISTS (SELECT 1 FROM ventas_accesorios va2  WHERE va2.id_cliente = c.id AND va2.id_usuario = {$idUsuario})
            OR EXISTS (SELECT 1 FROM ventas_payjoy_tc vp2   WHERE vp2.id_cliente = c.id AND vp2.id_usuario = {$idUsuario})
          )
        ";
    } elseif ($rolUsuario === 'Gerente') {
        $sql .= " AND c.id_sucursal = {$idSucursalMe} ";
    }
}

// Filtro activos
if ($soloActivos) {
    $sql .= " AND c.activo = 1 ";
}

// Filtro por sucursal
if ($idSucursalFiltro > 0) {
    $sql .= " AND c.id_sucursal = {$idSucursalFiltro} ";
}

// Filtro de búsqueda
if ($qEsc !== '') {
    $like = "%{$qEsc}%";
    $like = $conn->real_escape_string($like);
    $sql .= "
      AND (
          c.nombre         LIKE '{$like}'
       OR c.telefono       LIKE '{$like}'
       OR c.codigo_cliente LIKE '{$like}'
      )
    ";
}

$sql .= " ORDER BY ultima_compra DESC, c.nombre ASC ";

$res = $conn->query($sql);
if (!$res) {
    die("Error en consulta: " . $conn->error);
}

// =========================
// Agregados globales (ya con filtros de PHP)
// =========================
$totalClientes        = 0;
$clientesActivos      = 0;
$clientesConCompras   = 0;
$clientesRecientes    = 0;
$clientesDormidos     = 0;
$montoGlobal          = 0.0;
$totalComprasGlobal   = 0;

$hoy  = new DateTime();
$rows = [];

while ($row = $res->fetch_assoc()) {
    $ultimaRaw = $row['ultima_compra'];
    $ultimaDt  = null;
    $diasSinCompra = null;
    $esDormido = false;

    if ($ultimaRaw && $ultimaRaw !== '1970-01-01 00:00:00') {
        $ultimaDt = new DateTime($ultimaRaw);
        $diff     = $hoy->diff($ultimaDt);
        $diasSinCompra = $diff->days;
        if ($diasSinCompra > 90) $esDormido = true;
    } else {
        $esDormido = true;
    }

    // Filtros PHP
    if ($soloDormidos && !$esDormido) continue;

    if (($ultimaDesdeDt || $ultimaHastaDt) && !$ultimaDt) continue;
    if ($ultimaDesdeDt && $ultimaDt && $ultimaDt < $ultimaDesdeDt) continue;
    if ($ultimaHastaDt && $ultimaDt && $ultimaDt > $ultimaHastaDt) continue;

    // =========================
    // Plazo última venta + candidato a recompra
    // =========================
    $row['ultima_equipo_plazo'] = isset($row['ultima_equipo_plazo']) ? (int)$row['ultima_equipo_plazo'] : 0;

    $recompraBadge = '—';
    $plazoTxt = '—';
    $esCandidato = false;

    if (!empty($row['ultima_equipo_fecha']) && $row['ultima_equipo_plazo'] > 0) {
        $plazoTxt = $row['ultima_equipo_plazo'] . " sem";

        $fechaVenta = new DateTime($row['ultima_equipo_fecha']);
        $venc = (clone $fechaVenta)->modify('+' . ($row['ultima_equipo_plazo'] * 7) . ' days');
        $diasParaVencer = (int)$hoy->diff($venc)->format('%r%a'); // con signo

        if ($diasParaVencer >= 0 && $diasParaVencer <= 14) {
            $esCandidato = true;
            $recompraBadge = '<span class="badge bg-primary badge-estado">Candidato a recompra</span>'
                . '<div class="small text-muted">Vence: ' . $venc->format('Y-m-d') . '</div>';
        } elseif ($diasParaVencer < 0) {
            $recompraBadge = '<span class="badge bg-secondary badge-estado">Plazo vencido</span>'
                . '<div class="small text-muted">Venció: ' . $venc->format('Y-m-d') . '</div>';
        } else {
            $recompraBadge = '<span class="badge bg-light text-dark badge-estado">En curso</span>'
                . '<div class="small text-muted">Vence: ' . $venc->format('Y-m-d') . '</div>';
        }

        $row['equipo_vence_en'] = $venc->format('Y-m-d');
        $row['equipo_dias_para_vencer'] = $diasParaVencer;
    }

    // ✅ filtro "solo candidatos"
    if ($soloRecompra && !$esCandidato) {
        continue;
    }

    // Si pasa filtros, contamos KPIs
    $totalClientes++;

    if ((int)$row['activo'] === 1) $clientesActivos++;

    if ((int)$row['total_compras'] > 0) {
        $clientesConCompras++;
        $montoGlobal        += (float)$row['monto_total'];
        $totalComprasGlobal += (int)$row['total_compras'];

        if ($diasSinCompra !== null) {
            if ($diasSinCompra <= 30) $clientesRecientes++;
            elseif ($diasSinCompra > 90) $clientesDormidos++;
        }
    } else {
        if ($esDormido) $clientesDormidos++;
    }

    $row['plazo_ultima_venta_txt'] = $plazoTxt;
    $row['recompra_badge'] = $recompraBadge;
    $row['dias_sin_compra'] = $diasSinCompra;
    $row['es_candidato_recompra'] = $esCandidato ? 1 : 0;

    $rows[] = $row;
}

$porcConCompras          = ($totalClientes > 0) ? ($clientesConCompras / $totalClientes * 100) : 0;
$ticketPromedioGlobal    = ($totalComprasGlobal > 0) ? ($montoGlobal / $totalComprasGlobal) : 0;
$comprasPromedioCliente  = ($clientesConCompras > 0) ? ($totalComprasGlobal / $clientesConCompras) : 0;

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Catálogo de clientes</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <style>
        .badge-estado { font-size: 0.75rem; }
        .table-sm td, .table-sm th { padding: 0.35rem 0.5rem; }
    </style>
</head>
<body>

<div class="container-fluid mt-3">

    <h3 class="mb-3">Catálogo de clientes</h3>

    <!-- Filtros -->
    <form class="row g-2 mb-3" method="get">
        <div class="col-md-3 col-lg-3">
            <input type="text" name="q" value="<?= h($q); ?>"
                   class="form-control" placeholder="Buscar por nombre, teléfono o código...">
        </div>

        <div class="col-md-3 col-lg-2">
            <?php
              $disableSucursal = (in_array($rolUsuario, ['Ejecutivo','Gerente'], true) || $isSubdisGerente || $isSubdisEjecutivo);
            ?>
            <select name="id_sucursal" class="form-select" <?= $disableSucursal ? 'disabled' : ''; ?>>
                <option value="0">Todas las sucursales</option>
                <?php foreach ($sucursales as $s): ?>
                    <option value="<?= (int)$s['id']; ?>"
                        <?= $idSucursalFiltro == (int)$s['id'] ? 'selected' : ''; ?>>
                        <?= h($s['nombre']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if ($disableSucursal): ?>
                <input type="hidden" name="id_sucursal" value="<?= (int)$idSucursalFiltro; ?>">
            <?php endif; ?>
        </div>

        <div class="col-md-2 col-lg-2">
            <input type="date" name="ultima_desde" class="form-control"
                   value="<?= h($ultimaDesde); ?>"
                   placeholder="Última compra desde">
        </div>

        <div class="col-md-2 col-lg-2">
            <input type="date" name="ultima_hasta" class="form-control"
                   value="<?= h($ultimaHasta); ?>"
                   placeholder="Última compra hasta">
        </div>

        <div class="col-md-2 col-lg-3 d-flex align-items-center flex-wrap">
            <div class="form-check me-3">
                <input class="form-check-input" type="checkbox" value="1" id="solo_activos" name="solo_activos"
                    <?= $soloActivos ? 'checked' : ''; ?>>
                <label class="form-check-label" for="solo_activos">
                    Solo activos
                </label>
            </div>

            <div class="form-check me-3">
                <input class="form-check-input" type="checkbox" value="1" id="solo_dormidos" name="solo_dormidos"
                    <?= $soloDormidos ? 'checked' : ''; ?>>
                <label class="form-check-label" for="solo_dormidos">
                    Solo dormidos
                </label>
            </div>

            <div class="form-check me-3">
                <input class="form-check-input" type="checkbox" value="1" id="solo_recompra" name="solo_recompra"
                    <?= $soloRecompra ? 'checked' : ''; ?>>
                <label class="form-check-label" for="solo_recompra">
                    Solo candidatos a recompra
                </label>
            </div>

            <?php
              // ✅ URL de export con filtros actuales (respeta GET tal cual)
              $qsExport  = $_GET;
              $exportUrl = 'export_catalogo_clientes.php' . (!empty($qsExport) ? ('?' . http_build_query($qsExport)) : '');
            ?>

            <button class="btn btn-primary btn-sm mt-2 mt-md-0" type="submit">Aplicar filtros</button>

            <a href="<?= h($exportUrl); ?>"
               class="btn btn-outline-success btn-sm mt-2 mt-md-0 ms-md-2">
               Exportar
            </a>
        </div>
    </form>

    <!-- Resumen rápido (KPIs globales) -->
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Clientes en vista</div>
                    <div class="h5 mb-0"><?= number_format($totalClientes); ?></div>
                    <div class="small text-muted mt-1">
                        Activos: <?= number_format($clientesActivos); ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Clientes con compras</div>
                    <div class="h5 mb-0"><?= number_format($clientesConCompras); ?></div>
                    <div class="small text-muted mt-1">
                        <?= number_format($porcConCompras, 1); ?>% de la vista
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Monto total vendido</div>
                    <div class="h5 mb-0">$<?= number_format($montoGlobal, 2); ?></div>
                    <div class="small text-muted mt-1">
                        Equipos + SIMs + accesorios
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Ticket promedio global</div>
                    <div class="h5 mb-0">
                        $<?= number_format($ticketPromedioGlobal, 2); ?>
                    </div>
                    <div class="small text-muted mt-1">
                        Por operación (solo con compras)
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Compras promedio por cliente</div>
                    <div class="h5 mb-0">
                        <?= number_format($comprasPromedioCliente, 1); ?> compras
                    </div>
                    <div class="small text-muted mt-1">
                        Solo clientes que ya compraron
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Clientes recientes</div>
                    <div class="h5 mb-0"><?= number_format($clientesRecientes); ?></div>
                    <div class="small text-muted mt-1">
                        Última compra ≤ 30 días
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <div class="small text-muted">Clientes dormidos</div>
                    <div class="h5 mb-0"><?= number_format($clientesDormidos); ?></div>
                    <div class="small text-muted mt-1">
                        > 90 días sin compra o nunca
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de clientes -->
    <div class="table-responsive">
        <table class="table table-striped table-hover table-sm align-middle">
            <thead class="table-light">
            <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Teléfono</th>
                <th>Sucursal</th>
                <th class="text-center">Compras</th>
                <th class="text-end">Monto total</th>
                <th>Última compra</th>
                <th class="text-center">Días sin compra</th>
                <th class="text-center">Plazo última venta</th>
                <th>Recompra</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="11" class="text-center text-muted py-4">
                        No se encontraron clientes con los filtros seleccionados.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $c): ?>
                    <?php
                    $ultima    = $c['ultima_compra'];
                    $ultimaTxt = '—';
                    if ($ultima && $ultima !== '1970-01-01 00:00:00') {
                        $ultimaTxt = date('Y-m-d H:i', strtotime($ultima));
                    }

                    $diasSin = $c['dias_sin_compra'];
                    $badgeDias = '—';
                    if ($diasSin !== null) {
                        if ($diasSin <= 30)      $badgeClass = 'bg-success';
                        elseif ($diasSin <= 90)  $badgeClass = 'bg-warning text-dark';
                        else                     $badgeClass = 'bg-danger';
                        $badgeDias = "<span class=\"badge {$badgeClass} badge-estado\">{$diasSin} días</span>";
                    }

                    $badgeActivo = ((int)$c['activo'] === 1) ?
                        '<span class="badge bg-success badge-estado">Activo</span>' :
                        '<span class="badge bg-secondary badge-estado">Inactivo</span>';
                    ?>
                    <tr>
                        <td>
                            <?= h($c['codigo_cliente'] ?: '-'); ?><br>
                            <?= $badgeActivo; ?>
                        </td>
                        <td><?= h($c['nombre']); ?></td>
                        <td><?= h($c['telefono']); ?></td>
                        <td><?= h($c['sucursal_nombre'] ?? '-'); ?></td>
                        <td class="text-center"><?= (int)$c['total_compras']; ?></td>
                        <td class="text-end">$<?= number_format((float)$c['monto_total'], 2); ?></td>
                        <td><?= h($ultimaTxt); ?></td>
                        <td class="text-center"><?= $badgeDias; ?></td>
                        <td class="text-center"><?= h($c['plazo_ultima_venta_txt'] ?? '—'); ?></td>
                        <td><?= $c['recompra_badge'] ?? '—'; ?></td>
                        <td class="text-end">
                            <a href="cliente_detalle.php?id_cliente=<?= (int)$c['id']; ?>"
                               class="btn btn-sm btn-outline-primary">
                                Detalle
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>