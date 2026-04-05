<?php
// mi_nomina_semana_v2.php — Nómina semanal (EJECUTIVO / GERENTE) — cálculo 1:1 con reporte_nomina_v2
// ✅ Admin/RH: puede "Ver como" cualquier usuario con ?uid=ID o selector
// ✅ Usuario normal: solo ve lo suyo

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';
date_default_timezone_set('America/Mexico_City');

/* ============ Helpers base ============ */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n){ return '$' . number_format((float)$n, 2); }

function hasColumn(mysqli $conn, string $table, string $column): bool {
  $tableEsc  = $conn->real_escape_string($table);
  $columnEsc = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME='{$tableEsc}' AND COLUMN_NAME='{$columnEsc}' LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}
function tableExists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='{$t}' LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}
function userSucursalCol(mysqli $conn): ?string {
  if (hasColumn($conn,'usuarios','id_sucursal')) return 'id_sucursal';
  if (hasColumn($conn,'usuarios','sucursal')) return 'sucursal';
  return null;
}
function dateColVentas(mysqli $conn): string {
  if (hasColumn($conn,'ventas','fecha_venta')) return 'fecha_venta';
  if (hasColumn($conn,'ventas','created_at'))  return 'created_at';
  return 'fecha';
}
function notCanceledFilter(mysqli $conn, string $alias='v'): string {
  return hasColumn($conn,'ventas','estatus')
    ? " AND ({$alias}.estatus IS NULL OR {$alias}.estatus NOT IN ('Cancelada','Cancelado','cancelada','cancelado'))"
    : "";
}

/* Semana operativa Mar→Lun */
function semanaOperativa(int $offset=0): array {
  $tz = new DateTimeZone('America/Mexico_City');
  $hoy = new DateTime('now', $tz);
  $dow = (int)$hoy->format('N'); // 1=Lun..7=Dom
  $dif = $dow - 2; if ($dif < 0) $dif += 7; // Martes=2
  $ini = new DateTime('now', $tz);
  $ini->modify("-{$dif} days")->setTime(0,0,0);
  if ($offset > 0) $ini->modify('-'.(7*$offset).' days');
  $fin = clone $ini; $fin->modify('+6 days')->setTime(23,59,59);
  return [$ini,$fin];
}
function rangoSemanaHumano(DateTime $ini, DateTime $fin): string {
  $meses=[1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
  $d1=(int)$ini->format('j'); $d2=(int)$fin->format('j');
  $m1=(int)$ini->format('n'); $m2=(int)$fin->format('n');
  $y1=(int)$ini->format('Y'); $y2=(int)$fin->format('Y');
  if ($y1===$y2 && $m1===$m2) return "del {$d1} al {$d2} de {$meses[$m1]} de {$y1}";
  return "del {$d1} de {$meses[$m1]} de {$y1} al {$d2} de {$meses[$m2]} de {$y2}";
}

/* no módem/mifi */
function notModemSQL(mysqli $conn): string {
  if (hasColumn($conn,'productos','tipo')) {
    return "LOWER(p.tipo) NOT IN ('modem','módem','mifi','mi-fi','hotspot','router')";
  }
  $cols=[];
  foreach (['marca','modelo','descripcion','nombre_comercial','categoria','codigo_producto'] as $col) {
    if (hasColumn($conn,'productos',$col)) $cols[]="LOWER(COALESCE(p.$col,''))";
  }
  if (!$cols) return '1=1';
  $hay="CONCAT(" . implode(", ' ', ", $cols) . ")";
  return "$hay NOT LIKE '%modem%'
          AND $hay NOT LIKE '%módem%'
          AND $hay NOT LIKE '%mifi%'
          AND $hay NOT LIKE '%mi-fi%'
          AND $hay NOT LIKE '%hotspot%'
          AND $hay NOT LIKE '%router%'";
}

/* ====== EXACTO A ADMIN: Comisión equipos ejecutivo ====== */
function sumDetalleVentaEquiposEjecutivo(mysqli $conn, int $idUsuario, string $iniYmd, string $finYmd, bool $aplicaEspecial): float {
  if (!tableExists($conn,'detalle_venta') || !tableExists($conn,'ventas')) return 0.0;

  $colFecha = dateColVentas($conn);
  $hasComEsp = hasColumn($conn,'detalle_venta','comision_especial');
  $pIni = $iniYmd.' 00:00:00';
  $pFin = $finYmd.' 23:59:59';

  if ($aplicaEspecial && $hasComEsp) {
    $sql="SELECT COALESCE(SUM(d.comision + d.comision_especial),0) AS s
          FROM detalle_venta d
          INNER JOIN ventas v ON v.id=d.id_venta
          WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ?";
  } else {
    $sql="SELECT COALESCE(SUM(d.comision),0) AS s
          FROM detalle_venta d
          INNER JOIN ventas v ON v.id=d.id_venta
          WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ?";
  }

  $stmt=$conn->prepare($sql);
  $stmt->bind_param("iss",$idUsuario,$pIni,$pFin);
  $stmt->execute();
  $row=$stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float)($row['s'] ?? 0);
}

/* ====== EXACTO A ADMIN: Eq # ====== */
function countEquipos(mysqli $conn, int $idUsuario, string $iniYmd, string $finYmd): int {
  if (!tableExists($conn,'ventas')) return 0;

  $colFecha = dateColVentas($conn);
  $pIni = $iniYmd.' 00:00:00';
  $pFin = $finYmd.' 23:59:59';

  $tieneDet = hasColumn($conn,'detalle_venta','id');
  if ($tieneDet && tableExists($conn,'detalle_venta')) {
    $joinProd = hasColumn($conn,'productos','id') ? "LEFT JOIN productos p ON p.id=d.id_producto" : "";
    $condNoModem = notModemSQL($conn);
    $sql="SELECT COUNT(d.id) AS c
          FROM detalle_venta d
          INNER JOIN ventas v ON v.id=d.id_venta
          $joinProd
          WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ? AND ($condNoModem)";
    $stmt=$conn->prepare($sql);
    $stmt->bind_param("iss",$idUsuario,$pIni,$pFin);
    $stmt->execute();
    $row=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
  }

  $tieneTipo = hasColumn($conn,'ventas','tipo_venta');
  $estatusCond = hasColumn($conn,'ventas','estatus')
    ? " AND (v.estatus IS NULL OR v.estatus NOT IN ('Cancelada','Cancelado','cancelada','cancelado'))"
    : "";
  if ($tieneTipo) {
    $sql="SELECT COALESCE(SUM(CASE WHEN LOWER(v.tipo_venta) LIKE '%combo%' THEN 2 ELSE 1 END),0) AS c
          FROM ventas v
          WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ? $estatusCond";
  } else {
    $sql="SELECT COUNT(*) AS c
          FROM ventas v
          WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ? $estatusCond";
  }
  $stmt=$conn->prepare($sql);
  $stmt->bind_param("iss",$idUsuario,$pIni,$pFin);
  $stmt->execute();
  $row=$stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (int)($row['c'] ?? 0);
}

/* ====== EXACTO A ADMIN: elegibles bono provisional ====== */
function countEligibleUnitsForBonus(mysqli $conn, int $idUsuario, string $iniYmd, string $finYmd): int {
  if (!tableExists($conn,'ventas')) return 0;

  $colFecha = dateColVentas($conn);
  $pIni = $iniYmd.' 00:00:00';
  $pFin = $finYmd.' 23:59:59';
  $tieneDet = hasColumn($conn,'detalle_venta','id') && tableExists($conn,'detalle_venta');
  $tieneTipo = hasColumn($conn,'ventas','tipo_venta');
  $tieneCombo = hasColumn($conn,'detalle_venta','es_combo');

  if ($tieneDet) {
    $joinProd = hasColumn($conn,'productos','id') ? "LEFT JOIN productos p ON p.id=d.id_producto" : "";
    $condNoModem = notModemSQL($conn);

    if ($tieneCombo) $condNoCombo="(d.es_combo=0 OR d.es_combo IS NULL)";
    elseif ($tieneTipo) $condNoCombo="(LOWER(v.tipo_venta) NOT LIKE '%combo%')";
    else $condNoCombo="1=1";

    $sql="SELECT COUNT(DISTINCT v.id) AS c
          FROM detalle_venta d
          INNER JOIN ventas v ON v.id=d.id_venta
          $joinProd
          WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ? AND ($condNoCombo) AND ($condNoModem)";
    $stmt=$conn->prepare($sql);
    $stmt->bind_param("iss",$idUsuario,$pIni,$pFin);
    $stmt->execute();
    $row=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
  }

  if ($tieneTipo) {
    $sql="SELECT COUNT(*) AS c
          FROM ventas v
          WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ? AND (LOWER(v.tipo_venta) NOT LIKE '%combo%')";
  } else {
    $sql="SELECT COUNT(*) AS c
          FROM ventas v
          WHERE v.id_usuario=? AND v.$colFecha BETWEEN ? AND ?";
  }
  $stmt=$conn->prepare($sql);
  $stmt->bind_param("iss",$idUsuario,$pIni,$pFin);
  $stmt->execute();
  $row=$stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (int)($row['c'] ?? 0);
}

/* SIMs / PayJoy */
function sumSims(mysqli $conn, int $idUsuario, string $iniYmd, string $finYmd, bool $soloPospago, string $campo='comision_ejecutivo'): float {
  if (!tableExists($conn,'ventas_sims') || !hasColumn($conn,'ventas_sims',$campo) || !hasColumn($conn,'ventas_sims','fecha_venta')) return 0.0;
  $pIni=$iniYmd.' 00:00:00'; $pFin=$finYmd.' 23:59:59';
  if ($soloPospago) {
    $sql="SELECT COALESCE(SUM($campo),0) AS s
          FROM ventas_sims WHERE id_usuario=? AND tipo_venta='Pospago' AND fecha_venta BETWEEN ? AND ?";
  } else {
    $sql="SELECT COALESCE(SUM($campo),0) AS s
          FROM ventas_sims WHERE id_usuario=? AND tipo_venta<>'Pospago' AND fecha_venta BETWEEN ? AND ?";
  }
  $stmt=$conn->prepare($sql);
  $stmt->bind_param("iss",$idUsuario,$pIni,$pFin);
  $stmt->execute();
  $row=$stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float)($row['s'] ?? 0);
}
function sumPayjoyTC(mysqli $conn, int $idUsuario, string $iniYmd, string $finYmd, string $campo='comision'): float {
  if (!tableExists($conn,'ventas_payjoy_tc') || !hasColumn($conn,'ventas_payjoy_tc',$campo) || !hasColumn($conn,'ventas_payjoy_tc','fecha_venta')) return 0.0;
  $pIni=$iniYmd.' 00:00:00'; $pFin=$finYmd.' 23:59:59';
  $sql="SELECT COALESCE(SUM($campo),0) AS s
        FROM ventas_payjoy_tc WHERE id_usuario=? AND fecha_venta BETWEEN ? AND ?";
  $stmt=$conn->prepare($sql);
  $stmt->bind_param("iss",$idUsuario,$pIni,$pFin);
  $stmt->execute();
  $row=$stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float)($row['s'] ?? 0);
}

/* Bonos/Ajuste/Descuentos */
function sumAjusteTipo(mysqli $conn, int $idUsuario, string $iniYmd, string $finYmd, string $tipo): float {
  if (!tableExists($conn,'nomina_ajustes_v2')) return 0.0;
  $sql="SELECT COALESCE(SUM(monto),0) AS s
        FROM nomina_ajustes_v2 WHERE id_usuario=? AND semana_inicio=? AND semana_fin=? AND tipo=?";
  $stmt=$conn->prepare($sql);
  $stmt->bind_param("isss",$idUsuario,$iniYmd,$finYmd,$tipo);
  $stmt->execute();
  $row=$stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float)($row['s'] ?? 0);
}
function sumDescuentos(mysqli $conn, int $idUsuario, string $iniYmd, string $finYmd): float {
  if (!tableExists($conn,'descuentos_nomina')) return 0.0;
  $sql="SELECT COALESCE(SUM(monto),0) AS s
        FROM descuentos_nomina WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?";
  $stmt=$conn->prepare($sql);
  $stmt->bind_param("iss",$idUsuario,$iniYmd,$finYmd);
  $stmt->execute();
  $row=$stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (float)($row['s'] ?? 0);
}
function getDescuentosDetalle(mysqli $conn, int $idUsuario, string $iniYmd, string $finYmd): array {
  $out=[];
  if (!tableExists($conn,'descuentos_nomina')) return $out;
  $sql="SELECT concepto, monto, creado_en
        FROM descuentos_nomina
        WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?
        ORDER BY creado_en ASC, id ASC";
  $stmt=$conn->prepare($sql);
  $stmt->bind_param("iss",$idUsuario,$iniYmd,$finYmd);
  $stmt->execute();
  $res=$stmt->get_result();
  while($row=$res->fetch_assoc()) $out[]=$row;
  $stmt->close();
  return $out;
}

/* ============ Selección de usuario (Admin/RH pueden ver cualquiera) ============ */
$viewerId   = (int)($_SESSION['id_usuario'] ?? 0);
$viewerRol  = (string)($_SESSION['rol'] ?? '');
$isPriv     = in_array($viewerRol, ['Admin','RH'], true);

$uidSel = $viewerId;
if ($isPriv && isset($_GET['uid'])) {
  $uidSel = max(1, (int)$_GET['uid']);
}

/* Lista usuarios para selector (solo Admin/RH) */
$usuariosList = [];
if ($isPriv && tableExists($conn,'usuarios')) {
  $colSuc = userSucursalCol($conn);
  if ($colSuc && tableExists($conn,'sucursales')) {
    $sqlU = "SELECT u.id, u.nombre, u.rol, u.$colSuc AS id_sucursal, s.nombre AS sucursal
             FROM usuarios u
             LEFT JOIN sucursales s ON s.id = u.$colSuc
             WHERE (u.activo IS NULL OR u.activo=1)
             ORDER BY s.nombre ASC, u.rol ASC, u.nombre ASC";
  } else {
    $sqlU = "SELECT u.id, u.nombre, u.rol, NULL AS id_sucursal, '' AS sucursal
             FROM usuarios u
             WHERE (u.activo IS NULL OR u.activo=1)
             ORDER BY u.rol ASC, u.nombre ASC";
  }
  if ($rsU = $conn->query($sqlU)) {
    while ($r = $rsU->fetch_assoc()) $usuariosList[] = $r;
    $rsU->close();
  }
}

/* Validar que el uid existe */
if ($uidSel > 0) {
  $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id=? LIMIT 1");
  $stmt->bind_param("i",$uidSel);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$ok) $uidSel = $viewerId;
}

/* ============ Semana ============ */
$offsetSel = isset($_GET['offset']) ? max(0,(int)$_GET['offset']) : 1;
list($dtIni,$dtFin)=semanaOperativa($offsetSel);
$iniLabel=$dtIni->format('Y-m-d');
$finLabel=$dtFin->format('Y-m-d');
$iniStr=$dtIni->format('Y-m-d 00:00:00');
$finStr=$dtFin->format('Y-m-d 23:59:59');
$textoSemanaHumana=rangoSemanaHumano($dtIni,$dtFin);

/* Candado confirmación */
$dtJueves=(clone $dtIni)->modify('+2 days')->setTime(0,0,0);
$ahora=new DateTime('now', new DateTimeZone('America/Mexico_City'));
$puedeConfirmar = ($ahora >= $dtJueves);

/* Datos del usuario objetivo */
$colSuc = userSucursalCol($conn);
$idSucursalUsuario=null; $sueldoBase=0.0; $rolUser=''; $nombreUser='Usuario';
if ($colSuc && tableExists($conn,'usuarios')) {
  $stmt=$conn->prepare("SELECT nombre, COALESCE(rol,'') AS rol, {$colSuc} AS id_sucursal, COALESCE(sueldo,0) AS sueldo_base
                        FROM usuarios WHERE id=? LIMIT 1");
  $stmt->bind_param("i",$uidSel);
  $stmt->execute();
  $row=$stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($row) {
    $nombreUser = (string)$row['nombre'];
    $rolUser = (string)$row['rol'];
    $idSucursalUsuario = (int)$row['id_sucursal'];
    $sueldoBase = (float)$row['sueldo_base'];
  }
}

/* Confirmación existente (del usuario objetivo) */
$ya=['confirmado'=>0,'comentario'=>null,'confirmado_en'=>null,'ip_confirmacion'=>null];
if (tableExists($conn,'nomina_confirmaciones')) {
  $stmt=$conn->prepare("SELECT confirmado, comentario, confirmado_en, ip_confirmacion
                        FROM nomina_confirmaciones WHERE id_usuario=? AND semana_inicio=? AND semana_fin=? LIMIT 1");
  $stmt->bind_param("iss",$uidSel,$iniLabel,$finLabel);
  $stmt->execute();
  $r=$stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($r) $ya=$r;
}

/* Cuota unidades (para aplicaEspecial) */
$cuota_unid=null;
if ($idSucursalUsuario && tableExists($conn,'cuotas_semanales_sucursal')) {
  $stmt=$conn->prepare("SELECT cuota_unidades
                        FROM cuotas_semanales_sucursal
                        WHERE id_sucursal=? AND semana_inicio<=? AND semana_fin>=?
                        ORDER BY id DESC LIMIT 1");
  $stmt->bind_param("iss",$idSucursalUsuario,$iniLabel,$finLabel);
  $stmt->execute();
  $r=$stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($r) $cuota_unid=(int)$r['cuota_unidades'];
}

/* ======= Cálculos (exactos a admin) ======= */
$eq_cnt = countEquipos($conn,$uidSel,$iniLabel,$finLabel);
$aplicaEspecial = (strcasecmp($rolUser,'Ejecutivo')===0 && $cuota_unid !== null && $eq_cnt >= $cuota_unid);

$eq_eje  = sumDetalleVentaEquiposEjecutivo($conn,$uidSel,$iniLabel,$finLabel,$aplicaEspecial);
$sim_eje = sumSims($conn,$uidSel,$iniLabel,$finLabel,false,'comision_ejecutivo');
$pos_eje = sumSims($conn,$uidSel,$iniLabel,$finLabel,true,'comision_ejecutivo');
$tc_eje  = sumPayjoyTC($conn,$uidSel,$iniLabel,$finLabel,'comision');

$bonos   = sumAjusteTipo($conn,$uidSel,$iniLabel,$finLabel,'bono');
$ajuste  = sumAjusteTipo($conn,$uidSel,$iniLabel,$finLabel,'ajuste');
$descs   = sumDescuentos($conn,$uidSel,$iniLabel,$finLabel);
$descuentosDetalle = getDescuentosDetalle($conn,$uidSel,$iniLabel,$finLabel);

/* Bono provisional INFORMATIVO (no entra al total) — igual admin: 50 */
$eligible_units = countEligibleUnitsForBonus($conn,$uidSel,$iniLabel,$finLabel);
$bono_prov = 0.0;
if (strcasecmp($rolUser,'Ejecutivo')===0 && $eligible_units >= 10) {
  $bono_prov = $eligible_units * 50.0;
}

/* Comisión gerente (solo si el usuario objetivo es Gerente) — redistribución igual admin */
$g_eq=0.0; $g_sim=0.0; $g_pos=0.0; $g_tc=0.0;
if (strcasecmp($rolUser,'Gerente')===0 && $idSucursalUsuario && $colSuc) {
  $pIni = $iniStr; $pFin = $finStr;

  if (tableExists($conn,'detalle_venta') && tableExists($conn,'ventas') && tableExists($conn,'usuarios') && hasColumn($conn,'detalle_venta','comision_gerente')) {
    $colFecha = hasColumn($conn,'ventas','fecha_venta') ? 'v.fecha_venta' : (hasColumn($conn,'ventas','created_at') ? 'v.created_at' : 'v.fecha');
    $filtroEstatus = notCanceledFilter($conn,'v');

    $sql="SELECT COALESCE(SUM(d.comision_gerente),0) s
          FROM detalle_venta d
          JOIN ventas v ON v.id=d.id_venta
          JOIN usuarios u ON u.id=v.id_usuario
          WHERE u.{$colSuc}=? AND u.rol<>'Gerente'
            AND {$colFecha} BETWEEN ? AND ? {$filtroEstatus}";
    $stmt=$conn->prepare($sql);
    $stmt->bind_param("iss",$idSucursalUsuario,$pIni,$pFin);
    $stmt->execute();
    $r=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    $g_eq = (float)($r['s'] ?? 0);
  }

  if (tableExists($conn,'ventas_sims') && hasColumn($conn,'ventas_sims','comision_gerente') && hasColumn($conn,'ventas_sims','fecha_venta') && tableExists($conn,'usuarios')) {
    $sqlP="SELECT COALESCE(SUM(vs.comision_gerente),0) s
           FROM ventas_sims vs
           JOIN usuarios u ON u.id=vs.id_usuario
           WHERE u.{$colSuc}=? AND u.rol<>'Gerente'
             AND vs.fecha_venta BETWEEN ? AND ?
             AND vs.tipo_venta<>'Pospago'";
    $stmt=$conn->prepare($sqlP);
    $stmt->bind_param("iss",$idSucursalUsuario,$pIni,$pFin);
    $stmt->execute();
    $r=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    $g_sim = (float)($r['s'] ?? 0);

    $sqlPo="SELECT COALESCE(SUM(vs.comision_gerente),0) s
            FROM ventas_sims vs
            JOIN usuarios u ON u.id=vs.id_usuario
            WHERE u.{$colSuc}=? AND u.rol<>'Gerente'
              AND vs.fecha_venta BETWEEN ? AND ?
              AND vs.tipo_venta='Pospago'";
    $stmt=$conn->prepare($sqlPo);
    $stmt->bind_param("iss",$idSucursalUsuario,$pIni,$pFin);
    $stmt->execute();
    $r=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    $g_pos = (float)($r['s'] ?? 0);
  }

  if (tableExists($conn,'ventas_payjoy_tc') && hasColumn($conn,'ventas_payjoy_tc','comision_gerente') && hasColumn($conn,'ventas_payjoy_tc','fecha_venta') && tableExists($conn,'usuarios')) {
    $sql="SELECT COALESCE(SUM(t.comision_gerente),0) s
          FROM ventas_payjoy_tc t
          JOIN usuarios u ON u.id=t.id_usuario
          WHERE u.{$colSuc}=? AND u.rol<>'Gerente'
            AND t.fecha_venta BETWEEN ? AND ?";
    $stmt=$conn->prepare($sql);
    $stmt->bind_param("iss",$idSucursalUsuario,$pIni,$pFin);
    $stmt->execute();
    $r=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    $g_tc = (float)($r['s'] ?? 0);
  }
}

/* Total EXACTO a admin (sin bono_prov) */
$totalNomina = $sueldoBase
  + $eq_eje + $sim_eje + $pos_eje + $tc_eje
  + $g_eq + $g_sim + $g_pos + $g_tc
  + $bonos - $descs + $ajuste;

/* Ventas total (informativo) */
$ventasTotal=0.0;
if (tableExists($conn,'ventas')) {
  $colF = hasColumn($conn,'ventas','fecha_venta') ? 'fecha_venta' : (hasColumn($conn,'ventas','created_at') ? 'created_at' : 'fecha');
  $filtroE = notCanceledFilter($conn,'v');
  $sql="SELECT COALESCE(SUM(precio_venta),0) AS total_vta
        FROM ventas v
        WHERE v.id_usuario={$uidSel}
          AND v.{$colF} BETWEEN '{$iniStr}' AND '{$finStr}' {$filtroE}";
  if ($q=$conn->query($sql)) {
    if ($r=$q->fetch_assoc()) $ventasTotal=(float)$r['total_vta'];
  }
}

?>
<div class="container my-4">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div>
      <h3 class="m-0">Mi nómina semanal
        <small class="text-muted">(<?= h($nombreUser) ?>)</small>
      </h3>
      <div class="small text-muted">
        Semana (Mar→Lun): <strong><?= h($textoSemanaHumana) ?></strong><br>
        <?php if ($isPriv): ?>
          <span class="badge text-bg-dark">Modo visor Admin/RH</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="d-flex align-items-center gap-2 flex-wrap">
      <?php if ($isPriv): ?>
        <form method="get" class="d-flex align-items-center gap-2">
          <input type="hidden" name="offset" value="<?= (int)$offsetSel ?>">
          <label class="form-label m-0">Ver como:</label>
          <select name="uid" class="form-select" style="min-width:320px" onchange="this.form.submit()">
            <?php foreach ($usuariosList as $u): ?>
              <?php
                $opt = trim(($u['sucursal'] ?? '').' — '.($u['nombre'] ?? '').' ('.($u['rol'] ?? '').')');
                $sel = ((int)$u['id'] === (int)$uidSel) ? ' selected' : '';
              ?>
              <option value="<?= (int)$u['id'] ?>"<?= $sel ?>><?= h($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>

      <form class="d-flex align-items-center gap-2" method="get">
        <?php if ($isPriv): ?>
          <input type="hidden" name="uid" value="<?= (int)$uidSel ?>">
        <?php endif; ?>
        <label class="form-label m-0">Semana:</label>
        <select name="offset" class="form-select" style="min-width:240px">
          <?php
          for ($i=0;$i<=7;$i++){
            list($wIni,$wFin)=semanaOperativa($i);
            $lbl=rangoSemanaHumano($wIni,$wFin);
            $titulo = ($i===0?'Semana actual':($i===1?'Semana pasada':"Hace {$i} semanas"));
            $sel = ($i===$offsetSel)?' selected':'';
            echo '<option value="'.$i.'"'.$sel.'>'.$titulo.' — '.$lbl.'</option>';
          }
          ?>
        </select>
        <button class="btn btn-outline-primary">Ver</button>
      </form>
    </div>
  </div>

  <p class="text-muted mb-3">
    <?php if (!$puedeConfirmar): ?>
      <span class="badge text-bg-warning mt-1">Confirmación disponible a partir del jueves 00:00</span>
    <?php endif; ?>
  </p>

  <div class="row g-3">
    <div class="col-12 col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Eq # (sin módem)</div>
          <div class="fs-5 fw-bold"><?= number_format($eq_cnt) ?></div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="text-muted small">Suma de ventas</div>
          <div class="fs-5 fw-bold"><?= money($ventasTotal) ?></div>
        </div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="card shadow-sm border-success">
        <div class="card-body">
          <div class="text-muted small">Total a pagar</div>
          <div class="fs-3 fw-bold text-success"><?= money($totalNomina) ?></div>
          <div class="small text-muted">Total calculado igual que la vista Admin.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-4">
    <h5>Detalle de conceptos</h5>
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle">
        <thead>
          <tr>
            <th>Concepto</th>
            <th class="text-end">Monto</th>
          </tr>
        </thead>
        <tbody>
          <tr><td>Sueldo base</td><td class="text-end"><?= money($sueldoBase) ?></td></tr>
          <tr><td>Eq</td><td class="text-end"><?= money($eq_eje) ?></td></tr>
          <tr><td>SIMs</td><td class="text-end"><?= money($sim_eje) ?></td></tr>
          <tr><td>Posp</td><td class="text-end"><?= money($pos_eje) ?></td></tr>
          <tr><td>TC</td><td class="text-end"><?= money($tc_eje) ?></td></tr>

          <?php if (strcasecmp($rolUser,'Gerente')===0): ?>
            <tr><td>G. Eq</td><td class="text-end"><?= money($g_eq) ?></td></tr>
            <tr><td>G. SIMs</td><td class="text-end"><?= money($g_sim) ?></td></tr>
            <tr><td>G. Posp</td><td class="text-end"><?= money($g_pos) ?></td></tr>
            <tr><td>G. TC</td><td class="text-end"><?= money($g_tc) ?></td></tr>
          <?php endif; ?>

          <tr><td>Bonos</td><td class="text-end"><?= money($bonos) ?></td></tr>
          <tr><td>Desc</td><td class="text-end text-danger">-<?= money($descs) ?></td></tr>
          <tr><td>Ajuste</td><td class="text-end"><?= money($ajuste) ?></td></tr>

          <!-- ✅ Eliminado: Bono prov. (informativo) -->

          <tr class="table-success fw-bold">
            <td>Total</td>
            <td class="text-end"><?= money($totalNomina) ?></td>
          </tr>
        </tbody>
      </table>
    </div>

    <?php if (!empty($descuentosDetalle)): ?>
      <div class="mt-2">
        <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#detalleDescuentos">
          Ver detalle de descuentos
        </button>
      </div>
      <div class="collapse mt-2" id="detalleDescuentos">
        <div class="card card-body border-danger-subtle">
          <h6 class="mb-2">Detalle de descuentos</h6>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead><tr><th>Concepto</th><th class="text-end">Monto</th><th>Fecha</th></tr></thead>
              <tbody>
              <?php foreach ($descuentosDetalle as $d): ?>
                <tr>
                  <td><?= h($d['concepto'] ?? '') ?></td>
                  <td class="text-end text-danger">-<?= money($d['monto'] ?? 0) ?></td>
                  <td class="text-nowrap"><?= h(isset($d['creado_en']) ? substr($d['creado_en'],0,19) : '') ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="mt-4 d-flex align-items-center justify-content-between">
    <h5 class="m-0">Confirmación</h5>
    <?php if ((int)($ya['confirmado'] ?? 0) === 1): ?>
      <span class="badge text-bg-success">Confirmada el <?= h($ya['confirmado_en'] ?? '') ?></span>
    <?php else: ?>
      <span class="badge text-bg-warning">Pendiente</span>
    <?php endif; ?>
  </div>

  <?php if (!$isPriv && (int)$uidSel === (int)$viewerId): ?>
    <!-- Solo el dueño puede confirmar/reabrir su confirmación -->
    <form class="mt-3" method="post" action="guardar_confirmacion_nomina.php">
      <input type="hidden" name="semana_inicio" value="<?= h($iniLabel) ?>">
      <input type="hidden" name="semana_fin" value="<?= h($finLabel) ?>">
      <div class="mb-2">
        <label class="form-label">Comentario (opcional)</label>
        <textarea class="form-control" name="comentario" rows="2" maxlength="255"
          placeholder="Escribe una nota si deseas..."><?= h($ya['comentario'] ?? '') ?></textarea>
      </div>
      <?php if ((int)($ya['confirmado'] ?? 0) === 1): ?>
        <button class="btn btn-outline-secondary" type="submit" name="accion" value="reabrir">Reabrir confirmación</button>
      <?php else: ?>
        <button class="btn btn-primary" type="submit" name="accion" value="confirmar" <?= $puedeConfirmar ? '' : 'disabled' ?>>
          Confirmar mi nómina
        </button>
      <?php endif; ?>
    </form>
  <?php else: ?>
    <div class="alert alert-light border mt-3 small">
      Estás en modo visor. La confirmación solo la puede hacer el propio usuario.
    </div>
  <?php endif; ?>

</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
