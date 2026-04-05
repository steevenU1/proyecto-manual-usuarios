<?php
// ajax_crear_cliente.php
// Crea un cliente NUEVO o devuelve uno existente si coincide nombre+teléfono+sucursal.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function jexit($ok, $message, $extra = [])
{
    echo json_encode(array_merge([
        'ok'      => $ok ? true : false,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (!isset($_SESSION['id_usuario'])) {
        jexit(false, 'No autenticado.');
    }

    // ====== Datos de entrada ======
    $nombre      = trim($_POST['nombre']   ?? '');
    $telefonoRaw = trim($_POST['telefono'] ?? '');
    $correo      = trim($_POST['correo']   ?? '');
    $idSucursal  = (int)($_POST['id_sucursal'] ?? 0);

    // Normalizar teléfono (solo dígitos)
    $telefono = preg_replace('/\D+/', '', $telefonoRaw);

    if ($nombre === '') {
        jexit(false, 'El nombre del cliente es obligatorio.');
    }
    if (!preg_match('/^\d{10}$/', $telefono)) {
        jexit(false, 'El teléfono debe tener exactamente 10 dígitos.');
    }
    if ($idSucursal <= 0) {
        jexit(false, 'Sucursal no válida.');
    }

    // ====== 1) Buscar si YA existe mismo nombre+teléfono en esa sucursal ======
    //    - Permitimos que haya otros clientes con el mismo teléfono pero nombre distinto.
    $sqlBusca = "
        SELECT id, codigo_cliente, nombre, telefono, correo, fecha_alta
        FROM clientes
        WHERE id_sucursal = ?
          AND telefono = ?
          AND UPPER(TRIM(nombre)) = UPPER(TRIM(?))
        LIMIT 1
    ";
    $stmt = $conn->prepare($sqlBusca);
    $stmt->bind_param('iss', $idSucursal, $telefono, $nombre);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        // Ya existía ese MISMO cliente (misma sucursal, mismo nombre y teléfono)
        jexit(true, 'El cliente ya existía en esta sucursal.', [
            'ya_existia' => true,
            'cliente'    => [
                'id'             => (int)$row['id'],
                'codigo_cliente' => $row['codigo_cliente'],
                'nombre'         => $row['nombre'],
                'telefono'       => $row['telefono'],
                'correo'         => $row['correo'],
                'fecha_alta'     => $row['fecha_alta'],
            ],
        ]);
    }

    // ====== 2) Generar código de cliente por sucursal ======
    // Formato: CL-SS-XXXXXX (SS = id sucursal con 2 dígitos; XXXXXX = consecutivo)
    $prefijo = sprintf('CL-%02d-', $idSucursal);

    $sqlMax = "
        SELECT codigo_cliente
        FROM clientes
        WHERE id_sucursal = ?
          AND codigo_cliente LIKE CONCAT(?, '%')
        ORDER BY codigo_cliente DESC
        LIMIT 1
    ";
    $stmtMax = $conn->prepare($sqlMax);
    $stmtMax->bind_param('is', $idSucursal, $prefijo);
    $stmtMax->execute();
    $resMax = $stmtMax->get_result();

    $consec = 0;
    if ($rowMax = $resMax->fetch_assoc()) {
        $code = $rowMax['codigo_cliente']; // ej: CL-40-000003
        if (preg_match('/-(\d{1,6})$/', $code, $m)) {
            $consec = (int)$m[1];
        }
    }
    $nuevoConsec = $consec + 1;
    $codigoCliente = $prefijo . str_pad((string)$nuevoConsec, 6, '0', STR_PAD_LEFT);

    // ====== 3) Insertar nuevo cliente ======
    $sqlIns = "
        INSERT INTO clientes (codigo_cliente, nombre, telefono, correo, fecha_alta, activo, id_sucursal)
        VALUES (?, ?, ?, ?, NOW(), 1, ?)
    ";
    $stmtIns = $conn->prepare($sqlIns);
    $stmtIns->bind_param('ssssi', $codigoCliente, $nombre, $telefono, $correo, $idSucursal);
    $stmtIns->execute();

    $nuevoId = (int)$conn->insert_id;

    jexit(true, 'Cliente creado correctamente.', [
        'ya_existia' => false,
        'cliente'    => [
            'id'             => $nuevoId,
            'codigo_cliente' => $codigoCliente,
            'nombre'         => $nombre,
            'telefono'       => $telefono,
            'correo'         => $correo,
            'fecha_alta'     => date('Y-m-d H:i:s'),
        ],
    ]);

} catch (Throwable $e) {
    // En caso de error inesperado, devolvemos mensaje para el alert del front
    jexit(false, 'Error al guardar el cliente: ' . $e->getMessage());
}
