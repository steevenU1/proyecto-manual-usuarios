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

/* =========================================================
   PERMISOS
========================================================= */
$ROL = (string)($_SESSION['rol'] ?? '');
$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);
$NOMBRE_USUARIO = (string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario');

$ROLES_PERMITIDOS = ['Admin', 'Administrador', 'Logistica'];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para actualizar casos de logística.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

/* =========================================================
   HELPERS
========================================================= */
function htrim($v): string {
    return trim((string)$v);
}

function null_if_empty($v): ?string {
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function int_or_null($v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function dec_or_zero($v): float {
    if ($v === null || $v === '') return 0.0;
    return (float)$v;
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

function upsert_reparacion(
    mysqli $conn,
    int $idGarantia,
    array $data
): void {
    if (!table_exists($conn, 'garantias_reparaciones')) {
        return;
    }

    $sqlFind = "SELECT id FROM garantias_reparaciones WHERE id_garantia = ? ORDER BY id DESC LIMIT 1";
    $st = $conn->prepare($sqlFind);
    if (!$st) {
        throw new Exception("Error en prepare() de búsqueda de reparación: " . $conn->error);
    }
    $st->bind_param("i", $idGarantia);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $idRep = (int)($row['id'] ?? 0);

    if ($idRep > 0) {
        $sets = [];
        $params = [];
        $types = '';

        foreach ($data as $col => $val) {
            $sets[] = "{$col} = ?";
            $params[] = $val;
            $types .= is_int($val) ? 'i' : (is_float($val) ? 'd' : 's');
        }

        $sets[] = "updated_at = NOW()";

        $sql = "UPDATE garantias_reparaciones
                SET " . implode(", ", $sets) . "
                WHERE id = ?";

        $params[] = $idRep;
        $types .= 'i';

        $st = $conn->prepare($sql);
        if (!$st) {
            throw new Exception("Error en prepare() de update reparación: " . $conn->error);
        }

        bindParamsDynamic($st, $types, $params);

        if (!$st->execute()) {
            throw new Exception("Error al actualizar reparación: " . $st->error);
        }
        $st->close();
    } else {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = "INSERT INTO garantias_reparaciones (" . implode(',', $columns) . ", created_at, updated_at)
                VALUES (" . implode(',', $placeholders) . ", NOW(), NOW())";

        $params = array_values($data);
        $types = '';
        foreach ($params as $val) {
            $types .= is_int($val) ? 'i' : (is_float($val) ? 'd' : 's');
        }

        $st = $conn->prepare($sql);
        if (!$st) {
            throw new Exception("Error en prepare() de insert reparación: " . $conn->error);
        }

        bindParamsDynamic($st, $types, $params);

        if (!$st->execute()) {
            throw new Exception("Error al insertar reparación: " . $st->error);
        }
        $st->close();
    }
}

/* =========================================================
   INPUT
========================================================= */
$idGarantia = (int)($_POST['id_garantia'] ?? 0);
$accion = htrim($_POST['accion'] ?? '');
$observaciones = null_if_empty($_POST['observaciones_logistica'] ?? '');

$proveedorNombre = null_if_empty($_POST['proveedor_nombre'] ?? null);
$diagnosticoProveedor = null_if_empty($_POST['diagnostico_proveedor'] ?? null);
$costoRevision = dec_or_zero($_POST['costo_revision'] ?? 0);
$costoReparacion = dec_or_zero($_POST['costo_reparacion'] ?? 0);
$costoTotal = $costoRevision + $costoReparacion;
$tiempoEstimadoDias = int_or_null($_POST['tiempo_estimado_dias'] ?? null);

if ($idGarantia <= 0) {
    exit('ID de garantía inválido.');
}

$accionesPermitidas = [
    'autorizar_garantia',
    'rechazar_garantia',
    'enviar_proveedor',
    'cotizacion_disponible',
    'cotizacion_aceptada',
    'cotizacion_rechazada',
    'en_reparacion',
    'marcar_reparado',
    'cerrar_caso'
];

if (!in_array($accion, $accionesPermitidas, true)) {
    exit('Acción no válida.');
}

/* =========================================================
   VALIDAR TABLAS
========================================================= */
if (!table_exists($conn, 'garantias_casos') || !table_exists($conn, 'garantias_eventos')) {
    exit('No existen las tablas base del módulo de garantías.');
}

/* =========================================================
   CARGAR CASO
========================================================= */
$sql = "SELECT *
        FROM garantias_casos
        WHERE id = ?
        LIMIT 1";
$st = $conn->prepare($sql);
if (!$st) {
    exit("Error consultando caso: " . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8'));
}
$st->bind_param("i", $idGarantia);
$st->execute();
$caso = $st->get_result()->fetch_assoc();
$st->close();

if (!$caso) {
    exit('No se encontró el caso.');
}

$estadoAnterior = (string)$caso['estado'];
$estadoNuevo = $estadoAnterior;
$tipoEvento = 'comentario';
$descripcionEvento = 'Se registró una actualización de logística.';
$datosEvento = [];
$camposCaso = [
    'id_usuario_logistica' => $ID_USUARIO,
    'observaciones_logistica' => $observaciones,
];

/* =========================================================
   REGLAS POR ACCION
========================================================= */
switch ($accion) {
    case 'autorizar_garantia':
        $estadoNuevo = 'garantia_autorizada';
        $tipoEvento = 'garantia_autorizada';
        $descripcionEvento = 'Logística autorizó la garantía.';
        $camposCaso['fecha_dictamen'] = date('Y-m-d H:i:s');
        $datosEvento = [
            'accion' => $accion,
            'observaciones' => $observaciones
        ];
        break;

    case 'rechazar_garantia':
        $estadoNuevo = 'garantia_rechazada';
        $tipoEvento = 'garantia_rechazada';
        $descripcionEvento = 'Logística rechazó la garantía.';
        $camposCaso['fecha_dictamen'] = date('Y-m-d H:i:s');
        $datosEvento = [
            'accion' => $accion,
            'observaciones' => $observaciones
        ];
        break;

    case 'enviar_proveedor':
        $estadoNuevo = 'enviada_diagnostico';
        $tipoEvento = 'envio_proveedor';
        $descripcionEvento = 'El equipo fue enviado a proveedor para diagnóstico.';
        $camposCaso['fecha_envio_proveedor'] = date('Y-m-d H:i:s');
        $camposCaso['requiere_cotizacion'] = 1;
        $datosEvento = [
            'accion' => $accion,
            'proveedor_nombre' => $proveedorNombre,
            'observaciones' => $observaciones
        ];
        break;

    case 'cotizacion_disponible':
        $estadoNuevo = 'cotizacion_disponible';
        $tipoEvento = 'cotizacion_registrada';
        $descripcionEvento = 'Logística registró una cotización del proveedor.';
        $camposCaso['fecha_respuesta_proveedor'] = date('Y-m-d H:i:s');
        $camposCaso['es_reparable'] = 1;
        $camposCaso['requiere_cotizacion'] = 1;
        $datosEvento = [
            'accion' => $accion,
            'proveedor_nombre' => $proveedorNombre,
            'diagnostico_proveedor' => $diagnosticoProveedor,
            'costo_revision' => $costoRevision,
            'costo_reparacion' => $costoReparacion,
            'costo_total' => $costoTotal,
            'tiempo_estimado_dias' => $tiempoEstimadoDias,
            'observaciones' => $observaciones
        ];
        break;

    case 'cotizacion_aceptada':
        $estadoNuevo = 'cotizacion_aceptada';
        $tipoEvento = 'cotizacion_aceptada';
        $descripcionEvento = 'Se registró que el cliente aceptó la cotización.';
        $camposCaso['cliente_acepta_cotizacion'] = 1;
        $camposCaso['fecha_autorizacion_cliente'] = date('Y-m-d H:i:s');
        $datosEvento = [
            'accion' => $accion,
            'observaciones' => $observaciones
        ];
        break;

    case 'cotizacion_rechazada':
        $estadoNuevo = 'cotizacion_rechazada';
        $tipoEvento = 'cotizacion_rechazada';
        $descripcionEvento = 'Se registró que el cliente rechazó la cotización.';
        $camposCaso['cliente_acepta_cotizacion'] = 0;
        $camposCaso['fecha_autorizacion_cliente'] = date('Y-m-d H:i:s');
        $datosEvento = [
            'accion' => $accion,
            'observaciones' => $observaciones
        ];
        break;

    case 'en_reparacion':
        $estadoNuevo = 'en_reparacion';
        $tipoEvento = 'envio_reparacion';
        $descripcionEvento = 'El equipo ingresó a reparación.';
        $datosEvento = [
            'accion' => $accion,
            'proveedor_nombre' => $proveedorNombre,
            'observaciones' => $observaciones
        ];
        break;

    case 'marcar_reparado':
        $estadoNuevo = 'reparado';
        $tipoEvento = 'equipo_reparado';
        $descripcionEvento = 'Logística marcó el equipo como reparado.';
        $datosEvento = [
            'accion' => $accion,
            'observaciones' => $observaciones
        ];
        break;

    case 'cerrar_caso':
        $estadoNuevo = 'cerrado';
        $tipoEvento = 'cierre';
        $descripcionEvento = 'Logística cerró el caso.';
        $camposCaso['fecha_cierre'] = date('Y-m-d H:i:s');
        $camposCaso['observaciones_cierre'] = $observaciones;
        $datosEvento = [
            'accion' => $accion,
            'observaciones' => $observaciones
        ];
        break;
}

/* =========================================================
   TRANSACCION
========================================================= */
$conn->begin_transaction();

try {
    /* -------------------------
       UPDATE garantias_casos
    ------------------------- */
    $camposCaso['estado'] = $estadoNuevo;

    $sets = [];
    $params = [];
    $types = '';

    foreach ($camposCaso as $col => $val) {
        $sets[] = "{$col} = ?";
        $params[] = $val;
        $types .= is_int($val) ? 'i' : (is_float($val) ? 'd' : 's');
    }

    $sets[] = "updated_at = NOW()";

    $sqlUpdate = "UPDATE garantias_casos
                  SET " . implode(", ", $sets) . "
                  WHERE id = ?";

    $params[] = $idGarantia;
    $types .= 'i';

    $st = $conn->prepare($sqlUpdate);
    if (!$st) {
        throw new Exception("Error en prepare() de update caso: " . $conn->error);
    }

    bindParamsDynamic($st, $types, $params);

    if (!$st->execute()) {
        throw new Exception("Error al actualizar caso: " . $st->error);
    }
    $st->close();

    /* -------------------------
       Upsert reparación
    ------------------------- */
    if (in_array($accion, ['enviar_proveedor', 'cotizacion_disponible', 'cotizacion_aceptada', 'cotizacion_rechazada', 'en_reparacion', 'marcar_reparado', 'cerrar_caso'], true)) {
        $repData = [];

        if ($proveedorNombre !== null) {
            $repData['proveedor_nombre'] = $proveedorNombre;
        }
        if ($diagnosticoProveedor !== null) {
            $repData['diagnostico_proveedor'] = $diagnosticoProveedor;
        }

        if ($accion === 'enviar_proveedor') {
            $repData['id_garantia'] = $idGarantia;
            $repData['tipo_servicio'] = 'diagnostico';
            $repData['estado'] = 'enviada_a_diagnostico';
            $repData['fecha_envio'] = date('Y-m-d H:i:s');
            $repData['id_usuario_logistica'] = $ID_USUARIO;
            if ($observaciones !== null) {
                $repData['observaciones_logistica'] = $observaciones;
            }
        }

        if ($accion === 'cotizacion_disponible') {
            $repData['id_garantia'] = $idGarantia;
            $repData['tipo_servicio'] = 'cotizacion';
            $repData['estado'] = 'cotizada';
            $repData['reparable'] = 1;
            $repData['costo_revision'] = $costoRevision;
            $repData['costo_reparacion'] = $costoReparacion;
            $repData['costo_total'] = $costoTotal;
            $repData['tiempo_estimado_dias'] = $tiempoEstimadoDias;
            $repData['fecha_respuesta_proveedor'] = date('Y-m-d H:i:s');
            $repData['id_usuario_logistica'] = $ID_USUARIO;
            if ($observaciones !== null) {
                $repData['observaciones_logistica'] = $observaciones;
            }
        }

        if ($accion === 'cotizacion_aceptada') {
            $repData['id_garantia'] = $idGarantia;
            $repData['cliente_acepta'] = 1;
            $repData['fecha_respuesta_cliente'] = date('Y-m-d H:i:s');
            $repData['estado'] = 'aceptada_por_cliente';
            if ($observaciones !== null) {
                $repData['observaciones_cliente'] = $observaciones;
            }
        }

        if ($accion === 'cotizacion_rechazada') {
            $repData['id_garantia'] = $idGarantia;
            $repData['cliente_acepta'] = 0;
            $repData['fecha_respuesta_cliente'] = date('Y-m-d H:i:s');
            $repData['estado'] = 'rechazada_por_cliente';
            if ($observaciones !== null) {
                $repData['observaciones_cliente'] = $observaciones;
            }
        }

        if ($accion === 'en_reparacion') {
            $repData['id_garantia'] = $idGarantia;
            $repData['tipo_servicio'] = 'reparacion';
            $repData['estado'] = 'en_reparacion';
            $repData['fecha_ingreso_reparacion'] = date('Y-m-d H:i:s');
            $repData['id_usuario_logistica'] = $ID_USUARIO;
            if ($observaciones !== null) {
                $repData['observaciones_logistica'] = $observaciones;
            }
        }

        if ($accion === 'marcar_reparado') {
            $repData['id_garantia'] = $idGarantia;
            $repData['estado'] = 'reparada';
            $repData['fecha_equipo_reparado'] = date('Y-m-d H:i:s');
            $repData['id_usuario_logistica'] = $ID_USUARIO;
            if ($observaciones !== null) {
                $repData['observaciones_logistica'] = $observaciones;
            }
        }

        if ($accion === 'cerrar_caso') {
            if ($estadoAnterior === 'cotizacion_rechazada') {
                $repData['id_garantia'] = $idGarantia;
                $repData['fecha_devolucion'] = date('Y-m-d H:i:s');
                $repData['estado'] = 'devuelta_sin_reparacion';
                if ($observaciones !== null) {
                    $repData['observaciones_logistica'] = $observaciones;
                }
            }
        }

        if (!empty($repData)) {
            upsert_reparacion($conn, $idGarantia, $repData);
        }
    }

    /* -------------------------
       Evento
    ------------------------- */
    registrar_evento(
        $conn,
        $idGarantia,
        $tipoEvento,
        $estadoAnterior,
        $estadoNuevo,
        $descripcionEvento,
        $datosEvento,
        $ID_USUARIO,
        $NOMBRE_USUARIO,
        $ROL
    );

    $conn->commit();

    header("Location: garantias_detalle.php?id={$idGarantia}&oklog=1");
    exit();

} catch (Throwable $e) {
    $conn->rollback();

    http_response_code(500);
    echo "<h3>Error al actualizar el caso</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
    echo "<p><a href='javascript:history.back()'>Volver</a></p>";
    exit();
}