<?php
// recargas_base_generar.php — INSERT...SELECT desde ventas
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

$ROL = $_SESSION['rol'] ?? '';
if (!in_array($ROL, ['Admin','Logistica'], true)) { header("Location: 403.php"); exit(); }

$id_promo = (int)($_POST['id_promo'] ?? 0);
if ($id_promo <= 0) { header("Location: recargas_admin.php"); exit(); }

// Traer rango y origen
$promo = $conn->query("SELECT * FROM recargas_promo WHERE id=$id_promo")->fetch_assoc();
if (!$promo) { header("Location: recargas_admin.php"); exit(); }

$fi = $promo['fecha_inicio'];
$ff = $promo['fecha_fin'];

// Tip: si quieres excluir SIMs/Modems, aquí agregas tus condiciones extra a ventas.
$sql = "
INSERT IGNORE INTO recargas_promo_clientes
(id_promo, id_venta, id_sucursal, nombre_cliente, telefono_cliente, fecha_venta)
SELECT
  $id_promo,
  v.id,
  v.id_sucursal,
  COALESCE(NULLIF(TRIM(v.nombre_cliente),''),'Cliente') AS nombre_cliente,
  TRIM(v.telefono_cliente) AS telefono_cliente,
  v.fecha_venta
FROM ventas v
WHERE DATE(v.fecha_venta) BETWEEN '$fi' AND '$ff'
  AND TRIM(v.telefono_cliente) <> ''
  AND TRIM(v.telefono_cliente) IS NOT NULL
";
$ok = $conn->query($sql);

$ins = $conn->affected_rows;
$_SESSION['flash'] = $ok
  ? "✅ Base actualizada. Filas insertadas nuevas: $ins"
  : "⚠️ Sin cambios o error: ".$conn->error;

header("Location: recargas_admin.php");
