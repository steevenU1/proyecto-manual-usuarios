<?php
// portal_solicitante_nuevo.php — Portal Solicitante (Crear solicitud)
// LUGA -> creación DIRECTA en BD local (sin llamar API)
// Mantiene UI portal cliente + POST AJAX al mismo archivo
// Replica la lógica de api_portal_proyectos_crear.php

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_portal.php';
require_once __DIR__ . '/portal_notificaciones.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function out($ok, $data = [], $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function bad($msg, $code = 400, $extra = []) {
    out(false, array_merge(['error' => $msg], $extra), $code);
}

function get_json() {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function htrim($s, $max = 5000) {
    $s = trim((string)$s);
    if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
    return $s;
}

/* =========================================================
   CONFIG
========================================================= */
$ORIGEN_UI = 'LUGA';

/* =========================================================
   AJAX POST -> guardar directo en LUGA
========================================================= */
$isAjaxPost = (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

if ($isAjaxPost) {
    try {
        $in = get_json();

        $titulo = htrim($in['titulo'] ?? '', 180);
        $desc   = htrim($in['descripcion'] ?? '', 20000);
        $tipo   = htrim($in['tipo'] ?? 'Implementacion', 40);
        $prio   = htrim($in['prioridad'] ?? 'Media', 15);

        if ($titulo === '' || mb_strlen($titulo) < 5) {
            bad('titulo_invalido', 422);
        }

        if ($desc === '' || mb_strlen($desc) < 10) {
            bad('descripcion_invalida', 422);
        }

        $prioAllowed = ['Baja', 'Media', 'Alta', 'Urgente'];
        if (!in_array($prio, $prioAllowed, true)) {
            $prio = 'Media';
        }

        $idUsuarioSesion = (int)($_SESSION['id_usuario'] ?? 0);
        $nombreSesion    = trim((string)($_SESSION['nombre'] ?? ''));
        $correoSesion    = '';
        $rolSesion       = trim((string)($_SESSION['rol'] ?? ''));

        if ($idUsuarioSesion <= 0) {
            bad('sesion_invalida', 401);
        }

        $stmtU = $conn->prepare("SELECT nombre, correo FROM usuarios WHERE id = ? LIMIT 1");
        $stmtU->bind_param("i", $idUsuarioSesion);
        $stmtU->execute();
        $rowU = $stmtU->get_result()->fetch_assoc();
        $stmtU->close();

        if ($rowU) {
            if ($nombreSesion === '' && !empty($rowU['nombre'])) {
                $nombreSesion = trim((string)$rowU['nombre']);
            }
            $correoSesion = trim((string)($rowU['correo'] ?? ''));
        }

        if ($nombreSesion === '') {
            $nombreSesion = 'Usuario #' . $idUsuarioSesion;
        }

        // Empresa LUGA
        $stmtE = $conn->prepare("SELECT id FROM portal_empresas WHERE clave = ? AND activa = 1 LIMIT 1");
        $stmtE->bind_param("s", $ORIGEN_UI);
        $stmtE->execute();
        $rowE = $stmtE->get_result()->fetch_assoc();
        $stmtE->close();

        if (!$rowE) {
            bad('empresa_no_configurada', 500);
        }

        $empresaId = (int)$rowE['id'];

        $solId   = 0;
        $folio   = '';
        $estatus = 'EN_VALORACION_SISTEMAS';

        $conn->begin_transaction();

        $year = date('Y');

        $stmtL = $conn->prepare("
            SELECT folio
            FROM portal_proyectos_solicitudes
            WHERE folio LIKE CONCAT('PRJ-', ?, '-%')
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ");
        $stmtL->bind_param("s", $year);
        $stmtL->execute();
        $lastRow = $stmtL->get_result()->fetch_assoc();
        $stmtL->close();

        $nextNum = 1;
        if ($lastRow && !empty($lastRow['folio'])) {
            if (preg_match('/PRJ-\d{4}-(\d+)/', (string)$lastRow['folio'], $m)) {
                $nextNum = ((int)$m[1]) + 1;
            }
        }

        $folio = "PRJ-$year-" . str_pad((string)$nextNum, 6, '0', STR_PAD_LEFT);

        // En LUGA sí guardamos el usuario de sesión
        $ins = $conn->prepare("
            INSERT INTO portal_proyectos_solicitudes
            (folio, empresa_id, usuario_solicitante_id, solicitante_nombre, solicitante_correo, titulo, descripcion, tipo, prioridad, estatus)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $ins->bind_param(
            "siisssssss",
            $folio,
            $empresaId,
            $idUsuarioSesion,
            $nombreSesion,
            $correoSesion,
            $titulo,
            $desc,
            $tipo,
            $prio,
            $estatus
        );
        $ins->execute();
        $solId = (int)$conn->insert_id;
        $ins->close();

        $accion = "CREADA";
        $actor  = $nombreSesion . ($rolSesion !== '' ? " ({$rolSesion})" : '') . " - LUGA";
        $prev   = null;
        $coment = "Solicitud creada desde LUGA por {$nombreSesion}";

        $stmtH = $conn->prepare("
            INSERT INTO portal_proyectos_historial
            (solicitud_id, usuario_id, actor, accion, estatus_anterior, estatus_nuevo, comentario)
            VALUES (?,?,?,?,?,?,?)
        ");
        $stmtH->bind_param(
            "iisssss",
            $solId,
            $idUsuarioSesion,
            $actor,
            $accion,
            $prev,
            $estatus,
            $coment
        );
        $stmtH->execute();
        $stmtH->close();

        $conn->commit();

        $mailSent = true;
        $mailInfo = null;

        // Notificación fuera de transacción
        try {
            portal_notify_nueva_solicitud($conn, $solId);
        } catch (Throwable $mailErr) {
            $mailSent = false;
            $mailInfo = $mailErr->getMessage();
            error_log('PORTAL notify nueva solicitud ERROR: ' . $mailErr->getMessage());
        }

        out(true, [
            'id'        => $solId,
            'folio'     => $folio,
            'estatus'   => $estatus,
            'origen'    => $ORIGEN_UI,
            'mail_sent' => $mailSent,
            'mail_info' => $mailInfo
        ]);

    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        error_log('PORTAL crear solicitud LUGA ERROR: ' . $e->getMessage());
        bad('error_creando', 500, ['detail' => $e->getMessage()]);
    }
}

/* =========================================================
   HTML
========================================================= */
require_once __DIR__ . '/navbar.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Nueva solicitud • Portal Proyectos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --radius:18px; }
    body{ background:#f5f7fb; }
    .card{ border-radius: var(--radius); }
    .soft-shadow{ box-shadow: 0 10px 30px rgba(20,20,40,.06); }
    .small-muted{ font-size:12px; color:#6c757d; }
    .pill{
      border:1px solid rgba(0,0,0,.08);
      border-radius:999px;
      padding:.25rem .65rem;
      font-size:12px;
      background:#fff;
      display:inline-flex;
      align-items:center;
      gap:.35rem;
    }
    .mono{
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    }
  </style>
</head>
<body>

<div class="container py-4">

  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <div class="small-muted">
        <span class="pill"><span class="mono">Central</span> <b><?= h($ORIGEN_UI) ?></b></span>
        <span class="mx-2">•</span>
        <a href="portal_solicitante_listado.php" class="text-decoration-none">← Volver</a>
      </div>
      <h3 class="m-0">Nueva solicitud</h3>
      <div class="small-muted">Describe lo que necesitas y lo mandamos a valoración.</div>
    </div>
  </div>

  <div id="alertBox" class="mb-3"></div>

  <div class="row g-3">
    <div class="col-12 col-lg-7">
      <div class="card soft-shadow">
        <div class="card-body">
          <form id="frm" novalidate>

            <div class="mb-3">
              <label class="form-label fw-semibold">Título</label>
              <input
                type="text"
                class="form-control"
                name="titulo"
                id="titulo"
                maxlength="180"
                placeholder="Ej. Flujo de traspasos para Subdis"
                required
              >
              <div class="small-muted mt-1">Mínimo 5 caracteres.</div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Descripción</label>
              <textarea
                class="form-control"
                name="descripcion"
                id="descripcion"
                rows="7"
                maxlength="20000"
                placeholder="Describe el alcance, pantallas, validaciones, reportes, etc."
                required
              ></textarea>
              <div class="small-muted mt-1">Mínimo 10 caracteres.</div>
            </div>

            <div class="row g-2">
              <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Tipo</label>
                <select class="form-select" name="tipo" id="tipo">
                  <option value="Implementacion">Implementación</option>
                  <option value="Correccion">Corrección</option>
                  <option value="Mejora">Mejora</option>
                  <option value="Reporte">Reporte</option>
                  <option value="Otro">Otro</option>
                </select>
              </div>
              <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Prioridad</label>
                <select class="form-select" name="prioridad" id="prioridad">
                  <option value="Baja">Baja</option>
                  <option value="Media" selected>Media</option>
                  <option value="Alta">Alta</option>
                  <option value="Urgente">Urgente</option>
                </select>
              </div>
            </div>

            <hr class="my-4">

            <div class="d-flex justify-content-between flex-wrap gap-2">
              <div class="small-muted">
                Se creará en estatus: <b>EN_VALORACION_SISTEMAS</b>
              </div>

              <button id="btnEnviar" class="btn btn-primary" type="submit">
                Enviar solicitud
              </button>
            </div>

          </form>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-5">
      <div class="card soft-shadow">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="small-muted">Preview</div>
              <div class="fw-semibold">Así se verá en el listado</div>
            </div>
            <span class="badge text-bg-secondary">Borrador</span>
          </div>

          <hr>

          <div class="small-muted">Título</div>
          <div id="pvTitulo" class="fs-5 fw-semibold">—</div>

          <div class="mt-3 d-flex gap-2 flex-wrap">
            <span class="pill">Tipo: <b id="pvTipo">Implementación</b></span>
            <span class="pill">Prioridad: <b id="pvPrio">Media</b></span>
            <span class="pill">Central: <b><?= h($ORIGEN_UI) ?></b></span>
          </div>

          <div class="mt-3 small-muted">Descripción</div>
          <div id="pvDesc" style="white-space:pre-wrap;">—</div>

          <div class="mt-3 small-muted">
            Al enviar: se genera folio + historial inicial.
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  const el = (id) => document.getElementById(id);

  function setAlert(type, msg) {
    const cls =
      type === 'ok' ? 'alert-success'
      : type === 'warn' ? 'alert-warning'
      : 'alert-danger';

    el('alertBox').innerHTML = `<div class="alert ${cls} soft-shadow">${msg}</div>`;
  }

  function short(s, n = 220) {
    s = (s || '').trim();
    if (!s) return '—';
    if (s.length <= n) return s;
    return s.slice(0, n - 1) + '…';
  }

  function syncPreview() {
    const titulo = el('titulo').value.trim();
    const desc   = el('descripcion').value.trim();
    const tipo   = el('tipo').value;
    const prio   = el('prioridad').value;

    el('pvTitulo').textContent = titulo || '—';
    el('pvTipo').textContent   = tipo === 'Implementacion' ? 'Implementación' : tipo;
    el('pvPrio').textContent   = prio;
    el('pvDesc').textContent   = short(desc, 320);
  }

  ['titulo','descripcion','tipo','prioridad'].forEach(id => {
    el(id).addEventListener('input', syncPreview);
    el(id).addEventListener('change', syncPreview);
  });
  syncPreview();

  el('frm').addEventListener('submit', async (ev) => {
    ev.preventDefault();

    const titulo      = el('titulo').value.trim();
    const descripcion = el('descripcion').value.trim();
    const tipo        = el('tipo').value;
    const prioridad   = el('prioridad').value;

    if (titulo.length < 5) {
      return setAlert('bad', 'El título está muy corto (mínimo 5).');
    }
    if (descripcion.length < 10) {
      return setAlert('bad', 'La descripción está muy corta (mínimo 10).');
    }

    const payload = { titulo, descripcion, tipo, prioridad };

    const btn = el('btnEnviar');
    btn.disabled = true;
    btn.textContent = 'Enviando…';

    try {
      const res = await fetch(window.location.href, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(payload)
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok || !data.ok) {
        const err = (data && data.error) ? data.error : ('http_' + res.status);
        const det = (data && data.detail) ? `<div class="small mt-1">${String(data.detail)}</div>` : '';
        throw new Error(err + (det ? '|||' + det : ''));
      }

      const extraMail = (data && data.mail_sent === false)
        ? `<div class="small mt-1 text-warning">La solicitud sí se creó, pero la notificación no se pudo enviar.</div>`
        : '';

      setAlert('ok', `Listo ✅ Se creó <b>${data.folio ?? ('#' + data.id)}</b>. Redirigiendo al detalle…${extraMail}`);

      setTimeout(() => {
        window.location.href = `portal_solicitante_detalle.php?id=${encodeURIComponent(data.id)}`;
      }, 700);

    } catch (e) {
      const raw = String(e.message || e);
      const parts = raw.split('|||');
      const msg = parts[0] || 'error_desconocido';
      const det = parts[1] || '';
      setAlert('bad', `No se pudo crear: <b>${msg}</b>${det}`);
      btn.disabled = false;
      btn.textContent = 'Enviar solicitud';
    }
  });
</script>

</body>
</html>