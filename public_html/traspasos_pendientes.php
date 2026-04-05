<?php
// traspasos_pendientes.php — Recepción de traspasos (Gerente/Destino)
// Compatible sin mysqlnd (no usa ->get_result())

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Mexico_City');

/* ===== Debug seguro para producción (visible solo a Admin/Logistica) ===== */
$__IS_ADMIN = in_array(($_SESSION['rol'] ?? ''), ['Admin','Logistica'], true);
if ($__IS_ADMIN && isset($_GET['debug'])) {
  error_reporting(E_ALL);
  ini_set('display_errors', '1');
  mysqli_report(MYSQLI_REPORT_OFF); // no excepciones automáticas

  function ping(mysqli $c, string $q){
    $rs = $c->query($q);
    if (!$rs) {
      echo "<pre style='white-space:pre-wrap;background:#111;color:#eee;padding:12px;border-radius:8px'>❌ SQL FAIL:\n$q\n--\n".
           htmlspecialchars($c->error, ENT_QUOTES, 'UTF-8')."</pre>";
      return false;
    }
    echo "<pre style='white-space:pre-wrap;background:#0b3;color:#efe;padding:12px;border-radius:8px'>✅ SQL OK:\n$q</pre>";
    return $rs;
  }

  echo "<div style='font:14px/1.45 system-ui,Segoe UI,Roboto,sans-serif;margin:10px 0;padding:12px;border-radius:8px;background:#f1f5f9'>
  <b>DEBUG ACTIVO</b> (solo tú lo ves). Vamos a probar piezas clave…</div>";

  echo "<pre style='background:#222;color:#ddd;padding:10px;border-radius:8px'>PHP: ".PHP_VERSION." | mysqli: ".(function_exists('mysqli_connect')?'sí':'no')."</pre>";

  $checks = [
    "SHOW COLUMNS FROM `traspasos` LIKE 'id_sucursal_destino'",
    "SHOW COLUMNS FROM `traspasos` LIKE 'estatus'",
    "SHOW COLUMNS FROM `traspasos` LIKE 'id_garantia'",
    "SHOW COLUMNS FROM `detalle_traspaso` LIKE 'id_inventario'",
    "SHOW COLUMNS FROM `detalle_traspaso_acc` LIKE 'id_inventario_origen'",
    "SHOW COLUMNS FROM `detalle_traspaso_acc` LIKE 'cantidad'",
    "SHOW COLUMNS FROM `inventario` LIKE 'cantidad'",
    "SHOW TABLES LIKE 'traspasos_eventos'",
    "SHOW TABLES LIKE 'garantias_eventos'",
  ];
  foreach ($checks as $q) { ping($conn, $q); }

  $testId = (int)($conn->query("SELECT IFNULL(MIN(id),0) FROM traspasos WHERE estatus='Pendiente'")->fetch_row()[0] ?? 0);
  echo "<pre style='background:#333;color:#ddd;padding:10px;border-radius:8px'>Traspaso ejemplo: #$testId</pre>";
  if ($testId > 0) {
    ping($conn, "
      SELECT dt.id AS det_id,'equipo' AS tipo,i.id AS id_inv,p.marca,p.modelo,p.color,p.imei1,p.imei2,1 AS cantidad
      FROM detalle_traspaso dt
      JOIN inventario i ON i.id=dt.id_inventario
      JOIN productos p ON p.id=i.id_producto
      WHERE dt.id_traspaso=$testId
      UNION ALL
      SELECT dta.id,'accesorio',i.id,p.marca,p.modelo,p.color,NULL,NULL,dta.cantidad
      FROM detalle_traspaso_acc dta
      JOIN inventario i ON i.id=dta.id_inventario_origen
      JOIN productos p ON p.id=i.id_producto
      WHERE dta.id_traspaso=$testId
      ORDER BY tipo DESC, modelo, color, det_id
    ");
  }

  ping($conn, "
    SELECT t.id, t.fecha_traspaso, t.id_garantia, s.nombre AS sucursal_origen, u.nombre AS usuario_creo
    FROM traspasos t
    INNER JOIN sucursales s ON s.id = t.id_sucursal_origen
    INNER JOIN usuarios  u ON u.id = t.usuario_creo
    WHERE t.id_sucursal_destino = ".(int)($_SESSION['id_sucursal'] ?? 0)." AND t.estatus='Pendiente'
    ORDER BY t.fecha_traspaso ASC, t.id ASC
  ");
}

$idSucursalUsuario = (int)($_SESSION['id_sucursal'] ?? 0);
$idUsuario         = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario        = $_SESSION['rol'] ?? '';
$whereSucursal     = "id_sucursal_destino = $idSucursalUsuario";

$mensaje    = "";
$acuseUrl   = "";
$acuseReady = false;

/* ==========================================================
   Helpers
========================================================== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function hasColumn(mysqli $conn, string $table, string $column): bool {
  $table  = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
  return $rs && $rs->num_rows > 0;
}

function hasTable(mysqli $conn, string $table): bool {
  $table = $conn->real_escape_string($table);
  $rs = $conn->query("SHOW TABLES LIKE '$table'");
  return $rs && $rs->num_rows > 0;
}

function devNote($msg){
  $ROL = $_SESSION['rol'] ?? '';
  if (in_array($ROL, ['Admin','Logistica'], true)) {
    return "<div class='alert alert-warning small mt-2'><b>Nota técnica:</b> ".h($msg)."</div>";
  }
  return "";
}

function registrarEventoTraspaso(mysqli $conn, int $idTraspaso, int $idUsuario, string $evento): void {
  if (!hasTable($conn, 'traspasos_eventos')) return;

  $cols = [];
  if (hasColumn($conn, 'traspasos_eventos', 'id_traspaso'))  $cols[] = 'id_traspaso';
  if (hasColumn($conn, 'traspasos_eventos', 'id_usuario'))   $cols[] = 'id_usuario';
  if (hasColumn($conn, 'traspasos_eventos', 'evento'))       $cols[] = 'evento';
  if (hasColumn($conn, 'traspasos_eventos', 'descripcion'))  $cols[] = 'descripcion';
  if (hasColumn($conn, 'traspasos_eventos', 'fecha_evento')) $cols[] = 'fecha_evento';
  if (hasColumn($conn, 'traspasos_eventos', 'created_at'))   $cols[] = 'created_at';

  if (!in_array('id_traspaso', $cols, true)) return;

  $insertCols = [];
  $insertVals = [];

  foreach ($cols as $c) {
    $insertCols[] = $c;
    if ($c === 'id_traspaso')      $insertVals[] = (string)$idTraspaso;
    elseif ($c === 'id_usuario')   $insertVals[] = (string)$idUsuario;
    elseif ($c === 'evento')       $insertVals[] = "'".$conn->real_escape_string($evento)."'";
    elseif ($c === 'descripcion')  $insertVals[] = "'".$conn->real_escape_string($evento)."'";
    elseif ($c === 'fecha_evento') $insertVals[] = "NOW()";
    elseif ($c === 'created_at')   $insertVals[] = "NOW()";
    else                           $insertVals[] = "NULL";
  }

  $sql = "INSERT INTO traspasos_eventos (`".implode('`,`', $insertCols)."`) VALUES (".implode(',', $insertVals).")";
  @$conn->query($sql);
}

function registrarEventoGarantia(mysqli $conn, int $idGarantia, int $idUsuario, string $evento): void {
  if ($idGarantia <= 0) return;
  if (!hasTable($conn, 'garantias_eventos')) return;

  $cols = [];
  if (hasColumn($conn, 'garantias_eventos', 'id_garantia'))   $cols[] = 'id_garantia';
  if (hasColumn($conn, 'garantias_eventos', 'id_usuario'))    $cols[] = 'id_usuario';
  if (hasColumn($conn, 'garantias_eventos', 'evento'))        $cols[] = 'evento';
  if (hasColumn($conn, 'garantias_eventos', 'descripcion'))   $cols[] = 'descripcion';
  if (hasColumn($conn, 'garantias_eventos', 'fecha_evento'))  $cols[] = 'fecha_evento';
  if (hasColumn($conn, 'garantias_eventos', 'created_at'))    $cols[] = 'created_at';

  if (!in_array('id_garantia', $cols, true)) return;

  $insertCols = [];
  $insertVals = [];

  foreach ($cols as $c) {
    $insertCols[] = $c;
    if ($c === 'id_garantia')      $insertVals[] = (string)$idGarantia;
    elseif ($c === 'id_usuario')   $insertVals[] = (string)$idUsuario;
    elseif ($c === 'evento')       $insertVals[] = "'".$conn->real_escape_string($evento)."'";
    elseif ($c === 'descripcion')  $insertVals[] = "'".$conn->real_escape_string($evento)."'";
    elseif ($c === 'fecha_evento') $insertVals[] = "NOW()";
    elseif ($c === 'created_at')   $insertVals[] = "NOW()";
    else                           $insertVals[] = "NULL";
  }

  $sql = "INSERT INTO garantias_eventos (`".implode('`,`', $insertCols)."`) VALUES (".implode(',', $insertVals).")";
  @$conn->query($sql);
}

/* ==========================================================
   Flags de columnas opcionales
========================================================== */
$hasDT_Resultado         = hasColumn($conn, 'detalle_traspaso', 'resultado');
$hasDT_FechaResultado    = hasColumn($conn, 'detalle_traspaso', 'fecha_resultado');
$hasACC_Resultado        = hasColumn($conn, 'detalle_traspaso_acc', 'resultado');
$hasACC_FechaResultado   = hasColumn($conn, 'detalle_traspaso_acc', 'fecha_resultado');
$inventarioTieneCantidad = hasColumn($conn, 'inventario', 'cantidad');

/* ==========================================================
   POST: Recepción parcial / total
========================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['procesar_traspaso'])) {
  $idTraspaso = (int)($_POST['id_traspaso'] ?? 0);

  $recibirEq  = array_map('intval', $_POST['aceptar_eq']  ?? []);
  $recibirAcc = array_map('intval', $_POST['aceptar_acc'] ?? []);

  $sql = "SELECT id, id_sucursal_origen, id_sucursal_destino, IFNULL(id_garantia,0) AS id_garantia
          FROM traspasos
          WHERE id=$idTraspaso AND $whereSucursal AND estatus='Pendiente'
          LIMIT 1";
  $tinfo = null;
  if ($rs = $conn->query($sql)) { $tinfo = $rs->fetch_assoc(); }

  if (!$tinfo) {
    $mensaje = "<div class='alert alert-danger mt-3'>❌ Traspaso inválido o ya procesado.</div>";
  } else {
    $idOrigen    = (int)$tinfo['id_sucursal_origen'];
    $idDestino   = (int)$tinfo['id_sucursal_destino'];
    $idGarantia  = (int)($tinfo['id_garantia'] ?? 0);
    $esGarantia  = $idGarantia > 0;
    $estatusEqOk = $esGarantia ? 'Reemplazado' : 'Disponible';

    // Universo EQUIPOS
    $todosEq = [];
    if ($rs = $conn->query("SELECT id_inventario FROM detalle_traspaso WHERE id_traspaso=$idTraspaso")) {
      while ($r = $rs->fetch_row()) { $todosEq[] = (int)$r[0]; }
    }

    // Universo ACCESORIOS
    $todosAcc = [];
    if ($rs = $conn->query("SELECT id FROM detalle_traspaso_acc WHERE id_traspaso=$idTraspaso")) {
      while ($r = $rs->fetch_row()) { $todosAcc[] = (int)$r[0]; }
    }

    if (empty($todosEq) && empty($todosAcc)) {
      $mensaje = "<div class='alert alert-warning mt-3'>⚠️ El traspaso no contiene productos.</div>";
    } else {
      $recibirEq  = array_values(array_intersect($recibirEq, $todosEq));
      $recibirAcc = array_values(array_intersect($recibirAcc, $todosAcc));
      $rechazarEq  = array_values(array_diff($todosEq, $recibirEq));
      $rechazarAcc = array_values(array_diff($todosAcc, $recibirAcc));

      $conn->begin_transaction();
      try {
        /* -----------------------
           EQUIPOS (por IMEI)
        ------------------------*/
        if (!empty($recibirEq)) {
          $stUpdInv = $conn->prepare("UPDATE inventario SET id_sucursal=?, estatus=? WHERE id=?");
          $stUpdDet = ($hasDT_Resultado || $hasDT_FechaResultado)
            ? $conn->prepare("
                UPDATE detalle_traspaso
                SET ".($hasDT_Resultado ? "resultado='Recibido'," : "").($hasDT_FechaResultado ? " fecha_resultado=NOW()," : "")." id_traspaso=id_traspaso
                WHERE id_traspaso=? AND id_inventario=?")
            : null;

          foreach ($recibirEq as $idInv) {
            $stUpdInv->bind_param("isi", $idDestino, $estatusEqOk, $idInv);
            $stUpdInv->execute();

            if ($stUpdDet) {
              $stUpdDet->bind_param("ii", $idTraspaso, $idInv);
              $stUpdDet->execute();
            }
          }

          $stUpdInv->close();
          if ($stUpdDet) $stUpdDet->close();
        }

        if (!empty($rechazarEq)) {
          $stUpdInv = $conn->prepare("UPDATE inventario SET id_sucursal=?, estatus='Disponible' WHERE id=?");
          $stUpdDet = ($hasDT_Resultado || $hasDT_FechaResultado)
            ? $conn->prepare("
                UPDATE detalle_traspaso
                SET ".($hasDT_Resultado ? "resultado='Rechazado'," : "").($hasDT_FechaResultado ? " fecha_resultado=NOW()," : "")." id_traspaso=id_traspaso
                WHERE id_traspaso=? AND id_inventario=?")
            : null;

          foreach ($rechazarEq as $idInv) {
            $stUpdInv->bind_param("ii", $idOrigen, $idInv);
            $stUpdInv->execute();

            if ($stUpdDet) {
              $stUpdDet->bind_param("ii", $idTraspaso, $idInv);
              $stUpdDet->execute();
            }
          }

          $stUpdInv->close();
          if ($stUpdDet) $stUpdDet->close();
        }

        /* -----------------------
           ACCESORIOS (por cantidad)
        ------------------------*/
        if (!$inventarioTieneCantidad) {
          throw new Exception("La tabla inventario no tiene columna 'cantidad' para accesorios.");
        }

        $stFindDest = $conn->prepare("SELECT id FROM inventario WHERE id_sucursal=? AND id_producto=? AND estatus='Disponible' LIMIT 1");
        $stInsDest  = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, cantidad, estatus, fecha_ingreso) VALUES (?,?,?,?, NOW())");
        $stUpdDest  = $conn->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE id=?");

        foreach ($recibirAcc as $idDetAcc) {
          $idDetAcc = (int)$idDetAcc;
          $row = null;
          if ($rs = $conn->query("SELECT id_inventario_origen, id_producto, cantidad FROM detalle_traspaso_acc WHERE id=$idDetAcc LIMIT 1")) {
            $row = $rs->fetch_assoc();
          }
          if (!$row) continue;

          $idProd = (int)$row['id_producto'];
          $qty    = (int)$row['cantidad'];

          $stFindDest->bind_param("ii", $idDestino, $idProd);
          $stFindDest->execute();
          $stFindDest->bind_result($idInvDest);

          if ($stFindDest->fetch() && $idInvDest) {
            $stFindDest->free_result();
            $stUpdDest->bind_param("ii", $qty, $idInvDest);
            $stUpdDest->execute();
          } else {
            $stFindDest->free_result();
            $estatus = 'Disponible';
            $stInsDest->bind_param("iiis", $idProd, $idDestino, $qty, $estatus);
            $stInsDest->execute();
          }

          if ($hasACC_Resultado || $hasACC_FechaResultado) {
            $conn->query("
              UPDATE detalle_traspaso_acc
              SET ".($hasACC_Resultado ? "resultado='Recibido'," : "").($hasACC_FechaResultado ? " fecha_resultado=NOW()," : "")." id_traspaso=id_traspaso
              WHERE id={$idDetAcc} AND id_traspaso={$idTraspaso}
            ");
          }
        }

        $stUpdBack    = $conn->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE id=? AND id_sucursal=?");
        $stFindOrigUP = $conn->prepare("SELECT id FROM inventario WHERE id_sucursal=? AND id_producto=? AND estatus='Disponible' LIMIT 1");
        $stInsOrigUP  = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, cantidad, estatus, fecha_ingreso) VALUES (?,?,?,?, NOW())");
        $stUpdOrigUP  = $conn->prepare("UPDATE inventario SET cantidad = cantidad + ? WHERE id=?");

        foreach ($rechazarAcc as $idDetAcc) {
          $idDetAcc = (int)$idDetAcc;
          $row = null;
          if ($rs = $conn->query("SELECT id_inventario_origen, id_producto, cantidad FROM detalle_traspaso_acc WHERE id=$idDetAcc LIMIT 1")) {
            $row = $rs->fetch_assoc();
          }
          if (!$row) continue;

          $qty       = (int)$row['cantidad'];
          $idInvOrig = (int)$row['id_inventario_origen'];
          $idProd    = (int)$row['id_producto'];

          $stUpdBack->bind_param("iii", $qty, $idInvOrig, $idOrigen);
          $stUpdBack->execute();

          if ($stUpdBack->affected_rows === 0) {
            $stFindOrigUP->bind_param("ii", $idOrigen, $idProd);
            $stFindOrigUP->execute();
            $stFindOrigUP->bind_result($idInvO);

            if ($stFindOrigUP->fetch() && $idInvO) {
              $stFindOrigUP->free_result();
              $stUpdOrigUP->bind_param("ii", $qty, $idInvO);
              $stUpdOrigUP->execute();
            } else {
              $stFindOrigUP->free_result();
              $estatus = 'Disponible';
              $stInsOrigUP->bind_param("iiis", $idProd, $idOrigen, $qty, $estatus);
              $stInsOrigUP->execute();
            }
          }

          if ($hasACC_Resultado || $hasACC_FechaResultado) {
            $conn->query("
              UPDATE detalle_traspaso_acc
              SET ".($hasACC_Resultado ? "resultado='Rechazado'," : "").($hasACC_FechaResultado ? " fecha_resultado=NOW()," : "")." id_traspaso=id_traspaso
              WHERE id={$idDetAcc} AND id_traspaso={$idTraspaso}
            ");
          }
        }

        $stFindDest->close();
        $stInsDest->close();
        $stUpdDest->close();
        $stUpdBack->close();
        $stFindOrigUP->close();
        $stInsOrigUP->close();
        $stUpdOrigUP->close();

        /* -----------------------
           Estatus del traspaso
        ------------------------*/
        $totEq   = count($todosEq);
        $totAcc  = count($todosAcc);
        $okEq    = count($recibirEq);
        $okAcc   = count($recibirAcc);
        $totalFilas = $totEq + $totAcc;
        $okFilas    = $okEq  + $okAcc;

        $estatusTraspaso = ($okFilas === 0) ? 'Rechazado' : (($okFilas < $totalFilas) ? 'Parcial' : 'Completado');

        if (hasColumn($conn, 'traspasos', 'fecha_recepcion') && hasColumn($conn, 'traspasos', 'usuario_recibio')) {
          $st = $conn->prepare("UPDATE traspasos SET estatus=?, fecha_recepcion=NOW(), usuario_recibio=? WHERE id=?");
          $st->bind_param("sii", $estatusTraspaso, $idUsuario, $idTraspaso);
        } else {
          $st = $conn->prepare("UPDATE traspasos SET estatus=? WHERE id=?");
          $st->bind_param("si", $estatusTraspaso, $idTraspaso);
        }
        $st->execute();
        $st->close();

        /* -----------------------
           Bitácora opcional
        ------------------------*/
        $eventoTraspaso = $esGarantia
          ? "Recepción de traspaso de garantía procesada. Equipos recibidos marcados como Reemplazado. Estatus final: {$estatusTraspaso}."
          : "Recepción de traspaso normal procesada. Equipos recibidos marcados como Disponible. Estatus final: {$estatusTraspaso}.";

        registrarEventoTraspaso($conn, $idTraspaso, $idUsuario, $eventoTraspaso);

        if ($esGarantia) {
          $eventoGarantia = "Se recibió el traspaso #{$idTraspaso} ligado a garantía. Los equipos aceptados fueron marcados como Reemplazado en inventario.";
          registrarEventoGarantia($conn, $idGarantia, $idUsuario, $eventoGarantia);
        }

        $conn->commit();

        if ($okEq > 0) {
          $idsCsv = implode(',', array_map('intval',$recibirEq));
          $acuseUrl   = "acuse_traspaso.php?id={$idTraspaso}&scope=recibidos&ids=" . urlencode($idsCsv) . "&print=1";
          $acuseReady = true;
        }

        $pzAccOk = 0;
        if (!empty($recibirAcc)) {
          $in = implode(',', array_map('intval',$recibirAcc));
          if ($rs = $conn->query("SELECT IFNULL(SUM(cantidad),0) AS pz FROM detalle_traspaso_acc WHERE id IN ($in)")) {
            $row = $rs->fetch_assoc();
            $pzAccOk = (int)($row['pz'] ?? 0);
          }
        }

        $mensaje = "<div class='alert alert-success border-0 shadow-sm mt-3'>
          <div class='d-flex align-items-start gap-3'>
            <div class='fs-3'>✅</div>
            <div>
              <div class='fw-bold mb-1'>Traspaso #".h($idTraspaso)." procesado correctamente</div>
              <div class='small text-muted mb-2'>".
                ($esGarantia
                  ? "Este traspaso está ligado a garantía. Los equipos aceptados se enviaron a <b>Reemplazado</b>."
                  : "Este traspaso sigue flujo normal. Los equipos aceptados se enviaron a <b>Disponible</b>.")
              ."</div>
              <div class='d-flex flex-wrap gap-2'>
                <span class='badge rounded-pill text-bg-primary'>Equipos recibidos: <b>".h($okEq)."</b></span>
                <span class='badge rounded-pill text-bg-info text-dark'>Accesorios recibidos (pzs): <b>".h($pzAccOk)."</b></span>
                <span class='badge rounded-pill text-bg-secondary'>Estatus: <b>".h($estatusTraspaso)."</b></span>
              </div>
              ".($okEq>0 ? "<div class='small text-muted mt-2'>Se abrirá el acuse de recepción de equipos.</div>" : "")."
            </div>
          </div>
        </div>";
      } catch (Throwable $e) {
        $conn->rollback();
        $mensaje = "<div class='alert alert-danger mt-3'>❌ Error al procesar: ".h($e->getMessage())."</div>";
      }
    }
  }
}

/* ==========================================================
   Carga de pendientes + resumen
========================================================== */
$nomSucursal = '—';
if ($rs = $conn->query("SELECT nombre FROM sucursales WHERE id=$idSucursalUsuario")) {
  if ($row = $rs->fetch_assoc()) { $nomSucursal = $row['nombre']; }
}

$sqlPend = "
  SELECT 
    t.id, t.fecha_traspaso, t.id_garantia,
    s.nombre AS sucursal_origen,
    u.nombre AS usuario_creo
  FROM traspasos t
  INNER JOIN sucursales s ON s.id = t.id_sucursal_origen
  INNER JOIN usuarios  u ON u.id = t.usuario_creo
  WHERE t.$whereSucursal AND t.estatus='Pendiente'
  ORDER BY t.fecha_traspaso ASC, t.id ASC
";
$traspasos = $conn->query($sqlPend);
$cntTraspasos = $traspasos ? $traspasos->num_rows : 0;

$totItems = 0; $minFecha = null; $maxFecha = null;
$sqlRes = "
  SELECT 
    IFNULL(SUM(eq.cnt),0) AS eq_items,
    IFNULL(SUM(acc.pzs),0) AS acc_pzs,
    MIN(t.fecha_traspaso) AS primero,
    MAX(t.fecha_traspaso) AS ultimo
  FROM traspasos t
  LEFT JOIN (
    SELECT dt.id_traspaso, COUNT(*) AS cnt
    FROM detalle_traspaso dt
    GROUP BY dt.id_traspaso
  ) eq ON eq.id_traspaso = t.id
  LEFT JOIN (
    SELECT dta.id_traspaso, IFNULL(SUM(dta.cantidad),0) AS pzs
    FROM detalle_traspaso_acc dta
    GROUP BY dta.id_traspaso
  ) acc ON acc.id_traspaso = t.id
  WHERE t.$whereSucursal AND t.estatus='Pendiente'
";
if ($rs = $conn->query($sqlRes)) {
  $rRes = $rs->fetch_assoc();
  if ($rRes) {
    $totItems = (int)($rRes['eq_items'] ?? 0) + (int)($rRes['acc_pzs'] ?? 0);
    $minFecha = $rRes['primero'];
    $maxFecha = $rRes['ultimo'];
  }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Traspasos Pendientes</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root{
      --luga-primary:#2563eb;
      --luga-primary-2:#0ea5e9;
      --luga-success:#16a34a;
      --luga-dark:#0f172a;
      --luga-muted:#64748b;
      --luga-surface:#ffffff;
      --luga-border:#e2e8f0;
      --luga-soft:#f8fafc;
      --luga-warn:#f59e0b;
      --luga-danger:#ef4444;
      --luga-violet:#6366f1;
    }

    body.bg-light{
      background:
        radial-gradient(1200px 420px at 110% -80%, rgba(37,99,235,.10), transparent),
        radial-gradient(1200px 420px at -10% 120%, rgba(14,165,233,.08), transparent),
        linear-gradient(180deg,#f8fafc 0%, #eef4ff 100%);
      color:#0f172a;
    }

    .page-head{
      border:0;
      border-radius:1.25rem;
      background:
        linear-gradient(135deg, rgba(37,99,235,.98) 0%, rgba(14,165,233,.96) 55%, rgba(99,102,241,.96) 100%);
      color:#fff;
      box-shadow:0 24px 50px rgba(2,8,20,.14), 0 4px 12px rgba(2,8,20,.08);
      overflow:hidden;
      position:relative;
    }
    .page-head::after{
      content:"";
      position:absolute;
      inset:auto -40px -40px auto;
      width:220px; height:220px;
      background:radial-gradient(circle, rgba(255,255,255,.18) 0%, rgba(255,255,255,0) 65%);
      pointer-events:none;
    }
    .page-head .icon{
      width:56px;height:56px;
      display:grid;place-items:center;
      background:rgba(255,255,255,.16);
      border:1px solid rgba(255,255,255,.20);
      border-radius:18px;
      backdrop-filter: blur(8px);
    }
    .chip{
      background:rgba(255,255,255,.16);
      border:1px solid rgba(255,255,255,.24);
      color:#fff;
      padding:.45rem .75rem;
      border-radius:999px;
      font-weight:700;
      backdrop-filter: blur(8px);
    }

    .kpi-card{
      border:1px solid rgba(226,232,240,.85);
      border-radius:1rem;
      background:rgba(255,255,255,.85);
      backdrop-filter: blur(10px);
      box-shadow:0 10px 28px rgba(2,8,20,.05);
      height:100%;
    }
    .kpi-card .kpi-icon{
      width:44px;height:44px;border-radius:14px;display:grid;place-items:center;
      background:linear-gradient(135deg, rgba(37,99,235,.14), rgba(14,165,233,.18));
      color:var(--luga-primary);
      font-size:1.1rem;
    }
    .kpi-label{ color:var(--luga-muted); font-size:.84rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
    .kpi-value{ font-size:1.55rem; font-weight:800; line-height:1.1; }

    .card-elev{
      border:1px solid rgba(226,232,240,.9);
      border-radius:1.15rem;
      overflow:hidden;
      background:rgba(255,255,255,.92);
      box-shadow:0 14px 34px rgba(2,8,20,.06), 0 2px 10px rgba(2,8,20,.04);
    }

    .traspaso-header{
      background:linear-gradient(135deg,#0f172a 0%, #1e293b 100%);
      color:#fff;
      padding:1rem 1.15rem;
    }

    .traspaso-meta{
      display:flex;
      flex-wrap:wrap;
      gap:.5rem .75rem;
      align-items:center;
    }

    .meta-pill{
      display:inline-flex;
      align-items:center;
      gap:.4rem;
      padding:.38rem .7rem;
      border-radius:999px;
      background:rgba(255,255,255,.10);
      border:1px solid rgba(255,255,255,.12);
      font-size:.88rem;
      font-weight:600;
    }

    .badge-flow{
      border-radius:999px;
      padding:.48rem .8rem;
      font-size:.78rem;
      letter-spacing:.02em;
    }

    .section-soft{
      background:linear-gradient(180deg,#ffffff 0%, #f8fbff 100%);
      border-top:1px solid rgba(226,232,240,.8);
      border-bottom:1px solid rgba(226,232,240,.8);
      padding:.9rem 1.15rem;
    }

    .table-wrap{ padding:0 1rem 1rem; }
    .table-traspaso{
      --bs-table-bg: transparent;
      margin-bottom:0;
      overflow:hidden;
      border-radius:1rem;
    }
    .table-traspaso thead th{
      letter-spacing:.04em;
      text-transform:uppercase;
      font-size:.76rem;
      color:#475569;
      background:#f8fafc;
      border-bottom:1px solid #e2e8f0;
      white-space:nowrap;
      vertical-align:middle;
    }
    .table-traspaso tbody td{
      vertical-align:middle;
      border-color:#eef2f7;
    }
    .table-traspaso tbody tr:hover{
      background:rgba(37,99,235,.035);
    }

    .chk-cell{ width:72px; text-align:center; }
    .id-badge{
      display:inline-flex; align-items:center; gap:.35rem;
      border-radius:999px;
      padding:.3rem .65rem;
      background:#eef4ff;
      color:#1d4ed8;
      font-weight:700;
      font-size:.82rem;
    }

    .mini-note{
      display:flex;
      align-items:flex-start;
      gap:.7rem;
      padding:.9rem 1rem;
      border-radius:1rem;
      background:linear-gradient(180deg,#f8fafc 0%, #f1f5f9 100%);
      border:1px solid #e2e8f0;
      color:#334155;
    }
    .mini-note .i{
      width:34px;height:34px;border-radius:12px;display:grid;place-items:center;
      background:#e0ecff;color:#2563eb;flex:0 0 auto;
    }

    .sticky-actions{
      position:sticky;
      bottom:0;
      background:rgba(255,255,255,.95);
      backdrop-filter: blur(10px);
      padding:1rem 1.15rem;
      border-top:1px solid #e5e7eb;
    }

    .btn-luga{
      border:0;
      color:#fff;
      background:linear-gradient(135deg,#2563eb 0%, #0ea5e9 100%);
      box-shadow:0 8px 18px rgba(37,99,235,.22);
    }
    .btn-luga:hover{ color:#fff; filter:brightness(1.03); }

    .btn-success-soft{
      border:1px solid rgba(22,163,74,.18);
      color:#166534;
      background:linear-gradient(180deg,#f0fdf4 0%, #dcfce7 100%);
      font-weight:700;
    }
    .btn-success-soft:hover{
      color:#14532d;
      background:linear-gradient(180deg,#dcfce7 0%, #bbf7d0 100%);
    }

    .empty-state{
      border:1px dashed #cbd5e1;
      border-radius:1.25rem;
      background:rgba(255,255,255,.88);
      box-shadow:0 10px 24px rgba(2,8,20,.05);
    }

    .text-truncate-2{
      display:-webkit-box;
      -webkit-line-clamp:2;
      -webkit-box-orient:vertical;
      overflow:hidden;
    }

    @media (max-width: 768px){
      .page-head{ border-radius:1rem; }
      .traspaso-header{ padding:1rem; }
      .table-wrap{ padding:0 .75rem .75rem; }
      .sticky-actions{ padding:.85rem .9rem; }
      .table-traspaso{ font-size:.92rem; }
    }
  </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container my-4">

  <div class="page-head p-4 p-md-5 mb-4">
    <div class="d-flex flex-wrap align-items-center gap-3 position-relative" style="z-index:1;">
      <div class="icon"><i class="bi bi-arrow-left-right fs-4"></i></div>
      <div class="flex-grow-1">
        <div class="small fw-semibold text-white-50 mb-1">Panel de recepción</div>
        <h2 class="mb-1 fw-bold">Traspasos pendientes</h2>
        <div class="opacity-90">Sucursal destino: <strong><?= h($nomSucursal) ?></strong></div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <span class="chip"><i class="bi bi-box-seam me-1"></i> <?= (int)$cntTraspasos ?> pendientes</span>
        <span class="chip"><i class="bi bi-clock-history me-1"></i> <?= date('d/m/Y H:i') ?></span>
      </div>
    </div>
  </div>

  <?= $mensaje ?>

  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="kpi-card p-3">
        <div class="d-flex align-items-center gap-3">
          <div class="kpi-icon"><i class="bi bi-stack"></i></div>
          <div>
            <div class="kpi-label">Traspasos pendientes</div>
            <div class="kpi-value"><?= (int)$cntTraspasos ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="kpi-card p-3">
        <div class="d-flex align-items-center gap-3">
          <div class="kpi-icon"><i class="bi bi-box2-heart"></i></div>
          <div>
            <div class="kpi-label">Piezas por revisar</div>
            <div class="kpi-value"><?= (int)$totItems ?></div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="kpi-card p-3">
        <div class="d-flex align-items-center gap-3">
          <div class="kpi-icon"><i class="bi bi-calendar-range"></i></div>
          <div>
            <div class="kpi-label">Ventana detectada</div>
            <div class="fw-bold">
              <?= $minFecha ? h(date('d/m/Y H:i', strtotime($minFecha))) : '—' ?>
              <span class="text-muted">a</span>
              <?= $maxFecha ? h(date('d/m/Y H:i', strtotime($maxFecha))) : '—' ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($traspasos && $traspasos->num_rows > 0): ?>
    <?php while($traspaso = $traspasos->fetch_assoc()): ?>
      <?php
        $idTraspaso  = (int)$traspaso['id'];
        $idGarantia  = (int)($traspaso['id_garantia'] ?? 0);
        $esGarantia  = $idGarantia > 0;

        $sqlDetalle = "
          SELECT 
            dt.id          AS det_id,
            'equipo'       AS tipo,
            i.id           AS id_inv,
            p.marca, p.modelo, p.color,
            p.imei1, p.imei2,
            1              AS cantidad
          FROM detalle_traspaso dt
          JOIN inventario  i ON i.id = dt.id_inventario
          JOIN productos   p ON p.id = i.id_producto
          WHERE dt.id_traspaso = {$idTraspaso}

          UNION ALL

          SELECT
            dta.id         AS det_id,
            'accesorio'    AS tipo,
            i.id           AS id_inv,
            p.marca, p.modelo, p.color,
            NULL, NULL,
            dta.cantidad   AS cantidad
          FROM detalle_traspaso_acc dta
          JOIN inventario  i ON i.id = dta.id_inventario_origen
          JOIN productos   p ON p.id = i.id_producto
          WHERE dta.id_traspaso = {$idTraspaso}

          ORDER BY tipo DESC, modelo, color, det_id
        ";
        $detalles = $conn->query($sqlDetalle);

        $totalEq = 0;
        $totalAccPz = 0;

        if ($rsEQ = $conn->query("SELECT COUNT(*) AS c FROM detalle_traspaso WHERE id_traspaso={$idTraspaso}")) {
          $rw = $rsEQ->fetch_assoc();
          $totalEq = (int)($rw['c'] ?? 0);
        }
        if ($rsAC = $conn->query("SELECT IFNULL(SUM(cantidad),0) AS c FROM detalle_traspaso_acc WHERE id_traspaso={$idTraspaso}")) {
          $rw = $rsAC->fetch_assoc();
          $totalAccPz = (int)($rw['c'] ?? 0);
        }
      ?>
      <div class="card card-elev mb-4">
        <div class="traspaso-header">
          <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div>
              <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <span class="badge badge-flow <?= $esGarantia ? 'text-bg-danger' : 'text-bg-primary' ?>">
                  <i class="bi <?= $esGarantia ? 'bi-shield-check' : 'bi-arrow-left-right' ?> me-1"></i>
                  <?= $esGarantia ? 'Traspaso por garantía' : 'Traspaso normal' ?>
                </span>

                <?php if ($esGarantia): ?>
                  <span class="badge badge-flow text-bg-light text-dark">
                    <i class="bi bi-link-45deg me-1"></i> Garantía #<?= $idGarantia ?>
                  </span>
                <?php endif; ?>
              </div>

              <div class="h5 mb-2 fw-bold">
                Traspaso #<?= $idTraspaso ?>
              </div>

              <div class="traspaso-meta">
                <span class="meta-pill"><i class="bi bi-shop"></i> Origen: <strong><?= h($traspaso['sucursal_origen']) ?></strong></span>
                <span class="meta-pill"><i class="bi bi-person-circle"></i> Creó: <strong><?= h($traspaso['usuario_creo']) ?></strong></span>
                <span class="meta-pill"><i class="bi bi-calendar-event"></i> <?= h(date('d/m/Y H:i', strtotime($traspaso['fecha_traspaso']))) ?></span>
              </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
              <span class="badge rounded-pill text-bg-light text-dark px-3 py-2">Equipos: <b><?= $totalEq ?></b></span>
              <span class="badge rounded-pill text-bg-light text-dark px-3 py-2">Accesorios (pzs): <b><?= $totalAccPz ?></b></span>
            </div>
          </div>
        </div>

        <div class="section-soft">
          <div class="mini-note">
            <div class="i"><i class="bi bi-info-circle"></i></div>
            <div class="small">
              Marca únicamente lo que <b>sí recibiste físicamente</b>. Lo no marcado se rechazará y regresará al origen.
              <?php if ($esGarantia): ?>
                <div class="mt-1 text-danger fw-semibold">
                  Flujo especial: los equipos aceptados de este traspaso se marcarán como <b>Reemplazado</b>.
                </div>
              <?php else: ?>
                <div class="mt-1 text-success fw-semibold">
                  Flujo normal: los equipos aceptados se marcarán como <b>Disponible</b>.
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <form method="POST" onsubmit="return confirmProcesar(this, <?= $idTraspaso ?>, <?= $esGarantia ? 'true' : 'false' ?>)">
          <input type="hidden" name="id_traspaso" value="<?= $idTraspaso ?>">
          <input type="hidden" name="procesar_traspaso" value="1">

          <div class="table-wrap">
            <div class="table-responsive">
              <table class="table table-traspaso table-hover align-middle">
                <thead>
                  <tr>
                    <th class="chk-cell text-center">
                      <input type="checkbox" class="form-check-input" id="chk_all_<?= $idTraspaso ?>" checked
                             onclick="toggleAll(<?= $idTraspaso ?>, this.checked)">
                    </th>
                    <th>Tipo</th>
                    <th>ID inv</th>
                    <th>Marca</th>
                    <th>Modelo</th>
                    <th>Color</th>
                    <th>IMEI1</th>
                    <th>IMEI2</th>
                    <th class="text-end">Cantidad</th>
                    <th>Estatus actual</th>
                  </tr>
                </thead>
                <tbody>
                <?php if ($detalles && $detalles->num_rows): ?>
                  <?php while ($row = $detalles->fetch_assoc()): ?>
                    <?php
                      $isEquipo = ($row['tipo'] === 'equipo');
                      $checkName = $isEquipo ? 'aceptar_eq[]' : 'aceptar_acc[]';
                      $checkVal  = $isEquipo ? (int)$row['id_inv'] : (int)$row['det_id'];
                    ?>
                    <tr>
                      <td class="chk-cell text-center">
                        <input type="checkbox"
                               class="form-check-input chk-item-<?= $idTraspaso ?>"
                               name="<?= $checkName ?>"
                               value="<?= $checkVal ?>"
                               checked>
                      </td>

                      <td>
                        <span class="badge <?= $isEquipo ? 'text-bg-primary' : 'text-bg-info text-dark' ?>">
                          <?= $isEquipo ? 'Equipo' : 'Accesorio' ?>
                        </span>
                      </td>

                      <td>
                        <span class="id-badge">
                          <i class="bi bi-upc-scan"></i> <?= (int)$row['id_inv'] ?>
                        </span>
                      </td>

                      <td><?= h($row['marca']) ?></td>
                      <td class="fw-semibold"><?= h($row['modelo']) ?></td>
                      <td><?= h($row['color']) ?></td>
                      <td><span class="text-monospace"><?= $row['imei1'] ? h($row['imei1']) : '—' ?></span></td>
                      <td><span class="text-monospace"><?= $row['imei2'] ? h($row['imei2']) : '—' ?></span></td>
                      <td class="text-end fw-semibold"><?= (int)$row['cantidad'] ?></td>
                      <td>
                        <span class="badge rounded-pill text-bg-warning">
                          <i class="bi bi-truck me-1"></i> En tránsito
                        </span>
                      </td>
                    </tr>
                  <?php endwhile; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="10" class="text-center text-muted py-4">Sin detalle</td>
                  </tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="sticky-actions d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div class="small text-muted">
              Consejo práctico: si llegó todo, usa <b>Marcar todo</b>. Si faltó algo, desmarca solo esas piezas.
            </div>

            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-outline-secondary btn-sm"
                      onclick="toggleAll(<?= $idTraspaso ?>, true)">
                <i class="bi bi-check2-all me-1"></i> Marcar todo
              </button>

              <button type="button" class="btn btn-outline-secondary btn-sm"
                      onclick="toggleAll(<?= $idTraspaso ?>, false)">
                <i class="bi bi-x-circle me-1"></i> Desmarcar todo
              </button>

              <button type="submit" class="btn btn-success-soft btn-sm">
                <i class="bi bi-send-check me-1"></i> Procesar recepción
              </button>
            </div>
          </div>
        </form>
      </div>
    <?php endwhile; ?>
  <?php else: ?>
    <div class="empty-state p-5 text-center">
      <div class="display-5 mb-2">📦</div>
      <h4 class="fw-bold mb-2">No hay traspasos pendientes</h4>
      <div class="text-muted">Cuando tu sucursal tenga recepciones por confirmar, aparecerán aquí.</div>
    </div>
  <?php endif; ?>
</div>

<!-- Modal ACUSE -->
<div class="modal fade" id="modalAcuse" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-xxl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Acuse de recepción</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body p-0">
        <iframe id="frameAcuse" src="about:blank" style="width:100%;min-height:72vh;border:0;background:#fff"></iframe>
      </div>
      <div class="modal-footer">
        <button type="button" id="btnOpenAcuse" class="btn btn-outline-secondary">
          <i class="bi bi-box-arrow-up-right me-1"></i> Abrir en pestaña
        </button>
        <button type="button" id="btnPrintAcuse" class="btn btn-luga">
          <i class="bi bi-printer me-1"></i> Reimprimir
        </button>
        <button type="button" class="btn btn-dark" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal confirmación -->
<div class="modal fade" id="modalConfirmarRecepcion" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="bi bi-shield-check me-2"></i>Confirmar recepción</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div id="confirmText" class="mb-3"></div>
        <div class="small text-muted">
          Verifica bien lo recibido. Lo no marcado se considerará rechazado y regresará al origen.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-luga" id="btnConfirmarProcesar">Sí, procesar</button>
      </div>
    </div>
  </div>
</div>

<script>
function toggleAll(idT, checked){
  document.querySelectorAll('.chk-item-' + idT).forEach(el => el.checked = checked);
  const master = document.getElementById('chk_all_' + idT);
  if (master) master.checked = checked;
}

let __formPendiente = null;
function confirmProcesar(form, idTraspaso, esGarantia){
  __formPendiente = form;

  const total = form.querySelectorAll('.chk-item-' + idTraspaso).length;
  const marcados = form.querySelectorAll('.chk-item-' + idTraspaso + ':checked').length;
  const rechazados = total - marcados;

  const html = `
    <div class="fw-bold mb-2">Traspaso #${idTraspaso}</div>
    <div class="mb-2">
      <span class="badge text-bg-primary me-1">Marcados para recibir: ${marcados}</span>
      <span class="badge text-bg-secondary">Se rechazarán: ${rechazados}</span>
    </div>
    <div class="small">
      ${esGarantia
        ? '<span class="text-danger fw-semibold">Es un traspaso ligado a garantía: los equipos aceptados quedarán como Reemplazado.</span>'
        : '<span class="text-success fw-semibold">Es un traspaso normal: los equipos aceptados quedarán como Disponible.</span>'
      }
    </div>
  `;

  document.getElementById('confirmText').innerHTML = html;
  const modal = new bootstrap.Modal(document.getElementById('modalConfirmarRecepcion'));
  modal.show();

  const btn = document.getElementById('btnConfirmarProcesar');
  btn.onclick = function(){
    modal.hide();
    if (__formPendiente) __formPendiente.submit();
  };

  return false;
}

// ===== Modal ACUSE =====
const ACUSE_URL   = <?= json_encode($acuseUrl, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
const ACUSE_READY = <?= $acuseReady ? 'true' : 'false' ?>;

if (ACUSE_READY && ACUSE_URL) {
  const modalAcuse = new bootstrap.Modal(document.getElementById('modalAcuse'));
  const frame = document.getElementById('frameAcuse');
  frame.src = ACUSE_URL;
  frame.addEventListener('load', () => { try { frame.contentWindow.focus(); } catch(e){} });
  modalAcuse.show();

  document.getElementById('btnOpenAcuse').onclick  = () => window.open(ACUSE_URL, '_blank', 'noopener');
  document.getElementById('btnPrintAcuse').onclick = () => {
    try { frame.contentWindow.focus(); frame.contentWindow.print(); }
    catch(e){ window.open(ACUSE_URL, '_blank', 'noopener'); }
  };
}
</script>
</body>
</html>