<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
    echo json_encode([
        'ok' => false,
        'error' => 'No autenticado'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

/* =========================================================
   CONFIG
========================================================= */
$ROLES_PERMITIDOS = [
    'Admin', 'Administrador',
    'Gerente', 'GerenteZona',
    'Ejecutivo', 'Logistica',
    'Subdis_Admin', 'Subdis_Gerente', 'Subdis_Ejecutivo'
];

$rolSesion = (string)($_SESSION['rol'] ?? '');
if (!in_array($rolSesion, $ROLES_PERMITIDOS, true)) {
    echo json_encode([
        'ok' => false,
        'error' => 'Sin permiso para consultar garantías'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}



/* =========================================================
   HELPERS
========================================================= */
function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}


function sanitize_imei(?string $imei): string {
    $imei = preg_replace('/\s+/', '', (string)$imei);
    $imei = preg_replace('/[^0-9A-Za-z]/', '', $imei);
    return strtoupper(trim($imei));
}

function table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param("s", $table);
    $st->execute();
    $ok = ($st->get_result()->num_rows > 0);
    $st->close();
    return $ok;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param("ss", $table, $column);
    $st->execute();
    $ok = ($st->get_result()->num_rows > 0);
    $st->close();
    return $ok;
}

function first_existing_column(mysqli $conn, string $table, array $candidates): ?string {
    foreach ($candidates as $col) {
        if (column_exists($conn, $table, $col)) {
            return $col;
        }
    }
    return null;
}

function val_or_null($v): ?string {
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function int_or_null_mixed($v): ?int {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return (int)$v;
}

function tipo_equipo_por_combo($esCombo): string {
    return ((int)$esCombo === 1) ? 'combo' : 'principal';
}

/* =========================================================
   VALIDAR PARAMETRO
========================================================= */
$imei = sanitize_imei($_GET['imei'] ?? $_POST['imei'] ?? '');

if ($imei === '') {
    out([
        'ok' => false,
        'error' => 'Debes proporcionar un IMEI'
    ], 400);
}

if (strlen($imei) < 8) {
    out([
        'ok' => false,
        'error' => 'IMEI inválido'
    ], 400);
}

/* =========================================================
   VALIDAR TABLAS BASE
========================================================= */
$requiredTables = ['ventas', 'detalle_venta', 'productos', 'usuarios', 'sucursales'];
foreach ($requiredTables as $tb) {
    if (!table_exists($conn, $tb)) {
        out([
            'ok' => false,
            'error' => "No existe la tabla requerida: {$tb}"
        ], 500);
    }
}

/* =========================================================
   MAPEO DINAMICO DE COLUMNAS
========================================================= */
$ventasCols = [
    'id'                => first_existing_column($conn, 'ventas', ['id']),
    'id_sucursal'       => first_existing_column($conn, 'ventas', ['id_sucursal']),
    'id_usuario'        => first_existing_column($conn, 'ventas', ['id_usuario']),
    'id_cliente'        => first_existing_column($conn, 'ventas', ['id_cliente']),
    'fecha_venta'       => first_existing_column($conn, 'ventas', ['fecha_venta', 'created_at', 'fecha']),
    'tag'               => first_existing_column($conn, 'ventas', ['tag', 'folio', 'ticket', 'ticket_factura']),
    'cliente_nombre'    => first_existing_column($conn, 'ventas', ['nombre_cliente', 'cliente_nombre', 'cliente', 'nombre']),
    'cliente_telefono'  => first_existing_column($conn, 'ventas', ['telefono_cliente', 'telefono', 'cliente_telefono', 'celular_cliente']),
    'cliente_correo'    => first_existing_column($conn, 'ventas', ['correo_cliente', 'cliente_correo', 'correo', 'email']),
    'modalidad'         => first_existing_column($conn, 'ventas', ['modalidad', 'tipo_venta']),
    'financiera'        => first_existing_column($conn, 'ventas', ['financiera']),
    'estatus'           => first_existing_column($conn, 'ventas', ['estatus', 'estado'])
];

$detalleCols = [
    'id'            => first_existing_column($conn, 'detalle_venta', ['id']),
    'id_venta'      => first_existing_column($conn, 'detalle_venta', ['id_venta']),
    'id_producto'   => first_existing_column($conn, 'detalle_venta', ['id_producto']),
    'imei1'         => first_existing_column($conn, 'detalle_venta', ['imei1']),
    'imei2'         => first_existing_column($conn, 'detalle_venta', ['imei2']),
    'precio'        => first_existing_column($conn, 'detalle_venta', ['precio_unitario', 'precio', 'precio_venta']),
    'es_combo'      => first_existing_column($conn, 'detalle_venta', ['es_combo']),
];

$productosCols = [
    'id'            => first_existing_column($conn, 'productos', ['id']),
    'marca'         => first_existing_column($conn, 'productos', ['marca']),
    'modelo'        => first_existing_column($conn, 'productos', ['modelo']),
    'color'         => first_existing_column($conn, 'productos', ['color']),
    'capacidad'     => first_existing_column($conn, 'productos', ['capacidad', 'almacenamiento']),
    'imei1'         => first_existing_column($conn, 'productos', ['imei1']),
    'imei2'         => first_existing_column($conn, 'productos', ['imei2']),
    'proveedor'     => first_existing_column($conn, 'productos', ['proveedor']),
];

$usuariosCols = [
    'id'            => first_existing_column($conn, 'usuarios', ['id']),
    'nombre'        => first_existing_column($conn, 'usuarios', ['nombre', 'usuario', 'nombres']),
];

$sucursalesCols = [
    'id'            => first_existing_column($conn, 'sucursales', ['id']),
    'nombre'        => first_existing_column($conn, 'sucursales', ['nombre']),
];

$clientesCols = [
    'id'            => table_exists($conn, 'clientes') ? first_existing_column($conn, 'clientes', ['id']) : null,
    'nombre'        => table_exists($conn, 'clientes') ? first_existing_column($conn, 'clientes', ['nombre']) : null,
    'telefono'      => table_exists($conn, 'clientes') ? first_existing_column($conn, 'clientes', ['telefono']) : null,
    'correo'        => table_exists($conn, 'clientes') ? first_existing_column($conn, 'clientes', ['correo']) : null,
];

/* =========================================================
   1) BUSCAR EN VENTA ORIGINAL
========================================================= */
function buscar_en_venta(
    mysqli $conn,
    string $imei,
    array $ventasCols,
    array $detalleCols,
    array $productosCols,
    array $usuariosCols,
    array $sucursalesCols,
    array $clientesCols
): ?array {
    $campos = [];

    $campos[] = "v.`{$ventasCols['id']}` AS venta_id";
    $campos[] = "dv.`{$detalleCols['id']}` AS detalle_venta_id";
    $campos[] = "p.`{$productosCols['id']}` AS producto_id";

    $campos[] = $ventasCols['id_cliente'] ? "v.`{$ventasCols['id_cliente']}` AS id_cliente" : "NULL AS id_cliente";
    $campos[] = $clientesCols['id'] ? "c.`{$clientesCols['id']}` AS cliente_id" : "NULL AS cliente_id";

    $campos[] = $clientesCols['nombre']   ? "c.`{$clientesCols['nombre']}` AS cliente_nombre_catalogo" : "NULL AS cliente_nombre_catalogo";
    $campos[] = $clientesCols['telefono'] ? "c.`{$clientesCols['telefono']}` AS cliente_telefono_catalogo" : "NULL AS cliente_telefono_catalogo";
    $campos[] = $clientesCols['correo']   ? "c.`{$clientesCols['correo']}` AS cliente_correo_catalogo" : "NULL AS cliente_correo_catalogo";

    $campos[] = $ventasCols['cliente_nombre']   ? "v.`{$ventasCols['cliente_nombre']}` AS cliente_nombre" : "NULL AS cliente_nombre";
    $campos[] = $ventasCols['cliente_telefono'] ? "v.`{$ventasCols['cliente_telefono']}` AS cliente_telefono" : "NULL AS cliente_telefono";
    $campos[] = $ventasCols['cliente_correo']   ? "v.`{$ventasCols['cliente_correo']}` AS cliente_correo" : "NULL AS cliente_correo";
    $campos[] = $ventasCols['fecha_venta']      ? "v.`{$ventasCols['fecha_venta']}` AS fecha_venta" : "NULL AS fecha_venta";
    $campos[] = $ventasCols['tag']              ? "v.`{$ventasCols['tag']}` AS tag_venta" : "NULL AS tag_venta";
    $campos[] = $ventasCols['modalidad']        ? "v.`{$ventasCols['modalidad']}` AS modalidad_venta" : "NULL AS modalidad_venta";
    $campos[] = $ventasCols['financiera']       ? "v.`{$ventasCols['financiera']}` AS financiera" : "NULL AS financiera";
    $campos[] = $ventasCols['estatus']          ? "v.`{$ventasCols['estatus']}` AS estatus_venta" : "NULL AS estatus_venta";

    $campos[] = $productosCols['marca']         ? "p.`{$productosCols['marca']}` AS marca" : "NULL AS marca";
    $campos[] = $productosCols['modelo']        ? "p.`{$productosCols['modelo']}` AS modelo" : "NULL AS modelo";
    $campos[] = $productosCols['color']         ? "p.`{$productosCols['color']}` AS color" : "NULL AS color";
    $campos[] = $productosCols['capacidad']     ? "p.`{$productosCols['capacidad']}` AS capacidad" : "NULL AS capacidad";
    $campos[] = $productosCols['imei1']         ? "p.`{$productosCols['imei1']}` AS producto_imei1" : "NULL AS producto_imei1";
    $campos[] = $productosCols['imei2']         ? "p.`{$productosCols['imei2']}` AS producto_imei2" : "NULL AS producto_imei2";
    $campos[] = $productosCols['proveedor']     ? "p.`{$productosCols['proveedor']}` AS proveedor" : "NULL AS proveedor";

    $campos[] = $detalleCols['imei1']           ? "dv.`{$detalleCols['imei1']}` AS detalle_imei1" : "NULL AS detalle_imei1";
    $campos[] = $detalleCols['imei2']           ? "dv.`{$detalleCols['imei2']}` AS detalle_imei2" : "NULL AS detalle_imei2";
    $campos[] = $detalleCols['precio']          ? "dv.`{$detalleCols['precio']}` AS precio_unitario" : "NULL AS precio_unitario";
    $campos[] = $detalleCols['es_combo']        ? "dv.`{$detalleCols['es_combo']}` AS es_combo" : "0 AS es_combo";

    $campos[] = $sucursalesCols['nombre'] && $ventasCols['id_sucursal']
        ? "s.`{$sucursalesCols['nombre']}` AS sucursal_nombre"
        : "NULL AS sucursal_nombre";

    $campos[] = $usuariosCols['nombre'] && $ventasCols['id_usuario']
        ? "u.`{$usuariosCols['nombre']}` AS vendedor_nombre"
        : "NULL AS vendedor_nombre";

    $joins = [];
    $joins[] = "FROM `detalle_venta` dv";
    $joins[] = "INNER JOIN `ventas` v ON v.`{$ventasCols['id']}` = dv.`{$detalleCols['id_venta']}`";
    $joins[] = "LEFT JOIN `productos` p ON p.`{$productosCols['id']}` = dv.`{$detalleCols['id_producto']}`";

    if ($ventasCols['id_cliente'] && $clientesCols['id']) {
        $joins[] = "LEFT JOIN `clientes` c ON c.`{$clientesCols['id']}` = v.`{$ventasCols['id_cliente']}`";
    }

    if ($ventasCols['id_sucursal'] && $sucursalesCols['id']) {
        $joins[] = "LEFT JOIN `sucursales` s ON s.`{$sucursalesCols['id']}` = v.`{$ventasCols['id_sucursal']}`";
    }

    if ($ventasCols['id_usuario'] && $usuariosCols['id']) {
        $joins[] = "LEFT JOIN `usuarios` u ON u.`{$usuariosCols['id']}` = v.`{$ventasCols['id_usuario']}`";
    }

    $whereParts = [];
    if ($detalleCols['imei1']) $whereParts[] = "dv.`{$detalleCols['imei1']}` = ?";
    if ($detalleCols['imei2']) $whereParts[] = "dv.`{$detalleCols['imei2']}` = ?";
    if ($productosCols['imei1']) $whereParts[] = "p.`{$productosCols['imei1']}` = ?";
    if ($productosCols['imei2']) $whereParts[] = "p.`{$productosCols['imei2']}` = ?";

    if (!$whereParts) {
        return null;
    }

    $sql = "SELECT " . implode(",\n       ", $campos) . "\n"
         . implode("\n", $joins) . "\n"
         . "WHERE (" . implode(" OR ", $whereParts) . ")\n"
         . "ORDER BY v.`{$ventasCols['id']}` DESC\n"
         . "LIMIT 1";

    $st = $conn->prepare($sql);

    $bindCount = count($whereParts);
    $types = str_repeat("s", $bindCount);
    $vals = array_fill(0, $bindCount, $imei);

    $st->bind_param($types, ...$vals);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return $row ?: null;
}

/* =========================================================
   2) BUSCAR EN REEMPLAZOS DE GARANTIA
========================================================= */
function buscar_en_reemplazo(mysqli $conn, string $imei): ?array {
    if (!table_exists($conn, 'garantias_reemplazos') || !table_exists($conn, 'garantias_casos')) {
        return null;
    }

    $sql = "SELECT
                gr.id AS reemplazo_id,
                gr.id_garantia,
                gc.folio,
                gc.id_garantia_padre,
                gc.id_garantia_raiz,
                gc.id_venta AS venta_id,
                gc.id_detalle_venta AS detalle_venta_id,
                gc.id_producto_original AS producto_id,
                gc.id_cliente,
                gc.es_combo,
                gc.tipo_equipo_venta,
                gc.proveedor,

                gc.cliente_nombre,
                gc.cliente_telefono,
                gc.cliente_correo,

                gc.marca,
                gc.modelo,
                gc.color,
                gc.capacidad,

                gc.imei_original AS imei_venta_original,
                gc.imei2_original AS imei2_venta_original,

                gr.imei_reemplazo,
                gr.imei2_reemplazo,
                gr.marca_reemplazo,
                gr.modelo_reemplazo,
                gr.color_reemplazo,
                gr.capacidad_reemplazo,

                gc.fecha_compra,
                gc.tag_venta,
                gc.modalidad_venta,
                gc.financiera,
                gc.id_sucursal
            FROM garantias_reemplazos gr
            INNER JOIN garantias_casos gc
                ON gc.id = gr.id_garantia
            WHERE gr.imei_reemplazo = ?
               OR gr.imei2_reemplazo = ?
            ORDER BY gr.id DESC
            LIMIT 1";

    $st = $conn->prepare($sql);
    $st->bind_param("ss", $imei, $imei);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return $row ?: null;
}

/* =========================================================
   3) REVISAR GARANTIA ABIERTA DEL IMEI
========================================================= */
function buscar_garantia_abierta(mysqli $conn, string $imei): ?array {
    if (!table_exists($conn, 'garantias_casos')) {
        return null;
    }

    $finales = ['cerrado', 'cancelado', 'entregado'];

    $sql = "SELECT
                id,
                folio,
                estado,
                fecha_captura,
                cliente_nombre
            FROM garantias_casos
            WHERE (imei_original = ? OR imei2_original = ?)
              AND estado NOT IN ('" . implode("','", $finales) . "')
            ORDER BY id DESC
            LIMIT 1";

    $st = $conn->prepare($sql);
    $st->bind_param("ss", $imei, $imei);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row) return $row;

    if (table_exists($conn, 'garantias_reemplazos')) {
        $sql2 = "SELECT
                    gc.id,
                    gc.folio,
                    gc.estado,
                    gc.fecha_captura,
                    gc.cliente_nombre
                 FROM garantias_reemplazos gr
                 INNER JOIN garantias_casos gc
                    ON gc.id = gr.id_garantia
                 WHERE (gr.imei_reemplazo = ? OR gr.imei2_reemplazo = ?)
                   AND gc.estado NOT IN ('" . implode("','", $finales) . "')
                 ORDER BY gc.id DESC
                 LIMIT 1";

        $st2 = $conn->prepare($sql2);
        $st2->bind_param("ss", $imei, $imei);
        $st2->execute();
        $row2 = $st2->get_result()->fetch_assoc();
        $st2->close();

        if ($row2) return $row2;
    }

    return null;
}

/* =========================================================
   4) BUSCAR SUCURSAL POR ID SI VIENE DE GARANTIA
========================================================= */
function nombre_sucursal_por_id(mysqli $conn, ?int $idSucursal): ?string {
    if (!$idSucursal || !table_exists($conn, 'sucursales')) return null;

    $colId = first_existing_column($conn, 'sucursales', ['id']);
    $colNombre = first_existing_column($conn, 'sucursales', ['nombre']);

    if (!$colId || !$colNombre) return null;

    $sql = "SELECT `{$colNombre}` AS nombre
            FROM sucursales
            WHERE `{$colId}` = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param("i", $idSucursal);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    return $row['nombre'] ?? null;
}

/* =========================================================
   EJECUCION DE BUSQUEDA
========================================================= */
$venta = buscar_en_venta($conn, $imei, $ventasCols, $detalleCols, $productosCols, $usuariosCols, $sucursalesCols, $clientesCols);
$reemplazo = null;

if (!$venta) {
    $reemplazo = buscar_en_reemplazo($conn, $imei);
}

$garantiaAbierta = buscar_garantia_abierta($conn, $imei);

/* =========================================================
   RESPUESTA: VENTA ORIGINAL
========================================================= */
if ($venta) {
    $imei1 = val_or_null(($venta['detalle_imei1'] ?? '') ?: ($venta['producto_imei1'] ?? ''));
    $imei2 = val_or_null(($venta['detalle_imei2'] ?? '') ?: ($venta['producto_imei2'] ?? ''));

    $clienteId = null;
    if (isset($venta['cliente_id']) && $venta['cliente_id'] !== null && $venta['cliente_id'] !== '') {
        $clienteId = (int)$venta['cliente_id'];
    } elseif (isset($venta['id_cliente']) && $venta['id_cliente'] !== null && $venta['id_cliente'] !== '') {
        $clienteId = (int)$venta['id_cliente'];
    }

    $clienteNombre = val_or_null($venta['cliente_nombre_catalogo'] ?? null)
        ?? val_or_null($venta['cliente_nombre'] ?? null);

    $clienteTelefono = val_or_null($venta['cliente_telefono_catalogo'] ?? null)
        ?? val_or_null($venta['cliente_telefono'] ?? null);

    $clienteCorreo = val_or_null($venta['cliente_correo_catalogo'] ?? null)
        ?? val_or_null($venta['cliente_correo'] ?? null);

    $esCombo = isset($venta['es_combo']) ? (int)$venta['es_combo'] : 0;
    $tipoEquipoVenta = tipo_equipo_por_combo($esCombo);

    out([
        'ok' => true,
        'encontrado' => true,
        'origen' => 'venta',
        'imei_buscado' => $imei,

        'venta' => [
            'id_venta' => isset($venta['venta_id']) ? (int)$venta['venta_id'] : null,
            'id_detalle_venta' => isset($venta['detalle_venta_id']) ? (int)$venta['detalle_venta_id'] : null,
            'id_producto' => isset($venta['producto_id']) ? (int)$venta['producto_id'] : null,
            'id_cliente' => $clienteId,
            'fecha_venta' => $venta['fecha_venta'] ?? null,
            'tag' => $venta['tag_venta'] ?? null,
            'modalidad' => $venta['modalidad_venta'] ?? null,
            'financiera' => $venta['financiera'] ?? null,
            'estatus' => $venta['estatus_venta'] ?? null,
            'es_combo' => $esCombo,
            'tipo_equipo_venta' => $tipoEquipoVenta,
        ],

        'cliente' => [
            'id' => $clienteId,
            'nombre' => $clienteNombre,
            'telefono' => $clienteTelefono,
            'correo' => $clienteCorreo,
        ],

        'equipo' => [
            'marca' => $venta['marca'] ?? null,
            'modelo' => $venta['modelo'] ?? null,
            'color' => $venta['color'] ?? null,
            'capacidad' => $venta['capacidad'] ?? null,
            'imei1' => $imei1,
            'imei2' => $imei2,
            'proveedor' => $venta['proveedor'] ?? null,
        ],

        'operacion' => [
            'sucursal_nombre' => $venta['sucursal_nombre'] ?? null,
            'vendedor_nombre' => $venta['vendedor_nombre'] ?? null,
        ],

        'garantia_abierta' => [
            'existe' => $garantiaAbierta ? true : false,
            'id' => $garantiaAbierta ? (int)$garantiaAbierta['id'] : null,
            'folio' => $garantiaAbierta['folio'] ?? null,
            'estado' => $garantiaAbierta['estado'] ?? null,
            'fecha_captura' => $garantiaAbierta['fecha_captura'] ?? null,
        ]
    ]);
}

/* =========================================================
   RESPUESTA: REEMPLAZO DE GARANTIA
========================================================= */
if ($reemplazo) {
    $idSucursal = isset($reemplazo['id_sucursal']) ? (int)$reemplazo['id_sucursal'] : null;
    $sucursalNombre = nombre_sucursal_por_id($conn, $idSucursal);

    $clienteId = null;
    if (isset($reemplazo['id_cliente']) && $reemplazo['id_cliente'] !== null && $reemplazo['id_cliente'] !== '') {
        $clienteId = (int)$reemplazo['id_cliente'];
    }

    $esCombo = isset($reemplazo['es_combo']) && $reemplazo['es_combo'] !== null && $reemplazo['es_combo'] !== ''
        ? (int)$reemplazo['es_combo']
        : 0;

    $tipoEquipoVenta = !empty($reemplazo['tipo_equipo_venta'])
        ? (string)$reemplazo['tipo_equipo_venta']
        : tipo_equipo_por_combo($esCombo);

    out([
        'ok' => true,
        'encontrado' => true,
        'origen' => 'reemplazo_garantia',
        'imei_buscado' => $imei,

        'garantia_origen' => [
            'id_garantia' => isset($reemplazo['id_garantia']) ? (int)$reemplazo['id_garantia'] : null,
            'folio' => $reemplazo['folio'] ?? null,
            'id_garantia_padre' => isset($reemplazo['id_garantia_padre']) ? (int)$reemplazo['id_garantia_padre'] : null,
            'id_garantia_raiz' => isset($reemplazo['id_garantia_raiz']) ? (int)$reemplazo['id_garantia_raiz'] : null,
        ],

        'venta' => [
            'id_venta' => isset($reemplazo['venta_id']) ? (int)$reemplazo['venta_id'] : null,
            'id_detalle_venta' => isset($reemplazo['detalle_venta_id']) ? (int)$reemplazo['detalle_venta_id'] : null,
            'id_producto' => isset($reemplazo['producto_id']) ? (int)$reemplazo['producto_id'] : null,
            'id_cliente' => $clienteId,
            'fecha_venta' => $reemplazo['fecha_compra'] ?? null,
            'tag' => $reemplazo['tag_venta'] ?? null,
            'modalidad' => $reemplazo['modalidad_venta'] ?? null,
            'financiera' => $reemplazo['financiera'] ?? null,
            'es_combo' => $esCombo,
            'tipo_equipo_venta' => $tipoEquipoVenta,
        ],

        'cliente' => [
            'id' => $clienteId,
            'nombre' => $reemplazo['cliente_nombre'] ?? null,
            'telefono' => $reemplazo['cliente_telefono'] ?? null,
            'correo' => $reemplazo['cliente_correo'] ?? null,
        ],

        'equipo' => [
            'marca' => $reemplazo['marca_reemplazo'] ?: $reemplazo['marca'],
            'modelo' => $reemplazo['modelo_reemplazo'] ?: $reemplazo['modelo'],
            'color' => $reemplazo['color_reemplazo'] ?: $reemplazo['color'],
            'capacidad' => $reemplazo['capacidad_reemplazo'] ?: $reemplazo['capacidad'],
            'imei1' => $reemplazo['imei_reemplazo'] ?? null,
            'imei2' => $reemplazo['imei2_reemplazo'] ?? null,
            'imei_venta_original' => $reemplazo['imei_venta_original'] ?? null,
            'imei2_venta_original' => $reemplazo['imei2_venta_original'] ?? null,
            'proveedor' => $reemplazo['proveedor'] ?? null,
        ],

        'operacion' => [
            'sucursal_nombre' => $sucursalNombre,
            'vendedor_nombre' => null,
        ],

        'garantia_abierta' => [
            'existe' => $garantiaAbierta ? true : false,
            'id' => $garantiaAbierta ? (int)$garantiaAbierta['id'] : null,
            'folio' => $garantiaAbierta['folio'] ?? null,
            'estado' => $garantiaAbierta['estado'] ?? null,
            'fecha_captura' => $garantiaAbierta['fecha_captura'] ?? null,
        ]
    ]);
}

/* =========================================================
   NO ENCONTRADO
========================================================= */
out([
    'ok' => true,
    'encontrado' => false,
    'origen' => null,
    'imei_buscado' => $imei,
    'mensaje' => 'No se encontró el IMEI en ventas ni en reemplazos de garantía',
    'garantia_abierta' => [
        'existe' => $garantiaAbierta ? true : false,
        'id' => $garantiaAbierta ? (int)$garantiaAbierta['id'] : null,
        'folio' => $garantiaAbierta['folio'] ?? null,
        'estado' => $garantiaAbierta['estado'] ?? null,
        'fecha_captura' => $garantiaAbierta['fecha_captura'] ?? null,
    ]
]);