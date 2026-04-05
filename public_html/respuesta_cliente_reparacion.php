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
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);
$NOMBRE_USUARIO = (string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario');

$ROLES_PERMITIDOS = [
    'Admin', 'Administrador', 'Logistica',
    'Gerente', 'Ejecutivo',
    'Subdis_Admin', 'Subdis_Gerente', 'Subdis_Ejecutivo'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para registrar respuesta del cliente.');
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

function fmt_datetime(?string $dt): string {
    if (!$dt || $dt === '0000-00-00' || $dt === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i', $ts) : '-';
}

function to_datetime_local(?string $dt): string {
    if (!$dt || $dt === '0000-00-00' || $dt === '0000-00-00 00:00:00') {
        return date('Y-m-d\TH:i');
    }
    $ts = strtotime($dt);
    return $ts ? date('Y-m-d\TH:i', $ts) : date('Y-m-d\TH:i');
}

function to_mysql_datetime(?string $dt): ?string {
    $dt = trim((string)$dt);
    if ($dt === '') return null;

    $dt = str_replace('T', ' ', $dt);

    // Si viene sin segundos, se agregan
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $dt)) {
        $dt .= ':00';
    }

    $ts = strtotime($dt);
    if ($ts === false) return null;

    return date('Y-m-d H:i:s', $ts);
}

function badge_estado(string $estado): string {
    $map = [
        'capturada'              => 'secondary',
        'recepcion_registrada'   => 'info',
        'en_revision_logistica'  => 'warning text-dark',
        'garantia_autorizada'    => 'success',
        'garantia_rechazada'     => 'danger',
        'enviada_diagnostico'    => 'primary',
        'cotizacion_disponible'  => 'info',
        'cotizacion_aceptada'    => 'success',
        'cotizacion_rechazada'   => 'danger',
        'en_reparacion'          => 'warning text-dark',
        'reparado'               => 'success',
        'reemplazo_capturado'    => 'primary',
        'entregado'              => 'success',
        'cerrado'                => 'dark',
        'cancelado'              => 'dark',
    ];
    $cls = $map[$estado] ?? 'secondary';
    return '<span class="badge rounded-pill text-bg-' . $cls . '">' . h($estado) . '</span>';
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

/* =========================================================
   VALIDAR TABLAS
========================================================= */
$required = ['garantias_casos', 'garantias_eventos', 'garantias_reparaciones'];
foreach ($required as $tb) {
    if (!table_exists($conn, $tb)) {
        exit("No existe la tabla requerida: " . h($tb));
    }
}

/* =========================================================
   ID DEL CASO
========================================================= */
$idGarantia = (int)($_GET['id'] ?? $_POST['id_garantia'] ?? 0);
if ($idGarantia <= 0) {
    exit('ID de garantía inválido.');
}

/* =========================================================
   CARGAR CASO
========================================================= */
$sqlCaso = "SELECT
                gc.*,
                s.nombre AS sucursal_nombre,
                u.nombre AS capturista_nombre
            FROM garantias_casos gc
            LEFT JOIN sucursales s ON s.id = gc.id_sucursal
            LEFT JOIN usuarios u ON u.id = gc.id_usuario_captura
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

/* =========================================================
   VALIDAR ESTADO
========================================================= */
$estadosPermitidos = ['cotizacion_disponible', 'cotizacion_aceptada', 'cotizacion_rechazada'];
if (!in_array((string)$caso['estado'], $estadosPermitidos, true)) {
    exit('Este caso no está listo para registrar respuesta del cliente. Estado actual: ' . h($caso['estado']));
}

/* =========================================================
   CARGAR REPARACION
========================================================= */
$sqlRep = "SELECT *
           FROM garantias_reparaciones
           WHERE id_garantia = ?
           ORDER BY id DESC
           LIMIT 1";
$st = $conn->prepare($sqlRep);
if (!$st) {
    exit("Error consultando reparación: " . h($conn->error));
}
$st->bind_param("i", $idGarantia);
$st->execute();
$reparacion = $st->get_result()->fetch_assoc();
$st->close();

if (!$reparacion) {
    exit('Este caso no cuenta con una cotización registrada.');
}

/* =========================================================
   VALORES INICIALES FORM
========================================================= */
$valorDecision = '';
if ((string)$caso['estado'] === 'cotizacion_aceptada') {
    $valorDecision = 'acepta';
} elseif ((string)$caso['estado'] === 'cotizacion_rechazada') {
    $valorDecision = 'rechaza';
} elseif (isset($reparacion['cliente_acepta']) && $reparacion['cliente_acepta'] !== null) {
    $valorDecision = ((int)$reparacion['cliente_acepta'] === 1) ? 'acepta' : 'rechaza';
}

if (!empty($reparacion['fecha_respuesta_cliente']) && $reparacion['fecha_respuesta_cliente'] !== '0000-00-00 00:00:00') {
    $valorFechaRespuesta = to_datetime_local($reparacion['fecha_respuesta_cliente']);
} elseif (!empty($caso['fecha_autorizacion_cliente']) && $caso['fecha_autorizacion_cliente'] !== '0000-00-00 00:00:00') {
    $valorFechaRespuesta = to_datetime_local($caso['fecha_autorizacion_cliente']);
} else {
    $valorFechaRespuesta = date('Y-m-d\TH:i');
}

$valorNombreAutoriza = $caso['cliente_nombre'] ?? '';
$valorComentarios = $reparacion['observaciones_cliente'] ?? '';

/* =========================================================
   GUARDAR RESPUESTA
========================================================= */
$error = null;
$ok = isset($_GET['ok']) ? (int)$_GET['ok'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_respuesta'])) {
    $decision = (string)($_POST['decision_cliente'] ?? '');
    $fechaRespuestaInput = null_if_empty($_POST['fecha_respuesta'] ?? date('Y-m-d\TH:i'));
    $fechaRespuesta = to_mysql_datetime($fechaRespuestaInput);
    $nombreAutoriza = null_if_empty($_POST['nombre_autoriza'] ?? null);
    $comentarios = null_if_empty($_POST['comentarios_cliente'] ?? null);

    $valorDecision = $decision;
    $valorFechaRespuesta = $fechaRespuestaInput ?: date('Y-m-d\TH:i');
    $valorNombreAutoriza = (string)$nombreAutoriza;
    $valorComentarios = (string)$comentarios;

    if (!in_array($decision, ['acepta', 'rechaza'], true)) {
        $error = 'Debes seleccionar si el cliente acepta o rechaza la cotización.';
    }

    if (!$fechaRespuesta) {
        $error = 'Debes capturar una fecha y hora válidas.';
    }

    if (!$error) {
        $conn->begin_transaction();

        try {
            $estadoAnterior = (string)$caso['estado'];
            $estadoNuevo = $decision === 'acepta' ? 'cotizacion_aceptada' : 'cotizacion_rechazada';
            $estadoRep = $decision === 'acepta' ? 'aceptada_por_cliente' : 'rechazada_por_cliente';

            $comentarioFinal = $comentarios;
            if ($nombreAutoriza) {
                $comentarioFinal = "Autoriza: {$nombreAutoriza}" . ($comentarios ? "\n\n" . $comentarios : '');
            }

            /* -------------------------
               actualizar caso
            ------------------------- */
            $sqlUpCaso = "UPDATE garantias_casos
                          SET estado = ?,
                              cliente_acepta_cotizacion = ?,
                              fecha_autorizacion_cliente = ?,
                              observaciones_logistica = ?,
                              updated_at = NOW()
                          WHERE id = ?";
            $st = $conn->prepare($sqlUpCaso);
            if (!$st) {
                throw new Exception("Error en update del caso: " . $conn->error);
            }

            $acepta = $decision === 'acepta' ? 1 : 0;
            $st->bind_param("sissi", $estadoNuevo, $acepta, $fechaRespuesta, $comentarioFinal, $idGarantia);

            if (!$st->execute()) {
                throw new Exception("Error al actualizar el caso: " . $st->error);
            }
            $st->close();

            /* -------------------------
               actualizar reparación
            ------------------------- */
            $aceptaRep = $decision === 'acepta' ? 1 : 0;
            $idRep = (int)$reparacion['id'];

            if ($decision === 'rechaza') {
                $sqlUpRep = "UPDATE garantias_reparaciones
                             SET cliente_acepta = ?,
                                 fecha_respuesta_cliente = ?,
                                 fecha_devolucion = ?,
                                 estado = ?,
                                 observaciones_cliente = ?,
                                 updated_at = NOW()
                             WHERE id = ?";
                $st = $conn->prepare($sqlUpRep);
                if (!$st) {
                    throw new Exception("Error en update de reparación: " . $conn->error);
                }

                $st->bind_param("issssi", $aceptaRep, $fechaRespuesta, $fechaRespuesta, $estadoRep, $comentarioFinal, $idRep);
            } else {
                $sqlUpRep = "UPDATE garantias_reparaciones
                             SET cliente_acepta = ?,
                                 fecha_respuesta_cliente = ?,
                                 estado = ?,
                                 observaciones_cliente = ?,
                                 updated_at = NOW()
                             WHERE id = ?";
                $st = $conn->prepare($sqlUpRep);
                if (!$st) {
                    throw new Exception("Error en update de reparación: " . $conn->error);
                }

                $st->bind_param("isssi", $aceptaRep, $fechaRespuesta, $estadoRep, $comentarioFinal, $idRep);
            }

            if (!$st->execute()) {
                throw new Exception("Error al actualizar la reparación: " . $st->error);
            }
            $st->close();

            /* -------------------------
               evento
            ------------------------- */
            $tipoEvento = $decision === 'acepta' ? 'cotizacion_aceptada' : 'cotizacion_rechazada';
            $descripcion = $decision === 'acepta'
                ? 'Se registró que el cliente aceptó la cotización de reparación.'
                : 'Se registró que el cliente rechazó la cotización de reparación.';

            registrar_evento(
                $conn,
                $idGarantia,
                $tipoEvento,
                $estadoAnterior,
                $estadoNuevo,
                $descripcion,
                [
                    'decision_cliente' => $decision,
                    'fecha_respuesta' => $fechaRespuesta,
                    'nombre_autoriza' => $nombreAutoriza,
                    'comentarios_cliente' => $comentarios,
                    'comentario_final' => $comentarioFinal,
                    'costo_total' => $reparacion['costo_total'] ?? null
                ],
                $ID_USUARIO,
                $NOMBRE_USUARIO,
                $ROL
            );

            $conn->commit();
            header("Location: garantias_detalle.php?id={$idGarantia}&oklog=1");
            exit();

        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

/* =========================================================
   NAVBAR YA DESPUÉS DEL POST
========================================================= */
require_once __DIR__ . '/navbar.php';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Respuesta del cliente | <?= h($caso['folio']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body{
            background: linear-gradient(180deg, #f8fbff 0%, #f3f6fb 100%);
        }
        .page-wrap{
            max-width: 1200px;
            margin: 24px auto 40px;
            padding: 0 14px;
        }
        .hero{
            border-radius:22px;
            border:1px solid #e8edf3;
            background: linear-gradient(135deg, rgba(13,110,253,.08), rgba(25,135,84,.08));
            box-shadow:0 10px 28px rgba(17,24,39,.06);
        }
        .soft-card{
            border:1px solid #e8edf3;
            border-radius:20px;
            box-shadow:0 8px 24px rgba(17,24,39,.05);
            background:#fff;
        }
        .section-title{
            font-size:1rem;
            font-weight:700;
            margin-bottom:.9rem;
            display:flex;
            align-items:center;
            gap:.55rem;
        }
        .section-title i{
            color:#0d6efd;
        }
        .kv-label{
            font-size:.82rem;
            color:#6b7280;
            font-weight:600;
            margin-bottom:.2rem;
        }
        .kv-value{
            font-size:.95rem;
            font-weight:500;
            word-break:break-word;
        }
    </style>
</head>
<body>

<div class="page-wrap">

    <?php if ($error): ?>
        <div class="alert alert-danger border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle me-1"></i><?= h($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($ok === 1): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <i class="bi bi-check-circle me-1"></i> Respuesta registrada correctamente.
        </div>
    <?php endif; ?>

    <div class="hero p-4 p-md-5 mb-4">
        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
            <div>
                <h3 class="fw-bold mb-1">
                    <i class="bi bi-chat-dots me-2"></i>Registrar respuesta del cliente
                </h3>
                <div class="text-muted">
                    Folio <strong><?= h($caso['folio']) ?></strong> • Cliente <strong><?= h($caso['cliente_nombre']) ?></strong>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <?= badge_estado((string)$caso['estado']) ?>
                <a href="garantias_detalle.php?id=<?= (int)$caso['id'] ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Volver al detalle
                </a>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <div class="col-lg-6">
            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-person-vcard"></i>
                    <span>Resumen del caso</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="kv-label">Cliente</div>
                        <div class="kv-value"><?= h($caso['cliente_nombre']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="kv-label">Teléfono</div>
                        <div class="kv-value"><?= h($caso['cliente_telefono']) ?></div>
                    </div>

                    <div class="col-md-6">
                        <div class="kv-label">Sucursal</div>
                        <div class="kv-value"><?= h($caso['sucursal_nombre']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="kv-label">Estado actual</div>
                        <div class="kv-value"><?= badge_estado((string)$caso['estado']) ?></div>
                    </div>

                    <div class="col-md-6">
                        <div class="kv-label">Equipo</div>
                        <div class="kv-value">
                            <?= h(trim(($caso['marca'] ?? '') . ' ' . ($caso['modelo'] ?? ''))) ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="kv-label">IMEI</div>
                        <div class="kv-value"><?= h($caso['imei_original']) ?></div>
                    </div>
                </div>
            </div>

            <div class="soft-card p-4">
                <div class="section-title">
                    <i class="bi bi-tools"></i>
                    <span>Cotización registrada</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="kv-label">Proveedor</div>
                        <div class="kv-value"><?= h($reparacion['proveedor_nombre']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="kv-label">Estado reparación</div>
                        <div class="kv-value"><?= h($reparacion['estado']) ?></div>
                    </div>

                    <div class="col-md-4">
                        <div class="kv-label">Costo revisión</div>
                        <div class="kv-value">$<?= number_format((float)$reparacion['costo_revision'], 2) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="kv-label">Costo reparación</div>
                        <div class="kv-value">$<?= number_format((float)$reparacion['costo_reparacion'], 2) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="kv-label">Costo total</div>
                        <div class="kv-value fw-bold">$<?= number_format((float)$reparacion['costo_total'], 2) ?></div>
                    </div>

                    <div class="col-md-6">
                        <div class="kv-label">Tiempo estimado</div>
                        <div class="kv-value"><?= h($reparacion['tiempo_estimado_dias']) ?> día(s)</div>
                    </div>
                    <div class="col-md-6">
                        <div class="kv-label">Respuesta previa</div>
                        <div class="kv-value"><?= h(fmt_datetime($reparacion['fecha_respuesta_cliente'])) ?></div>
                    </div>

                    <div class="col-12">
                        <div class="kv-label">Diagnóstico del proveedor</div>
                        <div class="kv-value"><?= nl2br(h($reparacion['diagnostico_proveedor'])) ?></div>
                    </div>

                    <div class="col-12">
                        <div class="kv-label">Observaciones previas del cliente</div>
                        <div class="kv-value"><?= !empty($reparacion['observaciones_cliente']) ? nl2br(h($reparacion['observaciones_cliente'])) : '-' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="soft-card p-4">
                <div class="section-title">
                    <i class="bi bi-check2-square"></i>
                    <span>Decisión del cliente</span>
                </div>

                <form method="post">
                    <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                    <input type="hidden" name="guardar_respuesta" value="1">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Respuesta del cliente</label>
                        <select name="decision_cliente" class="form-select" required>
                            <option value="">Selecciona una opción</option>
                            <option value="acepta" <?= $valorDecision === 'acepta' ? 'selected' : '' ?>>Acepta la reparación</option>
                            <option value="rechaza" <?= $valorDecision === 'rechaza' ? 'selected' : '' ?>>Rechaza la reparación</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Fecha y hora de respuesta</label>
                        <input
                            type="datetime-local"
                            name="fecha_respuesta"
                            class="form-control"
                            value="<?= h($valorFechaRespuesta) ?>"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nombre de quien autoriza</label>
                        <input
                            type="text"
                            name="nombre_autoriza"
                            class="form-control"
                            value="<?= h($valorNombreAutoriza) ?>"
                            placeholder="Cliente o persona autorizada"
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Comentarios</label>
                        <textarea
                            name="comentarios_cliente"
                            class="form-control"
                            rows="4"
                            placeholder="Ejemplo: cliente informado por llamada, acepta el costo, solicita tiempo estimado, etc."
                        ><?= h($valorComentarios) ?></textarea>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="bi bi-save2 me-1"></i>Guardar respuesta
                        </button>

                        <a href="garantias_detalle.php?id=<?= (int)$caso['id'] ?>" class="btn btn-outline-secondary">
                            Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

</body>
</html>