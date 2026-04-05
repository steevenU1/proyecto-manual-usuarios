<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

date_default_timezone_set('America/Mexico_City');

/* =========================================================
   CONFIG / SESIÓN
========================================================= */
$idUsuarioSesion = (int)($_SESSION['id_usuario'] ?? 0);
$rolSesion       = trim((string)($_SESSION['rol'] ?? ''));

/*
  Roles que sí pueden actuar como jefe inmediato
*/
$rolesJefe = ['Gerente', 'Jefe', 'Supervisor', 'Coordinador'];

/*
  IDs de usuarios que sí pueden actuar como RH
  aunque su rol general sea Admin.
  CAMBIA estos valores por los IDs reales.
*/
$idsRhAutorizados = [6, 8, 88];

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function hasColumn(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
}

function badgeEstado(string $estado): string {
    $estado = trim($estado);

    $class = 'badge-pendiente';
    if ($estado === 'Aprobado') $class = 'badge-aprobado';
    if ($estado === 'Rechazado') $class = 'badge-rechazado';

    return '<span class="badge-estado ' . $class . '">' . h($estado) . '</span>';
}

function badgeResolucionFinal(string $estadoJefe, string $estadoAdmin): string {
    if ($estadoAdmin === 'Aprobado') {
        return '<span class="badge-estado badge-aprobado">Aprobada</span>';
    }
    if ($estadoAdmin === 'Rechazado') {
        return '<span class="badge-estado badge-rechazado">Rechazada</span>';
    }
    if ($estadoJefe === 'Aprobado') {
        return '<span class="badge-estado badge-pendiente">Pendiente RH</span>';
    }
    if ($estadoJefe === 'Rechazado') {
        return '<span class="badge-estado badge-pendiente">Pendiente RH</span>';
    }
    return '<span class="badge-estado badge-pendiente">Pendiente</span>';
}

function esJefe(array $rolesJefe, string $rol): bool {
    return in_array($rol, $rolesJefe, true);
}

function esRhAutorizado(int $idUsuario, array $idsRhAutorizados): bool {
    return in_array($idUsuario, $idsRhAutorizados, true);
}

function columnaJefeDisponible(mysqli $conn): ?string {
    $candidatas = ['id_jefe', 'id_supervisor', 'jefe_inmediato_id'];
    foreach ($candidatas as $col) {
        if (hasColumn($conn, 'usuarios', $col)) {
            return $col;
        }
    }
    return null;
}

function puedeAutorizarComoJefe(mysqli $conn, int $solicitudId, int $idJefeSesion, ?string $columnaJefe): bool {
    if (!$columnaJefe) return false;

    $sql = "SELECT vs.id
            FROM vacaciones_solicitudes vs
            INNER JOIN usuarios u ON u.id = vs.id_usuario
            WHERE vs.id = ?
              AND u.`$columnaJefe` = ?
              AND vs.status_jefe = 'Pendiente'
              AND vs.status_admin = 'Pendiente'
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $solicitudId, $idJefeSesion);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $ok;
}

/*
  RH puede resolver SIEMPRE que status_admin siga en Pendiente,
  sin importar si jefe está Pendiente, Aprobado o Rechazado.
*/
function puedeAutorizarComoRh(mysqli $conn, int $solicitudId): bool {
    $sql = "SELECT id
            FROM vacaciones_solicitudes
            WHERE id = ?
              AND status_admin = 'Pendiente'
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $solicitudId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $ok;
}

/* =========================================================
   PERMISOS
========================================================= */
$columnaJefe = columnaJefeDisponible($conn);

$usuarioEsJefe = esJefe($rolesJefe, $rolSesion);
$usuarioEsRh   = esRhAutorizado($idUsuarioSesion, $idsRhAutorizados);

if (!$usuarioEsRh && !$usuarioEsJefe) {
    http_response_code(403);
    exit('Sin permiso para autorizar vacaciones.');
}

/* =========================================================
   PROCESAR ACCIONES
========================================================= */
$mensaje = '';
$tipoMsg = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion      = trim((string)($_POST['accion'] ?? ''));
    $tipoAccion  = trim((string)($_POST['tipo'] ?? ''));
    $solicitudId = (int)($_POST['id_solicitud'] ?? 0);

    if ($solicitudId <= 0) {
        $mensaje = 'Solicitud inválida.';
        $tipoMsg = 'error';
    } else {
        try {
            $conn->begin_transaction();

            if ($tipoAccion === 'jefe') {
                if (!$usuarioEsJefe) {
                    throw new Exception('No tienes permisos de jefe para esta acción.');
                }

                if (!puedeAutorizarComoJefe($conn, $solicitudId, $idUsuarioSesion, $columnaJefe)) {
                    throw new Exception('No puedes autorizar esta solicitud como jefe o ya fue procesada.');
                }

                if ($accion === 'aprobar') {
                    $nuevoStatus = 'Aprobado';
                } elseif ($accion === 'rechazar') {
                    $nuevoStatus = 'Rechazado';
                } else {
                    throw new Exception('Acción inválida.');
                }

                $sql = "UPDATE vacaciones_solicitudes
                        SET status_jefe = ?
                        WHERE id = ?
                          AND status_jefe = 'Pendiente'
                          AND status_admin = 'Pendiente'
                        LIMIT 1";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $nuevoStatus, $solicitudId);
                $stmt->execute();

                if ($stmt->affected_rows <= 0) {
                    $stmt->close();
                    throw new Exception('No se pudo actualizar la solicitud.');
                }
                $stmt->close();

                $mensaje = $nuevoStatus === 'Aprobado'
                    ? 'La solicitud fue aprobada por el jefe inmediato.'
                    : 'La solicitud fue rechazada por el jefe inmediato.';
            }

            elseif ($tipoAccion === 'rh') {
                if (!$usuarioEsRh) {
                    throw new Exception('No tienes permisos de RH para esta acción.');
                }

                if (!puedeAutorizarComoRh($conn, $solicitudId)) {
                    throw new Exception('Esta solicitud ya fue resuelta por RH o no está disponible.');
                }

                if ($accion === 'aprobar') {
                    $nuevoStatus = 'Aprobado';
                } elseif ($accion === 'rechazar') {
                    $nuevoStatus = 'Rechazado';
                } else {
                    throw new Exception('Acción inválida.');
                }

                $sql = "UPDATE vacaciones_solicitudes
                        SET status_admin = ?
                        WHERE id = ?
                          AND status_admin = 'Pendiente'
                        LIMIT 1";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("si", $nuevoStatus, $solicitudId);
                $stmt->execute();

                if ($stmt->affected_rows <= 0) {
                    $stmt->close();
                    throw new Exception('No se pudo actualizar la autorización final.');
                }
                $stmt->close();

                $mensaje = $nuevoStatus === 'Aprobado'
                    ? 'La solicitud fue aprobada por RH.'
                    : 'La solicitud fue rechazada por RH.';
            }

            else {
                throw new Exception('Tipo de acción inválido.');
            }

            $conn->commit();

        } catch (Throwable $e) {
            $conn->rollback();
            $mensaje = $e->getMessage();
            $tipoMsg = 'error';
        }
    }
}

/* =========================================================
   CONSULTA PRINCIPAL
========================================================= */
$filtroWhere = "";
$params = [];
$types  = "";

if ($usuarioEsRh) {
    $filtroWhere = "1=1";
} elseif ($usuarioEsJefe && $columnaJefe) {
    $filtroWhere = "u.`$columnaJefe` = ?";
    $params[] = $idUsuarioSesion;
    $types .= "i";
} else {
    $filtroWhere = "1=0";
}

$sql = "SELECT
            vs.id,
            vs.id_usuario,
            vs.fecha_inicio,
            vs.fecha_fin,
            vs.dias,
            vs.motivo,
            vs.status_jefe,
            vs.status_admin,
            vs.creado_en,
            u.nombre AS empleado,
            COALESCE(s.nombre, 'Sin sucursal') AS sucursal
        FROM vacaciones_solicitudes vs
        INNER JOIN usuarios u ON u.id = vs.id_usuario
        LEFT JOIN sucursales s ON s.id = u.id_sucursal
        WHERE $filtroWhere
        ORDER BY
            CASE
                WHEN vs.status_admin = 'Pendiente' AND vs.status_jefe = 'Pendiente' THEN 1
                WHEN vs.status_admin = 'Pendiente' AND vs.status_jefe = 'Aprobado' THEN 2
                WHEN vs.status_admin = 'Pendiente' AND vs.status_jefe = 'Rechazado' THEN 3
                ELSE 4
            END,
            vs.creado_en DESC,
            vs.id DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$resSolicitudes = $stmt->get_result();

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Autorizar Vacaciones</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root{
            --bg:#f5f7fb;
            --card:#ffffff;
            --line:#e5e7eb;
            --text:#1f2937;
            --muted:#6b7280;
            --primary:#2563eb;
            --primary-dark:#1d4ed8;
            --success:#16a34a;
            --danger:#dc2626;
            --warning:#d97706;
            --shadow:0 8px 24px rgba(0,0,0,.08);
            --radius:14px;
        }

        *{ box-sizing:border-box; }

        body{
            margin:0;
            font-family: Arial, Helvetica, sans-serif;
            background:var(--bg);
            color:var(--text);
        }

        .wrap{
            max-width: 1500px;
            margin: 30px auto;
            padding: 0 16px 40px;
        }

        .panel{
            background:var(--card);
            border:1px solid var(--line);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            overflow:hidden;
        }

        .panel-head{
            padding:20px 24px;
            border-bottom:1px solid var(--line);
            display:flex;
            flex-wrap:wrap;
            align-items:center;
            justify-content:space-between;
            gap:12px;
        }

        .panel-head h1{
            margin:0;
            font-size:24px;
        }

        .panel-head .sub{
            margin-top:4px;
            color:var(--muted);
            font-size:14px;
        }

        .alert{
            margin:18px 24px 0;
            padding:14px 16px;
            border-radius:12px;
            font-size:14px;
            border:1px solid transparent;
        }

        .alert-ok{
            background:#ecfdf5;
            color:#065f46;
            border-color:#a7f3d0;
        }

        .alert-error{
            background:#fef2f2;
            color:#991b1b;
            border-color:#fecaca;
        }

        .table-wrap{
            width:100%;
            overflow:auto;
        }

        table{
            width:100%;
            border-collapse:collapse;
            min-width:1300px;
        }

        thead th{
            background:#f8fafc;
            color:#374151;
            font-size:13px;
            text-transform:uppercase;
            letter-spacing:.03em;
            padding:14px 12px;
            border-bottom:1px solid var(--line);
            text-align:left;
            white-space:nowrap;
        }

        tbody td{
            padding:14px 12px;
            border-bottom:1px solid var(--line);
            vertical-align:top;
            font-size:14px;
        }

        tbody tr:hover{
            background:#fafcff;
        }

        .badge-estado{
            display:inline-block;
            padding:6px 10px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            letter-spacing:.02em;
        }

        .badge-pendiente{
            background:#fff7ed;
            color:#b45309;
            border:1px solid #fdba74;
        }

        .badge-aprobado{
            background:#ecfdf5;
            color:#166534;
            border:1px solid #86efac;
        }

        .badge-rechazado{
            background:#fef2f2;
            color:#991b1b;
            border:1px solid #fca5a5;
        }

        .acciones{
            display:flex;
            flex-wrap:wrap;
            gap:8px;
        }

        .acciones form{
            margin:0;
        }

        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            text-decoration:none;
            border:none;
            border-radius:10px;
            padding:9px 12px;
            font-size:13px;
            font-weight:700;
            cursor:pointer;
            transition:.18s ease;
        }

        .btn-success{
            background:var(--success);
            color:#fff;
        }

        .btn-success:hover{
            filter:brightness(.95);
        }

        .btn-danger{
            background:var(--danger);
            color:#fff;
        }

        .btn-danger:hover{
            filter:brightness(.95);
        }

        .btn-primary{
            background:var(--primary);
            color:#fff;
        }

        .btn-primary:hover{
            background:var(--primary-dark);
        }

        .btn-muted{
            background:#e5e7eb;
            color:#374151;
            cursor:not-allowed;
        }

        .rol-chip{
            display:inline-block;
            padding:8px 12px;
            border-radius:999px;
            background:#eff6ff;
            color:#1d4ed8;
            font-weight:700;
            font-size:12px;
            border:1px solid #bfdbfe;
        }

        .small{
            font-size:12px;
            color:var(--muted);
        }

        .motivo{
            max-width:260px;
            line-height:1.4;
            white-space:pre-wrap;
        }

        .vacio{
            padding:34px 20px;
            text-align:center;
            color:var(--muted);
        }

        .stack{
            display:flex;
            flex-direction:column;
            gap:4px;
        }
    </style>
</head>
<body>

<div class="wrap">
    <div class="panel">
        <div class="panel-head">
            <div>
                <h1>Panel de autorización de vacaciones</h1>
                <div class="sub">
                    Flujo normal: Jefe inmediato → RH. RH puede resolver directamente si es necesario.
                </div>
            </div>

            <div class="rol-chip">
                Rol actual: <?= h($rolSesion) ?> | Usuario ID: <?= (int)$idUsuarioSesion ?>
            </div>
        </div>

        <?php if ($mensaje !== ''): ?>
            <div class="alert <?= $tipoMsg === 'ok' ? 'alert-ok' : 'alert-error' ?>">
                <?= h($mensaje) ?>
            </div>
        <?php endif; ?>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Empleado</th>
                        <th>Sucursal</th>
                        <th>Fecha inicio</th>
                        <th>Fecha fin</th>
                        <th>Días</th>
                        <th>Motivo</th>
                        <th>Estado jefe</th>
                        <th>Estado RH</th>
                        <th>Resolución final</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($resSolicitudes->num_rows > 0): ?>
                    <?php while ($row = $resSolicitudes->fetch_assoc()): ?>
                        <?php
                            $idSolicitud  = (int)$row['id'];
                            $estadoJefe   = (string)$row['status_jefe'];
                            $estadoAdmin  = (string)$row['status_admin'];

                            $mostrarAccionesJefe = false;
                            $mostrarAccionesRh   = false;

                            if ($usuarioEsJefe && $columnaJefe) {
                                $mostrarAccionesJefe = ($estadoJefe === 'Pendiente' && $estadoAdmin === 'Pendiente');
                            }

                            if ($usuarioEsRh) {
                                $mostrarAccionesRh = ($estadoAdmin === 'Pendiente');
                            }
                        ?>
                        <tr>
                            <td><?= $idSolicitud ?></td>
                            <td>
                                <div class="stack">
                                    <strong><?= h($row['empleado']) ?></strong>
                                </div>
                            </td>
                            <td><?= h($row['sucursal']) ?></td>
                            <td><?= h($row['fecha_inicio']) ?></td>
                            <td><?= h($row['fecha_fin']) ?></td>
                            <td><?= (int)$row['dias'] ?></td>
                            <td class="motivo"><?= h($row['motivo']) ?></td>
                            <td><?= badgeEstado($estadoJefe) ?></td>
                            <td><?= badgeEstado($estadoAdmin) ?></td>
                            <td><?= badgeResolucionFinal($estadoJefe, $estadoAdmin) ?></td>
                            <td>
                                <div class="acciones">

                                    <?php if ($mostrarAccionesJefe): ?>
                                        <form method="post" onsubmit="return confirm('¿Aprobar esta solicitud como jefe inmediato?');">
                                            <input type="hidden" name="id_solicitud" value="<?= $idSolicitud ?>">
                                            <input type="hidden" name="tipo" value="jefe">
                                            <input type="hidden" name="accion" value="aprobar">
                                            <button type="submit" class="btn btn-success">Aprobar jefe</button>
                                        </form>

                                        <form method="post" onsubmit="return confirm('¿Rechazar esta solicitud como jefe inmediato?');">
                                            <input type="hidden" name="id_solicitud" value="<?= $idSolicitud ?>">
                                            <input type="hidden" name="tipo" value="jefe">
                                            <input type="hidden" name="accion" value="rechazar">
                                            <button type="submit" class="btn btn-danger">Rechazar jefe</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($mostrarAccionesRh): ?>
                                        <form method="post" onsubmit="return confirm('¿Aprobar definitivamente esta solicitud como RH?');">
                                            <input type="hidden" name="id_solicitud" value="<?= $idSolicitud ?>">
                                            <input type="hidden" name="tipo" value="rh">
                                            <input type="hidden" name="accion" value="aprobar">
                                            <button type="submit" class="btn btn-success">Aprobar RH</button>
                                        </form>

                                        <form method="post" onsubmit="return confirm('¿Rechazar definitivamente esta solicitud como RH?');">
                                            <input type="hidden" name="id_solicitud" value="<?= $idSolicitud ?>">
                                            <input type="hidden" name="tipo" value="rh">
                                            <input type="hidden" name="accion" value="rechazar">
                                            <button type="submit" class="btn btn-danger">Rechazar RH</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($estadoAdmin === 'Aprobado'): ?>
                                        <a href="generar_formato_vacaciones.php?id=<?= $idSolicitud ?>"
                                           target="_blank"
                                           class="btn btn-primary">
                                            Generar formato
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!$mostrarAccionesJefe && !$mostrarAccionesRh && $estadoAdmin !== 'Aprobado'): ?>
                                        <button type="button" class="btn btn-muted">Sin acciones</button>
                                    <?php endif; ?>

                                </div>

                                <?php if ($estadoAdmin === 'Aprobado'): ?>
                                    <div class="small" style="margin-top:8px;">
                                        Resolución final aprobada por RH. Ya puedes generar el formato de vacaciones.
                                    </div>
                                <?php elseif ($estadoAdmin === 'Rechazado'): ?>
                                    <div class="small" style="margin-top:8px;">
                                        Resolución final rechazada por RH.
                                    </div>
                                <?php elseif ($estadoJefe === 'Pendiente'): ?>
                                    <div class="small" style="margin-top:8px;">
                                        Esperando respuesta del jefe inmediato, aunque RH puede resolver directamente.
                                    </div>
                                <?php elseif ($estadoJefe === 'Aprobado'): ?>
                                    <div class="small" style="margin-top:8px;">
                                        Jefe aprobó. Falta resolución final de RH.
                                    </div>
                                <?php elseif ($estadoJefe === 'Rechazado'): ?>
                                    <div class="small" style="margin-top:8px;">
                                        Jefe rechazó, pero RH aún puede tomar la decisión final.
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11" class="vacio">
                            No hay solicitudes de vacaciones para mostrar.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>