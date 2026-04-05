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

function tableExists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $sql = "SELECT 1
          FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$t}'
          LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

$propiedad = (string)($_SESSION['propiedad'] ?? $_SESSION['empresa'] ?? 'Luga');
$propiedad = ($propiedad === 'Nano' || $propiedad === 'Luga') ? $propiedad : 'Luga';

$idSubdis = isset($_SESSION['id_subdis']) ? (int)$_SESSION['id_subdis'] : null;

$id = (isset($_POST['id']) && ctype_digit((string)$_POST['id'])) ? (int)$_POST['id'] : 0;
$estatus = trim((string)($_POST['estatus'] ?? ''));

if ($id <= 0) {
  echo json_encode(['ok'=>false,'error'=>'ID inválido'], JSON_UNESCAPED_UNICODE);
  exit();
}

$valid = ['Pendiente','En proceso','Bloqueado','Terminado'];
if (!in_array($estatus, $valid, true)) {
  echo json_encode(['ok'=>false,'error'=>'Estatus inválido'], JSON_UNESCAPED_UNICODE);
  exit();
}

try {
  $hasIdSubdis = hasColumn($conn, 'tablero_tareas', 'id_subdis');

  // Tabla de participantes soportada
  $tblPart = null;
  if (tableExists($conn, 'tablero_participantes')) {
    $tblPart = 'tablero_participantes';
  } elseif (tableExists($conn, 'tablero_watchers')) {
    $tblPart = 'tablero_watchers';
  }

  // Cargar tarea
  if ($hasIdSubdis) {
    $sql = "
      SELECT
        id,
        id_creador,
        id_responsable,
        visibilidad,
        id_sucursal,
        propiedad,
        id_subdis,
        depende_de,
        estatus AS estatus_actual
      FROM tablero_tareas
      WHERE id = ?
      LIMIT 1
    ";
  } else {
    $sql = "
      SELECT
        id,
        id_creador,
        id_responsable,
        visibilidad,
        id_sucursal,
        propiedad,
        depende_de,
        estatus AS estatus_actual
      FROM tablero_tareas
      WHERE id = ?
      LIMIT 1
    ";
  }

  $st = $conn->prepare($sql);
  if (!$st) {
    throw new Exception('No se pudo preparar la consulta de tarea');
  }

  $st->bind_param("i", $id);
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

  $esCreador = ((int)$t['id_creador'] === $idUsuario);
  $esResp    = ((int)($t['id_responsable'] ?? 0) === $idUsuario);

  // ¿Es participante?
  $esParticipante = false;
  if ($tblPart) {
    $qp = $conn->prepare("SELECT 1 FROM {$tblPart} WHERE id_tarea = ? AND id_usuario = ? LIMIT 1");
    if (!$qp) {
      throw new Exception('No se pudo preparar la consulta de participantes');
    }

    $qp->bind_param("ii", $id, $idUsuario);
    $qp->execute();
    $esParticipante = (bool)$qp->get_result()->fetch_row();
    $qp->close();
  }

  // Permisos para mover: SOLO creador, responsable o participante
  $allowed = $esCreador || $esResp || $esParticipante;

  if (!$allowed) {
    echo json_encode(['ok'=>false,'error'=>'Sin permiso para mover esta tarea'], JSON_UNESCAPED_UNICODE);
    exit();
  }

  // Si intenta terminar, la dependencia debe estar terminada
  if ($estatus === 'Terminado' && !empty($t['depende_de'])) {
    $depId = (int)$t['depende_de'];

    if ($hasIdSubdis) {
      $sd = $conn->prepare("
        SELECT estatus, propiedad, id_subdis
        FROM tablero_tareas
        WHERE id = ?
        LIMIT 1
      ");
    } else {
      $sd = $conn->prepare("
        SELECT estatus, propiedad
        FROM tablero_tareas
        WHERE id = ?
        LIMIT 1
      ");
    }

    if (!$sd) {
      throw new Exception('No se pudo preparar la consulta de dependencia');
    }

    $sd->bind_param("i", $depId);
    $sd->execute();
    $dep = $sd->get_result()->fetch_assoc();
    $sd->close();

    if ($dep) {
      if (($dep['propiedad'] ?? '') !== $propiedad) {
        echo json_encode(['ok'=>false,'error'=>'Dependencia no válida para esta central'], JSON_UNESCAPED_UNICODE);
        exit();
      }

      if ($hasIdSubdis && $idSubdis !== null && array_key_exists('id_subdis', $dep) && $dep['id_subdis'] !== null) {
        if ((int)$dep['id_subdis'] !== $idSubdis) {
          echo json_encode(['ok'=>false,'error'=>'Dependencia no válida para esta central'], JSON_UNESCAPED_UNICODE);
          exit();
        }
      }

      if (($dep['estatus'] ?? '') !== 'Terminado') {
        echo json_encode(['ok'=>false,'error'=>'No se puede terminar: depende de una tarea no terminada'], JSON_UNESCAPED_UNICODE);
        exit();
      }
    }
  }

  $fechaFin = ($estatus === 'Terminado') ? date('Y-m-d') : null;

  $up = $conn->prepare("
    UPDATE tablero_tareas
    SET estatus = ?, fecha_fin = ?
    WHERE id = ?
    LIMIT 1
  ");
  if (!$up) {
    throw new Exception('No se pudo preparar la actualización');
  }

  $up->bind_param("ssi", $estatus, $fechaFin, $id);
  $up->execute();
  $up->close();

  echo json_encode([
    'ok' => true,
    'id' => $id,
    'estatus' => $estatus,
    'fecha_fin' => $fechaFin,
    'permisos' => [
      'es_creador' => $esCreador,
      'es_responsable' => $esResp,
      'es_participante' => $esParticipante
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