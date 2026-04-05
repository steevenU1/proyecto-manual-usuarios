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
    exit('Sin permiso para rechazar solicitudes de vacaciones.');
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_panel('', 'Método no permitido.');
}

$solId = (int)($_POST['id'] ?? 0);
$comentario = trim((string)($_POST['comentario'] ?? ''));

if ($solId <= 0) {
    redirect_panel('', 'Solicitud inválida.');
}

/* =========================================================
   Compatibilidad columnas
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

/* =========================================================
   Cargar solicitud
========================================================= */
$sqlGet = "
    SELECT id, `{$statusAdminCol}` AS status_admin
    FROM vacaciones_solicitudes
    WHERE id = ?
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

if (($sol['status_admin'] ?? '') !== 'Pendiente') {
    redirect_panel('', 'La solicitud ya no está pendiente.');
}

/* =========================================================
   Guardar rechazo
========================================================= */
$sets = [];
$params = [];
$types = '';

$sets[] = "`{$statusAdminCol}` = ?";
$types .= 's';
$params[] = 'Rechazado';

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
    $params[] = $comentario;
}

$types .= 'i';
$params[] = $solId;

$sqlUpd = "
    UPDATE vacaciones_solicitudes
    SET " . implode(', ', $sets) . "
    WHERE id = ?
      AND `{$statusAdminCol}` = 'Pendiente'
    LIMIT 1
";

try {
    $stmtUpd = $conn->prepare($sqlUpd);
    $stmtUpd->bind_param($types, ...$params);
    $stmtUpd->execute();

    if ($stmtUpd->affected_rows <= 0) {
        $stmtUpd->close();
        redirect_panel('', 'La solicitud ya no pudo actualizarse como pendiente.');
    }

    $stmtUpd->close();
    redirect_panel('Solicitud rechazada correctamente.', '');
} catch (Throwable $e) {
    redirect_panel('', 'No se pudo rechazar la solicitud: ' . $e->getMessage());
}