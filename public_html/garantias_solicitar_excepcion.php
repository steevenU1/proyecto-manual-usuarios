<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

/* =========================================================
   CONTEXTO
========================================================= */
$ROL            = (string)($_SESSION['rol'] ?? '');
$ID_USUARIO     = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL    = (int)($_SESSION['id_sucursal'] ?? 0);
$NOMBRE_USUARIO = (string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario');

$ROLES_SOLICITA = [
    'Ejecutivo', 'Gerente',
    'Subdis_Ejecutivo', 'Subdis_Gerente',
    'Admin', 'Administrador'
];

if (!in_array($ROL, $ROLES_SOLICITA, true)) {
    http_response_code(403);
    exit('Sin permiso para solicitar excepciones.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function sanitize_imei($v): string {
    $v = preg_replace('/\s+/', '', (string)$v);
    $v = preg_replace('/[^0-9A-Za-z]/', '', $v);
    return strtoupper(trim($v));
}

function null_if_empty($v): ?string {
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function decimal_or_null($v): ?float {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return (float)$v;
    $v = str_replace(['$', ',', ' '], '', (string)$v);
    return is_numeric($v) ? (float)$v : null;
}

function normalize_status(?string $status): string {
    $s = trim((string)$status);
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u',
    ]);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
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
    foreach ($candidates as $c) {
        if (column_exists($conn, $table, $c)) return $c;
    }
    return null;
}

function bindParamsDynamic(mysqli_stmt $stmt, string $types, array &$params): void {
    $refs = [];
    $refs[] = $types;
    foreach ($params as $k => $v) {
        $refs[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function registrar_evento(
    mysqli $conn,
    int $idGarantia,
    string $tipoEvento,
    ?string $estadoAnterior,
    ?string $estadoNuevo,
    ?string $descripcion,
    ?array $datosJson,
    ?int $idUsuario,
    ?string $nombreUsuario,
    ?string $rolUsuario
): void {
    $datos = $datosJson ? json_encode($datosJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $sql = "INSERT INTO garantias_eventos
            (id_garantia, tipo_evento, estado_anterior, estado_nuevo, descripcion, datos_json, id_usuario, nombre_usuario, rol_usuario, fecha_evento)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $st = $conn->prepare($sql);
    if (!$st) {
        throw new Exception("Error en prepare() de garantias_eventos: " . $conn->error);
    }

    $st->bind_param(
        "isssssiss",
        $idGarantia,
        $tipoEvento,
        $estadoAnterior,
        $estadoNuevo,
        $descripcion,
        $datos,
        $idUsuario,
        $nombreUsuario,
        $rolUsuario
    );

    if (!$st->execute()) {
        throw new Exception("Error al insertar evento: " . $st->error);
    }
    $st->close();
}

function puede_operar_caso(array $caso, string $rol, int $idUsuario, int $idSucursal): bool {
    if (in_array($rol, ['Admin', 'Administrador', 'Logistica'], true)) {
        return true;
    }
    if (in_array($rol, ['Gerente', 'Subdis_Gerente'], true)) {
        return ((int)($caso['id_sucursal'] ?? 0) === $idSucursal);
    }
    if (in_array($rol, ['Ejecutivo', 'Subdis_Ejecutivo'], true)) {
        return ((int)($caso['id_usuario_captura'] ?? 0) === $idUsuario);
    }
    if ($rol === 'Subdis_Admin') {
        return true;
    }
    return false;
}

function redirect_error(int $idGarantia, string $msg): void {
    header("Location: garantias_detalle.php?id={$idGarantia}&err=" . urlencode($msg) . "#bloque-excepcion");
    exit();
}

/* =========================================================
   VALIDAR TABLAS
========================================================= */
$required = [
    'garantias_casos',
    'garantias_eventos',
    'garantias_excepciones_reemplazo',
    'inventario',
    'productos',
    'detalle_venta'
];
foreach ($required as $tb) {
    if (!table_exists($conn, $tb)) {
        exit("No existe la tabla requerida: " . h($tb));
    }
}

/* =========================================================
   MAPEO DINÁMICO
========================================================= */
$invCols = [
    'id'          => first_existing_column($conn, 'inventario', ['id']),
    'id_producto' => first_existing_column($conn, 'inventario', ['id_producto']),
    'id_sucursal' => first_existing_column($conn, 'inventario', ['id_sucursal']),
    'estatus'     => first_existing_column($conn, 'inventario', ['estatus', 'estado']),
];

$prodCols = [
    'id'           => first_existing_column($conn, 'productos', ['id']),
    'marca'        => first_existing_column($conn, 'productos', ['marca']),
    'modelo'       => first_existing_column($conn, 'productos', ['modelo']),
    'color'        => first_existing_column($conn, 'productos', ['color']),
    'capacidad'    => first_existing_column($conn, 'productos', ['capacidad', 'almacenamiento']),
    'imei1'        => first_existing_column($conn, 'productos', ['imei1']),
    'imei2'        => first_existing_column($conn, 'productos', ['imei2']),
    'precio_lista' => first_existing_column($conn, 'productos', ['precio_lista']),
];

if (!$invCols['id'] || !$invCols['id_producto'] || !$invCols['id_sucursal'] || !$invCols['estatus']) {
    exit('La tabla inventario no tiene las columnas mínimas requeridas.');
}
if (!$prodCols['id'] || !$prodCols['imei1'] || !$prodCols['precio_lista']) {
    exit('La tabla productos no tiene las columnas mínimas requeridas para la excepción.');
}

/* =========================================================
   INPUT
========================================================= */
$idGarantia = (int)($_POST['id_garantia'] ?? 0);
$imeiPropuesto = sanitize_imei($_POST['imei_propuesto'] ?? '');
$motivo = null_if_empty($_POST['motivo_solicitud'] ?? null);

if ($idGarantia <= 0) {
    exit('ID de garantía inválido.');
}
if ($imeiPropuesto === '') {
    redirect_error($idGarantia, 'Debes capturar el IMEI del equipo propuesto.');
}
if ($motivo === null || mb_strlen($motivo) < 10) {
    redirect_error($idGarantia, 'Debes capturar un motivo de al menos 10 caracteres.');
}

/* =========================================================
   CARGAR CASO
========================================================= */
$sqlCaso = "SELECT
                gc.*,
                s.nombre AS sucursal_nombre,
                dv.precio_unitario AS precio_original_venta
            FROM garantias_casos gc
            LEFT JOIN sucursales s ON s.id = gc.id_sucursal
            LEFT JOIN detalle_venta dv ON dv.imei1 = gc.imei_original
            WHERE gc.id = ?
            LIMIT 1";
$st = $conn->prepare($sqlCaso);
if (!$st) {
    exit("Error consultando caso: " . h($conn->error));
}
$st->bind_param("i", $idGarantia);
$st->execute();
$caso = $st->get_result()->fetch_assoc();
$st->close();

if (!$caso) {
    exit('No se encontró el caso.');
}
if (!puede_operar_caso($caso, $ROL, $ID_USUARIO, $ID_SUCURSAL)) {
    http_response_code(403);
    exit('No tienes permiso para operar este caso.');
}

$estadosPermitidos = ['garantia_autorizada', 'reemplazo_capturado'];
if (!in_array((string)$caso['estado'], $estadosPermitidos, true)) {
    redirect_error($idGarantia, 'Este caso no permite solicitar excepción en su estado actual.');
}

$precioOriginal = decimal_or_null($caso['precio_original_venta'] ?? null);
if ($precioOriginal === null) {
    redirect_error($idGarantia, 'No se pudo obtener el precio original desde detalle_venta.');
}

/* =========================================================
   BUSCAR EQUIPO PROPUESTO
========================================================= */
$whereImei = [];
$paramsImei = [];
$typesImei = '';

$whereImei[] = "p.`{$prodCols['imei1']}` = ?";
$paramsImei[] = $imeiPropuesto;
$typesImei .= 's';

if ($prodCols['imei2']) {
    $whereImei[] = "p.`{$prodCols['imei2']}` = ?";
    $paramsImei[] = $imeiPropuesto;
    $typesImei .= 's';
}

$sqlEq = "SELECT
            i.`{$invCols['id']}` AS inventario_id,
            i.`{$invCols['id_producto']}` AS id_producto,
            i.`{$invCols['id_sucursal']}` AS id_sucursal,
            i.`{$invCols['estatus']}` AS estatus_inventario,
            p.`{$prodCols['id']}` AS producto_id,
            " . ($prodCols['marca'] ? "p.`{$prodCols['marca']}`" : "NULL") . " AS marca,
            " . ($prodCols['modelo'] ? "p.`{$prodCols['modelo']}`" : "NULL") . " AS modelo,
            " . ($prodCols['color'] ? "p.`{$prodCols['color']}`" : "NULL") . " AS color,
            " . ($prodCols['capacidad'] ? "p.`{$prodCols['capacidad']}`" : "NULL") . " AS capacidad,
            p.`{$prodCols['imei1']}` AS imei1,
            " . ($prodCols['imei2'] ? "p.`{$prodCols['imei2']}`" : "NULL") . " AS imei2,
            p.`{$prodCols['precio_lista']}` AS precio_reemplazo,
            s.nombre AS sucursal_nombre
          FROM inventario i
          INNER JOIN productos p
              ON p.`{$prodCols['id']}` = i.`{$invCols['id_producto']}`
          LEFT JOIN sucursales s
              ON s.id = i.`{$invCols['id_sucursal']}`
          WHERE (" . implode(" OR ", $whereImei) . ")
          LIMIT 1";
$st = $conn->prepare($sqlEq);
if (!$st) {
    redirect_error($idGarantia, "Error buscando equipo propuesto: " . $conn->error);
}
bindParamsDynamic($st, $typesImei, $paramsImei);
$st->execute();
$equipo = $st->get_result()->fetch_assoc();
$st->close();

if (!$equipo) {
    redirect_error($idGarantia, 'No se encontró el equipo propuesto.');
}

$estatusInv = normalize_status($equipo['estatus_inventario'] ?? '');
$permitidos = ['disponible', 'stock', 'activo'];
$bloqueados = ['garantia', 'vendido', 'retirado', 'en transito'];

if (in_array($estatusInv, $bloqueados, true) || !in_array($estatusInv, $permitidos, true)) {
    redirect_error($idGarantia, 'El equipo propuesto no está disponible en inventario.');
}

if ((int)$equipo['id_sucursal'] !== (int)$caso['id_sucursal']) {
    redirect_error($idGarantia, 'El equipo propuesto pertenece a otra sucursal.');
}

if (
    sanitize_imei($equipo['imei1'] ?? '') === sanitize_imei($caso['imei_original'] ?? '') ||
    sanitize_imei($equipo['imei1'] ?? '') === sanitize_imei($caso['imei2_original'] ?? '') ||
    sanitize_imei($equipo['imei2'] ?? '') === sanitize_imei($caso['imei_original'] ?? '') ||
    sanitize_imei($equipo['imei2'] ?? '') === sanitize_imei($caso['imei2_original'] ?? '')
) {
    redirect_error($idGarantia, 'No puedes proponer el mismo equipo original como reemplazo.');
}

$precioReemplazo = decimal_or_null($equipo['precio_reemplazo'] ?? null);
if ($precioReemplazo === null) {
    redirect_error($idGarantia, 'El equipo propuesto no tiene precio lista registrado.');
}

if ($precioReemplazo <= $precioOriginal) {
    redirect_error($idGarantia, 'Ese equipo no requiere excepción porque su precio no excede al original. Puedes capturarlo normalmente.');
}

/* =========================================================
   EVITAR DUPLICADOS / SOLICITUD ABIERTA
========================================================= */
$sqlOpen = "SELECT id, estatus
            FROM garantias_excepciones_reemplazo
            WHERE id_garantia = ?
              AND estatus = 'solicitada'
            ORDER BY id DESC
            LIMIT 1";
$st = $conn->prepare($sqlOpen);
$st->bind_param("i", $idGarantia);
$st->execute();
$solOpen = $st->get_result()->fetch_assoc();
$st->close();

if ($solOpen) {
    redirect_error($idGarantia, 'Ya existe una solicitud de excepción pendiente para este caso.');
}

/* =========================================================
   GUARDAR SOLICITUD
========================================================= */
$conn->begin_transaction();

try {
    $sqlInsert = "INSERT INTO garantias_excepciones_reemplazo
        (
            id_garantia,
            id_inventario_propuesto,
            id_producto_propuesto,
            imei_propuesto,
            imei2_propuesto,
            precio_original,
            precio_reemplazo,
            motivo_solicitud,
            estatus,
            id_usuario_solicita,
            fecha_solicitud
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'solicitada', ?, NOW())";
    $st = $conn->prepare($sqlInsert);
    if (!$st) {
        throw new Exception("Error al preparar inserción de excepción: " . $conn->error);
    }

    $idInv = (int)$equipo['inventario_id'];
    $idProd = (int)$equipo['id_producto'];
    $imei1 = null_if_empty($equipo['imei1'] ?? null);
    $imei2 = null_if_empty($equipo['imei2'] ?? null);

    $st->bind_param(
        "iiissddsi",
        $idGarantia,
        $idInv,
        $idProd,
        $imei1,
        $imei2,
        $precioOriginal,
        $precioReemplazo,
        $motivo,
        $ID_USUARIO
    );
    if (!$st->execute()) {
        throw new Exception("Error al guardar excepción: " . $st->error);
    }
    $idExcepcion = (int)$st->insert_id;
    $st->close();

    registrar_evento(
        $conn,
        $idGarantia,
        'excepcion_reemplazo_solicitada',
        $caso['estado'] ?? null,
        $caso['estado'] ?? null,
        'Se solicitó autorización para un reemplazo con mayor valor.',
        [
            'id_excepcion' => $idExcepcion,
            'imei_propuesto' => $imei1,
            'imei2_propuesto' => $imei2,
            'precio_original' => $precioOriginal,
            'precio_reemplazo' => $precioReemplazo,
            'motivo' => $motivo,
        ],
        $ID_USUARIO,
        $NOMBRE_USUARIO,
        $ROL
    );

    $conn->commit();
    header("Location: garantias_detalle.php?id={$idGarantia}&okexc=1#bloque-excepcion");
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    redirect_error($idGarantia, $e->getMessage());
}