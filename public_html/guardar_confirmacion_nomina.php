<?php
// guardar_confirmacion_nomina.php — Inserta/actualiza confirmación en nomina_confirmaciones
// Regla: SOLO permitir confirmar a partir del JUEVES 00:00 (después del miércoles) de la semana operativa.

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

function hredir($url){ header("Location: ".$url); exit(); }

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
$semIni = $_POST['semana_inicio'] ?? '';
$semFin = $_POST['semana_fin'] ?? '';
$accion = $_POST['accion'] ?? 'confirmar';
$coment = trim($_POST['comentario'] ?? '');
$ip     = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

$dest = "mi_nomina_semana_v2.php";
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$semIni) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$semFin)) {
  hredir("$dest?e=bad_range");
}

/* Candado: confirmar solo después del miércoles (=> jueves 00:00) */
try {
  $tz = new DateTimeZone('America/Mexico_City');
  $iniDT   = DateTime::createFromFormat('Y-m-d', $semIni, $tz)->setTime(0,0,0); // martes
  $jueves0 = (clone $iniDT)->modify('+2 days')->setTime(0,0,0);                 // jueves 00:00
  $ahora   = new DateTime('now', $tz);

  $puedeConfirmar = ($ahora >= $jueves0);
  if ($accion === 'confirmar' && !$puedeConfirmar) {
    hredir("$dest?w=".urlencode($iniDT->format('o-\WW'))."&e=early"); // aún no es jueves
  }
} catch (Throwable $e) {
  // si hay error de fecha, mejor no confirmar
  hredir("$dest?e=bad_date");
}

/* Upsert en nomina_confirmaciones */
$semIniEsc = $conn->real_escape_string($semIni);
$semFinEsc = $conn->real_escape_string($semFin);
$comentEsc = $conn->real_escape_string($coment);
$ipEsc     = $conn->real_escape_string($ip);

$sqlSel = "SELECT id FROM nomina_confirmaciones
           WHERE id_usuario={$idUsuario} AND semana_inicio='{$semIniEsc}' AND semana_fin='{$semFinEsc}'
           LIMIT 1";
$res = $conn->query($sqlSel);

if ($accion === 'reabrir') {
  if ($res && $res->num_rows>0) {
    $row = $res->fetch_assoc();
    $id  = (int)$row['id'];
    $sql = "UPDATE nomina_confirmaciones
            SET confirmado=0, comentario='{$comentEsc}', confirmado_en=NULL, ip_confirmacion=NULL
            WHERE id={$id} LIMIT 1";
    $conn->query($sql);
  } else {
    $sql = "INSERT INTO nomina_confirmaciones
            (id_usuario, semana_inicio, semana_fin, confirmado, comentario)
            VALUES ({$idUsuario}, '{$semIniEsc}', '{$semFinEsc}', 0, '{$comentEsc}')";
    $conn->query($sql);
  }
  hredir("$dest?w=".urlencode(DateTime::createFromFormat('Y-m-d',$semIni)->format('o-\WW')));
}

/* confirmar */
if ($res && $res->num_rows>0) {
  $row = $res->fetch_assoc();
  $id  = (int)$row['id'];
  $sql = "UPDATE nomina_confirmaciones
          SET confirmado=1, comentario='{$comentEsc}', confirmado_en=NOW(), ip_confirmacion='{$ipEsc}'
          WHERE id={$id} LIMIT 1";
  $conn->query($sql);
} else {
  $sql = "INSERT INTO nomina_confirmaciones
          (id_usuario, semana_inicio, semana_fin, confirmado, comentario, confirmado_en, ip_confirmacion)
          VALUES ({$idUsuario}, '{$semIniEsc}', '{$semFinEsc}', 1, '{$comentEsc}', NOW(), '{$ipEsc}')";
  $conn->query($sql);
}

hredir("$dest?w=".urlencode(DateTime::createFromFormat('Y-m-d',$semIni)->format('o-\WW'))."&ok=1");
