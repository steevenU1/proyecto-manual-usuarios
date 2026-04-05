<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}

include 'db.php';
include 'navbar.php';

$msg = "";
$editMode = false;
$editData = null;

// =========================
// CARGAR DATOS PARA EDICIÓN
// =========================
if (isset($_GET['edit_id'])) {
    $editMode = true;
    $editId = (int)$_GET['edit_id'];
    $stmtEdit = $conn->prepare("SELECT * FROM cuotas_mensuales WHERE id=?");
    $stmtEdit->bind_param("i", $editId);
    $stmtEdit->execute();
    $editData = $stmtEdit->get_result()->fetch_assoc();
}

// =========================
// INSERTAR O ACTUALIZAR
// =========================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id_sucursal'])) {
    $id_sucursal = (int)$_POST['id_sucursal'];
    $anio = (int)$_POST['anio'];
    $mes = (int)$_POST['mes'];
    $cuota_unidades = (int)$_POST['cuota_unidades'];
    $cuota_monto = (float)$_POST['cuota_monto'];

    if (!empty($_POST['edit_id'])) {
        // 🔹 MODO EDICIÓN → UPDATE
        $editId = (int)$_POST['edit_id'];
        $stmt = $conn->prepare("UPDATE cuotas_mensuales 
                                SET id_sucursal=?, anio=?, mes=?, cuota_unidades=?, cuota_monto=? 
                                WHERE id=?");
        $stmt->bind_param("iiiidi", $id_sucursal, $anio, $mes, $cuota_unidades, $cuota_monto, $editId);
        if ($stmt->execute()) {
            $msg = "✅ Cuota actualizada correctamente.";
            $editMode = false;
            $editData = null;
        } else {
            $msg = "❌ Error al actualizar cuota: " . $conn->error;
        }
    } else {
        // 🔹 MODO NUEVO → INSERT
        $check = $conn->prepare("SELECT id FROM cuotas_mensuales WHERE id_sucursal=? AND anio=? AND mes=?");
        $check->bind_param("iii", $id_sucursal, $anio, $mes);
        $check->execute();
        $res = $check->get_result();

        if ($res->num_rows > 0) {
            $msg = "⚠️ Ya existe una cuota para esa sucursal en ese mes/año.";
        } else {
            $stmt = $conn->prepare("INSERT INTO cuotas_mensuales (id_sucursal, anio, mes, cuota_unidades, cuota_monto) VALUES (?,?,?,?,?)");
            $stmt->bind_param("iiiid", $id_sucursal, $anio, $mes, $cuota_unidades, $cuota_monto);
            if ($stmt->execute()) {
                $msg = "✅ Cuota registrada correctamente.";
            } else {
                $msg = "❌ Error al registrar cuota: " . $conn->error;
            }
        }
    }
}

// =========================
// FILTRO DE MES/AÑO
// =========================
$anioSel = $_GET['anio'] ?? date('Y');
$mesSel = $_GET['mes'] ?? date('n');

$sql = "
    SELECT cm.*, s.nombre AS sucursal
    FROM cuotas_mensuales cm
    INNER JOIN sucursales s ON s.id = cm.id_sucursal
    WHERE cm.anio=? AND cm.mes=?
    ORDER BY s.nombre
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $anioSel, $mesSel);
$stmt->execute();
$result = $stmt->get_result();

// Lista de sucursales
$sucursales = $conn->query("SELECT id, nombre FROM sucursales WHERE tipo_sucursal='Tienda' ORDER BY nombre");
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cuotas Mensuales</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>📅 Gestión de Cuotas Mensuales</h2>
    <?php if($msg): ?>
        <div class="alert alert-info mt-3"><?= $msg ?></div>
    <?php endif; ?>

    <!-- Formulario para nueva/edición cuota -->
    <div class="card shadow p-3 mb-4">
        <h5><?= $editMode ? "✏️ Editar Cuota" : "➕ Agregar Nueva Cuota" ?></h5>
        <form method="POST" class="row g-3">
            <input type="hidden" name="edit_id" value="<?= $editMode ? $editData['id'] : '' ?>">

            <div class="col-md-3">
                <label>Sucursal</label>
                <select name="id_sucursal" class="form-select" required>
                    <option value="">Seleccione...</option>
                    <?php 
                    $sucursales->data_seek(0);
                    while($s = $sucursales->fetch_assoc()): 
                    ?>
                        <option value="<?= $s['id'] ?>" 
                            <?= $editMode && $editData['id_sucursal']==$s['id']?'selected':'' ?>>
                            <?= $s['nombre'] ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label>Año</label>
                <input type="number" name="anio" value="<?= $editMode?$editData['anio']:date('Y') ?>" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label>Mes</label>
                <input type="number" name="mes" min="1" max="12" value="<?= $editMode?$editData['mes']:date('n') ?>" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label>Cuota Unidades</label>
                <input type="number" name="cuota_unidades" min="0" value="<?= $editMode?$editData['cuota_unidades']:'' ?>" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label>Cuota Monto ($)</label>
                <input type="number" step="0.01" name="cuota_monto" min="0" value="<?= $editMode?$editData['cuota_monto']:'' ?>" class="form-control" required>
            </div>
            <div class="col-12 text-end mt-3">
                <button class="btn btn-primary"><?= $editMode ? "💾 Actualizar" : "💾 Guardar" ?></button>
                <?php if($editMode): ?>
                    <a href="cuotas_mensuales.php" class="btn btn-secondary">Cancelar</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Filtro de mes/año -->
    <form method="GET" class="mb-3 row g-2">
        <div class="col-md-2">
            <select name="mes" class="form-select">
                <?php for($m=1;$m<=12;$m++): ?>
                    <option value="<?= $m ?>" <?= $m==$mesSel?'selected':'' ?>><?= $m ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" name="anio" value="<?= $anioSel ?>" class="form-control">
        </div>
        <div class="col-md-2">
            <button class="btn btn-secondary">Filtrar</button>
        </div>
    </form>

    <!-- Tabla de cuotas mensuales -->
    <div class="card shadow p-3">
        <h5>Cuotas Registradas <?= $mesSel ?>/<?= $anioSel ?></h5>
        <table class="table table-bordered table-striped mt-3">
            <thead class="table-dark">
                <tr>
                    <th>Sucursal</th>
                    <th>Unidades</th>
                    <th>Monto ($)</th>
                    <th>Fecha Registro</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if($result->num_rows > 0): ?>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row['sucursal'] ?></td>
                            <td><?= $row['cuota_unidades'] ?></td>
                            <td>$<?= number_format($row['cuota_monto'],2) ?></td>
                            <td><?= $row['fecha_registro'] ?></td>
                            <td>
                                <a href="cuotas_mensuales.php?edit_id=<?= $row['id'] ?>" class="btn btn-sm btn-warning">✏️ Editar</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="text-center">Sin cuotas registradas</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>
