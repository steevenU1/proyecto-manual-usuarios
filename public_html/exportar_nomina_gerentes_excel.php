<?php
// exportar_nomina_gerentes_excel.php
// Exporta a Excel la nómina de Gerentes de Zona para la semana seleccionada (mar→lun)

session_start();
if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ======================
// Parámetros de entrada
// ======================
$fechaInicio = $_GET['semana'] ?? ''; // viene como Y-m-d desde reporte_nomina_gerentes_zona
if (!$fechaInicio || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio)) {
    die('Parámetro de semana inválido.');
}

// Rango mar–lun
$ini = new DateTime($fechaInicio . ' 00:00:00');
$fin = (clone $ini)->modify('+6 days')->setTime(23,59,59);
$iniStr = $ini->format('Y-m-d H:i:s');
$finStr = $fin->format('Y-m-d H:i:s');

// ======================
// Cargar histórico de comisiones por zona
// ======================
$stmt = $conn->prepare("
    SELECT cgz.*, u.nombre AS gerente
    FROM comisiones_gerentes_zona cgz
    INNER JOIN usuarios u ON u.id = cgz.id_gerente
    WHERE cgz.fecha_inicio = ?
    ORDER BY cgz.zona
");
$stmt->bind_param("s", $fechaInicio);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($r = $res->fetch_assoc()) {
    $z = trim((string)$r['zona']);
    if ($z === '') $z = 'Sin zona';
    $r['zona'] = $z;
    $rows[] = $r;
}
$stmt->close();

if (!$rows) {
    // Si no hay datos, aún así generamos un Excel con encabezados vacíos
    $rows = [];
}

// ======================
// MAPAS de apoyo por zona (unidades, cuotas, etc.)
// ======================

/* ---- Equipos vs Módems por zona ---- */
$subAggEq = "
  SELECT
      v.id,
      v.id_sucursal,
      DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS dia,
      SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE 1 END) AS uds_eq,
      SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0 ELSE dv.precio_unitario END) AS monto_eq,
      SUM(CASE WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 1 ELSE 0 END) AS uds_modem
  FROM ventas v
  LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
  LEFT JOIN productos p     ON p.id = dv.id_producto
  WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY v.id
";

$sqlEqZona = "
  SELECT
      s.zona,
      IFNULL(SUM(va.monto_eq),0)     AS ventas_eq,
      IFNULL(SUM(va.uds_eq),0)       AS uds_eq,
      IFNULL(SUM(va.uds_modem),0)    AS uds_modem
  FROM sucursales s
  LEFT JOIN ( $subAggEq ) va ON va.id_sucursal = s.id
  GROUP BY s.zona
";

$stEq = $conn->prepare($sqlEqZona);
$stEq->bind_param("ss", $iniStr, $finStr);
$stEq->execute();
$rEq = $stEq->get_result();
$ventasZona   = []; // $ equipos sin módem/mifi
$udsEqZona    = [];
$udsModemZona = [];
while ($r = $rEq->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $ventasZona[$z]   = (float)$r['ventas_eq'];
    $udsEqZona[$z]    = (int)$r['uds_eq'];
    $udsModemZona[$z] = (int)$r['uds_modem'];
}
$stEq->close();

/* ---- SIMs por zona: Nueva Bait / Porta Bait / ATT/Une y total ---- */
$stUS = $conn->prepare("
  SELECT
      s.zona,
      SUM(CASE WHEN vs.tipo_sim = 'Bait' AND vs.tipo_venta = 'Nueva'        THEN 1 ELSE 0 END) AS uds_nueva_bait,
      SUM(CASE WHEN vs.tipo_sim = 'Bait' AND vs.tipo_venta = 'Portabilidad' THEN 1 ELSE 0 END) AS uds_porta_bait,
      SUM(CASE WHEN vs.tipo_sim = 'ATT'                                      THEN 1 ELSE 0 END) AS uds_att,
      COUNT(dvs.id) AS uds_sims
  FROM detalle_venta_sims dvs
  INNER JOIN ventas_sims vs ON dvs.id_venta = vs.id
  INNER JOIN sucursales s   ON s.id = vs.id_sucursal
  WHERE DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY s.zona
");
$stUS->bind_param("ss", $iniStr, $finStr);
$stUS->execute();
$rUS = $stUS->get_result();
$udsNuevaZona = [];
$udsPortaZona = [];
$udsATTZona   = [];
$udsSimsZona  = [];
while ($r = $rUS->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $udsNuevaZona[$z] = (int)$r['uds_nueva_bait'];
    $udsPortaZona[$z] = (int)$r['uds_porta_bait'];
    $udsATTZona[$z]   = (int)$r['uds_att'];
    $udsSimsZona[$z]  = (int)$r['uds_sims'];
}
$stUS->close();

/* ---- Pospago Bait por zona ---- */
$stUP = $conn->prepare("
  SELECT
      s.zona,
      COUNT(dvs.id) AS uds_pos_bait
  FROM detalle_venta_sims dvs
  INNER JOIN ventas_sims vs ON dvs.id_venta = vs.id
  INNER JOIN sucursales s   ON s.id = vs.id_sucursal
  WHERE DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    AND vs.tipo_venta = 'Pospago'
    AND vs.tipo_sim   = 'Bait'
  GROUP BY s.zona
");
$stUP->bind_param("ss", $iniStr, $finStr);
$stUP->execute();
$rUP = $stUP->get_result();
$udsPosZona = [];
while ($r = $rUP->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $udsPosZona[$z] = (int)$r['uds_pos_bait'];
}
$stUP->close();

/* ---- Tarjeta de Crédito PayJoy por zona ---- */
$stPJ = $conn->prepare("
  SELECT
      s.zona,
      COUNT(vp.id) AS uds_pj
  FROM ventas_payjoy_tc vp
  INNER JOIN sucursales s ON s.id = vp.id_sucursal
  WHERE DATE(CONVERT_TZ(vp.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY s.zona
");
$stPJ->bind_param("ss", $iniStr, $finStr);
$stPJ->execute();
$rPJ = $stPJ->get_result();
$udsPayZona = [];
while ($r = $rPJ->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $udsPayZona[$z] = (int)$r['uds_pj'];
}
$stPJ->close();

/* ---- Cuotas vigentes por SUCURSAL y suma por ZONA ---- */
$stC = $conn->prepare("
  SELECT s.zona, cv.cuota_monto
  FROM sucursales s
  LEFT JOIN (
    SELECT c1.id_sucursal, c1.cuota_monto
    FROM cuotas_sucursales c1
    JOIN (
      SELECT id_sucursal, MAX(fecha_inicio) AS max_f
      FROM cuotas_sucursales
      WHERE fecha_inicio <= ?
      GROUP BY id_sucursal
    ) x ON x.id_sucursal = c1.id_sucursal AND x.max_f = c1.fecha_inicio
  ) cv ON cv.id_sucursal = s.id
");
$stC->bind_param("s", $iniStr);
$stC->execute();
$rC = $stC->get_result();
$cuotaZona = [];
while ($r = $rC->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $cu = (float)($r['cuota_monto'] ?? 0);
    if (!isset($cuotaZona[$z])) $cuotaZona[$z] = 0.0;
    $cuotaZona[$z] += $cu;
}
$stC->close();

/* ---- % de cumplimiento por zona ---- */
$cumpZona = [];
foreach ($cuotaZona as $z => $cu) {
    $ventas = (float)($ventasZona[$z] ?? 0.0);
    $cumpZona[$z] = $cu > 0 ? ($ventas / $cu) * 100.0 : 0.0;
}

// ======================
// Cabeceras para Excel
// ======================
$filename = "nomina_gerentes_zona_{$fechaInicio}.xls";

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Pragma: no-cache");
header("Expires: 0");

// Opcional: BOM UTF-8
echo "\xEF\xBB\xBF";
?>
<html>
<head>
  <meta charset="UTF-8">
</head>
<body>
<table border="1">
  <thead>
    <tr>
      <th>Zona</th>
      <th>Gerente de Zona</th>
      <th>Cuota Zona ($)</th>
      <th>Ventas Equipos Zona ($)</th>
      <th>% Cumplimiento</th>
      <th>Uds. Equipos</th>
      <th>Uds. Módems</th>
      <th>Uds. SIM Nueva Bait</th>
      <th>Uds. SIM Porta Bait</th>
      <th>Uds. SIM ATT/Une</th>
      <th>Uds. SIMs Totales</th>
      <th>Uds. Pospago Bait</th>
      <th>Uds. Tarjeta Crédito PJ</th>
      <th>Com. Equipos ($)</th>
      <th>Com. Módems ($)</th>
      <th>Com. SIMs ($)</th>
      <th>Com. Pospago + TC PJ ($)</th>
      <th>Total Comisión ($)</th>
    </tr>
  </thead>
  <tbody>
<?php if ($rows): ?>
<?php foreach ($rows as $r):
    $z          = $r['zona'];
    $gerente    = $r['gerente'];

    $cuotaZ     = (float)($cuotaZona[$z]     ?? 0.0);
    $ventasZ    = (float)($ventasZona[$z]    ?? 0.0);
    $cump       = (float)($cumpZona[$z]      ?? 0.0);

    $udsEq      = (int)  ($udsEqZona[$z]     ?? 0);
    $udsMod     = (int)  ($udsModemZona[$z]  ?? 0);
    $udsNueva   = (int)  ($udsNuevaZona[$z]  ?? 0);
    $udsPorta   = (int)  ($udsPortaZona[$z]  ?? 0);
    $udsATT     = (int)  ($udsATTZona[$z]    ?? 0);
    $udsSIMsT   = (int)  ($udsSimsZona[$z]   ?? 0);
    $udsPos     = (int)  ($udsPosZona[$z]    ?? 0);
    $udsPay     = (int)  ($udsPayZona[$z]    ?? 0);

    $comEq      = (float)$r['comision_equipos'];
    $comMod     = (float)$r['comision_modems'];
    $comSIMs    = (float)$r['comision_sims'];
    $comPosTC   = (float)$r['comision_pospago'];
    $comTot     = (float)$r['comision_total'];
?>
    <tr>
      <td><?= h($z) ?></td>
      <td><?= h($gerente) ?></td>
      <td><?= number_format($cuotaZ,2,'.','') ?></td>
      <td><?= number_format($ventasZ,2,'.','') ?></td>
      <td><?= number_format($cump,2,'.','') ?></td>
      <td><?= $udsEq ?></td>
      <td><?= $udsMod ?></td>
      <td><?= $udsNueva ?></td>
      <td><?= $udsPorta ?></td>
      <td><?= $udsATT ?></td>
      <td><?= $udsSIMsT ?></td>
      <td><?= $udsPos ?></td>
      <td><?= $udsPay ?></td>
      <td><?= number_format($comEq,2,'.','') ?></td>
      <td><?= number_format($comMod,2,'.','') ?></td>
      <td><?= number_format($comSIMs,2,'.','') ?></td>
      <td><?= number_format($comPosTC,2,'.','') ?></td>
      <td><?= number_format($comTot,2,'.','') ?></td>
    </tr>
<?php endforeach; ?>
<?php else: ?>
    <tr>
      <td colspan="18">Sin datos de comisiones para la semana seleccionada.</td>
    </tr>
<?php endif; ?>
  </tbody>
</table>
</body>
</html>
