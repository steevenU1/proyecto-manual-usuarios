<?php
// api/tickets_since.php — Lista tickets desde una fecha, aceptando tokens de NANO y MIPLAN
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_json.php'; // si no lo usas, puedes quitarlo

header('Content-Type: application/json; charset=utf-8');

/* ============================================================
   1) Resolver ORIGEN por token usando lo que ya tienes en _auth.php
   ============================================================ */
$allowed = ['NANO','MIPLAN'];

// Si tu _auth.php expone api_origen() úsalo:
$origen = null;
if (function_exists('api_origen')) {
  $origen = api_origen(); // e.g. 'NANO' | 'MIPLAN' | 'LUGA'
} else {
  // Fallback ligero: mapear el Bearer recibido contra API_TOKENS_BY_ORIGIN
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/Bearer\s+(\S+)/', $hdr, $m)) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'missing_bearer']); exit;
  }
  $bearer = trim($m[1]);

  if (!defined('API_TOKENS_BY_ORIGIN')) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'server_auth_misconfigured']); exit;
  }
  foreach (API_TOKENS_BY_ORIGIN as $k=>$v) {
    if (trim($v) === $bearer) { $origen = $k; break; }
  }
}

if (!$origen || !in_array($origen, $allowed, true)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'invalid_token_or_origin']); exit;
}

/* ============================================================
   2) Normalizar parámetros
   ============================================================ */
$since = $_GET['since'] ?? $_POST['since'] ?? '1970-01-01T00:00:00';
$since = str_replace('T', ' ', substr((string)$since, 0, 19));
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $since)) {
  $since = '1970-01-01 00:00:00';
}
$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));

// Mapear ORIGEN (token) → valor en DB
$mapSistema = [
  'NANO'   => 'NANO',
  'MIPLAN' => 'MiPlan',
];
$sistemaDB = $mapSistema[$origen] ?? $origen;

/* ============================================================
   3) Consulta de tickets (solo del sistema del token)
   ============================================================ */
$sql = "
  SELECT id, asunto, estado, prioridad, sistema_origen, sucursal_origen_id,
         creado_por_id, created_at, updated_at
  FROM tickets
  WHERE updated_at > ?
    AND sistema_origen = ?
";
$types = 'ss';
$params = [$since, $sistemaDB];

if ($q !== '') {
  // Búsqueda simple por asunto o match exacto de ID
  $sql .= " AND (asunto LIKE CONCAT('%', ?, '%') OR id = ?)";
  $types .= 'si';
  $params[] = $q;
  $params[] = (int)$q;
}

$sql .= " ORDER BY updated_at ASC LIMIT 2000";

$stmt = $conn->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'stmt_prepare_failed']); exit;
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$tickets = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();

/* ============================================================
   4) Mensajes de esos tickets (opcional)
   ============================================================ */
$mensajes = [];
if (!empty($tickets)) {
  $ids = array_column($tickets, 'id');
  $ids = array_values(array_unique(array_map('intval', $ids)));
  if (!empty($ids)) {
    $in = implode(',', $ids);
    // Si quieres además filtrar por autor_sistema, descomenta:
    // AND autor_sistema IN ('{$conn->real_escape_string($sistemaDB)}')
    $sqlM = "
      SELECT id, ticket_id, autor_sistema, autor_id, cuerpo, created_at
      FROM ticket_mensajes
      WHERE ticket_id IN ($in)
      ORDER BY ticket_id ASC, id ASC
    ";
    if ($qMsg = $conn->query($sqlM)) {
      $mensajes = $qMsg->fetch_all(MYSQLI_ASSOC);
    }
  }
}

/* ============================================================
   5) Respuesta
   ============================================================ */
echo json_encode([
  'ok'       => true,
  'origen'   => $origen,     // 'NANO' | 'MIPLAN'
  'sistema'  => $sistemaDB,  // 'NANO' | 'MiPlan'
  'since'    => $since,
  'tickets'  => $tickets,
  'mensajes' => $mensajes,
], JSON_UNESCAPED_UNICODE);
