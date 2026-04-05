<?php
// api_usuarios_por_area.php — devuelve JSON de usuarios según área
// GET: ?id_area=3
// Respuesta: { ok:true, users:[{id,nombre,rol,id_sucursal}], mode:"filtered|all" }

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['id_usuario'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'No autenticado']);
  exit();
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$idArea = (int)($_GET['id_area'] ?? 0);

try {
  // Si no seleccionan área, mandamos todos activos
  if ($idArea <= 0) {
    $rs = $conn->query("SELECT id, nombre, rol, id_sucursal FROM usuarios WHERE activa=1 ORDER BY nombre");
    $users = [];
    while($r = $rs->fetch_assoc()) $users[] = $r;
    echo json_encode(['ok'=>true,'mode'=>'all','users'=>$users]);
    exit();
  }

  // Verificar si el área tiene asignaciones activas
  $stChk = $conn->prepare("SELECT COUNT(*) AS c FROM usuarios_areas WHERE id_area=? AND activo=1");
  $stChk->bind_param("i", $idArea);
  $stChk->execute();
  $c = (int)($stChk->get_result()->fetch_assoc()['c'] ?? 0);
  $stChk->close();

  if ($c > 0) {
    // Filtrado por área
    $st = $conn->prepare("
      SELECT u.id, u.nombre, u.rol, u.id_sucursal
      FROM usuarios u
      JOIN usuarios_areas ua ON ua.id_usuario=u.id AND ua.activo=1
      WHERE u.activa=1 AND ua.id_area=?
      ORDER BY u.nombre
    ");
    $st->bind_param("i", $idArea);
    $st->execute();
    $rs = $st->get_result();
    $users = [];
    while($r = $rs->fetch_assoc()) $users[] = $r;
    $st->close();

    echo json_encode(['ok'=>true,'mode'=>'filtered','users'=>$users]);
    exit();
  }

  // Si no hay mapeos todavía: devolvemos todos para no bloquear
  $rs = $conn->query("SELECT id, nombre, rol, id_sucursal FROM usuarios WHERE activa=1 ORDER BY nombre");
  $users = [];
  while($r = $rs->fetch_assoc()) $users[] = $r;
  echo json_encode(['ok'=>true,'mode'=>'all','users'=>$users]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
