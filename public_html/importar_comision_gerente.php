<?php
// importar_comision_gerente.php — Actualiza detalle_venta.comision_gerente
// CSV soportados (con encabezados):
// 1) id_detalle,comision_gerente
// 2) id_venta,imei,comision_gerente   [imei puede ser IMEI1 o IMEI2]
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

/* ===== Permisos ===== */
$rol = $_SESSION['rol'] ?? '';
$ALLOW_ROLES = ['Admin','Gerente','GerenteZona','Super'];
if (!in_array($rol, $ALLOW_ROLES, true)) {
  http_response_code(403);
  echo "<h3>403 — No autorizado</h3>";
  exit();
}

/* ===== Helpers ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function normalize_decimal($s){
  $s = trim((string)$s);
  $s = str_replace([' ', "\xC2\xA0"], '', $s);
  if (preg_match('/^\d{1,3}(\.\d{3})+,\d+$/', $s)) { $s=str_replace('.','',$s); $s=str_replace(',', '.', $s); }
  elseif (strpos($s, ',') !== false && strpos($s, '.') === false) { $s=str_replace(',', '.', $s); }
  return $s;
}
function csv_to_array($tmpPath, $delimiter = null, $encoding = null){
  $rows = [];
  $raw = file_get_contents($tmpPath);
  if ($raw === false) return $rows;
  if ($encoding === null) {
    $encoding = mb_detect_encoding($raw, ['UTF-8','ISO-8859-1','Windows-1252'], true) ?: 'UTF-8';
  }
  if ($encoding !== 'UTF-8') $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
  if ($delimiter === null) {
    $comma = substr_count($raw, ','); $semicolon = substr_count($raw, ';');
    $delimiter = ($semicolon > $comma) ? ';' : ',';
  }
  $fp = fopen('php://memory', 'r+'); fwrite($fp, $raw); rewind($fp);
  while (($data = fgetcsv($fp, 0, $delimiter)) !== false) {
    foreach ($data as &$v) { $v = trim((string)$v, "\xEF\xBB\xBF \t\n\r\0\x0B"); }
    $rows[] = $data;
  }
  fclose($fp);
  return $rows;
}

/* ===== Plantillas ===== */
if (isset($_GET['plantilla']) && $_GET['plantilla'] === 'detalle') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename=plantilla_comision_gerente_por_id_detalle.csv');
  echo "id_detalle,comision_gerente\n10001,25\n10002,75\n10003,100\n"; exit;
}
if (isset($_GET['plantilla']) && $_GET['plantilla'] === 'venta_imei') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename=plantilla_comision_gerente_por_venta_imei.csv');
  echo "id_venta,imei,comision_gerente\n1234,864500077887242,75\n1234,351874533227016,100\n"; exit;
}

$debug = isset($_GET['debug']);
$dry   = isset($_POST['dry_run']);

$results = [
  'total' => 0,
  'actualizados' => 0,
  'saltados' => 0,
  'no_encontrados' => 0,
  'ambiguos' => 0,
  'errores' => 0,
  'detalles' => []
];

/* ===== Proceso ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
  $rows = csv_to_array($_FILES['csv']['tmp_name']);
  if (count($rows) === 0) {
    $results['errores']++;
    $results['detalles'][] = "Archivo CSV vacío o ilegible.";
  } else {
    // Mapea encabezados
    $headers = array_map('mb_strtolower', $rows[0]);
    $map = ['id_detalle'=>null, 'id_venta'=>null, 'imei'=>null, 'comision_gerente'=>null];
    foreach ($headers as $i => $hname) {
      $hn = preg_replace('/\s+/', '_', trim($hname));
      if ($hn==='id_detalle' || $hn==='iddetalle' || $hn==='id_detalle_venta') $map['id_detalle']=$i;
      if ($hn==='id_venta' || $hn==='idventa') $map['id_venta']=$i;
      if ($hn==='imei' || $hn==='imei1' || $hn==='imei_venta') $map['imei']=$i;
      if ($hn==='comision_gerente' || $hn==='comisiongerente') $map['comision_gerente']=$i;
    }
    if ($map['comision_gerente'] === null ||
        ($map['id_detalle'] === null && ($map['id_venta'] === null || $map['imei'] === null))) {
      $results['errores']++;
      $results['detalles'][] = "Encabezados válidos: [id_detalle, comision_gerente] **o** [id_venta, imei, comision_gerente].";
    } else {
      // Prepara statements
      $stmtUpdById   = $conn->prepare("UPDATE detalle_venta SET comision_gerente=? WHERE id=?");
      $stmtFindByVI  = $conn->prepare("
        SELECT dv.id
        FROM detalle_venta dv
        LEFT JOIN productos p ON p.id = dv.id_producto
        WHERE dv.id_venta = ?
          AND (dv.imei1 = ? OR p.imei2 = ?)
        LIMIT 2
      ");
      if (!$stmtUpdById || !$stmtFindByVI) {
        $results['errores']++;
        $results['detalles'][] = "Error preparando SQL: ".h($conn->error);
      } else {
        $conn->begin_transaction();
        try {
          for ($i=1; $i<count($rows); $i++) {
            $results['total']++;
            $row = $rows[$i];

            $cg  = isset($row[$map['comision_gerente']]) ? normalize_decimal($row[$map['comision_gerente']]) : '';
            if ($cg === '' || !is_numeric($cg)) {
              $results['saltados']++; $results['detalles'][] = "Fila $i: comision_gerente vacío o no numérico."; continue;
            }
            $cgF = (float)$cg;

            // Ruta 1: id_detalle directo
            if ($map['id_detalle'] !== null && isset($row[$map['id_detalle']]) && trim((string)$row[$map['id_detalle']])!=='') {
              $idDet = trim((string)$row[$map['id_detalle']]);
              if (!ctype_digit($idDet)) { $results['saltados']++; $results['detalles'][]="Fila $i: id_detalle no numérico."; continue; }

              if ($dry) {
                // Checa existencia
                $chk = $conn->prepare("SELECT 1 FROM detalle_venta WHERE id=? LIMIT 1");
                $idDetI=(int)$idDet; $chk->bind_param('i',$idDetI); $chk->execute();
                $has = $chk->get_result()->num_rows>0; $chk->close();
                if ($has) { $results['actualizados']++; $results['detalles'][]="DRY-RUN Fila $i: actualizaría id_detalle=$idDetI a $cgF"; }
                else { $results['no_encontrados']++; $results['detalles'][]="DRY-RUN Fila $i: id_detalle=$idDet no existe."; }
              } else {
                $idDetI=(int)$idDet; $stmtUpdById->bind_param('di', $cgF, $idDetI); $stmtUpdById->execute();
                if ($stmtUpdById->affected_rows>0) $results['actualizados']++;
                else { // sin cambios o inexistente
                  $chk = $conn->prepare("SELECT 1 FROM detalle_venta WHERE id=? LIMIT 1");
                  $chk->bind_param('i',$idDetI); $chk->execute();
                  $has = $chk->get_result()->num_rows>0; $chk->close();
                  if ($has) { $results['saltados']++; $results['detalles'][]="Fila $i: sin cambios (mismo valor) en id_detalle=$idDetI."; }
                  else { $results['no_encontrados']++; $results['detalles'][]="Fila $i: id_detalle=$idDetI no existe."; }
                }
              }
              continue;
            }

            // Ruta 2: id_venta + IMEI
            $idVenta = isset($row[$map['id_venta']]) ? trim((string)$row[$map['id_venta']]) : '';
            $imei    = isset($row[$map['imei']]) ? trim((string)$row[$map['imei']]) : '';
            if ($idVenta === '' || !ctype_digit($idVenta)) {
              $results['saltados']++; $results['detalles'][] = "Fila $i: id_venta vacío/no numérico (y no hay id_detalle)."; continue;
            }
            if ($imei === '') {
              $results['saltados']++; $results['detalles'][] = "Fila $i: IMEI vacío (y no hay id_detalle)."; continue;
            }

            // fuerza string exacto como viene (Excel a veces mutila), por eso no casteamos
            $idVentaI = (int)$idVenta;
            $stmtFindByVI->bind_param('iss', $idVentaI, $imei, $imei);
            $stmtFindByVI->execute();
            $rs = $stmtFindByVI->get_result();
            $n  = $rs->num_rows;

            if ($n === 0) {
              $results['no_encontrados']++; $results['detalles'][] = "Fila $i: no hallé detalle para id_venta=$idVentaI con IMEI=$imei (ni en imei1 ni en imei2)."; continue;
            }
            if ($n > 1) {
              $results['ambiguos']++; $results['detalles'][] = "Fila $i: IMEI=$imei con id_venta=$idVentaI devuelve $n filas (ambigüo). Skipped."; continue;
            }
            $idDetI = (int)$rs->fetch_assoc()['id'];

            if ($dry) {
              $results['actualizados']++; $results['detalles'][] = "DRY-RUN Fila $i: actualizaría id_detalle=$idDetI a $cgF";
            } else {
              $stmtUpdById->bind_param('di', $cgF, $idDetI);
              $stmtUpdById->execute();
              if ($stmtUpdById->affected_rows>0) $results['actualizados']++; else $results['saltados']++;
            }
          }

          if ($dry) $conn->rollback(); else $conn->commit();
        } catch (Throwable $e) {
          $conn->rollback();
          $results['errores']++;
          $results['detalles'][] = "Transacción revertida: ".h($e->getMessage());
        }
      }

      if (isset($stmtUpdById)) $stmtUpdById->close();
      if (isset($stmtFindByVI)) $stmtFindByVI->close();
    }
  }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Importar Comisión Gerente (detalle_venta)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; margin:20px; color:#222}
.card{border:1px solid #ddd; border-radius:12px; padding:18px; max-width:980px; box-shadow:0 2px 10px rgba(0,0,0,0.04)}
h1{font-size:20px; margin:0 0 16px}
label{display:block; margin:10px 0 6px}
input[type=file]{padding:8px; border:1px solid #ccc; border-radius:8px; width:100%}
button{padding:10px 16px; border:0; border-radius:10px; cursor:pointer}
.btn{background:#111; color:#fff}
.btn.secondary{background:#f5f5f5; color:#111; margin-left:8px}
.grid{display:grid; grid-template-columns:repeat(3,1fr); gap:12px}
.kpi{background:#fafafa; border:1px solid #eee; border-radius:10px; padding:10px}
.log{background:#fbfbfb; border:1px dashed #ddd; padding:10px; border-radius:10px; max-height:280px; overflow:auto; white-space:pre-wrap}
.small{color:#666; font-size:12px}
</style>
</head>
<body>
  <div class="card">
    <h1>Importar Comisión de Gerente → <code>detalle_venta.comision_gerente</code></h1>
    <p class="small">
      Formatos soportados:
      <ul>
        <li><b>id_detalle, comision_gerente</b> — <a href="?plantilla=detalle">descargar plantilla</a></li>
        <li><b>id_venta, imei, comision_gerente</b> — <a href="?plantilla=venta_imei">descargar plantilla</a></li>
      </ul>
      En el segundo formato, el IMEI se busca en <code>detalle_venta.imei1</code> o <code>productos.imei2</code>.
    </p>

    <form method="post" enctype="multipart/form-data">
      <label>Archivo CSV</label>
      <input type="file" name="csv" accept=".csv,text/csv" required>
      <div style="margin:10px 0;">
        <label><input type="checkbox" name="dry_run" <?= $dry?'checked':''; ?>> Simulación (dry-run, no guarda cambios)</label>
      </div>
      <button class="btn" type="submit">Procesar</button>
      <a class="btn secondary" href="?debug=1">Debug</a>
    </form>
  </div>

<?php if ($results['total'] + $results['errores'] + $results['saltados'] + $results['no_encontrados'] + $results['ambiguos'] + $results['actualizados'] > 0): ?>
  <div class="card" style="margin-top:16px;">
    <h1>Resultado</h1>
    <div class="grid">
      <div class="kpi"><b>Filas CSV</b><br><?= (int)$results['total'] ?></div>
      <div class="kpi"><b>Actualizados</b><br><?= (int)$results['actualizados'] ?></div>
      <div class="kpi"><b>Saltados</b><br><?= (int)$results['saltados'] ?></div>
      <div class="kpi"><b>No encontrados</b><br><?= (int)$results['no_encontrados'] ?></div>
      <div class="kpi"><b>Ambiguos</b><br><?= (int)$results['ambiguos'] ?></div>
      <div class="kpi"><b>Errores</b><br><?= (int)$results['errores'] ?></div>
    </div>
    <h3>Detalle</h3>
    <div class="log"><?= h(implode("\n", $results['detalles'])) ?: 'Sin mensajes adicionales.' ?></div>
  </div>
<?php endif; ?>
</body>
</html>
