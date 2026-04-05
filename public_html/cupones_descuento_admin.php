<?php
// cupones_descuento_admin.php — Gestión de cupones de descuento por código de producto
// Admin y Logistica: alta individual + carga masiva por CSV

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

$ROL        = $_SESSION['rol'] ?? 'Ejecutivo';
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);

// Solo Admin y Logistica pueden entrar
if (!in_array($ROL, ['Admin', 'Logistica'], true)) {
    header("Location: 403.php");
    exit();
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$mensaje = '';
$tipoMensaje = 'success';

/* ======================================
   Helper: normalizar fecha
   - Acepta: "YYYY-MM-DD" o "DD/MM/YYYY"
   - Devuelve: "YYYY-MM-DD" o NULL
====================================== */
function normalizarFecha(?string $str): ?string {
    $str = trim((string)$str);
    if ($str === '') {
        return null;
    }

    // Si ya viene en formato YYYY-MM-DD lo validamos rápido
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $str)) {
        [$y, $m, $d] = explode('-', $str);
        if (checkdate((int)$m, (int)$d, (int)$y)) {
            return sprintf('%04d-%02d-%02d', $y, $m, $d);
        }
        return null;
    }

    // Si viene en formato DD/MM/YYYY (como en Excel)
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $str)) {
        [$d, $m, $y] = explode('/', $str);
        if (checkdate((int)$m, (int)$d, (int)$y)) {
            return sprintf('%04d-%02d-%02d', $y, $m, $d);
        }
        return null;
    }

    // Otros formatos raros → mejor los ignoramos
    return null;
}

/* ======================================
   Helper: upsert de cupón por código
====================================== */
function upsertCupon(mysqli $conn, string $codigo, float $descuento, ?string $fechaInicio, ?string $fechaFin, int $idUsuario, array &$stats): bool
{
    $codigo = strtoupper(trim($codigo));
    if ($codigo === '' || $descuento < 0) {
        $stats['saltados']++;
        return false;
    }

    // Buscar cupón activo existente
    $stmt = $conn->prepare("SELECT id FROM cupones_descuento WHERE codigo_producto = ? AND activo = 1 LIMIT 1");
    $stmt->bind_param("s", $codigo);
    $stmt->execute();
    $stmt->bind_result($idExistente);
    $tieneExistente = $stmt->fetch();
    $stmt->close();

    if ($tieneExistente) {
        // UPDATE
        $sql = "UPDATE cupones_descuento
                SET descuento_mxn = ?, fecha_inicio = ?, fecha_fin = ?, actualizado_en = NOW()
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "dssi",
            $descuento,
            $fechaInicio,
            $fechaFin,
            $idExistente
        );
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            $stats['actualizados']++;
        } else {
            $stats['errores']++;
        }
        return $ok;
    } else {
        // INSERT
        $sql = "INSERT INTO cupones_descuento 
                (codigo_producto, descuento_mxn, fecha_inicio, fecha_fin, activo, creado_por)
                VALUES (?, ?, ?, ?, 1, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "sdssi",
            $codigo,
            $descuento,
            $fechaInicio,
            $fechaFin,
            $idUsuario
        );
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            $stats['insertados']++;
        } else {
            $stats['errores']++;
        }
        return $ok;
    }
}

/* ============================
   1) Desactivar cupón (GET)
============================ */
if (isset($_GET['desactivar'])) {
    $idDes = (int)$_GET['desactivar'];
    if ($idDes > 0) {
        $stmt = $conn->prepare("UPDATE cupones_descuento SET activo = 0 WHERE id = ?");
        $stmt->bind_param("i", $idDes);
        if ($stmt->execute()) {
            $mensaje = "Cupón desactivado correctamente.";
            $tipoMensaje = 'success';
        } else {
            $mensaje = "Error al desactivar el cupón.";
            $tipoMensaje = 'danger';
        }
        $stmt->close();
    }
}

/* ============================
   2) Crear / Actualizar cupón (POST manual)
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['modo'] ?? '') === 'manual') {
    $codigo   = strtoupper(trim($_POST['codigo_producto'] ?? ''));
    $descuento = trim($_POST['descuento_mxn'] ?? '');
    $fIni     = trim($_POST['fecha_inicio'] ?? '');
    $fFin     = trim($_POST['fecha_fin'] ?? '');

    // Normalizar descuento
    $descuento = str_replace(',', '', $descuento); // por si ponen 1,000.00
    if ($codigo === '' || $descuento === '' || !is_numeric($descuento)) {
        $mensaje = "Debes capturar un código de producto y un descuento válido.";
        $tipoMensaje = 'danger';
    } else {
        $descuento = (float)$descuento;

        // Fechas opcionales normalizadas (acepta YYYY-MM-DD o DD/MM/YYYY)
        $fechaInicio = normalizarFecha($fIni);
        $fechaFin    = normalizarFecha($fFin);

        $stats = ['insertados' => 0, 'actualizados' => 0, 'saltados' => 0, 'errores' => 0];

        if (upsertCupon($conn, $codigo, $descuento, $fechaInicio, $fechaFin, $idUsuario, $stats)) {
            if ($stats['insertados'] > 0) {
                $mensaje = "Cupón creado correctamente para el código {$codigo}.";
            } else {
                $mensaje = "Cupón actualizado correctamente para el código {$codigo}.";
            }
            $tipoMensaje = 'success';
        } else {
            $mensaje = "Error al guardar el cupón para el código {$codigo}.";
            $tipoMensaje = 'danger';
        }
    }
}

/* ============================
   3) Carga masiva por CSV
============================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['modo'] ?? '') === 'csv') {
    $stats = ['insertados' => 0, 'actualizados' => 0, 'saltados' => 0, 'errores' => 0];

    if (!isset($_FILES['archivo_csv']) || $_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $mensaje = "Error al subir el archivo CSV.";
        $tipoMensaje = 'danger';
    } else {
        $tmpName = $_FILES['archivo_csv']['tmp_name'];

        if (($handle = fopen($tmpName, "r")) !== false) {

            $tieneHeader = isset($_POST['tiene_header']) && $_POST['tiene_header'] === '1';
            $fila = 0;

            while (($data = fgetcsv($handle, 1000, ",")) !== false) {
                $fila++;

                if ($tieneHeader && $fila === 1) {
                    continue; // saltar cabecera
                }

                // Esperamos: codigo_producto, descuento_mxn, fecha_inicio, fecha_fin
                $codigo   = isset($data[0]) ? trim($data[0]) : '';
                $descStr  = isset($data[1]) ? trim($data[1]) : '';
                $fIni     = isset($data[2]) ? trim($data[2]) : '';
                $fFin     = isset($data[3]) ? trim($data[3]) : '';

                if ($codigo === '' || $descStr === '') {
                    $stats['saltados']++;
                    continue;
                }

                // Normalizar descuento
                $descStr = str_replace(',', '', $descStr); // quitar separadores de miles
                if (!is_numeric($descStr)) {
                    $stats['saltados']++;
                    continue;
                }
                $descuento = (float)$descStr;

                // Normalizar fechas (acepta DD/MM/YYYY como en Excel)
                $fechaInicio = normalizarFecha($fIni);
                $fechaFin    = normalizarFecha($fFin);

                upsertCupon($conn, $codigo, $descuento, $fechaInicio, $fechaFin, $idUsuario, $stats);
            }

            fclose($handle);

            $mensaje = "Carga CSV finalizada. "
                . "Insertados: {$stats['insertados']}, "
                . "Actualizados: {$stats['actualizados']}, "
                . "Saltados: {$stats['saltados']}, "
                . "Errores: {$stats['errores']}.";
            $tipoMensaje = $stats['errores'] > 0 ? 'warning' : 'success';

        } else {
            $mensaje = "No se pudo leer el archivo CSV.";
            $tipoMensaje = 'danger';
        }
    }
}

/* ============================
   4) Traer lista de cupones activos
============================ */
$cupones = [];
$sql = "SELECT c.id, c.codigo_producto, c.descuento_mxn, c.fecha_inicio, c.fecha_fin,
               c.activo, c.creado_en, u.nombre AS creado_por_nombre
        FROM cupones_descuento c
        LEFT JOIN usuarios u ON c.creado_por = u.id
        WHERE c.activo = 1
        ORDER BY c.codigo_producto ASC";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $cupones[] = $row;
    }
    $res->free();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cupones de Descuento</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4 mb-5">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0">Cupones de descuento por código de producto</h3>
        <span class="badge bg-primary">
            Rol: <?php echo h($ROL); ?>
        </span>
    </div>

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-<?php echo h($tipoMensaje); ?> alert-dismissible fade show" role="alert">
            <?php echo h($mensaje); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
    <?php endif; ?>

    <!-- Formulario alta / actualización manual -->
    <div class="card mb-4">
        <div class="card-header">
            Registrar / actualizar cupón (individual)
        </div>
        <div class="card-body">
            <form method="post" autocomplete="off">
                <input type="hidden" name="modo" value="manual">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="codigo_producto" class="form-label">Código de producto</label>
                        <input type="text" name="codigo_producto" id="codigo_producto"
                               class="form-control" required
                               placeholder="Ej. TEL-HIS-V40S-64GB-4R-AZ">
                        <div class="form-text">Debe coincidir exactamente con el código de producto usado en inventario/lista de precios.</div>
                    </div>

                    <div class="col-md-3">
                        <label for="descuento_mxn" class="form-label">Descuento (MXN)</label>
                        <input type="number" name="descuento_mxn" id="descuento_mxn"
                               class="form-control" required step="0.01" min="0">
                        <div class="form-text">Monto fijo en pesos que se restará al precio.</div>
                    </div>

                    <div class="col-md-2">
                        <label for="fecha_inicio" class="form-label">Fecha inicio</label>
                        <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control">
                    </div>

                    <div class="col-md-2">
                        <label for="fecha_fin" class="form-label">Fecha fin</label>
                        <input type="date" name="fecha_fin" id="fecha_fin" class="form-control">
                    </div>

                    <div class="col-md-12 d-flex justify-content-end">
                        <button type="submit" class="btn btn-success">
                            Guardar cupón
                        </button>
                    </div>
                </div>
            </form>
            <hr>
            <small class="text-muted">
                * Si el código ya tiene un cupón activo, al guardar se actualizará el descuento y la vigencia.
            </small>
        </div>
    </div>

    <!-- Carga masiva por CSV -->
    <div class="card mb-4">
        <div class="card-header">
            Carga masiva de cupones desde CSV
        </div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="modo" value="csv">
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label for="archivo_csv" class="form-label">Archivo CSV</label>
                        <input type="file" name="archivo_csv" id="archivo_csv"
                               class="form-control" accept=".csv" required>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" value="1" id="tiene_header" name="tiene_header" checked>
                            <label class="form-check-label" for="tiene_header">
                                El archivo tiene encabezados
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4 d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            Importar cupones
                        </button>
                    </div>
                </div>
            </form>
            <hr>
            <small class="text-muted">
                Formato esperado del CSV (separado por comas):<br>
                <code>codigo_producto,descuento_mxn,fecha_inicio,fecha_fin</code><br>
                Ejemplos válidos de fechas:<br>
                <code>2025-12-01</code> o <code>01/12/2025</code><br>
                Ejemplo de fila:<br>
                <code>TEL-HON-10 LITE-64GB-4R-RJO,200,01/12/2025,02/12/2025</code><br>
                Si el código ya existe activo, se actualizará el descuento y las fechas.<br>
                Puedes repetir el mismo descuento para muchos códigos sin problema.
            </small>
        </div>
    </div>

    <!-- Listado de cupones activos -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Cupones activos</span>
            <span class="badge bg-secondary"><?php echo count($cupones); ?> registro(s)</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($cupones)): ?>
                <p class="p-3 mb-0">No hay cupones activos registrados.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Código de producto</th>
                                <th>Descuento (MXN)</th>
                                <th>Fecha inicio</th>
                                <th>Fecha fin</th>
                                <th>Creado por</th>
                                <th>Creado en</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cupones as $idx => $c): ?>
                            <tr>
                                <td><?php echo $idx + 1; ?></td>
                                <td><?php echo h($c['codigo_producto']); ?></td>
                                <td>$<?php echo number_format((float)$c['descuento_mxn'], 2); ?></td>
                                <td><?php echo $c['fecha_inicio'] ?: '-'; ?></td>
                                <td><?php echo $c['fecha_fin'] ?: '-'; ?></td>
                                <td><?php echo $c['creado_por_nombre'] ? h($c['creado_por_nombre']) : '—'; ?></td>
                                <td><?php echo h($c['creado_en']); ?></td>
                                <td>
                                    <a href="?desactivar=<?php echo (int)$c['id']; ?>"
                                       class="btn btn-sm btn-outline-danger"
                                       onclick="return confirm('¿Desactivar este cupón?');">
                                        Desactivar
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
