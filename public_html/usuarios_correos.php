<?php
// usuarios_correos.php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
  return $rs && $rs->num_rows > 0;
}

$hasCorreo = hasColumn($conn, 'usuarios', 'correo');

$where = ["u.activo = 1"];
$sql = "SELECT u.id, u.nombre, u.usuario, u.rol, u.id_sucursal, ".($hasCorreo ? "u.correo" : "NULL AS correo")." 
        FROM usuarios u WHERE ".implode(" AND ",$where)." ORDER BY u.nombre";

$rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Seguimiento correos</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
<div class="container">
<h3>Seguimiento de correos</h3>
<table class="table table-bordered">
<thead>
<tr>
<th>ID</th><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Sucursal</th><th>Correo</th><th>Estatus</th>
</tr>
</thead>
<tbody>
<?php foreach($rows as $r):
$correo = trim((string)$r['correo']);
$ok = $hasCorreo && $correo!==''; ?>
<tr>
<td><?= (int)$r['id'] ?></td>
<td><?= h($r['nombre']) ?></td>
<td><?= h($r['usuario']) ?></td>
<td><?= h($r['rol']) ?></td>
<td><?= (int)$r['id_sucursal'] ?></td>
<td><?= $ok ? h($correo) : '—' ?></td>
<td><?= $ok ? 'Capturado' : 'Pendiente' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</body>
</html>
