<?php
// guardar_descansos.php
session_start();
require_once __DIR__.'/db.php';
date_default_timezone_set('America/Mexico_City');

// Solo Gerente (ajusta si también quieres Admin)
if (!isset($_SESSION['id_usuario']) || !in_array(($_SESSION['rol'] ?? ''), ['Gerente','Admin'])) {
  http_response_code(403); exit('Forbidden');
}

// --------- Utilidades ----------
function opWeekStartFromWeekInput(string $iso): ?DateTime {
  if (!preg_match('/^(\d{4})-W(\d{2})$/',$iso,$m)) return null;
  $dt=new DateTime(); $dt->setISODate((int)$m[1],(int)$m[2]); // Lunes ISO
  $dt->modify('+1 day'); // Martes como arranque operativo
  $dt->setTime(0,0,0);
  return $dt;
}
function currentOpWeekIso(): string {
  $t=new DateTime('today'); $dow=(int)$t->format('N');
  $off=($dow>=2)?$dow-2:6+$dow; $tue=(clone $t)->modify("-{$off} days");
  $mon=(clone $tue)->modify('-1 day');
  return $mon->format('o-\WW');
}

// --------- Entrada esperada ----------
// POST['week'] = "YYYY-Www" del <input type="week">
// POST['descanso'][id_usuario] = uno de {'Mar','Mié','Jue','Vie','Sáb','Dom','Lun'}
$weekIso = $_POST['week'] ?? currentOpWeekIso();
$tueStart = opWeekStartFromWeekInput($weekIso) ?: new DateTime('tuesday this week');

// Validación mínima
if (empty($_POST['descanso']) || !is_array($_POST['descanso'])) {
  http_response_code(400); exit('Sin datos de descansos');
}

// Mapea nombre corto → desplazamiento desde martes
$idx = ['Mar'=>0,'Mié'=>1,'Jue'=>2,'Vie'=>3,'Sáb'=>4,'Dom'=>5,'Lun'=>6];

// Prepara inserción
$sqlDel = "DELETE FROM descansos_programados 
           WHERE id_usuario=? AND semana_inicio=?";
$stDel  = $conn->prepare($sqlDel);

$sqlIns = "INSERT INTO descansos_programados
           (id_usuario, fecha, semana_inicio, dia_descanso, es_descanso, asignado_por)
           VALUES (?,?,?,?,1,?)";
$stIns  = $conn->prepare($sqlIns);

$conn->begin_transaction();
try {
  $semanaInicio = $tueStart->format('Y-m-d');
  $asignadoPor  = (int)$_SESSION['id_usuario'];
  $insertados = 0; $omitidos = 0;

  foreach ($_POST['descanso'] as $uidRaw => $diaTexto) {
    $idUsuario = (int)$uidRaw;
    $diaTexto  = trim($diaTexto);

    if (!isset($idx[$diaTexto]) || $idUsuario<=0) { $omitidos++; continue; }

    // Calcula la fecha real del descanso dentro de la semana operativa
    $fecha = (clone $tueStart)->modify("+{$idx[$diaTexto]} day")->format('Y-m-d');

    // Limpia el descanso previo de esa semana para ese usuario (idempotente)
    $stDel->bind_param('is', $idUsuario, $semanaInicio);
    $stDel->execute();

    // Inserta el nuevo registro cumpliendo NOT NULL
    $stIns->bind_param('isssi', $idUsuario, $fecha, $semanaInicio, $diaTexto, $asignadoPor);
    $stIns->execute();
    $insertados++;
  }

  $conn->commit();

  // Respuesta simple (cámbiala por redirección/flash si usas UI)
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok' => true,
    'semana_inicio' => $semanaInicio,
    'insertados' => $insertados,
    'omitidos' => $omitidos
  ]);
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
