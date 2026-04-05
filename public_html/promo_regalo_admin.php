<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'Admin') {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

function h($v){ return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

// ============================
// Crear promoción
// ============================
if(isset($_POST['crear_promo'])){
    
    $nombre = trim($_POST['nombre']);
    $fecha_inicio = $_POST['fecha_inicio'];
    $fecha_fin = $_POST['fecha_fin'];

    $stmt = $conn->prepare("
        INSERT INTO promos_regalo
        (nombre, activo, fecha_inicio, fecha_fin)
        VALUES (?,1,?,?)
    ");
    $stmt->bind_param("sss",$nombre,$fecha_inicio,$fecha_fin);
    $stmt->execute();
}

// ============================
// Agregar producto principal
// ============================
if(isset($_POST['agregar_producto'])){

    $id_promo = (int)$_POST['id_promo'];
    $codigo = trim($_POST['codigo_producto']);

    $stmt = $conn->prepare("
        INSERT INTO promos_regalo_principales
        (id_promo, codigo_producto_principal)
        VALUES (?,?)
    ");
    $stmt->bind_param("is",$id_promo,$codigo);
    $stmt->execute();
}

// ============================
// Activar / Desactivar promo
// ============================
if(isset($_GET['toggle'])){

    $id = (int)$_GET['toggle'];

    $conn->query("
        UPDATE promos_regalo
        SET activo = IF(activo=1,0,1)
        WHERE id = $id
    ");
}

// ============================
// Eliminar producto
// ============================
if(isset($_GET['del_prod'])){

    $id = (int)$_GET['del_prod'];

    $conn->query("
        DELETE FROM promos_regalo_principales
        WHERE id = $id
    ");
}

// ============================
// Obtener promos
// ============================
$promos = $conn->query("
    SELECT * FROM promos_regalo
    ORDER BY created_at DESC
");
?>

<div class="container mt-4">

<h3>🎁 Promociones de Regalo</h3>

<div class="card p-3 mb-4">
<form method="POST">

<div class="row">

<div class="col-md-4">
<label>Nombre Promo</label>
<input type="text" name="nombre" class="form-control" required>
</div>

<div class="col-md-3">
<label>Fecha Inicio</label>
<input type="date" name="fecha_inicio" class="form-control" required>
</div>

<div class="col-md-3">
<label>Fecha Fin</label>
<input type="date" name="fecha_fin" class="form-control" required>
</div>

<div class="col-md-2 d-flex align-items-end">
<button class="btn btn-success w-100" name="crear_promo">
Crear Promo
</button>
</div>

</div>

</form>
</div>

<?php while($p = $promos->fetch_assoc()): ?>

<div class="card mb-4">

<div class="card-header d-flex justify-content-between">

<div>
<strong><?=h($p['nombre'])?></strong>
<br>
<small>
<?=h($p['fecha_inicio'])?> → <?=h($p['fecha_fin'])?>
</small>
</div>

<div>

<?php if($p['activo']): ?>
<span class="badge bg-success">Activa</span>
<?php else: ?>
<span class="badge bg-secondary">Inactiva</span>
<?php endif; ?>

<a href="?toggle=<?=$p['id']?>" class="btn btn-sm btn-warning ms-2">
Activar / Desactivar
</a>

</div>

</div>

<div class="card-body">

<h6>Equipos que disparan la promo</h6>

<table class="table table-sm">

<thead>
<tr>
<th>Codigo Producto</th>
<th width="80"></th>
</tr>
</thead>

<tbody>

<?php
$idPromo = $p['id'];

$prods = $conn->query("
SELECT * 
FROM promos_regalo_principales
WHERE id_promo = $idPromo
");
?>

<?php while($pr = $prods->fetch_assoc()): ?>

<tr>

<td><?=h($pr['codigo_producto_principal'])?></td>

<td>
<a href="?del_prod=<?=$pr['id']?>" 
class="btn btn-danger btn-sm">
Eliminar
</a>
</td>

</tr>

<?php endwhile; ?>

</tbody>

</table>

<form method="POST">

<input type="hidden" name="id_promo" value="<?=$p['id']?>">

<div class="row">

<div class="col-md-10">
<input 
type="text"
name="codigo_producto"
class="form-control"
placeholder="Codigo producto principal"
required
>
</div>

<div class="col-md-2">
<button class="btn btn-primary w-100" name="agregar_producto">
Agregar
</button>
</div>

</div>

</form>

</div>

</div>

<?php endwhile; ?>

</div>