<?php
// ajax_crear_cliente.php
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/db.php';

function json_response(array $data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Seguridad básica
if (!isset($_SESSION['id_usuario'])) {
    json_response([
        'ok'      => false,
        'message' => 'No autenticado.'
    ]);
}

$nombre      = trim($_POST['nombre'] ?? '');
$telefonoRaw = trim($_POST['telefono'] ?? '');
$correo      = trim($_POST['correo'] ?? '');
$idSucursal  = (int)($_POST['id_sucursal'] ?? 0);

// Normalizar teléfono a solo dígitos
$telefono = preg_replace('/\D+/', '', $telefonoRaw);

// Validaciones básicas
if ($nombre === '') {
    json_response([
        'ok'      => false,
        'message' => 'El nombre del cliente es obligatorio.'
    ]);
}

if (!preg_match('/^\d{10}$/', $telefono)) {
    json_response([
        'ok'      => false,
        'message' => 'El teléfono debe tener exactamente 10 dígitos.'
    ]);
}

if ($idSucursal <= 0) {
    json_response([
        'ok'      => false,
        'message' => 'Sucursal inválida.'
    ]);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->set_charset('utf8mb4');

    // ============================
    // 1) Crear SIEMPRE un cliente nuevo
    //    (permitimos teléfonos duplicados)
    // ============================

    // 1.a) Obtener consecutivo para codigo_cliente por sucursal
    // Formato: CL-{id_sucursal}-{000001}
    $sqlMax = "
        SELECT 
            MAX(
                CAST(SUBSTRING_INDEX(codigo_cliente, '-', -1) AS UNSIGNED)
            ) AS max_consecutivo
        FROM clientes
        WHERE id_sucursal = ?
    ";
    $stmtMax = $conn->prepare($sqlMax);
    $stmtMax->bind_param('i', $idSucursal);
    $stmtMax->execute();
    $resMax = $stmtMax->get_result()->fetch_assoc();
    $stmtMax->close();

    $maxConsec = (int)($resMax['max_consecutivo'] ?? 0);
    $nextConsec = $maxConsec + 1;

    $codigoCliente = sprintf(
        'CL-%d-%06d',
        $idSucursal,
        $nextConsec
    );

    // 1.b) Insertar cliente
    $sqlIns = "
        INSERT INTO clientes (codigo_cliente, nombre, telefono, correo, fecha_alta, activo, id_sucursal)
        VALUES (?, ?, ?, ?, NOW(), 1, ?)
    ";
    $stmtIns = $conn->prepare($sqlIns);
    $stmtIns->bind_param('ssssi', $codigoCliente, $nombre, $telefono, $correo, $idSucursal);
    $stmtIns->execute();
    $nuevoId = $stmtIns->insert_id;
    $stmtIns->close();

    // 1.c) Devolver datos del nuevo cliente
    json_response([
        'ok'          => true,
        'ya_existia'  => false,
        'cliente'     => [
            'id'             => $nuevoId,
            'codigo_cliente' => $codigoCliente,
            'nombre'         => $nombre,
            'telefono'       => $telefono,
            'correo'         => $correo,
            'id_sucursal'    => $idSucursal
        ],
        'message' => 'Cliente creado correctamente.'
    ]);

} catch (mysqli_sql_exception $e) {
    // Aquí verás el error real si algo truena (por ejemplo, índice único que siga existiendo)
    json_response([
        'ok'      => false,
        'message' => 'Error SQL: ' . $e->getMessage()
    ]);
} catch (Throwable $e) {
    json_response([
        'ok'      => false,
        'message' => 'Error inesperado: ' . $e->getMessage()
    ]);
}
