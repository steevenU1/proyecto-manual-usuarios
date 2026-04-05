<?php
/* =========================================================
   solicitar_vacaciones.php
   - Solicitud de vacaciones para cualquier personal activo
   - Flujo nuevo: Jefe inmediato + Admin
   - Integrado con calcular_vacaciones.php
========================================================= */

ob_start();
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calcular_vacaciones.php';

date_default_timezone_set('America/Mexico_City');

$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
} else {
    ini_set('display_errors', '0');
}

/* =========================================================
   Auth
========================================================= */
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
$rolSesion = trim((string)($_SESSION['rol'] ?? ''));

/* =========================================================
   Helpers locales
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

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

function clase_badge(string $status): string {
    $s = mb_strtolower(trim($status));
    return match ($s) {
        'aprobado'  => 'success',
        'rechazado' => 'danger',
        default     => 'warning text-dark',
    };
}

/* =========================================================
   Validaciones base
========================================================= */
$tbl = 'vacaciones_solicitudes';

$msg = '';
$cls = 'info';

if (!table_exists($conn, 'usuarios')) {
    die("No existe la tabla usuarios.");
}
if (!table_exists($conn, 'sucursales')) {
    die("No existe la tabla sucursales.");
}
if (!table_exists($conn, 'usuarios_expediente')) {
    die("No existe la tabla usuarios_expediente.");
}
if (!table_exists($conn, $tbl)) {
    die("No existe la tabla vacaciones_solicitudes.");
}

/* =========================================================
   Descubrir columnas de vacaciones_solicitudes
========================================================= */
$hasDias             = column_exists($conn, $tbl, 'dias');
$hasMotivo           = column_exists($conn, $tbl, 'motivo');
$hasStatusJefe       = column_exists($conn, $tbl, 'status_jefe');
$hasAprobJefePor     = column_exists($conn, $tbl, 'aprobado_jefe_por');
$hasAprobJefeEn      = column_exists($conn, $tbl, 'aprobado_jefe_en');
$hasComentarioJefe   = column_exists($conn, $tbl, 'comentario_jefe');

$hasStatusAdmin      = column_exists($conn, $tbl, 'status_admin');
$hasAprobAdminPor    = column_exists($conn, $tbl, 'aprobado_admin_por');
$hasAprobAdminEn     = column_exists($conn, $tbl, 'aprobado_admin_en');
$hasComentarioAdmin  = column_exists($conn, $tbl, 'comentario_admin');

$hasCreadoEn         = column_exists($conn, $tbl, 'creado_en');

/* =========================================================
   Cargar usuario solicitante
========================================================= */
$sqlUser = "
    SELECT
        u.id,
        u.nombre,
        u.usuario,
        u.correo,
        u.rol,
        u.activo,
        u.id_sucursal,
        s.nombre AS sucursal,
        s.zona,
        ue.fecha_ingreso
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    LEFT JOIN usuarios_expediente ue ON ue.usuario_id = u.id
    WHERE u.id = ? AND u.activo = 1
    LIMIT 1
";
$stmt = $conn->prepare($sqlUser);
$stmt->bind_param('i', $idUsuario);
$stmt->execute();
$userRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$userRow) {
    $msg = "❌ No se encontró un usuario activo válido para generar la solicitud.";
    $cls = "danger";
}

/* =========================================================
   Resumen de vacaciones (motor central)
========================================================= */
$resumenVac = obtener_resumen_vacaciones_usuario($conn, $idUsuario);

if (!$resumenVac['ok'] && $userRow) {
    $msg = $resumenVac['mensaje'] ?: "No fue posible calcular el resumen vacacional.";
    $cls = "warning";
}

/* =========================================================
   POST: crear solicitud
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userRow) {
    $fechaInicio = trim((string)($_POST['fecha_inicio'] ?? ''));
    $fechaFin    = trim((string)($_POST['fecha_fin'] ?? ''));
    $motivo      = trim((string)($_POST['motivo'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
        $msg = "Debes capturar un rango de fechas válido.";
        $cls = "danger";
    } elseif ($fechaFin < $fechaInicio) {
        $msg = "La fecha fin no puede ser menor que la fecha inicio.";
        $cls = "danger";
    } elseif (!$resumenVac['ok']) {
        $msg = $resumenVac['mensaje'] ?: "No se puede registrar la solicitud sin un cálculo válido de vacaciones.";
        $cls = "danger";
    } else {
        $diasSolicitud = calcular_dias_solicitud_rango($fechaInicio, $fechaFin);

        if ($diasSolicitud <= 0) {
            $msg = "El número de días calculado no es válido.";
            $cls = "danger";
        } elseif ($resumenVac['dias_disponibles'] >= 0 && $diasSolicitud > $resumenVac['dias_disponibles']) {
            $msg = "La solicitud excede los días disponibles del periodo actual.";
            $cls = "danger";
        } else {
            // Duplicado pendiente mismo rango
            $sqlDup = "
                SELECT id
                FROM vacaciones_solicitudes
                WHERE id_usuario = ?
                  AND fecha_inicio = ?
                  AND fecha_fin = ?
            ";

            if ($hasStatusAdmin) {
                $sqlDup .= " AND status_admin = 'Pendiente' ";
            }

            if ($hasStatusJefe) {
                $sqlDup .= " AND status_jefe = 'Pendiente' ";
            }

            $sqlDup .= " LIMIT 1 ";

            $stmt = $conn->prepare($sqlDup);
            $stmt->bind_param('iss', $idUsuario, $fechaInicio, $fechaFin);
            $stmt->execute();
            $dup = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($dup) {
                $msg = "Ya existe una solicitud pendiente con ese mismo rango.";
                $cls = "warning";
            } else {
                $cols = ['id_usuario', 'id_sucursal', 'fecha_inicio', 'fecha_fin'];
                $ph   = ['?', '?', '?', '?'];
                $types= 'iiss';
                $vals = [$idUsuario, (int)$userRow['id_sucursal'], $fechaInicio, $fechaFin];

                if ($hasDias) {
                    $cols[] = 'dias';
                    $ph[] = '?';
                    $types .= 'i';
                    $vals[] = $diasSolicitud;
                }

                if ($hasMotivo) {
                    $cols[] = 'motivo';
                    $ph[] = '?';
                    $types .= 's';
                    $vals[] = ($motivo !== '' ? $motivo : 'Vacaciones');
                }

                if ($hasStatusJefe) {
                    $cols[] = 'status_jefe';
                    $ph[] = '?';
                    $types .= 's';
                    $vals[] = 'Pendiente';
                }

                if ($hasStatusAdmin) {
                    $cols[] = 'status_admin';
                    $ph[] = '?';
                    $types .= 's';
                    $vals[] = 'Pendiente';
                }

                if ($hasCreadoEn) {
                    $cols[] = 'creado_en';
                    $ph[] = 'NOW()';
                }

                $sqlIns = "INSERT INTO vacaciones_solicitudes (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $ph) . ")";
                $stmt = $conn->prepare($sqlIns);
                $stmt->bind_param($types, ...$vals);

                try {
                    $stmt->execute();
                    $msg = "✅ Solicitud enviada correctamente. Pasará por validación de jefe inmediato y/o Admin.";
                    $cls = "success";

                    // recalcular resumen real después de guardar
                    $resumenVac = obtener_resumen_vacaciones_usuario($conn, $idUsuario);
                } catch (Throwable $e) {
                    $msg = "❌ Error al guardar la solicitud: " . $e->getMessage();
                    $cls = "danger";
                }

                $stmt->close();
            }
        }
    }
}

/* =========================================================
   Historial del usuario
========================================================= */
$hist = [];
if ($userRow) {
    $selectCols = [
        'id',
        'fecha_inicio',
        'fecha_fin'
    ];

    if ($hasDias) $selectCols[] = 'dias';
    if ($hasMotivo) $selectCols[] = 'motivo';
    if ($hasStatusJefe) $selectCols[] = 'status_jefe';
    if ($hasStatusAdmin) $selectCols[] = 'status_admin';
    if ($hasCreadoEn) $selectCols[] = 'creado_en';
    if ($hasAprobJefeEn) $selectCols[] = 'aprobado_jefe_en';
    if ($hasAprobAdminEn) $selectCols[] = 'aprobado_admin_en';

    $sqlHist = "
        SELECT " . implode(',', $selectCols) . "
        FROM vacaciones_solicitudes
        WHERE id_usuario = ?
        ORDER BY id DESC
        LIMIT 30
    ";
    $stmt = $conn->prepare($sqlHist);
    $stmt->bind_param('i', $idUsuario);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $hist[] = $r;
    }
    $stmt->close();
}

/* =========================================================
   UI
========================================================= */
require_once __DIR__ . '/navbar.php';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Solicitar vacaciones</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        body{ background:#f4f7fb; }
        .card-soft{
            border:0;
            border-radius:1.2rem;
            box-shadow:0 12px 28px rgba(15,23,42,.06), 0 3px 8px rgba(15,23,42,.05);
        }
        .hero-vac{
            border-radius:1.4rem;
            padding:1.4rem;
            background:linear-gradient(135deg,#0f172a 0%, #1e3a8a 60%, #2563eb 100%);
            color:#fff;
        }
        .mini-kpi{
            background:rgba(255,255,255,.12);
            border:1px solid rgba(255,255,255,.16);
            border-radius:1rem;
            padding:1rem;
            height:100%;
        }
        .mini-kpi .label{
            font-size:.8rem;
            opacity:.85;
            margin-bottom:.35rem;
        }
        .mini-kpi .value{
            font-size:1.8rem;
            font-weight:800;
            line-height:1;
        }
        .info-pill{
            display:inline-flex;
            align-items:center;
            gap:.4rem;
            padding:.4rem .75rem;
            border-radius:999px;
            background:rgba(255,255,255,.12);
            border:1px solid rgba(255,255,255,.16);
            font-size:.85rem;
            margin:.2rem .3rem .2rem 0;
        }
        .table thead th{
            white-space:nowrap;
        }
    </style>
</head>
<body>
<div class="container my-4" style="max-width:1100px;">

    <div class="hero-vac mb-4">
        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
            <div>
                <h2 class="mb-2"><i class="bi bi-airplane me-2"></i>Solicitud de vacaciones</h2>
                <p class="mb-3" style="max-width:700px; opacity:.9;">
                    Registra tu solicitud de vacaciones dentro del periodo vigente. La solicitud quedará en revisión
                    para validación interna según el flujo de autorizaciones de la empresa.
                </p>

                <?php if ($userRow): ?>
                    <div>
                        <span class="info-pill"><i class="bi bi-person-badge"></i> #<?= (int)$userRow['id'] ?> · <?= h($userRow['nombre']) ?></span>
                        <span class="info-pill"><i class="bi bi-briefcase"></i> <?= h($userRow['rol']) ?></span>
                        <span class="info-pill"><i class="bi bi-shop"></i> <?= h($userRow['sucursal'] ?: 'Sin sucursal') ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="text-end">
                <span class="info-pill"><i class="bi bi-shield-check"></i> Rol actual: <?= h($rolSesion) ?></span>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?= h($cls) ?>"><?= h($msg) ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card card-soft mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Nueva solicitud</h5>

                    <form method="post" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Fecha inicio *</label>
                            <input type="date" name="fecha_inicio" class="form-control" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Fecha fin *</label>
                            <input type="date" name="fecha_fin" class="form-control" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Motivo / observaciones</label>
                            <textarea name="motivo" class="form-control" rows="3" placeholder="Ej. Vacaciones familiares, descanso programado, etc."></textarea>
                        </div>

                        <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2">
                            <div class="small text-muted">
                                La solicitud se registrará inicialmente como <strong>Pendiente</strong>.
                            </div>
                            <button class="btn btn-primary">
                                <i class="bi bi-send me-1"></i> Enviar solicitud
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card card-soft">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Mis solicitudes recientes</h5>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>Rango</th>
                                    <th>Días</th>
                                    <th>Jefe</th>
                                    <th>Admin</th>
                                    <th>Creada</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$hist): ?>
                                <tr>
                                    <td colspan="6" class="text-muted">Aún no tienes solicitudes registradas.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($hist as $r): ?>
                                    <?php
                                    $stJefe  = (string)($r['status_jefe'] ?? 'Pendiente');
                                    $stAdmin = (string)($r['status_admin'] ?? 'Pendiente');
                                    ?>
                                    <tr>
                                        <td><?= (int)$r['id'] ?></td>
                                        <td>
                                            <div><?= h(vac_fmt_date($r['fecha_inicio'])) ?> → <?= h(vac_fmt_date($r['fecha_fin'])) ?></div>
                                            <?php if (!empty($r['motivo'])): ?>
                                                <div class="small text-muted"><?= h($r['motivo']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= isset($r['dias']) ? (int)$r['dias'] : '—' ?></td>
                                        <td>
                                            <span class="badge bg-<?= h(clase_badge($stJefe)) ?>"><?= h($stJefe) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= h(clase_badge($stAdmin)) ?>"><?= h($stAdmin) ?></span>
                                        </td>
                                        <td><?= h($r['creado_en'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card card-soft mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Resumen vacacional</h5>

                    <?php if (!$resumenVac['ok']): ?>
                        <div class="text-muted">
                            <?= h($resumenVac['mensaje'] ?: 'No hay información suficiente para calcular vacaciones.') ?>
                        </div>
                    <?php else: ?>
                        <div class="row g-3 mb-3">
                            <div class="col-4">
                                <div class="mini-kpi text-center">
                                    <div class="label">Corresponden</div>
                                    <div class="value"><?= (int)$resumenVac['dias_otorgados'] ?></div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="mini-kpi text-center">
                                    <div class="label">Tomados</div>
                                    <div class="value"><?= (int)$resumenVac['dias_tomados'] ?></div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="mini-kpi text-center">
                                    <div class="label">Disponibles</div>
                                    <div class="value"><?= (int)$resumenVac['dias_disponibles'] ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="small text-muted mb-2">Periodo vacacional vigente</div>
                        <div class="fw-semibold mb-3"><?= h($resumenVac['vigencia_texto']) ?></div>

                        <div class="small text-muted mb-1">Años cumplidos al inicio del periodo</div>
                        <div class="fw-semibold mb-3"><?= (int)$resumenVac['anios_cumplidos'] ?></div>

                        <div class="small text-muted mb-1">Próximo aniversario</div>
                        <div class="fw-semibold"><?= h(vac_fmt_date($resumenVac['periodo']['proximo_aniversario'] ?? null)) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card card-soft">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Notas del flujo</h5>
                    <ul class="small text-muted ps-3 mb-0">
                        <li>Las vacaciones se manejan dentro de un periodo anual vigente.</li>
                        <li>La vigencia del saldo es de 1 año por periodo vacacional.</li>
                        <li>La solicitud queda en revisión interna.</li>
                        <li>La aprobación final permitirá reflejar el movimiento en asistencia.</li>
                        <li>El formato PDF podrá generarse una vez revisada la solicitud.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
<?php
echo ob_get_clean();