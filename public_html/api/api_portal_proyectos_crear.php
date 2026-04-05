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
function htrim($s, $max=5000){
  $s = trim((string)$s);
  if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
  return $s;
}

$origen = require_origen(['NANO','MIPLAN','LUGA']);

$in = get_json();
$titulo = htrim($in['titulo'] ?? '', 180);
$desc   = htrim($in['descripcion'] ?? '', 20000);
$tipo   = htrim($in['tipo'] ?? 'Implementacion', 40);
$prio   = htrim($in['prioridad'] ?? 'Media', 15);

$solNombre = htrim($in['solicitante_nombre'] ?? '', 120);
$solCorreo = htrim($in['solicitante_correo'] ?? '', 120);

if ($titulo === '' || mb_strlen($titulo) < 5) bad('titulo_invalido');
if ($desc === '' || mb_strlen($desc) < 10) bad('descripcion_invalida');

$prioAllowed = ['Baja','Media','Alta','Urgente'];
if (!in_array($prio, $prioAllowed, true)) $prio = 'Media';

$stmtE = $conn->prepare("SELECT id FROM portal_empresas WHERE clave=? AND activa=1 LIMIT 1");
$stmtE->bind_param("s", $origen);
$stmtE->execute();
$rowE = $stmtE->get_result()->fetch_assoc();
$stmtE->close();
if (!$rowE) bad('empresa_no_configurada', 500);
$empresaId = (int)$rowE['id'];

$solId   = 0;
$folio   = '';
$estatus = 'EN_VALORACION_SISTEMAS';

try {
  $conn->begin_transaction();

  $year = date('Y');

  $stmtL = $conn->prepare("
    SELECT folio FROM portal_proyectos_solicitudes
    WHERE folio LIKE CONCAT('PRJ-',?,'-%')
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

  $usuarioSolic = null; // externo por API
  $ins = $conn->prepare("INSERT INTO portal_proyectos_solicitudes
    (folio, empresa_id, usuario_solicitante_id, solicitante_nombre, solicitante_correo, titulo, descripcion, tipo, prioridad, estatus)
    VALUES (?,?,?,?,?,?,?,?,?,?)");
  $ins->bind_param("siisssssss", $folio, $empresaId, $usuarioSolic, $solNombre, $solCorreo, $titulo, $desc, $tipo, $prio, $estatus);
  $ins->execute();
  $solId = (int)$conn->insert_id;
  $ins->close();

  $accion = "CREADA";
  $actor  = $origen . " API";
  $prev   = null;
  $coment = "Solicitud creada desde $origen";

  $stmtH = $conn->prepare("INSERT INTO portal_proyectos_historial
    (solicitud_id, usuario_id, actor, accion, estatus_anterior, estatus_nuevo, comentario)
    VALUES (?,?,?,?,?,?,?)");
  $u = null;
  $stmtH->bind_param("iisssss", $solId, $u, $actor, $accion, $prev, $estatus, $coment);
  $stmtH->execute();
  $stmtH->close();

  $conn->commit();

} catch (Throwable $e) {
  try { $conn->rollback(); } catch (Throwable $ignore) {}
  error_log('PORTAL crear solicitud ERROR: ' . $e->getMessage());
  bad('error_creando', 500);
}

// Notificación fuera de la transacción.
// Si falla el correo, NO debe romper la creación.
try {
  portal_notify_nueva_solicitud($conn, $solId);
} catch (Throwable $mailErr) {
  error_log('PORTAL notify nueva solicitud ERROR: ' . $mailErr->getMessage());
}

out(true, ['id'=>$solId, 'folio'=>$folio, 'estatus'=>$estatus, 'origen'=>$origen]);