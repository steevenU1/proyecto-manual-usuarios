<?php
// api/tickets_get.php — Devuelve ticket + mensajes, acepta NANO y MIPLAN

require_once __DIR__.'/../db.php';
require_once __DIR__.'/_auth.php';

header('Content-Type: application/json; charset=utf-8');

/* ============================================================
   1) Resolver origen desde el token
   ============================================================ */
$allowed = ['NANO','MIPLAN'];
$origen = null;

if (function_exists('api_origen')) {
  $origen = api_origen(); // e.g. 'NANO', 'MIPLAN', etc.
} else {
  // Fallback simple si tu _auth.php no tiene api_origen()
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/Bearer\s+(\S+)/', $hdr, $m)) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'missing_bearer']); exit;
  }
  $token = trim($m[1]);
  if (defined('API_TOKENS_BY_ORIGIN')) {
    foreach (API_TOKENS_BY_ORIGIN as $k=>$v) {
      if (trim($v) === $token) { $origen = $k; break; }
    }
  }
}

if (!$origen || !in_array($origen, $allowed, true)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'invalid_token_or_origin']); exit;
}

/* ============================================================
   2) Normalizar ID
   ============================================================ */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(422);
  echo json_encode(['ok'=>false,'error'=>'id_invalido']); exit;
}

/* ============================================================
   3) Mapeo de origen a valor en la tabla
   ============================================================ */
$map = [
  'NANO'   => 'NANO',
  'MIPLAN' => 'MiPlan',
];
$sistemaDB = $map[$origen] ?? $origen;

/* ============================================================
   4) Leer ticket (solo si pertenece a ese sistema)
   ============================================================ */
$stmt = $conn->prepare("
  SELECT id, asunto, estado, prioridad, sistema_origen,
         sucursal_origen_id, creado_por_id, created_at, updated_at
  FROM tickets
  WHERE id=? AND sistema_origen=?
  LIMIT 1
");
$stmt->bind_param('is', $id, $sistemaDB);
$stmt->execute();
$t = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$t) {
  http_response_code(404);
  echo json_encode(['ok'=>false,'error'=>'no_encontrado_o_sin_permiso']); exit;
}

/* ============================================================
   5) Cargar mensajes del ticket
   ============================================================ */
$mensajes = [];
$q = $conn->prepare("
  SELECT id, ticket_id, autor_sistema, autor_id, cuerpo, created_at
  FROM ticket_mensajes
  WHERE ticket_id=?
  ORDER BY id ASC
");
$q->bind_param('i', $id);
$q->execute();
$r = $q->get_result();
while ($row = $r->fetch_assoc()) $mensajes[] = $row;
$q->close();

/* ============================================================
   6) Respuesta
   ============================================================ */
echo json_encode([
  'ok'        => true,
  'origen'    => $origen,
  'sistema'   => $sistemaDB,
  'ticket'    => $t,
  'mensajes'  => $mensajes
], JSON_UNESCAPED_UNICODE);
