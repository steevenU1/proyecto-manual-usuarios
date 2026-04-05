<?php
/******************************************************
 * Actualizar precios (solo masivo por CSV)
 * CSV con columnas:
 *   - codigo_producto (obligatorio)
 *   - precio_lista    (obligatorio)
 *   - precio_combo    (opcional, null/0 = sin combo)
 *   - promocion       (opcional, texto, vacío = NULL)
 ******************************************************/

session_start();
$ROL = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id_usuario']) || !in_array($ROL, ['Admin','Logistica'], true)) {
  header("Location: 403.php");
  exit();
}

date_default_timezone_set('America/Mexico_City');

/* =====================================================
   DESCARGA PLANTILLA CSV (debe ir ANTES de cualquier output)
===================================================== */
if (isset($_GET['plantilla'])) {
    // Limpia cualquier salida previa (espacios/BOM/eco accidental)
    while (ob_get_level()) { ob_end_clean(); }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=plantilla_precios.csv');

    echo "\xEF\xBB\xBF"; // BOM UTF-8 para Excel/Windows
    echo "codigo_producto,precio_lista,precio_combo,promocion\n";
    echo "MIC-HON-X7B,1500,1200,\"Promo Julio: $300 de descuento\"\n";
    echo "IPH15-128-SILVER,17999.00,16999.00,\"Incluye funda de regalo\"\n";
    exit;
}

include 'db.php';
include 'navbar.php';

/* =========================
   Helpers
========================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function norm_price($raw){
    $s = trim((string)$raw);
    if ($s === '') return null;
    // quita $ y espacios
    $s = str_replace(array('$',' '), '', $s);
    // coma decimal si no hay punto
    if (strpos($s, ',') !== false && strpos($s, '.') === false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        // miles con coma
        $s = str_replace(',', '', $s);
    }
    if (!is_numeric($s)) return null;
    $v = (float)$s;
    return $v >= 0 ? $v : null;
}

function detect_delimiter($line){
    $c = substr_count($line, ',');
    $s = substr_count($line, ';');
    return ($s > $c) ? ';' : ',';
}

function read_csv_assoc($tmpPath, &$errores, $requiredHeaders){
    $errores = array();
    $fh = @fopen($tmpPath, 'r');
    if (!$fh) { $errores[] = 'No se pudo abrir el archivo.'; return array(array(), array()); }

    $first = fgets($fh);
    if ($first === false) { $errores[] = 'Archivo vacío.'; fclose($fh); return array(array(), array()); }

    // Limpia BOM y NBSP de la primera línea
    $first = str_replace(array("\xEF\xBB\xBF", "\xC2\xA0"), '', $first);
    $delim = detect_delimiter($first);
    rewind($fh);

    // Leer encabezados
    $row = fgetcsv($fh, 0, $delim);
    if ($row === false) { $errores[] = 'No se pudieron leer los encabezados.'; fclose($fh); return array(array(), array()); }

    $headers = array();
    foreach ($row as $hname) {
        // normaliza encabezados: minúsculas + trim + sin BOM/NBSP
        $h = strtolower(trim(str_replace(array("\xEF\xBB\xBF", "\xC2\xA0"), '', $hname)));
        $headers[] = $h;
    }

    // Validar requeridos
    foreach ($requiredHeaders as $req) {
        if (!in_array($req, $headers, true)) {
            $errores[] = 'Falta encabezado: '.$req;
        }
    }
    if (!empty($errores)) { fclose($fh); return array(array(), array()); }

    // Mapa de índice->nombre
    $idxMap = array();
    foreach ($headers as $i => $hn) { $idxMap[$i] = $hn; }

    // Leer filas
    $rows = array();
    while (($r = fgetcsv($fh, 0, $delim)) !== false) {
        if (count($r) === 1 && trim($r[0]) === '') continue;
        $assoc = array();
        foreach ($r as $i => $val) {
            $key = isset($idxMap[$i]) ? $idxMap[$i] : ('col'.$i);
            // Limpia NBSP en celdas
            $assoc[$key] = trim(str_replace("\xC2\xA0", ' ', (string)$val));
        }
        $rows[] = $assoc;
    }
    fclose($fh);
    return array($headers, $rows);
}

/* ==========================================================
   CARGA MASIVA CSV (codigo_producto, precio_lista, precio_combo, promocion)
========================================================== */
define('PREVIEW_LIMIT', 200);
define('MAX_FILE_SIZE_MB', 8);
// precio_combo y promocion son opcionales, precio_lista sí es requerido
$REQUIRED_HEADERS = array('codigo_producto','precio_lista');

$step = (isset($_POST['accion']) && $_POST['accion']==='masivo' && isset($_POST['step'])) ? $_POST['step'] : 'form';
$result = array(
    'total'      => 0,
    'prev_rows'  => array(),
    'ok'         => 0,
    'not_found'  => 0,
    'invalid'    => 0,
    'duplicates' => 0,
    'updated'    => array(),
    'skipped'    => array(),
    'errors'     => array()
);

if (isset($_POST['accion']) && $_POST['accion']==='masivo' && ($step==='preview' || $step==='aplicar')) {
    $hasFile = (isset($_FILES['csv']) && isset($_FILES['csv']['error']) && $_FILES['csv']['error'] === UPLOAD_ERR_OK);
    if (!$hasFile) {
        $result['errors'][] = 'Sube un archivo CSV válido.';
        $step = 'form';
    } else {
        $sizeBytes = isset($_FILES['csv']['size']) ? (int)$_FILES['csv']['size'] : 0;
        if (($sizeBytes/(1024*1024)) > MAX_FILE_SIZE_MB) {
            $result['errors'][] = 'El archivo excede el tamaño permitido.';
            $step = 'form';
        } else {
            $tmp = $_FILES['csv']['tmp_name'];
            $errs = array();
            list($headers, $rows) = read_csv_assoc($tmp, $errs, $REQUIRED_HEADERS);
            if (!empty($errs)) {
                $result['errors'] = array_merge($result['errors'], $errs);
                $step = 'form';
            } else {
                $result['total'] = count($rows);

                $clean = array();
                $seen  = array();

                $hasPrecioComboCol = in_array('precio_combo', $headers, true);
                $hasPromoCol       = in_array('promocion',    $headers, true);

                for ($i=0; $i<count($rows); $i++){
                    $r = $rows[$i];
                    $line = $i + 2;

                    $codigo      = isset($r['codigo_producto']) ? trim($r['codigo_producto']) : '';
                    $precioLista = isset($r['precio_lista']) ? norm_price($r['precio_lista']) : null;
                    $precioCombo = null;
                    $promoText   = null;

                    if ($hasPrecioComboCol) {
                        $precioCombo = isset($r['precio_combo']) ? norm_price($r['precio_combo']) : null;
                    }
                    if ($hasPromoCol) {
                        $promoRaw = isset($r['promocion']) ? trim($r['promocion']) : '';
                        $promoText = ($promoRaw !== '') ? $promoRaw : null;
                    }

                    if ($codigo === '' || $precioLista === null) {
                        $result['invalid']++;
                        $rawPrecio = isset($r['precio_lista']) ? $r['precio_lista'] : '';
                        $result['skipped'][] = 'L'.$line.": inválido (codigo='".$codigo."', precio_lista='".$rawPrecio."')";
                        continue;
                    }
                    if (isset($seen[$codigo])) {
                        $result['duplicates']++;
                        $result['skipped'][] = 'L'.$line.": duplicado en archivo para codigo '".$codigo."' (se usa la primera aparición).";
                        continue;
                    }
                    $seen[$codigo] = true;
                    $clean[] = array(
                        'codigo_producto' => $codigo,
                        'precio_lista'    => $precioLista,
                        'precio_combo'    => $precioCombo,
                        'promocion'       => $promoText,
                        'line'            => $line
                    );
                }

                if ($step === 'preview') {
                    $result['prev_rows'] = array_slice($clean, 0, PREVIEW_LIMIT);
                    $step = 'show_preview';
                } else {
                    // ===========================================
                    // Aplicar cambios (carga masiva)
                    // - Resetea precio_combo y promocion a NULL
                    //   para equipos con inventario Disponible / En tránsito
                    // - Actualiza precio_lista, precio_combo y promocion
                    //   de TODOS los productos con el mismo codigo_producto
                    //   siempre que tengan inventario Disponible / En tránsito
                    // ===========================================
                    $conn->begin_transaction();
                    try {
                        // 1) Reset global de combos y promos (solo equipos con inventario Disponible / En tránsito)
                        $sqlReset = "
                            UPDATE productos p
                            JOIN inventario i ON i.id_producto = p.id
                            SET p.precio_combo = NULL,
                                p.promocion   = NULL
                            WHERE TRIM(i.estatus) IN ('Disponible','En tránsito')
                              AND p.tipo_producto = 'Equipo'
                        ";
                        $conn->query($sqlReset);

                        // 2) Select de productos por codigo_producto + inventario Disponible/En tránsito
                        $stmtSel = $conn->prepare("
                            SELECT DISTINCT p.id, p.precio_lista, p.precio_combo, p.promocion
                            FROM productos p
                            JOIN inventario i ON i.id_producto = p.id
                            WHERE p.codigo_producto = ?
                              AND TRIM(i.estatus) IN ('Disponible','En tránsito')
                              AND p.tipo_producto = 'Equipo'
                        ");

                        // 3) Update de precio_lista, precio_combo y promocion
                        $stmtUpd = $conn->prepare("
                            UPDATE productos
                            SET precio_lista = ?, precio_combo = ?, promocion = ?
                            WHERE id = ?
                        ");

                        foreach ($clean as $row) {
                            $codigo      = $row['codigo_producto'];
                            $precioLista = $row['precio_lista'];
                            $precioCombo = $row['precio_combo']; // puede ser null
                            $promoText   = $row['promocion'];    // puede ser null

                            $stmtSel->bind_param('s', $codigo);
                            $stmtSel->execute();
                            $res = $stmtSel->get_result();

                            if (!$res || $res->num_rows === 0) {
                                $result['not_found']++;
                                $result['skipped'][] = 'L'.$row['line'].": no hay productos con codigo_producto '".$codigo."' y inventario Disponible/En tránsito";
                                continue;
                            }

                            // Normalizamos combo: si viene null o <=0, se queda NULL (sin combo)
                            $precioComboDB = ($precioCombo !== null && $precioCombo > 0) ? $precioCombo : null;
                            // Promoción: texto o NULL
                            $promoDB = ($promoText !== null && $promoText !== '') ? $promoText : null;

                            while ($prod = $res->fetch_assoc()) {
                                $idProd        = (int)$prod['id'];
                                $precioAntList = isset($prod['precio_lista']) ? (float)$prod['precio_lista'] : 0.0;

                                $stmtUpd->bind_param('ddsi', $precioLista, $precioComboDB, $promoDB, $idProd);
                                if ($stmtUpd->execute()) {
                                    $result['ok']++;
                                    $result['updated'][] = array(
                                        'codigo_producto'    => $codigo,
                                        'precio_anterior'    => $precioAntList,
                                        'precio_nuevo'       => (float)$precioLista,
                                        'precio_combo_nuevo' => $precioComboDB,
                                        'promocion_nueva'    => $promoDB
                                    );
                                } else {
                                    $result['invalid']++;
                                    $result['skipped'][] = 'L'.$row['line'].": error al actualizar '".$codigo."' (id_producto=".$idProd.")";
                                }
                            }
                        }

                        $stmtSel->close();
                        $stmtUpd->close();

                        $conn->commit();
                        $step = 'done';
                    } catch (Exception $e) {
                        $conn->rollback();
                        $result['errors'][] = 'Se canceló la operación: '.$e->getMessage();
                        $step = 'form';
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Actualizar Precios (masivo CSV)</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
</head>
<body class="bg-light">

<div class="container mt-4">

    <h2>💰 Actualizar Precios</h2>
    <p>
      Carga un <b>CSV</b> con columnas:<br>
      <code>codigo_producto, precio_lista, precio_combo, promocion</code><br>
      <small class="text-muted">
        - <b>precio_lista</b> es obligatorio.<br>
        - <b>precio_combo</b> es opcional (vacío o 0 = sin combo).<br>
        - <b>promocion</b> es opcional (vacío = sin promo).<br>
        - Solo se actualizan productos tipo <b>Equipo</b> que tengan inventario en estatus
          <code>Disponible</code> o <code>En tránsito</code>.<br>
        - Antes de aplicar, se limpian <code>precio_combo</code> y <code>promocion</code> a
          <code>NULL</code> para todos esos equipos; los combos y promos vigentes serán solo los del archivo.
      </small>
      <br>
      <a href="?plantilla=1">Descargar plantilla</a>.
    </p>

    <div class="row">
      <div class="col-lg-8 col-md-10">
        <div class="card p-3 shadow-sm bg-white">
          <h5 class="mb-3">Actualización masiva por CSV</h5>

          <?php if (!empty($result['errors']) && $step==='form'): ?>
            <div class="alert alert-danger">
              <ul class="mb-0"><?php foreach($result['errors'] as $e){ echo '<li>'.h($e).'</li>'; } ?></ul>
            </div>
          <?php endif; ?>

          <?php if ($step === 'form'): ?>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="accion" value="masivo">
              <input type="hidden" name="step" value="preview">
              <div class="mb-3">
                <label class="form-label">Archivo CSV</label>
                <input type="file" name="csv" class="form-control" accept=".csv" required>
                <div class="form-text">
                  Columnas requeridas: <code>codigo_producto, precio_lista</code>.<br>
                  Columnas opcionales: <code>precio_combo, promocion</code>.<br>
                  Acepta coma o punto y coma; soporta <code>$</code>, comas y punto decimal.
                </div>
              </div>
              <button class="btn btn-outline-primary" type="submit">Previsualizar</button>
            </form>

          <?php elseif ($step === 'show_preview'): ?>
            <p>Total filas válidas: <b><?php echo count($result['prev_rows']); ?></b> (mostrando hasta <?php echo PREVIEW_LIMIT; ?>)</p>
            <?php if ($result['invalid'] || $result['duplicates']): ?>
              <div class="alert alert-warning">
                Inválidas: <?php echo (int)$result['invalid']; ?> · Duplicadas en archivo: <?php echo (int)$result['duplicates']; ?>
              </div>
            <?php endif; ?>

            <div class="table-responsive" style="max-height:320px">
              <table class="table table-sm">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>codigo_producto</th>
                    <th>precio_lista</th>
                    <th>precio_combo</th>
                    <th>promocion</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach($result['prev_rows'] as $i=>$r): ?>
                  <tr>
                    <td><?php echo $i+1; ?></td>
                    <td><?php echo h($r['codigo_producto']); ?></td>
                    <td><?php echo number_format((float)$r['precio_lista'],2); ?></td>
                    <td>
                      <?php
                        if ($r['precio_combo'] !== null) {
                            echo number_format((float)$r['precio_combo'],2);
                        } else {
                            echo '—';
                        }
                      ?>
                    </td>
                    <td><?php echo $r['promocion'] !== null ? h($r['promocion']) : '—'; ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <?php if (!empty($result['skipped'])): ?>
              <details class="mt-2">
                <summary>Omitidas / Observaciones</summary>
                <ul><?php foreach($result['skipped'] as $s){ echo '<li>'.h($s).'</li>'; } ?></ul>
              </details>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="mt-3">
              <input type="hidden" name="accion" value="masivo">
              <input type="hidden" name="step" value="aplicar">
              <input type="file" name="csv" class="form-control mb-2" accept=".csv" required>
              <button class="btn btn-primary" type="submit">Aplicar cambios</button>
              <a class="btn btn-secondary" href="actualizar_precios_modelo.php">Cancelar</a>
            </form>

          <?php elseif ($step === 'done'): ?>
            <div class="alert alert-success">
              <div><b>Total en archivo:</b> <?php echo (int)$result['total']; ?></div>
              <div><b>Actualizados (productos con inventario Disponible/En tránsito):</b> <?php echo (int)$result['ok']; ?></div>
              <div><b>Sin match / sin Disponible/En tránsito:</b> <?php echo (int)$result['not_found']; ?></div>
              <div><b>Duplicados en archivo:</b> <?php echo (int)$result['duplicates']; ?></div>
              <div><b>Inválidos/errores:</b> <?php echo (int)$result['invalid']; ?></div>
            </div>

            <?php if (!empty($result['updated'])): ?>
              <div class="table-responsive" style="max-height:320px">
                <table class="table table-sm">
                  <thead>
                    <tr>
                      <th>#</th>
                      <th>codigo_producto</th>
                      <th>precio_anterior</th>
                      <th>precio_nuevo</th>
                      <th>precio_combo</th>
                      <th>promocion</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php
                  $lim = min(100, count($result['updated']));
                  for ($i=0; $i<$lim; $i++){
                      $u = $result['updated'][$i];
                      echo '<tr>';
                      echo '<td>'.($i+1).'</td>';
                      echo '<td>'.h($u['codigo_producto']).'</td>';
                      echo '<td>'.number_format((float)$u['precio_anterior'],2).'</td>';
                      echo '<td><b>'.number_format((float)$u['precio_nuevo'],2).'</b></td>';
                      echo '<td>';
                      if ($u['precio_combo_nuevo'] !== null) {
                          echo '<b>'.number_format((float)$u['precio_combo_nuevo'],2).'</b>';
                      } else {
                          echo '—';
                      }
                      echo '</td>';
                      echo '<td>'.($u['promocion_nueva'] !== null ? h($u['promocion_nueva']) : '—').'</td>';
                      echo '</tr>';
                  }
                  ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <?php if (!empty($result['skipped'])): ?>
              <details class="mt-2">
                <summary>Omitidas / Observaciones</summary>
                <ul><?php foreach($result['skipped'] as $s){ echo '<li>'.h($s).'</li>'; } ?></ul>
              </details>
            <?php endif; ?>

            <a class="btn btn-outline-primary mt-2" href="actualizar_precios_modelo.php">Nueva carga</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
</div>

</body>
</html>
