<?php
// export_catalogo_clientes.php - Export CSV del catalogo de clientes (para Call Center / seguimiento)
// Incluye: totales por tipo, ultimas compras por tipo, ultima compra global, dias sin compra,
//          plazo de ultima venta de equipo y bandera "candidato a recompra" (vence en <= 14 dias).
//
// Respeta permisos/visibilidad por rol (Luga/Subdis) igual que catalogo_clientes.php
// Respeta filtros via GET:
//   q, id_sucursal, solo_activos, solo_dormidos, solo_recompra, ultima_desde, ultima_hasta
//
// CSV con BOM UTF-8 para Excel

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

/* =========================
   Helpers
========================= */
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
    $st->bind_param('s',$table);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_row();
    $st->close();
    return $ok;
}
function csv_cell($v): string {
    $s = (string)($v ?? '');
    $s = str_replace(["\r\n","\r"], "\n", $s);
    $s = str_replace('"', '""', $s);
    return '"' . $s . '"';
}
function fmt_dt($raw): string {
    if (!$raw || $raw === '1970-01-01 00:00:00') return '';
    $ts = strtotime($raw);
    if ($ts === false) return '';
    return date('Y-m-d H:i', $ts);
}
function fmt_date($raw): string {
    if (!$raw || $raw === '1970-01-01 00:00:00') return '';
    $ts = strtotime($raw);
    if ($ts === false) return '';
    return date('Y-m-d', $ts);
}

/* =========================
   Contexto usuario / roles
========================= */
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

/* =========================
   Detectar columnas multi-tenant
========================= */
$VENTAS_HAS_PROP    = table_exists($conn,'ventas') && column_exists($conn,'ventas','propiedad');
$VENTAS_HAS_SUBDIS  = table_exists($conn,'ventas') && column_exists($conn,'ventas','id_subdis');
$SIMS_HAS_PROP      = table_exists($conn,'ventas_sims') && column_exists($conn,'ventas_sims','propiedad');
$SIMS_HAS_SUBDIS    = table_exists($conn,'ventas_sims') && column_exists($conn,'ventas_sims','id_subdis');
$ACC_HAS_PROP       = table_exists($conn,'ventas_accesorios') && column_exists($conn,'ventas_accesorios','propiedad');
$ACC_HAS_SUBDIS     = table_exists($conn,'ventas_accesorios') && column_exists($conn,'ventas_accesorios','id_subdis');
$PAY_HAS_PROP       = table_exists($conn,'ventas_payjoy_tc') && column_exists($conn,'ventas_payjoy_tc','propiedad');
$PAY_HAS_SUBDIS     = table_exists($conn,'ventas_payjoy_tc') && column_exists($conn,'ventas_payjoy_tc','id_subdis');

/* =========================
   Filtros (mismos que la vista)
========================= */
$q               = trim($_GET['q'] ?? '');
$soloActivos     = isset($_GET['solo_activos']) ? 1 : 0;
$idSucursalFiltro= (int)($_GET['id_sucursal'] ?? 0);
$soloDormidos    = isset($_GET['solo_dormidos']) ? 1 : 0;
$soloRecompra    = isset($_GET['solo_recompra']) ? 1 : 0;
$ultimaDesde     = trim($_GET['ultima_desde'] ?? '');
$ultimaHasta     = trim($_GET['ultima_hasta'] ?? '');

$ultimaDesdeDt = $ultimaDesde !== '' ? new DateTime($ultimaDesde . ' 00:00:00') : null;
$ultimaHastaDt = $ultimaHasta !== '' ? new DateTime($ultimaHasta . ' 23:59:59') : null;

$qEsc = $conn->real_escape_string($q);

// Forzar sucursal por rol (igual que vista)
if (in_array($rolUsuario, ['Ejecutivo','Gerente'], true) || $isSubdisGerente || $isSubdisEjecutivo) {
    $idSucursalFiltro = $idSucursalMe;
}

/* =========================
   Condiciones para ultima venta de equipo (plazo) segun visibilidad
========================= */
$condUltEquipo = "v.id_cliente IS NOT NULL AND v.id_cliente > 0";

if ($isSubdisRole) {
    if ($VENTAS_HAS_PROP)   $condUltEquipo .= " AND v.propiedad = 'Subdistribuidor'";
    if ($VENTAS_HAS_SUBDIS && $idSubdisMe > 0) $condUltEquipo .= " AND v.id_subdis = {$idSubdisMe}";
    if ($isSubdisGerente || $isSubdisEjecutivo) $condUltEquipo .= " AND v.id_sucursal = {$idSucursalMe}";
    if ($isSubdisEjecutivo) $condUltEquipo .= " AND v.id_usuario = {$idUsuario}";
} else {
    if ($rolUsuario === 'Ejecutivo')      $condUltEquipo .= " AND v.id_usuario = {$idUsuario}";
    elseif ($rolUsuario === 'Gerente')   $condUltEquipo .= " AND v.id_sucursal = {$idSucursalMe}";
}

/* =========================
   Query principal (igual al catalogo, pero para export sacamos mas columnas)
========================= */
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

    COALESCE(e.compras_equipos, 0)      AS compras_equipos,
    COALESCE(e.monto_equipos, 0)        AS monto_equipos,
    COALESCE(e.ultima_equipo, '1970-01-01 00:00:00') AS ultima_equipo,

    COALESCE(sv.compras_sims, 0)        AS compras_sims,
    COALESCE(sv.monto_sims, 0)          AS monto_sims,
    COALESCE(sv.ultima_sim, '1970-01-01 00:00:00')   AS ultima_sim,

    COALESCE(a.compras_accesorios, 0)   AS compras_accesorios,
    COALESCE(a.monto_accesorios, 0)     AS monto_accesorios,
    COALESCE(a.ultima_accesorio, '1970-01-01 00:00:00') AS ultima_accesorio,

    COALESCE(p.compras_payjoy, 0)       AS compras_payjoy,
    COALESCE(p.ultima_payjoy, '1970-01-01 00:00:00') AS ultima_payjoy,

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

WHERE 1 = 1
";

/* =========================
   Visibilidad por rol (igual que vista)
========================= */
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

// Filtros SQL (activos/sucursal/busqueda)
if ($soloActivos) $sql .= " AND c.activo = 1 ";
if ($idSucursalFiltro > 0) $sql .= " AND c.id_sucursal = {$idSucursalFiltro} ";

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
    http_response_code(500);
    header("Content-Type: text/plain; charset=UTF-8");
    echo "Error en consulta: " . $conn->error;
    exit();
}

/* =========================
   Salida CSV
========================= */
$filename = "catalogo_clientes_" . date('Ymd_His') . ".csv";
header("Content-Type: text/csv; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Pragma: no-cache");
header("Expires: 0");

// BOM UTF-8 para Excel
echo "\xEF\xBB\xBF";

$headers = [
  'codigo_cliente','nombre','telefono','correo','sucursal','activo','fecha_alta',
  'total_compras','monto_total',
  'compras_equipos','monto_equipos','ultima_equipo',
  'compras_sims','monto_sims','ultima_sim',
  'compras_accesorios','monto_accesorios','ultima_accesorio',
  'compras_payjoy','ultima_payjoy',
  'ultima_compra','dias_sin_compra',
  'ultima_equipo_fecha','plazo_ultima_equipo_semanas','equipo_vence_en','dias_para_vencer_plazo',
  'es_candidato_recompra','segmento_callcenter'
];
echo implode(',', array_map('csv_cell', $headers)) . "\n";

$hoy = new DateTime();

while ($row = $res->fetch_assoc()) {

    $ultimaCompraRaw = $row['ultima_compra'] ?? '';
    $ultimaCompraDt  = null;
    $diasSinCompra   = '';

    if ($ultimaCompraRaw && $ultimaCompraRaw !== '1970-01-01 00:00:00') {
        $ultimaCompraDt = new DateTime($ultimaCompraRaw);
        $diasSinCompra   = (string)$hoy->diff($ultimaCompraDt)->days;
    }

    // Filtro PHP: rango ultima compra (como la vista)
    if (($ultimaDesdeDt || $ultimaHastaDt)) {
        if (!$ultimaCompraDt) continue;
        if ($ultimaDesdeDt && $ultimaCompraDt < $ultimaDesdeDt) continue;
        if ($ultimaHastaDt && $ultimaCompraDt > $ultimaHastaDt) continue;
    }

    $esDormido = false;
    if ($diasSinCompra === '') $esDormido = true;
    else if ((int)$diasSinCompra > 90) $esDormido = true;

    if ($soloDormidos && !$esDormido) continue;

    // Plazo ultima venta equipo
    $ultimaEquipoFechaRaw = $row['ultima_equipo_fecha'] ?? '';
    $plazoSemanas         = (int)($row['ultima_equipo_plazo'] ?? 0);
    $venceEn              = '';
    $diasParaVencer       = '';
    $esCandidato          = 0;

    if ($ultimaEquipoFechaRaw && $plazoSemanas > 0) {
        $fv = new DateTime($ultimaEquipoFechaRaw);
        $venc = (clone $fv)->modify('+' . ($plazoSemanas * 7) . ' days');
        $venceEn = $venc->format('Y-m-d');
        $diasParaVencer = (string)((int)$hoy->diff($venc)->format('%r%a'));
        $d = (int)$diasParaVencer;
        if ($d >= 0 && $d <= 14) $esCandidato = 1;
    }

    if ($soloRecompra && $esCandidato !== 1) continue;

    // Segmento simple
    $segmento = 'Seguimiento';
    if ($esCandidato === 1) {
        $segmento = 'Candidato a recompra';
    } elseif ($esDormido) {
        $segmento = 'Dormido (>90d o sin compra)';
    } elseif ($diasSinCompra !== '' && (int)$diasSinCompra <= 30) {
        $segmento = 'Reciente (<=30d)';
    } elseif ((int)($row['total_compras'] ?? 0) === 0) {
        $segmento = 'Sin compras';
    }

    $line = [
      $row['codigo_cliente'] ?? '',
      $row['nombre'] ?? '',
      $row['telefono'] ?? '',
      $row['correo'] ?? '',
      $row['sucursal_nombre'] ?? '',
      ((int)($row['activo'] ?? 0) === 1) ? '1' : '0',
      fmt_date($row['fecha_alta'] ?? ''),

      (string)((int)($row['total_compras'] ?? 0)),
      number_format((float)($row['monto_total'] ?? 0), 2, '.', ''),

      (string)((int)($row['compras_equipos'] ?? 0)),
      number_format((float)($row['monto_equipos'] ?? 0), 2, '.', ''),
      fmt_dt($row['ultima_equipo'] ?? ''),

      (string)((int)($row['compras_sims'] ?? 0)),
      number_format((float)($row['monto_sims'] ?? 0), 2, '.', ''),
      fmt_dt($row['ultima_sim'] ?? ''),

      (string)((int)($row['compras_accesorios'] ?? 0)),
      number_format((float)($row['monto_accesorios'] ?? 0), 2, '.', ''),
      fmt_dt($row['ultima_accesorio'] ?? ''),

      (string)((int)($row['compras_payjoy'] ?? 0)),
      fmt_dt($row['ultima_payjoy'] ?? ''),

      fmt_dt($ultimaCompraRaw),
      $diasSinCompra,

      fmt_dt($ultimaEquipoFechaRaw),
      (string)$plazoSemanas,
      $venceEn,
      $diasParaVencer,

      (string)$esCandidato,
      $segmento
    ];

    echo implode(',', array_map('csv_cell', $line)) . "\n";
}

exit;
