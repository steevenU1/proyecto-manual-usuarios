<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
  echo json_encode(['ok'=>false,'error'=>'No autenticado'], JSON_UNESCAPED_UNICODE);
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/notificaciones_tablero.php';
date_default_timezone_set('America/Mexico_City');

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

function cleanDateOrEmpty($d): string {
  $d = trim((string)$d);
  if ($d === '') return '';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return '';
  return $d;
}

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

$propiedad = (string)($_SESSION['propiedad'] ?? $_SESSION['empresa'] ?? 'Luga');
$propiedad = ($propiedad === 'Nano' || $propiedad === 'Luga') ? $propiedad : 'Luga';

$idSubdis = isset($_SESSION['id_subdis']) ? (int)$_SESSION['id_subdis'] : 0; // 0 = NULLIF

$titulo = trim((string)($_POST['titulo'] ?? ''));
$descripcion = trim((string)($_POST['descripcion'] ?? ''));

$idResponsable = (isset($_POST['id_responsable']) && ctype_digit((string)$_POST['id_responsable']))
  ? (int)$_POST['id_responsable'] : 0;

$prioridad = trim((string)($_POST['prioridad'] ?? 'Media'));
$visibilidad = trim((string)($_POST['visibilidad'] ?? 'Privada'));

$fechaInicio = cleanDateOrEmpty($_POST['fecha_inicio'] ?? '');
$fechaEstimada = cleanDateOrEmpty($_POST['fecha_estimada'] ?? '');

$dependeDe = (isset($_POST['depende_de']) && ctype_digit((string)$_POST['depende_de']))
  ? (int)$_POST['depende_de'] : 0;

$notificar = isset($_POST['notificar']) ? 1 : 0;

if ($titulo === '') {
  echo json_encode(['ok'=>false,'error'=>'Título requerido'], JSON_UNESCAPED_UNICODE);
  exit();
}

if (!in_array($prioridad, ['Baja','Media','Alta','Urgente'], true)) {
  $prioridad = 'Media';
}

if (!in_array($visibilidad, ['Privada','Sucursal','Empresa'], true)) {
  $visibilidad = 'Privada';
}

$idSuc = ($visibilidad === 'Sucursal') ? $idSucursal : 0;

try {
  $hasIdSubdis = hasColumn($conn, 'tablero_tareas', 'id_subdis');

  if ($hasIdSubdis) {
    $sql = "INSERT INTO tablero_tareas
      (titulo, descripcion, id_creador, id_responsable, estatus, prioridad,
       fecha_inicio, fecha_estimada, depende_de, visibilidad, id_sucursal, propiedad, id_subdis, notificar)
      VALUES
      (?, ?, ?, NULLIF(?,0), 'Pendiente', ?,
       NULLIF(?,''), NULLIF(?,''), NULLIF(?,0), ?, NULLIF(?,0), ?, NULLIF(?,0), ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      throw new Exception('No se pudo preparar el insert: ' . $conn->error);
    }

    // s,s,i,i,s,s,s,i,s,i,s,i,i
    $types = "ssiisssisisii";

    $stmt->bind_param(
      $types,
      $titulo,
      $descripcion,
      $idUsuario,
      $idResponsable,
      $prioridad,
      $fechaInicio,
      $fechaEstimada,
      $dependeDe,
      $visibilidad,
      $idSuc,
      $propiedad,
      $idSubdis,
      $notificar
    );
  } else {
    $sql = "INSERT INTO tablero_tareas
      (titulo, descripcion, id_creador, id_responsable, estatus, prioridad,
       fecha_inicio, fecha_estimada, depende_de, visibilidad, id_sucursal, propiedad, notificar)
      VALUES
      (?, ?, ?, NULLIF(?,0), 'Pendiente', ?,
       NULLIF(?,''), NULLIF(?,''), NULLIF(?,0), ?, NULLIF(?,0), ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      throw new Exception('No se pudo preparar el insert: ' . $conn->error);
    }

    // s,s,i,i,s,s,s,i,s,i,s,i
    $types = "ssiisssisisi";

    $stmt->bind_param(
      $types,
      $titulo,
      $descripcion,
      $idUsuario,
      $idResponsable,
      $prioridad,
      $fechaInicio,
      $fechaEstimada,
      $dependeDe,
      $visibilidad,
      $idSuc,
      $propiedad,
      $notificar
    );
  }

  if (!$stmt->execute()) {
    throw new Exception('No se pudo ejecutar el insert: ' . $stmt->error);
  }

  $idInsertado = (int)$stmt->insert_id;
  $stmt->close();

  $mailResult = ['ok' => true, 'skip' => 'not_disabled'];

  if ($notificar === 1 && $idInsertado > 0 && $idResponsable > 0) {
    $mailResult = nt_send_tarea_creada(
      $conn,
      $idInsertado,
      $idResponsable,
      $idUsuario,
      $titulo,
      $descripcion
    );
  }

  echo json_encode([
    'ok' => true,
    'id' => $idInsertado,
    'mail' => $mailResult
  ], JSON_UNESCAPED_UNICODE);
  exit();

} catch (Throwable $e) {
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
  exit();
}