<?php
// imei_lookup_status.php — Devuelve JSON con el estado de un IMEI.
// Reporta: TRASPASO (pendiente), RETIRO (no revertido) y VENTA (última venta).
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
  echo json_encode(['status' => 'unauthorized']); exit;
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fmtdt(?string $dt): string {
  if (!$dt) return 'N/D';
  $t = strtotime($dt);
  if ($t === false) return h($dt);
  return date('d/m/Y H:i', $t);
}

$imei = trim($_GET['imei'] ?? '');
if ($imei === '') { echo json_encode(['status' => 'bad_request']); exit; }

$pieces = [];
$kind   = []; // ['traspaso','retiro','venta']

// ========================= 1) Inventario por IMEI
$sqlInv = "SELECT i.id AS id_inventario, i.id_sucursal, i.estatus, s.nombre AS sucursal,
                  p.imei1, p.imei2, p.marca, p.modelo, p.color
           FROM inventario i
           JOIN productos p ON p.id = i.id_producto
           JOIN sucursales s ON s.id = i.id_sucursal
           WHERE p.imei1 = ? OR p.imei2 = ?
           ORDER BY i.id DESC
           LIMIT 1";
$stInv = $conn->prepare($sqlInv);
$stInv->bind_param("ss", $imei, $imei);
$stInv->execute();
$inv = $stInv->get_result()->fetch_assoc();

// Helper: nombres de sucursales
function map_sucs(mysqli $conn, array $ids): array {
  $ids = array_values(array_filter(array_map('intval',$ids)));
  if (!$ids) return [];
  $rs = $conn->query("SELECT id, nombre FROM sucursales WHERE id IN (".implode(',', $ids).")");
  $m = [];
  if ($rs) while($r=$rs->fetch_assoc()) $m[(int)$r['id']] = $r['nombre'];
  return $m;
}

// ========================= 2) TRASPASO pendiente (si hay inventario)
if ($inv) {
  $sqlT = "SELECT dt.id, dt.resultado, t.id AS id_traspaso, t.id_sucursal_origen, t.id_sucursal_destino, t.fecha_traspaso
           FROM detalle_traspaso dt
           JOIN traspasos t ON t.id = dt.id_traspaso
           WHERE dt.id_inventario = ?
           ORDER BY dt.id DESC
           LIMIT 1";
  $stT = $conn->prepare($sqlT);
  $stT->bind_param("i", $inv['id_inventario']);
  $stT->execute();
  $rowT = $stT->get_result()->fetch_assoc();

  if ($rowT && $rowT['resultado'] === 'Pendiente') {
    $m = map_sucs($conn, [(int)$rowT['id_sucursal_origen'], (int)$rowT['id_sucursal_destino']]);
    $origen  = $m[(int)$rowT['id_sucursal_origen']]  ?? ('ID '.(int)$rowT['id_sucursal_origen']);
    $destino = $m[(int)$rowT['id_sucursal_destino']] ?? ('ID '.(int)$rowT['id_sucursal_destino']);
    $fechaTr = fmtdt($rowT['fecha_traspaso'] ?? null);

    $pieces[] = '<div class="d-flex align-items-start gap-2">
      <i class="bi bi-truck fs-4 text-primary"></i>
      <div>
        <div class="fw-semibold">Equipo en tránsito</div>
        <div class="text-muted">De <b>'.h($origen).'</b> a <b>'.h($destino).'</b>. Traspaso #'.(int)$rowT['id_traspaso'].' (pendiente de recepción).</div>
        <div class="small text-secondary"><i class="bi bi-calendar-event me-1"></i>Fecha del traspaso: <b>'.h($fechaTr).'</b></div>
      </div>
    </div>';
    $kind[] = 'traspaso';
  }
}

// ========================= 3) RETIRO (no revertido)
// A) por id_inventario
if ($inv) {
  $sqlR = "SELECT r.id, r.id_sucursal, r.motivo, r.destino, r.revertido, r.fecha
           FROM inventario_retiros_detalle d
           JOIN inventario_retiros r ON r.id = d.retiro_id
           WHERE d.id_inventario = ?
           ORDER BY d.id DESC
           LIMIT 1";
  if ($stR = $conn->prepare($sqlR)) {
    $stR->bind_param("i", $inv['id_inventario']);
    $stR->execute();
    $rowR = $stR->get_result()->fetch_assoc();
    if ($rowR && (int)$rowR['revertido'] === 0) {
      $sucNom = '';
      if ($rowR['id_sucursal']) {
        $idS = (int)$rowR['id_sucursal'];
        $rsS = $conn->query("SELECT nombre FROM sucursales WHERE id = ".$idS);
        $sucNom = $rsS && $rsS->num_rows ? $rsS->fetch_row()[0] : ('ID '.$idS);
      }
      $destino = trim((string)$rowR['destino']);
      $motivo  = (string)$rowR['motivo'];
      $fechaRt = fmtdt($rowR['fecha'] ?? null);

      $pieces[] = '<div class="d-flex align-items-start gap-2">
        <i class="bi bi-box-arrow-right fs-4 text-danger"></i>
        <div>
          <div class="fw-semibold">Equipo retirado de inventario</div>
          <div class="text-muted">Sucursal: <b>'.h($sucNom ?: 'N/D').'</b>. Motivo: <b>'.h($motivo).'</b>'.($destino!==''?'. Destino: <b>'.h($destino).'</b>':'').'.</div>
          <div class="small text-secondary"><i class="bi bi-calendar-event me-1"></i>Fecha del retiro: <b>'.h($fechaRt).'</b></div>
        </div>
      </div>';
      $kind[] = 'retiro';
    }
  }
}
// B) fallback por IMEI si no hubo inv o no salió nada
if (!$inv && !in_array('retiro', $kind, true)) {
  $colsRes = $conn->query("SHOW COLUMNS FROM inventario_retiros_detalle");
  $cols = [];
  if ($colsRes) while($c = $colsRes->fetch_assoc()) $cols[] = $c['Field'];
  $whereImei = []; $params = []; $types = '';
  foreach (['imei1','imei','imei2'] as $c) {
    if (in_array($c, $cols, true)) { $whereImei[] = "d.`$c` = ?"; $params[] = $imei; $types .= 's'; }
  }
  if ($whereImei) {
    $sqlRF = "SELECT r.id, r.id_sucursal, r.motivo, r.destino, r.revertido, r.fecha
              FROM inventario_retiros_detalle d
              JOIN inventario_retiros r ON r.id = d.retiro_id
              WHERE ".implode(' OR ', $whereImei)."
              ORDER BY d.id DESC
              LIMIT 1";
    $stRF = $conn->prepare($sqlRF);
    $stRF->bind_param($types, ...$params);
    $stRF->execute();
    $rowRF = $stRF->get_result()->fetch_assoc();
    if ($rowRF && (int)$rowRF['revertido'] === 0) {
      $sucNom = '';
      if ($rowRF['id_sucursal']) {
        $idS = (int)$rowRF['id_sucursal'];
        $rsS = $conn->query("SELECT nombre FROM sucursales WHERE id = ".$idS);
        $sucNom = $rsS && $rsS->num_rows ? $rsS->fetch_row()[0] : ('ID '.$idS);
      }
      $destino = trim((string)$rowRF['destino']);
      $motivo  = (string)$rowRF['motivo'];
      $fechaRt = fmtdt($rowRF['fecha'] ?? null);

      $pieces[] = '<div class="d-flex align-items-start gap-2">
        <i class="bi bi-box-arrow-right fs-4 text-danger"></i>
        <div>
          <div class="fw-semibold">Equipo retirado de inventario</div>
          <div class="text-muted">Sucursal: <b>'.h($sucNom ?: 'N/D').'</b>. Motivo: <b>'.h($motivo).'</b>'.($destino!==''?'. Destino: <b>'.h($destino).'</b>':'').'.</div>
          <div class="small text-secondary"><i class="bi bi-calendar-event me-1"></i>Fecha del retiro: <b>'.h($fechaRt).'</b></div>
        </div>
      </div>';
      $kind[] = 'retiro';
    }
  }
}

// ========================= 4) VENTA (última venta del IMEI)
// Se busca en detalle_venta. Traemos sucursal y fecha desde ventas.
$sqlV = "SELECT v.id, v.tag, v.fecha_venta, v.id_sucursal, s.nombre AS sucursal
         FROM detalle_venta dv
         JOIN ventas v      ON v.id = dv.id_venta
         LEFT JOIN sucursales s ON s.id = v.id_sucursal
         WHERE dv.imei1 = ?
         ORDER BY v.fecha_venta DESC, v.id DESC
         LIMIT 1";
$stV = $conn->prepare($sqlV);
$stV->bind_param("s", $imei);
$stV->execute();
$rowV = $stV->get_result()->fetch_assoc();

if ($rowV) {
  $fechaV = fmtdt($rowV['fecha_venta'] ?? null);
  $sucV   = $rowV['sucursal'] ?: ('ID '.(int)$rowV['id_sucursal']);
  $tag    = trim((string)($rowV['tag'] ?? ''));

  $pieces[] = '<div class="d-flex align-items-start gap-2">
    <i class="bi bi-bag-check fs-4 text-success"></i>
    <div>
      <div class="fw-semibold">Equipo vendido</div>
      <div class="text-muted">Sucursal: <b>'.h($sucV).'</b>'.($tag!==''?'. TAG: <b>'.h($tag).'</b>':'').'.</div>
      <div class="small text-secondary"><i class="bi bi-calendar-event me-1"></i>Fecha de venta: <b>'.h($fechaV).'</b></div>
    </div>
  </div>';
  $kind[] = 'venta';
}

// ========================= Respuesta
if (!$pieces) {
  echo json_encode(['status' => 'not_found']);
} else {
  echo json_encode([
    'status' => 'ok',
    'kind'   => array_values(array_unique($kind)),
    'html'   => '<div class="vstack gap-2">'.implode('', $pieces).'</div>'
  ]);
}
