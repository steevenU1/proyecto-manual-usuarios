<?php
// recargas_confirmar.php — backend de confirmación con subida de comprobante
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

/* =========================
   CONFIG (feature flags)
========================= */
// FALSE = sin bloqueo por días (modo pruebas); TRUE = aplica espera
const RECARGAS_ENFORCE_WAIT = false;
// Días configurables (solo aplican si RECARGAS_ENFORCE_WAIT = true)
const REC1_WAIT_DAYS = 15;
const REC2_WAIT_DAYS = 30;
/* ========================= */

$ROL         = $_SESSION['rol'] ?? '';
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);

if (!in_array($ROL, ['Ejecutivo','Gerente','Admin','Logistica'], true)) { header("Location: 403.php"); exit(); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fail($m){
  $_SESSION['flash'] = "<div class='alert alert-danger'>$m</div>";
  header("Location: recargas_portal.php"); exit();
}
function ok($m, $warn = ''){
  $html = "<div class='alert alert-success'>$m</div>";
  if ($warn !== '') $html .= "<div class='alert alert-warning mt-2'>$warn</div>";
  $_SESSION['flash'] = $html;
  header("Location: recargas_portal.php"); exit();
}

$id_rpc = (int)($_POST['id_rpc'] ?? 0);
$which  = (int)($_POST['which']  ?? 0);
if ($id_rpc<=0 || !in_array($which,[1,2],true)) fail("Solicitud inválida.");

$rpc = $conn->query("SELECT * FROM recargas_promo_clientes WHERE id=$id_rpc")->fetch_assoc();
if (!$rpc) fail("Registro no encontrado.");
// Si no es Admin/Logística, debe ser de su sucursal:
if (!in_array($ROL, ['Admin','Logistica'], true) && (int)$rpc['id_sucursal'] !== $ID_SUCURSAL){
  fail("No tienes permiso en esta sucursal.");
}

/* =========================
   Validación de días (conmutable)
========================= */
if (RECARGAS_ENFORCE_WAIT) {
  try {
    $ventaDT = new DateTime($rpc['fecha_venta']);
    $now     = new DateTime();
    $minDT   = clone $ventaDT;
    $minDT->modify($which===1 ? ('+'.(int)REC1_WAIT_DAYS.' day') : ('+'.(int)REC2_WAIT_DAYS.' day'));
    if ($now < $minDT) {
      $dias = $which===1 ? (int)REC1_WAIT_DAYS : (int)REC2_WAIT_DAYS;
      fail("Aún no cumple los $dias días desde la venta.");
    }
  } catch (Throwable $e) {
    fail("Fecha de venta inválida para validar días.");
  }
}

// Validar archivo
if (!isset($_FILES['comprobante']) || $_FILES['comprobante']['error'] !== UPLOAD_ERR_OK) fail("Archivo requerido.");
$fn  = $_FILES['comprobante']['name'] ?? '';
$sz  = (int)($_FILES['comprobante']['size'] ?? 0);
$tmp = $_FILES['comprobante']['tmp_name'] ?? '';
if ($sz <= 0 || $sz > 5*1024*1024) fail("Tamaño inválido (máx. 5MB).");

// extensión permitida
$ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
$okExt = in_array($ext, ['png','jpg','jpeg','pdf'], true);
if (!$okExt) fail("Formato no permitido. Usa PNG, JPG o PDF.");

// carpeta
$dir = __DIR__ . '/uploads/recargas';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
$fname   = "promo{$rpc['id_promo']}_venta{$rpc['id_venta']}_rec{$which}_".bin2hex(random_bytes(6)).".$ext";
$pathAbs = $dir . "/$fname";
$pathRel = "uploads/recargas/$fname";

if (!@move_uploaded_file($tmp, $pathAbs)) fail("No se pudo guardar el archivo.");

// ===== Update recarga (confirmar R1/R2) =====
$colS = $which===1 ? 'rec1_status'           : 'rec2_status';
$colA = $which===1 ? 'rec1_at'               : 'rec2_at';
$colB = $which===1 ? 'rec1_by'               : 'rec2_by';
$colP = $which===1 ? 'rec1_comprobante_path' : 'rec2_comprobante_path';

$id  = (int)$rpc['id'];
$sql = "UPDATE recargas_promo_clientes
        SET $colS='confirmada', $colA=NOW(), $colB=$ID_USUARIO, $colP='".$conn->real_escape_string($pathRel)."'
        WHERE id=$id";
if (!$conn->query($sql)) {
  fail("Error al guardar: ".$conn->error);
}

/* ===== Cobro negativo por recarga de regalo =====
   Nota: asegúrate que en la tabla cobros exista el motivo 'Recarga promocional'
   en el ENUM de `motivo`. */
$warn = '';
$MONTO_RECARGA_REGALO = 100.00; // o trae de recargas_promo si lo parametrizas por promo
$neg   = -1 * $MONTO_RECARGA_REGALO;
$cero  = 0.00;
$motivo   = 'Recarga promocional';
$tipoPago = 'Efectivo';
$ticket   = bin2hex(random_bytes(16)) . ($which === 1 ? '-R1' : '-R2'); // <= char(36) OK (35 máx)
$now      = date('Y-m-d H:i:s');

// Datos cliente/sucursal desde el registro de la promo
$nom = trim((string)($rpc['nombre_cliente'] ?? 'Cliente'));
$tel = trim((string)($rpc['telefono_cliente'] ?? ''));
$idSucCobro = (int)$rpc['id_sucursal'];

$stmtCob = $conn->prepare("
  INSERT INTO cobros
    (id_usuario, id_sucursal, motivo, tipo_pago, monto_total, monto_efectivo, monto_tarjeta,
     nombre_cliente, telefono_cliente, ticket_uid, fecha_cobro)
  VALUES (?,?,?,?,?,?,?,?,?,?,?)
");
if ($stmtCob) {
  $stmtCob->bind_param(
    "iissdddssss",
    $ID_USUARIO, $idSucCobro, $motivo, $tipoPago,
    $neg, $neg, $cero,
    $nom, $tel, $ticket, $now
  );
  if (!$stmtCob->execute()) {
    // No rompemos la confirmación; dejamos advertencia
    $warn = "Recarga confirmada, pero no se registró el cobro negativo en caja: ".h($conn->error);
  }
  $stmtCob->close();
} else {
  $warn = "No se pudo preparar el INSERT en cobros: ".h($conn->error);
}

// Éxito (con posible advertencia)
ok("Recarga $which confirmada.", $warn);
