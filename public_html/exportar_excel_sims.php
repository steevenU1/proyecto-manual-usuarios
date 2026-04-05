<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

/* ========================
   Zona horaria y charset
======================== */
date_default_timezone_set('America/Mexico_City');
if (method_exists($conn, 'set_charset')) { @$conn->set_charset('utf8mb4'); }

/* ========================
   Helpers
======================== */
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

function obtenerSemanaPorIndice($offset = 0) {
    $hoy = new DateTime();
    $diaSemana = (int)$hoy->format('N');
    $dif = $diaSemana - 2;
    if ($dif < 0) $dif += 7;

    $inicio = new DateTime();
    $inicio->modify("-{$dif} days")->setTime(0,0,0);

    if ($offset > 0) {
        $inicio->modify("-" . (7*$offset) . " days");
    }

    $fin = clone $inicio;
    $fin->modify("+6 days")->setTime(23,59,59);

    return [$inicio, $fin];
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function bindParams(mysqli_stmt $stmt, string $types, array &$params): void {
  if (!$params) return;
  $refs = [];
  foreach ($params as $k => &$v) { $refs[$k] = &$v; }
  array_unshift($refs, $types);
  @call_user_func_array([$stmt, 'bind_param'], $refs);
}

/* ========================
   Limpia buffers antes de headers
======================== */
if (function_exists('ob_get_level')) {
  while (ob_get_level() > 0) { @ob_end_clean(); }
}

/* ========================
   Encabezados Excel
======================== */
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=historial_ventas_sims.xls");
header("Pragma: no-cache");
header("Expires: 0");
echo "\xEF\xBB\xBF";

/* ========================
   Filtros (semana)
======================== */
$iniGET = $_GET['ini'] ?? '';
$finGET = $_GET['fin'] ?? '';

if ($iniGET !== '' && $finGET !== '') {
    $inicioSemana = $iniGET;
    $finSemana    = $finGET;
    $inicioSemanaObj = DateTime::createFromFormat('Y-m-d', $inicioSemana) ?: new DateTime($inicioSemana);
    $finSemanaObj    = DateTime::createFromFormat('Y-m-d', $finSemana)    ?: new DateTime($finSemana);
    $finSemanaObj->setTime(23,59,59);
} else {
    $semana = (int)($_GET['semana'] ?? 0);
    list($inicioSemanaObj, $finSemanaObj) = obtenerSemanaPorIndice($semana);
    $inicioSemana = $inicioSemanaObj->format('Y-m-d');
    $finSemana    = $finSemanaObj->format('Y-m-d');
}

$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$rol         = trim((string)($_SESSION['rol'] ?? ''));
$rolLower    = strtolower($rol);
$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);

/* ========================
   TENANT (Luga/Subdis + id_subdis) por sucursal sesión
======================== */
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

/* ========================
   WHERE base + tenant gate
======================== */
$where  = " WHERE DATE(vs.fecha_venta) BETWEEN ? AND ? ";
$params = [$inicioSemana, $finSemana];
$types  = "ss";

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

/* ========================
   Roles (Subdis)
   - subdis_admin: todo su subdis (sin filtro extra)
   - subdis_gerente: su sucursal
   - subdis_ejecutivo: sus ventas
   (Luga) Admin: todo Luga; Gerente: sucursal; Ejecutivo: propias
======================== */
if ($rolLower === 'subdis_ejecutivo' || $rol === 'Ejecutivo') {
    $where .= " AND vs.id_usuario=? ";
    $params[] = $idUsuario;
    $types .= "i";
} elseif ($rolLower === 'subdis_gerente' || $rol === 'Gerente') {
    $where .= " AND vs.id_sucursal=? ";
    $params[] = $id_sucursal;
    $types .= "i";
} else {
    // Admin / subdis_admin: no filtro extra (ya va por tenant)
}

/* ========================
   Filtros GET
======================== */
$tipoVentaGet = $_GET['tipo_venta'] ?? '';
$usuarioGet   = $_GET['usuario'] ?? '';

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

/* ========================
   CONSULTA con DN
======================== */
$sql = "
    SELECT 
        vs.id               AS id_venta,
        vs.fecha_venta,
        s.nombre            AS sucursal,
        u.nombre            AS usuario,
        vs.es_esim,
        i.iccid,
        i.dn AS dn_sim,
        vs.tipo_sim,
        vs.tipo_venta,
        vs.modalidad,
        vs.nombre_cliente,
        vs.numero_cliente,
        vs.precio_total,
        vs.comision_ejecutivo,
        vs.comision_gerente,
        vs.comentarios
    FROM ventas_sims vs
    INNER JOIN usuarios   u ON vs.id_usuario  = u.id
    INNER JOIN sucursales s ON vs.id_sucursal = s.id
    LEFT JOIN detalle_venta_sims d ON vs.id   = d.id_venta
    LEFT JOIN inventario_sims    i ON d.id_sim = i.id
    $where
    ORDER BY vs.fecha_venta DESC, vs.id DESC
";

$stmt = $conn->prepare($sql);
bindParams($stmt, $types, $params);
$stmt->execute();

$rows = [];
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) { $rows[] = $r; }
$stmt->close();

/* ========================
   Encabezado
======================== */
echo "<table border='1'>";
echo "<thead>
        <tr style='background:#f2f2f2'>
            <th>ID Venta</th>
            <th>Fecha</th>
            <th>Sucursal</th>
            <th>Usuario</th>
            <th>Cliente</th>
            <th>Teléfono</th>
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
      </thead><tbody>";

foreach ($rows as $row) {

    $dn = $row['dn_sim'] ?? '';
    $dnOut = $dn !== '' ? '="'.h($dn).'"' : '';

    if (!empty($row['es_esim'])) {
        $iccidOut = "eSIM";
    } else {
        $iccidRaw = (string)($row['iccid'] ?? '');
        $iccidOut = $iccidRaw !== '' ? '="'.h($iccidRaw).'"' : '';
    }

    echo "<tr>
            <td>".(int)$row['id_venta']."</td>
            <td>".h($row['fecha_venta'])."</td>
            <td>".h($row['sucursal'])."</td>
            <td>".h($row['usuario'])."</td>
            <td>".h($row['nombre_cliente'])."</td>
            <td>=\"".h($row['numero_cliente'])."\"</td>
            <td>{$dnOut}</td>
            <td>{$iccidOut}</td>
            <td>".h($row['tipo_sim'])."</td>
            <td>".h($row['tipo_venta'])."</td>
            <td>".h($row['modalidad'])."</td>
            <td>".h($row['precio_total'])."</td>
            <td>".h($row['comision_ejecutivo'])."</td>
            <td>".h($row['comision_gerente'])."</td>
            <td>".h($row['comentarios'])."</td>
        </tr>";
}

echo "</tbody></table>";
exit;
