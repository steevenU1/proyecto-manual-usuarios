<?php
// asistencia.php — compatible con diferencias de esquema (estatus / latitud_salida / longitud_salida)
// Candado: salida mínima después de X minutos desde entrada
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calcular_vacaciones.php';
date_default_timezone_set('America/Mexico_City');

/* ===== Charset seguro (evita 500 por acentos en ENUM 'Mié','Sáb') ===== */
if (method_exists($conn, 'set_charset')) { @$conn->set_charset('utf8mb4'); }
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

// DEBUG opcional: https://tu-sitio/asistencia.php?debug=1
if (isset($_GET['debug'])) {
  ini_set('display_errors', '1');
  error_reporting(E_ALL);
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}

/* ========= Configuración de reglas ========= */
const MIN_SALIDA_MIN = 60; // ← Candado: minutos mínimos desde entrada para permitir salida

/* ========= Fallback GEO (sin coords por sucursal) =========
   - Si el navegador NO da ubicación (PC), usamos coords genéricas.
   - Solo placeholder para no bloquear marcajes.
*/
const ALLOW_FALLBACK_GEO = true;
const FALLBACK_LAT = 19.4326077;     // CDMX centro (placeholder)
const FALLBACK_LNG = -99.1332080;    // CDMX centro (placeholder)

/* ========= Helpers ========= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function client_ip(): string{
  foreach (['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','HTTP_X_FORWARDED','HTTP_X_CLUSTER_CLIENT_IP','HTTP_FORWARDED_FOR','HTTP_FORWARDED','REMOTE_ADDR'] as $k){
    if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
  }
  return '0.0.0.0';
}
function opWeekStartFromWeekInput(string $iso): ?DateTime{
  if (!preg_match('/^(\d{4})-W(\d{2})$/',$iso,$m)) return null;
  $dt=new DateTime();
  $dt->setISODate((int)$m[1], (int)$m[2]); // lunes ISO
  $dt->modify('+1 day'); // operativa: martes → lunes
  $dt->setTime(0,0,0);
  return $dt;
}
function currentOpWeekIso(): string{
  $today=new DateTime('today');
  $dow=(int)$today->format('N'); // 1..7
  $offset=($dow>=2)?$dow-2:6+$dow;
  $tue=(clone $today)->modify("-{$offset} days");
  $mon=(clone $tue)->modify('-1 day'); // lunes ISO para <input type=week>
  return $mon->format('o-\WW');
}
function fmtBadgeRango(DateTime $tueStart): string{
  $dias=['Mar','Mié','Jue','Vie','Sáb','Dom','Lun'];
  $ini=(clone $tueStart); $fin=(clone $tueStart)->modify('+6 day');
  return $dias[0].' '.$ini->format('d/m').' → '.$dias[6].' '.$fin->format('d/m');
}
function horarioSucursalParaFecha(mysqli $conn, int $idSucursal, string $fechaYmd): ?array{
  $dow=(int)date('N', strtotime($fechaYmd)); // 1..7
  $st=$conn->prepare("SELECT abre,cierra,cerrado FROM sucursales_horario WHERE id_sucursal=? AND dia_semana=? LIMIT 1");
  $st->bind_param('ii',$idSucursal,$dow);
  $st->execute();
  $res=$st->get_result()->fetch_assoc();
  $st->close();
  return $res ?: null;
}
function hasColumn(mysqli $conn, string $table, string $column): bool{
  $t=$conn->real_escape_string($table);
  $c=$conn->real_escape_string($column);
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1";
  $rs=$conn->query($sql);
  return $rs && $rs->num_rows>0;
}

/* ===== Helpers para autodetectar ENUM dia_descanso (con o sin acentos) ===== */
function enumValues(mysqli $conn, string $table, string $column): array {
  $sql = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
  $st = $conn->prepare($sql);
  $st->bind_param('ss', $table, $column);
  $st->execute();
  $col = $st->get_result()->fetch_assoc()['COLUMN_TYPE'] ?? '';
  $st->close();
  if (!preg_match_all("/'([^']+)'/", $col, $m)) return [];
  return $m[1];
}
function mapDiaEnum(mysqli $conn): array {
  $enum = enumValues($conn, 'descansos_programados', 'dia_descanso'); // e.g. ['Mar','Mie',...]
  // variantes estándar
  $acc   = [2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom',1=>'Lun'];
  $plain = [2=>'Mar',3=>'Mie',4=>'Jue',5=>'Vie',6=>'Sab',7=>'Dom',1=>'Lun'];
  // elegir juego base según presencia de acentos
  $pick = (in_array('Mié', $enum, true) || in_array('Sáb', $enum, true)) ? $acc : $plain;
  // normalizar por si el ENUM trae variantes
  foreach ($pick as $k=>$v){ if (!in_array($v, $enum, true)) { $pick[$k] = $plain[$k]; } }
  return $pick;
}

/* ===== Geo helpers ===== */
function normalizeCoords($latRaw, $lngRaw): array {
  $lat = ($latRaw !== '' && $latRaw !== null) ? (float)$latRaw : null;
  $lng = ($lngRaw !== '' && $lngRaw !== null) ? (float)$lngRaw : null;
  // evitar 0 como coordenada “válida”
  if ($lat !== null && abs($lat) < 0.000001) $lat = null;
  if ($lng !== null && abs($lng) < 0.000001) $lng = null;
  return [$lat, $lng];
}
function fallbackCoords(): array {
  return [ (float)FALLBACK_LAT, (float)FALLBACK_LNG ];
}

/* ========= Session data ========= */
$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$nombreUser = trim($_SESSION['nombre'] ?? 'Usuario');
$rolUser    = $_SESSION['rol'] ?? '';
$isManager  = in_array($rolUser, ['Gerente','Admin','GerenteZona'], true);

$msg = '';

/* ========= Vacaciones (para aviso y bloqueo UX) ========= */
$vacResumen = ['ok' => false, 'dias_disponibles' => 0];
$vacDiasDisponibles = 0;
$vacPuedeSolicitar = false;
if ($idUsuario > 0 && function_exists('obtener_resumen_vacaciones_usuario')) {
  try {
    $vacResumen = obtener_resumen_vacaciones_usuario($conn, $idUsuario);
    $vacDiasDisponibles = (int)($vacResumen['dias_disponibles'] ?? 0);
    $vacPuedeSolicitar = !empty($vacResumen['ok']) && $vacDiasDisponibles > 0;
  } catch (Throwable $e) {
    $vacResumen = ['ok' => false, 'dias_disponibles' => 0];
    $vacDiasDisponibles = 0;
    $vacPuedeSolicitar = false;
  }
}

/* ========= Acceso por SUBTIPO de sucursal ========= */
/* Garantiza tener id_sucursal en sesión (fallback a usuarios.id_sucursal) */
if ($idSucursal <= 0) {
  $st = $conn->prepare("SELECT id_sucursal FROM usuarios WHERE id=? LIMIT 1");
  $st->bind_param('i', $idUsuario);
  $st->execute();
  if ($u = $st->get_result()->fetch_assoc()) {
    $idSucursal = (int)$u['id_sucursal'];
    $_SESSION['id_sucursal'] = $idSucursal;
  }
  $st->close();
}

/* Lee nombre y subtipo de la sucursal */
$nombreSucursalActual = '';
$subtipoSucursal      = '';
$esSucursalPropia     = false;

if ($idSucursal > 0) {
  $st = $conn->prepare("SELECT nombre, subtipo FROM sucursales WHERE id=? LIMIT 1");
  $st->bind_param('i', $idSucursal);
  $st->execute();
  if ($s = $st->get_result()->fetch_assoc()) {
    $nombreSucursalActual = trim($s['nombre'] ?? '');
    $subtipoSucursal      = trim($s['subtipo'] ?? '');
    $esSucursalPropia     = (strcasecmp($subtipoSucursal, 'Propia') === 0);
  }
  $st->close();
}

if (isset($_GET['debug'])) {
  error_log("asistencia.php DEBUG => id_usuario={$idUsuario}, id_sucursal={$idSucursal}, sucursal='{$nombreSucursalActual}', subtipo='{$subtipoSucursal}', esPropia=" . ($esSucursalPropia ? '1' : '0'));
}

/* Si NO es Propia ⇒ mostrar aviso y terminar (sin UI de marcaje) */
if (!$esSucursalPropia) {
  require_once __DIR__ . '/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Asistencia</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>
  <div class="container my-5">
    <div class="alert alert-info shadow-sm">
      <i class="bi bi-info-circle me-2"></i>
      Para tu sucursal no es necesario registrar asistencia.
      <div class="small text-muted mt-1">
        Sucursal: <b><?= h($nombreSucursalActual ?: '—') ?></b>
        <?php if ($subtipoSucursal !== ''): ?> · Subtipo: <b><?= h($subtipoSucursal) ?></b><?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
<?php
  exit;
}

/* ========= Datos de hoy y de semana operativa ========= */
$hoyYmd       = date('Y-m-d');
$horarioHoy   = horarioSucursalParaFecha($conn, $idSucursal, $hoyYmd);
$sucCerrada   = $horarioHoy ? ((int)$horarioHoy['cerrado'] === 1 || empty($horarioHoy['abre'])) : false;
$horaApertura = $horarioHoy && !$sucCerrada ? $horarioHoy['abre'] : null;
$horaCierre   = $horarioHoy && !$sucCerrada ? ($horarioHoy['cierra'] ?? null) : null;
$toleranciaStr = $horaApertura ? DateTime::createFromFormat('H:i:s', $horaApertura)->modify('+10 minutes')->format('H:i:s') : null;

// Semana seleccionada (para panel de descansos / permisos)
$weekSelected  = $_GET['semana_g'] ?? $_POST['semana_g'] ?? currentOpWeekIso();
$tuesdayStart  = opWeekStartFromWeekInput($weekSelected) ?: new DateTime('tuesday this week');
$startWeekYmd  = $tuesdayStart->format('Y-m-d');
$endWeekYmd    = (clone $tuesdayStart)->modify('+6 day')->format('Y-m-d');
$days = [];
for ($i = 0; $i < 7; $i++) { $d = clone $tuesdayStart; $d->modify("+$i day"); $days[] = $d; }
$weekNames = ['Mar','Mié','Jue','Vie','Sáb','Dom','Lun'];

/* ========= Flags de día: descanso / permiso ========= */
$st = $conn->prepare("SELECT 1 FROM descansos_programados WHERE id_usuario=? AND fecha=? AND es_descanso=1 LIMIT 1");
$st->bind_param('is', $idUsuario, $hoyYmd);
$st->execute();
$resDesc = $st->get_result();
$esDescansoHoy = ($resDesc && $resDesc->num_rows > 0);
$st->close();

$st = $conn->prepare("SELECT status FROM permisos_solicitudes WHERE id_usuario=? AND fecha=? LIMIT 1");
$st->bind_param('is', $idUsuario, $hoyYmd);
$st->execute();
$permHoy = $st->get_result()->fetch_assoc();
$st->close();

$permisoAprobadoHoy = ($permHoy && ($permHoy['status'] ?? '') === 'Aprobado');

$bloqueadoParaCheckIn = $esDescansoHoy || $sucCerrada || $permisoAprobadoHoy;

/* ========= Detección de columnas (compatibilidad entre entornos) ========= */
$colEstatus      = hasColumn($conn, 'asistencias', 'estatus');
$colLatOut       = hasColumn($conn, 'asistencias', 'latitud_salida');
$colLngOut       = hasColumn($conn, 'asistencias', 'longitud_salida');
$tieneColsSalida = $colLatOut && $colLngOut;

/* ========= Acciones de marcaje ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['checkin','checkout'], true)) {
  $action = $_POST['action'];
  $ip     = client_ip();
  $metodo = 'web';

  if ($action === 'checkin') {
    if ($bloqueadoParaCheckIn) {
      $razon = $esDescansoHoy ? 'día de descanso' : ($permisoAprobadoHoy ? 'permiso aprobado' : 'sucursal cerrada');
      $msg = "<div class='alert alert-warning mb-3'>Hoy es <b>$razon</b>. No es necesario registrar entrada.</div>";
    } else {
      $st = $conn->prepare("SELECT id, hora_salida FROM asistencias WHERE id_usuario=? AND fecha=? ORDER BY id DESC LIMIT 1");
      $st->bind_param('is',$idUsuario,$hoyYmd);
      $st->execute();
      $exist = $st->get_result()->fetch_assoc();
      $st->close();

      if ($exist) {
        $msg = $exist['hora_salida'] === null
          ? "<div class='alert alert-warning mb-3'>Ya tienes una <b>entrada abierta</b> hoy. Marca salida primero.</div>"
          : "<div class='alert alert-info mb-3'>Ya registraste asistencia hoy. No es necesario otro check-in.</div>";
      } else {
        [$lat, $lng] = normalizeCoords($_POST['lat'] ?? '', $_POST['lng'] ?? '');
        $usoFallback = false;

        if (($lat === null || $lng === null) && ALLOW_FALLBACK_GEO) {
          [$lat, $lng] = fallbackCoords();
          $usoFallback = true;
          $metodo = 'web_fijo';
        }

        if ($lat === null || $lng === null) {
          $msg = "<div class='alert alert-danger mb-3'>No se pudo obtener ubicación (ni fallback). Revisa configuración.</div>";
        } else {
          $retardo=0; $retMin=0;
          if ($horaApertura) {
            $now = new DateTime();
            $tolDT = DateTime::createFromFormat('H:i:s',$horaApertura)
              ->setDate((int)date('Y'),(int)date('m'),(int)date('d'))
              ->modify('+10 minutes');
            if ($now > $tolDT) {
              $retardo=1;
              $retMin=(int)round(($now->getTimestamp()-$tolDT->getTimestamp())/60);
            }
          }

          if ($colEstatus) {
            $estatus='Asistencia';
            $sql="INSERT INTO asistencias
                  (id_usuario,id_sucursal,fecha,hora_entrada,estatus,retardo,retardo_minutos,latitud,longitud,ip,metodo)
                  VALUES (?,?,?,NOW(),?,?,?,?,?,?,?)";
            $st=$conn->prepare($sql);
            $st->bind_param('iissiiddss',$idUsuario,$idSucursal,$hoyYmd,$estatus,$retardo,$retMin,$lat,$lng,$ip,$metodo);
          } else {
            $sql="INSERT INTO asistencias
                  (id_usuario,id_sucursal,fecha,hora_entrada,retardo,retardo_minutos,latitud,longitud,ip,metodo)
                  VALUES (?,?,?,NOW(),?,?,?,?,?,?)";
            $st=$conn->prepare($sql);
            $st->bind_param('iisiiddss',$idUsuario,$idSucursal,$hoyYmd,$retardo,$retMin,$lat,$lng,$ip,$metodo);
          }

          if ($st->execute()) {
            $badge = $usoFallback ? " <span class='badge bg-secondary'>Sin GPS (fijo)</span>" : "";
            $msg="<div class='alert alert-success mb-3'>✅ Entrada registrada{$badge}".($retardo?" <span class='badge bg-warning text-dark'>Retardo +{$retMin}m</span>":'')."</div>";
          } else {
            $msg="<div class='alert alert-danger mb-3'>Error al registrar la entrada.</div>";
          }
          $st->close();
        }
      }
    }
  }

  if ($action === 'checkout') {
    $st=$conn->prepare("
      SELECT id, TIMESTAMPDIFF(MINUTE, hora_entrada, NOW()) AS mins_desde_entrada
      FROM asistencias
      WHERE id_usuario=? AND fecha=? AND hora_salida IS NULL
      ORDER BY id ASC LIMIT 1
    ");
    $st->bind_param('is',$idUsuario,$hoyYmd);
    $st->execute();
    $abierta=$st->get_result()->fetch_assoc();
    $st->close();

    if (!$abierta) {
      $msg="<div class='alert alert-warning mb-3'>No hay una entrada abierta hoy para cerrar.</div>";
    } else {
      $minsDesdeEntrada=(int)($abierta['mins_desde_entrada'] ?? 0);
      if ($minsDesdeEntrada < MIN_SALIDA_MIN) {
        $faltan=max(0, MIN_SALIDA_MIN - $minsDesdeEntrada);
        $msg="<div class='alert alert-warning mb-3'>
                Para registrar la salida se requiere un mínimo de <b>".MIN_SALIDA_MIN." min</b> desde tu entrada.
                Te faltan <b>{$faltan} min</b>.
              </div>";
      } else {
        [$latOut, $lngOut] = normalizeCoords($_POST['lat_out'] ?? '', $_POST['lng_out'] ?? '');
        $usoFallback = false;

        if (($latOut === null || $lngOut === null) && ALLOW_FALLBACK_GEO) {
          [$latOut, $lngOut] = fallbackCoords();
          $usoFallback = true;
          $metodo = 'web_fijo';
        }

        if ($latOut === null || $lngOut === null) {
          $msg="<div class='alert alert-danger mb-3'>No se pudo obtener ubicación (ni fallback) para salida.</div>";
        } else {
          $idAsist=(int)$abierta['id'];
          $ipNow=client_ip();

          if ($tieneColsSalida) {
            $sql="UPDATE asistencias
                  SET hora_salida=NOW(),
                      duracion_minutos=TIMESTAMPDIFF(MINUTE,hora_entrada,NOW()),
                      latitud_salida=?, longitud_salida=?, ip=?, metodo=?
                  WHERE id=? AND hora_salida IS NULL";
            $st=$conn->prepare($sql);
            $st->bind_param('ddssi',$latOut,$lngOut,$ipNow,$metodo,$idAsist);
          } else {
            $sql="UPDATE asistencias
                  SET hora_salida=NOW(),
                      duracion_minutos=TIMESTAMPDIFF(MINUTE,hora_entrada,NOW()),
                      ip=?, metodo=?
                  WHERE id=? AND hora_salida IS NULL";
            $st=$conn->prepare($sql);
            $st->bind_param('ssi',$ipNow,$metodo,$idAsist);
          }

          if ($st->execute() && $st->affected_rows > 0) {
            $badge = $usoFallback ? " <span class='badge bg-secondary'>Sin GPS (fijo)</span>" : "";
            $msg="<div class='alert alert-success mb-3'>✅ Salida registrada{$badge}.</div>";
          } else {
            $msg="<div class='alert alert-danger mb-3'>No se pudo registrar la salida.</div>";
          }
          $st->close();
        }
      }
    }
  }
}

/* ========= Datos de hoy ========= */
$st=$conn->prepare("SELECT * FROM asistencias WHERE id_usuario=? AND fecha=? ORDER BY id DESC LIMIT 1");
$st->bind_param('is',$idUsuario,$hoyYmd);
$st->execute();
$asistHoy=$st->get_result()->fetch_assoc();
$st->close();

$st=$conn->prepare("SELECT id, hora_entrada FROM asistencias WHERE id_usuario=? AND fecha=? AND hora_salida IS NULL LIMIT 1");
$st->bind_param('is',$idUsuario,$hoyYmd);
$st->execute();
$abiertaHoy=$st->get_result()->fetch_assoc();
$st->close();

$puedeCheckIn  = !$asistHoy && !$bloqueadoParaCheckIn;
$puedeCheckOut = $abiertaHoy !== null;

$entradaHoy    = $asistHoy['hora_entrada'] ?? null;
$salidaHoy     = $asistHoy['hora_salida']  ?? null;
$duracionHoy   = $asistHoy['duracion_minutos'] ?? null;
$retardoHoy    = (int)($asistHoy['retardo'] ?? 0);
$retardoMinHoy = (int)($asistHoy['retardo_minutos'] ?? 0);

/* ==== Cálculo para UI: bloqueo de salida anticipada ==== */
$minsDesdeEntradaUI=null; $bloqueoAnticipadoUI=false; $faltanUI=0;
if ($puedeCheckOut && $entradaHoy && !$salidaHoy) {
  $entradaStr=(string)$entradaHoy;
  $entradaDT=(strlen($entradaStr)<=8)?strtotime($hoyYmd.' '.$entradaStr):strtotime($entradaStr);
  if ($entradaDT) {
    $minsDesdeEntradaUI=(int)floor((time()-$entradaDT)/60);
    $bloqueoAnticipadoUI = ($minsDesdeEntradaUI < MIN_SALIDA_MIN);
    if ($bloqueoAnticipadoUI) $faltanUI = max(0, MIN_SALIDA_MIN - $minsDesdeEntradaUI);
  }
}

/* ========= Historial (últimos 20) ========= */
$st=$conn->prepare("
  SELECT a.*, s.nombre AS sucursal
  FROM asistencias a
  LEFT JOIN sucursales s ON s.id=a.id_sucursal
  WHERE a.id_usuario=?
  ORDER BY a.fecha DESC, a.id DESC
  LIMIT 20
");
$st->bind_param('i',$idUsuario);
$st->execute();
$hist=$st->get_result()->fetch_all(MYSQLI_ASSOC);
$st->close();

/* ========= Paneles (descansos / permisos) ========= */
$staff=[]; $staffSuc=[];
if ($isManager) {
  $st=$conn->prepare("SELECT id,nombre,rol FROM usuarios WHERE id_sucursal=? AND activo=1 ORDER BY (rol='Gerente') DESC, nombre");
  $st->bind_param('i',$idSucursal);
  $st->execute();
  $staff=$st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();

  $st=$conn->prepare("SELECT id,nombre FROM usuarios WHERE id_sucursal=? AND activo=1 ORDER BY nombre");
  $st->bind_param('i',$idSucursal);
  $st->execute();
  $staffSuc=$st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
}

/* ===================== DESCANSOS: guardar / limpiar ===================== */
/* Política: historial semanal.
   - Solo tocamos la semana seleccionada (Mar→Lun) de ESTA sucursal.
   - No modificamos semanas anteriores o futuras.
   - Sin upsert global; limpiamos esa semana y reinsertamos lo marcado.
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isManager && isset($_POST['action']) && in_array($_POST['action'], ['guardar_descansos','limpiar_semana'], true)) {

  // Mapeo de día según el ENUM real de la tabla (con o sin acentos)
  $enumDia = mapDiaEnum($conn); // [2=>'Mar',3=>'Mie/Mié',...]

  $semanaIn = trim($_POST['semana_g'] ?? '');
  $tue = opWeekStartFromWeekInput($semanaIn);
  if (!$tue) {
    $msg = "<div class='alert alert-danger mb-3'>Semana inválida.</div>";
  } else {
    $semanaInicio = $tue->format('Y-m-d');                 // martes que inicia la semana operativa
    $startWeek    = $semanaInicio;
    $endWeek      = (clone $tue)->modify('+6 day')->format('Y-m-d');

    try {
      mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
      $conn->begin_transaction();

      // 1) Limpiar SOLO la semana seleccionada de ESTA sucursal (historial de otras semanas intacto)
      $sqlDel = "DELETE dp
           FROM descansos_programados dp
           JOIN usuarios u ON u.id = dp.id_usuario
           WHERE u.id_sucursal = ?
             AND dp.fecha BETWEEN ? AND ?";
      $st = $conn->prepare($sqlDel);
      $st->bind_param('iss', $idSucursal, $startWeek, $endWeek);
      $st->execute();
      $st->close();

      if ($_POST['action'] === 'limpiar_semana') {
        $conn->commit();
        $msg = "<div class='alert alert-warning mb-3'>🧹 Semana <b>".h($semanaInicio)."</b> limpiada para tu sucursal. Las demás semanas permanecen igual.</div>";
      } else {
        // 2) Insertar marcados de la semana (historial = una “versión” por semana)
        $sqlStaff = "SELECT id FROM usuarios WHERE id_sucursal=? AND activo=1 ORDER BY nombre";
        $st = $conn->prepare($sqlStaff);
        $st->bind_param('i', $idSucursal);
        $st->execute();
        $staffIds = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();

        $sqlIns = "INSERT INTO descansos_programados
           (id_usuario, fecha, es_descanso, creado_por, semana_inicio, dia_descanso, asignado_por)
           VALUES (?, ?, 1, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE
             es_descanso   = VALUES(es_descanso),
             creado_por    = VALUES(creado_por),
             semana_inicio = VALUES(semana_inicio),
             dia_descanso  = VALUES(dia_descanso),
             asignado_por  = VALUES(asignado_por)";
        $ins = $conn->prepare($sqlIns);
        $insertados = 0;
        foreach ($staffIds as $row) {
          $uid = (int)$row['id'];
          if (empty($_POST['descanso'][$uid]) || !is_array($_POST['descanso'][$uid])) continue;

          foreach ($_POST['descanso'][$uid] as $f => $v) {
            if ($v !== '1') continue;
            if ($f < $startWeek || $f > $endWeek) continue;

            $dow = (int)date('N', strtotime($f));      // 1..7
            $dia = $enumDia[$dow] ?? null;             // 'Mar','Mie/Mié',...
            if ($dia === null) continue;

            $ins->bind_param('isissi', $uid, $f, $idUsuario, $semanaInicio, $dia, $idUsuario);
            $ins->execute();
            $insertados++;
          }
        }
        $ins->close();

        $conn->commit();
        $msg = "<div class='alert alert-success mb-3'>✅ Descansos guardados para la semana <b>".h($semanaInicio)."</b>. Registros: {$insertados}. Las demás semanas no se tocaron.</div>";
      }
    } catch (mysqli_sql_exception $e) {
      $conn->rollback();
      $det = isset($_GET['debug']) ? " <small class='text-muted'>[{$e->getCode()}] ".h($e->getMessage())."</small>" : "";
      $msg = "<div class='alert alert-danger mb-3'>No se pudieron procesar los descansos.$det</div>";
    } finally {
      mysqli_report(MYSQLI_REPORT_OFF);
    }
  }
}

/* ===================== Permisos ===================== */
/* Permisos: crear (Gerente/Admin) — con manejo de duplicados (errno 1062) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'crear_permiso' && in_array($rolUser, ['Gerente','Admin'], true)) {
  $uid   = (int)($_POST['perm_uid'] ?? 0);
  $fecha = trim($_POST['perm_fecha'] ?? '');
  $motivo= trim($_POST['perm_motivo'] ?? '');
  $com   = trim($_POST['perm_com'] ?? '');

  $textoVac = mb_strtolower(trim($motivo . ' ' . $com), 'UTF-8');
  $pareceVacacion = (bool)preg_match('/\b(vacacion|vacaciones|vacacional|descanso vacacional|dia de vacaciones|d[ií]a de vacaciones)\b/u', $textoVac);

  if ($pareceVacacion) {
    $linkVac = 'solicitar_vacaciones.php';
    $extraVac = $vacPuedeSolicitar
      ? 'Tienes <b>' . (int)$vacDiasDisponibles . '</b> día(s) disponible(s) en este momento.'
      : 'En este momento no tienes días disponibles visibles desde tu perfil.';
    $msg = "<div class='alert alert-warning mb-3'><i class='bi bi-calendar2-week me-1'></i> Las vacaciones no se solicitan desde este formulario de permisos. Deben pedirse desde el <b>menú de tu perfil</b>, en la opción <b>Solicitar vacaciones</b>. {$extraVac} <a class='alert-link ms-1' href='{$linkVac}'>Ir a solicitar vacaciones</a>.</div>";
  } elseif (!$uid || !$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha) || $motivo==='') {
    $msg = "<div class='alert alert-danger mb-3'>Datos incompletos para permiso.</div>";
  } else {
    $st=$conn->prepare("SELECT id_sucursal FROM usuarios WHERE id=? AND activo=1 LIMIT 1");
    $st->bind_param('i',$uid);
    $st->execute();
    $ux=$st->get_result()->fetch_assoc();
    $st->close();
    if (!$ux || (int)$ux['id_sucursal'] !== $idSucursal) {
      $msg = "<div class='alert alert-danger mb-3'>El colaborador no pertenece a tu sucursal.</div>";
    } else {
      $st=$conn->prepare("SELECT id, `status` FROM permisos_solicitudes WHERE id_usuario=? AND fecha=? LIMIT 1");
      $st->bind_param('is',$uid,$fecha);
      $st->execute();
      $dup=$st->get_result()->fetch_assoc();
      $st->close();

      if ($dup) {
        $estado=$dup['status'] ?? 'Pendiente';
        $msg = "<div class='alert alert-warning mb-3'>Ya existe una solicitud para ese colaborador y fecha. Estado: <b>".h($estado)."</b>.</div>";
      } else {
        try{
          $sql="INSERT INTO permisos_solicitudes (id_usuario,id_sucursal,fecha,motivo,comentario,`status`,creado_por)
                VALUES (?,?,?,?,?, 'Pendiente', ?)";
          $st=$conn->prepare($sql);
          $st->bind_param('iisssi',$uid,$idSucursal,$fecha,$motivo,$com,$idUsuario);
          $st->execute();
          $st->close();
          $msg = "<div class='alert alert-success mb-3'>✅ Permiso registrado y enviado a Gerente de Zona.</div>";
        } catch (mysqli_sql_exception $e){
          if ((int)$e->getCode()===1062) {
            $msg = "<div class='alert alert-warning mb-3'>Ya existe una solicitud de permiso para ese colaborador y fecha.</div>";
          } else {
            $det = isset($_GET['debug']) ? " <small class='text-muted'>[{$e->getCode()}] ".h($e->getMessage())."</small>" : "";
            $msg = "<div class='alert alert-danger mb-3'>No se pudo registrar el permiso.$det</div>";
          }
        }
      }
    }
  }
}
/* Permisos: aprobar/rechazar (GZ/Admin) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['aprobar_permiso','rechazar_permiso'], true) && in_array($rolUser, ['GerenteZona','Admin'], true)) {
  $pid=(int)($_POST['perm_id'] ?? 0);
  $obs=trim($_POST['perm_obs'] ?? '');
  $stLabel = $_POST['action']==='aprobar_permiso' ? 'Aprobado' : 'Rechazado';
  if ($pid) {
    $st=$conn->prepare("UPDATE permisos_solicitudes SET status=?, aprobado_por=?, aprobado_en=NOW(), comentario_aprobador=? WHERE id=? AND status='Pendiente'");
    $st->bind_param('sisi',$stLabel,$idUsuario,$obs,$pid);
    $st->execute();
    $st->close();
    $msg = "<div class='alert alert-success mb-3'>✅ Permiso $stLabel.</div>";
  }
}

/* Listas permisos de la semana */
$permisosSemana=[];
if ($isManager) {
  $st=$conn->prepare("
    SELECT p.*, u.nombre AS usuario
    FROM permisos_solicitudes p
    JOIN usuarios u ON u.id=p.id_usuario
    WHERE p.id_sucursal=? AND p.fecha BETWEEN ? AND ?
    ORDER BY p.fecha DESC, p.id DESC
  ");
  $st->bind_param('iss',$idSucursal,$startWeekYmd,$endWeekYmd);
  $st->execute();
  $permisosSemana=$st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
}

$pendientesGZ=[];
if (in_array($rolUser, ['GerenteZona','Admin'], true)) {
  $st=$conn->prepare("
    SELECT p.*, u.nombre AS usuario, s.nombre AS sucursal
    FROM permisos_solicitudes p
    JOIN usuarios u ON u.id=p.id_usuario
    JOIN sucursales s ON s.id=p.id_sucursal
    WHERE p.status='Pendiente' AND p.fecha BETWEEN ? AND ?
    ORDER BY s.nombre, u.nombre, p.fecha
  ");
  $st->bind_param('ss',$startWeekYmd,$endWeekYmd);
  $st->execute();
  $pendientesGZ=$st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();
}

// Navbar (al final para aprovechar $msg si lo deseas arriba del contenido)
require_once __DIR__ . '/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Asistencia (Permisos + Horario)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root{ --brand:#0d6efd; --bg:#f8fafc; --ink:#0f172a; --muted:#64748b; }
    body{
      background: radial-gradient(1200px 400px at 120% -50%, rgba(13,110,253,.07), transparent),
                 radial-gradient(1000px 380px at -10% 120%, rgba(25,135,84,.06), transparent), var(--bg);
    }
    .page-title{ font-weight:800; letter-spacing:.2px; color:var(--ink); }
    .card-elev{ border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(15,23,42,.06), 0 2px 6px rgba(15,23,42,.05); }
    .section-title{ font-size:.95rem; font-weight:700; color:#334155; letter-spacing:.6px; text-transform:uppercase; display:flex; align-items:center; gap:.5rem; }
    .help-text{ color:var(--muted); font-size:.9rem; }
    .table-xs td,.table-xs th{ padding:.45rem .6rem; font-size:.92rem; }
    .badge-retardo{ background:#fff3cd; color:#8a6d3b; border:1px solid #ffeeba; }

    .hero-assist{
      background:
        radial-gradient(700px 220px at 0% 0%, rgba(13,110,253,.10), transparent 60%),
        radial-gradient(650px 220px at 100% 0%, rgba(25,135,84,.08), transparent 60%),
        linear-gradient(135deg, #ffffff, #f8fbff);
      border:1px solid rgba(13,110,253,.08);
      border-radius:1.25rem;
      box-shadow:0 14px 34px rgba(15,23,42,.07), 0 2px 8px rgba(15,23,42,.04);
    }
    .mini-stat{
      border:1px solid rgba(148,163,184,.18);
      border-radius:1rem;
      background:#fff;
      padding:1rem;
      height:100%;
    }
    .mini-stat .label{ color:#64748b; font-size:.82rem; text-transform:uppercase; letter-spacing:.4px; font-weight:700; }
    .mini-stat .value{ color:#0f172a; font-size:1.15rem; font-weight:800; }
    .warning-vac-box{
      border:1px solid rgba(255,193,7,.35);
      background:linear-gradient(135deg, rgba(255,248,225,.95), rgba(255,243,205,.9));
      border-radius:1rem;
      padding:1rem 1.1rem;
    }
    .warning-vac-box .title{
      font-weight:800;
      color:#7c5a00;
      margin-bottom:.2rem;
    }
    .form-permisos input, .form-permisos select{
      border-radius:.85rem;
      min-height:46px;
    }
    .form-permisos .is-warning{
      border-color:#ffc107 !important;
      box-shadow:0 0 0 .25rem rgba(255,193,7,.18);
    }
    .btn-soft-primary{
      background:rgba(13,110,253,.08);
      border:1px solid rgba(13,110,253,.14);
      color:#0d6efd;
    }
    .btn-soft-primary:hover{
      background:rgba(13,110,253,.14);
      color:#0a58ca;
    }
  </style>
</head>
<body>

<div class="container my-4">
  <div class="hero-assist p-4 p-lg-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div>
        <div class="d-inline-flex align-items-center gap-2 px-3 py-2 rounded-pill bg-white shadow-sm mb-3">
          <i class="bi bi-person-check text-primary"></i>
          <span class="fw-semibold text-dark">Control de asistencia y permisos</span>
        </div>
        <h2 class="page-title mb-2">Asistencia</h2>
        <div class="help-text">Hola, <b><?= h($nombreUser) ?></b>. Aquí puedes registrar tu entrada y salida, consultar tus marcajes y gestionar permisos operativos.</div>
      </div>
      <div class="text-lg-end">
        <div class="help-text mb-2">Semana operativa</div>
        <div class="btn btn-soft-primary btn-sm disabled">
          <i class="bi bi-calendar3 me-1"></i><?= h(fmtBadgeRango($tuesdayStart)) ?>
        </div>
      </div>
    </div>

    <div class="row g-3 mt-1">
      <div class="col-md-4">
        <div class="mini-stat">
          <div class="label">Sucursal</div>
          <div class="value"><?= h($nombreSucursalActual ?: 'Sin sucursal') ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="mini-stat">
          <div class="label">Rol</div>
          <div class="value"><?= h($rolUser ?: 'Usuario') ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="mini-stat">
          <div class="label">Vacaciones disponibles</div>
          <div class="value"><?= (int)$vacDiasDisponibles ?> día(s)</div>
        </div>
      </div>
    </div>
  </div>

  <?= $msg ?>

  <?php if ($sucCerrada): ?>
    <div class="alert alert-secondary"><i class="bi bi-door-closed me-1"></i>Sucursal <b>cerrada</b> hoy.</div>
  <?php endif; ?>
  <?php if ($esDescansoHoy): ?>
    <div class="alert alert-light border"><i class="bi bi-moon-stars me-1"></i>Hoy es tu <b>descanso</b>. <span class="text-muted">No cuenta como falta.</span></div>
  <?php endif; ?>
  <?php if ($permHoy && ($permHoy['status'] ?? '') === 'Aprobado'): ?>
    <div class="alert alert-info"><i class="bi bi-clipboard-check me-1"></i>Tienes un <b>permiso aprobado</b> para hoy. <span class="text-muted">No cuenta como falta.</span></div>
  <?php endif; ?>

  <!-- Estado de hoy -->
  <div class="card card-elev mb-4">
    <div class="card-body">
      <div class="row g-3 align-items-center">
        <div class="col-md-6">
          <div class="section-title mb-2"><i class="bi bi-calendar-day"></i> Tu día de hoy</div>
          <ul class="list-unstyled mb-2">
            <li><b>Entrada:</b> <?= $entradaHoy ? h($entradaHoy) : '<span class="text-muted">—</span>' ?>
              <?php if ($entradaHoy && $retardoHoy): ?><span class="badge badge-retardo ms-2">Retardo +<?= (int)$retardoMinHoy ?> min</span><?php endif; ?>
            </li>
            <li><b>Salida:</b> <?= $salidaHoy ? h($salidaHoy) : '<span class="text-muted">—</span>' ?></li>
            <li><b>Duración:</b> <?= $duracionHoy !== null ? (int)$duracionHoy . ' min' : '<span class="text-muted">—</span>' ?></li>
            <?php if ($bloqueoAnticipadoUI): ?>
              <li class="text-danger"><i class="bi bi-hourglass-split me-1"></i>
                Te faltan <b><?= (int)$faltanUI ?></b> min para poder registrar la salida (mínimo <?= MIN_SALIDA_MIN ?> min).
              </li>
            <?php endif; ?>
          </ul>
          <div class="help-text">
            <?php if ($sucCerrada): ?>
              <i class="bi bi-info-circle me-1"></i>Hoy no hay horario laboral (cerrada).
            <?php elseif ($horaApertura): ?>
              <i class="bi bi-clock me-1"></i>Horario de hoy: <b><?= h(substr($horaApertura,0,5)) ?></b><?= $horaCierre ? '– <b>'.h(substr($horaCierre,0,5)).'</b>' : '' ?>.
              Tolerancia: <b>10 min</b> (retardo después de <b><?= h(substr($toleranciaStr,0,5)) ?></b>).
            <?php else: ?>
              <i class="bi bi-clock me-1"></i>Sin horario configurado para hoy.
            <?php endif; ?>
          </div>
        </div>
        <div class="col-md-6">
          <div class="d-flex flex-wrap gap-2 justify-content-md-end">
            <!-- CHECK-IN -->
            <form method="post" class="d-inline" onsubmit="return prepGeoIn(this)">
              <input type="hidden" name="action" value="checkin">
              <input type="hidden" name="lat" id="lat_in">
              <input type="hidden" name="lng" id="lng_in">
              <button class="btn btn-success btn-lg" id="btnIn" <?= $puedeCheckIn ? '' : 'disabled' ?>>
                <i class="bi bi-box-arrow-in-right me-1"></i> Entrar
              </button>
            </form>
            <!-- CHECK-OUT -->
            <form method="post" class="d-inline" onsubmit="return prepGeoOut(this)">
              <input type="hidden" name="action" value="checkout">
              <input type="hidden" name="lat_out" id="lat_out">
              <input type="hidden" name="lng_out" id="lng_out">
              <?php
              $btnOutDisabled = !$puedeCheckOut || $bloqueoAnticipadoUI;
              $btnOutTitle = $bloqueoAnticipadoUI ? "Debes esperar {$faltanUI} min (mínimo ".MIN_SALIDA_MIN." min desde tu entrada)" : "";
              ?>
              <button class="btn btn-danger btn-lg" id="btnOut" <?= $btnOutDisabled ? 'disabled' : '' ?> title="<?= h($btnOutTitle) ?>">
                <i class="bi bi-box-arrow-right me-1"></i> Salir
              </button>
            </form>
          </div>
          <div class="help-text mt-2"><i class="bi bi-shield-check me-1"></i> </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Historial -->
  <div class="card card-elev mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="section-title mb-0"><i class="bi bi-clock-history"></i> Tus últimos marcajes</div>
      <span class="badge bg-light text-dark">Últimos <?= count($hist) ?> registros</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-xs align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th>Fecha</th>
              <th>Entrada</th>
              <th>Salida</th>
              <th class="text-end">Duración (min)</th>
              <th>Sucursal</th>
              <th>Mapa</th>
              <th>IP</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$hist): ?>
            <tr><td colspan="7" class="text-muted">Sin registros.</td></tr>
          <?php else: foreach ($hist as $r): ?>
            <tr class="<?= $r['hora_salida'] ? '' : 'table-warning' ?>">
              <td><?= h($r['fecha']) ?></td>
              <td><?= h($r['hora_entrada']) ?></td>
              <td><?= $r['hora_salida'] ? h($r['hora_salida']) : '<span class="text-muted">—</span>' ?></td>
              <td class="text-end"><?= $r['duracion_minutos'] !== null ? (int)$r['duracion_minutos'] : '—' ?></td>
              <td><?= h($r['sucursal'] ?? 'N/D') ?></td>
              <td>
                <?php if ($r['latitud'] !== null && $r['longitud'] !== null):
                  $url='https://maps.google.com/?q='.urlencode($r['latitud'].','.$r['longitud']); ?>
                  <a href="<?= h($url) ?>" class="btn btn-sm btn-outline-primary" target="_blank"><i class="bi bi-geo"></i> Ver mapa</a>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
              </td>
              <td><code><?= h($r['ip'] ?? '—') ?></code></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if ($isManager): ?>
  <!-- Descansos (Mar→Lun) -->
  <div class="card card-elev mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div class="section-title mb-0"><i class="bi bi-moon-stars"></i> Descansos por semana (Mar→Lun)</div>
      <form method="get" class="d-flex align-items-center gap-2">
        <span class="badge text-bg-secondary"><?= h(fmtBadgeRango($tuesdayStart)) ?></span>
        <label class="form-label mb-0">Semana</label>
        <input type="week" name="semana_g" value="<?= h($weekSelected) ?>" class="form-control form-control-sm">
        <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat me-1"></i> Ver</button>
      </form>
    </div>
    <div class="card-body p-0">
      <?php
      $pre=[];
      $st=$conn->prepare("SELECT id_usuario,fecha FROM descansos_programados WHERE fecha BETWEEN ? AND ? AND id_usuario IN (SELECT id FROM usuarios WHERE id_sucursal=? AND activo=1)");
      $st->bind_param('ssi',$startWeekYmd,$endWeekYmd,$idSucursal);
      $st->execute();
      $rows=$st->get_result()->fetch_all(MYSQLI_ASSOC);
      $st->close();
      foreach($rows as $r){ $pre[(int)$r['id_usuario']][$r['fecha']] = true; }
      ?>
      <?php if (!$staff): ?>
        <div class="p-3 text-muted">No hay personal activo en tu sucursal.</div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="semana_g" value="<?= h($weekSelected) ?>">
        <div class="table-responsive">
          <table class="table table-hover table-xs align-middle mb-0">
            <thead class="table-dark">
              <tr>
                <th>Colaborador</th>
                <th>Rol</th>
                <?php foreach ($days as $idx=>$d): ?>
                  <th class="text-center"><?= $weekNames[$idx] ?><br><small class="text-muted"><?= $d->format('d/m') ?></small></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($staff as $u): ?>
                <tr>
                  <td class="fw-semibold"><?= h($u['nombre']) ?></td>
                  <td><span class="badge bg-light text-dark"><?= h($u['rol']) ?></span></td>
                  <?php foreach ($days as $d): $f=$d->format('Y-m-d'); $checked=!empty($pre[(int)$u['id']][$f]); ?>
                    <td class="text-center">
                      <input type="checkbox" class="form-check-input" name="descanso[<?= (int)$u['id'] ?>][<?= $f ?>]" value="1" <?= $checked?'checked':'' ?>>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="d-flex justify-content-between align-items-center p-2 border-top bg-white">
          <button class="btn btn-outline-secondary" name="action" value="limpiar_semana"><i class="bi bi-eraser me-1"></i> Limpiar semana</button>
          <button class="btn btn-success" name="action" value="guardar_descansos"><i class="bi bi-save me-1"></i> Guardar descansos</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (in_array($rolUser, ['Gerente','Admin'], true)): ?>
  <!-- Solicitud de permisos -->
  <div class="card card-elev mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="section-title mb-0"><i class="bi bi-clipboard-plus"></i> Solicitud de permisos</div>
      <span class="help-text">Si lo aprueba el Gerente de Zona, no contará como falta.</span>
    </div>
    <div class="card-body">
      <div class="warning-vac-box mb-3">
        <div class="title"><i class="bi bi-megaphone-fill me-2"></i>Ojo con las vacaciones</div>
        <div class="small text-muted">
          Este formulario es solo para <b>permisos</b>. Las <b>vacaciones no se solicitan aquí</b>.
          Deben pedirse desde el <b>menú de tu perfil</b> en la opción <b>Solicitar vacaciones</b>
          <?php if ($vacPuedeSolicitar): ?>
            <span class="d-block mt-1 text-dark">Actualmente tienes <b><?= (int)$vacDiasDisponibles ?></b> día(s) disponible(s).</span>
          <?php else: ?>
            <span class="d-block mt-1 text-dark">Si no ves esa opción habilitada, es porque por ahora no tienes días disponibles.</span>
          <?php endif; ?>
        </div>
      </div>

      <form method="post" class="row g-2 form-permisos" id="formPermisos">
        <input type="hidden" name="action" value="crear_permiso">
        <div class="col-md-3">
          <label class="form-label mb-0">Colaborador</label>
          <select name="perm_uid" class="form-select" required>
            <option value="">-- Selecciona --</option>
            <?php foreach ($staffSuc as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= h($u['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label mb-0">Fecha</label>
          <input type="date" name="perm_fecha" class="form-control" required value="<?= $tuesdayStart->format('Y-m-d') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label mb-0">Motivo</label>
          <input type="text" name="perm_motivo" id="perm_motivo" class="form-control" required placeholder="Ej. cita médica">
        </div>
        <div class="col-md-3">
          <label class="form-label mb-0">Comentario (opcional)</label>
          <input type="text" name="perm_com" id="perm_com" class="form-control" placeholder="Detalle adicional">
        </div>
        <div class="col-12 text-end">
          <button class="btn btn-warning"><i class="bi bi-send-plus me-1"></i> Enviar</button>
        </div>
      </form>

      <hr>
      <div class="section-title mb-2"><i class="bi bi-list-check"></i> Permisos de la semana (tu sucursal)</div>
      <div class="table-responsive">
        <table class="table table-striped table-xs align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th>Colaborador</th><th>Fecha</th><th>Motivo</th><th>Comentario</th><th>Status</th><th>Resuelto por</th><th>Obs.</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$permisosSemana): ?>
            <tr><td colspan="7" class="text-muted">Sin solicitudes esta semana.</td></tr>
          <?php else: foreach ($permisosSemana as $p): ?>
            <tr>
              <td><?= h($p['usuario']) ?></td>
              <td><?= h($p['fecha']) ?></td>
              <td><?= h($p['motivo']) ?></td>
              <td><?= h($p['comentario'] ?? '—') ?></td>
              <td><span class="badge <?= $p['status']==='Aprobado'?'bg-success':($p['status']==='Rechazado'?'bg-danger':'bg-warning text-dark') ?>"><?= h($p['status']) ?></span></td>
              <td><?= $p['aprobado_por'] ? (int)$p['aprobado_por'] : '—' ?></td>
              <td><?= h($p['comentario_aprobador'] ?? '—') ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Pendientes GZ -->
  <?php if (in_array($rolUser, ['GerenteZona','Admin'], true)): ?>
  <div class="card card-elev">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="section-title mb-0"><i class="bi bi-inboxes"></i> Permisos pendientes (Mar→Lun)</div>
      <span class="badge bg-danger"><?= count($pendientesGZ) ?></span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover table-xs align-middle mb-0">
          <thead class="table-dark">
            <tr>
              <th>Sucursal</th><th>Colaborador</th><th>Fecha</th><th>Motivo</th><th>Comentario</th><th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$pendientesGZ): ?>
            <tr><td colspan="6" class="text-muted">Sin pendientes.</td></tr>
          <?php else: foreach ($pendientesGZ as $p): ?>
            <tr>
              <td><?= h($p['sucursal']) ?></td>
              <td><?= h($p['usuario']) ?></td>
              <td><?= h($p['fecha']) ?></td>
              <td><?= h($p['motivo']) ?></td>
              <td><?= h($p['comentario'] ?? '—') ?></td>
              <td class="text-end">
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="aprobar_permiso">
                  <input type="hidden" name="perm_id" value="<?= (int)$p['id'] ?>">
                  <input type="hidden" name="perm_obs" value="">
                  <button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Aprobar</button>
                </form>
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="rechazar_permiso">
                  <input type="hidden" name="perm_id" value="<?= (int)$p['id'] ?>">
                  <input type="hidden" name="perm_obs" value="">
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Rechazar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; // GZ/Admin ?>
  <?php endif; // isManager ?>
</div>


<div class="modal fade" id="modalVacacionesInfo" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-warning-subtle">
        <h5 class="modal-title"><i class="bi bi-calendar2-week me-2"></i>Las vacaciones van por otro camino</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Este formulario es para <b>permisos</b>. Las <b>vacaciones no se solicitan aquí</b>.</p>
        <p class="mb-2">Debes entrar al <b>menú de tu perfil</b> y usar la opción <b>Solicitar vacaciones</b>.</p>
        <?php if ($vacPuedeSolicitar): ?>
          <div class="alert alert-success mb-0">Tienes <b><?= (int)$vacDiasDisponibles ?></b> día(s) disponible(s) para solicitar.</div>
        <?php else: ?>
          <div class="alert alert-secondary mb-0">En este momento no aparece disponibilidad de vacaciones desde tu perfil.</div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <a href="solicitar_vacaciones.php" class="btn btn-warning">
          <i class="bi bi-box-arrow-up-right me-1"></i>Ir a solicitar vacaciones
        </a>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Entendido</button>
      </div>
    </div>
  </div>
</div>

<script>

  const VAC_RE = /\b(vacacion|vacaciones|vacacional|descanso vacacional|dia de vacaciones|d[ií]a de vacaciones)\b/i;

  document.addEventListener('DOMContentLoaded', function(){
    const formPerm = document.getElementById('formPermisos');
    const motivo = document.getElementById('perm_motivo');
    const comentario = document.getElementById('perm_com');
    const modalEl = document.getElementById('modalVacacionesInfo');
    const vacModal = modalEl ? new bootstrap.Modal(modalEl) : null;

    function pareceVacacion(){
      const txt = ((motivo?.value || '') + ' ' + (comentario?.value || '')).trim();
      return VAC_RE.test(txt);
    }

    [motivo, comentario].forEach(el => {
      el?.addEventListener('input', function(){
        if (pareceVacacion()) {
          motivo?.classList.add('is-warning');
          comentario?.classList.add('is-warning');
        } else {
          motivo?.classList.remove('is-warning');
          comentario?.classList.remove('is-warning');
        }
      });
    });

    formPerm?.addEventListener('submit', function(ev){
      if (pareceVacacion()) {
        ev.preventDefault();
        vacModal?.show();
      }
    });
  });

  function prepGeoIn(form){
    const btn=document.getElementById('btnIn'); if(btn) btn.disabled=true;

    // Si no hay geo o falla, mandamos igual para que el backend use fallback fijo
    if(!navigator.geolocation){
      form.submit(); return false;
    }

    navigator.geolocation.getCurrentPosition(
      (pos)=>{
        document.getElementById('lat_in').value=pos.coords.latitude;
        document.getElementById('lng_in').value=pos.coords.longitude;
        form.submit();
      },
      (_)=>{
        // FALLA en PC? no bloqueamos
        form.submit();
      },
      { enableHighAccuracy:false, timeout:15000, maximumAge:600000 }
    );
    return false;
  }

  function prepGeoOut(form){
    const btn=document.getElementById('btnOut'); if(btn) btn.disabled=true;

    if(!navigator.geolocation){
      form.submit(); return false;
    }

    navigator.geolocation.getCurrentPosition(
      (pos)=>{
        document.getElementById('lat_out').value=pos.coords.latitude;
        document.getElementById('lng_out').value=pos.coords.longitude;
        form.submit();
      },
      (_)=>{
        form.submit();
      },
      { enableHighAccuracy:false, timeout:15000, maximumAge:600000 }
    );
    return false;
  }
</script>
</body>
</html>