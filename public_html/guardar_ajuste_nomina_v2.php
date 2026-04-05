<?php
// guardar_ajuste_nomina_v2.php — Guarda Bono/Ajuste por semana y usuario
session_start();
if (!isset($_SESSION['id_usuario'])) { http_response_code(401); exit('No auth'); }

require_once __DIR__ . '/db.php';

$creado_por = (int)($_SESSION['id_usuario']);
$uid  = (int)($_POST['uid'] ?? 0);
$tipo = $_POST['tipo'] ?? ''; // 'bono' | 'ajuste'
$monto = (float)($_POST['monto'] ?? 0);
$ini = $_POST['ini'] ?? '';
$fin = $_POST['fin'] ?? '';

if (!$uid || !$ini || !$fin || !in_array($tipo, ['bono','ajuste'],true)) {
  http_response_code(400); exit('Bad request');
}

// Crear tabla si no existe
$conn->query("
CREATE TABLE IF NOT EXISTS nomina_ajustes_v2 (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario INT NOT NULL,
  semana_inicio DATE NOT NULL,
  semana_fin DATE NOT NULL,
  tipo ENUM('bono','ajuste') NOT NULL,
  monto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  nota VARCHAR(200),
  creado_por INT,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_semana (semana_inicio, semana_fin),
  KEY idx_user (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Guardamos como UPSERT por semana/usuario/tipo (acumula reemplazando)
$stmt = $conn->prepare("
  SELECT id FROM nomina_ajustes_v2
  WHERE id_usuario=? AND semana_inicio=? AND semana_fin=? AND tipo=? LIMIT 1
");
$stmt->bind_param("isss", $uid, $ini, $fin, $tipo);
$stmt->execute();
$ex = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($ex) {
  $stmt = $conn->prepare("UPDATE nomina_ajustes_v2 SET monto=?, creado_por=? WHERE id=?");
  $stmt->bind_param("dii", $monto, $creado_por, $ex['id']);
  $stmt->execute(); $stmt->close();
} else {
  $stmt = $conn->prepare("
    INSERT INTO nomina_ajustes_v2 (id_usuario, semana_inicio, semana_fin, tipo, monto, creado_por)
    VALUES (?,?,?,?,?,?)
  ");
  $stmt->bind_param("isssdi", $uid, $ini, $fin, $tipo, $monto, $creado_por);
  $stmt->execute(); $stmt->close();
}

header('Content-Type: application/json');
echo json_encode(['ok'=>true]);
