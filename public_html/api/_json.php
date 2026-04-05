<?php
// _json.php — helpers JSON
function json_input(): array {
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}
function respond($payload, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload);
  exit;
}
function req_str(array $a, string $k, string $def=''): string {
  $v = $a[$k] ?? $def;
  return is_string($v) ? trim($v) : $def;
}
function req_int(array $a, string $k, int $def=0): int {
  return (int)($a[$k] ?? $def);
}
