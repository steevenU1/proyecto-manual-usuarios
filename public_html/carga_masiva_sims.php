<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}

include 'db.php';
// mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ========= Config =========
define('PREVIEW_LIMIT', 200);
const OPERADORES_VALIDOS = ['Bait', 'AT&T', 'Virgin', 'Unefon', 'Telcel', 'Movistar'];

$msg = '';
$previewRows = [];
$contador = ['total' => 0, 'ok' => 0, 'ignoradas' => 0];

// Resultado de la última carga (después de insertar)
$okCarga       = isset($_GET['ok']);
$ultimoResumen = $_SESSION['carga_sims_resumen'] ?? null;
$ultimoError   = $_SESSION['carga_sims_error'] ?? '';
unset($_SESSION['carga_sims_resumen'], $_SESSION['carga_sims_error']);

// ========= Helpers =========
function columnAllowsNull(mysqli $conn, string $table, string $column): bool
{
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);

    $sql = "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME='$t'
              AND COLUMN_NAME='$c'
            LIMIT 1";

    $rs = $conn->query($sql);
    if (!$rs) return false;

    $row = $rs->fetch_assoc();
    return isset($row['IS_NULLABLE']) && strtoupper($row['IS_NULLABLE']) === 'YES';
}

function getSucursalIdPorNombre(mysqli $conn, string $nombre, array &$cache): int
{
    $nombre = trim($nombre);
    if ($nombre === '') return 0;
    if (isset($cache[$nombre])) return $cache[$nombre];

    $stmt = $conn->prepare("SELECT id FROM sucursales WHERE nombre=? LIMIT 1");
    $stmt->bind_param("s", $nombre);
    $stmt->execute();
    $id = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();

    $cache[$nombre] = $id;
    return $id;
}

function quitarAcentosMayus(string $s): string
{
    $s = mb_strtoupper($s, 'UTF-8');
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    return $t !== false ? strtoupper($t) : $s;
}

function normalizarOperador(string $opRaw): array
{
    $op = quitarAcentosMayus(trim($opRaw));
    $sinEspHyf = str_replace([' ', '-', '.', '_'], '', $op);

    if ($op === '') return ['Bait', true];

    $map = [
        'BAIT'         => 'Bait',
        'AT&T'         => 'AT&T',
        'ATT'          => 'AT&T',
        'VIRGIN'       => 'Virgin',
        'VIRGINMOBILE' => 'Virgin',
        'UNEFON'       => 'Unefon',
        'TELCEL'       => 'Telcel',
        'MOVISTAR'     => 'Movistar',
    ];

    if (isset($map[$op])) return [$map[$op], true];
    if (isset($map[$sinEspHyf])) return [$map[$sinEspHyf], true];

    foreach (OPERADORES_VALIDOS as $val) {
        if (quitarAcentosMayus($val) === $op) return [$val, true];
    }

    return [$opRaw, false];
}

/**
 * Limpia el texto crudo del header:
 * - Quita BOM (por bytes y por la secuencia ï»¿).
 * - Quita espacios raros y normaliza a minúsculas.
 */
function cleanHeaderRaw(string $raw): string
{
    $raw = preg_replace('/^\xEF\xBB\xBF/u', '', $raw); // BOM bytes
    $raw = str_replace('ï»¿', '', $raw);               // BOM “visible”
    $raw = str_replace("\xC2\xA0", ' ', $raw);         // NBSP
    $raw = trim($raw);
    $raw = strtolower($raw);
    $raw = str_replace([' ', '-'], '_', $raw);
    return $raw;
}

/** Lee el header del CSV y arma un mapa de índice por nombre de columna (case-insensitive). */
function buildHeaderMap(array $hdr): array
{
    $map = [];
    foreach ($hdr as $i => $raw) {
        $k = cleanHeaderRaw((string)$raw);
        if ($k === '') continue;
        $map[$k] = $i;
    }

    // alias comunes para caja
    if (!isset($map['caja_id'])) {
        if (isset($map['id_caja'])) $map['caja_id'] = $map['id_caja'];
        elseif (isset($map['caja'])) $map['caja_id'] = $map['caja'];
    }

    // detectar iccid aunque tenga suciedad rara (cualquier header que contenga "iccid")
    if (!isset($map['iccid'])) {
        foreach ($map as $k => $idx) {
            if (strpos($k, 'iccid') !== false) {
                $map['iccid'] = $idx;
                break;
            }
        }
    }

    return $map;
}

function getCsvVal(array $row, array $map, string $key): string
{
    if (isset($map[$key])) return trim((string)($row[$map[$key]] ?? ''));
    return '';
}

// ========= Descubrimientos iniciales =========

// ID sucursal Eulalia (almacén)
$idEulalia = 0;
$resEulalia = $conn->query("SELECT id FROM sucursales WHERE nombre='Eulalia' LIMIT 1");
if ($resEulalia && $rowE = $resEulalia->fetch_assoc()) {
    $idEulalia = (int)$rowE['id'];
}

// ¿La columna inventario_sims.dn permite NULL?
$dnPermiteNull = columnAllowsNull($conn, 'inventario_sims', 'dn');
// ¿La columna inventario_sims.lote permite NULL?
$lotePermiteNull = columnAllowsNull($conn, 'inventario_sims', 'lote');
// ¿La columna inventario_sims.caja_id permite NULL?
$cajaPermiteNull = columnAllowsNull($conn, 'inventario_sims', 'caja_id');

// Cache de búsqueda de sucursal
$sucursalCache = [];

// ========= INSERTAR =========
// Aquí ya no leemos archivo. Insertamos lo que se guardó en sesión en el preview.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'insertar') {
    $resumen = [
        'total'        => 0,
        'insertadas'   => 0,
        'ignoradas'    => 0,
        'primer_error' => ''
    ];

    try {
        $rowsOK = $_SESSION['carga_sims_okrows'] ?? [];
        if (empty($rowsOK)) {
            throw new RuntimeException("No hay registros preparados para insertar. Genera primero la vista previa.");
        }

        $sqlInsert = "INSERT INTO inventario_sims (iccid,dn,caja_id,lote,id_sucursal,operador,estatus,fecha_ingreso)
                      VALUES (?,?,?,?,?,?,'Disponible',NOW())";
        $stmtInsert = $conn->prepare($sqlInsert);

        foreach ($rowsOK as $row) {
            $resumen['total']++;

            $iccid       = $row['iccid'];
            $dn          = $row['dn'];
            $caja        = $row['caja'];
            $lote        = $row['lote'];
            $id_sucursal = $row['id_sucursal'];
            $operador    = $row['operador'];

            // Doble check duplicados por ICCID
            $stmtDup = $conn->prepare("SELECT id FROM inventario_sims WHERE iccid=? LIMIT 1");
            $stmtDup->bind_param("s", $iccid);
            $stmtDup->execute();
            $stmtDup->store_result();

            if ($stmtDup->num_rows > 0) {
                $resumen['ignoradas']++;
                if ($resumen['primer_error'] === '') {
                    $resumen['primer_error'] = "ICCID duplicado en base: {$iccid}";
                }
                $stmtDup->close();
                continue;
            }
            $stmtDup->close();

            $dnParam   = ($dn === '')   ? ($dnPermiteNull   ? null : '') : $dn;
            $loteParam = ($lote === '') ? ($lotePermiteNull ? null : '') : $lote;
            $cajaParam = ($caja === '') ? ($cajaPermiteNull ? null : '') : $caja;

            try {
                $stmtInsert->bind_param("ssssis", $iccid, $dnParam, $cajaParam, $loteParam, $id_sucursal, $operador);
                $stmtInsert->execute();
                $resumen['insertadas']++;
            } catch (Throwable $e) {
                $resumen['ignoradas']++;
                if ($resumen['primer_error'] === '') {
                    $resumen['primer_error'] = 'Error inserción: ' . $e->getMessage();
                }
            }
        }

        // Limpiamos los datos guardados en sesión
        unset($_SESSION['carga_sims_okrows'], $_SESSION['carga_sims_contador']);

        $_SESSION['carga_sims_resumen'] = $resumen;
        $_SESSION['carga_sims_error']   = '';
        header('Location: carga_masiva_sims.php?ok=1');
        exit;
    } catch (Throwable $e) {
        $_SESSION['carga_sims_resumen'] = $resumen;
        $_SESSION['carga_sims_error']   = $e->getMessage();
        header('Location: carga_masiva_sims.php?ok=1');
        exit;
    }
}

// ========= PREVIEW =========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview' && isset($_FILES['archivo_csv'])) {
    if ($_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $msg = "❌ Error al subir el archivo.";
    } else {
        $nombreOriginal = $_FILES['archivo_csv']['name'];
        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $msg = "Convierte tu Excel a CSV UTF-8 y súbelo de nuevo.";
        } else {
            $tmpPath = $_FILES['archivo_csv']['tmp_name'];

            $fh = fopen($tmpPath, 'r');
            if ($fh) {
                $fila = 0;
                $hdrMap = null;
                $rowsOK = []; // filas que sí se van a intentar insertar

                while (($data = fgetcsv($fh, 0, ",")) !== false) {
                    $fila++;
                    if ($fila === 1) {
                        $hdrMap = buildHeaderMap($data);
                        continue;
                    }

                    $iccid  = getCsvVal($data, $hdrMap, 'iccid');
                    $dn     = getCsvVal($data, $hdrMap, 'dn');
                    $caja   = getCsvVal($data, $hdrMap, 'caja_id');
                    $lote   = getCsvVal($data, $hdrMap, 'lote');
                    $sucNom = getCsvVal($data, $hdrMap, 'sucursal');
                    $opRaw  = getCsvVal($data, $hdrMap, 'operador');

                    $id_sucursal = $sucNom === '' ? $idEulalia : getSucursalIdPorNombre($conn, $sucNom, $sucursalCache);
                    [$operador, $opValido] = normalizarOperador($opRaw);

                    $estatus = 'OK';
                    $motivo  = 'Listo para insertar';

                    if ($iccid === '') {
                        $estatus = 'Ignorada';
                        $motivo  = 'ICCID vacío';
                    } elseif ($id_sucursal === 0) {
                        $estatus = 'Ignorada';
                        $motivo  = 'Sucursal no encontrada';
                    } elseif (!$opValido) {
                        $estatus = 'Ignorada';
                        $motivo  = 'Operador inválido';
                    } elseif ($caja === '' && !$cajaPermiteNull) {
                        $estatus = 'Ignorada';
                        $motivo  = 'Caja vacía y caja_id no permite NULL';
                    } else {
                        // Detección temprana de duplicados
                        $stmtDup = $conn->prepare("SELECT id FROM inventario_sims WHERE iccid=? LIMIT 1");
                        $stmtDup->bind_param("s", $iccid);
                        $stmtDup->execute();
                        $stmtDup->store_result();
                        if ($stmtDup->num_rows > 0) {
                            $estatus = 'Ignorada';
                            $motivo  = 'Duplicado en base';
                        }
                        $stmtDup->close();
                    }

                    $contador['total']++;
                    if ($estatus === 'OK') {
                        $contador['ok']++;
                        $rowsOK[] = [
                            'iccid'       => $iccid,
                            'dn'          => $dn,
                            'caja'        => $caja,
                            'lote'        => $lote,
                            'id_sucursal' => $id_sucursal,
                            'operador'    => $operador
                        ];
                    } else {
                        $contador['ignoradas']++;
                    }

                    if (count($previewRows) < PREVIEW_LIMIT) {
                        $previewRows[] = [
                            'iccid'           => $iccid,
                            'dn'              => $dn,
                            'caja'            => $caja,
                            'lote'            => $lote,
                            'nombre_sucursal' => $sucNom,
                            'operador'        => $operador,
                            'estatus'         => $estatus,
                            'motivo'          => $motivo
                        ];
                    }
                }
                fclose($fh);

                // Guardamos las filas OK en sesión para el segundo paso (insertar)
                $_SESSION['carga_sims_okrows']   = $rowsOK;
                $_SESSION['carga_sims_contador'] = $contador;
            } else {
                $msg = "❌ No se pudo abrir el archivo.";
            }
        }
    }
} elseif (!isset($_POST['action']) && isset($_SESSION['carga_sims_contador'])) {
    // Si recarga después del preview sin insertar, recuperamos contadores para mostrar
    $contador = $_SESSION['carga_sims_contador'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Carga Masiva de SIMs</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include __DIR__ . '/navbar.php'; ?>
<div style="height:70px"></div>

<div class="container mt-4">
    <h2>Carga Masiva de SIMs</h2>
    <a href="dashboard_unificado.php" class="btn btn-secondary mb-3">← Volver al Dashboard</a>

    <?php if ($okCarga && $ultimoResumen): ?>
        <div class="alert alert-success">
            ✅ Carga procesada.<br>
            Filas leídas (intento de inserción): <b><?= (int)$ultimoResumen['total'] ?></b><br>
            Insertadas: <b><?= (int)$ultimoResumen['insertadas'] ?></b><br>
            Ignoradas: <b><?= (int)$ultimoResumen['ignoradas'] ?></b>
            <?php if (!empty($ultimoResumen['primer_error'])): ?>
                <br><small class="text-muted">Primer motivo detectado: <?= htmlspecialchars($ultimoResumen['primer_error']) ?></small>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($okCarga && $ultimoError): ?>
        <div class="alert alert-danger">
            ⚠️ Ocurrió un problema general en la carga: <br>
            <?= htmlspecialchars($ultimoError) ?>
        </div>
    <?php endif; ?>

    <?php if ($msg): ?>
        <div class="alert alert-info"><?= $msg ?></div>
    <?php endif; ?>

    <?php if (!isset($_POST['action']) || ($_POST['action'] ?? '') === ''): ?>
        <div class="card p-4 shadow-sm bg-white">
            <h5>Subir Archivo CSV</h5>
            <p>
                Columnas (recomendado): <b>iccid, dn, caja_id, lote, sucursal, operador</b>.<br>
                <b>dn</b> y <b>lote</b> son opcionales; si vienen vacíos, se guardan como <b>NULL</b> (si la columna lo permite).<br>
                Si <b>sucursal</b> está vacía, se asigna <b>Eulalia</b>.<br>
                Si <b>operador</b> está vacío, se usa <b>Bait</b>.<br>
                Admitimos encabezados equivalentes: <code>caja_id</code>, <code>id_caja</code> o <code>caja</code>.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="preview">
                <input type="file" name="archivo_csv" class="form-control mb-3" accept=".csv" required>
                <button class="btn btn-primary">👀 Vista Previa</button>
            </form>
        </div>

    <?php elseif (($_POST['action'] ?? '') === 'preview'): ?>
        <div class="card p-4 shadow-sm bg-white">
            <h5>Vista Previa</h5>
            <p>
                Total filas: <b><?= $contador['total'] ?></b> |
                OK: <b class="text-success"><?= $contador['ok'] ?></b> |
                Ignoradas: <b class="text-danger"><?= $contador['ignoradas'] ?></b>
            </p>

            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="table-light">
                    <tr>
                        <th>ICCID</th>
                        <th>DN</th>
                        <th>Caja</th>
                        <th>Lote</th>
                        <th>Sucursal</th>
                        <th>Operador</th>
                        <th>Estatus</th>
                        <th>Motivo</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($previewRows as $r): ?>
                        <tr class="<?= ($r['estatus'] === 'OK') ? '' : 'table-warning' ?>">
                            <td><?= htmlspecialchars($r['iccid']) ?></td>
                            <td><?= htmlspecialchars($r['dn']) ?></td>
                            <td><?= htmlspecialchars($r['caja']) ?></td>
                            <td><?= htmlspecialchars($r['lote']) ?></td>
                            <td><?= htmlspecialchars($r['nombre_sucursal']) ?></td>
                            <td><?= htmlspecialchars($r['operador']) ?></td>
                            <td><?= htmlspecialchars($r['estatus']) ?></td>
                            <td><?= htmlspecialchars($r['motivo']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="POST" class="mt-3" id="formInsertar">
                <input type="hidden" name="action" value="insertar">
                <div class="alert alert-warning">
                    Se insertarán hasta <b class="text-success"><?= $contador['ok'] ?></b> registros válidos (los marcados como OK).
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="1" id="confirm_ok" name="confirm_ok">
                    <label class="form-check-label" for="confirm_ok">
                        Entiendo y deseo continuar con la carga.
                    </label>
                </div>
                <button class="btn btn-success" id="btnConfirm" disabled>✅ Confirmar e Insertar</button>
                <a href="carga_masiva_sims.php" class="btn btn-outline-secondary">Cancelar</a>
            </form>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const chk  = document.getElementById('confirm_ok');
    const btn  = document.getElementById('btnConfirm');
    const form = document.getElementById('formInsertar');

    function toggleBtn() {
        if (!btn || !chk) return;
        btn.disabled = !chk.checked;
    }

    if (chk && btn) {
        chk.addEventListener('change', toggleBtn);
        toggleBtn();
    }

    if (form && btn) {
        form.addEventListener('submit', function () {
            btn.disabled = true;
            btn.innerText = 'Procesando carga...';
            if (chk) chk.disabled = true;
        });
    }
})();
</script>

</body>
</html>