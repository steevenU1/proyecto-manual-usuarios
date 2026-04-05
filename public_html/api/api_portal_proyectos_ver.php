<?php
// /api/api_portal_proyectos_ver.php
// Solo lectura: consultar solicitud por id o folio
// y solo si pertenece a tu ORIGEN.
// Devuelve:
// - datos generales
// - última revisión de sistemas
// - último costeo
// - timeline opcional

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

// 1) Auth
$origen = require_origen(['NANO', 'MIPLAN', 'LUGA']);

// 2) Parámetros: id o folio
$id    = (int)($_GET['id'] ?? 0);
$folio = trim((string)($_GET['folio'] ?? ''));

if ($id <= 0 && $folio === '') {
  bad('missing_params');
}

// 3) Resolver empresa_id del ORIGEN
$stmtE = $conn->prepare("
  SELECT id
  FROM portal_empresas
  WHERE clave = ? AND activa = 1
  LIMIT 1
");
$stmtE->bind_param("s", $origen);
$stmtE->execute();
$rowE = $stmtE->get_result()->fetch_assoc();
$stmtE->close();

if (!$rowE) {
  bad('empresa_no_configurada', 500);
}

$empresaId = (int)$rowE['id'];

try {
  // =========================================
  // Solicitud principal
  // =========================================
  if ($id > 0) {
    $stmt = $conn->prepare("
      SELECT
        s.id,
        s.folio,
        s.empresa_id,
        s.usuario_solicitante_id,
        s.solicitante_nombre,
        s.solicitante_correo,
        s.titulo,
        s.descripcion,
        s.tipo,
        s.prioridad,
        s.estatus,
        s.costo_mxn,
        s.costo_capturado_por,
        s.costo_capturado_at,
        s.costo_validado_por,
        s.costo_validado_at,
        s.created_at,
        s.updated_at
      FROM portal_proyectos_solicitudes s
      WHERE s.id = ? AND s.empresa_id = ?
      LIMIT 1
    ");
    $stmt->bind_param("ii", $id, $empresaId);
  } else {
    if (mb_strlen($folio) > 30) {
      $folio = mb_substr($folio, 0, 30);
    }

    $stmt = $conn->prepare("
      SELECT
        s.id,
        s.folio,
        s.empresa_id,
        s.usuario_solicitante_id,
        s.solicitante_nombre,
        s.solicitante_correo,
        s.titulo,
        s.descripcion,
        s.tipo,
        s.prioridad,
        s.estatus,
        s.costo_mxn,
        s.costo_capturado_por,
        s.costo_capturado_at,
        s.costo_validado_por,
        s.costo_validado_at,
        s.created_at,
        s.updated_at
      FROM portal_proyectos_solicitudes s
      WHERE s.folio = ? AND s.empresa_id = ?
      LIMIT 1
    ");
    $stmt->bind_param("si", $folio, $empresaId);
  }

  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) {
    http_response_code(404);
    out(false, ['error' => 'not_found']);
  }

  $solicitudId = (int)$row['id'];

  // =========================================
  // Última revisión de sistemas
  // =========================================
  $revision = null;
  try {
    $stmtR = $conn->prepare("
      SELECT
        r.id,
        r.version,
        r.horas_min,
        r.horas_max,
        r.plan_acciones,
        r.riesgos_dependencias,
        r.created_at,
        u.nombre AS usuario_nombre
      FROM portal_proyectos_revision_sistemas r
      LEFT JOIN usuarios u ON u.id = r.usuario_sistemas_id
      WHERE r.solicitud_id = ?
      ORDER BY r.version DESC, r.id DESC
      LIMIT 1
    ");
    $stmtR->bind_param("i", $solicitudId);
    $stmtR->execute();
    $rev = $stmtR->get_result()->fetch_assoc();
    $stmtR->close();

    if ($rev) {
      $revision = [
        'id'                   => (int)$rev['id'],
        'version'              => ($rev['version'] === null) ? null : (int)$rev['version'],
        'horas_min'            => ($rev['horas_min'] === null) ? null : (float)$rev['horas_min'],
        'horas_max'            => ($rev['horas_max'] === null) ? null : (float)$rev['horas_max'],
        'plan_acciones'        => (string)($rev['plan_acciones'] ?? ''),
        'riesgos_dependencias' => (string)($rev['riesgos_dependencias'] ?? ''),
        'created_at'           => $rev['created_at'] ?? null,
        'usuario_nombre'       => (string)($rev['usuario_nombre'] ?? ''),
      ];
    }
  } catch (Throwable $te) {
    // No rompemos el endpoint si algo falla en revisión
    $revision = null;
  }

  // =========================================
  // Último costeo
  // =========================================
  $costeo = null;
  try {
    $stmtC = $conn->prepare("
      SELECT
        c.id,
        c.version,
        c.costo_mxn,
        c.desglose,
        c.condiciones,
        c.created_at,
        u.nombre AS usuario_nombre
      FROM portal_proyectos_costeo c
      LEFT JOIN usuarios u ON u.id = c.usuario_validador_id
      WHERE c.solicitud_id = ?
      ORDER BY c.version DESC, c.id DESC
      LIMIT 1
    ");
    $stmtC->bind_param("i", $solicitudId);
    $stmtC->execute();
    $co = $stmtC->get_result()->fetch_assoc();
    $stmtC->close();

    if ($co) {
      $costeo = [
        'id'             => (int)$co['id'],
        'version'        => ($co['version'] === null) ? null : (int)$co['version'],
        'costo_mxn'      => ($co['costo_mxn'] === null) ? null : (float)$co['costo_mxn'],
        'desglose'       => (string)($co['desglose'] ?? ''),
        'condiciones'    => (string)($co['condiciones'] ?? ''),
        'created_at'     => $co['created_at'] ?? null,
        'usuario_nombre' => (string)($co['usuario_nombre'] ?? ''),
      ];
    }
  } catch (Throwable $te) {
    // No rompemos el endpoint si algo falla en costeo
    $costeo = null;
  }

  // =========================================
  // Timeline opcional
  // =========================================
  $timeline = [];
  try {
    $stmtT = $conn->prepare("
      SELECT
        t.id,
        t.accion,
        t.descripcion,
        t.created_at,
        t.usuario_nombre
      FROM portal_proyectos_timeline t
      WHERE t.solicitud_id = ?
      ORDER BY t.id ASC
    ");
    $stmtT->bind_param("i", $solicitudId);
    $stmtT->execute();
    $rsT = $stmtT->get_result();

    while ($ev = $rsT->fetch_assoc()) {
      $timeline[] = [
        'id'             => (int)$ev['id'],
        'titulo'         => (string)($ev['accion'] ?? 'Movimiento'),
        'descripcion'    => (string)($ev['descripcion'] ?? ''),
        'created_at'     => $ev['created_at'],
        'usuario_nombre' => (string)($ev['usuario_nombre'] ?? ''),
      ];
    }
    $stmtT->close();
  } catch (Throwable $te) {
    // Si no existe timeline todavía, no rompemos el endpoint
    $timeline = [];
  }

  // =========================================
  // Respuesta
  // =========================================
  out(true, [
    'origen' => $origen,
    'data' => [
      'id'                     => (int)$row['id'],
      'folio'                  => (string)$row['folio'],
      'empresa_id'             => (int)$row['empresa_id'],
      'usuario_solicitante_id' => ($row['usuario_solicitante_id'] === null) ? null : (int)$row['usuario_solicitante_id'],
      'solicitante_nombre'     => (string)($row['solicitante_nombre'] ?? ''),
      'solicitante_correo'     => (string)($row['solicitante_correo'] ?? ''),
      'titulo'                 => (string)$row['titulo'],
      'descripcion'            => (string)($row['descripcion'] ?? ''),
      'tipo'                   => (string)$row['tipo'],
      'prioridad'              => (string)$row['prioridad'],
      'estatus'                => (string)$row['estatus'],

      'costo_mxn'              => ($row['costo_mxn'] === null) ? null : (float)$row['costo_mxn'],
      'costo_capturado_por'    => ($row['costo_capturado_por'] === null) ? null : (int)$row['costo_capturado_por'],
      'costo_capturado_at'     => $row['costo_capturado_at'],
      'costo_validado_por'     => ($row['costo_validado_por'] === null) ? null : (int)$row['costo_validado_por'],
      'costo_validado_at'      => $row['costo_validado_at'],

      'created_at'             => $row['created_at'],
      'updated_at'             => $row['updated_at'],

      // Nuevos bloques
      'revision'               => $revision,
      'costeo'                 => $costeo,

      // Timeline
      'timeline'               => $timeline,
    ]
  ]);

} catch (Throwable $e) {
  bad('error', 500, $e->getMessage());
}