<?php
// validar_regalo.php — Valida TAG de venta de equipo para "regalo" de accesorio.
// SIEMPRE responde JSON { ok: bool, msg?: string }

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['id_usuario'])) {
  echo json_encode(['ok' => false, 'msg' => 'Sesión expirada.'], JSON_UNESCAPED_UNICODE);
  exit;
}

require_once __DIR__ . '/db.php';

/* ---------- Robustez: forzar excepciones mysqli y capturar todo ---------- */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function jerr(string $m, int $code = 400): never {
  http_response_code($code);
  echo json_encode(['ok' => false, 'msg' => $m], JSON_UNESCAPED_UNICODE);
  exit;
}
function jok(array $extra = []): never {
  echo json_encode(['ok' => true] + $extra, JSON_UNESCAPED_UNICODE);
  exit;
}
function normalizar_tag(string $s): string {
  $s = strtoupper(trim($s));
  return preg_replace('/\s+/', ' ', $s);
}
function column_exists(mysqli $conn, string $table, string $col): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $rs = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $rs && $rs->num_rows > 0;
}

try {
  // ---------- Entradas ----------
  $tag = isset($_POST['tag_equipo']) ? normalizar_tag((string)$_POST['tag_equipo']) : '';
  $idAcc = (int)($_POST['id_producto_accesorio'] ?? 0);

  if ($tag === '' || $idAcc <= 0) {
    jerr('Parámetros incompletos.');
  }

  // ---------- 1) Buscar venta de equipo por TAG (insensible a mayúsculas/espacios) ----------
  $sqlVenta = "SELECT v.id, v.id_sucursal
                 FROM ventas v
                WHERE UPPER(TRIM(v.tag)) = UPPER(TRIM(?))
                LIMIT 1";
  $st = $conn->prepare($sqlVenta);
  $st->bind_param('s', $tag);
  $st->execute();
  $venta = $st->get_result()->fetch_assoc();

  if (!$venta) {
    jerr('No se encontró una venta de equipo con ese TAG.');
  }

  $idVentaEquipo = (int)$venta['id'];

  // ---------- 2) Obtener códigos (codigo_producto) de equipos de esa venta ----------
  $equiposCodigos = [];

  $sqlEq = "SELECT DISTINCT TRIM(p.codigo_producto) AS codigo
              FROM detalle_venta dv
              JOIN productos p ON p.id = dv.id_producto
             WHERE dv.id_venta = ?
               AND (
                    p.tipo_producto = 'Equipo'
                    OR COALESCE(p.imei1,'') <> ''
                    OR COALESCE(p.imei2,'') <> ''
               )";
  $st = $conn->prepare($sqlEq);
  $st->bind_param('i', $idVentaEquipo);
  $st->execute();
  $rs = $st->get_result();

  while ($r = $rs->fetch_assoc()) {
    $c = trim((string)($r['codigo'] ?? ''));
    if ($c !== '') {
      $equiposCodigos[] = $c;
    }
  }

  $equiposCodigos = array_values(array_unique($equiposCodigos));

  if (!$equiposCodigos) {
    jerr('La venta encontrada no tiene equipos con código de producto.');
  }

  // ---------- 3) Resolver codigo_producto del accesorio ----------
  $sqlAcc = "SELECT TRIM(codigo_producto) AS codigo
               FROM productos
              WHERE id = ?
              LIMIT 1";
  $st = $conn->prepare($sqlAcc);
  $st->bind_param('i', $idAcc);
  $st->execute();
  $acc = $st->get_result()->fetch_assoc();

  $codigoAcc = trim((string)($acc['codigo'] ?? ''));

  if ($codigoAcc === '') {
    jerr('El accesorio no tiene código de producto configurado.');
  }

  // ---------- 4) Obtener lista de modelos habilitadores del accesorio ----------
  $usaCodigoProducto = column_exists($conn, 'accesorios_regalo_modelos', 'codigo_producto');

  if ($usaCodigoProducto) {
    // Esquema nuevo: se trabaja por codigo_producto
    $sqlCfg = "SELECT codigos_equipos
                 FROM accesorios_regalo_modelos
                WHERE codigo_producto = ?
                  AND activo = 1
                LIMIT 1";
    $st = $conn->prepare($sqlCfg);
    $st->bind_param('s', $codigoAcc);
  } else {
    // Fallback de compatibilidad: esquema viejo por id_producto
    $sqlCfg = "SELECT codigos_equipos
                 FROM accesorios_regalo_modelos
                WHERE id_producto = ?
                  AND activo = 1
                LIMIT 1";
    $st = $conn->prepare($sqlCfg);
    $st->bind_param('i', $idAcc);
  }

  $st->execute();
  $row = $st->get_result()->fetch_assoc();

  $raw = trim((string)($row['codigos_equipos'] ?? ''));
  if ($raw === '') {
    jerr('El accesorio no tiene modelos habilitadores configurados.');
  }

  // Admite CSV o JSON en codigos_equipos
  $codes = [];

  if (preg_match('/^\s*\[/', $raw)) {
    // JSON
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) {
      $codes = array_map(fn($x) => trim((string)$x), $tmp);
    }
  } else {
    // CSV / líneas
    $rawNorm = str_replace(["\r\n", "\r"], "\n", $raw);
    foreach (preg_split('/[\n,]+/', $rawNorm) as $c) {
      $c = trim($c);
      if ($c !== '') {
        $codes[] = $c;
      }
    }
  }

  $codes = array_values(array_unique(array_filter($codes, fn($x) => $x !== '')));

  if (!$codes) {
    jerr('La lista de modelos habilitadores está vacía.');
  }

  // ---------- 5) Verificar intersección (al menos un modelo coincide) ----------
  $inter = array_intersect($codes, $equiposCodigos);

  if (count($inter) === 0) {
    jerr('El TAG no corresponde a un modelo habilitador para este accesorio.');
  }

  // ---------- 6) “Solo un regalo por venta” ----------
  $dup = false;

  if (column_exists($conn, 'ventas_accesorios', 'tag_equipo')) {
    $sqlDup = "SELECT 1
                 FROM ventas_accesorios
                WHERE tag_equipo = ?
                LIMIT 1";
    $st = $conn->prepare($sqlDup);
    $st->bind_param('s', $tag);
    $st->execute();
    $dup = (bool)$st->get_result()->fetch_row();
  }

  if ($dup) {
    jerr('Ese TAG ya usó su accesorio de regalo.');
  }

  // ¡Todo OK!
  jok();

} catch (Throwable $e) {
  // Respuesta JSON aun en fallo interno
  jerr('Error del servidor: ' . $e->getMessage(), 500);
}
