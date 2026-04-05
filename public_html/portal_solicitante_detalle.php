<?php
// portal_solicitante_detalle.php
// LUGA -> vista detalle local (sin consumir API)
// + autorización/rechazo local del solicitante
// + muestra revisión de sistemas, costeo y timeline

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/portal_notificaciones.php')) {
    require_once __DIR__ . '/portal_notificaciones.php';
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function badgeClassEstatus(string $estatus): string {
    $e = strtoupper(trim($estatus));
    return match ($e) {
        'EN_VALORACION_SISTEMAS'        => 'warning',
        'EN_COSTEO'                     => 'info',
        'EN_VALIDACION_COSTO_SISTEMAS'  => 'primary',
        'EN_AUTORIZACION_SOLICITANTE'   => 'warning',
        'PENDIENTE', 'PENDIENTE_INFO'   => 'secondary',
        'AUTORIZADO', 'APROBADO'        => 'success',
        'EN_PROCESO', 'DESARROLLO', 'IMPLEMENTACION', 'EN_EJECUCION' => 'primary',
        'RECHAZADO', 'CANCELADO'        => 'danger',
        'TERMINADO', 'FINALIZADO', 'CERRADO' => 'success',
        default => 'dark',
    };
}

function labelTipo(string $tipo): string {
    $map = [
        'Implementacion' => 'Implementación',
        'Correccion'     => 'Corrección',
        'Mejora'         => 'Mejora',
        'Reporte'        => 'Reporte',
        'Otro'           => 'Otro',
    ];
    return $map[$tipo] ?? $tipo;
}

function valorPrimero(array $row, array $keys, $default = '') {
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') {
            return $row[$k];
        }
    }
    return $default;
}

function fechaBonita($valor): string {
    $valor = trim((string)$valor);
    if ($valor === '') return '—';

    try {
        $dt = new DateTime($valor);
        return $dt->format('d/m/Y h:i A');
    } catch (Throwable $e) {
        return h($valor);
    }
}

function normalizarDecision(string $d): string {
    $d = strtoupper(trim($d));
    return match ($d) {
        'AUTORIZAR', 'AUTORIZADO', 'APROBAR', 'APROBADO', 'ACEPTAR', 'ACEPTADO' => 'AUTORIZAR',
        'RECHAZAR', 'RECHAZADO', 'DECLINAR', 'CANCELAR' => 'RECHAZAR',
        default => '',
    };
}

function flash_set(string $type, string $message): void {
    $_SESSION['portal_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function flash_get(): ?array {
    if (!empty($_SESSION['portal_flash']) && is_array($_SESSION['portal_flash'])) {
        $f = $_SESSION['portal_flash'];
        unset($_SESSION['portal_flash']);
        return $f;
    }
    return null;
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

/* =========================================================
   CONFIG
========================================================= */
$ORIGEN_UI = 'LUGA';
$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);
$ROL_USUARIO = trim((string)($_SESSION['rol'] ?? ''));
$NOMBRE_USUARIO = trim((string)($_SESSION['nombre'] ?? ''));

/* =========================================================
   INPUT
========================================================= */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    require_once __DIR__ . '/navbar.php';
    ?>
    <!doctype html>
    <html lang="es">
    <head>
      <meta charset="utf-8">
      <title>Detalle de solicitud</title>
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body style="background:#f5f7fb;">
      <div class="container py-4">
        <div class="alert alert-danger shadow-sm">ID inválido.</div>
        <a href="portal_solicitante_listado.php" class="btn btn-outline-secondary">Volver al listado</a>
      </div>
    </body>
    </html>
    <?php
    exit();
}

/* =========================================================
   POST: AUTORIZAR / RECHAZAR LOCAL
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = trim((string)($_POST['action'] ?? ''));
    $decision   = normalizarDecision((string)($_POST['decision'] ?? ''));
    $comentario = trim((string)($_POST['comentario'] ?? ''));

    if ($postAction === 'resolver_autorizacion') {
        if ($decision === '') {
            flash_set('danger', 'La decisión enviada no es válida.');
            header("Location: portal_solicitante_detalle.php?id={$id}");
            exit();
        }

        if ($decision === 'RECHAZAR' && mb_strlen($comentario) < 5) {
            flash_set('danger', 'Para rechazar, agrega un comentario de al menos 5 caracteres.');
            header("Location: portal_solicitante_detalle.php?id={$id}");
            exit();
        }

        try {
            $stmtCur = $conn->prepare("
                SELECT s.*, e.clave empresa_clave
                FROM portal_proyectos_solicitudes s
                INNER JOIN portal_empresas e ON e.id = s.empresa_id
                WHERE s.id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $stmtCur->bind_param("i", $id);
            $stmtCur->execute();
            $solActual = $stmtCur->get_result()->fetch_assoc();
            $stmtCur->close();

            if (!$solActual) {
                flash_set('danger', 'La solicitud ya no existe.');
                header("Location: portal_solicitante_listado.php");
                exit();
            }

            if ((string)$solActual['empresa_clave'] !== $ORIGEN_UI) {
                flash_set('danger', 'No tienes permiso para modificar esta solicitud.');
                header("Location: portal_solicitante_listado.php");
                exit();
            }

            $estatusActual = trim((string)($solActual['estatus'] ?? ''));
            if (strtoupper($estatusActual) !== 'EN_AUTORIZACION_SOLICITANTE') {
                flash_set('warning', 'La solicitud ya no está en etapa de autorización del solicitante.');
                header("Location: portal_solicitante_detalle.php?id={$id}");
                exit();
            }

            $nuevoEstatus = ($decision === 'AUTORIZAR') ? 'AUTORIZADO' : 'RECHAZADO';
            $accionHist   = ($decision === 'AUTORIZAR') ? 'COSTO_AUTORIZADO_SOLICITANTE' : 'COSTO_RECHAZADO_SOLICITANTE';

            if ($comentario === '') {
                $comentario = ($decision === 'AUTORIZAR')
                    ? 'Costo autorizado por el solicitante.'
                    : 'Costo rechazado por el solicitante.';
            }

            $actor = $NOMBRE_USUARIO !== ''
                ? $NOMBRE_USUARIO . ($ROL_USUARIO !== '' ? " ({$ROL_USUARIO})" : '') . " - {$ORIGEN_UI}"
                : "{$ORIGEN_UI} SOLICITANTE";

            $conn->begin_transaction();

            $sqlUpdate = "UPDATE portal_proyectos_solicitudes SET estatus = ?";
            $typesUp   = "s";
            $paramsUp  = [$nuevoEstatus];

            if (column_exists($conn, 'portal_proyectos_solicitudes', 'updated_at')) {
                $sqlUpdate .= ", updated_at = NOW()";
            }
            if (column_exists($conn, 'portal_proyectos_solicitudes', 'fecha_actualizacion')) {
                $sqlUpdate .= ", fecha_actualizacion = NOW()";
            }

            $sqlUpdate .= " WHERE id = ?";
            $typesUp   .= "i";
            $paramsUp[] = $id;

            $stmtUp = $conn->prepare($sqlUpdate);
            $stmtUp->bind_param($typesUp, ...$paramsUp);
            $stmtUp->execute();
            $stmtUp->close();

            $stmtH = $conn->prepare("
                INSERT INTO portal_proyectos_historial
                (solicitud_id, usuario_id, actor, accion, estatus_anterior, estatus_nuevo, comentario)
                VALUES (?,?,?,?,?,?,?)
            ");
            $stmtH->bind_param(
                "iisssss",
                $id,
                $ID_USUARIO,
                $actor,
                $accionHist,
                $estatusActual,
                $nuevoEstatus,
                $comentario
            );
            $stmtH->execute();
            $stmtH->close();

            $conn->commit();

            try {
                if (function_exists('portal_notify_cambio_estatus')) {
                    portal_notify_cambio_estatus($conn, $id);
                } elseif (function_exists('portal_notify_nueva_solicitud')) {
                    // opcional: no hacemos nada si no existe la específica
                }
            } catch (Throwable $mailErr) {
                error_log('PORTAL notify cambio estatus ERROR: ' . $mailErr->getMessage());
            }

            if ($decision === 'AUTORIZAR') {
                flash_set('success', 'Solicitud autorizada correctamente.');
            } else {
                flash_set('warning', 'Solicitud rechazada correctamente.');
            }

        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignore) {}
            error_log('PORTAL detalle resolver autorización ERROR: ' . $e->getMessage());
            flash_set('danger', 'No se pudo procesar la decisión: ' . $e->getMessage());
        }

        header("Location: portal_solicitante_detalle.php?id={$id}");
        exit();
    }
}

/* =========================================================
   CARGA LOCAL DE DETALLE
========================================================= */
$error   = '';
$detalle = null;

try {
    $stmt = $conn->prepare("
        SELECT s.*, e.clave empresa_clave, e.nombre empresa_nombre
        FROM portal_proyectos_solicitudes s
        INNER JOIN portal_empresas e ON e.id = s.empresa_id
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $detalle = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$detalle) {
        $error = 'No se encontró la solicitud.';
    } elseif ((string)$detalle['empresa_clave'] !== $ORIGEN_UI) {
        $error = 'La solicitud no pertenece a esta central.';
    }
} catch (Throwable $e) {
    $error = 'No se pudo cargar el detalle: ' . $e->getMessage();
}

require_once __DIR__ . '/navbar.php';

/* =========================================================
   CARGA COMPLEMENTARIA LOCAL
========================================================= */
$revisionNode = [];
$costeoNode   = [];
$timeline     = [];

if ($error === '' && $detalle) {
    try {
        if (table_exists($conn, 'portal_proyectos_revision_sistemas')) {
            $stmtR = $conn->prepare("
                SELECT *
                FROM portal_proyectos_revision_sistemas
                WHERE solicitud_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtR->bind_param("i", $id);
            $stmtR->execute();
            $revisionNode = $stmtR->get_result()->fetch_assoc() ?: [];
            $stmtR->close();
        }

        if (table_exists($conn, 'portal_proyectos_costeo')) {
            $stmtC = $conn->prepare("
                SELECT *
                FROM portal_proyectos_costeo
                WHERE solicitud_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmtC->bind_param("i", $id);
            $stmtC->execute();
            $costeoNode = $stmtC->get_result()->fetch_assoc() ?: [];
            $stmtC->close();
        }

        if (table_exists($conn, 'portal_proyectos_historial')) {
            $stmtT = $conn->prepare("
                SELECT h.*, u.nombre AS usuario_nombre
                FROM portal_proyectos_historial h
                LEFT JOIN usuarios u ON u.id = h.usuario_id
                WHERE h.solicitud_id = ?
                ORDER BY h.id DESC
                LIMIT 100
            ");
            $stmtT->bind_param("i", $id);
            $stmtT->execute();
            $timeline = $stmtT->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtT->close();
        }
    } catch (Throwable $e) {
        error_log('PORTAL detalle carga complementaria ERROR: ' . $e->getMessage());
    }
}

/* =========================================================
   NORMALIZACIÓN
========================================================= */
$flash       = flash_get();
$folio       = $detalle ? valorPrimero($detalle, ['folio'], '—') : '—';
$titulo      = $detalle ? valorPrimero($detalle, ['titulo', 'nombre'], '—') : '—';
$descripcion = $detalle ? valorPrimero($detalle, ['descripcion', 'detalle', 'resumen'], '—') : '—';
$tipo        = $detalle ? valorPrimero($detalle, ['tipo'], '—') : '—';
$prioridad   = $detalle ? valorPrimero($detalle, ['prioridad'], '—') : '—';
$estatus     = $detalle ? valorPrimero($detalle, ['estatus', 'status'], '—') : '—';
$origen      = $detalle ? valorPrimero($detalle, ['empresa_clave', 'origen', 'central_origen'], $ORIGEN_UI) : $ORIGEN_UI;
$creadoPor   = $detalle ? valorPrimero($detalle, ['solicitante_nombre', 'creado_por_nombre', 'usuario_nombre', 'created_by_name'], '—') : '—';
$correo      = $detalle ? valorPrimero($detalle, ['solicitante_correo', 'solicitante_email', 'correo', 'email'], '—') : '—';
$fechaAlta   = $detalle ? valorPrimero($detalle, ['created_at', 'fecha_creacion', 'fecha_alta', 'creado_en'], '') : '';
$actualizado = $detalle ? valorPrimero($detalle, ['updated_at', 'fecha_actualizacion', 'actualizado_en'], '') : '';
$costoMxn    = $detalle ? valorPrimero($detalle, ['costo_mxn', 'costo', 'monto_costo'], null) : null;

/* -------- revision -------- */
$revVersion    = $revisionNode ? valorPrimero($revisionNode, ['version'], null) : null;
$revHorasMin   = $revisionNode ? valorPrimero($revisionNode, ['horas_min'], null) : null;
$revHorasMax   = $revisionNode ? valorPrimero($revisionNode, ['horas_max'], null) : null;
$revPlan       = $revisionNode ? valorPrimero($revisionNode, ['plan_acciones', 'plan'], '') : '';
$revRiesgos    = $revisionNode ? valorPrimero($revisionNode, ['riesgos_dependencias', 'riesgos'], '') : '';
$revUsuario    = $revisionNode ? valorPrimero($revisionNode, ['usuario_nombre', 'usuario', 'revisor_nombre'], '') : '';
$revFecha      = $revisionNode ? valorPrimero($revisionNode, ['created_at', 'fecha'], '') : '';
$tieneRevision = !empty($revisionNode);

/* -------- costeo -------- */
$costVersion   = $costeoNode ? valorPrimero($costeoNode, ['version'], null) : null;
$costUsuario   = $costeoNode ? valorPrimero($costeoNode, ['usuario_nombre', 'usuario', 'costeador_nombre'], '') : '';
$costFecha     = $costeoNode ? valorPrimero($costeoNode, ['created_at', 'fecha'], '') : '';
$desglose      = $costeoNode ? valorPrimero($costeoNode, ['desglose', 'costeo_desglose'], '') : '';
$condiciones   = $costeoNode ? valorPrimero($costeoNode, ['condiciones', 'costeo_condiciones'], '') : '';
if (($costoMxn === null || $costoMxn === '') && $costeoNode) {
    $costoMxn = valorPrimero($costeoNode, ['costo_mxn', 'costo', 'monto_costo'], null);
}
$tieneCosteo   = !empty($costeoNode);

$estatusUpper = strtoupper(trim((string)$estatus));
$requiereAutorizacion = ($estatusUpper === 'EN_AUTORIZACION_SOLICITANTE');
$yaAutorizado = in_array($estatusUpper, ['AUTORIZADO', 'APROBADO'], true);
$yaRechazado  = in_array($estatusUpper, ['RECHAZADO', 'CANCELADO'], true);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Detalle de solicitud • <?= h($folio !== '—' ? $folio : ('#' . $id)) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --radius:18px; }
    body{ background:#f5f7fb; }
    .card{ border-radius: var(--radius); border:1px solid rgba(0,0,0,.06); }
    .soft-shadow{ box-shadow: 0 10px 30px rgba(20,20,40,.06); }
    .small-muted{ font-size:12px; color:#6c757d; }
    .pill{
      border:1px solid rgba(0,0,0,.08);
      border-radius:999px;
      padding:.35rem .75rem;
      font-size:12px;
      background:#fff;
      display:inline-flex;
      align-items:center;
      gap:.35rem;
    }
    .mono{
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }
    .prelike{
      white-space: pre-wrap;
      line-height: 1.55;
    }
    .meta-label{
      font-size:12px;
      color:#6c757d;
      margin-bottom:.2rem;
    }
    .meta-value{
      font-weight:600;
      color:#212529;
    }
    .money-big{
      font-size:2rem;
      font-weight:800;
      line-height:1;
    }
    .section-title{
      font-size:1rem;
      font-weight:700;
      margin-bottom:.25rem;
    }
    .section-subtitle{
      font-size:12px;
      color:#6c757d;
    }
  </style>
</head>
<body>

<div class="container py-4">

  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <div class="small-muted d-flex align-items-center gap-2 flex-wrap">
        <span class="pill"><span class="mono">Central</span> <b><?= h($ORIGEN_UI) ?></b></span>
        <span>•</span>
        <a href="portal_solicitante_listado.php" class="text-decoration-none">← Volver</a>
      </div>
      <h3 class="m-0 mt-2">Detalle de solicitud</h3>
      <div class="small-muted">Vista local de la solicitud en LUGA.</div>
    </div>

    <div class="text-end">
      <div class="small-muted">Solicitud</div>
      <div class="fs-5 fw-bold"><?= h($folio !== '—' ? $folio : ('#' . $id)) ?></div>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type'] ?? 'info') ?> soft-shadow">
      <?= h($flash['message'] ?? '') ?>
    </div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="alert alert-danger soft-shadow">
      <div class="fw-semibold mb-1">No se pudo cargar el detalle</div>
      <div><?= h($error) ?></div>
    </div>
  <?php else: ?>

    <?php if ($requiereAutorizacion): ?>
      <div class="card soft-shadow mb-3 border-warning">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
              <div class="fw-semibold fs-5">Autorización requerida</div>
              <div class="small-muted">
                Sistemas ya validó el costo. Falta tu respuesta para continuar el flujo.
              </div>
            </div>

            <span class="badge text-bg-warning px-3 py-2">Pendiente de tu aprobación</span>
          </div>

          <div class="row g-3 mt-1">
            <div class="col-12 col-lg-4">
              <div class="card bg-light border-0">
                <div class="card-body">
                  <div class="small-muted">Costo propuesto</div>
                  <div class="money-big">
                    <?= ($costoMxn !== null && $costoMxn !== '') ? '$' . number_format((float)$costoMxn, 2) . ' MXN' : '—' ?>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-12 col-lg-8">
              <?php if ($desglose !== ''): ?>
                <div class="mb-3">
                  <div class="meta-label">Desglose</div>
                  <div class="prelike"><?= h((string)$desglose) ?></div>
                </div>
              <?php endif; ?>

              <?php if ($condiciones !== ''): ?>
                <div class="mb-3">
                  <div class="meta-label">Condiciones</div>
                  <div class="prelike"><?= h((string)$condiciones) ?></div>
                </div>
              <?php endif; ?>

              <form method="post" class="mt-3">
                <input type="hidden" name="action" value="resolver_autorizacion">

                <div class="mb-3">
                  <label class="form-label">Comentario</label>
                  <textarea
                    name="comentario"
                    class="form-control"
                    rows="3"
                    placeholder="Opcional al autorizar. Recomendado al rechazar para indicar motivo."></textarea>
                </div>

                <div class="d-flex flex-wrap gap-2 justify-content-end">
                  <button
                    type="submit"
                    name="decision"
                    value="RECHAZAR"
                    class="btn btn-outline-danger"
                    onclick="return confirm('¿Seguro que deseas rechazar este costo?');">
                    Rechazar
                  </button>

                  <button
                    type="submit"
                    name="decision"
                    value="AUTORIZAR"
                    class="btn btn-success"
                    onclick="return confirm('¿Confirmas que deseas autorizar este costo?');">
                    Autorizar costo
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php elseif ($yaAutorizado): ?>
      <div class="alert alert-success soft-shadow mb-3">
        <div class="fw-semibold">Esta solicitud ya fue autorizada.</div>
        <div class="small-muted">El flujo ya puede continuar del lado de Sistemas.</div>
      </div>
    <?php elseif ($yaRechazado): ?>
      <div class="alert alert-danger soft-shadow mb-3">
        <div class="fw-semibold">Esta solicitud fue rechazada.</div>
        <div class="small-muted">Si hace falta, Sistemas podrá revisarla nuevamente.</div>
      </div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-12 col-lg-8">
        <div class="card soft-shadow">
          <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <div class="small-muted">Título</div>
                <div class="fs-3 fw-semibold"><?= h($titulo) ?></div>
              </div>

              <div class="d-flex gap-2 flex-wrap">
                <span class="badge text-bg-<?= h(badgeClassEstatus((string)$estatus)) ?> px-3 py-2">
                  <?= h((string)$estatus) ?>
                </span>
              </div>
            </div>

            <div class="mt-3 d-flex gap-2 flex-wrap">
              <span class="pill">Tipo: <b><?= h(labelTipo((string)$tipo)) ?></b></span>
              <span class="pill">Prioridad: <b><?= h((string)$prioridad) ?></b></span>
              <span class="pill">Central: <b><?= h((string)$origen) ?></b></span>
              <span class="pill">ID: <b><?= (int)$id ?></b></span>
            </div>

            <hr class="my-4">

            <div class="meta-label">Descripción</div>
            <div class="prelike fs-6"><?= h($descripcion) ?></div>
          </div>
        </div>

        <?php if ($tieneRevision): ?>
          <div class="card soft-shadow mt-3">
            <div class="card-body p-4">
              <div class="section-title">Revisión de Sistemas</div>
              <div class="section-subtitle mb-3">
                <?php if ($revVersion !== null && $revVersion !== ''): ?>
                  Versión <b>#<?= h((string)$revVersion) ?></b>
                <?php else: ?>
                  Última revisión registrada
                <?php endif; ?>
                <?php if ($revUsuario !== ''): ?>
                  • Por: <b><?= h($revUsuario) ?></b>
                <?php endif; ?>
                <?php if ($revFecha !== ''): ?>
                  • <?= h(fechaBonita($revFecha)) ?>
                <?php endif; ?>
              </div>

              <div class="row g-3">
                <div class="col-6 col-md-3">
                  <div class="meta-label">Horas mínimas</div>
                  <div class="meta-value"><?= ($revHorasMin !== null && $revHorasMin !== '') ? h((string)$revHorasMin) : '—' ?></div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="meta-label">Horas máximas</div>
                  <div class="meta-value"><?= ($revHorasMax !== null && $revHorasMax !== '') ? h((string)$revHorasMax) : '—' ?></div>
                </div>
              </div>

              <?php if ($revPlan !== ''): ?>
                <div class="mt-3">
                  <div class="meta-label">Plan de acciones</div>
                  <div class="prelike"><?= h($revPlan) ?></div>
                </div>
              <?php endif; ?>

              <?php if ($revRiesgos !== ''): ?>
                <div class="mt-3">
                  <div class="meta-label">Riesgos / dependencias</div>
                  <div class="prelike"><?= h($revRiesgos) ?></div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($tieneCosteo): ?>
          <div class="card soft-shadow mt-3">
            <div class="card-body p-4">
              <div class="section-title">Costeo</div>
              <div class="section-subtitle mb-3">
                <?php if ($costVersion !== null && $costVersion !== ''): ?>
                  Versión <b>#<?= h((string)$costVersion) ?></b>
                <?php else: ?>
                  Último costeo registrado
                <?php endif; ?>
                <?php if ($costUsuario !== ''): ?>
                  • Por: <b><?= h($costUsuario) ?></b>
                <?php endif; ?>
                <?php if ($costFecha !== ''): ?>
                  • <?= h(fechaBonita($costFecha)) ?>
                <?php endif; ?>
              </div>

              <div class="mb-3">
                <div class="small-muted">Costo propuesto</div>
                <div class="money-big">
                  <?= ($costoMxn !== null && $costoMxn !== '') ? '$' . number_format((float)$costoMxn, 2) . ' MXN' : '—' ?>
                </div>
              </div>

              <?php if ($desglose !== ''): ?>
                <div class="mt-3">
                  <div class="meta-label">Desglose</div>
                  <div class="prelike"><?= h($desglose) ?></div>
                </div>
              <?php endif; ?>

              <?php if ($condiciones !== ''): ?>
                <div class="mt-3">
                  <div class="meta-label">Condiciones</div>
                  <div class="prelike"><?= h($condiciones) ?></div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

      </div>

      <div class="col-12 col-lg-4">
        <div class="card soft-shadow h-100">
          <div class="card-body p-4">
            <div class="fw-semibold mb-3">Resumen</div>

            <div class="mb-3">
              <div class="meta-label">Folio</div>
              <div class="meta-value"><?= h($folio !== '—' ? $folio : ('#' . $id)) ?></div>
            </div>

            <div class="mb-3">
              <div class="meta-label">Estatus</div>
              <div class="meta-value"><?= h((string)$estatus) ?></div>
            </div>

            <div class="mb-3">
              <div class="meta-label">Solicitante</div>
              <div class="meta-value"><?= h((string)$creadoPor) ?></div>
            </div>

            <div class="mb-3">
              <div class="meta-label">Correo</div>
              <div class="meta-value"><?= h((string)$correo) ?></div>
            </div>

            <div class="mb-3">
              <div class="meta-label">Costo</div>
              <div class="meta-value">
                <?= ($costoMxn !== null && $costoMxn !== '') ? '$' . number_format((float)$costoMxn, 2) . ' MXN' : '—' ?>
              </div>
            </div>

            <div class="mb-3">
              <div class="meta-label">Fecha de alta</div>
              <div class="meta-value"><?= h(fechaBonita($fechaAlta)) ?></div>
            </div>

            <div class="mb-0">
              <div class="meta-label">Última actualización</div>
              <div class="meta-value"><?= h(fechaBonita($actualizado)) ?></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <?php if (!empty($timeline)): ?>
      <div class="card soft-shadow mt-3">
        <div class="card-body p-4">
          <div class="fw-semibold mb-3">Timeline</div>

          <div class="list-group list-group-flush">
            <?php foreach ($timeline as $ev): ?>
              <?php
                $evTitulo = is_array($ev) ? valorPrimero($ev, ['titulo', 'accion', 'evento', 'nombre'], 'Movimiento') : 'Movimiento';
                $evDesc   = is_array($ev) ? valorPrimero($ev, ['descripcion', 'detalle', 'comentario'], '') : '';
                $evFecha  = is_array($ev) ? valorPrimero($ev, ['fecha', 'created_at', 'fecha_evento'], '') : '';
                $evUser   = is_array($ev) ? valorPrimero($ev, ['usuario_nombre', 'usuario', 'autor', 'actor', 'created_by_name'], '') : '';
              ?>
              <div class="list-group-item px-0 py-3">
                <div class="d-flex justify-content-between gap-2 flex-wrap">
                  <div class="fw-semibold"><?= h((string)$evTitulo) ?></div>
                  <div class="small-muted"><?= h(fechaBonita($evFecha)) ?></div>
                </div>

                <?php if ($evDesc !== ''): ?>
                  <div class="mt-1 prelike"><?= h((string)$evDesc) ?></div>
                <?php endif; ?>

                <?php if ($evUser !== ''): ?>
                  <div class="small-muted mt-1">Por: <?= h((string)$evUser) ?></div>
                <?php endif; ?>

                <?php
                  $stPrev = valorPrimero($ev, ['estatus_anterior'], '');
                  $stNext = valorPrimero($ev, ['estatus_nuevo'], '');
                ?>
                <?php if ($stPrev !== '' || $stNext !== ''): ?>
                  <div class="small-muted mt-1">
                    Estatus:
                    <?= h($stPrev !== '' ? $stPrev : '—') ?>
                    →
                    <?= h($stNext !== '' ? $stNext : '—') ?>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</div>

</body>
</html>