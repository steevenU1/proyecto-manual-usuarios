<?php
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../portal_notificaciones.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'method_not_allowed'], JSON_UNESCAPED_UNICODE);
  exit;
}

function out($ok, $data=[]){
  echo json_encode(array_merge(['ok'=>$ok], $data), JSON_UNESCAPED_UNICODE);
  exit;
}
function bad($msg, $code=400){
  http_response_code($code);
  out(false, ['error'=>$msg]);
}
function get_json(){
  $raw = file_get_contents('php://input');
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}
function htrim($s, $max=2000){
  $s = trim((string)$s);
  if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
  return $s;
}

$origen = require_origen(['NANO','MIPLAN','LUGA']);

$in = get_json();
$solId  = (int)($in['id'] ?? 0);
$accion = strtoupper(trim((string)($in['accion'] ?? ''))); // AUTORIZAR | RECHAZAR
$coment = htrim($in['comentario'] ?? '', 2000);

if ($solId <= 0) bad('id_invalido');
if (!in_array($accion, ['AUTORIZAR','RECHAZAR'], true)) bad('accion_invalida');

$next = null;
$costoMxn = null;

try {
  $conn->begin_transaction();

  $stmt = $conn->prepare("
    SELECT s.id, s.estatus, s.costo_mxn, e.clave empresa_clave
    FROM portal_proyectos_solicitudes s
    JOIN portal_empresas e ON e.id = s.empresa_id
    WHERE s.id=? LIMIT 1 FOR UPDATE
  ");
  $stmt->bind_param("i", $solId);
  $stmt->execute();
  $sol = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$sol) throw new Exception("no_existe");
  if ((string)$sol['empresa_clave'] !== $origen) throw new Exception("origen_no_corresponde");
  if ((string)$sol['estatus'] !== 'EN_AUTORIZACION_SOLICITANTE') throw new Exception("estatus_invalido");
  if ($sol['costo_mxn'] === null || (float)$sol['costo_mxn'] <= 0) throw new Exception("sin_costo");

  $prev = (string)$sol['estatus'];
  $next = ($accion === 'AUTORIZAR') ? 'AUTORIZADO' : 'RECHAZADO';
  $costoMxn = (float)$sol['costo_mxn'];

  $up = $conn->prepare("UPDATE portal_proyectos_solicitudes SET estatus=? WHERE id=? LIMIT 1");
  $up->bind_param("si", $next, $solId);
  $up->execute();
  $up->close();

  $actor = $origen . " API";
  $haccion = ($accion === 'AUTORIZAR') ? 'COSTO_AUTORIZADO_SOLICITANTE' : 'COSTO_RECHAZADO_SOLICITANTE';

  $stmtH = $conn->prepare("INSERT INTO portal_proyectos_historial
    (solicitud_id, usuario_id, actor, accion, estatus_anterior, estatus_nuevo, comentario)
    VALUES (?,?,?,?,?,?,?)");
  $u = null;
  $stmtH->bind_param("iisssss", $solId, $u, $actor, $haccion, $prev, $next, $coment);
  $stmtH->execute();
  $stmtH->close();

  $conn->commit();

} catch (Throwable $e) {
  try { $conn->rollback(); } catch (Throwable $ignore) {}
  $k = $e->getMessage();
  if ($k === 'no_existe') bad('no_existe', 404);
  if ($k === 'origen_no_corresponde') bad('origen_no_corresponde', 403);
  if ($k === 'estatus_invalido') bad('estatus_invalido', 409);
  if ($k === 'sin_costo') bad('sin_costo', 409);
  bad('error_autorizando', 500);
}

// Notificación fuera de transacción.
// Si falla el correo, NO debe romper la autorización.
try {
  portal_notify_solicitante_respuesta($conn, $solId, $accion, $coment);
} catch (Throwable $mailErr) {
  error_log('PORTAL notify solicitante_respuesta ERROR: ' . $mailErr->getMessage());
}

out(true, [
  'id'        => $solId,
  'estatus'   => $next,
  'origen'    => $origen,
  'costo_mxn' => $costoMxn
]);