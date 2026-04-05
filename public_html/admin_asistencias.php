<?php
// admin_asistencias.php · Panel Admin con KPIs + Matriz + Detalle + Permisos + Solicitudes + Export CSV + Modales
ob_start();
session_start();

if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

/* ===== Debug opcional ===== */
$DEBUG = isset($_GET['debug']);
if ($DEBUG) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
}

/* ===== Utils ===== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function opWeekStartFromWeekInput(string $iso): ?DateTime {
  if (!preg_match('/^(\d{4})-W(\d{2})$/', $iso, $m)) return null;
  $dt = new DateTime();
  $dt->setISODate((int)$m[1], (int)$m[2]); // lunes ISO
  $dt->modify('+1 day');                    // martes operativo
  $dt->setTime(0,0,0);
  return $dt;
}

function currentOpWeekIso(): string {
  $t = new DateTime('today');
  $dow = (int)$t->format('N');
  $off = ($dow >= 2) ? $dow - 2 : 6 + $dow;
  $tue = (clone $t)->modify("-{$off} days");
  $mon = (clone $tue)->modify('-1 day');
  return $mon->format('o-\WW');
}

function fmtBadgeRango(DateTime $tueStart): string {
  $dias = ['Mar','Mié','Jue','Vie','Sáb','Dom','Lun'];
  $ini = (clone $tueStart);
  $fin = (clone $tueStart)->modify('+6 day');
  return $dias[0] . ' ' . $ini->format('d/m') . ' → ' . $dias[6] . ' ' . $fin->format('d/m');
}

function diaCortoEs(DateTime $d): string {
  static $map = [1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'];
  return $map[(int)$d->format('N')] ?? $d->format('D');
}

/* ================== Compatibilidad BD ================== */
function table_exists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $q = $conn->query("SHOW TABLES LIKE '{$t}'");
  return $q && $q->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $q = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $q && $q->num_rows > 0;
}

function pickDateCol(mysqli $conn, string $table, array $candidates=['fecha','creado_en','fecha_evento','dia','timestamp']): string {
  foreach ($candidates as $c) {
    if (column_exists($conn, $table, $c)) return $c;
  }
  return 'fecha';
}

function pickDateColWithAlias(mysqli $conn, string $table, string $alias, array $candidates=['fecha','creado_en','fecha_evento','dia','timestamp']): string {
  $raw = pickDateCol($conn, $table, $candidates);
  return "{$alias}.`{$raw}`";
}

function pickStatusCol(mysqli $conn, string $table, array $candidates=['status_admin','status','estatus','estado']): string {
  foreach ($candidates as $c) {
    if (column_exists($conn, $table, $c)) return $c;
  }
  return 'status';
}

/* ===== Helpers stmt SIN mysqlnd ===== */
function stmt_all_assoc(mysqli_stmt $stmt): array {
  $rows = [];
  $meta = $stmt->result_metadata();
  if (!$meta) return $rows;

  $fields = $meta->fetch_fields();
  $row = [];
  $bind = [];
  foreach ($fields as $f) {
    $row[$f->name] = null;
    $bind[] = &$row[$f->name];
  }

  call_user_func_array([$stmt, 'bind_result'], $bind);

  while ($stmt->fetch()) {
    $rows[] = array_combine(
      array_keys($row),
      array_map(fn($v) => $v, array_values($row))
    );
  }

  return $rows;
}

function stmt_one_assoc(mysqli_stmt $stmt): ?array {
  $rows = stmt_all_assoc($stmt);
  return $rows[0] ?? null;
}

/* ================== Filtros ================== */
$isExport = isset($_GET['export']);
$weekIso = $_GET['week'] ?? currentOpWeekIso();
$tuesdayStart = opWeekStartFromWeekInput($weekIso) ?: new DateTime('tuesday this week');
$start = $tuesdayStart->format('Y-m-d');
$end   = (clone $tuesdayStart)->modify('+6 day')->format('Y-m-d');
$today = (new DateTime('today'))->format('Y-m-d');

/* Filtro por día */
$diaSel = '';
if (!empty($_GET['dia']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['dia'])) {
  if ($_GET['dia'] >= $start && $_GET['dia'] <= $end) {
    $diaSel = $_GET['dia'];
  }
}

/* ===== Flash ===== */
$msgVac=''; $clsVac='info';
$msgInc=''; $clsInc='info';
$msgSol=''; $clsSol='info';

/* ===== Handler: Resolver solicitud de vacaciones ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'resolver_solicitud_vacaciones') {
  $solId    = (int)($_POST['sol_id'] ?? 0);
  $decision = strtoupper(trim((string)($_POST['decision'] ?? ''))); // APROBAR | RECHAZAR
  $obs      = trim((string)($_POST['comentario'] ?? ''));

  if (!table_exists($conn, 'vacaciones_solicitudes')) {
    $msgSol = "No existe la tabla vacaciones_solicitudes.";
    $clsSol = "danger";
  } elseif (!$solId || !in_array($decision, ['APROBAR','RECHAZAR'], true)) {
    $msgSol = "Solicitud o acción inválida.";
    $clsSol = "danger";
  } else {
    $stCol = column_exists($conn, 'vacaciones_solicitudes', 'status_admin')
      ? 'status_admin'
      : pickStatusCol($conn, 'vacaciones_solicitudes');

    $colBy = column_exists($conn, 'vacaciones_solicitudes', 'aprobado_admin_por')
      ? 'aprobado_admin_por'
      : (column_exists($conn, 'vacaciones_solicitudes', 'resuelto_por')
          ? 'resuelto_por'
          : (column_exists($conn, 'vacaciones_solicitudes', 'aprobado_por') ? 'aprobado_por' : null));

    $colEn = column_exists($conn, 'vacaciones_solicitudes', 'aprobado_admin_en')
      ? 'aprobado_admin_en'
      : (column_exists($conn, 'vacaciones_solicitudes', 'resuelto_en')
          ? 'resuelto_en'
          : (column_exists($conn, 'vacaciones_solicitudes', 'aprobado_en') ? 'aprobado_en' : null));

    $colObs = column_exists($conn, 'vacaciones_solicitudes', 'comentario_resolucion')
      ? 'comentario_resolucion'
      : (column_exists($conn, 'vacaciones_solicitudes', 'comentario_admin') ? 'comentario_admin' : null);

    $sqlGet = "SELECT * FROM vacaciones_solicitudes WHERE id=? AND `{$stCol}`='Pendiente' LIMIT 1";
    $st = $conn->prepare($sqlGet);
    $st->bind_param('i', $solId);
    $st->execute();
    $sol = stmt_one_assoc($st);
    $st->close();

    if (!$sol) {
      $msgSol = "La solicitud no existe o ya fue resuelta.";
      $clsSol = "warning";
    } else {
      $idUsuario  = (int)($sol['id_usuario'] ?? 0);
      $idSucursal = (int)($sol['id_sucursal'] ?? 0);
      $fini       = (string)($sol['fecha_inicio'] ?? '');
      $ffin       = (string)($sol['fecha_fin'] ?? '');
      $adminId    = (int)($_SESSION['id_usuario'] ?? 0);

      $doUpdateSolicitud = function(string $nuevoStatus) use ($conn, $stCol, $colBy, $colEn, $colObs, $adminId, $obs, $solId) {
        $sets = [];
        $types = '';
        $params = [];

        $sets[] = "`{$stCol}`=?";
        $types .= 's';
        $params[] = $nuevoStatus;

        if ($colBy) {
          $sets[] = "`{$colBy}`=?";
          $types .= 'i';
          $params[] = $adminId;
        }

        if ($colEn) {
          $sets[] = "`{$colEn}`=NOW()";
        }

        if ($colObs) {
          $sets[] = "`{$colObs}`=?";
          $types .= 's';
          $params[] = $obs;
        }

        $types .= 'i';
        $params[] = $solId;

        $sql = "UPDATE vacaciones_solicitudes 
                SET ".implode(', ', $sets)." 
                WHERE id=? AND `{$stCol}`='Pendiente' 
                LIMIT 1";

        $stU = $conn->prepare($sql);
        $stU->bind_param($types, ...$params);
        $stU->execute();
        $stU->close();
      };

      if ($decision === 'RECHAZAR') {
        try {
          $doUpdateSolicitud('Rechazado');
          $msgSol = "✅ Solicitud rechazada.";
          $clsSol = "success";
        } catch (Throwable $e) {
          $msgSol = "❌ Error al rechazar: " . $e->getMessage();
          $clsSol = "danger";
        }
      } else {
        if (!table_exists($conn, 'permisos_solicitudes')) {
          $msgSol = "No existe permisos_solicitudes para registrar días aprobados.";
          $clsSol = "danger";
        } else {
          $dateCol      = pickDateCol($conn, 'permisos_solicitudes', ['fecha','fecha_permiso','dia','fecha_solicitada','creado_en']);
          $hasAprobPor  = column_exists($conn, 'permisos_solicitudes', 'aprobado_por');
          $hasAprobEn   = column_exists($conn, 'permisos_solicitudes', 'aprobado_en');
          $hasComApr    = column_exists($conn, 'permisos_solicitudes', 'comentario_aprobador');
          $hasStatus    = column_exists($conn, 'permisos_solicitudes', 'status');
          $hasMotivo    = column_exists($conn, 'permisos_solicitudes', 'motivo');
          $hasComent    = column_exists($conn, 'permisos_solicitudes', 'comentario');
          $hasSucursal  = column_exists($conn, 'permisos_solicitudes', 'id_sucursal');
          $hasCreadoPor = column_exists($conn, 'permisos_solicitudes', 'creado_por');
          $hasCreadoEn  = column_exists($conn, 'permisos_solicitudes', 'creado_en');

          $cols = ['id_usuario'];
          if ($hasSucursal)  $cols[] = 'id_sucursal';
          $cols[] = $dateCol;
          if ($hasMotivo)    $cols[] = 'motivo';
          if ($hasComent)    $cols[] = 'comentario';
          if ($hasStatus)    $cols[] = 'status';
          if ($hasCreadoPor) $cols[] = 'creado_por';
          if ($hasCreadoEn)  $cols[] = 'creado_en';
          if ($hasAprobPor)  $cols[] = 'aprobado_por';
          if ($hasAprobEn)   $cols[] = 'aprobado_en';
          if ($hasComApr)    $cols[] = 'comentario_aprobador';

          $ph = [];
          foreach ($cols as $c) {
            $ph[] = ($c === 'creado_en' || $c === 'aprobado_en') ? 'NOW()' : '?';
          }

          $sqlIns = "INSERT INTO permisos_solicitudes (`".implode('`,`', $cols)."`) VALUES (".implode(',', $ph).")";
          $stmtIns = $conn->prepare($sqlIns);

          $dates = [];
          $cursor = new DateTime($fini);
          $endR = new DateTime($ffin);
          while ($cursor <= $endR) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor->modify('+1 day');
          }

          $conn->begin_transaction();
          try {
            $insertados = 0;
            $omitidos = 0;

            foreach ($dates as $d) {
              if ($hasMotivo) {
                $sqlDup = "SELECT id FROM permisos_solicitudes WHERE id_usuario=? AND DATE(`{$dateCol}`)=? AND motivo='Vacaciones' LIMIT 1";
              } else {
                $sqlDup = "SELECT id FROM permisos_solicitudes WHERE id_usuario=? AND DATE(`{$dateCol}`)=? LIMIT 1";
              }

              $stDup = $conn->prepare($sqlDup);
              $stDup->bind_param('is', $idUsuario, $d);
              $stDup->execute();
              $dup = (bool) stmt_one_assoc($stDup);
              $stDup->close();

              if ($dup) {
                $omitidos++;
                continue;
              }

              $vals = [];
              $types = '';

              foreach ($cols as $c) {
                if ($c === 'creado_en' || $c === 'aprobado_en') continue;

                switch ($c) {
                  case 'id_usuario':           $vals[] = $idUsuario; $types .= 'i'; break;
                  case 'id_sucursal':          $vals[] = $idSucursal; $types .= 'i'; break;
                  case 'motivo':               $vals[] = 'Vacaciones'; $types .= 's'; break;
                  case 'comentario':           $vals[] = ''; $types .= 's'; break;
                  case 'status':               $vals[] = 'Aprobado'; $types .= 's'; break;
                  case 'creado_por':           $vals[] = $adminId; $types .= 'i'; break;
                  case 'aprobado_por':         $vals[] = $adminId; $types .= 'i'; break;
                  case 'comentario_aprobador': $vals[] = 'Aprobado desde solicitud #'.$solId.($obs ? (' · '.$obs) : ''); $types .= 's'; break;
                  default:
                    if ($c === $dateCol) {
                      $vals[] = $d;
                      $types .= 's';
                    }
                }
              }

              $stmtIns->bind_param($types, ...$vals);
              $stmtIns->execute();
              $insertados++;
            }

            $doUpdateSolicitud('Aprobado');
            $conn->commit();

            $msgSol = "✅ Solicitud aprobada. Días registrados: {$insertados}. Omitidos (ya existían): {$omitidos}.";
            $clsSol = "success";
          } catch (Throwable $e) {
            $conn->rollback();
            $msgSol = "❌ Error al aprobar: " . $e->getMessage();
            $clsSol = "danger";
          }
        }
      }
    }
  }
}

/* ===== Handler: Alta de incidencia ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion'] ?? '')==='alta_incidencia') {
  $idUsuario = (int)($_POST['id_usuario'] ?? 0);
  $fechaInc  = trim((string)($_POST['fecha'] ?? ''));
  $tipoInc   = strtoupper(trim((string)($_POST['tipo'] ?? '')));
  $minutos   = (int)($_POST['minutos'] ?? 0);
  $comentInc = trim((string)($_POST['comentario'] ?? ''));

  $tiposValidos = ['FALTA','RETARDO','SALIDA_ANTICIPADA','OTRO','FALTA_JUSTIFICADA','INCAPACIDAD'];

  if (!table_exists($conn, 'incidencias_asistencia')) {
    $msgInc = "No existe la tabla incidencias_asistencia.";
    $clsInc = "danger";
  } elseif (!$idUsuario) {
    $msgInc = "Debe seleccionar un colaborador.";
    $clsInc = "danger";
  } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fechaInc)) {
    $msgInc = "Fecha de incidencia inválida.";
    $clsInc = "danger";
  } elseif (!in_array($tipoInc, $tiposValidos, true)) {
    $msgInc = "Tipo de incidencia inválido.";
    $clsInc = "danger";
  } else {
    $stU = $conn->prepare("
      SELECT u.id, u.id_sucursal
      FROM usuarios u
      JOIN sucursales s ON s.id=u.id_sucursal
      WHERE u.id=?
        AND u.rol IN ('Gerente','Ejecutivo')
        AND s.tipo_sucursal='tienda'
        AND s.subtipo='propia'
      LIMIT 1
    ");
    $stU->bind_param('i', $idUsuario);
    $stU->execute();
    $userRow = stmt_one_assoc($stU);
    $stU->close();

    if (!$userRow) {
      $msgInc = "Usuario inválido o no elegible.";
      $clsInc = "danger";
    } else {
      $dateCol     = pickDateCol($conn, 'incidencias_asistencia', ['fecha','dia','fecha_evento']);
      $hasMinutos  = column_exists($conn, 'incidencias_asistencia', 'minutos');
      $hasComent   = column_exists($conn, 'incidencias_asistencia', 'comentario');
      $hasSucursal = column_exists($conn, 'incidencias_asistencia', 'id_sucursal');
      $hasCreado   = column_exists($conn, 'incidencias_asistencia', 'creado_por');
      $hasCreadoEn = column_exists($conn, 'incidencias_asistencia', 'creado_en');

      $cols = ['id_usuario'];
      if ($hasSucursal) $cols[] = 'id_sucursal';
      $cols[] = $dateCol;
      $cols[] = 'tipo';
      if ($hasMinutos)  $cols[] = 'minutos';
      if ($hasComent)   $cols[] = 'comentario';
      if ($hasCreado)   $cols[] = 'creado_por';
      if ($hasCreadoEn) $cols[] = 'creado_en';

      $placeholders = [];
      foreach ($cols as $c) {
        $placeholders[] = ($c === 'creado_en') ? 'CURRENT_TIMESTAMP' : '?';
      }

      $sqlIns = "INSERT INTO incidencias_asistencia (`".implode('`,`', $cols)."`) VALUES (".implode(',', $placeholders).")";
      $stmt = $conn->prepare($sqlIns);

      $vals = [];
      $types = '';
      foreach ($cols as $c) {
        if ($c === 'creado_en') continue;

        switch ($c) {
          case 'id_usuario':  $vals[] = $idUsuario; $types .= 'i'; break;
          case 'id_sucursal': $vals[] = (int)$userRow['id_sucursal']; $types .= 'i'; break;
          case 'tipo':        $vals[] = $tipoInc; $types .= 's'; break;
          case 'minutos':     $vals[] = $minutos; $types .= 'i'; break;
          case 'comentario':  $vals[] = $comentInc; $types .= 's'; break;
          case 'creado_por':  $vals[] = (int)$_SESSION['id_usuario']; $types .= 'i'; break;
          default:
            if ($c === $dateCol) {
              $vals[] = $fechaInc;
              $types .= 's';
            }
        }
      }

      $stmt->bind_param($types, ...$vals);
      try {
        $stmt->execute();
        $msgInc = "✅ Incidencia registrada correctamente.";
        $clsInc = "success";
      } catch (Throwable $e) {
        $msgInc = "❌ Error al registrar incidencia: " . $e->getMessage();
        $clsInc = "danger";
      }
    }
  }
}

/* ===== Sucursales ===== */
$sucursales = [];
$resSuc = $conn->query("SELECT id,nombre FROM sucursales WHERE tipo_sucursal='tienda' AND subtipo='propia' ORDER BY nombre");
if ($resSuc) {
  while ($r = $resSuc->fetch_assoc()) $sucursales[] = $r;
}

$sucursal_id = isset($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : 0;

$qsExportArr = ['week'=>$weekIso,'sucursal_id'=>$sucursal_id];
if ($diaSel) $qsExportArr['dia'] = $diaSel;
$qsExport = http_build_query($qsExportArr);

/* ===== Opciones semanas ===== */
$weekOptions = [];
$todayIso = currentOpWeekIso();
$todayTue = opWeekStartFromWeekInput($todayIso) ?: new DateTime('tuesday this week');

for ($i = 0; $i < 10; $i++) {
  $tue = (clone $todayTue)->modify('-'.(7*$i).' days');
  $monForIso = (clone $tue)->modify('-1 day');
  $iso = $monForIso->format('o-\WW');

  $iniLabel = 'Mar ' . $tue->format('d/m');
  $fin = (clone $tue)->modify('+6 day');
  $finLabel = 'Lun ' . $fin->format('d/m');

  $prefix = ($i === 0) ? 'Semana actual · ' : '';
  $weekOptions[] = [
    'iso'   => $iso,
    'label' => $prefix . $iniLabel . ' → ' . $finLabel
  ];
}

$foundSelected = false;
foreach ($weekOptions as $opt) {
  if ($opt['iso'] === $weekIso) { $foundSelected = true; break; }
}
if (!$foundSelected) {
  $iniLabel = 'Mar '.$tuesdayStart->format('d/m');
  $finTmp   = (clone $tuesdayStart)->modify('+6 day');
  $finLabel = 'Lun '.$finTmp->format('d/m');
  array_unshift($weekOptions, [
    'iso'   => $weekIso,
    'label' => $iniLabel . ' → ' . $finLabel . ' (seleccionada)'
  ]);
}

/* ===== Usuarios activos + bajas en semana ===== */
$paramsU = [];
$typesU  = '';
$useUsuariosLog = table_exists($conn, 'usuarios_log');

if ($useUsuariosLog) {
  $whereU = "
    WHERE
      s.tipo_sucursal = 'tienda'
      AND s.subtipo   = 'propia'
      AND u.rol IN ('Gerente','Ejecutivo')
      AND (
        u.activo = 1
        OR (
          lg.ultima_baja IS NOT NULL
          AND (lg.ultima_reactivar IS NULL OR lg.ultima_baja > lg.ultima_reactivar)
          AND DATE(lg.ultima_baja) BETWEEN ? AND ?
        )
      )
  ";
  $typesU = 'ss';
  $paramsU = [$start, $end];

  if ($sucursal_id > 0) {
    $whereU .= ' AND u.id_sucursal = ? ';
    $typesU .= 'i';
    $paramsU[] = $sucursal_id;
  }

  $sqlUsers = "
    SELECT 
      u.id,
      u.nombre,
      u.id_sucursal,
      s.nombre AS sucursal,
      u.activo,
      CASE 
        WHEN lg.ultima_baja IS NOT NULL
             AND (lg.ultima_reactivar IS NULL OR lg.ultima_baja > lg.ultima_reactivar)
        THEN DATE(lg.ultima_baja)
        ELSE NULL
      END AS fecha_baja
    FROM usuarios u
    JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN (
      SELECT
        target_id,
        MAX(CASE WHEN accion='baja' THEN created_at END) AS ultima_baja,
        MAX(CASE WHEN accion='reactivar' THEN created_at END) AS ultima_reactivar
      FROM usuarios_log
      GROUP BY target_id
    ) lg ON lg.target_id = u.id
    $whereU
    ORDER BY s.nombre, u.nombre
  ";
} else {
  $whereU = "
    WHERE u.activo=1
      AND s.tipo_sucursal='tienda'
      AND s.subtipo='propia'
      AND u.rol IN ('Gerente','Ejecutivo')
  ";
  if ($sucursal_id > 0) {
    $whereU .= ' AND u.id_sucursal=? ';
    $typesU .= 'i';
    $paramsU[] = $sucursal_id;
  }

  $sqlUsers = "
    SELECT
      u.id,
      u.nombre,
      u.id_sucursal,
      s.nombre AS sucursal,
      u.activo,
      NULL AS fecha_baja
    FROM usuarios u
    JOIN sucursales s ON s.id=u.id_sucursal
    $whereU
    ORDER BY s.nombre, u.nombre
  ";
}

$stmt = $conn->prepare($sqlUsers);
if ($typesU) $stmt->bind_param($typesU, ...$paramsU);
$stmt->execute();
$usuarios = stmt_all_assoc($stmt);
$stmt->close();

$userIds = array_map(fn($u)=>(int)$u['id'], $usuarios);
if (!$userIds) $userIds = [0];

/* ===== Horarios ===== */
$horarios = [];
$horTable = table_exists($conn,'sucursales_horario')
  ? 'sucursales_horario'
  : (table_exists($conn,'horarios_sucursal') ? 'horarios_sucursal' : null);

if ($horTable === 'sucursales_horario') {
  $resH = $conn->query("SELECT id_sucursal,dia_semana,abre,cierra,cerrado FROM sucursales_horario");
  if ($resH) {
    while ($r = $resH->fetch_assoc()) {
      $horarios[(int)$r['id_sucursal']][(int)$r['dia_semana']] = [
        'abre'    => $r['abre'],
        'cierra'  => $r['cierra'],
        'cerrado' => (int)$r['cerrado']
      ];
    }
  }
} elseif ($horTable === 'horarios_sucursal') {
  $resH = $conn->query("SELECT id_sucursal,dia_semana,apertura AS abre,cierre AS cierra,IF(activo=1,0,1) AS cerrado FROM horarios_sucursal");
  if ($resH) {
    while ($r = $resH->fetch_assoc()) {
      $horarios[(int)$r['id_sucursal']][(int)$r['dia_semana']] = [
        'abre'    => $r['abre'],
        'cierra'  => $r['cierra'],
        'cerrado' => (int)$r['cerrado']
      ];
    }
  }
}

/* ===== Descansos ===== */
$descansos = [];
if (table_exists($conn, 'descansos_programados')) {
  $inList = implode(',', array_fill(0, count($userIds), '?'));
  $typesD = str_repeat('i', count($userIds)).'ss';
  $descansoDateCol = pickDateCol($conn, 'descansos_programados', ['fecha','dia','fecha_programada']);

  $sqlD = "SELECT id_usuario, `{$descansoDateCol}` AS fecha
           FROM descansos_programados
           WHERE id_usuario IN ($inList)
             AND `{$descansoDateCol}` BETWEEN ? AND ?";

  $stmt = $conn->prepare($sqlD);
  $stmt->bind_param($typesD, ...array_merge($userIds, [$start, $end]));
  $stmt->execute();
  $rows = stmt_all_assoc($stmt);
  $stmt->close();

  foreach ($rows as $r) {
    $descansos[(int)$r['id_usuario']][$r['fecha']] = true;
  }
}

/* ===== Permisos aprobados ===== */
$permAprob = [];
if (table_exists($conn, 'permisos_solicitudes')) {
  $permDateCol = pickDateCol($conn, 'permisos_solicitudes', ['fecha','dia','fecha_solicitada','fecha_permiso','creado_en']);
  $hasMotivo = column_exists($conn, 'permisos_solicitudes', 'motivo');

  $inList = implode(',', array_fill(0, count($userIds), '?'));
  $typesPA = str_repeat('i', count($userIds)).'ss';
  $selMot = $hasMotivo ? ", p.motivo AS motivo" : "";

  $sqlPA = "
    SELECT p.id_usuario, `{$permDateCol}` AS fecha {$selMot}
    FROM permisos_solicitudes p
    WHERE p.id_usuario IN ($inList)
      AND p.status='Aprobado'
      AND `{$permDateCol}` BETWEEN ? AND ?
  ";

  $stmt = $conn->prepare($sqlPA);
  $stmt->bind_param($typesPA, ...array_merge($userIds, [$start, $end]));
  $stmt->execute();
  $rows = stmt_all_assoc($stmt);
  $stmt->close();

  foreach ($rows as $r) {
    $permAprob[(int)$r['id_usuario']][$r['fecha']] = $hasMotivo
      ? (string)($r['motivo'] ?? '')
      : 'PERMISO';
  }
}

/* ===== Incidencias semana ===== */
$incidencias = [];
if (table_exists($conn, 'incidencias_asistencia') && $userIds !== [0]) {
  $inList = implode(',', array_fill(0, count($userIds), '?'));
  $typesInc = str_repeat('i', count($userIds)).'ss';

  $sqlInc = "
    SELECT id_usuario, fecha, tipo, minutos, comentario
    FROM incidencias_asistencia
    WHERE id_usuario IN ($inList)
      AND fecha BETWEEN ? AND ?
  ";

  $stmt = $conn->prepare($sqlInc);
  $stmt->bind_param($typesInc, ...array_merge($userIds, [$start, $end]));
  $stmt->execute();
  $rows = stmt_all_assoc($stmt);
  $stmt->close();

  foreach ($rows as $r) {
    $uid = (int)$r['id_usuario'];
    $f   = $r['fecha'];
    $incidencias[$uid][$f] = $r;
  }
}

/* ===== Asistencias detalle ===== */
$asistDetWeek = [];
$typesA = str_repeat('i', count($userIds)).'ss';
$asisDateRaw = pickDateColWithAlias($conn, 'asistencias', 'a', ['fecha','creado_en','fecha_evento','dia','timestamp']);

$sqlA = "
  SELECT a.*, {$asisDateRaw} AS fecha, s.nombre AS sucursal, u.nombre AS usuario
  FROM asistencias a
  JOIN sucursales s ON s.id=a.id_sucursal
  JOIN usuarios u   ON u.id=a.id_usuario
  WHERE a.id_usuario IN (%s)
    AND DATE({$asisDateRaw}) BETWEEN ? AND ?
  ORDER BY {$asisDateRaw} ASC, a.hora_entrada ASC, a.id ASC
";

$inList = implode(',', array_fill(0, count($userIds), '?'));
$sqlA = sprintf($sqlA, $inList);

$stmt = $conn->prepare($sqlA);
$stmt->bind_param($typesA, ...array_merge($userIds, [$start, $end]));
$stmt->execute();
$asistDetWeek = stmt_all_assoc($stmt);
$stmt->close();

$asistDetView = $asistDetWeek;
if ($diaSel) {
  $asistDetView = array_values(array_filter($asistDetWeek, fn($a) => ($a['fecha'] ?? '') === $diaSel));
}

/* ===== Index asistencias por usuario/día ===== */
$asistByUserDay = [];
foreach ($asistDetWeek as $a) {
  $uid = (int)$a['id_usuario'];
  $f   = $a['fecha'];
  if (!isset($asistByUserDay[$uid][$f])) {
    $asistByUserDay[$uid][$f] = $a;
  }
}

/* ===== Permisos de la semana ===== */
$permisosSemana = [];
if (table_exists($conn, 'permisos_solicitudes')) {
  $permDateRaw = pickDateColWithAlias($conn, 'permisos_solicitudes', 'p', ['fecha','dia','fecha_solicitada','fecha_permiso','creado_en']);
  $typesPS = 'ss';
  $paramsPS = [$start, $end];
  $wherePS = " AND s.tipo_sucursal='tienda' AND s.subtipo='propia' ";
  $hasAprobPor = column_exists($conn, 'permisos_solicitudes', 'aprobado_por');

  if ($sucursal_id > 0) {
    $typesPS .= 'i';
    $paramsPS[] = $sucursal_id;
    $wherePS .= ' AND s.id=? ';
  }

  $selAprob = $hasAprobPor ? 'p.aprobado_por' : 'NULL AS aprobado_por';

  $sqlPS = "
    SELECT p.*, {$permDateRaw} AS fecha, u.nombre AS usuario, s.nombre AS sucursal, {$selAprob}
    FROM permisos_solicitudes p
    JOIN usuarios u ON u.id=p.id_usuario
    JOIN sucursales s ON s.id=p.id_sucursal
    WHERE DATE({$permDateRaw}) BETWEEN ? AND ? $wherePS
    ORDER BY s.nombre, u.nombre, {$permDateRaw} DESC
  ";

  $stmt = $conn->prepare($sqlPS);
  $stmt->bind_param($typesPS, ...$paramsPS);
  $stmt->execute();
  $permisosSemana = stmt_all_assoc($stmt);
  $stmt->close();
}

/* ===== Solicitudes vacaciones pendientes ===== */
$solVacPend = [];
if (table_exists($conn, 'vacaciones_solicitudes')) {
  $typesSV = '';
  $paramsSV = [];
  $stColSV = pickStatusCol($conn, 'vacaciones_solicitudes');
  $createdColSV = pickDateCol($conn, 'vacaciones_solicitudes', ['creado_en','created_at','fecha','fecha_solicitud']);
  $whereSV = " WHERE vs.`{$stColSV}`='Pendiente' ";

  if ($sucursal_id > 0) {
    $whereSV .= " AND vs.id_sucursal=? ";
    $typesSV .= 'i';
    $paramsSV[] = $sucursal_id;
  }

  $sqlSV = "
    SELECT vs.*, vs.`{$createdColSV}` AS creado_en, u.nombre AS usuario, s.nombre AS sucursal
    FROM vacaciones_solicitudes vs
    JOIN usuarios u ON u.id = vs.id_usuario
    JOIN sucursales s ON s.id = vs.id_sucursal
    $whereSV
    ORDER BY vs.`{$createdColSV}` ASC, s.nombre, u.nombre
  ";

  $st = $conn->prepare($sqlSV);
  if ($typesSV) $st->bind_param($typesSV, ...$paramsSV);
  $st->execute();
  $solVacPend = stmt_all_assoc($st);
  $st->close();
}


/* ===== Historial de vacaciones aprobadas en la semana vista ===== */
$histVacAprob = [];
if (table_exists($conn, 'permisos_solicitudes')) {
  $permDateHist = pickDateCol($conn, 'permisos_solicitudes', ['fecha','dia','fecha_solicitada','fecha_permiso','creado_en']);
  $hasMotivoHist = column_exists($conn, 'permisos_solicitudes', 'motivo');
  $typesHV = 'ss';
  $paramsHV = [$start, $end];
  $whereHV = " WHERE DATE(p.`{$permDateHist}`) BETWEEN ? AND ? AND p.status='Aprobado' ";

  if ($hasMotivoHist) {
    $whereHV .= " AND UPPER(TRIM(COALESCE(p.motivo,''))) LIKE 'VACACION%' ";
  }

  if ($sucursal_id > 0) {
    $whereHV .= " AND p.id_sucursal=? ";
    $typesHV .= 'i';
    $paramsHV[] = $sucursal_id;
  }

  $sqlHV = "
    SELECT 
      p.id,
      p.id_usuario,
      p.id_sucursal,
      DATE(p.`{$permDateHist}`) AS fecha,
      COALESCE(p.motivo,'Vacaciones') AS motivo,
      COALESCE(p.comentario,'') AS comentario,
      COALESCE(p.comentario_aprobador,'') AS comentario_aprobador,
      COALESCE(p.aprobado_en,'') AS aprobado_en,
      u.nombre AS usuario,
      s.nombre AS sucursal
    FROM permisos_solicitudes p
    JOIN usuarios u ON u.id = p.id_usuario
    JOIN sucursales s ON s.id = p.id_sucursal
    {$whereHV}
    ORDER BY fecha DESC, s.nombre, u.nombre
  ";

  $st = $conn->prepare($sqlHV);
  if ($typesHV) $st->bind_param($typesHV, ...$paramsHV);
  $st->execute();
  $histVacAprob = stmt_all_assoc($st);
  $st->close();
}

/* ===== Construcción matriz + KPIs ===== */
$days = [];
for ($i = 0; $i < 7; $i++) {
  $d = clone $tuesdayStart;
  $d->modify("+$i day");
  $days[] = $d;
}
$weekNames = ['Mar','Mié','Jue','Vie','Sáb','Dom','Lun'];

$matriz = [];
$totAsis=0; $totRet=0; $totFal=0; $totPerm=0; $totDesc=0; $totMin=0; $faltasPorRetardos=0; $laborables=0; $presentes=0;

foreach ($usuarios as $u) {
  $uid = (int)$u['id'];
  $sid = (int)$u['id_sucursal'];
  $fechaBaja = $u['fecha_baja'] ?? null;

  $fila = [
    'usuario'  => $u['nombre'],
    'sucursal' => $u['sucursal'],
    'dias'     => [],
    'asis'     => 0,
    'ret'      => 0,
    'fal'      => 0,
    'perm'     => 0,
    'desc'     => 0,
    'min'      => 0
  ];

  $retSemanaUsuario = 0;

  foreach ($days as $d) {
    $f = $d->format('Y-m-d');
    $isFuture = ($f > $today);
    $dow = (int)$d->format('N');

    $hor = $horarios[$sid][$dow] ?? null;
    $cerrado = $hor ? ((int)$hor['cerrado'] === 1) : false;

    $isDesc = !empty($descansos[$uid][$f]);
    $isPerm = isset($permAprob[$uid][$f]);
    $hasInc = isset($incidencias[$uid][$f]);
    $a = $asistByUserDay[$uid][$f] ?? null;

    $esLaborable = !$cerrado && !$isDesc && !$isPerm && !$hasInc;

    // BAJA manda por encima de todo
    if ($fechaBaja !== null && $f >= $fechaBaja) {
      $fila['dias'][] = [
        'fecha'       => $f,
        'estado'      => 'BAJA',
        'entrada'     => null,
        'salida'      => null,
        'retardo_min' => 0,
        'dur'         => 0
      ];
      continue;
    }

    // Asistencia real manda por encima de programación
    if ($a) {
      $ret    = (int)($a['retardo'] ?? 0);
      $retMin = (int)($a['retardo_minutos'] ?? 0);
      $dur    = (int)($a['duracion_minutos'] ?? 0);

      $fila['min'] += $dur;

      if ($ret === 1) {
        $estado = 'RETARDO';
        $fila['ret']++;
        $retSemanaUsuario++;
      } else {
        $estado = 'ASISTIÓ';
        $fila['asis']++;
      }

      if (!$isFuture) {
        $totMin += $dur;

        if ($ret === 1) $totRet++;
        else $totAsis++;

        $presentes++;
        if ($esLaborable) $laborables++;
      }

      $fila['dias'][] = [
        'fecha'       => $f,
        'estado'      => $estado,
        'entrada'     => $a['hora_entrada'],
        'salida'      => $a['hora_salida'],
        'retardo_min' => $retMin,
        'dur'         => $dur
      ];
      continue;
    }

    // Incidencia visible desde el inicio si ya está capturada
    if ($hasInc) {
      $estado = 'PERMISO';
      $fila['perm']++;

      if (!$isFuture) {
        $totPerm++;
      }

      $fila['dias'][] = [
        'fecha'       => $f,
        'estado'      => $estado,
        'entrada'     => null,
        'salida'      => null,
        'retardo_min' => 0,
        'dur'         => 0
      ];
      continue;
    }

    // Descanso programado visible desde inicio de semana
    if ($isDesc) {
      $estado = 'DESCANSO';
      $fila['desc']++;

      if (!$isFuture) {
        $totDesc++;
      }

      $fila['dias'][] = [
        'fecha'       => $f,
        'estado'      => $estado,
        'entrada'     => null,
        'salida'      => null,
        'retardo_min' => 0,
        'dur'         => 0
      ];
      continue;
    }

    // Sucursal cerrada
    if ($cerrado) {
      $estado = 'CERRADA';
      $fila['dias'][] = [
        'fecha'       => $f,
        'estado'      => $estado,
        'entrada'     => null,
        'salida'      => null,
        'retardo_min' => 0,
        'dur'         => 0
      ];
      continue;
    }

    // Permiso / vacaciones aprobadas visibles desde inicio de semana
    if ($isPerm) {
      $mot = strtoupper(trim((string)($permAprob[$uid][$f] ?? '')));
      $estado = (strpos($mot, 'VACACION') === 0) ? 'VACACIONES' : 'PERMISO';

      $fila['perm']++;

      if (!$isFuture) {
        $totPerm++;
      }

      $fila['dias'][] = [
        'fecha'       => $f,
        'estado'      => $estado,
        'entrada'     => null,
        'salida'      => null,
        'retardo_min' => 0,
        'dur'         => 0
      ];
      continue;
    }

    // Futuro sin programación
    if ($isFuture) {
      $fila['dias'][] = [
        'fecha'       => $f,
        'estado'      => 'PENDIENTE',
        'entrada'     => null,
        'salida'      => null,
        'retardo_min' => 0,
        'dur'         => 0
      ];
      continue;
    }

    // Día transcurrido sin nada = falta
    $estado = 'FALTA';
    $fila['fal']++;
    $totFal++;
    if ($esLaborable) $laborables++;

    $fila['dias'][] = [
      'fecha'       => $f,
      'estado'      => $estado,
      'entrada'     => null,
      'salida'      => null,
      'retardo_min' => 0,
      'dur'         => 0
    ];
  }

  if ($retSemanaUsuario >= 3) {
    $faltasPorRetardos++;
  }

  $matriz[] = $fila;
}

/* ===== KPIs ===== */
$empleadosActivos = ($userIds === [0]) ? 0 : count($usuarios);
$puntualidad = ($totAsis + $totRet) > 0 ? round(($totAsis / ($totAsis + $totRet)) * 100, 1) : 0.0;
$cumplimiento = ($laborables > 0) ? round(($presentes / $laborables) * 100, 1) : 0.0;
$horasTot = $totMin > 0 ? round($totMin / 60, 2) : 0.0;

/* ===== EXPORTACIONES ===== */
if ($isExport) {
  ini_set('display_errors', '0');
  while (ob_get_level()) { ob_end_clean(); }

  header("Content-Type: text/csv; charset=UTF-8");
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  echo "\xEF\xBB\xBF";

  $type = $_GET['export'];
  $labels = [];
  foreach ($days as $d) $labels[] = diaCortoEs($d).' '.$d->format('d/m');

  if ($type === 'matrix') {
    header("Content-Disposition: attachment; filename=asistencias_matriz_{$weekIso}.csv");
    $out = fopen('php://output', 'w');

    $head = ['Sucursal','Colaborador'];
    foreach ($labels as $l) $head[] = $l;
    $head = array_merge($head, ['Asistencias','Retardos','Faltas','Permisos','Descansos','Minutos','Horas','Falta_por_retardos']);
    fputcsv($out, $head);

    foreach ($matriz as $fila) {
      $row = [$fila['sucursal'], $fila['usuario']];
      foreach ($fila['dias'] as $dCell) {
        $estado = $dCell['estado'];
        if ($estado === 'PENDIENTE') $row[] = '—';
        elseif ($estado === 'RETARDO') $row[] = 'RETARDO +'.($dCell['retardo_min'] ?? 0).'m';
        else $row[] = $estado;
      }

      $hrs = $fila['min'] > 0 ? round($fila['min']/60, 2) : 0;
      $faltaRet = ($fila['ret'] >= 3) ? 1 : 0;

      fputcsv($out, array_merge($row, [
        $fila['asis'], $fila['ret'], $fila['fal'], $fila['perm'],
        $fila['desc'], $fila['min'], $hrs, $faltaRet
      ]));
    }

    fclose($out);
    exit;
  }

  if ($type === 'detalles') {
    header("Content-Disposition: attachment; filename=asistencias_detalle_{$weekIso}.csv");
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Sucursal','Usuario','Fecha','Entrada','Salida','Duración(min)','Estado','Retardo(min)','Lat','Lng','IP']);

    foreach ($asistDetWeek as $a) {
      $estado = ((int)($a['retardo'] ?? 0) === 1) ? 'RETARDO' : 'OK';
      fputcsv($out, [
        $a['sucursal'],
        $a['usuario'],
        $a['fecha'],
        $a['hora_entrada'],
        $a['hora_salida'],
        (int)($a['duracion_minutos'] ?? 0),
        $estado,
        (int)($a['retardo_minutos'] ?? 0),
        $a['latitud'] ?? '',
        $a['longitud'] ?? '',
        $a['ip'] ?? ''
      ]);
    }

    fclose($out);
    exit;
  }

  if ($type === 'permisos' && $permisosSemana) {
    header("Content-Disposition: attachment; filename=permisos_semana_{$weekIso}.csv");
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Sucursal','Colaborador','Fecha','Motivo','Comentario','Status','Aprobado por','Aprobado en','Obs.aprobador']);

    foreach ($permisosSemana as $p) {
      fputcsv($out, [
        $p['sucursal'],
        $p['usuario'],
        $p['fecha'],
        $p['motivo'] ?? '',
        $p['comentario'] ?? '',
        $p['status'] ?? '',
        $p['aprobado_por'] ?? '',
        $p['aprobado_en'] ?? '',
        $p['comentario_aprobador'] ?? ''
      ]);
    }

    fclose($out);
    exit;
  }

  header("Content-Disposition: attachment; filename=export_{$weekIso}.csv");
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Sin datos']);
  fclose($out);
  exit;
}

/* ===== UI ===== */
require_once __DIR__ . '/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Admin · Asistencias (Mar→Lun)</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  
  <style>
    :root{
      --bg-app:#f4f8fc;
      --card-bg:#ffffff;
      --ink:#0f172a;
      --muted:#64748b;
      --line:rgba(148,163,184,.18);
      --shadow-lg:0 18px 40px rgba(15,23,42,.08), 0 4px 12px rgba(15,23,42,.05);
      --shadow-md:0 10px 24px rgba(15,23,42,.06), 0 2px 6px rgba(15,23,42,.05);
    }

    body{
      background:
        radial-gradient(1100px 400px at 0% 0%, rgba(13,110,253,.06), transparent 55%),
        radial-gradient(900px 320px at 100% 0%, rgba(25,135,84,.05), transparent 50%),
        var(--bg-app);
      color:var(--ink);
    }

    .page-shell{ max-width: 1600px; }

    .hero-admin{
      background:
        radial-gradient(800px 260px at 0% 0%, rgba(13,110,253,.10), transparent 58%),
        radial-gradient(800px 260px at 100% 0%, rgba(111,66,193,.08), transparent 58%),
        linear-gradient(135deg, #ffffff, #f8fbff);
      border:1px solid rgba(255,255,255,.7);
      border-radius:1.4rem;
      box-shadow:var(--shadow-lg);
      padding:1.3rem 1.35rem;
      position:relative;
      overflow:hidden;
    }

    .hero-admin::after{
      content:"";
      position:absolute;
      inset:auto -60px -80px auto;
      width:220px;
      height:220px;
      border-radius:50%;
      background:radial-gradient(circle, rgba(13,110,253,.10), rgba(13,110,253,0));
      pointer-events:none;
    }

    .hero-chip{
      display:inline-flex;
      align-items:center;
      gap:.55rem;
      padding:.52rem .85rem;
      border-radius:999px;
      background:#fff;
      border:1px solid rgba(13,110,253,.12);
      box-shadow:0 8px 18px rgba(15,23,42,.06);
      font-weight:700;
      color:#0f172a;
    }

    .hero-meta{
      display:flex;
      flex-wrap:wrap;
      gap:.75rem;
      justify-content:flex-end;
    }

    .hero-meta .meta-box{
      min-width:170px;
      padding:.9rem 1rem;
      border-radius:1rem;
      background:rgba(255,255,255,.78);
      border:1px solid rgba(148,163,184,.16);
      box-shadow:0 10px 22px rgba(15,23,42,.05);
    }

    .hero-meta .meta-box .label{
      font-size:.76rem;
      text-transform:uppercase;
      letter-spacing:.45px;
      color:var(--muted);
      font-weight:800;
      margin-bottom:.18rem;
    }

    .hero-meta .meta-box .value{
      font-weight:800;
      font-size:1rem;
      color:var(--ink);
    }

    .section-card,
    .card-elev{
      border:1px solid rgba(255,255,255,.72);
      border-radius:1.15rem;
      box-shadow:var(--shadow-md);
      background:var(--card-bg);
    }

    .card-elev .card-header{
      background:linear-gradient(180deg, rgba(248,250,252,.96), rgba(255,255,255,.96));
      border-bottom:1px solid var(--line);
      border-top-left-radius:1.15rem !important;
      border-top-right-radius:1.15rem !important;
      padding:.95rem 1.1rem;
    }

    .card-elev .card-body{ padding:1.05rem 1.1rem; }

    .toolbar-soft{
      display:flex;
      flex-wrap:wrap;
      gap:.55rem;
      padding:.85rem 1rem;
      border-radius:1rem;
      background:rgba(255,255,255,.75);
      border:1px dashed rgba(148,163,184,.28);
      box-shadow:0 10px 22px rgba(15,23,42,.04);
    }

    .filter-label{
      font-size:.82rem;
      font-weight:700;
      color:#334155;
      margin-bottom:.35rem !important;
    }

    .form-select, .form-control{
      border-radius:.9rem;
      border-color:rgba(148,163,184,.28);
      min-height:46px;
      box-shadow:none !important;
    }

    .form-select:focus, .form-control:focus{
      border-color:rgba(13,110,253,.45);
      box-shadow:0 0 0 .24rem rgba(13,110,253,.10) !important;
    }

    .table-responsive{
      border-radius:1rem;
    }

    .table-xs td, .table-xs th{ padding:.52rem .62rem; font-size:.92rem; }
    .table thead th{
      white-space:nowrap;
      border-bottom-width:0;
    }
    .table tbody tr:hover{
      background:rgba(13,110,253,.03);
    }

    .pill{ display:inline-block; border-radius:999px; font-weight:700; line-height:1; }
    .pill-compact{ padding:.16rem .42rem; font-size:.72rem; min-width:1.42rem; text-align:center; border:1px solid transparent; box-shadow: inset 0 -1px 0 rgba(255,255,255,.35); }
    .pill-A{ background:#e6fcf5; color:#0f5132; border-color:#c3fae8; }
    .pill-R{ background:#fff3cd; color:#8a6d3b; border-color:#ffeeba; }
    .pill-F{ background:#ffe3e3; color:#842029; border-color:#f5c2c7; }
    .pill-P{ background:#e2f0d9; color:#2b6a2b; border-color:#c7e3be; }
    .pill-V{ background:#fff0f6; color:#9c36b5; border-color:#f3d9fa; }
    .pill-D{ background:#f3f4f6; color:#374151; border-color:#e5e7eb; }
    .pill-X{ background:#ede9fe; color:#5b21b6; border-color:#ddd6fe; }
    .pill-PN{ background:#eef2ff; color:#3730a3; border-color:#c7d2fe; }
    .pill-B{ background:#fee2e2; color:#991b1b; border-color:#fecaca; }

    .thead-sticky th{
      position:sticky;
      top:0;
      background:linear-gradient(180deg, #101827, #111827);
      color:#fff;
      z-index:2;
      box-shadow:0 1px 0 rgba(255,255,255,.06) inset;
    }

    .kpi{
      border:1px solid rgba(255,255,255,.65);
      border-radius:1.15rem;
      padding:1rem 1.1rem;
      display:flex;
      gap:.9rem;
      align-items:center;
      min-height:100%;
      box-shadow:0 14px 30px rgba(15,23,42,.06);
      position:relative;
      overflow:hidden;
    }
    .kpi::after{
      content:"";
      position:absolute;
      right:-18px;
      top:-18px;
      width:72px;
      height:72px;
      border-radius:50%;
      background:rgba(255,255,255,.35);
    }
    .kpi i{ font-size:1.35rem; opacity:.95; }
    .kpi .big{ font-weight:800; font-size:1.4rem; line-height:1; }
    .kpi .text-muted.small{ font-size:.78rem !important; text-transform:uppercase; letter-spacing:.38px; font-weight:800; }
    .bg-soft-blue{ background:linear-gradient(135deg, #e7f5ff, #f7fbff); }
    .bg-soft-green{ background:linear-gradient(135deg, #e6fcf5, #f7fffb); }
    .bg-soft-yellow{ background:linear-gradient(135deg, #fff9db, #fffdf1); }
    .bg-soft-red{ background:linear-gradient(135deg, #ffe3e3, #fff5f5); }
    .bg-soft-purple{ background:linear-gradient(135deg, #f3f0ff, #faf7ff); }
    .bg-soft-slate{ background:linear-gradient(135deg, #f1f5f9, #fbfdff); }

    .nav-tabs{
      gap:.35rem;
      padding:.8rem .9rem 0;
      border-bottom:1px solid var(--line);
      background:linear-gradient(180deg, rgba(248,250,252,.96), rgba(255,255,255,.96));
      border-top-left-radius:1.15rem;
      border-top-right-radius:1.15rem;
    }

    .nav-tabs .nav-link{
      border:none;
      border-radius:.9rem .9rem 0 0;
      color:#475569;
      font-weight:700;
      padding:.82rem 1rem;
    }

    .nav-tabs .nav-link.active{
      background:#fff;
      color:#0f172a;
      box-shadow:0 -1px 0 #fff inset, 0 8px 18px rgba(15,23,42,.05);
    }

    .badge-outline-soft{
      border:1px solid rgba(148,163,184,.24);
      background:#fff;
      color:#475569;
      padding:.45rem .7rem;
      border-radius:999px;
      font-weight:700;
    }

    .table-dark{
      --bs-table-bg:#111827;
      --bs-table-striped-bg:#172132;
      --bs-table-striped-color:#fff;
      --bs-table-hover-bg:#1e293b;
      --bs-table-hover-color:#fff;
      color:#fff;
      border-color:rgba(255,255,255,.06);
    }

    .modal-content{
      border:none;
      border-radius:1.15rem;
      box-shadow:0 24px 60px rgba(15,23,42,.16);
    }

    .modal-header{
      background:linear-gradient(180deg, rgba(248,250,252,.96), rgba(255,255,255,.96));
      border-bottom:1px solid var(--line);
      border-top-left-radius:1.15rem;
      border-top-right-radius:1.15rem;
    }

    .alert{
      border:none;
      border-radius:1rem;
      box-shadow:0 10px 22px rgba(15,23,42,.05);
    }

    @media (max-width: 991.98px){
      .hero-meta{
        justify-content:flex-start;
      }
      .hero-meta .meta-box{
        min-width:calc(50% - .4rem);
      }
    }

    @media (max-width: 575.98px){
      .hero-admin{
        padding:1rem;
      }
      .hero-meta .meta-box{
        min-width:100%;
      }
      .kpi{
        padding:.92rem .95rem;
      }
    }
  </style>

</head>
<body>

<div class="container my-4 page-shell">
  <div class="hero-admin mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
      <div>
        <div class="hero-chip mb-3">
          <i class="bi bi-building-fill-gear text-primary"></i>
          <span>Panel administrativo de asistencias</span>
        </div>
        <h3 class="mb-2 fw-bold">Asistencias</h3>
        <div class="text-secondary">Monitorea la semana operativa, consulta incidencias y revisa permisos y vacaciones desde una sola vista.</div>
      </div>
      <div class="hero-meta">
        <div class="meta-box">
          <div class="label">Semana operativa</div>
          <div class="value"><?= h(fmtBadgeRango($tuesdayStart)) ?></div>
        </div>
        <div class="meta-box">
          <div class="label">Vista</div>
          <div class="value"><?= $sucursal_id > 0 ? 'Sucursal filtrada' : 'Todas las sucursales' ?></div>
        </div>
      </div>
    </div>
  </div>


  <?php if($msgVac): ?>
    <div id="alert-vac" class="alert alert-<?= h($clsVac) ?>"><?= h($msgVac) ?></div>
  <?php endif; ?>

  <?php if($msgInc): ?>
    <div id="alert-inc" class="alert alert-<?= h($clsInc) ?>"><?= h($msgInc) ?></div>
  <?php endif; ?>

  <?php if($msgSol): ?>
    <div id="alert-sol" class="alert alert-<?= h($clsSol) ?>"><?= h($msgSol) ?></div>
  <?php endif; ?>

  <!-- KPIs -->
  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-slate"><i class="bi bi-people"></i><div><div class="text-muted small">Colaboradores</div><div class="big"><?= (int)$empleadosActivos ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-green"><i class="bi bi-person-check"></i><div><div class="text-muted small">Presentes</div><div class="big"><?= (int)($totAsis+$totRet) ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-yellow"><i class="bi bi-alarm"></i><div><div class="text-muted small">Retardos</div><div class="big"><?= (int)$totRet ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-red"><i class="bi bi-person-x"></i><div><div class="text-muted small">Faltas</div><div class="big"><?= (int)$totFal ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-purple"><i class="bi bi-clipboard-check"></i><div><div class="text-muted small">Permisos</div><div class="big"><?= (int)$totPerm ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-slate"><i class="bi bi-moon-stars"></i><div><div class="text-muted small">Descansos</div><div class="big"><?= (int)$totDesc ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-blue"><i class="bi bi-clock-history"></i><div><div class="text-muted small">Horas</div><div class="big"><?= number_format($horasTot,2) ?></div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="kpi bg-soft-green"><i class="bi bi-graph-up"></i><div><div class="text-muted small">Puntualidad</div><div class="big"><?= $puntualidad ?>%</div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-3">
      <div class="kpi bg-soft-blue"><i class="bi bi-bullseye"></i><div><div class="text-muted small">Cumplimiento</div><div class="big"><?= $cumplimiento ?>%</div></div></div>
    </div>
    <div class="col-6 col-md-3 col-xl-3">
      <div class="kpi bg-soft-yellow"><i class="bi bi-exclamation-diamond"></i><div><div class="text-muted small">Falta por 3+ retardos</div><div class="big"><?= (int)$faltasPorRetardos ?></div></div></div>
    </div>
  </div>

  <div class="card card-elev mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-sm-6 col-md-5 col-xl-4">
          <label class="form-label filter-label">Semana (Mar→Lun)</label>
          <select name="week" class="form-select">
            <?php foreach ($weekOptions as $opt): ?>
              <option value="<?= h($opt['iso']) ?>" <?= $opt['iso'] === $weekIso ? 'selected' : '' ?>>
                <?= h($opt['label']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6 col-md-4 col-xl-4">
          <label class="form-label filter-label">Sucursal</label>
          <select name="sucursal_id" class="form-select">
            <option value="0">Todas</option>
            <?php foreach($sucursales as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= $sucursal_id === (int)$s['id'] ? 'selected' : '' ?>>
                <?= h($s['nombre']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-4 col-md-3 col-xl-2">
          <button class="btn btn-primary w-100 mt-2 mt-md-0">
            <i class="bi bi-funnel me-1"></i>Filtrar
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Export + Acciones -->
  <div class="toolbar-soft mb-3">
    <a class="btn btn-outline-success btn-sm rounded-pill px-3" href="?export=matrix&<?= $qsExport ?>"><i class="bi bi-grid-3x3-gap me-1"></i> Exportar matriz</a>
    <a class="btn btn-outline-primary btn-sm rounded-pill px-3" href="?export=detalles&<?= $qsExport ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Exportar detalles</a>
    <a class="btn btn-outline-secondary btn-sm rounded-pill px-3" href="?export=permisos&<?= $qsExport ?>"><i class="bi bi-clipboard-check me-1"></i> Exportar permisos</a>

    <button class="btn btn-danger btn-sm rounded-pill px-3 ms-auto" data-bs-toggle="modal" data-bs-target="#modalIncidencia">
      <i class="bi bi-exclamation-octagon me-1"></i> Agregar incidencia
    </button>

    <a class="btn btn-warning btn-sm rounded-pill px-3" href="vacaciones_panel.php">
      <i class="bi bi-calendar2-week me-1"></i> Ir al panel de vacaciones
    </a>
  </div>

  <!-- MATRIZ -->
  <div class="card card-elev mb-4">
    <div class="card-header fw-bold d-flex align-items-center justify-content-between">
      <span><i class="bi bi-grid-3x3-gap-fill me-2 text-primary"></i>Matriz semanal (Mar→Lun) por persona</span>
      <div class="small text-muted">
        <span class="pill pill-compact pill-A"  title="Asistió">A</span>
        <span class="pill pill-compact pill-R"  title="Retardo">R+min</span>
        <span class="pill pill-compact pill-F"  title="Falta">F</span>
        <span class="pill pill-compact pill-P"  title="Permiso / Incidencia">P</span>
        <span class="pill pill-compact pill-V"  title="Vacaciones">V</span>
        <span class="pill pill-compact pill-D"  title="Descanso">D</span>
        <span class="pill pill-compact pill-X"  title="Sucursal cerrada">X</span>
        <span class="pill pill-compact pill-PN" title="Pendiente">—</span>
        <span class="pill pill-compact pill-B"  title="Baja">B</span>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-xs align-middle mb-0">
          <thead class="table-dark thead-sticky">
            <tr>
              <th>Sucursal</th>
              <th>Colaborador</th>
              <?php foreach($days as $idx=>$d): ?>
                <th class="text-center">
                  <?= $weekNames[$idx] ?><br>
                  <small><?= $d->format('d/m') ?></small>
                </th>
              <?php endforeach; ?>
              <th class="text-end">Asis.</th>
              <th class="text-end">Ret.</th>
              <th class="text-end">Faltas</th>
              <th class="text-end">Perm.</th>
              <th class="text-end">Desc.</th>
              <th class="text-end">Min</th>
              <th class="text-end">Horas</th>
              <th class="text-center">Falta por retardos</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$matriz): ?>
            <tr><td colspan="<?= 2 + count($days) + 8 ?>" class="text-muted">Sin datos.</td></tr>
          <?php else: foreach($matriz as $fila):
            $hrs = $fila['min'] > 0 ? number_format($fila['min']/60, 2) : '0.00';
            $faltaRet = ($fila['ret'] >= 3)
              ? '<span class="badge text-bg-danger">1</span>'
              : '<span class="badge text-bg-secondary">0</span>';
          ?>
            <tr>
              <td><?= h($fila['sucursal']) ?></td>
              <td class="fw-semibold"><?= h($fila['usuario']) ?></td>

              <?php foreach($fila['dias'] as $d):
                $estado=$d['estado'];
                $abbr=''; $cls=''; $title='';

                switch ($estado) {
                  case 'ASISTIÓ':    $abbr='A'; $cls='pill-A';  $title='Asistió'; break;
                  case 'RETARDO':    $abbr='R'.($d['retardo_min']>0?'+'.$d['retardo_min'].'m':''); $cls='pill-R'; $title='Retardo'; break;
                  case 'FALTA':      $abbr='F'; $cls='pill-F';  $title='Falta'; break;
                  case 'PERMISO':    $abbr='P'; $cls='pill-P';  $title='Permiso / Incidencia'; break;
                  case 'VACACIONES': $abbr='V'; $cls='pill-V';  $title='Vacaciones'; break;
                  case 'DESCANSO':   $abbr='D'; $cls='pill-D';  $title='Descanso'; break;
                  case 'CERRADA':    $abbr='X'; $cls='pill-X';  $title='Sucursal cerrada'; break;
                  case 'PENDIENTE':  $abbr='—'; $cls='pill-PN'; $title='Pendiente'; break;
                  case 'BAJA':       $abbr='B'; $cls='pill-B';  $title='Baja'; break;
                  default:           $abbr='?'; $cls='pill-PN'; $title=$estado; break;
                }

                $tooltip = 'Entrada: '.($d['entrada']??'—').' | Salida: '.($d['salida']??'—').' | Dur: '.$d['dur'].'m';
                $titleFull = trim($title.' · '.$tooltip);
              ?>
                <td class="text-center">
                  <span class="pill pill-compact <?= $cls ?>" title="<?= h($titleFull) ?>">
                    <?= h($abbr) ?>
                  </span>
                </td>
              <?php endforeach; ?>

              <td class="text-end"><?= (int)$fila['asis'] ?></td>
              <td class="text-end"><?= (int)$fila['ret'] ?></td>
              <td class="text-end"><?= (int)$fila['fal'] ?></td>
              <td class="text-end"><?= (int)$fila['perm'] ?></td>
              <td class="text-end"><?= (int)$fila['desc'] ?></td>
              <td class="text-end"><?= (int)$fila['min'] ?></td>
              <td class="text-end"><?= $hrs ?></td>
              <td class="text-center"><?= $faltaRet ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="card card-elev">
    <div class="card-header p-0">
      <ul class="nav nav-tabs card-header-tabs" id="asistTabs" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" id="tab-detalle-tab" data-bs-toggle="tab" data-bs-target="#tab-detalle" type="button" role="tab">
            Detalle de asistencias
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="tab-permisos-tab" data-bs-toggle="tab" data-bs-target="#tab-permisos" type="button" role="tab">
            Permisos de la semana
          </button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" id="tab-sol-vac-tab" data-bs-toggle="tab" data-bs-target="#tab-sol-vac" type="button" role="tab">
            Solicitudes de vacaciones
            <?php if(!empty($solVacPend)): ?>
              <span class="badge text-bg-danger ms-1"><?= count($solVacPend) ?></span>
            <?php endif; ?>
          </button>
        </li>
      </ul>
    </div>

    <div class="card-body">
      <div class="tab-content" id="asistTabsContent">

        <!-- TAB DETALLE -->
        <div class="tab-pane fade show active" id="tab-detalle" role="tabpanel">
          <form method="get" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="week" value="<?= h($weekIso) ?>">
            <input type="hidden" name="sucursal_id" value="<?= (int)$sucursal_id ?>">
            <div class="col-sm-6 col-md-4">
              <label class="form-label mb-0">Día dentro de la semana</label>
              <select name="dia" class="form-select">
                <option value="">Todos los días (semana)</option>
                <?php foreach($days as $d): $v=$d->format('Y-m-d'); ?>
                  <option value="<?= $v ?>" <?= $diaSel === $v ? 'selected' : '' ?>>
                    <?= diaCortoEs($d).' '.$d->format('d/m/Y') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-3 col-md-2">
              <button class="btn btn-outline-primary w-100">
                <i class="bi bi-funnel me-1"></i>Aplicar
              </button>
            </div>
            <?php if($diaSel): ?>
              <div class="col-sm-3 col-md-2">
                <a class="btn btn-outline-secondary w-100" href="?week=<?= h($weekIso) ?>&sucursal_id=<?= (int)$sucursal_id ?>">
                  Limpiar día
                </a>
              </div>
            <?php endif; ?>
          </form>

          <div class="table-responsive">
            <table class="table table-hover table-xs align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Sucursal</th><th>Usuario</th><th>Fecha</th>
                  <th>Entrada</th><th>Salida</th>
                  <th class="text-end">Duración (min)</th>
                  <th>Estado</th><th>Retardo(min)</th>
                  <th>Mapa</th><th>IP</th>
                </tr>
              </thead>
              <tbody>
              <?php if(!$asistDetView): ?>
                <tr><td colspan="10" class="text-muted">Sin registros.</td></tr>
              <?php else: foreach($asistDetView as $a):
                $esRet = ((int)($a['retardo']??0)===1);
                $retMin = (int)($a['retardo_minutos']??0);
                $abbr = $esRet ? ('R'.($retMin>0?'+'.$retMin.'m':'')) : 'A';
                $cls  = $esRet ? 'pill-R' : 'pill-A';
                $title= $esRet ? ('Retardo'.($retMin>0?' +'.$retMin.'m':'')) : 'Asistió';
              ?>
                <tr class="<?= $a['hora_salida'] ? '' : 'table-warning' ?>">
                  <td><?= h($a['sucursal']) ?></td>
                  <td><?= h($a['usuario']) ?></td>
                  <td><?= h($a['fecha']) ?></td>
                  <td><?= h($a['hora_entrada']) ?></td>
                  <td><?= $a['hora_salida'] ? h($a['hora_salida']) : '<span class="text-muted">—</span>' ?></td>
                  <td class="text-end"><?= (int)($a['duracion_minutos'] ?? 0) ?></td>
                  <td><span class="pill pill-compact <?= $cls ?>" title="<?= h($title) ?>"><?= h($abbr) ?></span></td>
                  <td><?= $retMin ?></td>
                  <td>
                    <?php if($a['latitud'] !== null && $a['longitud'] !== null):
                      $url = 'https://maps.google.com/?q='.urlencode($a['latitud'].','.$a['longitud']);
                    ?>
                      <a href="<?= h($url) ?>" target="_blank" class="btn btn-sm btn-outline-primary">Mapa</a>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                  <td><code><?= h($a['ip'] ?? '—') ?></code></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- TAB PERMISOS -->
        <div class="tab-pane fade" id="tab-permisos" role="tabpanel">
          <div class="table-responsive">
            <table class="table table-striped table-xs align-middle mb-0">
              <thead class="table-dark">
                <tr>
                  <th>Sucursal</th><th>Colaborador</th><th>Fecha</th>
                  <th>Motivo</th><th>Comentario</th>
                  <th>Status</th><th>Resuelto por</th><th>Obs.</th>
                </tr>
              </thead>
              <tbody>
              <?php if(!$permisosSemana): ?>
                <tr><td colspan="8" class="text-muted">Sin permisos en esta semana.</td></tr>
              <?php else: foreach($permisosSemana as $p): ?>
                <tr>
                  <td><?= h($p['sucursal']) ?></td>
                  <td><?= h($p['usuario']) ?></td>
                  <td><?= h($p['fecha']) ?></td>
                  <td><?= h($p['motivo'] ?? '') ?></td>
                  <td><?= h($p['comentario'] ?? '—') ?></td>
                  <td>
                    <span class="badge <?= ($p['status'] ?? '') === 'Aprobado' ? 'bg-success' : ((($p['status'] ?? '') === 'Rechazado') ? 'bg-danger' : 'bg-warning text-dark') ?>">
                      <?= h($p['status'] ?? '') ?>
                    </span>
                  </td>
                  <td><?= isset($p['aprobado_por']) && $p['aprobado_por'] !== null ? (int)$p['aprobado_por'] : '—' ?></td>
                  <td><?= h($p['comentario_aprobador'] ?? '—') ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- TAB SOLICITUDES VACACIONES -->
        <div class="tab-pane fade" id="tab-sol-vac" role="tabpanel">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
              <div class="fw-bold">Vacaciones</div>
              <div class="small text-muted">La aprobación y gestión se realiza únicamente desde el panel de vacaciones.</div>
            </div>
            <a href="vacaciones_panel.php" class="btn btn-sm btn-warning">
              <i class="bi bi-calendar2-week me-1"></i>Ir al panel de vacaciones
            </a>
          </div>

          <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
              <div class="fw-semibold mb-2">Solicitudes pendientes</div>
              <div class="table-responsive">
                <table class="table table-striped table-xs align-middle mb-0">
                  <thead class="table-dark">
                    <tr>
                      <th>#</th>
                      <th>Sucursal</th>
                      <th>Colaborador</th>
                      <th>Rango</th>
                      <th>Comentario</th>
                      <th>Creada</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if(!$solVacPend): ?>
                    <tr><td colspan="6" class="text-muted">No hay solicitudes pendientes.</td></tr>
                  <?php else: foreach($solVacPend as $sv): ?>
                    <tr>
                      <td><?= (int)$sv['id'] ?></td>
                      <td><?= h($sv['sucursal']) ?></td>
                      <td><?= h($sv['usuario']) ?></td>
                      <td><?= h(($sv['fecha_inicio'] ?? '').' → '.($sv['fecha_fin'] ?? '')) ?></td>
                      <td><?= h($sv['comentario'] ?? '—') ?></td>
                      <td><?= h($sv['creado_en'] ?? '—') ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="card border-0 shadow-sm">
            <div class="card-body">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="fw-semibold">Historial de vacaciones aprobadas en la semana</div>
                <div class="small text-muted"><?= h(fmtBadgeRango($tuesdayStart)) ?></div>
              </div>

              <div class="table-responsive">
                <table class="table table-striped table-xs align-middle mb-0">
                  <thead class="table-dark">
                    <tr>
                      <th>Fecha</th>
                      <th>Sucursal</th>
                      <th>Colaborador</th>
                      <th>Motivo</th>
                      <th>Comentario</th>
                      <th>Obs. aprobación</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php if(!$histVacAprob): ?>
                    <tr><td colspan="6" class="text-muted">No hay vacaciones aprobadas registradas en esta semana.</td></tr>
                  <?php else: foreach($histVacAprob as $hv): ?>
                    <tr>
                      <td><?= h($hv['fecha']) ?></td>
                      <td><?= h($hv['sucursal']) ?></td>
                      <td><?= h($hv['usuario']) ?></td>
                      <td><span class="badge text-bg-success"><?= h($hv['motivo'] ?: 'Vacaciones') ?></span></td>
                      <td><?= h($hv['comentario'] !== '' ? $hv['comentario'] : '—') ?></td>
                      <td><?= h($hv['comentario_aprobador'] !== '' ? $hv['comentario_aprobador'] : '—') ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>
  </div>
</div>

<!-- MODAL: Alta de Incidencia -->
<div class="modal fade" id="modalIncidencia" tabindex="-1" aria-labelledby="modalIncidenciaLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="accion" value="alta_incidencia">
        <div class="modal-header">
          <h5 class="modal-title" id="modalIncidenciaLabel">Agregar incidencia de asistencia</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Colaborador *</label>
            <input type="text" class="form-control form-control-sm mb-2 js-buscar-empleado" placeholder="Escribe nombre o sucursal...">
            <select name="id_usuario" class="form-select js-select-empleado" required>
              <option value="">Seleccione…</option>
              <?php foreach($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= h($u['sucursal'].' · '.$u['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Fecha *</label>
              <input type="date" name="fecha" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Tipo *</label>
              <select name="tipo" class="form-select" required>
                <option value="">Seleccione…</option>
                <option value="FALTA_JUSTIFICADA">Falta justificada</option>
                <option value="INCAPACIDAD">Incapacidad</option>
                <option value="FALTA">Falta</option>
                <option value="RETARDO">Retardo</option>
                <option value="SALIDA_ANTICIPADA">Salida anticipada</option>
                <option value="OTRO">Otro</option>
              </select>
            </div>
          </div>

          <div class="row g-2 mt-2">
            <div class="col-md-4">
              <label class="form-label">Minutos (opcional)</label>
              <input type="number" name="minutos" class="form-control" min="0" step="1">
            </div>
            <div class="col-md-8">
              <label class="form-label">Comentario (opcional)</label>
              <textarea name="comentario" rows="2" class="form-control" placeholder="Descripción breve"></textarea>
            </div>
          </div>

          <div class="mt-2 small text-muted">
            Todo lo capturado aquí se considera <strong>aprobado</strong> y en la matriz cuenta como <strong>PERMISO</strong>.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-danger">Guardar incidencia</button>
        </div>
      </form>
    </div>
  </div>
</div>





<?php if($msgInc && $clsInc === 'danger'): ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
  if (typeof bootstrap !== 'undefined') {
    new bootstrap.Modal(document.getElementById('modalIncidencia')).show();
  }
});
</script>
<?php endif; ?>



<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.js-buscar-empleado').forEach(function (input) {
    const modalBody = input.closest('.modal-body');
    if (!modalBody) return;

    const select = modalBody.querySelector('.js-select-empleado');
    if (!select) return;

    const options = Array.from(select.options);

    input.addEventListener('input', function () {
      const term = input.value.trim().toLowerCase();

      if (!term) {
        select.selectedIndex = 0;
        return;
      }

      const match = options.find(function (opt, idx) {
        if (idx === 0) return false;
        return opt.text.toLowerCase().includes(term);
      });

      if (match) {
        select.value = match.value;
      }
    });
  });
});
</script>
</body>
</html>