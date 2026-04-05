<?php
// includes/notificaciones_tablero.php

require_once __DIR__ . '/mail_hostinger.php';

function nt_has_column(mysqli $conn, string $table, string $column): bool
{
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $sql = "SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$t}'
              AND COLUMN_NAME = '{$c}'
            LIMIT 1";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

function nt_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return $scheme . '://' . $host . $basePath;
}

function nt_get_usuario(mysqli $conn, int $idUsuario): ?array
{
    if ($idUsuario <= 0) return null;

    $hasCorreo = nt_has_column($conn, 'usuarios', 'correo');
    $colCorreo = $hasCorreo ? 'correo' : "'' AS correo";

    $sql = "SELECT id, nombre, {$colCorreo}
            FROM usuarios
            WHERE id = ?
            LIMIT 1";

    $st = $conn->prepare($sql);
    if (!$st) return null;

    $st->bind_param("i", $idUsuario);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$u) return null;

    return [
        'id'     => (int)$u['id'],
        'nombre' => (string)($u['nombre'] ?? ''),
        'correo' => trim((string)($u['correo'] ?? ''))
    ];
}

function nt_get_tarea_basica(mysqli $conn, int $idTarea): ?array
{
    if ($idTarea <= 0) return null;

    $sql = "SELECT
              t.id,
              t.titulo,
              t.descripcion,
              t.id_creador,
              t.id_responsable,
              u.nombre AS responsable_nombre
            FROM tablero_tareas t
            LEFT JOIN usuarios u ON u.id = t.id_responsable
            WHERE t.id = ?
            LIMIT 1";

    $st = $conn->prepare($sql);
    if (!$st) return null;

    $st->bind_param("i", $idTarea);
    $st->execute();
    $t = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$t) return null;

    return [
        'id'                => (int)$t['id'],
        'titulo'            => (string)($t['titulo'] ?? ''),
        'descripcion'       => (string)($t['descripcion'] ?? ''),
        'id_creador'        => (int)($t['id_creador'] ?? 0),
        'id_responsable'    => (int)($t['id_responsable'] ?? 0),
        'responsable_nombre'=> (string)($t['responsable_nombre'] ?? '')
    ];
}

function nt_send_tarea_creada(
    mysqli $conn,
    int $idTarea,
    int $idResponsable,
    int $idActor,
    string $titulo,
    string $descripcion = ''
): array {
    if ($idTarea <= 0 || $idResponsable <= 0) {
        return ['ok' => true, 'skip' => 'sin_responsable'];
    }

    // Evitar mandarle correo al mismo que crea si además es responsable
    if ($idResponsable === $idActor) {
        return ['ok' => true, 'skip' => 'actor_es_responsable'];
    }

    $responsable = nt_get_usuario($conn, $idResponsable);
    $actor       = nt_get_usuario($conn, $idActor);

    if (!$responsable) {
        return ['ok' => false, 'error' => 'No se encontró responsable'];
    }

    $correo = trim((string)($responsable['correo'] ?? ''));
    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => true, 'skip' => 'responsable_sin_correo'];
    }

    $actorNombre = trim((string)($actor['nombre'] ?? 'Sistema'));
    $tituloSafe = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    $descSafe   = nl2br(htmlspecialchars($descripcion, ENT_QUOTES, 'UTF-8'));

    $url = nt_base_url() . '/tarea_detalle.php?id=' . $idTarea;

    $subject = "Nueva tarea asignada: {$titulo}";

    $html = '
    <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.5">
      <h2 style="margin:0 0 12px 0;color:#111">Se te asignó una nueva tarea</h2>

      <p style="margin:0 0 10px 0">
        Hola <b>' . htmlspecialchars($responsable['nombre'], ENT_QUOTES, 'UTF-8') . '</b>,
        <br>
        <b>' . htmlspecialchars($actorNombre, ENT_QUOTES, 'UTF-8') . '</b> te asignó una tarea en el Tablero de Operación.
      </p>

      <div style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin:12px 0">
        <div style="font-size:12px;color:#6b7280;text-transform:uppercase;margin-bottom:6px">Título</div>
        <div style="font-size:16px;font-weight:700;margin-bottom:10px">' . $tituloSafe . '</div>

        ' . ($descripcion !== '' ? '
        <div style="font-size:12px;color:#6b7280;text-transform:uppercase;margin-bottom:6px">Descripción</div>
        <div style="font-size:14px;color:#333">' . $descSafe . '</div>
        ' : '') . '
      </div>

      <p style="margin:14px 0">
        <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#0d6efd;color:#fff;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:700">
          Ver tarea
        </a>
      </p>

      <p style="margin:12px 0 0 0;color:#6b7280;font-size:12px">
        ID de tarea: #' . (int)$idTarea . '
      </p>
    </div>
    ';

    $text = "Se te asignó una nueva tarea.\n\n"
        . "Título: {$titulo}\n"
        . ($descripcion !== '' ? "Descripción: {$descripcion}\n" : '')
        . "Ver tarea: {$url}\n"
        . "ID de tarea: #{$idTarea}\n";

    return send_mail_hostinger([
        'to'      => $correo,
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text
    ]);
}

function nt_send_participante_agregado(
    mysqli $conn,
    int $idTarea,
    int $idParticipante,
    int $idActor
): array {
    if ($idTarea <= 0 || $idParticipante <= 0) {
        return ['ok' => true, 'skip' => 'datos_incompletos'];
    }

    // Evitar mail espejo
    if ($idParticipante === $idActor) {
        return ['ok' => true, 'skip' => 'actor_es_participante'];
    }

    $participante = nt_get_usuario($conn, $idParticipante);
    $actor        = nt_get_usuario($conn, $idActor);
    $tarea        = nt_get_tarea_basica($conn, $idTarea);

    if (!$participante) {
        return ['ok' => false, 'error' => 'No se encontró participante'];
    }

    if (!$tarea) {
        return ['ok' => false, 'error' => 'No se encontró tarea'];
    }

    $correo = trim((string)($participante['correo'] ?? ''));
    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => true, 'skip' => 'participante_sin_correo'];
    }

    $actorNombre = trim((string)($actor['nombre'] ?? 'Sistema'));
    $titulo = (string)($tarea['titulo'] ?? '');
    $descripcion = (string)($tarea['descripcion'] ?? '');

    $tituloSafe = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    $descSafe   = nl2br(htmlspecialchars($descripcion, ENT_QUOTES, 'UTF-8'));

    $url = nt_base_url() . '/tarea_detalle.php?id=' . $idTarea;
    $subject = "Te agregaron como participante: {$titulo}";

    $html = '
    <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.5">
      <h2 style="margin:0 0 12px 0;color:#111">Te agregaron como participante</h2>

      <p style="margin:0 0 10px 0">
        Hola <b>' . htmlspecialchars($participante['nombre'], ENT_QUOTES, 'UTF-8') . '</b>,
        <br>
        <b>' . htmlspecialchars($actorNombre, ENT_QUOTES, 'UTF-8') . '</b> te agregó como participante en una tarea del Tablero de Operación.
      </p>

      <div style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin:12px 0">
        <div style="font-size:12px;color:#6b7280;text-transform:uppercase;margin-bottom:6px">Título</div>
        <div style="font-size:16px;font-weight:700;margin-bottom:10px">' . $tituloSafe . '</div>

        ' . ($descripcion !== '' ? '
        <div style="font-size:12px;color:#6b7280;text-transform:uppercase;margin-bottom:6px">Descripción</div>
        <div style="font-size:14px;color:#333">' . $descSafe . '</div>
        ' : '') . '
      </div>

      <p style="margin:14px 0">
        <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#198754;color:#fff;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:700">
          Ver tarea
        </a>
      </p>

      <p style="margin:12px 0 0 0;color:#6b7280;font-size:12px">
        ID de tarea: #' . (int)$idTarea . '
      </p>
    </div>
    ';

    $text = "Te agregaron como participante en una tarea.\n\n"
        . "Título: {$titulo}\n"
        . ($descripcion !== '' ? "Descripción: {$descripcion}\n" : '')
        . "Ver tarea: {$url}\n"
        . "ID de tarea: #{$idTarea}\n";

    return send_mail_hostinger([
        'to'      => $correo,
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text
    ]);
}