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

$ROLES_RESUELVE = ['Admin', 'Administrador', 'Logistica'];

if (!in_array($ROL, $ROLES_RESUELVE, true)) {
    http_response_code(403);
    exit('Sin permiso para resolver excepciones.');
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

function null_if_empty($v): ?string {
    $v = trim((string)$v);
    return $v === '' ? null : $v;
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
$required = ['garantias_casos', 'garantias_excepciones_reemplazo', 'garantias_eventos'];
foreach ($required as $tb) {
    if (!table_exists($conn, $tb)) {
        exit("No existe la tabla requerida: " . h($tb));
    }
}

/* =========================================================
   INPUT
========================================================= */
$idGarantia  = (int)($_POST['id_garantia'] ?? 0);
$idExcepcion = (int)($_POST['id_excepcion'] ?? 0);
$accion      = trim((string)($_POST['accion'] ?? ''));
$comentario  = null_if_empty($_POST['comentario_resolucion'] ?? null);

if ($idGarantia <= 0) {
    exit('ID de garantía inválido.');
}
if ($idExcepcion <= 0) {
    redirect_error($idGarantia, 'ID de excepción inválido.');
}
if (!in_array($accion, ['autorizar', 'rechazar'], true)) {
    redirect_error($idGarantia, 'Acción inválida.');
}

if ($accion === 'rechazar' && ($comentario === null || mb_strlen($comentario) < 5)) {
    redirect_error($idGarantia, 'Para rechazar la excepción debes capturar un comentario de al menos 5 caracteres.');
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
    redirect_error($idGarantia, 'No se encontró la solicitud de excepción.');
}

if ((string)$excepcion['estatus'] !== 'solicitada') {
    redirect_error($idGarantia, 'La solicitud ya fue resuelta previamente.');
}

/* =========================================================
   RESOLVER
========================================================= */
$nuevoEstatus = ($accion === 'autorizar') ? 'autorizada' : 'rechazada';

$conn->begin_transaction();

try {
    $sqlUp = "UPDATE garantias_excepciones_reemplazo
              SET estatus = ?,
                  comentario_resolucion = ?,
                  id_usuario_resuelve = ?,
                  fecha_resolucion = NOW()
              WHERE id = ?
                AND id_garantia = ?";
    $st = $conn->prepare($sqlUp);
    if (!$st) {
        throw new Exception("Error al preparar actualización de excepción: " . $conn->error);
    }
    $st->bind_param("ssiii", $nuevoEstatus, $comentario, $ID_USUARIO, $idExcepcion, $idGarantia);
    if (!$st->execute()) {
        throw new Exception("Error al actualizar excepción: " . $st->error);
    }
    $st->close();

    if ($accion === 'autorizar') {
        registrar_evento(
            $conn,
            $idGarantia,
            'excepcion_reemplazo_autorizada',
            $caso['estado'] ?? null,
            $caso['estado'] ?? null,
            'La excepción de reemplazo fue autorizada.',
            [
                'id_excepcion' => $idExcepcion,
                'imei_propuesto' => $excepcion['imei_propuesto'] ?? null,
                'imei2_propuesto' => $excepcion['imei2_propuesto'] ?? null,
                'precio_original' => $excepcion['precio_original'] ?? null,
                'precio_reemplazo' => $excepcion['precio_reemplazo'] ?? null,
                'comentario_resolucion' => $comentario,
            ],
            $ID_USUARIO,
            $NOMBRE_USUARIO,
            $ROL
        );
    } else {
        registrar_evento(
            $conn,
            $idGarantia,
            'excepcion_reemplazo_rechazada',
            $caso['estado'] ?? null,
            $caso['estado'] ?? null,
            'La excepción de reemplazo fue rechazada.',
            [
                'id_excepcion' => $idExcepcion,
                'imei_propuesto' => $excepcion['imei_propuesto'] ?? null,
                'imei2_propuesto' => $excepcion['imei2_propuesto'] ?? null,
                'precio_original' => $excepcion['precio_original'] ?? null,
                'precio_reemplazo' => $excepcion['precio_reemplazo'] ?? null,
                'comentario_resolucion' => $comentario,
            ],
            $ID_USUARIO,
            $NOMBRE_USUARIO,
            $ROL
        );
    }

    $conn->commit();
    header("Location: garantias_detalle.php?id={$idGarantia}&okrexc=1#bloque-excepcion");
    exit();

} catch (Throwable $e) {
    $conn->rollback();
    redirect_error($idGarantia, $e->getMessage());
}