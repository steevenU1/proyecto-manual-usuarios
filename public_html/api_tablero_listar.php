<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
  echo json_encode(['ok'=>false,'error'=>'No autenticado']);
  exit();
}

require_once __DIR__ . '/db.php';
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

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rol        = (string)($_SESSION['rol'] ?? '');
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

$propiedad = (string)($_SESSION['propiedad'] ?? $_SESSION['empresa'] ?? 'Luga');
$propiedad = ($propiedad === 'Nano' || $propiedad === 'Luga') ? $propiedad : 'Luga';

$idSubdis = isset($_SESSION['id_subdis']) ? (int)$_SESSION['id_subdis'] : null;

// filtros
$buscar      = trim((string)($_GET['buscar'] ?? ''));
$prioridad   = trim((string)($_GET['prioridad'] ?? ''));
$solo        = trim((string)($_GET['solo'] ?? ''));
$responsable = trim((string)($_GET['responsable'] ?? ''));
$visibilidad = trim((string)($_GET['visibilidad'] ?? ''));

try {
  $hasIdSubdis = hasColumn($conn, 'tablero_tareas', 'id_subdis');

  $where = [];
  $params = [];
  $types = '';

  // Siempre filtrar por propiedad
  $where[] = "t.propiedad = ?";
  $params[] = $propiedad;
  $types .= 's';

  // Solo si existe la columna id_subdis
  if ($hasIdSubdis && $idSubdis !== null) {
    $where[] = "(t.id_subdis IS NULL OR t.id_subdis = ?)";
    $params[] = $idSubdis;
    $types .= 'i';
  }

  // Ya no dar privilegios especiales para mover/comportamiento,
  // pero para LISTAR sí puedes dejar que admin vea todo si así lo quieres.
  // Si NO quieres eso, quita este bloque y aplica la misma regla general para todos.
  $isAdmin = ($rol === 'Admin' || $rol === 'SuperAdmin');

  if (!$isAdmin) {
    $where[] = "(
      t.id_creador = ?
      OR t.id_responsable = ?
      OR EXISTS (
        SELECT 1
        FROM tablero_watchers wperm
        WHERE wperm.id_tarea = t.id
          AND wperm.id_usuario = ?
      )
      OR (t.visibilidad = 'Sucursal' AND t.id_sucursal = ?)
      OR (t.visibilidad = 'Empresa')
    )";
    $params[] = $idUsuario;  $types .= 'i';
    $params[] = $idUsuario;  $types .= 'i';
    $params[] = $idUsuario;  $types .= 'i';
    $params[] = $idSucursal; $types .= 'i';
  }

  // Filtros UI
  if ($buscar !== '') {
    $where[] = "(t.titulo LIKE ? OR t.descripcion LIKE ?)";
    $like = "%{$buscar}%";
    $params[] = $like; $types .= 's';
    $params[] = $like; $types .= 's';
  }

  if ($prioridad !== '') {
    $where[] = "t.prioridad = ?";
    $params[] = $prioridad; $types .= 's';
  }

  if ($visibilidad !== '') {
    $where[] = "t.visibilidad = ?";
    $params[] = $visibilidad; $types .= 's';
  }

  if ($responsable !== '' && ctype_digit($responsable)) {
    $where[] = "t.id_responsable = ?";
    $params[] = (int)$responsable; $types .= 'i';
  }

  if ($solo === 'mias') {
    $where[] = "t.id_creador = ?";
    $params[] = $idUsuario; $types .= 'i';
  } elseif ($solo === 'asignadas') {
    $where[] = "t.id_responsable = ?";
    $params[] = $idUsuario; $types .= 'i';
  } elseif ($solo === 'vencidas') {
    $where[] = "t.fecha_estimada IS NOT NULL AND t.estatus <> 'Terminado' AND t.fecha_estimada < CURDATE()";
  }

  $sql = "
    SELECT
      t.id,
      t.titulo,
      t.descripcion,
      t.estatus,
      t.prioridad,
      DATE_FORMAT(t.fecha_inicio, '%Y-%m-%d') AS fecha_inicio,
      DATE_FORMAT(t.fecha_estimada, '%Y-%m-%d') AS fecha_estimada,
      DATE_FORMAT(t.fecha_fin, '%Y-%m-%d') AS fecha_fin,
      t.depende_de,
      t.visibilidad,
      t.id_creador,
      t.id_responsable,
      u.nombre AS responsable_nombre,
      COALESCE(wp.participantes_nombres, '') AS participantes_nombres
    FROM tablero_tareas t
    LEFT JOIN usuarios u
      ON u.id = t.id_responsable
    LEFT JOIN (
      SELECT
        tw.id_tarea,
        GROUP_CONCAT(DISTINCT ux.nombre ORDER BY ux.nombre SEPARATOR '|') AS participantes_nombres
      FROM tablero_watchers tw
      INNER JOIN usuarios ux
        ON ux.id = tw.id_usuario
      GROUP BY tw.id_tarea
    ) wp
      ON wp.id_tarea = t.id
    ".(count($where) ? "WHERE ".implode(" AND ", $where) : "")."
    ORDER BY
      FIELD(t.prioridad,'Urgente','Alta','Media','Baja'),
      (t.fecha_estimada IS NULL), t.fecha_estimada ASC,
      t.created_at DESC
    LIMIT 800
  ";

  $stmt = $conn->prepare($sql);
  if (!$stmt) {
    throw new Exception("Error al preparar consulta: " . $conn->error);
  }

  if ($types !== '') {
    $stmt->bind_param($types, ...$params);
  }

  $stmt->execute();
  $res = $stmt->get_result();

  $items = [];
  while ($row = $res->fetch_assoc()) {
    $items[] = [
      'id'                    => (int)$row['id'],
      'titulo'                => (string)($row['titulo'] ?? ''),
      'descripcion'           => (string)($row['descripcion'] ?? ''),
      'estatus'               => (string)($row['estatus'] ?? ''),
      'prioridad'             => (string)($row['prioridad'] ?? ''),
      'fecha_inicio'          => $row['fecha_inicio'],
      'fecha_estimada'        => $row['fecha_estimada'],
      'fecha_fin'             => $row['fecha_fin'],
      'depende_de'            => $row['depende_de'] !== null ? (int)$row['depende_de'] : null,
      'visibilidad'           => (string)($row['visibilidad'] ?? ''),
      'id_creador'            => (int)$row['id_creador'],
      'id_responsable'        => $row['id_responsable'] !== null ? (int)$row['id_responsable'] : null,
      'responsable_nombre'    => (string)($row['responsable_nombre'] ?? ''),
      'participantes_nombres' => (string)($row['participantes_nombres'] ?? '')
    ];
  }

  echo json_encode([
    'ok'    => true,
    'items' => $items
  ], JSON_UNESCAPED_UNICODE);
  exit();

} catch (Throwable $e) {
  echo json_encode([
    'ok'    => false,
    'error' => $e->getMessage()
  ], JSON_UNESCAPED_UNICODE);
  exit();
}