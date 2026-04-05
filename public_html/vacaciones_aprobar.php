<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

$miRol = trim((string)($_SESSION['rol'] ?? ''));
$miId  = (int)($_SESSION['id_usuario'] ?? 0);

if (!in_array($miRol, ['Admin', 'Gerente'], true)) {
    http_response_code(403);
    exit('Sin permiso para aprobar solicitudes de vacaciones.');
}

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");
date_default_timezone_set('America/Mexico_City');

/* =========================================================
   Helpers
========================================================= */
function redirect_panel(string $ok = '', string $err = ''): void {
    $params = [];
    if ($ok !== '')  $params['ok'] = $ok;
    if ($err !== '') $params['err'] = $err;

    $url = 'vacaciones_panel.php';
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    header("Location: {$url}");
    exit;
}

function empty_date($v): bool {
    return empty($v) || $v === '0000-00-00';
}

function table_exists(mysqli $conn, string $table): bool {
    $t = $conn->real_escape_string($table);
    $rs = $conn->query("SHOW TABLES LIKE '{$t}'");
    return $rs && $rs->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $rs = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return $rs && $rs->num_rows > 0;
}

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

/* =========================================================
   Validaciones base
========================================================= */
if (!table_exists($conn, 'vacaciones_solicitudes')) {
    redirect_panel('', 'No existe la tabla vacaciones_solicitudes.');
}

if (!table_exists($conn, 'permisos_solicitudes')) {
    redirect_panel('', 'No existe la tabla permisos_solicitudes.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_panel('', 'Método no permitido.');
}

$solId = (int)($_POST['id'] ?? 0);
$comentarioResolucion = trim((string)($_POST['comentario'] ?? ''));

if ($solId <= 0) {
    redirect_panel('', 'Solicitud inválida.');
}

/* =========================================================
   Compatibilidad columnas vacaciones_solicitudes
========================================================= */
$statusAdminCol = column_exists($conn, 'vacaciones_solicitudes', 'status_admin') ? 'status_admin' : 'status';

$aprobadoPorCol = column_exists($conn, 'vacaciones_solicitudes', 'aprobado_admin_por')
    ? 'aprobado_admin_por'
    : (column_exists($conn, 'vacaciones_solicitudes', 'resuelto_por')
        ? 'resuelto_por'
        : (column_exists($conn, 'vacaciones_solicitudes', 'aprobado_por') ? 'aprobado_por' : null));

$aprobadoEnCol = column_exists($conn, 'vacaciones_solicitudes', 'aprobado_admin_en')
    ? 'aprobado_admin_en'
    : (column_exists($conn, 'vacaciones_solicitudes', 'resuelto_en')
        ? 'resuelto_en'
        : (column_exists($conn, 'vacaciones_solicitudes', 'aprobado_en') ? 'aprobado_en' : null));

$comentResCol = column_exists($conn, 'vacaciones_solicitudes', 'comentario_resolucion')
    ? 'comentario_resolucion'
    : (column_exists($conn, 'vacaciones_solicitudes', 'comentario_admin')
        ? 'comentario_admin'
        : null));

$comentarioCol = column_exists($conn, 'vacaciones_solicitudes', 'comentario')
    ? 'comentario'
    : (column_exists($conn, 'vacaciones_solicitudes', 'motivo')
        ? 'motivo'
        : null);

$idSucursalCol = column_exists($conn, 'vacaciones_solicitudes', 'id_sucursal') ? 'id_sucursal' : null;

/* =========================================================
   Obtener solicitud
========================================================= */
$sqlGet = "
    SELECT
        vs.*,
        u.nombre AS usuario_nombre,
        u.id_sucursal AS usuario_id_sucursal
    FROM vacaciones_solicitudes vs
    INNER JOIN usuarios u ON u.id = vs.id_usuario
    WHERE vs.id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sqlGet);
$stmt->bind_param('i', $solId);
$stmt->execute();
$sol = stmt_one_assoc($stmt);
$stmt->close();

if (!$sol) {
    redirect_panel('', 'La solicitud no existe.');
}

if (($sol[$statusAdminCol] ?? '') !== 'Pendiente') {
    redirect_panel('', 'La solicitud ya no está pendiente.');
}

$idUsuario = (int)($sol['id_usuario'] ?? 0);
$idSucursal = $idSucursalCol
    ? (int)($sol[$idSucursalCol] ?? 0)
    : (int)($sol['usuario_id_sucursal'] ?? 0);

$fechaInicio = trim((string)($sol['fecha_inicio'] ?? ''));
$fechaFin    = trim((string)($sol['fecha_fin'] ?? ''));
$comentarioBase = $comentarioCol ? trim((string)($sol[$comentarioCol] ?? '')) : '';

if ($idUsuario <= 0 || empty_date($fechaInicio) || empty_date($fechaFin) || $fechaFin < $fechaInicio) {
    redirect_panel('', 'La solicitud no tiene un rango de fechas válido.');
}

/* =========================================================
   Compatibilidad columnas permisos_solicitudes
========================================================= */
$permDateCol = null;
foreach (['fecha', 'fecha_permiso', 'dia', 'fecha_solicitada', 'creado_en'] as $c) {
    if (column_exists($conn, 'permisos_solicitudes', $c)) {
        $permDateCol = $c;
        break;
    }
}
if ($permDateCol === null) {
    redirect_panel('', 'No se encontró una columna de fecha válida en permisos_solicitudes.');
}

$hasMotivo    = column_exists($conn, 'permisos_solicitudes', 'motivo');
$hasComent    = column_exists($conn, 'permisos_solicitudes', 'comentario');
$hasStatus    = column_exists($conn, 'permisos_solicitudes', 'status');
$hasSucursal  = column_exists($conn, 'permisos_solicitudes', 'id_sucursal');
$hasCreadoPor = column_exists($conn, 'permisos_solicitudes', 'creado_por');
$hasCreadoEn  = column_exists($conn, 'permisos_solicitudes', 'creado_en');
$hasAprobPor  = column_exists($conn, 'permisos_solicitudes', 'aprobado_por');
$hasAprobEn   = column_exists($conn, 'permisos_solicitudes', 'aprobado_en');
$hasComApr    = column_exists($conn, 'permisos_solicitudes', 'comentario_aprobador');

/* =========================================================
   Construcción INSERT permisos_solicitudes
========================================================= */
$cols = ['id_usuario'];
if ($hasSucursal)  $cols[] = 'id_sucursal';
$cols[] = $permDateCol;
if ($hasMotivo)    $cols[] = 'motivo';
if ($hasComent)    $cols[] = 'comentario';
if ($hasStatus)    $cols[] = 'status';
if ($hasCreadoPor) $cols[] = 'creado_por';
if ($hasCreadoEn)  $cols[] = 'creado_en';
if ($hasAprobPor)  $cols[] = 'aprobado_por';
if ($hasAprobEn)   $cols[] = 'aprobado_en';
if ($hasComApr)    $cols[] = 'comentario_aprobador';

$placeholders = [];
foreach ($cols as $c) {
    $placeholders[] = ($c === 'creado_en' || $c === 'aprobado_en') ? 'NOW()' : '?';
}

$sqlInsertPerm = "INSERT INTO permisos_solicitudes (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")";
$stmtInsertPerm = $conn->prepare($sqlInsertPerm);

/* =========================================================
   Rango de fechas
========================================================= */
$fechas = [];
$cursor = new DateTime($fechaInicio);
$finObj = new DateTime($fechaFin);

while ($cursor <= $finObj) {
    $fechas[] = $cursor->format('Y-m-d');
    $cursor->modify('+1 day');
}

/* =========================================================
   Aprobar
========================================================= */
$conn->begin_transaction();

try {
    $insertados = 0;
    $omitidos = 0;

    foreach ($fechas as $fecha) {
        if ($hasMotivo) {
            $sqlDup = "SELECT id
                       FROM permisos_solicitudes
                       WHERE id_usuario = ?
                         AND DATE(`{$permDateCol}`) = ?
                         AND motivo = 'Vacaciones'
                       LIMIT 1";
        } else {
            $sqlDup = "SELECT id
                       FROM permisos_solicitudes
                       WHERE id_usuario = ?
                         AND DATE(`{$permDateCol}`) = ?
                       LIMIT 1";
        }

        $stmtDup = $conn->prepare($sqlDup);
        $stmtDup->bind_param('is', $idUsuario, $fecha);
        $stmtDup->execute();
        $dup = stmt_one_assoc($stmtDup);
        $stmtDup->close();

        if ($dup) {
            $omitidos++;
            continue;
        }

        $vals = [];
        $types = '';

        foreach ($cols as $c) {
            if ($c === 'creado_en' || $c === 'aprobado_en') {
                continue;
            }

            switch ($c) {
                case 'id_usuario':
                    $vals[] = $idUsuario;
                    $types .= 'i';
                    break;

                case 'id_sucursal':
                    $vals[] = $idSucursal;
                    $types .= 'i';
                    break;

                case 'motivo':
                    $vals[] = 'Vacaciones';
                    $types .= 's';
                    break;

                case 'comentario':
                    $vals[] = $comentarioBase;
                    $types .= 's';
                    break;

                case 'status':
                    $vals[] = 'Aprobado';
                    $types .= 's';
                    break;

                case 'creado_por':
                    $vals[] = $miId;
                    $types .= 'i';
                    break;

                case 'aprobado_por':
                    $vals[] = $miId;
                    $types .= 'i';
                    break;

                case 'comentario_aprobador':
                    $vals[] = $comentarioResolucion !== '' ? $comentarioResolucion : 'Aprobado desde panel de vacaciones';
                    $types .= 's';
                    break;

                default:
                    if ($c === $permDateCol) {
                        $vals[] = $fecha;
                        $types .= 's';
                    }
                    break;
            }
        }

        $stmtInsertPerm->bind_param($types, ...$vals);
        $stmtInsertPerm->execute();
        $insertados++;
    }

    $sets = [];
    $params = [];
    $types = '';

    $sets[] = "`{$statusAdminCol}` = ?";
    $types .= 's';
    $params[] = 'Aprobado';

    if ($aprobadoPorCol) {
        $sets[] = "`{$aprobadoPorCol}` = ?";
        $types .= 'i';
        $params[] = $miId;
    }

    if ($aprobadoEnCol) {
        $sets[] = "`{$aprobadoEnCol}` = NOW()";
    }

    if ($comentResCol) {
        $sets[] = "`{$comentResCol}` = ?";
        $types .= 's';
        $params[] = $comentarioResolucion;
    }

    $types .= 'i';
    $params[] = $solId;

    $sqlUpdate = "UPDATE vacaciones_solicitudes
                  SET " . implode(', ', $sets) . "
                  WHERE id = ?
                    AND `{$statusAdminCol}` = 'Pendiente'
                  LIMIT 1";

    $stmtUpd = $conn->prepare($sqlUpdate);
    $stmtUpd->bind_param($types, ...$params);
    $stmtUpd->execute();

    if ($stmtUpd->affected_rows <= 0) {
        $stmtUpd->close();
        throw new Exception('La solicitud ya no pudo actualizarse como pendiente.');
    }
    $stmtUpd->close();

    $conn->commit();

    $msg = "Solicitud aprobada. Días registrados: {$insertados}.";
    if ($omitidos > 0) {
        $msg .= " Omitidos por duplicado: {$omitidos}.";
    }

    redirect_panel($msg, '');
} catch (Throwable $e) {
    $conn->rollback();
    redirect_panel('', 'Error al aprobar la solicitud: ' . $e->getMessage());
}