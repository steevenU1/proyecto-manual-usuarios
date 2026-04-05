<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

/* ===========================
   Headers para Excel (TSV)
=========================== */
header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=inventario_sucursal.xls");
header("Pragma: no-cache");
header("Expires: 0");

// BOM UTF-8 para que Excel respete acentos
echo "\xEF\xBB\xBF";

/* ===========================
   Helpers
=========================== */
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $rs = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $rs && $rs->num_rows > 0;
}

/** Escapa tabs y saltos para TSV */
function tsv($v): string {
    $s = (string)($v ?? '');
    // Reemplazos mínimos para no romper columnas/filas
    $s = str_replace(["\t", "\r", "\n"], [' ', ' ', ' '], $s);
    return $s;
}

/** Para que Excel no maltrate IMEIs/ICCID (texto puro) */
function excel_text($v): string {
    $s = trim((string)($v ?? ''));
    if ($s === '') return '';
    return "'" . $s; // prefijo apóstrofe
}

/* ===========================
   Filtros/Contexto
=========================== */
$rol         = $_SESSION['rol'] ?? '';
$id_sucursal = (int)($_SESSION['id_sucursal'] ?? 0);

$where  = "WHERE i.estatus IN ('Disponible','En tránsito')";
$params = [];
$types  = "";

if ($rol !== 'Admin') {
    $where   .= " AND i.id_sucursal = ?";
    $params[] = $id_sucursal;
    $types   .= "i";
}

$inventarioTieneCantidad = hasColumn($conn, 'inventario', 'cantidad');

/* ===========================
   SELECT (incluye cantidad lógica)
   - cantidad_inventario si existe
   - cantidad_mostrar:
       * equipo (con IMEI) = 1
       * accesorio (sin IMEI) = i.cantidad (o 0 si no existe col)
=========================== */
$selectCantidad = $inventarioTieneCantidad
    ? " i.cantidad AS cantidad_inventario,
        (CASE WHEN (p.imei1 IS NULL OR p.imei1='') THEN IFNULL(i.cantidad,0) ELSE 1 END) AS cantidad_mostrar "
    : " NULL AS cantidad_inventario,
        (CASE WHEN (p.imei1 IS NULL OR p.imei1='') THEN 0 ELSE 1 END) AS cantidad_mostrar ";

// Si es Admin, incluimos nombre de sucursal en export
$selectSucursal = ($rol === 'Admin')
    ? "s.nombre AS sucursal, "
    : "";

// Join sucursal solo si se va a imprimir
$joinSucursal = ($rol === 'Admin') ? " INNER JOIN sucursales s ON s.id = i.id_sucursal " : "";

$sql = "
    SELECT
        i.id,
        {$selectSucursal}
        p.marca, p.modelo, p.color, p.capacidad,
        p.imei1, p.imei2,
        i.estatus, i.fecha_ingreso,
        $selectCantidad
    FROM inventario i
    INNER JOIN productos p ON p.id = i.id_producto
    $joinSucursal
    $where
    ORDER BY i.fecha_ingreso DESC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

/* ===========================
   Encabezados TSV
=========================== */
$headers = [];
$headers[] = 'ID';
if ($rol === 'Admin') { $headers[] = 'Sucursal'; }
$headers = array_merge($headers, [
    'Marca','Modelo','Color','Capacidad',
    'Cantidad',        // ✅ nueva columna calculada
    'IMEI1','IMEI2',
    'Estatus','Fecha Ingreso'
]);

echo implode("\t", $headers) . "\n";

/* ===========================
   Filas
=========================== */
while ($row = $result->fetch_assoc()) {
    $line = [];

    $line[] = tsv($row['id']);

    if ($rol === 'Admin') {
        $line[] = tsv($row['sucursal'] ?? '');
    }

    $line[] = tsv($row['marca'] ?? '');
    $line[] = tsv($row['modelo'] ?? '');
    $line[] = tsv($row['color'] ?? '');
    $line[] = tsv($row['capacidad'] ?? '');

    // Cantidad calculada
    $cantidad_mostrar = isset($row['cantidad_mostrar']) ? (int)$row['cantidad_mostrar'] : 1;
    $line[] = (string)$cantidad_mostrar;

    // IMEIs protegidos para Excel
    $line[] = excel_text($row['imei1'] ?? '');
    $line[] = excel_text($row['imei2'] ?? '');

    $line[] = tsv($row['estatus'] ?? '');
    $line[] = tsv($row['fecha_ingreso'] ?? '');

    echo implode("\t", $line) . "\n";
}

$stmt->close();
$conn->close();
