<?php
// /api/api_portal_proyectos_listar.php
// Listado JSON para portal solicitante.
// Filtra SIEMPRE por la empresa/origen autenticado vía Bearer.

require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

function out($ok, $data = []) {
    echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

function bad($msg, $code = 400, $detail = null) {
    http_response_code($code);
    $payload = ['error' => $msg];
    if ($detail !== null && $detail !== '') {
        $payload['detail'] = $detail;
    }
    out(false, $payload);
}

function htrim($s, $max = 2000) {
    $s = trim((string)$s);
    if (mb_strlen($s) > $max) {
        $s = mb_substr($s, 0, $max);
    }
    return $s;
}

/* =========================================================
   1) AUTH POR ORIGEN
========================================================= */
$origen = require_origen(['NANO', 'MIPLAN', 'LUGA']);

/* =========================================================
   2) INPUTS
========================================================= */
$tab = strtoupper(htrim($_GET['tab'] ?? 'PENDIENTES', 40));
$q   = htrim($_GET['q'] ?? '', 180);

$TABS = [
    'PENDIENTES'  => ['EN_AUTORIZACION_SOLICITANTE'],
    'EN_PROCESO'  => ['EN_VALORACION_SISTEMAS', 'EN_COSTEO', 'EN_VALIDACION_COSTO_SISTEMAS'],
    'AUTORIZADOS' => ['AUTORIZADO', 'EN_EJECUCION', 'FINALIZADO'],
    'RECHAZADOS'  => ['RECHAZADO', 'CANCELADO'],
    'TODOS'       => [],
];

if (!isset($TABS[$tab])) {
    $tab = 'PENDIENTES';
}

/* =========================================================
   3) RESOLVER EMPRESA_ID DEL ORIGEN
========================================================= */
$stmtE = $conn->prepare("
    SELECT id, clave, nombre
    FROM portal_empresas
    WHERE clave = ? AND activa = 1
    LIMIT 1
");
$stmtE->bind_param("s", $origen);
$stmtE->execute();
$empresa = $stmtE->get_result()->fetch_assoc();
$stmtE->close();

if (!$empresa) {
    bad('empresa_no_configurada', 500);
}

$empresaId = (int)$empresa['id'];

/* =========================================================
   4) COUNTS POR TAB
========================================================= */
function countForStatuses(mysqli $conn, int $empresaId, array $statuses, string $q = ''): int {
    $where  = ["s.empresa_id = ?"];
    $types  = "i";
    $params = [$empresaId];

    if (!empty($statuses)) {
        $in = implode(',', array_fill(0, count($statuses), '?'));
        $where[] = "s.estatus IN ($in)";
        $types .= str_repeat('s', count($statuses));
        foreach ($statuses as $st) {
            $params[] = $st;
        }
    }

    if ($q !== '') {
        $where[] = "(s.folio LIKE ? OR s.titulo LIKE ? OR s.descripcion LIKE ?)";
        $types .= "sss";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql = "
        SELECT COUNT(*) AS c
        FROM portal_proyectos_solicitudes s
        WHERE " . implode(' AND ', $where);

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['c'] ?? 0);
}

$counts = [
    'PENDIENTES'  => countForStatuses($conn, $empresaId, $TABS['PENDIENTES'], $q),
    'EN_PROCESO'  => countForStatuses($conn, $empresaId, $TABS['EN_PROCESO'], $q),
    'AUTORIZADOS' => countForStatuses($conn, $empresaId, $TABS['AUTORIZADOS'], $q),
    'RECHAZADOS'  => countForStatuses($conn, $empresaId, $TABS['RECHAZADOS'], $q),
    'TODOS'       => countForStatuses($conn, $empresaId, [], $q),
];

/* =========================================================
   5) LISTADO PRINCIPAL
========================================================= */
$where  = ["s.empresa_id = ?"];
$types  = "i";
$params = [$empresaId];

$statuses = $TABS[$tab];
if (!empty($statuses)) {
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $where[] = "s.estatus IN ($in)";
    $types .= str_repeat('s', count($statuses));
    foreach ($statuses as $st) {
        $params[] = $st;
    }
}

if ($q !== '') {
    $where[] = "(s.folio LIKE ? OR s.titulo LIKE ? OR s.descripcion LIKE ?)";
    $types .= "sss";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql = "
    SELECT
        s.id,
        s.folio,
        s.titulo,
        s.descripcion,
        s.tipo,
        s.prioridad,
        s.estatus,
        s.costo_mxn,
        s.created_at,
        s.updated_at
    FROM portal_proyectos_solicitudes s
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.created_at DESC
    LIMIT 200
";

try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rs = $stmt->get_result();

    $rows = [];
    while ($r = $rs->fetch_assoc()) {
        $rows[] = [
            'id'          => (int)$r['id'],
            'folio'       => (string)$r['folio'],
            'titulo'      => (string)$r['titulo'],
            'descripcion' => (string)($r['descripcion'] ?? ''),
            'tipo'        => (string)($r['tipo'] ?? ''),
            'prioridad'   => (string)($r['prioridad'] ?? ''),
            'estatus'     => (string)($r['estatus'] ?? ''),
            'costo_mxn'   => ($r['costo_mxn'] === null) ? null : (float)$r['costo_mxn'],
            'created_at'  => $r['created_at'],
            'updated_at'  => $r['updated_at'],
        ];
    }
    $stmt->close();

    out(true, [
        'origen'  => $origen,
        'empresa' => [
            'id'     => (int)$empresa['id'],
            'clave'  => (string)$empresa['clave'],
            'nombre' => (string)$empresa['nombre'],
        ],
        'tab'    => $tab,
        'q'      => $q,
        'counts' => $counts,
        'rows'   => $rows,
    ]);

} catch (Throwable $e) {
    bad('error_listando', 500, $e->getMessage());
}