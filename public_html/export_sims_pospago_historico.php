<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}
require_once 'db.php';

date_default_timezone_set('America/Mexico_City');

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

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=historial_ventas_sims_pospago_historico.xls");
header("Pragma: no-cache");
header("Expires: 0");
echo "\xEF\xBB\xBF";

$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol         = trim((string)($_SESSION['rol'] ?? ''));
$rolLower    = strtolower($rol);
$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);

$where  = " WHERE 1=1 ";
$params = [];
$types  = "";

$where .= " AND LOWER(vs.tipo_venta) = 'pospago' ";

$desdeGet = trim((string)($_GET['desde'] ?? ''));
$hastaGet = trim((string)($_GET['hasta'] ?? ''));

if ($desdeGet !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desdeGet)) {
    $where   .= " AND DATE(vs.fecha_venta) >= ? ";
    $params[] = $desdeGet;
    $types   .= "s";
}
if ($hastaGet !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hastaGet)) {
    $where   .= " AND DATE(vs.fecha_venta) <= ? ";
    $params[] = $hastaGet;
    $types   .= "s";
}

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

if ($tenantPropiedad === 'Luga' && strpos($rolLower, 'subdis') !== false) {
    $tenantPropiedad = 'Subdis';
    $tenantIdSubdis  = (int)($_SESSION['id_subdis'] ?? $tenantIdSubdis);
}
$esSubdis = ($tenantPropiedad === 'Subdis');

$colVsProp = hasColumn($conn, 'ventas_sims', 'propiedad');
$colVsSub  = hasColumn($conn, 'ventas_sims', 'id_subdis');

if ($esSubdis) {
    if ($colVsProp) {
        $where .= " AND vs.propiedad='Subdis' ";
    }
    if ($colVsSub) {
        $where .= " AND vs.id_subdis=? ";
        $params[] = $tenantIdSubdis;
        $types   .= "i";
    } else {
        if ($colSucSubtipo) $where .= " AND s.subtipo='Subdistribuidor' ";
        if ($colSucIdSubdis && $tenantIdSubdis > 0) {
            $where .= " AND s.id_subdis=? ";
            $params[] = $tenantIdSubdis;
            $types   .= "i";
        }
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

if ($rolLower === 'subdis_ejecutivo' || $rol === 'Ejecutivo') {
    $where .= " AND vs.id_usuario=? ";
    $params[] = $idUsuario;
    $types .= "i";
} elseif ($rolLower === 'subdis_gerente' || $rol === 'Gerente') {
    $where .= " AND vs.id_sucursal=? ";
    $params[] = $id_sucursal;
    $types .= "i";
}

$usuarioGet   = $_GET['usuario'] ?? '';
$buscarGet    = $_GET['buscar'] ?? '';

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
if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

echo "<table border='1'>";
echo "<thead>
        <tr style='background-color:#e8f5e9'>
            <th colspan='15'>Historial de Ventas SIM — POSPAGO (Histórico)</th>
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
    $dnOut = $dn !== '' ? "=\"" . htmlspecialchars($dn) . "\"" : '';

    if (!empty($row['es_esim'])) {
        $iccidCell = "eSIM";
    } else {
        $iccid = htmlspecialchars((string)($row['iccid'] ?? ''));
        $iccidCell = $iccid !== '' ? "=\"{$iccid}\"" : '';
    }

    echo "<tr>
            <td>{$row['id_venta']}</td>
            <td>{$row['fecha_venta']}</td>
            <td>".htmlspecialchars((string)$row['sucursal'])."</td>
            <td>".htmlspecialchars((string)$row['usuario'])."</td>
            <td>=\"".htmlspecialchars((string)$row['numero_cliente'])."\"</td>
            <td>".htmlspecialchars((string)$row['nombre_cliente'])."</td>
            <td>{$dnOut}</td>
            <td>{$iccidCell}</td>
            <td>".htmlspecialchars((string)$row['tipo_sim'])."</td>
            <td>".htmlspecialchars((string)$row['tipo_venta'])."</td>
            <td>".htmlspecialchars((string)$row['modalidad'])."</td>
            <td>{$row['precio_total']}</td>
            <td>{$row['comision_ejecutivo']}</td>
            <td>{$row['comision_gerente']}</td>
            <td>".htmlspecialchars((string)$row['comentarios'])."</td>
          </tr>";
}

echo "</tbody></table>";
exit;
?>