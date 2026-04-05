<?php
// gestionar_usuarios.php — UNIFICADO (Alta + Gestión) + Multi-tenant Luga/Subdis
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';

// ✅ Forzar excepciones MySQL (para atrapar duplicados y errores reales)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$ROL         = $_SESSION['rol'] ?? '';
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);
$MI_SUBDIS   = (int)($_SESSION['id_subdis'] ?? 0); // ✅ en tu tabla usuarios ya existe (NULL o int)

// =================== PERMISOS (núcleo) ===================
// Admin (Luga): rol Admin y SIN subdis
$permAdminLuga   = ($ROL === 'Admin' && $MI_SUBDIS === 0);
// Subdis Admin: rol Subdis_Admin y con subdis asignado
$permAdminSubdis = ($ROL === 'Subdis_Admin' && $MI_SUBDIS > 0);

// Solo lectura para otros subdis
$permLecturaSubdis = (in_array($ROL, ['Subdis_Gerente','Subdis_Ejecutivo','Subdis_Administrativo'], true) && $MI_SUBDIS > 0);

// Gerente Luga (si lo quieres conservar con permisos limitados como tu archivo anterior)
$permGerenteLuga = ($ROL === 'Gerente' && $MI_SUBDIS === 0);

// Gate de acceso general a la vista
$puedeVer = ($permAdminLuga || $permAdminSubdis || $permLecturaSubdis || $permGerenteLuga);
if (!$puedeVer) { header("Location: 403.php"); exit(); }

// =================== Helpers ===================
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function generarTemporal() {
  $alf = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%^*()-_+=';
  $len = random_int(12, 16);
  $out = '';
  for ($i=0; $i<$len; $i++) $out .= $alf[random_int(0, strlen($alf)-1)];
  return $out;
}

function normalize_spaces($s){
  $s = preg_replace('/\s+/u', ' ', trim((string)$s));
  return $s;
}
function slug_user($s){
  $s = trim(mb_strtolower((string)$s, 'UTF-8'));
  $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
  $s = preg_replace('/[^a-z0-9]+/', '', (string)$s);
  return $s;
}
function generar_username_base($nombreMayus){
  // Regla PRO: INICIAL del primer nombre + PATERNO + inicial MATERNO (si existe)
  // Ej: "JUAN PEREZ ROBLEDO" -> "jperezr"
  $parts = preg_split('/\s+/u', trim((string)$nombreMayus));
  $parts = array_values(array_filter($parts, fn($x)=>$x!=='')); 

  if (count($parts) === 0) return 'user';
  if (count($parts) === 1) {
    $base = slug_user($parts[0]);
    return $base !== '' ? $base : 'user';
  }

  $primer = $parts[0] ?? '';
  $paterno = $parts[count($parts)-2] ?? '';
  $materno = $parts[count($parts)-1] ?? '';

  $iniNom = $primer !== '' ? mb_substr($primer, 0, 1, 'UTF-8') : '';
  $base = slug_user($iniNom) . slug_user($paterno);

  if ($base === '') $base = 'user';

  if ($materno !== '' && $materno !== $paterno) {
    $ini = mb_substr($materno, 0, 1, 'UTF-8');
    $base .= slug_user($ini);
  }

  return $base;
}

function username_disponible($conn, $u){
  $st = $conn->prepare("SELECT 1 FROM usuarios WHERE LOWER(usuario)=LOWER(?) LIMIT 1");
  $st->bind_param("s", $u);
  $st->execute();
  $r = $st->get_result();
  $st->close();
  return $r && $r->num_rows === 0;
}
function generar_username_unico($conn, $nombreMayus){
  $base = generar_username_base($nombreMayus);
  $final = $base;
  $i = 1;
  while (!username_disponible($conn, $final)) {
    $i++;
    $final = $base . $i;
    if ($i > 999) break; // safety
  }
  return $final;
}

function log_usuario($conn, $actor_id, $target_id, $accion, $detalles = '') {
  $ip = $_SERVER['REMOTE_ADDR'] ?? null;
  $stmt = $conn->prepare("INSERT INTO usuarios_log (actor_id, target_id, accion, detalles, ip) VALUES (?,?,?,?,?)");
  $stmt->bind_param("iisss", $actor_id, $target_id, $accion, $detalles, $ip);
  $stmt->execute();
  $stmt->close();
}

// =================== Bitácora: crear tabla si no existe ===================
$conn->query("
  CREATE TABLE IF NOT EXISTS usuarios_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NOT NULL,
    target_id INT NOT NULL,
    accion ENUM('alta','baja','reactivar','cambiar_rol','reset_password','cambiar_sucursal') NOT NULL,
    detalles TEXT,
    ip VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (target_id),
    INDEX (actor_id),
    INDEX (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// =================== CSRF ===================
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf'];

$mensaje = "";
if (!empty($_SESSION['flash_usuario_msg'])) { $mensaje = $_SESSION['flash_usuario_msg']; unset($_SESSION['flash_usuario_msg']); }
$newCreds = null;
if (!empty($_SESSION['flash_new_user_creds'])) { $newCreds = $_SESSION['flash_new_user_creds']; unset($_SESSION['flash_new_user_creds']); }

// =================== Catálogos base ===================
$suc = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre ASC")->fetch_all(MYSQLI_ASSOC);
$subdisList = $conn->query("SELECT id, nombre_comercial FROM subdistribuidores WHERE estatus='Activo' ORDER BY nombre_comercial ASC")->fetch_all(MYSQLI_ASSOC);

// Roles permitidos por "ámbito" (depurados)
$rolesLuga   = ['Admin','Gerente','Ejecutivo','Logistica'];
$rolesSubdis = ['Subdis_Admin','Subdis_Gerente','Subdis_Ejecutivo'];

// Etiquetas amigables para UI
$rolesLabels = [
  'Admin'             => 'Administrador',
  'Gerente'           => 'Gerente',
  'Ejecutivo'         => 'Ejecutivo',
  'Logistica'         => 'Logística',
  'Subdis_Admin'      => 'Subdis_Admin',
  'Subdis_Gerente'    => 'Subdis_Gerente',
  'Subdis_Ejecutivo'  => 'Subdis_Ejecutivo',
  'Subdis_Administrativo' => 'Subdis_Administrativo',
  'GerenteZona'       => 'GerenteZona',
  'Supervisor'        => 'Supervisor',
  'Sistemas'          => 'Sistemas',
];

// =================== Funciones de SCOPE (candados) ===================
function scopeWhereUsuarios($permAdminLuga, $permAdminSubdis, $permLecturaSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL){
  // Devuelve [sqlExtra, params, types]
  if ($permAdminLuga) {
    return ["", [], ""];
  }
  if ($permAdminSubdis || $permLecturaSubdis) {
    // Solo su subdis
    return [" AND IFNULL(u.id_subdis,0)=? ", [$MI_SUBDIS], "i"];
  }
  if ($permGerenteLuga) {
    // Gerente Luga: solo su sucursal
    return [" AND u.id_sucursal=? AND IFNULL(u.id_subdis,0)=0 ", [$ID_SUCURSAL], "i"];
  }
  return [" AND 1=0 ", [], ""];
}

function puedeOperarTarget($permAdminLuga, $permAdminSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL, $target){
  // Admin Luga puede operar todo
  if ($permAdminLuga) return true;

  // Subdis Admin solo usuarios de su subdis
  if ($permAdminSubdis) {
    return ((int)($target['id_subdis'] ?? 0) === $MI_SUBDIS);
  }

  // Gerente Luga: solo ejecutivos de su sucursal
  if ($permGerenteLuga) {
    return (($target['rol'] ?? '') === 'Ejecutivo' && (int)$target['id_sucursal'] === $ID_SUCURSAL && (int)($target['id_subdis'] ?? 0) === 0);
  }

  return false;
}

// =================== EXPORT CSV (scopeado) ===================
if (isset($_GET['export']) && $_GET['export'] === 'activos_csv') {
  if (!$puedeVer) { header("Location: 403.php"); exit(); }

  [$extra, $p, $t] = scopeWhereUsuarios($permAdminLuga, $permAdminSubdis, $permLecturaSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL);

  $sql = "
    SELECT u.id, u.nombre, u.usuario, u.rol, u.id_sucursal, s.nombre AS sucursal_nombre,
           IFNULL(u.id_subdis,0) AS id_subdis,
           sd.nombre_comercial AS subdis_nombre
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN subdistribuidores sd ON sd.id = u.id_subdis
    WHERE u.activo=1
    $extra
    ORDER BY IFNULL(sd.nombre_comercial,''), s.nombre ASC, u.nombre ASC
  ";

  $stmt = $conn->prepare($sql);
  if ($t !== "") $stmt->bind_param($t, ...$p);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  $filename = 'usuarios_activos_' . date('Ymd_His') . '.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  echo "\xEF\xBB\xBF";

  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID','Nombre','Usuario','Rol','ID Sucursal','Sucursal','ID Subdis','Subdistribuidor']);
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['id'],
      $r['nombre'],
      $r['usuario'],
      $r['rol'],
      $r['id_sucursal'],
      $r['sucursal_nombre'] ?? '',
      $r['id_subdis'],
      $r['subdis_nombre'] ?? ''
    ]);
  }
  fclose($out);
  exit;
}

// =================== AJAX: SUGERIR USUARIO (preview + disponibilidad) ===================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'sugerir_usuario') {
  header('Content-Type: application/json; charset=UTF-8');

  $nombreRaw = normalize_spaces($_GET['nombre'] ?? '');
  $nombreMay = mb_strtoupper($nombreRaw, 'UTF-8');

  if ($nombreMay === '') {
    echo json_encode(['ok'=>false,'msg'=>'Nombre vacío.'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // ¿Nombre duplicado?
  $stn = $conn->prepare("SELECT COUNT(*) FROM usuarios WHERE TRIM(nombre)=TRIM(?)");
  $stn->bind_param("s", $nombreMay);
  $stn->execute();
  $stn->bind_result($exNombre);
  $stn->fetch();
  $stn->close();

  $base = generar_username_base($nombreMay);
  $sugerido = generar_username_unico($conn, $nombreMay);
  $baseDisponible = (strcasecmp($base, $sugerido) === 0);

  echo json_encode([
    'ok' => true,
    'nombre' => $nombreMay,
    'base' => $base,
    'sugerido' => $sugerido,
    'base_disponible' => $baseDisponible,
    'nombre_existe' => ((int)$exNombre > 0)
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// =================== AJAX: SUELDO SUGERIDO (moda por rol) ===================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'sueldo_sugerido') {
  header('Content-Type: application/json; charset=UTF-8');

  $rol = trim((string)($_GET['rol'] ?? ''));
  $propiedad = strtoupper(trim((string)($_GET['propiedad'] ?? 'LUGA'))); // LUGA o SUBDISTRIBUIDOR

  if ($rol === '') {
    echo json_encode(['ok'=>false,'msg'=>'Rol vacío'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $rolesPermitidos = array_merge($rolesLuga ?? [], $rolesSubdis ?? []);
  if (!in_array($rol, $rolesPermitidos, true)) {
    echo json_encode(['ok'=>false,'msg'=>'Rol inválido'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Si es SUBDISTRIBUIDOR => sueldo siempre 0
  if ($propiedad !== 'LUGA') {
    echo json_encode(['ok'=>true,'sugerido'=>0,'conteo'=>0,'msg'=>'SUBDIS'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Moda de sueldo por rol (solo ACTIVOS, LUGA, sueldo>0)
  $sql = "
    SELECT u.sueldo, COUNT(*) AS c
    FROM usuarios u
    WHERE u.activo=1
      AND u.rol=?
      AND IFNULL(u.id_subdis,0)=0
      AND u.sueldo IS NOT NULL
      AND u.sueldo > 0
    GROUP BY u.sueldo
    ORDER BY c DESC, u.sueldo DESC
    LIMIT 1
  ";
  $st = $conn->prepare($sql);
  $st->bind_param("s", $rol);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$row) {
    echo json_encode(['ok'=>true,'sugerido'=>null,'conteo'=>0], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode([
    'ok'=>true,
    'sugerido'=>(float)$row['sueldo'],
    'conteo'=>(int)$row['c']
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// =================== AJAX: DETALLE DE USUARIO (modal) ===================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detalle_usuario') {
  header('Content-Type: application/json; charset=UTF-8');

  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) { echo json_encode(['ok'=>false,'html'=>'<div class="alert alert-danger mb-0">ID inválido.</div>']); exit; }

  [$extra, $params, $types] = scopeWhereUsuarios($permAdminLuga, $permAdminSubdis, $permLecturaSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL);

  $sql = "
    SELECT u.*,
           s.nombre AS sucursal_nombre,
           sd.nombre_comercial AS subdis_nombre,
           c.nombre AS creador_nombre,
           c.usuario AS creador_usuario
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN subdistribuidores sd ON sd.id = u.id_subdis
    LEFT JOIN usuarios c ON c.id = u.creado_por
    WHERE u.id=? $extra
    LIMIT 1
  ";
  $p = [$id];
  $t = "i";
  if ($types !== "") { $t .= $types; $p = array_merge($p, $params); }

  $st = $conn->prepare($sql);
  $st->bind_param($t, ...$p);
  $st->execute();
  $u = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$u) { echo json_encode(['ok'=>false,'html'=>'<div class="alert alert-warning mb-0">Usuario no encontrado o sin permiso.</div>']); exit; }

  $st = $conn->prepare("
    SELECT l.id, l.created_at, l.accion, l.detalles, l.ip,
           a.nombre AS actor_nombre, a.usuario AS actor_user
    FROM usuarios_log l
    LEFT JOIN usuarios a ON a.id = l.actor_id
    WHERE l.target_id=?
    ORDER BY l.id DESC
    LIMIT 200
  ");
  $st->bind_param("i", $id);
  $st->execute();
  $logsUser = $st->get_result()->fetch_all(MYSQLI_ASSOC);
  $st->close();

  $creado_en = isset($u['creado_en']) && $u['creado_en'] ? $u['creado_en'] : null;
  $creado_por = isset($u['creado_por']) ? (int)$u['creado_por'] : 0;

  $badgeMap = [
    'alta'             => 'bg-success',
    'baja'             => 'bg-danger',
    'reactivar'        => 'bg-success',
    'cambiar_rol'      => 'bg-warning text-dark',
    'reset_password'   => 'bg-info text-dark',
    'cambiar_sucursal' => 'bg-primary',
  ];

  $copyLines = [];
  foreach ($logsUser as $l) {
    $copyLines[] = sprintf(
      "%s | %s | actor:%s (%s) | %s | ip:%s",
      $l['created_at'] ?? '',
      $l['accion'] ?? '',
      $l['actor_nombre'] ?? '-',
      $l['actor_user'] ?? '',
      preg_replace('/\s+/', ' ', trim((string)($l['detalles'] ?? ''))),
      $l['ip'] ?? ''
    );
  }
  $copyText = implode("\n", $copyLines);

  ob_start(); ?>
  <div class="row g-3">
    <div class="col-12">
      <div class="p-3 rounded-4 bg-light border">
        <div class="d-flex align-items-start justify-content-between gap-3">
          <div>
            <div class="h5 mb-1"><?= h($u['nombre'] ?? '') ?></div>
            <div class="text-muted small">
              <span class="me-2"><i class="bi bi-person-badge"></i> <?= h($u['usuario'] ?? '') ?></span>
              <span class="me-2"><i class="bi bi-shield-check"></i> <?= h($rolesLabels[$u['rol']] ?? ($u['rol'] ?? '')) ?></span>
              <span class="me-2"><i class="bi bi-shop"></i> <?= h($u['sucursal_nombre'] ?? '-') ?></span>
              <span class="me-2"><i class="bi bi-building"></i> <?= h((int)($u['id_subdis'] ?? 0) ? ($u['subdis_nombre'] ?? ('SUBDIS #'.$u['id_subdis'])) : 'LUGA') ?></span>
              <span class="badge <?= ((int)($u['activo'] ?? 0)===1?'bg-success':'bg-secondary') ?> ms-1"><?= ((int)($u['activo'] ?? 0)===1?'Activo':'Inactivo') ?></span>
            </div>
          </div>
          <div class="text-end">
            <div class="text-muted small">ID</div>
            <div class="fw-semibold">#<?= (int)$u['id'] ?></div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card border-0 rounded-4 shadow-sm h-100">
        <div class="card-body">
          <div class="fw-semibold mb-2"><i class="bi bi-clock-history me-1"></i> Creación</div>
          <div class="small text-muted">Creado en</div>
          <div class="mb-2"><?= $creado_en ? h($creado_en) : '<span class="text-muted">No disponible</span>' ?></div>

          <div class="small text-muted">Creado por</div>
          <div>
            <?php if ($creado_por > 0): ?>
              <?= h($u['creador_nombre'] ?? ('Usuario #'.$creado_por)) ?>
              <span class="text-muted">(<?= h($u['creador_usuario'] ?? '') ?>)</span>
            <?php else: ?>
              <span class="text-muted">No disponible</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card border-0 rounded-4 shadow-sm h-100">
        <div class="card-body">
          <div class="fw-semibold mb-2"><i class="bi bi-info-circle me-1"></i> Detalles</div>
          <div class="row g-2 small">
            <div class="col-6 text-muted">Sucursal ID</div><div class="col-6 text-end"><?= (int)($u['id_sucursal'] ?? 0) ?></div>
            <div class="col-6 text-muted">Subdis ID</div><div class="col-6 text-end"><?= (int)($u['id_subdis'] ?? 0) ?></div>
            <?php if (isset($u['sueldo'])): ?>
              <div class="col-6 text-muted">Sueldo</div><div class="col-6 text-end">$<?= number_format((float)$u['sueldo'], 2) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card border-0 rounded-4 shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="fw-semibold"><i class="bi bi-activity me-1"></i> Actividad reciente</div>
            <div class="d-flex align-items-center gap-2">
              <div class="text-muted small">Últimos <?= count($logsUser) ?> movimientos</div>
              <button type="button" class="btn btn-outline-secondary btn-sm" onclick="copyUserHistory()">
                <i class="bi bi-clipboard-check me-1"></i> Copiar historial
              </button>
            </div>
          </div>

          <textarea id="historialCopyText" class="d-none"><?= h($copyText) ?></textarea>

          <?php if (!$logsUser): ?>
            <div class="text-center text-muted py-3">Sin movimientos.</div>
          <?php else: ?>
            <div class="mt-3" style="position:relative;">
              <div style="position:absolute; left:14px; top:8px; bottom:8px; width:2px; background:rgba(0,0,0,.08);"></div>
              <?php foreach ($logsUser as $l):
                $acc = (string)($l['accion'] ?? '');
                $bCls = $badgeMap[$acc] ?? 'bg-light text-dark border';
                $det = trim((string)($l['detalles'] ?? ''));
              ?>
                <div class="d-flex gap-3 mb-3">
                  <div class="flex-shrink-0" style="width:30px;">
                    <div class="rounded-circle bg-white border" style="width:18px;height:18px;margin-left:5px;margin-top:3px;"></div>
                  </div>
                  <div class="flex-grow-1">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                      <div class="d-flex align-items-center gap-2">
                        <span class="badge <?= h($bCls) ?>"><?= h($acc) ?></span>
                        <span class="text-muted small"><?= h($l['created_at'] ?? '') ?></span>
                        <span class="text-muted small">·</span>
                        <span class="small">
                          <?= h($l['actor_nombre'] ?? '-') ?> <span class="text-muted">(<?= h($l['actor_user'] ?? '') ?>)</span>
                        </span>
                      </div>
                      <div class="text-muted small">IP: <?= h($l['ip'] ?? '') ?></div>
                    </div>
                    <?php if ($det !== ''): ?>
                      <div class="mt-1 small" style="white-space:normal;">
                        <?= nl2br(h($det)) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php
  $html = ob_get_clean();
  echo json_encode(['ok'=>true,'html'=>$html], JSON_UNESCAPED_UNICODE);
  exit;
}

// =================== POST Actions (Alta + Operaciones) ===================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {

  if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
    $mensaje = "<div class='alert alert-danger'>❌ Token inválido. Recarga la página.</div>";
  } else {

    $accion = $_POST['accion'];

    try {
      $conn->begin_transaction();

      // ===== ACCIÓN: ALTA DE USUARIO =====
      if ($accion === 'crear_usuario') {

        if (!$permAdminLuga && !$permAdminSubdis) {
          throw new Exception("No tienes permisos para crear usuarios.");
        }

        $nombre      = normalize_spaces($_POST['nombre'] ?? '');
        $nombre      = mb_strtoupper($nombre, 'UTF-8');

        // ✅ CANDADO BACKEND: SOLO A-Z y espacios
        if (!preg_match('/^[A-Z ]+$/', $nombre)) {
          throw new Exception("El nombre solo puede contener letras A-Z y espacios. Sin acentos, sin Ñ y sin caracteres especiales.");
        }

        $usuario     = '';
        $password    = '';
        $id_sucursal = (int)($_POST['id_sucursal'] ?? 0);
        $rolNuevo    = trim($_POST['rol'] ?? '');
        $sueldo      = (float)($_POST['sueldo'] ?? 0);

        // Determinar propiedad del nuevo usuario
        $propiedadForm = strtoupper(trim((string)($_POST['propiedad'] ?? ''))); // LUGA | SUBDISTRIBUIDOR
        $idSubdisForm  = (int)($_POST['id_subdis'] ?? 0);

        if ($nombre === '' || $id_sucursal <= 0 || $rolNuevo === '') {
          throw new Exception("Nombre, Sucursal y Rol son obligatorios.");
        }

        // No duplicados por nombre
        $stn = $conn->prepare("SELECT COUNT(*) FROM usuarios WHERE TRIM(nombre)=TRIM(?)");
        $stn->bind_param("s", $nombre);
        $stn->execute();
        $stn->bind_result($exNombre);
        $stn->fetch();
        $stn->close();
        if ((int)$exNombre > 0) throw new Exception("Ya existe un usuario con el nombre <b>" . h($nombre) . "</b>.");

        // Generar usuario único + contraseña temporal
        $usuario = generar_username_unico($conn, $nombre);
        $tempGen = generarTemporal();
        $password = $tempGen;

        // Resolver id_subdis a asignar
        $id_subdis_nuevo = 0;

        if ($permAdminSubdis) {
          // Subdis_Admin SOLO puede crear dentro de su subdis
          $id_subdis_nuevo = $MI_SUBDIS;

          if (!in_array($rolNuevo, $rolesSubdis, true)) {
            throw new Exception("Como Subdistribuidor, solo puedes crear roles SUBDIS.");
          }

          $propiedadForm = 'SUBDISTRIBUIDOR';
        } else {
          // Admin Luga puede crear LUGA o SUBDISTRIBUIDOR
          if (!in_array($propiedadForm, ['LUGA', 'SUBDISTRIBUIDOR'], true)) {
            throw new Exception("Selecciona una propiedad válida.");
          }

          if ($propiedadForm === 'LUGA') {
            $id_subdis_nuevo = 0;

            if (!in_array($rolNuevo, $rolesLuga, true)) {
              throw new Exception("Para LUGA solo puedes asignar: Administrador, Gerente, Ejecutivo o Logística.");
            }
          } else {
            // SUBDISTRIBUIDOR
            if ($idSubdisForm <= 0) {
              throw new Exception("Selecciona el Subdistribuidor.");
            }

            $id_subdis_nuevo = $idSubdisForm;

            if (!in_array($rolNuevo, $rolesSubdis, true)) {
              throw new Exception("Para SUBDISTRIBUIDOR solo puedes asignar: Subdis_Admin, Subdis_Gerente o Subdis_Ejecutivo.");
            }
          }
        }

        // Sueldo: LUGA requiere >0; SUBDIS forzamos 0
        if ($id_subdis_nuevo > 0) {
          $sueldo = 0;
        } else {
          if ($sueldo <= 0) {
            throw new Exception("El sueldo debe ser mayor a 0 para cuentas LUGA.");
          }
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $must_change_password = 1;

        $usuarioBase = generar_username_base($nombre);
        $usuario     = generar_username_unico($conn, $nombre);
        $nuevoId     = 0;

        $maxRetry = 30;
        for ($try=1; $try<=$maxRetry; $try++) {

          if ($id_subdis_nuevo > 0) {
            $stmt = $conn->prepare("
              INSERT INTO usuarios (nombre, usuario, password, id_sucursal, rol, sueldo, must_change_password, id_subdis, activo, creado_por)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
            ");
            $stmt->bind_param("sssissiii", $nombre, $usuario, $hash, $id_sucursal, $rolNuevo, $sueldo, $must_change_password, $id_subdis_nuevo, $ID_USUARIO);
          } else {
            $stmt = $conn->prepare("
              INSERT INTO usuarios (nombre, usuario, password, id_sucursal, rol, sueldo, must_change_password, id_subdis, activo, creado_por)
              VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 1, ?)
            ");
            $stmt->bind_param("sssissii", $nombre, $usuario, $hash, $id_sucursal, $rolNuevo, $sueldo, $must_change_password, $ID_USUARIO);
          }

          try {
            $stmt->execute();
            $nuevoId = (int)$stmt->insert_id;
            $stmt->close();
            break;
          } catch (mysqli_sql_exception $e) {
            $stmt->close();

            $isDup = ((int)$e->getCode() === 1062);

            if ($isDup) {
              $usuario = $usuarioBase . ($try + 1);
              continue;
            }

            throw $e;
          }
        }

        if ($nuevoId <= 0) {
          throw new Exception("No se pudo crear el usuario (colisiones repetidas). Intenta de nuevo.");
        }

        log_usuario($conn, $ID_USUARIO, $nuevoId, 'alta', "Rol: $rolNuevo | Sucursal: $id_sucursal | id_subdis: ".($id_subdis_nuevo?:'NULL'));

        $conn->commit();

        $_SESSION['flash_new_user_creds'] = [
          'nombre'  => $nombre,
          'usuario' => $usuario,
          'password'=> $tempGen
        ];

        $mensaje = "<div class='alert alert-success'>✅ Usuario <b>".h($usuario)."</b> creado correctamente. Se generó una contraseña temporal (se mostrará una sola vez).</div>";
      }

      // ===== ACCIONES SOBRE USUARIOS EXISTENTES =====
      else {

        if ($permLecturaSubdis) {
          throw new Exception("No tienes permisos para ejecutar acciones sobre usuarios.");
        }

        $usuario_id = (int)($_POST['usuario_id'] ?? 0);
        if ($usuario_id <= 0) throw new Exception("Usuario inválido.");

        $stmt = $conn->prepare("SELECT id, nombre, rol, id_sucursal, activo, IFNULL(id_subdis,0) AS id_subdis FROM usuarios WHERE id=? LIMIT 1");
        $stmt->bind_param("i", $usuario_id);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$target) throw new Exception("Usuario no encontrado.");

        if ((int)$target['id'] === $ID_USUARIO) {
          throw new Exception("No puedes operar sobre tu propia cuenta.");
        }

        if (!puedeOperarTarget($permAdminLuga, $permAdminSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL, $target)) {
          throw new Exception("Permisos insuficientes para operar a este usuario (scope).");
        }

        if ($accion === 'baja') {

          $stmt = $conn->prepare("UPDATE usuarios SET activo=0 WHERE id=?");
          $stmt->bind_param("i", $usuario_id);
          $stmt->execute();
          $stmt->close();

          log_usuario($conn, $ID_USUARIO, $usuario_id, 'baja', "Baja de usuario");
          $conn->commit();
          $mensaje = "<div class='alert alert-success'>✅ Usuario dado de baja.</div>";

        } elseif ($accion === 'reactivar') {

          $stmt = $conn->prepare("UPDATE usuarios SET activo=1 WHERE id=?");
          $stmt->bind_param("i", $usuario_id);
          $stmt->execute();
          $stmt->close();

          log_usuario($conn, $ID_USUARIO, $usuario_id, 'reactivar', "Reactivación de cuenta");
          $conn->commit();
          $mensaje = "<div class='alert alert-success'>✅ Usuario reactivado.</div>";

        } elseif ($accion === 'cambiar_rol') {

          if (!$permAdminLuga && !$permAdminSubdis) throw new Exception("Solo un admin puede cambiar roles.");

          $nuevo_rol = trim($_POST['nuevo_rol'] ?? '');
          if ($nuevo_rol === '') throw new Exception("Selecciona un rol válido.");

          $targetSub = (int)$target['id_subdis'];

          if ($targetSub === 0) {
            if (!in_array($nuevo_rol, $rolesLuga, true)) throw new Exception("Rol no válido para usuario LUGA.");
          } else {
            if (!in_array($nuevo_rol, $rolesSubdis, true)) throw new Exception("Rol no válido para usuario SUBDISTRIBUIDOR.");
          }

          $stmt = $conn->prepare("UPDATE usuarios SET rol=? WHERE id=?");
          $stmt->bind_param("si", $nuevo_rol, $usuario_id);
          $stmt->execute();
          $stmt->close();

          log_usuario($conn, $ID_USUARIO, $usuario_id, 'cambiar_rol', "Nuevo rol: $nuevo_rol (antes: {$target['rol']})");
          $conn->commit();
          $mensaje = "<div class='alert alert-success'>✅ Rol actualizado a <b>".h($rolesLabels[$nuevo_rol] ?? $nuevo_rol)."</b>.</div>";

        } elseif ($accion === 'cambiar_sucursal') {

          if (!$permAdminLuga && !$permAdminSubdis) throw new Exception("Solo un admin puede cambiar sucursal.");

          $nueva_sucursal = (int)($_POST['nueva_sucursal'] ?? 0);
          if ($nueva_sucursal <= 0) throw new Exception("Selecciona una sucursal válida.");

          $stmt = $conn->prepare("SELECT id, nombre FROM sucursales WHERE id=? LIMIT 1");
          $stmt->bind_param("i", $nueva_sucursal);
          $stmt->execute();
          $sucRow = $stmt->get_result()->fetch_assoc();
          $stmt->close();
          if (!$sucRow) throw new Exception("La sucursal seleccionada no existe.");

          $stmt = $conn->prepare("UPDATE usuarios SET id_sucursal=? WHERE id=?");
          $stmt->bind_param("ii", $nueva_sucursal, $usuario_id);
          $stmt->execute();
          $stmt->close();

          log_usuario($conn, $ID_USUARIO, $usuario_id, 'cambiar_sucursal', "Nueva sucursal: {$sucRow['nombre']} (ID $nueva_sucursal)");
          $conn->commit();
          $mensaje = "<div class='alert alert-success'>🏬 Sucursal actualizada.</div>";

        } elseif ($accion === 'reset_password') {

          $ok = $permAdminLuga || $permAdminSubdis || $permGerenteLuga;
          if (!$ok) throw new Exception("Permisos insuficientes para resetear contraseña.");

          if ($permGerenteLuga) {
            if (!(($target['rol'] ?? '')==='Ejecutivo' && (int)$target['id_sucursal']===$ID_SUCURSAL && (int)$target['id_subdis']===0)) {
              throw new Exception("Como Gerente solo puedes resetear ejecutivos de tu sucursal (LUGA).");
            }
          }

          $temp = generarTemporal();
          $hash = password_hash($temp, PASSWORD_DEFAULT);

          $stmt = $conn->prepare("UPDATE usuarios SET password=?, must_change_password=1, last_password_reset_at=NOW() WHERE id=?");
          $stmt->bind_param("si", $hash, $usuario_id);
          $stmt->execute();
          $stmt->close();

          log_usuario($conn, $ID_USUARIO, $usuario_id, 'reset_password', "Se generó contraseña temporal");
          $conn->commit();

          $mensaje = "<div class='alert alert-warning'>🔐 Contraseña temporal generada:
                      <code style='user-select:all'>".h($temp)."</code><br>
                      * Se le pedirá cambiarla al iniciar sesión.
                      </div>";
        } else {
          throw new Exception("Acción no válida.");
        }
      }

    } catch (Throwable $e) {
      $conn->rollback();
      $mensaje = "<div class='alert alert-danger'>❌ ".$e->getMessage()."</div>";
    }
  }
}

// Si la acción fue exitosa, guardamos mensaje y regresamos a la pestaña actual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($mensaje) && strpos($mensaje, "alert-success") !== false) {
  $_SESSION['flash_usuario_msg'] = $mensaje;
  $tabReturn = $_POST['tab_return'] ?? 'activos';
  $tabReturn = in_array($tabReturn, ['activos','inactivos','bitacora'], true) ? $tabReturn : 'activos';
  header("Location: gestionar_usuarios.php?tab=".$tabReturn);
  exit;
}

// =================== Listados (scopeados) ===================
$tab = $_GET['tab'] ?? 'activos';
$tab = in_array($tab, ['activos','inactivos','bitacora'], true) ? $tab : 'activos';

$busq = trim($_GET['q'] ?? '');
$frol = trim($_GET['rol'] ?? '');
$fsuc = (int)($_GET['suc'] ?? 0);

function cargarUsuarios($conn, $activo, $busq, $frol, $fsuc, $extra, $params, $types) {
  $sql = "
    SELECT u.id, u.nombre, u.usuario, u.rol, u.id_sucursal, u.activo,
           IFNULL(u.id_subdis,0) AS id_subdis,
           s.nombre AS sucursal_nombre,
           sd.nombre_comercial AS subdis_nombre
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN subdistribuidores sd ON sd.id = u.id_subdis
    WHERE u.activo=? $extra
  ";
  $p = [$activo];
  $t = "i";

  if ($busq !== '') {
    $sql .= " AND (u.nombre LIKE CONCAT('%',?,'%') OR u.usuario LIKE CONCAT('%',?,'%'))";
    $p[] = $busq; $p[] = $busq; $t .= "ss";
  }
  if ($frol !== '') {
    $sql .= " AND u.rol=?";
    $p[] = $frol; $t .= "s";
  }
  if ($fsuc > 0) {
    $sql .= " AND u.id_sucursal=?";
    $p[] = $fsuc; $t .= "i";
  }

  if ($types !== "") { $t .= $types; $p = array_merge($p, $params); }

  $sql .= " ORDER BY IFNULL(sd.nombre_comercial,''), s.nombre ASC, u.nombre ASC";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($t, ...$p);
  $stmt->execute();
  $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  return $data;
}

[$scopeExtra, $scopeParams, $scopeTypes] = scopeWhereUsuarios($permAdminLuga, $permAdminSubdis, $permLecturaSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL);

$usuariosActivos   = cargarUsuarios($conn, 1, $busq, $frol, $fsuc, $scopeExtra, $scopeParams, $scopeTypes);
$usuariosInactivos = cargarUsuarios($conn, 0, $busq, $frol, $fsuc, $scopeExtra, $scopeParams, $scopeTypes);

// Logs (scopeados)
$logLimit = 250;
if ($permAdminLuga) {
  $stmt = $conn->prepare("
    SELECT l.id, l.created_at, l.accion, l.detalles, l.ip,
           a.nombre AS actor_nombre,
           t.nombre AS target_nombre, t.usuario AS target_user
    FROM usuarios_log l
    LEFT JOIN usuarios a ON a.id = l.actor_id
    LEFT JOIN usuarios t ON t.id = l.target_id
    ORDER BY l.id DESC
    LIMIT ?
  ");
  $stmt->bind_param("i", $logLimit);
} else {
  $stmt = $conn->prepare("
    SELECT l.id, l.created_at, l.accion, l.detalles, l.ip,
           a.nombre AS actor_nombre,
           t.nombre AS target_nombre, t.usuario AS target_user
    FROM usuarios_log l
    LEFT JOIN usuarios a ON a.id = l.actor_id
    LEFT JOIN usuarios t ON t.id = l.target_id
    WHERE IFNULL(t.id_subdis,0)=?
    ORDER BY l.id DESC
    LIMIT ?
  ");
  $stmt->bind_param("ii", $MI_SUBDIS, $logLimit);
}
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Roles para filtros
$rolesFiltro = $permAdminLuga ? array_merge($rolesLuga, $rolesSubdis) : $rolesSubdis;

// KPIs
$kpiActivos    = count($usuariosActivos);
$kpiInactivos  = count($usuariosInactivos);
$kpiSucursales = count($suc);
$kpiLogs       = count($logs);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Usuarios (Alta y Gestión)</title>
  <link rel="icon" type="image/x-icon" href="./img/favicon.ico">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root{ --brand:#0d6efd; --brand-100: rgba(13,110,253,.08); }
    body.bg-light{
      background:
        radial-gradient(1100px 420px at 110% -80%, var(--brand-100), transparent),
        radial-gradient(1100px 420px at -10% 120%, rgba(25,135,84,.06), transparent),
        #f8fafc;
    }
    .page-title{
      border:0; border-radius:1rem;
      background: linear-gradient(135deg, #22c55e 0%, #0ea5e9 55%, #6366f1 100%);
      color:#fff; padding:1rem 1.25rem;
      box-shadow: 0 20px 45px rgba(2,8,20,.12), 0 3px 10px rgba(2,8,20,.06);
    }
    .card-elev{ border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(2,8,20,.06), 0 2px 8px rgba(2,8,20,.05); }
    .kpi-card{ border:0; border-radius:1rem; box-shadow:0 10px 24px rgba(2,8,20,.06), 0 2px 8px rgba(2,8,20,.05); }
    .kpi-icon{ width:42px; height:42px; display:inline-grid; place-items:center; border-radius:12px; background:#eef2ff; color:#1e40af; }
    .badge-role{
      background:#e9eefb;
      color:#111 !important;
      border:1px solid #cbd5e1;
      font-weight:600;
      padding:.35rem .6rem;
    }
    .table-sm td, .table-sm th{vertical-align: middle;}
  </style>
</head>
<body class="bg-light">

<?php if (file_exists(__DIR__.'/navbar.php')) include __DIR__.'/navbar.php'; ?>

<div class="container my-4">

  <div class="page-title mb-3 d-flex flex-wrap justify-content-between align-items-end">
    <div>
      <h2 class="mb-1">👥 Usuarios</h2>
      <div class="opacity-75">
        Alta y Gestión. Tu rol: <b><?= h($ROL) ?></b>
        <?php if ($MI_SUBDIS>0): ?>
          <span class="ms-2 badge bg-dark">SUBDIS #<?= (int)$MI_SUBDIS ?></span>
        <?php else: ?>
          <span class="ms-2 badge bg-dark">LUGA</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-success" href="?export=activos_csv">
        <i class="bi bi-download me-1"></i> Exportar activos (CSV)
      </a>
    </div>
  </div>

  <?= $mensaje ?>

  <!-- KPIs -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
      <div class="kpi-card p-3 bg-white h-100">
        <div class="d-flex align-items-center gap-3">
          <div class="kpi-icon"><i class="bi bi-people"></i></div>
          <div><div class="text-muted small">Activos</div><div class="h5 mb-0"><?= $kpiActivos ?></div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi-card p-3 bg-white h-100">
        <div class="d-flex align-items-center gap-3">
          <div class="kpi-icon" style="background:#fff7ed; color:#9a3412;"><i class="bi bi-person-dash"></i></div>
          <div><div class="text-muted small">Inactivos</div><div class="h5 mb-0"><?= $kpiInactivos ?></div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi-card p-3 bg-white h-100">
        <div class="d-flex align-items-center gap-3">
          <div class="kpi-icon" style="background:#ecfeff; color:#155e75;"><i class="bi bi-shop"></i></div>
          <div><div class="text-muted small">Sucursales</div><div class="h5 mb-0"><?= $kpiSucursales ?></div></div>
        </div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="kpi-card p-3 bg-white h-100">
        <div class="d-flex align-items-center gap-3">
          <div class="kpi-icon" style="background:#f0fdf4; color:#166534;"><i class="bi bi-activity"></i></div>
          <div><div class="text-muted small">Movimientos</div><div class="h5 mb-0"><?= $kpiLogs ?></div></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabs + Acción -->
  <div class="d-flex align-items-center justify-content-between card-elev bg-white px-3 pt-3" style="border-bottom:0; border-radius:16px 16px 0 0;">
    <ul class="nav nav-tabs" role="tablist" style="border-bottom:0;">
      <li class="nav-item" role="presentation">
        <a class="nav-link <?= ($tab==='activos'?'active':'') ?>" href="?tab=activos">
          Activos (<?= $kpiActivos ?>)
        </a>
      </li>
      <li class="nav-item" role="presentation">
        <a class="nav-link <?= ($tab==='inactivos'?'active':'') ?>" href="?tab=inactivos">
          Inactivos (<?= $kpiInactivos ?>)
        </a>
      </li>
      <li class="nav-item" role="presentation">
        <a class="nav-link <?= ($tab==='bitacora'?'active':'') ?>" href="?tab=bitacora">
          Bitácora
        </a>
      </li>
    </ul>

    <?php if ($permAdminLuga || $permAdminSubdis): ?>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearUsuario">
        <i class="bi bi-person-plus me-1"></i> Crear usuario
      </button>
    <?php else: ?>
      <button class="btn btn-primary" disabled title="Solo admin puede crear usuarios">
        <i class="bi bi-person-plus me-1"></i> Crear usuario
      </button>
    <?php endif; ?>
  </div>

  <div class="tab-content card-elev bg-white mt-2">

    <!-- =================== CREAR USUARIO (MODAL) =================== -->
    <div class="modal fade" id="modalCrearUsuario" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Crear usuario</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
      <?php if (!$permAdminLuga && !$permAdminSubdis): ?>
            <div class="alert alert-warning mb-0">⚠️ Solo un admin puede crear usuarios.</div>
          <?php else: ?>
            <form method="post" class="row g-3">
              <input type="hidden" name="accion" value="crear_usuario">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="tab_return" value="<?= h($tab) ?>">

              <!-- 1) Nombre primero (izq) + Acceso (der) -->
              <div class="col-md-6">
                <label class="form-label">Nombre completo</label>
                <input type="text" name="nombre" id="crear_nombre" class="form-control" required autocomplete="off" placeholder="Ej. JUAN PEREZ ROBLEDO">
                <div class="form-text text-danger d-none" id="nombre_invalid_msg">
                  Solo letras A-Z y espacios. Sin acentos (ÁÉÍÓÚ), sin Ñ y sin caracteres especiales.
                </div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Acceso</label>
                <div class="form-control bg-light" style="height:auto">
                  <div class="small text-muted mb-1">Se generan automáticamente al guardar</div>
                  <div class="d-flex flex-wrap gap-2 mb-2">
                    <span class="badge text-bg-primary">usuario: auto</span>
                    <span class="badge text-bg-secondary">contraseña: temporal</span>
                  </div>

                  <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="text-muted small">Usuario sugerido:</span>
                    <code class="px-2 py-1 rounded bg-white border" id="preview_usuario_final">—</code>
                    <small class="text-muted ms-2 d-none" id="nota_usuario_ajuste"></small>
                    <span class="badge text-bg-success" id="badge_usuario_estado">Disponible</span>
                    <span class="badge text-bg-danger d-none" id="badge_nombre_dup">Nombre duplicado</span>
                  </div>

                  <div class="small text-muted mt-2" id="msg_usuario_preview"></div>
                </div>
              </div>

              <!-- 2) Propiedad -->
              <div class="col-md-4">
                <label class="form-label">Propiedad</label>
                <?php if ($permAdminLuga): ?>
                  <select name="propiedad" id="propiedad_select" class="form-select" required>
                    <option value="">-- Selecciona --</option>
                    <option value="LUGA">LUGA</option>
                    <option value="SUBDISTRIBUIDOR">SUBDISTRIBUIDOR</option>
                  </select>
                  <div class="form-text">Primero selecciona la propiedad para filtrar roles.</div>
                <?php else: ?>
                  <input type="hidden" name="propiedad" id="propiedad_select" value="SUBDISTRIBUIDOR">
                  <div class="form-control bg-light">SUBDISTRIBUIDOR</div>
                <?php endif; ?>
              </div>

              <!-- 3) Rol -->
              <div class="col-md-4">
                <label class="form-label">Rol</label>
                <select name="rol" id="rol_select" class="form-select" required>
                  <option value="">-- Selecciona una propiedad primero --</option>
                </select>
                <div class="form-text" id="rol_hint">
                  Selecciona la propiedad para mostrar roles válidos.
                </div>
              </div>

              <!-- 4) Subdistribuidor -->
              <div class="col-md-4" id="wrap_subdis" style="display:none;">
                <label class="form-label">Subdistribuidor</label>
                <?php if ($permAdminLuga): ?>
                  <select name="id_subdis" id="crear_id_subdis" class="form-select">
                    <option value="">-- Selecciona --</option>
                    <?php foreach ($subdisList as $sd): ?>
                      <option value="<?= (int)$sd['id'] ?>"><?= h($sd['nombre_comercial']) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <input type="hidden" name="id_subdis" id="crear_id_subdis" value="<?= (int)$MI_SUBDIS ?>">
                  <div class="form-control bg-light">SUBDIS #<?= (int)$MI_SUBDIS ?></div>
                <?php endif; ?>
              </div>

              <!-- 5) Sucursal -->
              <div class="col-md-6">
                <label class="form-label">Sucursal</label>
                <select name="id_sucursal" id="crear_sucursal" class="form-select" required>
                  <option value="">-- Selecciona --</option>
                  <?php foreach ($suc as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- 6) Sueldo -->
              <div class="col-md-6" id="wrap_sueldo">
                <label class="form-label">Sueldo (MXN)</label>
                <input type="number" step="0.01" min="0" name="sueldo" id="crear_sueldo" class="form-control" required>
                <div class="form-text" id="sueldo_hint"></div>
              </div>

              <div class="d-flex justify-content-end gap-2 pt-2">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btnGuardarUsuario">
                  <i class="bi bi-check2-circle me-1"></i> Guardar
                </button>
              </div>
            </form>
          <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- =================== ACTIVOS =================== -->
    <div class="tab-pane fade p-3 <?= ($tab==='activos'?'show active':'') ?>" id="tab-activos" role="tabpanel">
      <form method="get" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="tab" value="activos">
        <div class="col-12 col-md-4">
          <label class="form-label small text-muted mb-1">Buscar</label>
          <input type="text" name="q" value="<?= h($busq) ?>" class="form-control" placeholder="Nombre o usuario">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small text-muted mb-1">Rol</label>
          <select name="rol" class="form-select">
            <option value="">Todos</option>
            <?php foreach ($rolesFiltro as $r): ?>
              <option value="<?= h($r) ?>" <?= ($frol===$r?'selected':'') ?>><?= h($rolesLabels[$r] ?? $r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small text-muted mb-1">Sucursal</label>
          <select name="suc" class="form-select">
            <option value="0">Todas</option>
            <?php foreach ($suc as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= ($fsuc==(int)$s['id']?'selected':'') ?>><?= h($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-2 d-grid">
          <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i> Aplicar</button>
        </div>
      </form>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Sucursal</th><th>Subdis</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$usuariosActivos): ?>
              <tr><td colspan="7" class="text-center py-4 text-muted">Sin usuarios activos.</td></tr>
            <?php else: foreach ($usuariosActivos as $u):
              $puedeOperar = puedeOperarTarget($permAdminLuga, $permAdminSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL, $u);
              $soloLectura = $permLecturaSubdis;
              $bloq = (!$puedeOperar || $soloLectura);

              $btnBaja   = $bloq;
              $btnRol    = !($permAdminLuga || $permAdminSubdis) || !$puedeOperar;
              $btnSuc    = !($permAdminLuga || $permAdminSubdis) || !$puedeOperar;
              $btnReset  = (!$puedeOperar || $soloLectura) ? true : false;
            ?>
              <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><?= h($u['nombre']) ?></td>
                <td><?= h($u['usuario']) ?></td>
                <td><span class="badge badge-role rounded-pill"><?= h($rolesLabels[$u['rol']] ?? $u['rol']) ?></span></td>
                <td><?= h($u['sucursal_nombre'] ?? '-') ?></td>
                <td><?= h(($u['id_subdis'] ?? 0) ? ($u['subdis_nombre'] ?? ('SUBDIS #'.$u['id_subdis'])) : '—') ?></td>
                <td class="text-end">
                  <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalDetalles" data-id="<?=$u['id']?>">
                    <i class="bi bi-card-text me-1"></i> Detalles
                  </button>

                  <button class="btn btn-outline-danger btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalBaja"
                          data-id="<?=$u['id']?>" data-nombre="<?=h($u['nombre'])?>"
                          <?= $btnBaja ? 'disabled' : '' ?>>
                    <i class="bi bi-person-x me-1"></i> Baja
                  </button>

                  <button class="btn btn-outline-secondary btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalRol"
                          data-id="<?=$u['id']?>" data-nombre="<?=h($u['nombre'])?>"
                          data-rol="<?=$u['rol']?>"
                          data-id-subdis="<?= (int)$u['id_subdis'] ?>"
                          <?= $btnRol ? 'disabled' : '' ?>>
                    <i class="bi bi-person-gear me-1"></i> Rol
                  </button>

                  <button class="btn btn-outline-primary btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalSucursal"
                          data-id="<?=$u['id']?>" data-nombre="<?=h($u['nombre'])?>"
                          data-sucursal-id="<?= (int)$u['id_sucursal'] ?>"
                          <?= $btnSuc ? 'disabled' : '' ?>>
                    <i class="bi bi-shop-window me-1"></i> Sucursal
                  </button>

                  <button class="btn btn-outline-warning btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalResetPass"
                          data-id="<?=$u['id']?>" data-nombre="<?=h($u['nombre'])?>"
                          <?= $btnReset ? 'disabled' : '' ?>>
                    <i class="bi bi-key me-1"></i> Reset
                  </button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- =================== INACTIVOS =================== -->
    <div class="tab-pane fade p-3 <?= ($tab==='inactivos'?'show active':'') ?>" id="tab-inactivos" role="tabpanel">
      <form method="get" class="row g-2 align-items-end mb-3">
        <input type="hidden" name="tab" value="inactivos">
        <div class="col-12 col-md-4">
          <label class="form-label small text-muted mb-1">Buscar</label>
          <input type="text" name="q" value="<?= h($busq) ?>" class="form-control" placeholder="Nombre o usuario">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small text-muted mb-1">Rol</label>
          <select name="rol" class="form-select">
            <option value="">Todos</option>
            <?php foreach ($rolesFiltro as $r): ?>
              <option value="<?= h($r) ?>" <?= ($frol===$r?'selected':'') ?>><?= h($rolesLabels[$r] ?? $r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label small text-muted mb-1">Sucursal</label>
          <select name="suc" class="form-select">
            <option value="0">Todas</option>
            <?php foreach ($suc as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= ($fsuc==(int)$s['id']?'selected':'') ?>><?= h($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-2 d-grid">
          <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i> Aplicar</button>
        </div>
      </form>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Sucursal</th><th>Subdis</th>
              <th class="text-end">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$usuariosInactivos): ?>
              <tr><td colspan="7" class="text-center py-4 text-muted">Sin usuarios inactivos.</td></tr>
            <?php else: foreach ($usuariosInactivos as $u):
              $puedeOperar = puedeOperarTarget($permAdminLuga, $permAdminSubdis, $permGerenteLuga, $MI_SUBDIS, $ID_SUCURSAL, $u);
              $soloLectura = $permLecturaSubdis;
              $btnReact = (!$puedeOperar || $soloLectura);
              $btnReset = (!$puedeOperar || $soloLectura);
            ?>
              <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><?= h($u['nombre']) ?></td>
                <td><?= h($u['usuario']) ?></td>
                <td><span class="badge badge-role rounded-pill"><?= h($rolesLabels[$u['rol']] ?? $u['rol']) ?></span></td>
                <td><?= h($u['sucursal_nombre'] ?? '-') ?></td>
                <td><?= h(($u['id_subdis'] ?? 0) ? ($u['subdis_nombre'] ?? ('SUBDIS #'.$u['id_subdis'])) : '—') ?></td>
                <td class="text-end">
                  <button class="btn btn-outline-success btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalReactivar"
                          data-id="<?=$u['id']?>" data-nombre="<?=h($u['nombre'])?>"
                          <?= $btnReact ? 'disabled' : '' ?>>
                    <i class="bi bi-person-check me-1"></i> Reactivar
                  </button>

                  <button class="btn btn-outline-warning btn-sm"
                          data-bs-toggle="modal" data-bs-target="#modalResetPass"
                          data-id="<?=$u['id']?>" data-nombre="<?=h($u['nombre'])?>"
                          <?= $btnReset ? 'disabled' : '' ?>>
                    <i class="bi bi-key me-1"></i> Reset
                  </button>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- =================== BITÁCORA =================== -->
    <div class="tab-pane fade p-3 <?= ($tab==='bitacora'?'show active':'') ?>" id="tab-bitacora" role="tabpanel">
      <h6 class="mb-2">Últimos <?= (int)$logLimit ?> movimientos</h6>
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th><th>Fecha</th><th>Acción</th><th>Actor</th><th>Usuario afectado</th><th>Detalles</th><th>IP</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($logs)): ?>
              <tr><td colspan="7" class="text-center py-3 text-muted">Sin registros.</td></tr>
            <?php else: foreach ($logs as $l): ?>
              <tr>
                <td><?= (int)$l['id'] ?></td>
                <td><?= h($l['created_at']) ?></td>
                <td><span class="badge bg-secondary"><?= h($l['accion']) ?></span></td>
                <td><?= h($l['actor_nombre'] ?: '-') ?></td>
                <td><?= h(($l['target_nombre'] ?: '-') . ' (' . ($l['target_user'] ?: '-') . ')') ?></td>
                <td><?= h($l['detalles'] ?: '-') ?></td>
                <td><?= h($l['ip'] ?: '-') ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<!-- =================== MODALES =================== -->
<div class="modal fade" id="modalBaja" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Dar de baja usuario</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="baja">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="usuario_id" id="baja_usuario_id">
        <label class="form-label">Usuario</label>
        <input type="text" id="baja_usuario_nombre" class="form-control" readonly>
        <div class="alert alert-warning mt-3 mb-0">Esta acción desactiva el acceso inmediatamente.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger" type="submit">Confirmar baja</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalReactivar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Reactivar usuario</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="reactivar">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="usuario_id" id="react_usuario_id">
        <label class="form-label">Usuario</label>
        <input type="text" id="react_usuario_nombre" class="form-control" readonly>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success" type="submit">Reactivar</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalRol" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Cambiar rol</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="cambiar_rol">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="usuario_id" id="rol_usuario_id">
        <label class="form-label">Usuario</label>
        <input type="text" id="rol_usuario_nombre" class="form-control" readonly>

        <div class="mt-2">
          <label class="form-label">Rol actual</label>
          <input type="text" id="rol_actual" class="form-control" readonly>
        </div>

        <div class="mt-2">
          <label class="form-label">Nuevo rol</label>
          <select name="nuevo_rol" id="rol_nuevo_select" class="form-select" required></select>
          <div class="form-text">Se listan roles según si el usuario es LUGA o SUBDISTRIBUIDOR.</div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar cambio</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalSucursal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Cambiar sucursal</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="cambiar_sucursal">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="usuario_id" id="suc_usuario_id">
        <label class="form-label">Usuario</label>
        <input type="text" id="suc_usuario_nombre" class="form-control" readonly>

        <div class="mt-2">
          <label class="form-label">Nueva sucursal</label>
          <select name="nueva_sucursal" id="suc_select_nueva" class="form-select" required>
            <option value="">Selecciona sucursal…</option>
            <?php foreach ($suc as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?= h($s['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalResetPass" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Resetear contraseña</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="accion" value="reset_password">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="usuario_id" id="reset_usuario_id">
        <label class="form-label">Usuario</label>
        <input type="text" id="reset_usuario_nombre" class="form-control" readonly>
        <div class="alert alert-info mt-3 mb-0">
          Se generará una contraseña temporal y se forzará cambio al iniciar sesión.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-warning" type="submit">Generar temporal</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {

  /* =========================
     Alta Usuario: MAYÚSCULAS + preview + validación live
  ========================= */
  const inpNombre = document.getElementById('crear_nombre');
  const previewFinal = document.getElementById('preview_usuario_final');
  const notaAjuste = document.getElementById('nota_usuario_ajuste');
  const badgeEstado = document.getElementById('badge_usuario_estado');
  const badgeNombreDup = document.getElementById('badge_nombre_dup');
  const btnGuardar = document.getElementById('btnGuardarUsuario');
  const nombreInvalidMsg = document.getElementById('nombre_invalid_msg');

  function validarNombreSinAcentosEspeciales(){
    if(!inpNombre) return true;

    const raw = inpNombre.value || '';
    const limpio = raw.replace(/[^A-Z ]+/g, '');
    const esValido = (limpio === raw);

    if(!esValido){
      const start = inpNombre.selectionStart;
      const end = inpNombre.selectionEnd;
      inpNombre.value = limpio;
      try{ inpNombre.setSelectionRange(Math.max(0,start-1), Math.max(0,end-1)); }catch(e){}
    }

    if (nombreInvalidMsg){
      nombreInvalidMsg.classList.toggle('d-none', esValido);
    }
    if (btnGuardar){
      btnGuardar.disabled = !esValido;
    }

    inpNombre.classList.toggle('is-invalid', !esValido);
    inpNombre.classList.toggle('is-valid', esValido && limpio.trim().length > 0);

    return esValido;
  }

  function jsSlug(str){
    if(!str) return '';
    try{
      str = str.normalize('NFD').replace(/[\u0300-\u036f]/g,'');
    }catch(e){}
    str = str.toLowerCase().replace(/[^a-z0-9]+/g,'');
    return str;
  }

  function normalizeSpaces(str){
    return (str || '').replace(/\s+/g,' ').trim();
  }

  function baseFromNombre(nombre){
    const clean = normalizeSpaces(nombre);
    if(!clean) return '';
    const parts = clean.split(' ').filter(Boolean);
    if(parts.length === 1) return jsSlug(parts[0]);

    const primer = parts[0] || '';
    const paterno = parts[parts.length-2] || '';
    const materno = parts[parts.length-1] || '';

    let base = jsSlug(primer.substring(0,1)) + jsSlug(paterno);
    if(materno && materno !== paterno){
      base += jsSlug(materno.substring(0,1));
    }
    return base || 'user';
  }

  function setBadge(el, kind, text){
    if(!el) return;
    el.classList.remove('text-bg-success','text-bg-warning','text-bg-danger','text-bg-secondary');
    el.classList.add(kind);
    el.textContent = text;
  }

  let tDeb = null;
  let lastReq = 0;

  async function validarNombreDebounced(){
    if(!inpNombre) return;

    const nombreVal = normalizeSpaces(inpNombre.value || '');
    const base = baseFromNombre(nombreVal);

    if(!nombreVal || nombreVal.split(' ').filter(Boolean).length < 2){
      if(previewFinal) previewFinal.textContent = base || '—';
      if(notaAjuste) notaAjuste.classList.add('d-none');
      if(badgeNombreDup) badgeNombreDup.classList.add('d-none');
      if(btnGuardar) btnGuardar.disabled = false;
      setBadge(badgeEstado, 'text-bg-secondary', 'Escribe nombre completo');
      return;
    }

    clearTimeout(tDeb);
    tDeb = setTimeout(async ()=>{
      const reqId = ++lastReq;

      setBadge(badgeEstado, 'text-bg-secondary', 'Validando...');
      if(previewFinal) previewFinal.textContent = '—';
      if(notaAjuste) notaAjuste.classList.add('d-none');
      if(badgeNombreDup) badgeNombreDup.classList.add('d-none');

      try{
        const url = `${window.location.pathname}?ajax=sugerir_usuario&nombre=${encodeURIComponent(nombreVal)}`;
        const r = await fetch(url, {headers:{'Accept':'application/json'}});
        const j = await r.json();
        if(reqId !== lastReq) return;

        if(!j || !j.ok){
          setBadge(badgeEstado, 'text-bg-danger', 'Error');
          if(btnGuardar) btnGuardar.disabled = false;
          return;
        }

        if(j.nombre_existe){
          if(previewFinal) previewFinal.textContent = '—';
          if(notaAjuste) notaAjuste.classList.add('d-none');
          if(badgeNombreDup) badgeNombreDup.classList.remove('d-none');
          setBadge(badgeEstado, 'text-bg-danger', 'Nombre duplicado');
          if(btnGuardar) btnGuardar.disabled = true;
        }else{
          if(btnGuardar) btnGuardar.disabled = false;
          const sug = j.sugerido || j.base || base;
          if(previewFinal){
            previewFinal.textContent = sug;
            previewFinal.classList.remove('d-none');
          }
          if(j.base_disponible){
            setBadge(badgeEstado, 'text-bg-success', 'Disponible');
          }else{
            setBadge(badgeEstado, 'text-bg-warning', 'Se ajustará');
          }
        }

      }catch(e){
        if(reqId !== lastReq) return;
        setBadge(badgeEstado, 'text-bg-danger', 'Sin conexión');
        if(btnGuardar) btnGuardar.disabled = false;
      }
    }, 420);
  }

  if(inpNombre){
    inpNombre.addEventListener('input', ()=>{
      const start = inpNombre.selectionStart;
      const end = inpNombre.selectionEnd;
      const beforeRaw = inpNombre.value;
      const cleaned = beforeRaw.replace(/[;,]+/g, ' ');
      const before = cleaned;
      const up = before.toLocaleUpperCase('es-MX');
      if(before !== up){
        inpNombre.value = up;
        try{ inpNombre.setSelectionRange(start, end); }catch(e){}
      } else if(beforeRaw !== cleaned) {
        inpNombre.value = before;
        try{ inpNombre.setSelectionRange(start, end); }catch(e){}
      }

      const okChars = validarNombreSinAcentosEspeciales();
      if(!okChars){
        if(previewFinal) previewFinal.textContent = '—';
        if(notaAjuste) notaAjuste.classList.add('d-none');
        if(badgeNombreDup) badgeNombreDup.classList.add('d-none');
        setBadge(badgeEstado, 'text-bg-danger', 'Caracter inválido');
        return;
      }

      validarNombreDebounced();
    });

    inpNombre.addEventListener('blur', ()=>{
      const okChars = validarNombreSinAcentosEspeciales();
      if(okChars) validarNombreDebounced();
    });
  }

  // Alta: Propiedad / Rol / Subdis / Sueldo sugerido
  const propiedadSel = document.getElementById('propiedad_select');
  const wrapSub = document.getElementById('wrap_subdis');
  const rolSel = document.getElementById('rol_select');
  const rolHint = document.getElementById('rol_hint');
  const inpSueldo = document.getElementById('crear_sueldo');
  const wrapSueldo = document.getElementById('wrap_sueldo');
  const sueldoHint = document.getElementById('sueldo_hint');
  let sueldoTouched = false;

  const rolesAltaLuga = [
    { value: 'Admin', label: 'Administrador' },
    { value: 'Gerente', label: 'Gerente' },
    { value: 'Ejecutivo', label: 'Ejecutivo' },
    { value: 'Logistica', label: 'Logística' }
  ];

  const rolesAltaSubdis = [
    { value: 'Subdis_Admin', label: 'Subdis_Admin' },
    { value: 'Subdis_Gerente', label: 'Subdis_Gerente' },
    { value: 'Subdis_Ejecutivo', label: 'Subdis_Ejecutivo' }
  ];

  function esSubdisUI(){
    const p = (propiedadSel && propiedadSel.value) ? propiedadSel.value : '';
    return p === 'SUBDISTRIBUIDOR';
  }

  function cargarRolesPorPropiedad(){
    if (!rolSel) return;

    const propiedad = (propiedadSel && propiedadSel.value) ? propiedadSel.value : '';
    const currentValue = rolSel.value || '';
    rolSel.innerHTML = '';

    let lista = [];
    if (propiedad === 'LUGA') {
      lista = rolesAltaLuga;
      if (rolHint) rolHint.textContent = 'Roles disponibles para LUGA.';
    } else if (propiedad === 'SUBDISTRIBUIDOR') {
      lista = rolesAltaSubdis;
      if (rolHint) rolHint.textContent = 'Roles disponibles para SUBDISTRIBUIDOR.';
    } else {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = '-- Selecciona una propiedad primero --';
      rolSel.appendChild(opt);
      if (rolHint) rolHint.textContent = 'Selecciona la propiedad para mostrar roles válidos.';
      return;
    }

    const first = document.createElement('option');
    first.value = '';
    first.textContent = '-- Selecciona --';
    rolSel.appendChild(first);

    lista.forEach(item => {
      const opt = document.createElement('option');
      opt.value = item.value;
      opt.textContent = item.label;
      if (item.value === currentValue) opt.selected = true;
      rolSel.appendChild(opt);
    });
  }

  function setSueldoEditable(on){
    if (!inpSueldo || !wrapSueldo) return;
    if (on){
      wrapSueldo.style.display = '';
      inpSueldo.disabled = false;
      inpSueldo.required = true;
    } else {
      inpSueldo.value = '0';
      wrapSueldo.style.display = 'none';
      inpSueldo.disabled = true;
      inpSueldo.required = false;
      if (sueldoHint) sueldoHint.textContent = 'SUBDISTRIBUIDOR: sueldo fijo 0.';
    }
  }

  function refreshCuentaUI(){
    const sub = esSubdisUI();
    if (wrapSub){
      wrapSub.style.display = sub ? '' : 'none';
    }
    setSueldoEditable(!sub);
  }

  if (inpSueldo){
    inpSueldo.addEventListener('input', ()=>{ sueldoTouched = true; });
  }

  let tSueldo = null;
  let lastSueldoReq = 0;

  async function sugerirSueldoDebounced(){
    if (!rolSel || !rolSel.value) return;
    if (esSubdisUI()) {
      if (sueldoHint) sueldoHint.textContent = 'SUBDISTRIBUIDOR: sueldo fijo 0.';
      return;
    }
    if (!inpSueldo) return;

    clearTimeout(tSueldo);
    tSueldo = setTimeout(async ()=>{
      const reqId = ++lastSueldoReq;
      try{
        const propiedadActual = (propiedadSel && propiedadSel.value) ? propiedadSel.value : 'LUGA';
        const url = `${window.location.pathname}?ajax=sueldo_sugerido&rol=${encodeURIComponent(rolSel.value)}&propiedad=${encodeURIComponent(propiedadActual)}&_=${Date.now()}`;
        const r = await fetch(url, {headers:{'Accept':'application/json'}});
        const j = await r.json();
        if (reqId !== lastSueldoReq) return;

        if(!j || !j.ok){
          if (sueldoHint) sueldoHint.textContent = '';
          return;
        }

        if (j.sugerido === null || typeof j.sugerido === 'undefined'){
          if (sueldoHint) sueldoHint.textContent = 'Sin historial suficiente para sugerir sueldo.';
          return;
        }

        const sug = Number(j.sugerido || 0);
        if (!sueldoTouched || !inpSueldo.value){
          inpSueldo.value = (sug > 0 ? sug.toFixed(2) : '0');
        }
        if (sueldoHint){
          sueldoHint.textContent = (sug > 0)
            ? `Sugerido: $${sug.toFixed(2)}`
            : 'Sugerido: 0';
        }
      }catch(e){
        if (reqId !== lastSueldoReq) return;
        if (sueldoHint) sueldoHint.textContent = '';
      }
    }, 420);
  }

  if (propiedadSel){
    propiedadSel.addEventListener('change', ()=>{
      sueldoTouched = false;
      if (rolSel) rolSel.value = '';
      cargarRolesPorPropiedad();
      refreshCuentaUI();
      sugerirSueldoDebounced();
    });
  }

  if (rolSel){
    rolSel.addEventListener('change', ()=>{
      sueldoTouched = false;
      refreshCuentaUI();
      sugerirSueldoDebounced();
    });
  }

  cargarRolesPorPropiedad();
  refreshCuentaUI();
  sugerirSueldoDebounced();

  // Modal Baja
  const modalBaja = document.getElementById('modalBaja');
  if (modalBaja) {
    modalBaja.addEventListener('show.bs.modal', e => {
      const b = e.relatedTarget;
      document.getElementById('baja_usuario_id').value = b.getAttribute('data-id');
      document.getElementById('baja_usuario_nombre').value = b.getAttribute('data-nombre');
    });
  }

  // Modal Reactivar
  const modalReactivar = document.getElementById('modalReactivar');
  if (modalReactivar) {
    modalReactivar.addEventListener('show.bs.modal', e => {
      const b = e.relatedTarget;
      document.getElementById('react_usuario_id').value = b.getAttribute('data-id');
      document.getElementById('react_usuario_nombre').value = b.getAttribute('data-nombre');
    });
  }

  // Modal Rol
  const rolesLuga = <?= json_encode($rolesLuga, JSON_UNESCAPED_UNICODE) ?>;
  const rolesSub  = <?= json_encode($rolesSubdis, JSON_UNESCAPED_UNICODE) ?>;

  const rolesLabelsMap = {
    'Admin': 'Administrador',
    'Gerente': 'Gerente',
    'Ejecutivo': 'Ejecutivo',
    'Logistica': 'Logística',
    'Subdis_Admin': 'Subdis_Admin',
    'Subdis_Gerente': 'Subdis_Gerente',
    'Subdis_Ejecutivo': 'Subdis_Ejecutivo'
  };

  const modalRol = document.getElementById('modalRol');
  if (modalRol) {
    modalRol.addEventListener('show.bs.modal', e => {
      const b = e.relatedTarget;
      const id = b.getAttribute('data-id');
      const nombre = b.getAttribute('data-nombre');
      const rol = b.getAttribute('data-rol');
      const idSubdis = parseInt(b.getAttribute('data-id-subdis') || '0', 10);

      document.getElementById('rol_usuario_id').value = id;
      document.getElementById('rol_usuario_nombre').value = nombre;
      document.getElementById('rol_actual').value = rolesLabelsMap[rol] || rol;

      const sel = document.getElementById('rol_nuevo_select');
      sel.innerHTML = '';
      const lista = (idSubdis > 0) ? rolesSub : rolesLuga;

      lista.forEach(r => {
        const opt = document.createElement('option');
        opt.value = r;
        opt.textContent = rolesLabelsMap[r] || r;
        if (r === rol) opt.selected = true;
        sel.appendChild(opt);
      });
    });
  }

  // Modal Sucursal
  const modalSuc = document.getElementById('modalSucursal');
  if (modalSuc) {
    modalSuc.addEventListener('show.bs.modal', e => {
      const b = e.relatedTarget;
      document.getElementById('suc_usuario_id').value = b.getAttribute('data-id');
      document.getElementById('suc_usuario_nombre').value = b.getAttribute('data-nombre');

      const actualId = parseInt(b.getAttribute('data-sucursal-id') || '0', 10);
      const sel = document.getElementById('suc_select_nueva');
      if (sel && sel.options && sel.options.length > 0) {
        for (let i=0; i<sel.options.length; i++){
          if (parseInt(sel.options[i].value,10) === actualId) { sel.selectedIndex = i; break; }
        }
      }
    });
  }

  // Modal Reset
  const modalResetPass = document.getElementById('modalResetPass');
  if (modalResetPass) {
    modalResetPass.addEventListener('show.bs.modal', e => {
      const b = e.relatedTarget;
      document.getElementById('reset_usuario_id').value = b.getAttribute('data-id');
      document.getElementById('reset_usuario_nombre').value = b.getAttribute('data-nombre');
    });
  }

  // Modal Detalles (AJAX)
  const modalDetalles = document.getElementById('modalDetalles');
  if (modalDetalles) {
    modalDetalles.addEventListener('show.bs.modal', async (e) => {
      const b = e.relatedTarget;
      const id = b?.getAttribute('data-id');
      const body = document.getElementById('detalleUsuarioBody');
      if (!body) return;

      body.innerHTML = `
        <div class="d-flex align-items-center gap-2 text-muted">
          <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
          Cargando...
        </div>`;

      try {
        const basePath = window.location.pathname;
        const url = `${basePath}?ajax=detalle_usuario&id=${encodeURIComponent(id || '')}&_=${Date.now()}`;
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (!data?.ok) {
          body.innerHTML = data?.html || '<div class="alert alert-warning mb-0">No se pudo cargar el detalle.</div>';
          return;
        }
        body.innerHTML = data.html;
      } catch (err) {
        body.innerHTML = '<div class="alert alert-danger mb-0">Error cargando detalles. Revisa consola/servidor.</div>';
      }
    });
  }

  // Copiar historial
  window.copyUserHistory = async function () {
    const ta = document.getElementById('historialCopyText');
    const text = ta ? (ta.value || ta.textContent || '') : '';
    if (!text.trim()) return;
    try {
      await navigator.clipboard.writeText(text);
      alert('✅ Historial copiado al portapapeles');
    } catch (e) {
      try {
        const tmp = document.createElement('textarea');
        tmp.value = text;
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand('copy');
        document.body.removeChild(tmp);
        alert('✅ Historial copiado al portapapeles');
      } catch (e2) {
        alert('No se pudo copiar automáticamente.');
      }
    }
  }

});
</script>

<!-- =================== MODAL DETALLES =================== -->
<div class="modal fade" id="modalDetalles" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Detalles del usuario</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body" id="detalleUsuarioBody">
        <div class="d-flex align-items-center gap-2 text-muted">
          <div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>
          Cargando...
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<?php if (!empty($newCreds) && !empty($newCreds['usuario']) && !empty($newCreds['password'])): ?>
<div class="modal fade" id="modalCredenciales" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Credenciales generadas</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info py-2">
          Se muestran una sola vez. Copia y compártelas por un canal seguro.
        </div>

        <div class="mb-2">
          <div class="text-muted small">Nombre</div>
          <div class="fw-semibold"><?= h($newCreds['nombre'] ?? '') ?></div>
        </div>

        <div class="row g-2">
          <div class="col-12">
            <div class="text-muted small">Usuario</div>
            <div class="form-control font-monospace" id="cred_user" style="user-select:all"><?= h($newCreds['usuario']) ?></div>
          </div>
          <div class="col-12">
            <div class="text-muted small">Contraseña temporal</div>
            <div class="form-control font-monospace" id="cred_pass" style="user-select:all"><?= h($newCreds['password']) ?></div>
            <div class="form-text">Al iniciar sesión se le debe pedir cambio de contraseña.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" id="btnCopiarCreds">
          📋 Copiar credenciales
        </button>
        <button class="btn btn-primary" type="button" data-bs-dismiss="modal">Listo</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  try{
    const m = new bootstrap.Modal(document.getElementById('modalCredenciales'));
    m.show();

    const btn = document.getElementById('btnCopiarCreds');
    btn?.addEventListener('click', async ()=>{
      const u = document.getElementById('cred_user')?.innerText || '';
      const p = document.getElementById('cred_pass')?.innerText || '';
      const txt = `Usuario: ${u}\nContraseña temporal: ${p}`;
      try{
        await navigator.clipboard.writeText(txt);
        btn.innerHTML = '✅ Copiado';
        setTimeout(()=>btn.innerHTML='📋 Copiar credenciales', 1500);
      }catch(e){
        const ta = document.createElement('textarea');
        ta.value = txt; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy');
        ta.remove();
        btn.innerHTML = '✅ Copiado';
        setTimeout(()=>btn.innerHTML='📋 Copiar credenciales', 1500);
      }
    });
  }catch(e){}
});
</script>
<?php endif; ?>

</body>
</html>