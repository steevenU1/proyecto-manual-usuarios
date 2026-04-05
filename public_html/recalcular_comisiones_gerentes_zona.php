<?php
/* Recalcula e inserta comisiones para Gerentes de Zona por semana (mar–lun) */
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'Admin') {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ---- Entrada ----
$fechaInicio = $_POST['fecha_inicio'] ?? ''; // Y-m-d (martes)
$semana      = (int)($_POST['semana'] ?? 0);
if (!$fechaInicio || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio)) {
    header("Location: reporte_nomina_gerentes_zona.php?semana={$semana}&msg=Fecha+no+válida");
    exit();
}

// Rango mar–lun
$ini = new DateTime($fechaInicio.' 00:00:00');
$fin = (clone $ini)->modify('+6 days')->setTime(23,59,59);
$iniStr = $ini->format('Y-m-d H:i:s');
$finStr = $fin->format('Y-m-d H:i:s');

// ---- Cargar Gerentes de Zona ----
$gerentes = [];
$resG = $conn->query("
  SELECT u.id AS id_gerente, u.nombre AS gerente, s.zona
  FROM usuarios u
  INNER JOIN sucursales s ON s.id = u.id_sucursal
  WHERE u.rol = 'GerenteZona'
");
while ($r = $resG->fetch_assoc()) {
    $z = trim((string)$r['zona']);
    if ($z === '') $z = 'Sin zona';
    $r['zona'] = $z;
    $gerentes[] = $r;
}
if (!$gerentes) {
    header("Location: reporte_nomina_gerentes_zona.php?semana={$semana}&msg=No+hay+Gerentes+de+Zona");
    exit();
}

/* =========================================================
   VENTAS DE EQUIPOS (separando EQUIPOS vs MÓDEMS)
   - Para cuota de zona: solo se usan ventas de equipos SIN modem/mifi.
   - Para comisión: se pagan equipos y módems según el tramo de % zona.
========================================================= */

// Sub-agregado por venta con desglose
$subAggEq = "
  SELECT
      v.id,
      v.id_sucursal,
      DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) AS dia,
      -- Unidades de equipos (sin módem/MiFi)
      SUM(CASE
            WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
            ELSE 1
          END) AS uds,
      -- Monto de equipos (sin módem/MiFi)
      SUM(CASE
            WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
            ELSE dv.precio_unitario
          END) AS monto,
      -- Unidades de módems/MiFi
      SUM(CASE
            WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 1
            ELSE 0
          END) AS uds_modem,
      -- Monto de módems/MiFi (solo informativo)
      SUM(CASE
            WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN dv.precio_unitario
            ELSE 0
          END) AS monto_modem
  FROM ventas v
  LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
  LEFT JOIN productos p     ON p.id = dv.id_producto
  WHERE DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY v.id
";

$sqlEqZona = "
  SELECT
      s.zona,
      IFNULL(SUM(va.monto),0)       AS ventas_eq,
      IFNULL(SUM(va.uds),0)         AS uds_eq,
      IFNULL(SUM(va.uds_modem),0)   AS uds_modem
  FROM sucursales s
  LEFT JOIN ( $subAggEq ) va ON va.id_sucursal = s.id
  GROUP BY s.zona
";

$stEq = $conn->prepare($sqlEqZona);
$stEq->bind_param("ss", $iniStr, $finStr);
$stEq->execute();
$resEq = $stEq->get_result();

$ventasZona   = [];  // zona => monto equipos (sin módem/mifi)
$udsEqZona    = [];  // zona => uds equipos
$udsModemZona = [];  // zona => uds módems
while ($r = $resEq->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $ventasZona[$z]   = (float)$r['ventas_eq'];
    $udsEqZona[$z]    = (int)$r['uds_eq'];
    $udsModemZona[$z] = (int)$r['uds_modem'];
}
$stEq->close();

/* =========================================================
   SIMs por zona (desglose: Nueva Bait / Porta Bait / ATT)
========================================================= */

$stUS = $conn->prepare("
  SELECT
      s.zona,
      -- SIM Nueva Bait
      SUM(CASE WHEN vs.tipo_sim = 'Bait' AND vs.tipo_venta = 'Nueva'        THEN 1 ELSE 0 END) AS uds_nueva_bait,
      -- SIM Portabilidad Bait
      SUM(CASE WHEN vs.tipo_sim = 'Bait' AND vs.tipo_venta = 'Portabilidad' THEN 1 ELSE 0 END) AS uds_porta_bait,
      -- SIM ATT/Une (cualquier tipo_venta)
      SUM(CASE WHEN vs.tipo_sim = 'ATT'                                      THEN 1 ELSE 0 END) AS uds_att,
      -- Total SIMs (para referencia en reporte)
      COUNT(dvs.id) AS uds_sims
  FROM detalle_venta_sims dvs
  INNER JOIN ventas_sims vs ON dvs.id_venta = vs.id
  INNER JOIN sucursales s   ON s.id = vs.id_sucursal
  WHERE DATE(CONVERT_TZ(vs.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
  GROUP BY s.zona
");
$stUS->bind_param("ss", $iniStr, $finStr);
$stUS->execute();
$resUS = $stUS->get_result();

$udsSimsZona   = []; // total SIMs
$udsNuevaZona  = []; // Nueva Bait
$udsPortaZona  = []; // Porta Bait
$udsATTZona    = []; // ATT/Une
while ($r = $resUS->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $udsNuevaZona[$z] = (int)$r['uds_nueva_bait'];
    $udsPortaZona[$z] = (int)$r['uds_porta_bait'];
    $udsATTZona[$z]   = (int)$r['uds_att'];
    $udsSimsZona[$z]  = (int)$r['uds_sims'];
}
$stUS->close();

/* =========================================================
   POSPAGO BAIT por zona
   - Esquema gerente zona: $30 por cada Pospago Bait en TODOS los tramos.
========================================================= */

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
$resUP = $stUP->get_result();

$udsPosZona = []; // zona => uds Pospago Bait
$comPosZona = []; // zona => $ pospago Bait
while ($r = $resUP->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $uds = (int)$r['uds_pos_bait'];
    $udsPosZona[$z] = $uds;
    $comPosZona[$z] = $uds * 30; // $30 siempre
}
$stUP->close();

/* =========================================================
   TARJETA DE CRÉDITO PAYJOY por zona
   - Esquema gerente zona: $15 por cada TC PJ (en todos los tramos).
   - Se toma directamente de ventas_payjoy_tc usando id_sucursal.
========================================================= */

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
$resPJ = $stPJ->get_result();

$udsPayZona = []; // zona => uds PayJoy
$comPayZona = []; // zona => $ TC PJ
while ($r = $resPJ->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $uds = (int)$r['uds_pj'];
    $udsPayZona[$z] = $uds;
    $comPayZona[$z] = $uds * 15; // $15 siempre
}
$stPJ->close();

/* =========================================================
   Cuotas vigentes por SUCURSAL y suma por ZONA
   - Para % de zona solo se usa cuota_monto (equipos $).
========================================================= */

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
$resC = $stC->get_result();

$cuotaZona = []; // zona => $
while ($r = $resC->fetch_assoc()) {
    $z = trim((string)$r['zona']); if ($z === '') $z = 'Sin zona';
    $cu = (float)($r['cuota_monto'] ?? 0);
    if (!isset($cuotaZona[$z])) $cuotaZona[$z] = 0.0;
    $cuotaZona[$z] += $cu;
}
$stC->close();

/* =========================================================
   Recalcular semana: limpiar registros de esa semana e insertar
========================================================= */

$conn->begin_transaction();
try {
    // Borra semana
    $stDel = $conn->prepare("DELETE FROM comisiones_gerentes_zona WHERE fecha_inicio = ?");
    $stDel->bind_param("s", $ini->format('Y-m-d'));
    $stDel->execute();
    $stDel->close();

    // Insert preparado
    $stIns = $conn->prepare("
      INSERT INTO comisiones_gerentes_zona
      (id_gerente, fecha_inicio, zona, cuota_zona, ventas_zona, porcentaje_cumplimiento,
       comision_equipos, comision_modems, comision_sims, comision_pospago, comision_total)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");

    foreach ($gerentes as $g) {
        $idGer   = (int)$g['id_gerente'];
        $zona    = $g['zona'];

        $ventas  = (float)($ventasZona[$zona]   ?? 0.0); // solo equipos sin módem/mifi
        $cuota   = (float)($cuotaZona[$zona]    ?? 0.0);

        $udsEq   = (int)  ($udsEqZona[$zona]    ?? 0);
        $udsMod  = (int)  ($udsModemZona[$zona] ?? 0);

        $udsSIMs = (int)  ($udsSimsZona[$zona]  ?? 0);
        $udsNueva = (int) ($udsNuevaZona[$zona] ?? 0);
        $udsPorta = (int) ($udsPortaZona[$zona] ?? 0);
        $udsATT   = (int) ($udsATTZona[$zona]   ?? 0);

        $udsPos  = (int)  ($udsPosZona[$zona]   ?? 0);
        $comPos  = (float)($comPosZona[$zona]   ?? 0.0);

        $comPay  = (float)($comPayZona[$zona]   ?? 0.0);

        // % de cumplimiento de zona (solo equipos vs cuota_monto)
        $cump = $cuota > 0 ? ($ventas / $cuota) * 100.0 : 0.0;

        // ===== Esquema de Gerente de Zona según imagen =====
        // Tramos:
        // <80%     → Eq $10, Modem 0, SIM Nueva 0, SIM Porta 0, SIM ATT 0
        // 80–<100% → Eq $10, Modem $5, Nueva $5, Porta $10, ATT $5
        // ≥100%    → Eq $20, Modem $10, Nueva $10, Porta $20, ATT $5

        if ($cump < 80) {
            $comEq      = $udsEq   * 10;
            $comMod     = 0.0;
            $comNueva   = 0.0;
            $comPorta   = 0.0;
            $comATT     = 0.0;
        } elseif ($cump < 100) {
            $comEq      = $udsEq   * 10;
            $comMod     = $udsMod  * 5;
            $comNueva   = $udsNueva * 5;
            $comPorta   = $udsPorta * 10;
            $comATT     = $udsATT   * 5;
        } else {
            $comEq      = $udsEq   * 20;
            $comMod     = $udsMod  * 10;
            $comNueva   = $udsNueva * 10;
            $comPorta   = $udsPorta * 20;
            $comATT     = $udsATT   * 5;
        }

        // SIMs totales = suma de los tipos (aunque en BD solo guardamos 1 columna)
        $comSIM = $comNueva + $comPorta + $comATT;

        // Pospago Bait + Tarjeta de Crédito PayJoy
        $comPosTotal = $comPos + $comPay;

        $comTot = $comEq + $comMod + $comSIM + $comPosTotal;

        $fi = $ini->format('Y-m-d');
        $stIns->bind_param(
            "issdddddddd",
            $idGer, $fi, $zona, $cuota, $ventas, $cump,
            $comEq, $comMod, $comSIM, $comPosTotal, $comTot
        );
        $stIns->execute();
    }

    $stIns->close();
    $conn->commit();

    header("Location: reporte_nomina_gerentes_zona.php?semana={$semana}&msg=".rawurlencode("✅ Semana recalculada correctamente"));
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    $msg = "Error al recalcular: ".$e->getMessage();
    header("Location: reporte_nomina_gerentes_zona.php?semana={$semana}&msg=".rawurlencode($msg));
    exit();
}
