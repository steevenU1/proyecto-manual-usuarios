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

$ROLES_APLICA = ['Admin', 'Administrador', 'Logistica'];

if (!in_array($ROL, $ROLES_APLICA, true)) {
    http_response_code(403);
    exit('Sin permiso para aplicar reemplazos autorizados.');
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

function int_or_null($v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function normalize_status(?string $status): string {
    $s = trim((string)$status);
    $s = mb_strtolower($s, 'UTF-8');
    $map = [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u',
    ];
    $s = strtr($s, $map);
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

function redirect_error(int $idGarantia, string $msg): void {
    header("Location: garantias_detalle.php?id={$idGarantia}&err=" . urlencode($msg) . "#bloque-excepcion");
    exit();
}

/* =========================================================
   VALIDAR TABLAS
========================================================= */
$required = [
    'garantias_casos',
    'garantias_reemplazos',
    'garantias_excepciones_reemplazo',
    'garantias_eventos',
    'inventario',
    'productos'
];
foreach ($required as $tb) {
    if (!table_exists($conn, $tb)) {
        exit("No existe la tabla requerida: " . h($tb));
    }
}

/* =========================================================
   MAPEO DINÁMICO inventario / productos
========================================================= */
$invCols = [
    'id'          => first_existing_column($conn, 'inventario', ['id']),
    'id_producto' => first_existing_column($conn, 'inventario', ['id_producto']),
    'id_sucursal' => first_existing_column($conn, 'inventario', ['id_sucursal']),
    'estatus'     => first_existing_column($conn, 'inventario', ['estatus', 'estado']),
];

$prodCols = [
    'id'        => first_existing_column($conn, 'productos', ['id']),
    'marca'     => first_existing_column($conn, 'productos', ['marca']),
    'modelo'    => first_existing_column($conn, 'productos', ['modelo']),
    'color'     => first_existing_column($conn, 'productos', ['color']),
    'capacidad' => first_existing_column($conn, 'productos', ['capacidad', 'almacenamiento']),
    'imei1'     => first_existing_column($conn, 'productos', ['imei1']),
    'imei2'     => first_existing_column($conn, 'productos', ['imei2']),
];

if (!$invCols['id'] || !$invCols['id_producto'] || !$invCols['id_sucursal'] || !$invCols['estatus']) {
    exit('La tabla inventario no tiene las columnas mínimas requeridas.');
}
if (!$prodCols['id'] || !$prodCols['imei1']) {
    exit('La tabla productos no tiene las columnas mínimas requeridas.');
}

/* =========================================================
   INPUT
========================================================= */
$idGarantia  = (int)($_POST['id_garantia'] ?? 0);
$idExcepcion = (int)($_POST['id_excepcion'] ?? 0);

if ($idGarantia <= 0) {
    exit('ID de garantía inválido.');
}
if ($idExcepcion <= 0) {
    redirect_error($idGarantia, 'ID de excepción inválido.');
}

/* =========================================================
   CARGAR CASO
========================================================= */
$sqlCaso = "SELECT *
            FROM garantias_casos
            WHERE id = ?
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
    exit('No se encontró el caso de garantía.');
}

$estadosPermitidos = ['garantia_autorizada', 'reemplazo_capturado'];
if (!in_array((string)$caso['estado'], $estadosPermitidos, true)) {
    redirect_error($idGarantia, 'Este caso no permite aplicar un reemplazo autorizado en su estado actual.');
}

/* =========================================================
   CARGAR EXCEPCIÓN
========================================================= */
$sqlExc = "SELECT *
           FROM garantias_excepciones_reemplazo
           WHERE id = ?
             AND id_garantia = ?
           LIMIT 1";
$st = $conn->prepare($sqlExc);
if (!$st) {
    redirect_error($idGarantia, "Error consultando excepción: " . $conn->error);
}
$st->bind_param("ii", $idExcepcion, $idGarantia);
$st->execute();
$excepcion = $st->get_result()->fetch_assoc();
$st->close();

if (!$excepcion) {
    redirect_error($idGarantia, 'No se encontró la excepción autorizada.');
}

if ((string)$excepcion['estatus'] !== 'autorizada') {
    redirect_error($idGarantia, 'La excepción no está autorizada.');
}

/* =========================================================
   CARGAR REEMPLAZO ACTUAL
========================================================= */
$reemplazoActual = null;
$sqlR = "SELECT *
         FROM garantias_reemplazos
         WHERE id_garantia = ?
         ORDER BY id DESC
         LIMIT 1";
$st = $conn->prepare($sqlR);
if ($st) {
    $st->bind_param("i", $idGarantia);
    $st->execute();
    $reemplazoActual = $st->get_result()->fetch_assoc();
    $st->close();
}

/* =========================================================
   BUSCAR EQUIPO AUTORIZADO
========================================================= */
$idInventarioPropuesto = (int)($excepcion['id_inventario_propuesto'] ?? 0);
$idProductoPropuesto   = (int)($excepcion['id_producto_propuesto'] ?? 0);
$imeiPropuesto         = sanitize_imei($excepcion['imei_propuesto'] ?? '');
$imei2Propuesto        = sanitize_imei($excepcion['imei2_propuesto'] ?? '');

if ($idInventarioPropuesto <= 0) {
    redirect_error($idGarantia, 'La excepción no tiene inventario propuesto válido.');
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
            " . ($prodCols['imei2'] ? "p.`{$prodCols['imei2']}`" : "NULL") . " AS imei2
          FROM inventario i
          INNER JOIN productos p
              ON p.`{$prodCols['id']}` = i.`{$invCols['id_producto']}`
          WHERE i.`{$invCols['id']}` = ?
          LIMIT 1";
$st = $conn->prepare($sqlEq);
if (!$st) {
    redirect_error($idGarantia, "Error consultando equipo autorizado: " . $conn->error);
}
$st->bind_param("i", $idInventarioPropuesto);
$st->execute();
$equipoNuevo = $st->get_result()->fetch_assoc();
$st->close();

if (!$equipoNuevo) {
    redirect_error($idGarantia, 'No se encontró el equipo autorizado en inventario.');
}

/* =========================================================
   VALIDACIONES DEL EQUIPO
========================================================= */
$estatusInvRaw = (string)($equipoNuevo['estatus_inventario'] ?? '');
$estatusInv = normalize_status($estatusInvRaw);

$permitidos = ['disponible', 'stock', 'activo'];
$bloqueados = ['garantia', 'vendido', 'retirado', 'en transito'];

if (in_array($estatusInv, $bloqueados, true)) {
    if ($estatusInv === 'garantia') {
        redirect_error($idGarantia, 'El equipo autorizado ya está asignado a una garantía.');
    } else {
        redirect_error($idGarantia, 'El equipo autorizado ya no está disponible en inventario. Estatus actual: ' . ($equipoNuevo['estatus_inventario'] ?? '-'));
    }
}
if (!in_array($estatusInv, $permitidos, true)) {
    redirect_error($idGarantia, 'El equipo autorizado no está disponible en inventario. Estatus actual: ' . ($equipoNuevo['estatus_inventario'] ?? '-'));
}

if ((int)$equipoNuevo['id_sucursal'] !== (int)$caso['id_sucursal']) {
    redirect_error($idGarantia, 'El equipo autorizado pertenece a otra sucursal y no puede aplicarse.');
}

if (
    sanitize_imei($equipoNuevo['imei1'] ?? '') === sanitize_imei($caso['imei_original'] ?? '') ||
    sanitize_imei($equipoNuevo['imei1'] ?? '') === sanitize_imei($caso['imei2_original'] ?? '') ||
    sanitize_imei($equipoNuevo['imei2'] ?? '') === sanitize_imei($caso['imei_original'] ?? '') ||
    sanitize_imei($equipoNuevo['imei2'] ?? '') === sanitize_imei($caso['imei2_original'] ?? '')
) {
    redirect_error($idGarantia, 'No puedes aplicar el mismo equipo original como reemplazo.');
}

/* =========================================================
   VALIDAR QUE NO ESTÉ EN OTRA GARANTÍA
========================================================= */
$sqlUsed = "SELECT id, id_garantia
            FROM garantias_reemplazos
            WHERE imei_reemplazo = ? OR imei2_reemplazo = ?
            LIMIT 1";
$st = $conn->prepare($sqlUsed);
if (!$st) {
    redirect_error($idGarantia, "Error validando IMEI en reemplazos: " . $conn->error);
}
$imeiA = sanitize_imei($equipoNuevo['imei1'] ?? '');
$imeiB = sanitize_imei($equipoNuevo['imei2'] ?? '');
$st->bind_param("ss", $imeiA, $imeiB);
$st->execute();
$used = $st->get_result()->fetch_assoc();
$st->close();

if ($used && (int)$used['id_garantia'] !== $idGarantia) {
    redirect_error($idGarantia, 'Ese equipo ya fue registrado como reemplazo en otro caso.');
}

/* =========================================================
   APLICAR REEMPLAZO AUTORIZADO
========================================================= */
$conn->begin_transaction();

try {
    $estadoAnterior = (string)$caso['estado'];

    /* ----------------------------------------
       liberar inventario anterior si cambió
    ---------------------------------------- */
    if (
        $reemplazoActual &&
        !empty($reemplazoActual['id_inventario_reemplazo']) &&
        (int)$reemplazoActual['id_inventario_reemplazo'] !== (int)$equipoNuevo['inventario_id']
    ) {
        $sqlLiberar = "UPDATE inventario
                       SET `{$invCols['estatus']}` = ?
                       WHERE `{$invCols['id']}` = ?";
        $st = $conn->prepare($sqlLiberar);
        if (!$st) {
            throw new Exception("Error al preparar liberación de inventario anterior: " . $conn->error);
        }
        $estatusDisponible = 'Disponible';
        $idInvAnterior = (int)$reemplazoActual['id_inventario_reemplazo'];
        $st->bind_param("si", $estatusDisponible, $idInvAnterior);
        if (!$st->execute()) {
            throw new Exception("Error al liberar inventario anterior: " . $st->error);
        }
        $st->close();

        registrar_evento(
            $conn,
            $idGarantia,
            'inventario_liberado_reemplazo_anterior',
            'garantia',
            'disponible',
            'Se liberó el inventario del reemplazo anterior al aplicar el reemplazo autorizado.',
            [
                'id_inventario_anterior' => $idInvAnterior,
                'imei_reemplazo_anterior' => $reemplazoActual['imei_reemplazo'] ?? null
            ],
            $ID_USUARIO,
            $NOMBRE_USUARIO,
            $ROL
        );
    }

    /* ----------------------------------------
       buscar si ya existe reemplazo del caso
    ---------------------------------------- */
    $sqlFind = "SELECT id
                FROM garantias_reemplazos
                WHERE id_garantia = ?
                ORDER BY id DESC
                LIMIT 1";
    $st = $conn->prepare($sqlFind);
    if (!$st) {
        throw new Exception("Error buscando reemplazo actual: " . $conn->error);
    }
    $st->bind_param("i", $idGarantia);
    $st->execute();
    $rowFind = $st->get_result()->fetch_assoc();
    $st->close();

    $idReemplazo = (int)($rowFind['id'] ?? 0);

    $observacionesAuto = "Reemplazo aplicado desde excepción autorizada #{$idExcepcion}.";
    if (!empty($excepcion['comentario_resolucion'])) {
        $observacionesAuto .= "\nComentario autorización: " . trim((string)$excepcion['comentario_resolucion']);
    }

    $data = [
        'id_garantia'                 => $idGarantia,
        'id_producto_original'        => int_or_null($caso['id_producto_original'] ?? null),
        'id_producto_reemplazo'       => (int)$equipoNuevo['id_producto'],
        'imei_original'               => $caso['imei_original'],
        'imei2_original'              => $caso['imei2_original'],
        'imei_reemplazo'              => $equipoNuevo['imei1'],
        'imei2_reemplazo'             => null_if_empty($equipoNuevo['imei2'] ?? null),
        'marca_reemplazo'             => null_if_empty($equipoNuevo['marca'] ?? null),
        'modelo_reemplazo'            => null_if_empty($equipoNuevo['modelo'] ?? null),
        'color_reemplazo'             => null_if_empty($equipoNuevo['color'] ?? null),
        'capacidad_reemplazo'         => null_if_empty($equipoNuevo['capacidad'] ?? null),
        'id_inventario_reemplazo'     => (int)$equipoNuevo['inventario_id'],
        'estatus_inventario_anterior' => $equipoNuevo['estatus_inventario'],
        'estatus_inventario_nuevo'    => 'Garantia',
        'id_usuario_registro'         => $ID_USUARIO,
        'observaciones'               => $observacionesAuto,
    ];

    if ($idReemplazo > 0) {
        $sets = [];
        $params = [];
        $types = '';

        foreach ($data as $col => $val) {
            if ($col === 'id_garantia') continue;
            $sets[] = "{$col} = ?";
            $params[] = $val;
            $types .= is_int($val) ? 'i' : 's';
        }

        $sqlUpdateR = "UPDATE garantias_reemplazos
                       SET " . implode(", ", $sets) . "
                       WHERE id = ?";
        $params[] = $idReemplazo;
        $types .= 'i';

        $st = $conn->prepare($sqlUpdateR);
        if (!$st) {
            throw new Exception("Error en update de reemplazo: " . $conn->error);
        }
        bindParamsDynamic($st, $types, $params);
        if (!$st->execute()) {
            throw new Exception("Error al actualizar reemplazo: " . $st->error);
        }
        $st->close();
    } else {
        $cols = array_keys($data);
        $place = array_fill(0, count($cols), '?');
        $params = array_values($data);
        $types = '';

        foreach ($params as $val) {
            $types .= is_int($val) ? 'i' : 's';
        }

        $sqlInsertR = "INSERT INTO garantias_reemplazos
                       (" . implode(',', $cols) . ", fecha_registro)
                       VALUES (" . implode(',', $place) . ", NOW())";
        $st = $conn->prepare($sqlInsertR);
        if (!$st) {
            throw new Exception("Error en insert de reemplazo: " . $conn->error);
        }
        bindParamsDynamic($st, $types, $params);
        if (!$st->execute()) {
            throw new Exception("Error al insertar reemplazo: " . $st->error);
        }
        $st->close();
    }

    /* ----------------------------------------
       actualizar inventario nuevo a Garantia
    ---------------------------------------- */
    $sqlInv = "UPDATE inventario
               SET `{$invCols['estatus']}` = ?
               WHERE `{$invCols['id']}` = ?";
    $st = $conn->prepare($sqlInv);
    if (!$st) {
        throw new Exception("Error en update de inventario: " . $conn->error);
    }
    $nuevoEstatusInv = 'Garantia';
    $idInv = (int)$equipoNuevo['inventario_id'];
    $st->bind_param("si", $nuevoEstatusInv, $idInv);
    if (!$st->execute()) {
        throw new Exception("Error al actualizar inventario: " . $st->error);
    }
    $st->close();

    registrar_evento(
        $conn,
        $idGarantia,
        'inventario_asignado_garantia',
        $equipoNuevo['estatus_inventario'] ?? null,
        'Garantia',
        'El equipo autorizado fue marcado en inventario como Garantia.',
        [
            'inventario_id' => $idInv,
            'imei_reemplazo' => $equipoNuevo['imei1'] ?? null,
            'imei2_reemplazo' => $equipoNuevo['imei2'] ?? null,
            'id_excepcion' => $idExcepcion
        ],
        $ID_USUARIO,
        $NOMBRE_USUARIO,
        $ROL
    );

    /* ----------------------------------------
       actualizar caso
    ---------------------------------------- */
    $sqlCasoUp = "UPDATE garantias_casos
                  SET estado = 'reemplazo_capturado',
                      updated_at = NOW()
                  WHERE id = ?";
    $st = $conn->prepare($sqlCasoUp);
    if (!$st) {
        throw new Exception("Error actualizando caso: " . $conn->error);
    }
    $st->bind_param("i", $idGarantia);
    if (!$st->execute()) {
        throw new Exception("Error al actualizar estado del caso: " . $st->error);
    }
    $st->close();

    registrar_evento(
        $conn,
        $idGarantia,
        'reemplazo_autorizado_aplicado',
        $estadoAnterior,
        'reemplazo_capturado',
        'Se aplicó el reemplazo autorizado desde la excepción aprobada.',
        [
            'id_excepcion' => $idExcepcion,
            'imei_original' => $caso['imei_original'] ?? null,
            'imei_reemplazo' => $equipoNuevo['imei1'] ?? null,
            'imei2_reemplazo' => $equipoNuevo['imei2'] ?? null,
            'inventario_id' => $equipoNuevo['inventario_id'] ?? null,
            'producto_id' => $equipoNuevo['id_producto'] ?? null,
            'precio_original' => $excepcion['precio_original'] ?? null,
            'precio_reemplazo' => $excepcion['precio_reemplazo'] ?? null,
        ],
        $ID_USUARIO,
        $NOMBRE_USUARIO,
        $ROL
    );

    $conn->commit();
    header("Location: garantias_detalle.php?id={$idGarantia}&okapplyexc=1#bloque-excepcion");
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    redirect_error($idGarantia, $e->getMessage());
}