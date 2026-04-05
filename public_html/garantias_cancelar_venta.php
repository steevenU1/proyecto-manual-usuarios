<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

$ROL         = (string)($_SESSION['rol'] ?? '');
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

$ROLES_PERMITIDOS = [
    'Admin', 'Administrador',
    'Gerente', 'Ejecutivo',
    'Subdis_Admin', 'Subdis_Gerente', 'Subdis_Ejecutivo'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para cancelar ventas por garantía.');
}

/* =========================================================
   HELPERS
========================================================= */
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

function get_table_columns(mysqli $conn, string $table): array {
    $cols = [];
    $sql = "SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION";
    $st = $conn->prepare($sql);
    $st->bind_param("s", $table);
    $st->execute();
    $rs = $st->get_result();
    while ($row = $rs->fetch_assoc()) {
        $cols[] = (string)$row['COLUMN_NAME'];
    }
    $st->close();
    return $cols;
}

function parse_date_flexible(?string $dateStr): ?DateTime {
    $dateStr = trim((string)$dateStr);
    if ($dateStr === '') return null;

    $tz = new DateTimeZone('America/Mexico_City');
    $formats = ['Y-m-d', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y', 'd/m/Y H:i:s', 'd/m/Y H:i', DateTime::ATOM];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $dateStr, $tz);
        if ($dt instanceof DateTime) return $dt;
    }

    $ts = strtotime($dateStr);
    if ($ts === false) return null;

    $dt = new DateTime('now', $tz);
    $dt->setTimestamp($ts);
    return $dt;
}

function diff_days_from_today(?string $dateStr): ?int {
    $base = parse_date_flexible($dateStr);
    if (!$base) return null;

    $tz = new DateTimeZone('America/Mexico_City');
    $today = new DateTime('today', $tz);
    $base->setTime(0, 0, 0);

    $seconds = $today->getTimestamp() - $base->getTimestamp();
    return (int) floor($seconds / 86400);
}

function puede_operar_caso(array $caso, string $rol, int $idUsuario, int $idSucursal): bool {
    if (in_array($rol, ['Admin', 'Administrador', 'Subdis_Admin'], true)) {
        return true;
    }

    if (in_array($rol, ['Gerente', 'Subdis_Gerente'], true)) {
        return ((int)($caso['id_sucursal'] ?? 0) === $idSucursal);
    }

    if (in_array($rol, ['Ejecutivo', 'Subdis_Ejecutivo'], true)) {
        return ((int)($caso['id_usuario_captura'] ?? 0) === $idUsuario);
    }

    return false;
}

function bind_params_dynamic(mysqli_stmt $stmt, string $types, array &$params): void {
    $refs = [];
    $refs[] = $types;
    foreach ($params as $k => $v) {
        $refs[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
}

function redirect_detalle(int $idGarantia, string $query = ''): void {
    $url = 'garantias_detalle.php?id=' . $idGarantia;
    if ($query !== '') {
        $url .= '&' . $query;
    }
    header('Location: ' . $url);
    exit();
}

function fail_and_redirect(int $idGarantia, string $msg): void {
    redirect_detalle($idGarantia, 'err=' . urlencode($msg));
}

function registrar_evento_seguro(
    mysqli $conn,
    int $idGarantia,
    string $tipo,
    string $descripcion,
    int $idUsuario,
    ?string $estadoAnterior = null,
    ?string $estadoNuevo = null
): void {
    if (!table_exists($conn, 'garantias_eventos')) {
        return;
    }

    $campos = get_table_columns($conn, 'garantias_eventos');
    if (!$campos) {
        return;
    }

    $data = [];
    if (in_array('id_garantia', $campos, true))       $data['id_garantia'] = $idGarantia;
    if (in_array('tipo_evento', $campos, true))       $data['tipo_evento'] = $tipo;
    if (in_array('descripcion', $campos, true))       $data['descripcion'] = $descripcion;
    if (in_array('detalle', $campos, true))           $data['detalle'] = $descripcion;
    if (in_array('estado_anterior', $campos, true))   $data['estado_anterior'] = $estadoAnterior;
    if (in_array('estado_nuevo', $campos, true))      $data['estado_nuevo'] = $estadoNuevo;
    if (in_array('id_usuario', $campos, true))        $data['id_usuario'] = $idUsuario;
    if (in_array('id_usuario_accion', $campos, true)) $data['id_usuario_accion'] = $idUsuario;
    if (in_array('fecha_evento', $campos, true))      $data['fecha_evento'] = date('Y-m-d H:i:s');
    if (in_array('created_at', $campos, true) && !in_array('fecha_evento', $campos, true)) {
        $data['created_at'] = date('Y-m-d H:i:s');
    }

    if (!$data) return;

    $cols = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $types = '';
    $params = [];

    foreach ($cols as $col) {
        $val = $data[$col];
        $params[] = $val;
        $types .= is_int($val) ? 'i' : 's';
    }

    $sql = "INSERT INTO garantias_eventos (`" . implode('`,`', $cols) . "`) VALUES ($placeholders)";
    $st = $conn->prepare($sql);
    bind_params_dynamic($st, $types, $params);
    $st->execute();
    $st->close();
}

function insert_dynamic(mysqli $conn, string $table, array $data): int {
    if (!$data) {
        throw new RuntimeException("No hay datos para insertar en {$table}.");
    }

    $cols = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES ({$placeholders})";

    $types = '';
    $params = [];
    foreach ($cols as $col) {
        $val = $data[$col];
        $params[] = $val;
        if (is_int($val)) {
            $types .= 'i';
        } elseif (is_float($val)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }
    }

    $st = $conn->prepare($sql);
    if (!$st) {
        throw new RuntimeException("Error al preparar insert en {$table}: " . $conn->error);
    }

    bind_params_dynamic($st, $types, $params);
    $st->execute();
    $insertId = (int)$conn->insert_id;
    $st->close();

    return $insertId;
}

/* =========================================================
   ENTRADA
========================================================= */
$idGarantia = (int)($_POST['id_garantia'] ?? $_POST['id'] ?? $_GET['id_garantia'] ?? $_GET['id'] ?? 0);

if ($idGarantia <= 0) {
    http_response_code(400);
    exit('ID de garantía inválido.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail_and_redirect($idGarantia, 'Método no permitido para cancelar la venta.');
}

if (!table_exists($conn, 'ventas_canceladas')) {
    fail_and_redirect($idGarantia, 'No existe la tabla ventas_canceladas.');
}

if (!table_exists($conn, 'detalle_venta_cancelada')) {
    fail_and_redirect($idGarantia, 'No existe la tabla detalle_venta_cancelada.');
}

$conn->begin_transaction();

try {
    /* =====================================================
       1) CARGAR CASO
    ===================================================== */
    $sqlCaso = "SELECT gc.*
                FROM garantias_casos gc
                WHERE gc.id = ?
                LIMIT 1
                FOR UPDATE";
    $st = $conn->prepare($sqlCaso);
    $st->bind_param('i', $idGarantia);
    $st->execute();
    $caso = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$caso) {
        throw new RuntimeException('No se encontró el caso de garantía.');
    }

    if (!puede_operar_caso($caso, $ROL, $ID_USUARIO, $ID_SUCURSAL)) {
        throw new RuntimeException('No tienes permiso para operar este caso.');
    }

    $estadoAnterior = (string)($caso['estado'] ?? '');
    $dictamen = (string)($caso['dictamen_preliminar'] ?? '');
    $idVenta = (int)($caso['id_venta'] ?? 0);
    $idSucursalCaso = (int)($caso['id_sucursal'] ?? 0);
    $yaCancelada = (int)($caso['id_venta_cancelada'] ?? 0);
    $diasCompra = diff_days_from_today((string)($caso['fecha_compra'] ?? ''));

    if ($yaCancelada > 0 || $estadoAnterior === 'cerrado') {
        throw new RuntimeException('Este caso ya fue procesado previamente.');
    }

    if ($idVenta <= 0) {
        throw new RuntimeException('El caso no tiene una venta ligada para cancelar.');
    }

    if ($diasCompra === null) {
        throw new RuntimeException('No fue posible calcular los días desde la compra.');
    }

    if ($diasCompra < 0) {
        throw new RuntimeException('La fecha de compra es inválida.');
    }

    if ($diasCompra > 7) {
        throw new RuntimeException('Este caso ya no está dentro de los 7 días para cambio inmediato en tienda.');
    }

    if ($dictamen !== 'procede') {
        throw new RuntimeException('Solo se puede cancelar la venta cuando el dictamen preliminar es procede.');
    }

    if (!in_array($estadoAnterior, ['capturada', 'pendiente_cancelacion_venta_tienda'], true)) {
        throw new RuntimeException('El estado actual del caso no permite cancelación directa en tienda.');
    }

    /* =====================================================
       2) CARGAR VENTA Y DETALLE
    ===================================================== */
    $sqlVenta = "SELECT * FROM ventas WHERE id = ? LIMIT 1 FOR UPDATE";
    $st = $conn->prepare($sqlVenta);
    $st->bind_param('i', $idVenta);
    $st->execute();
    $venta = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$venta) {
        throw new RuntimeException('La venta original ya no existe o ya fue eliminada.');
    }

    if ((int)($venta['id_sucursal'] ?? 0) !== $idSucursalCaso && !in_array($ROL, ['Admin', 'Administrador', 'Subdis_Admin'], true)) {
        throw new RuntimeException('La venta pertenece a otra sucursal.');
    }

    $sqlDetalles = "SELECT * FROM detalle_venta WHERE id_venta = ? ORDER BY id ASC";
    $st = $conn->prepare($sqlDetalles);
    $st->bind_param('i', $idVenta);
    $st->execute();
    $resDet = $st->get_result();
    $detalles = [];
    while ($row = $resDet->fetch_assoc()) {
        $detalles[] = $row;
    }
    $st->close();

    if (!$detalles) {
        throw new RuntimeException('La venta no tiene detalle_venta para respaldar.');
    }

    /* =====================================================
       3) RESPALDAR VENTA CANCELADA
    ===================================================== */
    $colsVentasCanceladas = get_table_columns($conn, 'ventas_canceladas');
    $dataVentaCancelada = [];

    $mapStatic = [
        'id_venta_original'     => (int)$venta['id'],
        'motivo_cancelacion'    => 'Cancelada por garantia',
        'detalle_cancelacion'   => 'Cancelación automática desde módulo de garantías por cambio inmediato en tienda dentro de 7 días.',
        'origen_cancelacion'    => 'garantias',
        'id_garantia'           => $idGarantia,
        'cancelada_por'         => $ID_USUARIO,
    ];

    foreach ($mapStatic as $col => $value) {
        if (in_array($col, $colsVentasCanceladas, true)) {
            $dataVentaCancelada[$col] = $value;
        }
    }

    if (in_array('fecha_cancelacion', $colsVentasCanceladas, true)) {
        $dataVentaCancelada['fecha_cancelacion'] = date('Y-m-d H:i:s');
    }

    $camposVentaCopiables = [
        'tag', 'nombre_cliente', 'telefono_cliente', 'tipo_venta', 'precio_venta', 'fecha_venta',
        'id_usuario', 'id_sucursal', 'id_esquema', 'comision', 'comision_gerente',
        'enganche', 'forma_pago_enganche', 'enganche_efectivo', 'enganche_tarjeta',
        'plazo_semanas', 'financiera', 'comentarios', 'comision_especial',
        'id_cliente', 'codigo_referido', 'propiedad', 'id_subdis',
        'promo_descuento_id', 'promo_descuento_modo', 'promo_descuento_porcentaje'
    ];

    foreach ($camposVentaCopiables as $col) {
        if (in_array($col, $colsVentasCanceladas, true)) {
            $dataVentaCancelada[$col] = $venta[$col] ?? null;
        }
    }

    $idVentaCancelada = insert_dynamic($conn, 'ventas_canceladas', $dataVentaCancelada);

    /* =====================================================
       4) RESPALDAR DETALLE CANCELADO
    ===================================================== */
    $colsDetalleCancelado = get_table_columns($conn, 'detalle_venta_cancelada');

    foreach ($detalles as $det) {
        $dataDetCancelado = [];

        $mapDet = [
            'id_venta_cancelada'       => $idVentaCancelada,
            'id_detalle_venta_original'=> isset($det['id']) ? (int)$det['id'] : null,
            'id_producto'              => isset($det['id_producto']) ? (int)$det['id_producto'] : null,
            'es_combo'                 => isset($det['es_combo']) ? (int)$det['es_combo'] : 0,
            'es_regalo'                => isset($det['es_regalo']) ? (int)$det['es_regalo'] : 0,
            'id_promo_regalo'          => isset($det['id_promo_regalo']) && $det['id_promo_regalo'] !== '' ? (int)$det['id_promo_regalo'] : null,
            'imei1'                    => $det['imei1'] ?? null,
            'precio_unitario'          => isset($det['precio_unitario']) ? (float)$det['precio_unitario'] : 0.0,
            'comision_regular'         => isset($det['comision_regular']) ? (float)$det['comision_regular'] : 0.0,
            'comision_especial'        => isset($det['comision_especial']) ? (float)$det['comision_especial'] : 0.0,
            'comision_gerente'         => isset($det['comision_gerente']) ? (float)$det['comision_gerente'] : 0.0,
            'comision'                 => isset($det['comision']) ? (float)$det['comision'] : 0.0,
        ];

        foreach ($mapDet as $col => $value) {
            if (in_array($col, $colsDetalleCancelado, true)) {
                $dataDetCancelado[$col] = $value;
            }
        }

        insert_dynamic($conn, 'detalle_venta_cancelada', $dataDetCancelado);
    }

    /* =====================================================
       5) CAMBIAR INVENTARIO A GARANTIA
    ===================================================== */
    $idsProducto = [];
    $imeis = [];

    foreach ($detalles as $det) {
        if (!empty($det['id_producto'])) {
            $idsProducto[] = (int)$det['id_producto'];
        }
        if (!empty($det['imei1'])) {
            $imeis[] = trim((string)$det['imei1']);
        }
    }

    $idsProducto = array_values(array_unique(array_filter($idsProducto)));
    $imeis = array_values(array_unique(array_filter($imeis)));

    $inventarioActualizado = 0;

    if ($idsProducto) {
        $marks = implode(',', array_fill(0, count($idsProducto), '?'));
        $types = str_repeat('i', count($idsProducto));
        $params = $idsProducto;

        $sqlInv = "UPDATE inventario
                   SET estatus = 'Garantia'
                   WHERE id_producto IN ($marks)";

        if ($idSucursalCaso > 0 && column_exists($conn, 'inventario', 'id_sucursal')) {
            $sqlInv .= " AND id_sucursal = ?";
            $types .= 'i';
            $params[] = $idSucursalCaso;
        }

        $stInv = $conn->prepare($sqlInv);
        bind_params_dynamic($stInv, $types, $params);
        $stInv->execute();
        $inventarioActualizado += $stInv->affected_rows;
        $stInv->close();
    }

    if ($inventarioActualizado <= 0 && $imeis && table_exists($conn, 'productos')) {
        $marks = implode(',', array_fill(0, count($imeis), '?'));
        $types = str_repeat('s', count($imeis));
        $params = $imeis;

        $sqlInvImei = "UPDATE inventario i
                       INNER JOIN productos p ON p.id = i.id_producto
                       SET i.estatus = 'Garantia'
                       WHERE p.imei1 IN ($marks)";

        if ($idSucursalCaso > 0 && column_exists($conn, 'inventario', 'id_sucursal')) {
            $sqlInvImei .= " AND i.id_sucursal = ?";
            $types .= 'i';
            $params[] = $idSucursalCaso;
        }

        $stInv = $conn->prepare($sqlInvImei);
        bind_params_dynamic($stInv, $types, $params);
        $stInv->execute();
        $inventarioActualizado += $stInv->affected_rows;
        $stInv->close();
    }

    if ($inventarioActualizado <= 0) {
        throw new RuntimeException('No fue posible marcar el inventario vendido en estatus Garantia.');
    }

    /* =====================================================
       6) ELIMINAR VENTA ORIGINAL
    ===================================================== */
    $st = $conn->prepare("DELETE FROM detalle_venta WHERE id_venta = ?");
    $st->bind_param('i', $idVenta);
    $st->execute();
    $st->close();

    $st = $conn->prepare("DELETE FROM ventas WHERE id = ? LIMIT 1");
    $st->bind_param('i', $idVenta);
    $st->execute();
    if ($st->affected_rows <= 0) {
        $st->close();
        throw new RuntimeException('No fue posible eliminar la venta original.');
    }
    $st->close();

    /* =====================================================
       7) ACTUALIZAR CASO
    ===================================================== */
    $colsGarantias = get_table_columns($conn, 'garantias_casos');
    $updateParts = [];
    $paramsUpd = [];
    $typesUpd = '';

    if (in_array('estado', $colsGarantias, true)) {
        $updateParts[] = "estado = ?";
        $paramsUpd[] = 'cerrado';
        $typesUpd .= 's';
    }

    if (in_array('aplica_cambio_inmediato', $colsGarantias, true)) {
        $updateParts[] = "aplica_cambio_inmediato = ?";
        $paramsUpd[] = 1;
        $typesUpd .= 'i';
    }

    if (in_array('id_venta_cancelada', $colsGarantias, true)) {
        $updateParts[] = "id_venta_cancelada = ?";
        $paramsUpd[] = $idVentaCancelada;
        $typesUpd .= 'i';
    }

    if (in_array('resolucion_final', $colsGarantias, true)) {
        $updateParts[] = "resolucion_final = ?";
        $paramsUpd[] = 'Cancelación de venta por garantía en tienda dentro de 7 días';
        $typesUpd .= 's';
    }

    if (in_array('fecha_cierre', $colsGarantias, true)) {
        $updateParts[] = "fecha_cierre = ?";
        $paramsUpd[] = date('Y-m-d H:i:s');
        $typesUpd .= 's';
    }

    if (in_array('updated_at', $colsGarantias, true)) {
        $updateParts[] = "updated_at = ?";
        $paramsUpd[] = date('Y-m-d H:i:s');
        $typesUpd .= 's';
    }

    if (!$updateParts) {
        throw new RuntimeException('No se encontraron columnas para actualizar el caso de garantía.');
    }

    $sqlUpdateCaso = "UPDATE garantias_casos
                      SET " . implode(", ", $updateParts) . "
                      WHERE id = ?
                      LIMIT 1";
    $paramsUpd[] = $idGarantia;
    $typesUpd .= 'i';

    $st = $conn->prepare($sqlUpdateCaso);
    bind_params_dynamic($st, $typesUpd, $paramsUpd);
    $st->execute();
    $st->close();

    registrar_evento_seguro(
        $conn,
        $idGarantia,
        'venta_cancelada_por_garantia',
        'Se canceló la venta original por garantía dentro de los primeros 7 días. Venta respaldada en ventas_canceladas #' . $idVentaCancelada . '.',
        $ID_USUARIO,
        $estadoAnterior,
        'cerrado'
    );

    $conn->commit();
    redirect_detalle($idGarantia, 'okcancelventa=1');

} catch (Throwable $e) {
    $conn->rollback();
    fail_and_redirect($idGarantia, $e->getMessage());
}
?>
