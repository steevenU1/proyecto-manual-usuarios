<?php
// portal_proyectos_detalle.php — Portal Intercentrales (LUGA)
// Detalle + acciones por rol/estatus + bitácora + feedback visual de autorización API

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

/**
 * IMPORTANTE:
 * NO cargar navbar.php aquí arriba porque imprime HTML y rompe los header().
 * Se cargará dentro del <body>.
 */

// Buffer por seguridad (evita headers already sent si algún include mete espacios)
ob_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/portal_notificaciones.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);
$ROL        = (string)($_SESSION['rol'] ?? '');

// Sistemas (solo Erick)
$SISTEMAS_IDS = [6];
$isSistemas   = in_array($ID_USUARIO, $SISTEMAS_IDS, true);

// Costos
$COSTOS_IDS = [66, 8, 7];
$isCostos   = in_array($ID_USUARIO, $COSTOS_IDS, true);

// Debug seguro: solo Sistemas y con ?debug=1
$DEBUG = ($isSistemas && isset($_GET['debug']) && $_GET['debug'] == '1');
if ($DEBUG) {
  ini_set('display_errors','1');
  ini_set('display_startup_errors','1');
  error_reporting(E_ALL);
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function back($id){ header("Location: portal_proyectos_detalle.php?id=".$id); exit; }

function column_exists(mysqli $conn, string $table, string $col): bool {
  $sql = "SELECT 1
          FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND COLUMN_NAME = ?
          LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param("ss", $table, $col);
  $st->execute();
  $ok = (bool)$st->get_result()->fetch_row();
  $st->close();
  return $ok;
}
function pick_order_col(mysqli $conn, string $table, array $candidates, string $fallback='id'): string {
  foreach ($candidates as $c) {
    if (column_exists($conn, $table, $c)) return $c;
  }
  return $fallback;
}

$ESTATUS = [
  'EN_VALORACION_SISTEMAS'        => 'En valoración por Sistemas',
  'EN_COSTEO'                     => 'En costeo (Costos)',
  'EN_VALIDACION_COSTO_SISTEMAS'  => 'Validación de costo (Sistemas)',
  'EN_AUTORIZACION_SOLICITANTE'   => 'Autorización del solicitante',
  'AUTORIZADO'                    => 'Autorizado',
  'EN_EJECUCION'                  => 'En ejecución',
  'FINALIZADO'                    => 'Finalizado',
  'RECHAZADO'                     => 'Rechazado',
  'CANCELADO'                     => 'Cancelado',
];

function add_hist(mysqli $conn, int $solId, ?int $usuarioId, ?string $actor, string $accion, ?string $prev, ?string $next, ?string $coment=null){
  $stmt = $conn->prepare("INSERT INTO portal_proyectos_historial
    (solicitud_id, usuario_id, actor, accion, estatus_anterior, estatus_nuevo, comentario)
    VALUES (?,?,?,?,?,?,?)");
  $stmt->bind_param("iisssss", $solId, $usuarioId, $actor, $accion, $prev, $next, $coment);
  $stmt->execute();
  $stmt->close();
}
function set_status(mysqli $conn, int $solId, string $newStatus){
  $stmt = $conn->prepare("UPDATE portal_proyectos_solicitudes SET estatus=? WHERE id=? LIMIT 1");
  $stmt->bind_param("si", $newStatus, $solId);
  $stmt->execute();
  $stmt->close();
}

// =============================
// Wrapper para evitar pantalla blanca
// =============================
try {

  // ====== Cargar solicitud ======
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) { header("Location: portal_proyectos_listado.php"); exit(); }

  $stmt = $conn->prepare("
    SELECT s.*, e.clave empresa_clave, e.nombre empresa_nombre
    FROM portal_proyectos_solicitudes s
    JOIN portal_empresas e ON e.id = s.empresa_id
    WHERE s.id=? LIMIT 1
  ");
  $stmt->bind_param("i", $id);
  $stmt->execute();
  $sol = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$sol) { header("Location: portal_proyectos_listado.php"); exit(); }

  $estatusActual = (string)$sol['estatus'];

  // ====== Reglas de visibilidad ======
  if ($isSistemas) {
    // ok
  } elseif ($isCostos) {
    $capt = (int)($sol['costo_capturado_por'] ?? 0);
    if (!($estatusActual === 'EN_COSTEO' || $capt === $ID_USUARIO)) {
      http_response_code(403);
      exit("403");
    }
  } else {
    http_response_code(403);
    exit("403");
  }

  // ====== Acciones POST ======
  $msg = '';
  $err = '';

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $notifyAfterCommit = null;

    try {
      $conn->begin_transaction();

      // Re-cargar estatus para evitar carreras
      $stmt2 = $conn->prepare("SELECT estatus, costo_mxn, costo_capturado_por, costo_validado_por
                               FROM portal_proyectos_solicitudes
                               WHERE id=? LIMIT 1 FOR UPDATE");
      $stmt2->bind_param("i", $id);
      $stmt2->execute();
      $cur = $stmt2->get_result()->fetch_assoc();
      $stmt2->close();

      $prevStatus = (string)($cur['estatus'] ?? '');

      if ($action === 'save_revision_sistemas') {
        if (!$isSistemas) throw new Exception("Sin permiso.");

        $horas_min = trim((string)($_POST['horas_min'] ?? ''));
        $horas_max = trim((string)($_POST['horas_max'] ?? ''));
        $plan      = trim((string)($_POST['plan_acciones'] ?? ''));
        $riesgos   = trim((string)($_POST['riesgos'] ?? ''));
        $interno   = trim((string)($_POST['comentarios_internos'] ?? ''));

        if ($plan === '' || mb_strlen($plan) < 10) throw new Exception("El plan de acciones está muy corto.");

        // versión incremental
        $rv = $conn->prepare("SELECT COALESCE(MAX(version),0)+1 v FROM portal_proyectos_revision_sistemas WHERE solicitud_id=?");
        $rv->bind_param("i", $id);
        $rv->execute();
        $v = (int)$rv->get_result()->fetch_assoc()['v'];
        $rv->close();

        // Insert con NULLIF para permitir NULL sin broncas con bind_param(d)
        $hmStr = ($horas_min === '') ? '' : (string)((float)$horas_min);
        $hxStr = ($horas_max === '') ? '' : (string)((float)$horas_max);

        $ins = $conn->prepare("INSERT INTO portal_proyectos_revision_sistemas
          (solicitud_id, usuario_sistemas_id, horas_min, horas_max, plan_acciones, riesgos_dependencias, comentarios_internos, version)
          VALUES (?,?, NULLIF(?,''), NULLIF(?,''), ?,?,?,?)");
        $ins->bind_param("iisssssi", $id, $ID_USUARIO, $hmStr, $hxStr, $plan, $riesgos, $interno, $v);
        $ins->execute();
        $ins->close();

        add_hist($conn, $id, $ID_USUARIO, null, "REVISION_SISTEMAS_GUARDADA", $prevStatus, $prevStatus, "Versión $v");
        $msg = "Revisión de Sistemas guardada (versión $v).";
      }

      elseif ($action === 'send_to_costeo') {
        if (!$isSistemas) throw new Exception("Sin permiso.");
        if ($prevStatus !== 'EN_VALORACION_SISTEMAS') throw new Exception("Esta solicitud no está en valoración de sistemas.");

        set_status($conn, $id, 'EN_COSTEO');
        add_hist($conn, $id, $ID_USUARIO, null, "ENVIADA_A_COSTEO", $prevStatus, 'EN_COSTEO', null);
        $msg = "Enviada a Costos.";
        $notifyAfterCommit = 'send_to_costeo';
      }

      elseif ($action === 'capture_cost') {
        if (!$isCostos) throw new Exception("Sin permiso (solo Costos).");
        if ($prevStatus !== 'EN_COSTEO') throw new Exception("Esta solicitud no está en costeo.");

        $costo = trim((string)($_POST['costo_mxn'] ?? ''));
        $desg  = trim((string)($_POST['desglose'] ?? ''));
        $cond  = trim((string)($_POST['condiciones'] ?? ''));

        if ($costo === '') throw new Exception("Captura el costo.");
        $c = (float)str_replace([',','$',' '], '', $costo);
        if ($c <= 0) throw new Exception("Costo inválido.");

        $up = $conn->prepare("UPDATE portal_proyectos_solicitudes
          SET costo_mxn=?, costo_capturado_por=?, costo_capturado_at=NOW()
          WHERE id=? LIMIT 1");
        $up->bind_param("dii", $c, $ID_USUARIO, $id);
        $up->execute();
        $up->close();

        $rv = $conn->prepare("SELECT COALESCE(MAX(version),0)+1 v FROM portal_proyectos_costeo WHERE solicitud_id=?");
        $rv->bind_param("i", $id);
        $rv->execute();
        $v = (int)$rv->get_result()->fetch_assoc()['v'];
        $rv->close();

        $ins = $conn->prepare("INSERT INTO portal_proyectos_costeo
          (solicitud_id, usuario_validador_id, costo_mxn, desglose, condiciones, version)
          VALUES (?,?,?,?,?,?)");
        $ins->bind_param("iidssi", $id, $ID_USUARIO, $c, $desg, $cond, $v);
        $ins->execute();
        $ins->close();

        set_status($conn, $id, 'EN_VALIDACION_COSTO_SISTEMAS');
        add_hist($conn, $id, $ID_USUARIO, null, "COSTO_CAPTURADO", $prevStatus, 'EN_VALIDACION_COSTO_SISTEMAS', "Costo: $".number_format($c,2));
        $msg = "Costo capturado y enviado a Sistemas para validación.";
        $notifyAfterCommit = 'capture_cost';
      }

      elseif ($action === 'validate_cost_sistemas') {
        if (!$isSistemas) throw new Exception("Sin permiso.");
        if ($prevStatus !== 'EN_VALIDACION_COSTO_SISTEMAS') throw new Exception("No está en validación de costo.");

        $stmtC = $conn->prepare("SELECT costo_mxn FROM portal_proyectos_solicitudes WHERE id=? LIMIT 1");
        $stmtC->bind_param("i", $id);
        $stmtC->execute();
        $cc = $stmtC->get_result()->fetch_assoc();
        $stmtC->close();

        $costoVal = $cc['costo_mxn'] ?? null;
        if ($costoVal === null || (float)$costoVal <= 0) throw new Exception("No hay costo capturado.");

        $up = $conn->prepare("UPDATE portal_proyectos_solicitudes
          SET costo_validado_por=?, costo_validado_at=NOW()
          WHERE id=? LIMIT 1");
        $up->bind_param("ii", $ID_USUARIO, $id);
        $up->execute();
        $up->close();

        set_status($conn, $id, 'EN_AUTORIZACION_SOLICITANTE');
        add_hist($conn, $id, $ID_USUARIO, null, "COSTO_VALIDADO_POR_SISTEMAS", $prevStatus, 'EN_AUTORIZACION_SOLICITANTE', "OK para enviar a solicitante.");
        $msg = "Costo validado. Enviado a autorización del solicitante.";
        $notifyAfterCommit = 'validate_cost_sistemas';
      }

      elseif ($action === 'start_execution') {
        if (!$isSistemas) throw new Exception("Sin permiso.");
        if ($prevStatus !== 'AUTORIZADO') throw new Exception("Solo puedes iniciar si está AUTORIZADO.");

        set_status($conn, $id, 'EN_EJECUCION');
        add_hist($conn, $id, $ID_USUARIO, null, "INICIO_EJECUCION", $prevStatus, 'EN_EJECUCION', null);
        $msg = "Proyecto marcado como EN EJECUCIÓN.";
      }

      elseif ($action === 'finish') {
        if (!$isSistemas) throw new Exception("Sin permiso.");
        if ($prevStatus !== 'EN_EJECUCION') throw new Exception("Solo puedes finalizar si está EN EJECUCIÓN.");

        set_status($conn, $id, 'FINALIZADO');
        add_hist($conn, $id, $ID_USUARIO, null, "FINALIZADO", $prevStatus, 'FINALIZADO', null);
        $msg = "Proyecto finalizado.";
      }

      else {
        throw new Exception("Acción no válida.");
      }

      $conn->commit();

      try {
        if ($notifyAfterCommit === 'send_to_costeo') {
          portal_notify_enviado_a_costeo($conn, $id);
        } elseif ($notifyAfterCommit === 'capture_cost') {
          portal_notify_costo_capturado($conn, $id);
        } elseif ($notifyAfterCommit === 'validate_cost_sistemas') {
          portal_notify_lista_para_aprobacion($conn, $id);
        }
      } catch (Throwable $mailErr) {
        error_log('PORTAL notify after commit ERROR: ' . $mailErr->getMessage());
      }

      back($id);

    } catch (Throwable $e) {
      try { $conn->rollback(); } catch (Throwable $ignore) {}
      $err = $e->getMessage();
    }
  }

  // ===== Cargar últimas revisiones/costeo =====
  $revLast = null;
  $revOrder = pick_order_col($conn, 'portal_proyectos_revision_sistemas', ['created_at','fecha','created'], 'id');

  $stmtR = $conn->prepare("
    SELECT r.*, u.nombre nombre_sistemas
    FROM portal_proyectos_revision_sistemas r
    LEFT JOIN usuarios u ON u.id = r.usuario_sistemas_id
    WHERE r.solicitud_id=?
    ORDER BY r.version DESC, r.$revOrder DESC
    LIMIT 1
  ");
  $stmtR->bind_param("i", $id);
  $stmtR->execute();
  $revLast = $stmtR->get_result()->fetch_assoc();
  $stmtR->close();

  $costLast = null;
  $costOrder = pick_order_col($conn, 'portal_proyectos_costeo', ['created_at','fecha','created'], 'id');

  $stmtK = $conn->prepare("
    SELECT c.*, u.nombre nombre_costos
    FROM portal_proyectos_costeo c
    LEFT JOIN usuarios u ON u.id = c.usuario_validador_id
    WHERE c.solicitud_id=?
    ORDER BY c.version DESC, c.$costOrder DESC
    LIMIT 1
  ");
  $stmtK->bind_param("i", $id);
  $stmtK->execute();
  $costLast = $stmtK->get_result()->fetch_assoc();
  $stmtK->close();

  // Historial
  $hist = [];
  $histOrder = pick_order_col($conn, 'portal_proyectos_historial', ['created_at','fecha','created'], 'id');

  $stmtH = $conn->prepare("
    SELECT h.*, u.nombre nombre_usuario
    FROM portal_proyectos_historial h
    LEFT JOIN usuarios u ON u.id = h.usuario_id
    WHERE h.solicitud_id=?
    ORDER BY h.$histOrder DESC
    LIMIT 50
  ");
  $stmtH->bind_param("i", $id);
  $stmtH->execute();
  $hist = $stmtH->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmtH->close();

  // ===== Detectar última respuesta del solicitante (API) para feedback visual =====
  $respSolic = null;
  if (!empty($hist)) {
    foreach ($hist as $hrow) {
      $accion = (string)($hrow['accion'] ?? '');
      if ($accion === 'COSTO_AUTORIZADO_SOLICITANTE' || $accion === 'COSTO_RECHAZADO_SOLICITANTE') {
        $respSolic = [
          'tipo'   => ($accion === 'COSTO_AUTORIZADO_SOLICITANTE') ? 'AUTORIZADO' : 'RECHAZADO',
          'when'   => (string)($hrow[$histOrder] ?? ($hrow['created_at'] ?? $hrow['fecha'] ?? '')),
          'who'    => (string)($hrow['actor'] ?? ($hrow['nombre_usuario'] ?? 'Solicitante')),
          'coment' => (string)($hrow['comentario'] ?? ''),
        ];
        break;
      }
    }
  }

  $estatusLabel = $ESTATUS[$estatusActual] ?? $estatusActual;

} catch (Throwable $e) {
  if ($DEBUG) {
    echo "<pre style='padding:12px;background:#111;color:#0f0;white-space:pre-wrap'>";
    echo "ERROR: ".$e->getMessage()."\n\n";
    echo $e->getFile().":".$e->getLine()."\n\n";
    echo $e->getTraceAsString();
    echo "</pre>";
  } else {
    echo "<div style='padding:14px;font-family:Arial;background:#f8d7da;color:#842029;border:1px solid #f5c2c7;border-radius:10px;max-width:900px;margin:18px auto;'>
            <b>Error interno</b><br>Revisa el log del servidor o entra con <code>?debug=1</code> (solo Admin).
          </div>";
  }
  exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= h($sol['folio']) ?> • Portal Proyectos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .card{ border-radius:16px; }
    .small-muted{ font-size:12px; color:#6c757d; }
    .label{ font-size:12px; color:#6c757d; }
    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  </style>
</head>
<body class="bg-light">

<?php
// ✅ Navbar ya dentro del body (ya no rompe headers)
require_once __DIR__ . '/navbar.php';
?>

<div class="container py-4">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <div class="small-muted"><a href="portal_proyectos_listado.php">← Volver al listado</a></div>
      <h4 class="mb-0"><?= h($sol['folio']) ?> <span class="badge text-bg-secondary"><?= h($estatusLabel) ?></span></h4>
      <div class="small-muted">
        Empresa: <b><?= h($sol['empresa_clave'].' - '.$sol['empresa_nombre']) ?></b>
        • Creado: <?= h(substr((string)$sol['created_at'],0,16)) ?>
        <?php if($DEBUG): ?><span class="badge text-bg-dark ms-2">debug</span><?php endif; ?>
      </div>
    </div>
  </div>

  <?php if(!empty($err)): ?>
    <div class="alert alert-danger shadow-sm"><?= h($err) ?></div>
  <?php elseif(!empty($msg)): ?>
    <div class="alert alert-success shadow-sm"><?= h($msg) ?></div>
  <?php endif; ?>

  <?php if (!empty($respSolic)): ?>
    <?php
      $isOk = ($respSolic['tipo'] === 'AUTORIZADO');
      $alertClass = $isOk ? 'alert-success' : 'alert-danger';
      $title = $isOk ? 'Autorizado por el solicitante' : 'Rechazado por el solicitante';
      $costoTxt = ($sol['costo_mxn'] !== null) ? ('$' . number_format((float)$sol['costo_mxn'], 2) . ' MXN') : '—';
      $whenTxt = $respSolic['when'] ? substr($respSolic['when'], 0, 16) : '';
      $whoTxt  = $respSolic['who'] ?: 'Solicitante';
    ?>
    <div class="alert <?= h($alertClass) ?> shadow-sm">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
          <div class="fw-semibold"><?= h($title) ?></div>
          <div class="small-muted">
            Costo: <b><?= h($costoTxt) ?></b>
            <?= $whenTxt ? (' • Fecha: <b>' . h($whenTxt) . '</b>') : '' ?>
            <?= $whoTxt ? (' • Origen: <b>' . h($whoTxt) . '</b>') : '' ?>
          </div>

          <?php if (trim((string)$respSolic['coment']) !== ''): ?>
            <div class="mt-2" style="white-space:pre-wrap;"><?= h($respSolic['coment']) ?></div>
          <?php else: ?>
            <div class="mt-2 small-muted">Sin comentario.</div>
          <?php endif; ?>
        </div>

        <?php if ($isSistemas && $estatusActual === 'AUTORIZADO'): ?>
          <div class="d-flex gap-2">
            <form method="post" class="m-0">
              <input type="hidden" name="action" value="start_execution">
              <button class="btn btn-sm btn-primary">Iniciar ejecución</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-3">
    <!-- Solicitud -->
    <div class="col-12 col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="label">Título</div>
          <div class="fs-5 fw-semibold mb-2"><?= h($sol['titulo']) ?></div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <div class="label">Tipo</div>
              <div><?= h($sol['tipo']) ?></div>
            </div>
            <div class="col-6">
              <div class="label">Prioridad</div>
              <div><?= h($sol['prioridad']) ?></div>
            </div>
          </div>

          <div class="label">Descripción</div>
          <div class="mt-1" style="white-space:pre-wrap;"><?= h($sol['descripcion']) ?></div>

          <hr>

          <div class="row g-2">
            <div class="col-12 col-md-6">
              <div class="label">Solicitante (interno)</div>
              <div class="mono">
                <?= $sol['usuario_solicitante_id'] ? ('ID '.$sol['usuario_solicitante_id']) : '<span class="text-muted">Externo</span>' ?>
              </div>
              <?php if(!$sol['usuario_solicitante_id']): ?>
                <div class="small-muted">
                  <?= h($sol['solicitante_nombre'] ?? '') ?>
                  <?= ($sol['solicitante_correo'] ?? '') ? (' • '.h($sol['solicitante_correo'])) : '' ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="col-12 col-md-6 text-md-end">
              <div class="label">Costo actual</div>
              <div class="fs-5 fw-bold">
                <?= ($sol['costo_mxn'] !== null) ? ('$'.number_format((float)$sol['costo_mxn'],2)) : '<span class="text-muted">—</span>' ?>
              </div>
              <div class="small-muted">
                Capturado por: <?= $sol['costo_capturado_por'] ? ('ID '.(int)$sol['costo_capturado_por']) : '—' ?>
                • Validado por: <?= $sol['costo_validado_por'] ? ('ID '.(int)$sol['costo_validado_por']) : '—' ?>
              </div>
            </div>
          </div>

          <?php if($isSistemas && $estatusActual === 'EN_VALORACION_SISTEMAS'): ?>
            <hr>
            <form method="post" class="d-flex justify-content-end gap-2">
              <input type="hidden" name="action" value="send_to_costeo">
              <button class="btn btn-warning">Enviar a Costeo</button>
            </form>
          <?php endif; ?>

        </div>
      </div>

      <!-- Revisión Sistemas -->
      <div class="card shadow-sm mt-3">
        <div class="card-body">
          <h6 class="mb-2">Revisión de Sistemas</h6>

          <?php if($revLast): ?>
            <div class="small-muted mb-2">
              Última versión: <b>#<?= (int)$revLast['version'] ?></b>
              • Por: <b><?= h($revLast['nombre_sistemas'] ?? ('ID '.$revLast['usuario_sistemas_id'])) ?></b>
              • <?= h(substr((string)($revLast['created_at'] ?? $revLast['fecha'] ?? $revLast['created'] ?? ''),0,16)) ?>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <div class="label">Horas min</div>
                <div><?= ($revLast['horas_min']!==null) ? h($revLast['horas_min']) : '—' ?></div>
              </div>
              <div class="col-6">
                <div class="label">Horas max</div>
                <div><?= ($revLast['horas_max']!==null) ? h($revLast['horas_max']) : '—' ?></div>
              </div>
            </div>
            <div class="label">Plan de acciones</div>
            <div style="white-space:pre-wrap;"><?= h($revLast['plan_acciones']) ?></div>
            <?php if(!empty($revLast['riesgos_dependencias'])): ?>
              <div class="mt-3 label">Riesgos / dependencias</div>
              <div style="white-space:pre-wrap;"><?= h($revLast['riesgos_dependencias']) ?></div>
            <?php endif; ?>
          <?php else: ?>
            <div class="text-muted">Aún no hay revisión de Sistemas.</div>
          <?php endif; ?>

          <?php if($isSistemas): ?>
            <hr>
            <form method="post" class="row g-2">
              <input type="hidden" name="action" value="save_revision_sistemas">
              <div class="col-6 col-md-3">
                <label class="form-label small-muted">Horas min</label>
                <input type="number" step="0.25" name="horas_min" class="form-control" placeholder="ej. 10">
              </div>
              <div class="col-6 col-md-3">
                <label class="form-label small-muted">Horas max</label>
                <input type="number" step="0.25" name="horas_max" class="form-control" placeholder="ej. 16">
              </div>
              <div class="col-12">
                <label class="form-label small-muted">Plan de acciones (qué se implementará)</label>
                <textarea name="plan_acciones" class="form-control" rows="5" required></textarea>
              </div>
              <div class="col-12">
                <label class="form-label small-muted">Riesgos / dependencias (opcional)</label>
                <textarea name="riesgos" class="form-control" rows="3"></textarea>
              </div>
              <div class="col-12">
                <label class="form-label small-muted">Comentarios internos (opcional)</label>
                <textarea name="comentarios_internos" class="form-control" rows="2"></textarea>
              </div>
              <div class="col-12 d-flex justify-content-end">
                <button class="btn btn-primary">Guardar revisión</button>
              </div>
            </form>
          <?php endif; ?>

        </div>
      </div>

      <!-- Costeo -->
      <div class="card shadow-sm mt-3">
        <div class="card-body">
          <h6 class="mb-2">Costeo</h6>

          <?php if($costLast): ?>
            <div class="small-muted mb-2">
              Última versión: <b>#<?= (int)$costLast['version'] ?></b>
              • Por: <b><?= h($costLast['nombre_costos'] ?? ('ID '.$costLast['usuario_validador_id'])) ?></b>
              • <?= h(substr((string)($costLast['created_at'] ?? $costLast['fecha'] ?? $costLast['created'] ?? ''),0,16)) ?>
            </div>
            <div class="fs-5 fw-bold mb-2">$<?= number_format((float)$costLast['costo_mxn'],2) ?></div>
            <?php if(!empty($costLast['desglose'])): ?>
              <div class="label">Desglose</div>
              <div style="white-space:pre-wrap;"><?= h($costLast['desglose']) ?></div>
            <?php endif; ?>
            <?php if(!empty($costLast['condiciones'])): ?>
              <div class="mt-3 label">Condiciones</div>
              <div style="white-space:pre-wrap;"><?= h($costLast['condiciones']) ?></div>
            <?php endif; ?>
          <?php else: ?>
            <div class="text-muted">Aún no hay costeo.</div>
          <?php endif; ?>

          <?php if($isCostos && $estatusActual === 'EN_COSTEO'): ?>
            <hr>
            <form method="post" class="row g-2">
              <input type="hidden" name="action" value="capture_cost">
              <div class="col-12 col-md-4">
                <label class="form-label small-muted">Costo (MXN)</label>
                <input type="text" name="costo_mxn" class="form-control" placeholder="Ej. 8500" required>
              </div>
              <div class="col-12">
                <label class="form-label small-muted">Desglose (opcional)</label>
                <textarea name="desglose" class="form-control" rows="3" placeholder="Análisis: ... / Desarrollo: ... / Pruebas: ..."></textarea>
              </div>
              <div class="col-12">
                <label class="form-label small-muted">Condiciones (opcional)</label>
                <textarea name="condiciones" class="form-control" rows="2" placeholder="Pago, alcances, soporte, etc."></textarea>
              </div>
              <div class="col-12 d-flex justify-content-end">
                <button class="btn btn-warning">Guardar costo y enviar a Sistemas</button>
              </div>
            </form>
          <?php endif; ?>

          <?php if($isSistemas && $estatusActual === 'EN_VALIDACION_COSTO_SISTEMAS'): ?>
            <hr>
            <form method="post" class="d-flex justify-content-end">
              <input type="hidden" name="action" value="validate_cost_sistemas">
              <button class="btn btn-success">Validar costo y enviar a solicitante</button>
            </form>
          <?php endif; ?>

        </div>
      </div>

      <!-- Ejecución -->
      <?php if($isSistemas): ?>
        <div class="card shadow-sm mt-3">
          <div class="card-body">
            <h6 class="mb-2">Ejecución</h6>

            <?php if($estatusActual === 'AUTORIZADO'): ?>
              <form method="post" class="d-flex justify-content-end">
                <input type="hidden" name="action" value="start_execution">
                <button class="btn btn-primary">Iniciar ejecución</button>
              </form>
            <?php elseif($estatusActual === 'EN_EJECUCION'): ?>
              <form method="post" class="d-flex justify-content-end">
                <input type="hidden" name="action" value="finish">
                <button class="btn btn-dark">Finalizar</button>
              </form>
            <?php else: ?>
              <div class="text-muted">Acciones disponibles cuando esté AUTORIZADO o EN EJECUCIÓN.</div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

    </div>

    <!-- Historial -->
    <div class="col-12 col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <h6 class="mb-2">Timeline / Bitácora</h6>
          <?php if(!$hist): ?>
            <div class="text-muted">Sin movimientos aún.</div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach($hist as $hrow): ?>
                <?php
                  $who  = $hrow['nombre_usuario'] ?? $hrow['actor'] ?? '—';
                  $prev = $hrow['estatus_anterior'] ?? '';
                  $next = $hrow['estatus_nuevo'] ?? '';

                  $isRespSolic = in_array((string)($hrow['accion'] ?? ''), ['COSTO_AUTORIZADO_SOLICITANTE','COSTO_RECHAZADO_SOLICITANTE'], true);
                  $itemClass = $isRespSolic
                    ? ('border border-2 ' . ((string)$hrow['accion'] === 'COSTO_AUTORIZADO_SOLICITANTE'
                        ? 'border-success bg-success bg-opacity-10'
                        : 'border-danger bg-danger bg-opacity-10'))
                    : '';
                ?>
                <div class="list-group-item <?= h($itemClass) ?>">
                  <div class="d-flex justify-content-between">
                    <div class="fw-semibold"><?= h($hrow['accion']) ?></div>
                    <div class="small-muted"><?= h(substr((string)($hrow[$histOrder] ?? ''),0,16)) ?></div>
                  </div>
                  <div class="small-muted">Por: <?= h($who) ?></div>
                  <?php if($prev || $next): ?>
                    <div class="small-muted">Estatus: <?= h($prev) ?> → <?= h($next) ?></div>
                  <?php endif; ?>
                  <?php if(!empty($hrow['comentario'])): ?>
                    <div class="mt-2" style="white-space:pre-wrap;"><?= h($hrow['comentario']) ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="small-muted mt-3">
            Nota: La autorización del solicitante se integra por API/UI del solicitante (EN_AUTORIZACION_SOLICITANTE → AUTORIZADO/RECHAZADO).
          </div>
        </div>
      </div>
    </div>

  </div>

</div>

</body>
</html>
<?php
// flush buffer
ob_end_flush();