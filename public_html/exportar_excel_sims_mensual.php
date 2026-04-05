<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}
require_once 'db.php';

date_default_timezone_set('America/Mexico_City');

/* ======================
   Helpers
====================== */
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = '{$t}'
              AND COLUMN_NAME  = '{$c}' LIMIT 1";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

/* ======================
   Encabezados XLS
====================== */
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=historial_ventas_sims_mensual.xls");
header("Pragma: no-cache");
header("Expires: 0");
echo "\xEF\xBB\xBF";

/* ======================
   MES / AÑO
====================== */
$mes  = (int)($_GET['mes'] ?? date('n'));
$anio = (int)($_GET['anio'] ?? date('Y'));
if ($mes < 1 || $mes > 12) $mes = (int)date('n');
if ($anio < 2000 || $anio > 2100) $anio = (int)date('Y');

$inicioMes = sprintf('%04d-%02d-01', $anio, $mes);
$finMesObj = new DateTime($inicioMes);
$finMesObj->modify('last day of this month')->setTime(23,59,59);
$finMes = $finMesObj->format('Y-m-d');

/* ======================
   FILTROS BASE
====================== */
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol         = trim((string)($_SESSION['rol'] ?? ''));
$rolLower    = strtolower($rol);
$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);

$where  = " WHERE DATE(vs.fecha_venta) BETWEEN ? AND ? ";
$params = [$inicioMes, $finMes];
$types  = "ss";

/* ======================
   TENANT (Luga/Subdis + id_subdis)
====================== */
$colSucSubtipo  = hasColumn($conn, 'sucursales', 'subtipo');
$colSucIdSubdis = hasColumn($conn, 'sucursales', 'id_subdis');

$tenantPropiedad = 'Luga';
$tenantIdSubdis  = 0;
$subtipoSesion   = '';

if ($id_sucursal > 0) {
    if ($colSucSubtipo && $colSucIdSubdis) {
        $stTen = $conn->prepare("SELECT subtipo, id_subdis FROM sucursales WHERE id=? LIMIT 1");
        $stTen->bind_param("i", $id_sucursal);
    } elseif ($colSucSubtipo) {
        $stTen = $conn->prepare("SELECT subtipo, 0 AS id_subdis FROM sucursales WHERE id=? LIMIT 1");
        $stTen->bind_param("i", $id_sucursal);
    } else {
        $stTen = $conn->prepare("SELECT '' AS subtipo, 0 AS id_subdis FROM sucursales WHERE id=? LIMIT 1");
        $stTen->bind_param("i", $id_sucursal);
    }

    $stTen->execute();
    $rowTen = $stTen->get_result()->fetch_assoc();
    $stTen->close();

    $subtipoSesion  = $rowTen['subtipo'] ?? '';
    $tenantIdSubdis = (int)($rowTen['id_subdis'] ?? 0);

    if (trim((string)$subtipoSesion) === 'Subdistribuidor') {
        $tenantPropiedad = 'Subdis';
    }
}

// fallback por rol
if ($tenantPropiedad === 'Luga' && strpos($rolLower, 'subdis') !== false) {
    $tenantPropiedad = 'Subdis';
    $tenantIdSubdis  = (int)($_SESSION['id_subdis'] ?? $tenantIdSubdis);
}
$esSubdis = ($tenantPropiedad === 'Subdis');

$colVsProp = hasColumn($conn, 'ventas_sims', 'propiedad');
$colVsSub  = hasColumn($conn, 'ventas_sims', 'id_subdis');

/* Tenant gate */
if ($esSubdis) {
    if ($colVsProp) {
        $where .= " AND vs.propiedad='Subdis' ";
    }
    if ($colVsSub) {
        $where .= " AND vs.id_subdis=? ";
        $params[] = $tenantIdSubdis;
        $types   .= "i";
    } else {
        // fallback por sucursal join (s ya está en query)
        if ($colSucSubtipo) $where .= " AND s.subtipo='Subdistribuidor' ";
        if ($colSucIdSubdis && $tenantIdSubdis > 0) { $where .= " AND s.id_subdis=? "; $params[]=$tenantIdSubdis; $types.="i"; }
    }
} else {
    if ($colVsProp) {
        $where .= " AND (vs.propiedad='Luga' OR vs.propiedad IS NULL OR vs.propiedad='') ";
    }
    if ($colVsSub) {
        $where .= " AND (vs.id_subdis IS NULL OR vs.id_subdis=0) ";
    } else {
        if ($colSucSubtipo) $where .= " AND (s.subtipo IS NULL OR s.subtipo='' OR s.subtipo<>'Subdistribuidor') ";
    }
}

/* ======================
   Roles (Subdis + Luga)
====================== */
if ($rolLower === 'subdis_ejecutivo' || $rol === 'Ejecutivo') {
    $where .= " AND vs.id_usuario=? ";
    $params[] = $idUsuario;
    $types .= "i";
} elseif ($rolLower === 'subdis_gerente' || $rol === 'Gerente') {
    $where .= " AND vs.id_sucursal=? ";
    $params[] = $id_sucursal;
    $types .= "i";
} else {
    // Admin/subdis_admin: no filtro extra (ya va por tenant)
}

/* ======================
   Filtros GET
====================== */
$tipoVentaGet = $_GET['tipo_venta'] ?? '';
$usuarioGet   = $_GET['usuario'] ?? '';
$buscarGet    = $_GET['buscar'] ?? '';

if ($tipoVentaGet !== '') {
    $where .= " AND vs.tipo_venta=? ";
    $params[] = $tipoVentaGet;
    $types .= "s";
}
if ($usuarioGet !== '') {
    $where .= " AND vs.id_usuario=? ";
    $params[] = (int)$usuarioGet;
    $types .= "i";
}
if ($buscarGet !== '') {
    $busq = "%".$buscarGet."%";
    $where .= " AND (vs.nombre_cliente LIKE ? OR EXISTS(
                      SELECT 1
                      FROM detalle_venta_sims d2
                      LEFT JOIN inventario_sims i2 ON d2.id_sim=i2.id
                      WHERE d2.id_venta=vs.id AND i2.iccid LIKE ?
                    ))";
    $params[] = $busq;
    $params[] = $busq;
    $types   .= "ss";
}

/* ======================
   CONSULTA CON DN
====================== */
$sql = "
    SELECT 
        vs.id            AS id_venta,
        vs.fecha_venta,
        s.nombre         AS sucursal,
        u.nombre         AS usuario,
        vs.numero_cliente,
        vs.nombre_cliente,
        vs.tipo_venta,
        vs.modalidad,
        vs.precio_total,
        vs.comision_ejecutivo,
        vs.comision_gerente,
        vs.comentarios,
        vs.es_esim,
        vs.tipo_sim,
        i.iccid,
        i.dn AS dn_sim
    FROM ventas_sims vs
    INNER JOIN usuarios u ON vs.id_usuario=u.id
    INNER JOIN sucursales s ON vs.id_sucursal=s.id
    LEFT JOIN detalle_venta_sims d ON vs.id=d.id_venta
    LEFT JOIN inventario_sims i ON d.id_sim=i.id
    $where
    ORDER BY vs.fecha_venta DESC, vs.id DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

/* ======================
   RENDER XLS
====================== */
echo "<table border='1'>";
echo "<thead>
        <tr style='background-color:#e8f5e9'>
            <th colspan='15'>Historial de Ventas SIM — {$mes}/{$anio}</th>
        </tr>
        <tr style='background-color:#f2f2f2'>
            <th>ID Venta</th>
            <th>Fecha</th>
            <th>Sucursal</th>
            <th>Usuario</th>
            <th>Número Cliente</th>
            <th>Cliente</th>
            <th>DN</th>
            <th>ICCID / Tipo</th>
            <th>Operador</th>
            <th>Tipo Venta</th>
            <th>Modalidad</th>
            <th>Precio Total</th>
            <th>Comisión Ejecutivo</th>
            <th>Comisión Gerente</th>
            <th>Comentarios</th>
        </tr>
      </thead>
      <tbody>";

while ($row = $res->fetch_assoc()) {

    $dn = $row['dn_sim'] ?? '';
    $dnOut = $dn !== '' ? "=\"".htmlspecialchars($dn)."\"" : '';

    if ($row['es_esim']) {
        $iccidCell = "eSIM";
    } else {
        $iccid = htmlspecialchars((string)$row['iccid']);
        $iccidCell = $iccid !== '' ? "=\"{$iccid}\"" : '';
    }

    echo "<tr>
            <td>{$row['id_venta']}</td>
            <td>{$row['fecha_venta']}</td>
            <td>".htmlspecialchars($row['sucursal'])."</td>
            <td>".htmlspecialchars($row['usuario'])."</td>
            <td>=\"".htmlspecialchars($row['numero_cliente'])."\"</td>
            <td>".htmlspecialchars($row['nombre_cliente'])."</td>
            <td>{$dnOut}</td>
            <td>{$iccidCell}</td>
            <td>".htmlspecialchars($row['tipo_sim'])."</td>
            <td>".htmlspecialchars($row['tipo_venta'])."</td>
            <td>".htmlspecialchars($row['modalidad'])."</td>
            <td>{$row['precio_total']}</td>
            <td>{$row['comision_ejecutivo']}</td>
            <td>{$row['comision_gerente']}</td>
            <td>".htmlspecialchars($row['comentarios'])."</td>
          </tr>";
}

echo "</tbody></table>";
exit;
