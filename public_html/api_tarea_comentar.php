<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
  echo json_encode(['ok'=>false,'error'=>'No autenticado'], JSON_UNESCAPED_UNICODE);
  exit();
}

require_once __DIR__ . '/db.php';

function hasColumn(mysqli $conn, string $table, string $column): bool {
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

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

$propiedad = (string)($_SESSION['propiedad'] ?? $_SESSION['empresa'] ?? 'Luga');
$propiedad = ($propiedad === 'Nano' || $propiedad === 'Luga') ? $propiedad : 'Luga';

$idSubdis = isset($_SESSION['id_subdis']) ? (int)$_SESSION['id_subdis'] : null;

$idTarea = isset($_POST['id_tarea']) && ctype_digit((string)$_POST['id_tarea']) ? (int)$_POST['id_tarea'] : 0;
$comentario = trim((string)($_POST['comentario'] ?? ''));

if ($idTarea <= 0 || $comentario === '') {
  echo json_encode(['ok'=>false,'error'=>'Comentario requerido'], JSON_UNESCAPED_UNICODE);
  exit();
}

if (mb_strlen($comentario, 'UTF-8') > 5000) {
  echo json_encode(['ok'=>false,'error'=>'El comentario es demasiado largo'], JSON_UNESCAPED_UNICODE);
  exit();
}

$hasIdSubdis = hasColumn($conn, 'tablero_tareas', 'id_subdis');

try {
  // 1) cargar tarea
  $sql = $hasIdSubdis
    ? "SELECT id, propiedad, id_subdis, id_creador, id_responsable, visibilidad, id_sucursal
       FROM tablero_tareas
       WHERE id = ?
       LIMIT 1"
    : "SELECT id, propiedad, id_creador, id_responsable, visibilidad, id_sucursal
       FROM tablero_tareas
       WHERE id = ?
       LIMIT 1";

  $st = $conn->prepare($sql);
  if (!$st) {
    throw new Exception('No se pudo preparar la consulta de tarea');
  }

  $st->bind_param("i", $idTarea);
  $st->execute();
  $t = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$t) {
    echo json_encode(['ok'=>false,'error'=>'No existe'], JSON_UNESCAPED_UNICODE);
    exit();
  }

  if (($t['propiedad'] ?? '') !== $propiedad) {
    echo json_encode(['ok'=>false,'error'=>'No permitido'], JSON_UNESCAPED_UNICODE);
    exit();
  }

  if ($hasIdSubdis && $idSubdis !== null && array_key_exists('id_subdis', $t) && $t['id_subdis'] !== null) {
    if ((int)$t['id_subdis'] !== $idSubdis) {
      echo json_encode(['ok'=>false,'error'=>'No permitido'], JSON_UNESCAPED_UNICODE);
      exit();
    }
  }

  // 2) permiso: si puede ver, puede comentar
  $canSee = ((int)$t['id_creador'] === $idUsuario)
    || ((int)($t['id_responsable'] ?? 0) === $idUsuario);

  $isWatcher = false;
  if (!$canSee) {
    $sw = $conn->prepare("
      SELECT 1
      FROM tablero_watchers
      WHERE id_tarea = ?
        AND id_usuario = ?
      LIMIT 1
    ");
    if (!$sw) {
      throw new Exception('No se pudo preparar la consulta de watchers');
    }

    $sw->bind_param("ii", $idTarea, $idUsuario);
    $sw->execute();
    $w = $sw->get_result()->fetch_assoc();
    $sw->close();

    if ($w) {
      $isWatcher = true;
      $canSee = true;
    }
  }

  if (!$canSee) {
    if (($t['visibilidad'] ?? '') === 'Sucursal' && (int)($t['id_sucursal'] ?? 0) === $idSucursal) {
      $canSee = true;
    }
    if (($t['visibilidad'] ?? '') === 'Empresa') {
      $canSee = true;
    }
  }

  if (!$canSee) {
    echo json_encode(['ok'=>false,'error'=>'Sin acceso a esta tarea'], JSON_UNESCAPED_UNICODE);
    exit();
  }

  // 3) insertar comentario
  $ins = $conn->prepare("
    INSERT INTO tablero_comentarios (id_tarea, id_usuario, comentario)
    VALUES (?, ?, ?)
  ");
  if (!$ins) {
    throw new Exception('No se pudo preparar el insert de comentario');
  }

  $ins->bind_param("iis", $idTarea, $idUsuario, $comentario);
  $ins->execute();
  $idComentario = (int)$ins->insert_id;
  $ins->close();

  // 4) obtener nombre del usuario para devolverlo al front
  $su = $conn->prepare("SELECT nombre FROM usuarios WHERE id = ? LIMIT 1");
  if (!$su) {
    throw new Exception('No se pudo preparar la consulta del usuario');
  }

  $su->bind_param("i", $idUsuario);
  $su->execute();
  $u = $su->get_result()->fetch_assoc();
  $su->close();

  echo json_encode([
    'ok' => true,
    'comentario' => [
      'id'         => $idComentario,
      'id_tarea'   => $idTarea,
      'id_usuario' => $idUsuario,
      'nombre'     => (string)($u['nombre'] ?? ''),
      'comentario' => $comentario,
      'created_at' => date('Y-m-d H:i')
    ],
    'permisos' => [
      'puede_comentar' => true,
      'es_participante' => $isWatcher
    ]
  ], JSON_UNESCAPED_UNICODE);
  exit();

} catch (Throwable $e) {
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
  exit();
}