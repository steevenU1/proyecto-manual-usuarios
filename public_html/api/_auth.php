<?php
// _auth.php — Autenticación por Bearer con mapeo a ORIGEN (NANO, MIPLAN, LUGA) + allowlist por IP (opcional)
// Es retro-compatible con require_bearer($issuerExpected) pero añade require_origen() para flujos multi-origen.

// =======================
//  Configura tus tokens
// =======================
// Asigna UN token distinto por origen. ¡No repitas tokens!
const API_TOKENS_BY_ORIGIN = [
  'NANO'   => '1Sp2gd3pa*1Fba23a326*',   // cámbialo
  'MIPLAN' => '1Sp2gd3pa*1Fba23a327', // cámbialo
  'LUGA'   => '1Sp2gd3pa*1Fba23a326',   // opcional (si LUGA también consume su propia API)
  // 'OTRO' => 'TOK-OTRO-xxxxx',               // opcional
];

// (Opcional) Allowlist por IP por origen. Déjalo vacío si no quieres filtrar.
const API_ALLOWLIST = [
  // 'NANO'   => ['1.2.3.4','5.6.7.8'],
  // 'MIPLAN' => ['11.22.33.44'],
  // 'LUGA'   => [],
];

// =======================
//  Utilidades internas
// =======================
function json_unauthorized(string $msgKey)
{
  http_response_code(401);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => $msgKey], JSON_UNESCAPED_UNICODE);
  exit;
}
function json_forbidden(string $msgKey)
{
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => $msgKey], JSON_UNESCAPED_UNICODE);
  exit;
}
function get_bearer(): ?string
{
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (stripos($hdr, 'Bearer ') !== 0) return null;
  return trim(substr($hdr, 7));
}
function resolve_origen_from_token(?string $bearer): ?string
{
  if (!$bearer) return null;
  // Invertimos el mapa ORIGEN->TOKEN a TOKEN->ORIGEN
  static $byToken = null;
  if ($byToken === null) {
    $byToken = [];
    foreach (API_TOKENS_BY_ORIGIN as $origin => $tok) {
      if (isset($byToken[$tok])) {
        // Configuración insegura: token duplicado
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
          'ok' => false,
          'error' => 'duplicate_token_config',
          'detail' => "Token repetido entre {$byToken[$tok]} y {$origin}"
        ], JSON_UNESCAPED_UNICODE);
        exit;
      }
      $byToken[$tok] = $origin;
    }
  }
  // Búsqueda exacta del token
  return $byToken[$bearer] ?? null;
}

// =======================
//  API pública (nueva)
// =======================

/**
 * Valida el Bearer y devuelve el ORIGEN (e.g., 'NANO', 'MIPLAN', 'LUGA').
 * Si pasas $allowedOrigins, además verifica que el origen esté en esa lista.
 * Aplica allowlist por IP si está configurada para ese origen.
 */
function require_origen(?array $allowedOrigins = null): string
{
  $bearer = get_bearer();
  if (!$bearer) json_unauthorized('missing_bearer');

  $origin = resolve_origen_from_token($bearer);
  if (!$origin) json_forbidden('invalid_token');

  // Allowlist por IP (opcional)
  $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
  $allowIps = API_ALLOWLIST[$origin] ?? [];
  if (!empty($allowIps) && !in_array($remoteIp, $allowIps, true)) {
    json_forbidden('ip_not_allowed');
  }

  if ($allowedOrigins && !in_array($origin, $allowedOrigins, true)) {
    json_forbidden('origin_not_allowed');
  }
  return $origin;
}

// =======================
//  API retro-compatible
// =======================

/**
 * Versión antigua: valida que el Bearer coincida con el token del $issuerExpected (e.g., 'NANO').
 * Úsala solo si quieres forzar un único origen. Para multi-origen usa require_origen().
 */
function require_bearer(string $issuerExpected): void
{
  $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (stripos($hdr, 'Bearer ') !== 0) {
    json_unauthorized('missing_bearer');
  }
  $token = trim(substr($hdr, 7));
  $expected = API_TOKENS_BY_ORIGIN[$issuerExpected] ?? '';
  // hash_equals evita comparaciones de tiempo variable
  if ($expected === '' || !hash_equals($expected, $token)) {
    json_forbidden('invalid_token');
  }

  // Allowlist por IP (opcional) para ese issuer
  $allow = API_ALLOWLIST[$issuerExpected] ?? [];
  if (!empty($allow)) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, $allow, true)) {
      json_forbidden('ip_not_allowed');
    }
  }
}
