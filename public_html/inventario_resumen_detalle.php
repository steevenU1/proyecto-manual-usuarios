<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { 
  http_response_code(403); 
  exit('No autorizado'); 
}
require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=UTF-8');

$marca     = $_GET['marca']     ?? '';
$modelo    = $_GET['modelo']    ?? '';
$capacidad = $_GET['capacidad'] ?? '';
$sucursal  = (int)($_GET['sucursal'] ?? 0);
$tipo      = $_GET['tipo']      ?? ''; // Puede ser Equipo, Modem, MiFi, Accesorio, etc.

$marca     = trim($marca);
$modelo    = trim($modelo);
$capacidad = trim($capacidad);
$tipo      = trim($tipo);

if ($marca === '' || $modelo === '' || $capacidad === '') {
  echo '<div class="mini">Parámetros incompletos</div>'; 
  exit;
}

/*
  Lógica de cantidad:
  - Si el producto NO tiene IMEI (accesorios no serializados), usamos inventario.cantidad.
  - Si sí tiene IMEI (equipos / módems / MiFi), cada fila cuenta como 1.
  Además, solo consideramos registros con COALESCE(i.cantidad,0) > 0,
  igual que en inventario_global e inventario_resumen.
*/

$sql = "
  SELECT 
    s.id      AS id_sucursal,
    s.nombre  AS sucursal,
    COALESCE(NULLIF(p.color,''),'N/D') AS color,
    SUM(
      CASE 
        WHEN (p.imei1 IS NULL OR p.imei1 = '') THEN COALESCE(i.cantidad,0)
        ELSE 1
      END
    ) AS piezas
  FROM inventario i
  INNER JOIN productos  p ON p.id = i.id_producto
  INNER JOIN sucursales s ON s.id = i.id_sucursal
  WHERE i.estatus IN ('Disponible','En tránsito')
    AND COALESCE(i.cantidad,0) > 0
    AND p.marca = ?
    AND p.modelo = ?
    AND p.capacidad = ?
";

$params = [];
$types  = '';

$params[] = $marca;   $types .= 's';
$params[] = $modelo;  $types .= 's';
$params[] = $capacidad; $types .= 's';

/* Filtrar por tipo_producto si viene desde el resumen
   (Equipo / Modem / Módem / MiFi / Accesorio / etc.) */
if ($tipo !== '') {
  $sql .= " AND p.tipo_producto = ? ";
  $params[] = $tipo;
  $types   .= 's';
}

/* Filtrar por sucursal específica si se solicitó */
if ($sucursal > 0) {
  $sql .= " AND s.id = ? ";
  $params[] = $sucursal;
  $types   .= 'i';
}

$sql .= "
  GROUP BY s.id, s.nombre, color
  ORDER BY s.nombre, color
";

$stmt = $conn->prepare($sql);
if ($types !== '') {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$totalPiezas = 0;
while($r = $res->fetch_assoc()){
  $r['piezas'] = (int)($r['piezas'] ?? 0);
  $rows[] = $r;
  $totalPiezas += $r['piezas'];
}
$stmt->close();
?>
<style>
  .det-head{display:flex;align-items:center;gap:10px;margin:4px 0 8px 0}
  .det-head h4{margin:0;font-size:15px}
  .pill{display:inline-block; padding:2px 6px; border-radius:999px; font-size:12px; background:#eef2ff}

  .det-table{width:100%; border-collapse:collapse; white-space:nowrap}
  .det-table th, .det-table td{border-bottom:1px solid #eee; padding:6px 8px; font-size:13px}
  .det-table th{text-align:left}
  .det-table td:nth-child(3), .det-table th:nth-child(3){ text-align:right } /* piezas a la derecha */
  .sum{background:#fafafa; font-weight:600}
  .mini{font-size:13px;color:#64748b}
</style>

<div class="det-head">
  <h4>
    Detalle por sucursal y color — 
    <span class="pill">
      <?=htmlspecialchars(($tipo ? "$tipo · " : "")."$marca $modelo $capacidad")?>
    </span>
  </h4>
</div>

<?php if (empty($rows)): ?>
  <div class="mini">Sin inventario disponible para este modelo con los filtros actuales.</div>
<?php else: ?>
  <table class="det-table">
    <thead>
      <tr>
        <th>Sucursal</th>
        <th>Color</th>
        <th>Piezas</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($rows as $r): ?>
        <tr>
          <td><?=htmlspecialchars($r['sucursal'])?></td>
          <td><?=htmlspecialchars($r['color'])?></td>
          <td><?= (int)$r['piezas'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="sum">
        <td colspan="2">Total</td>
        <td><?= (int)$totalPiezas ?></td>
      </tr>
    </tfoot>
  </table>
<?php endif; ?>
