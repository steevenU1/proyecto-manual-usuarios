<?php
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
    echo json_encode([
        'ok' => false,
        'msg' => 'Sesión no válida.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

/* =========================================================
   CONTEXTO / PERMISOS
========================================================= */
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ROL         = trim((string)($_SESSION['rol'] ?? ''));
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

$ROLES_PERMITIDOS = [
    'Admin',
    'Administrador',
    'Auditor',
    'Logistica',
    'GerenteZona',
    'Gerente',
    'Supervisor'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    echo json_encode([
        'ok' => false,
        'msg' => 'Sin permiso para firmar actas.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
   HELPERS
========================================================= */
function jsonError(string $msg): void {
    echo json_encode([
        'ok' => false,
        'msg' => $msg
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function generarTokenFirma(): string {
    return 'AUD-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2))) . '-' . date('YmdHis');
}

function getClientIp(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidates as $ip) {
        $ip = trim((string)$ip);
        if ($ip !== '') {
            if (strpos($ip, ',') !== false) {
                $parts = explode(',', $ip);
                return trim($parts[0]);
            }
            return $ip;
        }
    }
    return '';
}

/* =========================================================
   INPUT
========================================================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método no permitido.');
}

$id_auditoria      = (int)($_POST['id_auditoria'] ?? 0);
$password_auditor  = (string)($_POST['password_auditor'] ?? '');
$password_gerente  = (string)($_POST['password_gerente'] ?? '');

if ($id_auditoria <= 0) {
    jsonError('Auditoría no válida.');
}

if ($password_auditor === '') {
    jsonError('Debes capturar la contraseña del auditor.');
}

if ($password_gerente === '') {
    jsonError('Debes capturar la contraseña del gerente o responsable presente.');
}

/* =========================================================
   VALIDAR COLUMNAS MINIMAS
========================================================= */
function hasColumn(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    return (bool)($res && $res->fetch_row());
}

$requiredAuditoriaCols = [
    'firmado_digitalmente',
    'token_firma',
    'fecha_firma_final',
    'id_firma_auditor',
    'id_firma_gerente',
    'fecha_firma_auditor',
    'fecha_firma_gerente'
];

foreach ($requiredAuditoriaCols as $col) {
    if (!hasColumn($conn, 'auditorias', $col)) {
        jsonError("Falta la columna {$col} en la tabla auditorias.");
    }
}

/* =========================================================
   CARGAR AUDITORIA
========================================================= */
$stmtAud = $conn->prepare("
    SELECT
        a.id,
        a.id_sucursal,
        a.id_auditor,
        a.id_gerente,
        a.estatus,
        a.firmado_digitalmente,
        a.token_firma,
        u1.nombre AS auditor_nombre,
        u1.password AS auditor_password,
        u2.nombre AS gerente_nombre,
        u2.password AS gerente_password
    FROM auditorias a
    INNER JOIN usuarios u1 ON u1.id = a.id_auditor
    LEFT JOIN usuarios u2 ON u2.id = a.id_gerente
    WHERE a.id = ?
    LIMIT 1
");
$stmtAud->bind_param("i", $id_auditoria);
$stmtAud->execute();
$auditoria = $stmtAud->get_result()->fetch_assoc();

if (!$auditoria) {
    jsonError('La auditoría no existe.');
}

if (($auditoria['estatus'] ?? '') !== 'Cerrada') {
    jsonError('La auditoría todavía no está cerrada.');
}

if ((int)($auditoria['firmado_digitalmente'] ?? 0) === 1) {
    jsonError('Esta acta ya fue firmada digitalmente.');
}

if (in_array($ROL, ['Gerente', 'Supervisor'], true) && (int)$auditoria['id_sucursal'] !== $ID_SUCURSAL) {
    jsonError('No puedes firmar una auditoría de otra sucursal.');
}

$idAuditor = (int)($auditoria['id_auditor'] ?? 0);
$idGerente = (int)($auditoria['id_gerente'] ?? 0);

if ($idAuditor <= 0) {
    jsonError('La auditoría no tiene auditor asignado.');
}

if ($idGerente <= 0) {
    jsonError('La auditoría no tiene gerente o responsable presente asignado.');
}

/* =========================================================
   VALIDAR PASSWORDS
========================================================= */
$hashAuditor = (string)($auditoria['auditor_password'] ?? '');
$hashGerente = (string)($auditoria['gerente_password'] ?? '');

if ($hashAuditor === '' || !password_verify($password_auditor, $hashAuditor)) {
    jsonError('La contraseña del auditor es incorrecta.');
}

if ($hashGerente === '' || !password_verify($password_gerente, $hashGerente)) {
    jsonError('La contraseña del gerente o responsable presente es incorrecta.');
}

/* =========================================================
   GUARDAR FIRMA DIGITAL
========================================================= */
try {
    $conn->begin_transaction();

    $token = generarTokenFirma();

    $stmtUpd = $conn->prepare("
        UPDATE auditorias
        SET firmado_digitalmente = 1,
            token_firma = ?,
            fecha_firma_final = NOW(),
            id_firma_auditor = ?,
            id_firma_gerente = ?,
            fecha_firma_auditor = NOW(),
            fecha_firma_gerente = NOW()
        WHERE id = ?
          AND firmado_digitalmente = 0
        LIMIT 1
    ");
    $stmtUpd->bind_param("siii", $token, $idAuditor, $idGerente, $id_auditoria);
    $stmtUpd->execute();

    if ($stmtUpd->affected_rows <= 0) {
        throw new RuntimeException('No fue posible guardar la firma digital.');
    }

    $ip = getClientIp();
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000);

    if (hasColumn($conn, 'auditorias_bitacora', 'id_auditoria')) {
        $accion = 'Firma digital acta';
        $detalle = 'El acta de auditoría fue firmada digitalmente mediante validación por contraseña.';
        $json = json_encode([
            'token_firma' => $token,
            'id_auditor' => $idAuditor,
            'id_gerente' => $idGerente,
            'ip' => $ip,
            'user_agent' => $ua
        ], JSON_UNESCAPED_UNICODE);

        $stmtBit = $conn->prepare("
            INSERT INTO auditorias_bitacora (
                id_auditoria, accion, detalle, datos_extra, realizado_por, fecha_evento
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmtBit->bind_param("isssi", $id_auditoria, $accion, $detalle, $json, $ID_USUARIO);
        $stmtBit->execute();
    }

    $conn->commit();

    echo json_encode([
        'ok' => true,
        'msg' => 'Acta firmada digitalmente correctamente.',
        'token' => $token
    ], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    jsonError('Error al firmar el acta: ' . $e->getMessage());
}