<?php
// ajax_clientes_crear_modal.php
session_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/db.php';

function jres(array $data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['id_usuario'])) {
    jres(['ok' => false, 'message' => 'No autenticado.']);
}

$nombre      = trim($_POST['nombre']      ?? '');
$telefonoRaw = trim($_POST['telefono']    ?? '');
$correo      = trim($_POST['correo']      ?? '');
$idSucursal  = (int)($_POST['id_sucursal'] ?? 0);

// Normalizar teléfono: solo dígitos
$telefono = preg_replace('/\D+/', '', $telefonoRaw);

if ($nombre === '') {
    jres(['ok' => false, 'message' => 'El nombre del cliente es obligatorio.']);
}
if (strlen($telefono) < 7) {
    jres(['ok' => false, 'message' => 'El teléfono debe tener al menos 7 dígitos (idealmente 10).']);
}
if ($idSucursal <= 0) {
    jres(['ok' => false, 'message' => 'Sucursal inválida para el cliente.']);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->set_charset('utf8mb4');

    // 1) ¿Ya existe un cliente con ese teléfono EN ESA SUCURSAL?
    $sqlBus = "SELECT id, codigo_cliente, nombre, telefono, correo, fecha_alta, id_sucursal
               FROM clientes
               WHERE REPLACE(REPLACE(telefono,' ',''),'-','') = ?
                 AND id_sucursal = ?
               LIMIT 1";
    $stBus = $conn->prepare($sqlBus);
    $stBus->bind_param('si', $telefono, $idSucursal);
    $stBus->execute();
    $resBus = $stBus->get_result();

    if ($row = $resBus->fetch_assoc()) {
        // Ya existía → lo regresamos y marcamos bandera
        $stBus->close();
        jres([
            'ok'         => true,
            'ya_existia' => true,
            'cliente'    => [
                'id'             => (int)$row['id'],
                'codigo_cliente' => $row['codigo_cliente'],
                'nombre'         => $row['nombre'],
                'telefono'       => $row['telefono'],
                'correo'         => $row['correo'],
                'fecha_alta'     => $row['fecha_alta'],
                'id_sucursal'    => (int)$row['id_sucursal'],
            ],
            'message' => 'El cliente ya existía en esta sucursal.'
        ]);
    }
    $stBus->close();

    // 2) Generar siguiente código de cliente por sucursal
    // Formato sugerido: CL-SS-XXXXXX  (SS = id_sucursal con 2 digitos)
    $prefijoSuc = str_pad((string)$idSucursal, 2, '0', STR_PAD_LEFT);

    $sqlMax = "
        SELECT MAX(CAST(SUBSTRING_INDEX(codigo_cliente, '-', -1) AS UNSIGNED)) AS max_consec
        FROM clientes
        WHERE codigo_cliente LIKE CONCAT('CL-',$prefijoSuc,'-%')
    ";
    // Como usamos variable PHP en el LIKE, lo armamos en PHP mejor:
    $likePref = 'CL-' . $prefijoSuc . '-%';
    $sqlMax = "
        SELECT MAX(CAST(SUBSTRING_INDEX(codigo_cliente, '-', -1) AS UNSIGNED)) AS max_consec
        FROM clientes
        WHERE codigo_cliente LIKE ?
    ";
    $stMax = $conn->prepare($sqlMax);
    $stMax->bind_param('s', $likePref);
    $stMax->execute();
    $resMax = $stMax->get_result();
    $maxConsec = 0;
    if ($rowMax = $resMax->fetch_assoc()) {
        $maxConsec = (int)($rowMax['max_consec'] ?? 0);
    }
    $stMax->close();

    $nuevoConsec = $maxConsec + 1;
    $codigoCliente = sprintf('CL-%s-%06d', $prefijoSuc, $nuevoConsec);

    // 3) Insertar nuevo cliente
    $sqlIns = "
        INSERT INTO clientes (codigo_cliente, nombre, telefono, correo, fecha_alta, activo, id_sucursal)
        VALUES (?, ?, ?, ?, NOW(), 1, ?)
    ";
    $stIns = $conn->prepare($sqlIns);
    $stIns->bind_param('ssssi', $codigoCliente, $nombre, $telefono, $correo, $idSucursal);
    $stIns->execute();
    $nuevoId = $stIns->insert_id;
    $stIns->close();

    jres([
        'ok'         => true,
        'ya_existia' => false,
        'cliente'    => [
            'id'             => (int)$nuevoId,
            'codigo_cliente' => $codigoCliente,
            'nombre'         => $nombre,
            'telefono'       => $telefono,
            'correo'         => $correo,
            'fecha_alta'     => date('Y-m-d H:i:s'),
            'id_sucursal'    => $idSucursal,
        ],
        'message' => 'Cliente creado correctamente.'
    ]);

} catch (mysqli_sql_exception $e) {
    // Si todavía existe un UNIQUE por teléfono, tratamos de rescatar al cliente
    if ($e->getCode() == 1062) {
        try {
            $sqlDup = "SELECT id, codigo_cliente, nombre, telefono, correo, fecha_alta, id_sucursal
                       FROM clientes
                       WHERE REPLACE(REPLACE(telefono,' ',''),'-','') = ?
                         AND id_sucursal = ?
                       LIMIT 1";
            $stDup = $conn->prepare($sqlDup);
            $stDup->bind_param('si', $telefono, $idSucursal);
            $stDup->execute();
            $resDup = $stDup->get_result();
            if ($rowDup = $resDup->fetch_assoc()) {
                $stDup->close();
                jres([
                    'ok'         => true,
                    'ya_existia' => true,
                    'cliente'    => [
                        'id'             => (int)$rowDup['id'],
                        'codigo_cliente' => $rowDup['codigo_cliente'],
                        'nombre'         => $rowDup['nombre'],
                        'telefono'       => $rowDup['telefono'],
                        'correo'         => $rowDup['correo'],
                        'fecha_alta'     => $rowDup['fecha_alta'],
                        'id_sucursal'    => (int)$rowDup['id_sucursal'],
                    ],
                    'message' => 'El cliente ya existía (por teléfono único).'
                ]);
            }
            $stDup->close();
        } catch (Throwable $e2) {
            // Si también falla aquí, ya mandamos error genérico
        }
    }

    jres([
        'ok'      => false,
        'message' => 'Error SQL al guardar el cliente: ' . $e->getMessage()
    ]);

} catch (Throwable $e) {
    jres([
        'ok'      => false,
        'message' => 'Error inesperado al guardar el cliente: ' . $e->getMessage()
    ]);
}
