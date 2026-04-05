<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
  echo json_encode(['ok'=>false,'error'=>'No autenticado'], JSON_UNESCAPED_UNICODE);
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/notificaciones_tablero.php';

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

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);

$propiedad = (string)($_SESSION['propiedad'] ?? $_SESSION['empresa'] ?? 'Luga');
$propiedad = ($propiedad === 'Nano' || $propiedad === 'Luga') ? $propiedad : 'Luga';

$idSubdis = isset($_SESSION['id_subdis']) ? (int)$_SESSION['id_subdis'] : null;

$idTarea = isset($_POST['id_tarea']) && ctype_digit((string)$_POST['id_tarea']) ? (int)$_POST['id_tarea'] : 0;
$idU     = isset($_POST['id_usuario']) && ctype_digit((string)$_POST['id_usuario']) ? (int)$_POST['id_usuario'] : 0;
$accion  = trim((string)($_POST['accion'] ?? ''));

if ($idTarea <= 0 || $idU <= 0) {
  echo json_encode(['ok'=>false,'error'=>'Datos inválidos'], JSON_UNESCAPED_UNICODE);
  exit();
}

if (!in_array($accion, ['add','remove'], true)) {
  echo json_encode(['ok'=>false,'error'=>'Acción inválida'], JSON_UNESCAPED_UNICODE);
  exit();
}

$hasIdSubdis = hasColumn($conn, 'tablero_tareas', 'id_subdis');
$hasUsuariosPropiedad = hasColumn($conn, 'usuarios', 'propiedad');
$hasUsuariosSubdis    = hasColumn($conn, 'usuarios', 'id_subdis');

try {
  // 1) cargar tarea para validar permisos
  $sqlT = $hasIdSubdis
    ? "SELECT id, propiedad, id_subdis, id_creador, id_responsable
       FROM tablero_tareas
       WHERE id = ?
       LIMIT 1"
    : "SELECT id, propiedad, id_creador, id_responsable
       FROM tablero_tareas
       WHERE id = ?
       LIMIT 1";

  $st = $conn->prepare($sqlT);
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

  // 2) solo creador o responsable pueden editar participantes
  $puede = ((int)$t['id_creador'] === $idUsuario)
    || ((int)($t['id_responsable'] ?? 0) === $idUsuario);

  if (!$puede) {
    echo json_encode(['ok'=>false,'error'=>'Sin permiso para editar participantes'], JSON_UNESCAPED_UNICODE);
    exit();
  }

  // 3) validar que el usuario objetivo exista y corresponda a la misma propiedad/contexto
  $whereUsuario = ["id = ?"];
  $paramsU = [$idU];
  $typesU = "i";

  if ($hasUsuariosPropiedad) {
    $whereUsuario[] = "propiedad = ?";
    $paramsU[] = $propiedad;
    $typesU .= "s";
  }

  if ($hasUsuariosSubdis && $idSubdis !== null) {
    $whereUsuario[] = "(id_subdis IS NULL OR id_subdis = ?)";
    $paramsU[] = $idSubdis;
    $typesU .= "i";
  }

  $sqlU = "SELECT id, nombre
           FROM usuarios
           WHERE " . implode(" AND ", $whereUsuario) . "
           LIMIT 1";

  $su = $conn->prepare($sqlU);
  if (!$su) {
    throw new Exception('No se pudo preparar la consulta del usuario');
  }

  $su->bind_param($typesU, ...$paramsU);
  $su->execute();
  $u = $su->get_result()->fetch_assoc();
  $su->close();

  if (!$u) {
    echo json_encode(['ok'=>false,'error'=>'Usuario no válido para esta central'], JSON_UNESCAPED_UNICODE);
    exit();
  }

  // 4) ejecutar acción
  if ($accion === 'add') {
    $ins = $conn->prepare("
      INSERT IGNORE INTO tablero_watchers (id_tarea, id_usuario)
      VALUES (?, ?)
    ");
    if (!$ins) {
      throw new Exception('No se pudo preparar el alta de participante');
    }

    $ins->bind_param("ii", $idTarea, $idU);
    $ins->execute();
    $ins->close();

    $mailResult = nt_send_participante_agregado(
      $conn,
      $idTarea,
      $idU,
      $idUsuario
    );

    echo json_encode([
      'ok' => true,
      'accion' => 'add',
      'id_tarea' => $idTarea,
      'id_usuario' => $idU,
      'nombre' => (string)($u['nombre'] ?? ''),
      'mail' => $mailResult
    ], JSON_UNESCAPED_UNICODE);
    exit();

  } else {
    $del = $conn->prepare("
      DELETE FROM tablero_watchers
      WHERE id_tarea = ?
        AND id_usuario = ?
      LIMIT 1
    ");
    if (!$del) {
      throw new Exception('No se pudo preparar la baja de participante');
    }

    $del->bind_param("ii", $idTarea, $idU);
    $del->execute();
    $del->close();

    echo json_encode([
      'ok' => true,
      'accion' => 'remove',
      'id_tarea' => $idTarea,
      'id_usuario' => $idU,
      'nombre' => (string)($u['nombre'] ?? '')
    ], JSON_UNESCAPED_UNICODE);
    exit();
  }

} catch (Throwable $e) {
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
  exit();
}