<?php
// ajax_clientes_buscar_modal.php
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

$q          = trim($_POST['q'] ?? '');
$idSucursal = (int)($_POST['id_sucursal'] ?? 0); // 🔹 ahora sí lo usamos para ordenar

if ($q === '') {
    jres(['ok' => false, 'message' => 'Falta término de búsqueda.']);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn->set_charset('utf8mb4');

    // Normalizamos el término de búsqueda
    $soloDigitos = preg_replace('/\D+/', '', $q);
    $esTelefono  = (strlen($soloDigitos) >= 7);

    $likeNombre  = '%' . $q . '%';
    $likeCodigo  = '%' . $q . '%';
    $likeTel     = '%' . $soloDigitos . '%';

    /**
     * Busca clientes a nivel GLOBAL (sin filtrar por sucursal),
     * pero ordenando primero los de la sucursal indicada.
     *
     * @param mysqli $conn
     * @param string $likeNombre
     * @param string $likeCodigo
     * @param string $likeTel
     * @param bool   $esTelefono
     * @param int    $idSucursal
     * @return array
     */
    function buscarClientesGlobal(
        mysqli $conn,
        string $likeNombre,
        string $likeCodigo,
        string $likeTel,
        bool $esTelefono,
        int $idSucursal
    ): array {

        if ($esTelefono) {
            // Búsqueda global priorizando teléfono, pero también por nombre/código
            $sql = "
                SELECT 
                    c.id,
                    c.codigo_cliente,
                    c.nombre,
                    c.telefono,
                    c.correo,
                    c.fecha_alta,
                    c.id_sucursal,
                    s.nombre AS sucursal_nombre
                FROM clientes c
                LEFT JOIN sucursales s ON s.id = c.id_sucursal
                WHERE
                      c.nombre LIKE ?
                   OR c.codigo_cliente LIKE ?
                   OR REPLACE(REPLACE(c.telefono,' ',''),'-','') LIKE ?
                ORDER BY
                    CASE WHEN c.id_sucursal = ? THEN 0 ELSE 1 END,
                    s.nombre ASC,
                    c.nombre ASC
                LIMIT 50
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sssi', $likeNombre, $likeCodigo, $likeTel, $idSucursal);
        } else {
            // Búsqueda global por nombre/código
            $sql = "
                SELECT 
                    c.id,
                    c.codigo_cliente,
                    c.nombre,
                    c.telefono,
                    c.correo,
                    c.fecha_alta,
                    c.id_sucursal,
                    s.nombre AS sucursal_nombre
                FROM clientes c
                LEFT JOIN sucursales s ON s.id = c.id_sucursal
                WHERE
                      c.nombre LIKE ?
                   OR c.codigo_cliente LIKE ?
                ORDER BY
                    CASE WHEN c.id_sucursal = ? THEN 0 ELSE 1 END,
                    s.nombre ASC,
                    c.nombre ASC
                LIMIT 50
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssi', $likeNombre, $likeCodigo, $idSucursal);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $clientes = [];
        while ($row = $res->fetch_assoc()) {
            $clientes[] = [
                'id'              => (int)$row['id'],
                'codigo_cliente'  => $row['codigo_cliente'],
                'nombre'          => $row['nombre'],
                'telefono'        => $row['telefono'],
                'correo'          => $row['correo'],
                'fecha_alta'      => $row['fecha_alta'],
                'id_sucursal'     => (int)$row['id_sucursal'],
                'sucursal_nombre' => $row['sucursal_nombre'] ?? 'Sin sucursal',
            ];
        }
        $stmt->close();
        return $clientes;
    }

    // 🔍 Búsqueda SIEMPRE GLOBAL (sin filtrar por sucursal), pero ordenando por sucursal
    $clientes = buscarClientesGlobal($conn, $likeNombre, $likeCodigo, $likeTel, $esTelefono, $idSucursal);

    $msg = 'Se encontraron ' . count($clientes) . ' cliente(s) a nivel global.';

    jres([
        'ok'       => true,
        'clientes' => $clientes,
        'message'  => $msg,
        'scope'    => 'global'
    ]);

} catch (mysqli_sql_exception $e) {
    jres(['ok' => false, 'message' => 'Error SQL: ' . $e->getMessage()]);
} catch (Throwable $e) {
    jres(['ok' => false, 'message' => 'Error inesperado: ' . $e->getMessage()]);
}
