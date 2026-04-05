<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmt_date(?string $dt): string {
    if (!$dt || $dt === '0000-00-00' || $dt === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y', $ts) : '-';
}

function fmt_datetime(?string $dt): string {
    if (!$dt || $dt === '0000-00-00' || $dt === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i', $ts) : '-';
}

function check_label_contextual($value, string $tipo = 'si_no'): string {
    if ($value === null || $value === '') return 'Sin revisar';

    $esSi = ((string)$value === '1');

    $labels = [
        'si_no' => [
            'yes' => 'Sí',
            'no'  => 'No',
        ],
        'funciona' => [
            'yes' => 'Funciona',
            'no'  => 'No funciona',
        ],
        'presenta' => [
            'yes' => 'Presenta',
            'no'  => 'No presenta',
        ],
        'detecta' => [
            'yes' => 'Se detecta',
            'no'  => 'No se detecta',
        ],
        'tiene' => [
            'yes' => 'Sí tiene',
            'no'  => 'No tiene',
        ],
    ];

    $cfg = $labels[$tipo] ?? $labels['si_no'];
    return $esSi ? $cfg['yes'] : $cfg['no'];
}

function money($n): string {
    return '$' . number_format((float)$n, 2);
}

function si_no($value): string {
    if ($value === null || $value === '') return '-';
    return ((int)$value === 1) ? 'Sí' : 'No';
}

function respuesta_cliente_label($value): string {
    if ($value === null || $value === '') return 'Pendiente';
    return ((int)$value === 1) ? 'Acepta reparación' : 'No acepta reparación';
}

function table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param("s", $table);
    $st->execute();
    $ok = ($st->get_result()->num_rows > 0);
    $st->close();
    return $ok;
}

function registrar_documento_generado(
    mysqli $conn,
    int $idGarantia,
    string $tipoDocumento,
    int $idUsuario,
    string $nombreArchivo,
    string $rutaArchivo,
    string $mimeType = 'text/html'
): void {
    if (!table_exists($conn, 'garantias_documentos')) {
        return;
    }

    $hoy = date('Y-m-d');

    $sqlFind = "SELECT id
                FROM garantias_documentos
                WHERE id_garantia = ?
                  AND tipo_documento = ?
                  AND generado_por = ?
                  AND DATE(fecha_generado) = ?
                ORDER BY id DESC
                LIMIT 1";
    $st = $conn->prepare($sqlFind);
    if (!$st) {
        throw new Exception("Error en prepare() de búsqueda de documento: " . $conn->error);
    }
    $st->bind_param("isis", $idGarantia, $tipoDocumento, $idUsuario, $hoy);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    if ($row && !empty($row['id'])) {
        $idDoc = (int)$row['id'];

        $sqlUp = "UPDATE garantias_documentos
                  SET nombre_archivo = ?,
                      ruta_archivo = ?,
                      mime_type = ?,
                      fecha_generado = NOW(),
                      activo = 1
                  WHERE id = ?";
        $st = $conn->prepare($sqlUp);
        if (!$st) {
            throw new Exception("Error en prepare() de update documento: " . $conn->error);
        }
        $st->bind_param("sssi", $nombreArchivo, $rutaArchivo, $mimeType, $idDoc);
        if (!$st->execute()) {
            throw new Exception("Error al actualizar documento generado: " . $st->error);
        }
        $st->close();
        return;
    }

    $sqlIns = "INSERT INTO garantias_documentos
               (id_garantia, tipo_documento, nombre_archivo, ruta_archivo, mime_type, generado_por, fecha_generado, activo)
               VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)";
    $st = $conn->prepare($sqlIns);
    if (!$st) {
        throw new Exception("Error en prepare() de insert documento: " . $conn->error);
    }
    $st->bind_param("issssi", $idGarantia, $tipoDocumento, $nombreArchivo, $rutaArchivo, $mimeType, $idUsuario);
    if (!$st->execute()) {
        throw new Exception("Error al insertar documento generado: " . $st->error);
    }
    $st->close();
}

function nombre_archivo_documento(string $folio, string $tipoDocumento): string {
    $folio = preg_replace('/[^A-Za-z0-9\-_]/', '_', $folio);
    $tipoDocumento = preg_replace('/[^A-Za-z0-9\-_]/', '_', $tipoDocumento);
    return $folio . '_' . $tipoDocumento . '.html';
}

function obtener_contexto_empresa(): array {
    $empresaSesion = strtolower(trim((string)($_SESSION['empresa'] ?? $_SESSION['propiedad'] ?? 'luga')));
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));

    if (in_array($empresaSesion, ['nano', 'nanored', 'nano red'], true)) {
        return [
            'clave'        => 'nano',
            'nombre'       => 'NANO',
            'nombre_largo' => 'Nano',
            'logo'         => 'assets/logo_luga_nano.png',
            'color'        => '#0f766e',
            'color_soft'   => '#ccfbf1',
            'color_dark'   => '#134e4a',
        ];
    }

    if (in_array($empresaSesion, ['luga', 'lugaph', 'luga ph'], true)) {
        return [
            'clave'        => 'luga',
            'nombre'       => 'LUGA',
            'nombre_largo' => 'Luga',
            'logo'         => 'assets/logo_luga.png',
            'color'        => '#1d4ed8',
            'color_soft'   => '#dbeafe',
            'color_dark'   => '#1e3a8a',
        ];
    }

    if (strpos($host, 'nano') !== false) {
        return [
            'clave'        => 'nano',
            'nombre'       => 'NANO',
            'nombre_largo' => 'Nano',
            'logo'         => 'assets/logo_luga_nano.png',
            'color'        => '#0f766e',
            'color_soft'   => '#ccfbf1',
            'color_dark'   => '#134e4a',
        ];
    }

    return [
        'clave'        => 'luga',
        'nombre'       => 'LUGA',
        'nombre_largo' => 'Luga',
        'logo'         => 'assets/logo_luga.png',
        'color'        => '#1d4ed8',
        'color_soft'   => '#dbeafe',
        'color_dark'   => '#1e3a8a',
    ];
}

function base_url_actual(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $scheme . '://' . $host . ($dir ? $dir : '');
}

function resolver_logo_url(string $rutaRelativa): string {
    $rutaFisica = __DIR__ . '/' . ltrim($rutaRelativa, '/');
    if (!is_file($rutaFisica)) {
        return '';
    }
    return base_url_actual() . '/' . ltrim($rutaRelativa, '/');
}

function estado_badge_class(?string $estado): string {
    $e = strtolower(trim((string)$estado));

    return match (true) {
        str_contains($e, 'entregado'),
        str_contains($e, 'cerrado'),
        str_contains($e, 'finalizado') => 'ok',

        str_contains($e, 'proceso'),
        str_contains($e, 'diagnostico'),
        str_contains($e, 'reparacion'),
        str_contains($e, 'enviado') => 'warn',

        str_contains($e, 'no procede'),
        str_contains($e, 'cancelado'),
        str_contains($e, 'rechazado') => 'danger',

        default => 'neutral',
    };
}

/* =========================================================
   INPUT
========================================================= */
$id = (int)($_GET['id'] ?? 0);
$tipo = trim((string)($_GET['tipo'] ?? 'recepcion_garantia'));
$idUsuarioSesion = (int)($_SESSION['id_usuario'] ?? 0);

if ($id <= 0) {
    exit('ID de garantía inválido.');
}

$tiposPermitidos = [
    'recepcion_garantia',
    'entrega_garantia',
    'no_procede',
    'cotizacion_reparacion',
    'envio_diagnostico',
    'respuesta_cliente_reparacion',
    'entrega_equipo_reparado',
    'devolucion_sin_reparacion'
];

if (!in_array($tipo, $tiposPermitidos, true)) {
    exit('Tipo de documento no permitido.');
}

/* =========================================================
   CARGAR CASO
========================================================= */
$sql = "SELECT
            gc.*,
            s.nombre AS sucursal_nombre,
            uc.nombre AS capturista_nombre,
            ul.nombre AS logistica_nombre,
            ug.nombre AS gerente_nombre
        FROM garantias_casos gc
        LEFT JOIN sucursales s ON s.id = gc.id_sucursal
        LEFT JOIN usuarios uc ON uc.id = gc.id_usuario_captura
        LEFT JOIN usuarios ul ON ul.id = gc.id_usuario_logistica
        LEFT JOIN usuarios ug ON ug.id = gc.id_usuario_gerente
        WHERE gc.id = ?
        LIMIT 1";

$st = $conn->prepare($sql);
if (!$st) {
    exit('Error al consultar el caso: ' . h($conn->error));
}
$st->bind_param("i", $id);
$st->execute();
$caso = $st->get_result()->fetch_assoc();
$st->close();

if (!$caso) {
    exit('No se encontró la garantía.');
}

/* =========================================================
   REEMPLAZO
========================================================= */
$reemplazo = null;
if (table_exists($conn, 'garantias_reemplazos')) {
    $sqlR = "SELECT *
             FROM garantias_reemplazos
             WHERE id_garantia = ?
             ORDER BY id DESC
             LIMIT 1";
    $st = $conn->prepare($sqlR);
    if ($st) {
        $st->bind_param("i", $id);
        $st->execute();
        $reemplazo = $st->get_result()->fetch_assoc();
        $st->close();
    }
}

/* =========================================================
   REPARACION
========================================================= */
$reparacion = null;
if (table_exists($conn, 'garantias_reparaciones')) {
    $sqlRep = "SELECT *
               FROM garantias_reparaciones
               WHERE id_garantia = ?
               ORDER BY id DESC
               LIMIT 1";
    $st = $conn->prepare($sqlRep);
    if ($st) {
        $st->bind_param("i", $id);
        $st->execute();
        $reparacion = $st->get_result()->fetch_assoc();
        $st->close();
    }
}

/* =========================================================
   DATOS DERIVADOS REALES
========================================================= */
$proveedorNombre = trim((string)($reparacion['proveedor_nombre'] ?? ''));
$tipoServicio = trim((string)($reparacion['tipo_servicio'] ?? ''));
$diagnosticoProveedor = trim((string)($reparacion['diagnostico_proveedor'] ?? ''));
$reparable = $reparacion['reparable'] ?? null;

$costoRevision = (float)($reparacion['costo_revision'] ?? 0);
$costoReparacion = (float)($reparacion['costo_reparacion'] ?? 0);
$costoTotal = isset($reparacion['costo_total']) ? (float)$reparacion['costo_total'] : ($costoRevision + $costoReparacion);

$tiempoEstimadoDias = trim((string)($reparacion['tiempo_estimado_dias'] ?? ''));
$tiempoEstimadoTexto = $tiempoEstimadoDias !== '' ? ($tiempoEstimadoDias . ' día(s)') : '-';

$clienteAceptaRep = $reparacion['cliente_acepta'] ?? null;
$clienteAceptaCaso = $caso['cliente_acepta_cotizacion'] ?? null;
$clienteAceptaFinal = ($clienteAceptaRep !== null && $clienteAceptaRep !== '') ? $clienteAceptaRep : $clienteAceptaCaso;

$fechaEnvioDiagnostico = $reparacion['fecha_envio'] ?? $caso['fecha_envio_proveedor'] ?? null;
$fechaRespuestaProveedor = $reparacion['fecha_respuesta_proveedor'] ?? $caso['fecha_respuesta_proveedor'] ?? null;
$fechaRespuestaCliente = $reparacion['fecha_respuesta_cliente'] ?? $caso['fecha_autorizacion_cliente'] ?? null;
$fechaIngresoReparacion = $reparacion['fecha_ingreso_reparacion'] ?? null;
$fechaEquipoReparado = $reparacion['fecha_equipo_reparado'] ?? null;
$fechaDevolucion = $reparacion['fecha_devolucion'] ?? null;
$fechaEntregaFinal = $caso['fecha_entrega'] ?? null;

$observacionesLogisticaRep = trim((string)($reparacion['observaciones_logistica'] ?? ''));
$observacionesClienteRep = trim((string)($reparacion['observaciones_cliente'] ?? ''));

$observacionesTiendaCaso = trim((string)($caso['observaciones_tienda'] ?? ''));
$observacionesLogisticaCaso = trim((string)($caso['observaciones_logistica'] ?? ''));
$observacionesCierreCaso = trim((string)($caso['observaciones_cierre'] ?? ''));

$respuestaClienteTexto = respuesta_cliente_label($clienteAceptaFinal);

/* =========================================================
   BRANDING
========================================================= */
$empresaCtx = obtener_contexto_empresa();
$empresaNombre = $empresaCtx['nombre'];
$empresaNombreLargo = $empresaCtx['nombre_largo'];
$empresaColor = $empresaCtx['color'];
$empresaColorSoft = $empresaCtx['color_soft'];
$empresaColorDark = $empresaCtx['color_dark'];

$logoUrl = resolver_logo_url($empresaCtx['logo']);
$logoPath = __DIR__ . '/' . ltrim($empresaCtx['logo'], '/');
$tieneLogo = $logoUrl !== '' && is_file($logoPath);

$estadoBadgeClass = estado_badge_class((string)($caso['estado'] ?? ''));

/* =========================================================
   REGISTRO DOCUMENTAL
========================================================= */
try {
    $tituloDoc = match ($tipo) {
        'recepcion_garantia'           => 'ORDEN DE SERVICIO / RECEPCIÓN DE GARANTÍA',
        'entrega_garantia'             => 'FORMATO DE ENTREGA DE EQUIPO POR GARANTÍA',
        'no_procede'                   => 'DICTAMEN DE GARANTÍA NO PROCEDENTE',
        'cotizacion_reparacion'        => 'COTIZACIÓN DE REPARACIÓN',
        'envio_diagnostico'            => 'FORMATO DE ENVÍO A DIAGNÓSTICO / PROVEEDOR',
        'respuesta_cliente_reparacion' => 'RESPUESTA DEL CLIENTE A COTIZACIÓN DE REPARACIÓN',
        'entrega_equipo_reparado'      => 'FORMATO DE ENTREGA DE EQUIPO REPARADO',
        'devolucion_sin_reparacion'    => 'FORMATO DE DEVOLUCIÓN DE EQUIPO SIN REPARACIÓN',
        default                        => 'DOCUMENTO DE GARANTÍA'
    };

    $nombreArchivo = nombre_archivo_documento((string)$caso['folio'], $tipo);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $self = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    $rutaArchivo = $scheme . '://' . $host . $self . '?id=' . urlencode((string)$id) . '&tipo=' . urlencode($tipo);

    registrar_documento_generado(
        $conn,
        (int)$caso['id'],
        $tipo,
        $idUsuarioSesion,
        $nombreArchivo,
        $rutaArchivo,
        'text/html'
    );
} catch (Throwable $e) {
    // No detenemos la visualización si falla la bitácora
}

/* =========================================================
   TITULOS
========================================================= */
$tituloDoc = match ($tipo) {
    'recepcion_garantia'           => 'ORDEN DE SERVICIO / RECEPCIÓN DE GARANTÍA',
    'entrega_garantia'             => 'FORMATO DE ENTREGA DE EQUIPO POR GARANTÍA',
    'no_procede'                   => 'DICTAMEN DE GARANTÍA NO PROCEDENTE',
    'cotizacion_reparacion'        => 'COTIZACIÓN DE REPARACIÓN',
    'envio_diagnostico'            => 'FORMATO DE ENVÍO A DIAGNÓSTICO / PROVEEDOR',
    'respuesta_cliente_reparacion' => 'RESPUESTA DEL CLIENTE A COTIZACIÓN DE REPARACIÓN',
    'entrega_equipo_reparado'      => 'FORMATO DE ENTREGA DE EQUIPO REPARADO',
    'devolucion_sin_reparacion'    => 'FORMATO DE DEVOLUCIÓN DE EQUIPO SIN REPARACIÓN',
    default                        => 'DOCUMENTO DE GARANTÍA'
};

$fechaGeneracion = date('d/m/Y H:i');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= h($tituloDoc) ?> | <?= h($caso['folio']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root{
            --brand: <?= h($empresaColor) ?>;
            --brand-soft: <?= h($empresaColorSoft) ?>;
            --brand-dark: <?= h($empresaColorDark) ?>;
            --text: #111827;
            --muted: #6b7280;
            --line: #d1d5db;
            --line-soft: #e5e7eb;
            --bg: #eef2f7;
            --white: #ffffff;
            --shadow: 0 10px 30px rgba(15, 23, 42, 0.10);
            --radius: 12px;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(255,255,255,0.7), transparent 38%),
                linear-gradient(180deg, #f8fafc 0%, var(--bg) 100%);
        }

        .toolbar {
            width: 210mm;
            margin: 14px auto 0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn {
            border: 0;
            border-radius: 10px;
            padding: 10px 16px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            box-shadow: 0 4px 14px rgba(0,0,0,0.08);
            transition: .18s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-print {
            background: var(--brand);
            color: #fff;
        }

        .btn-back {
            background: #fff;
            color: var(--text);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            border: 1px solid #dbe1ea;
        }

        .page {
            position: relative;
            width: 210mm;
            min-height: 297mm;
            margin: 12px auto 18px;
            background: var(--white);
            padding: 14mm 14mm 18mm;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .page::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 8px;
            background: linear-gradient(90deg, var(--brand-dark) 0%, var(--brand) 65%, var(--brand-soft) 100%);
        }

        .watermark {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            z-index: 0;
        }

        .watermark img {
            max-width: 58%;
            max-height: 45%;
            opacity: 0.045;
            object-fit: contain;
            filter: grayscale(100%);
        }

        .content {
            position: relative;
            z-index: 1;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            border-bottom: 2px solid var(--line-soft);
            padding-bottom: 12px;
            margin-bottom: 16px;
        }

        .brand-wrap {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            flex: 1;
            min-width: 0;
        }

        .logo-box {
            width: 104px;
            min-width: 104px;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            padding: 10px;
        }

        .logo-box img {
            max-width: 100%;
            max-height: 62px;
            object-fit: contain;
            display: block;
        }

        .brand-text {
            flex: 1;
            min-width: 0;
        }

        .brand-company {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            color: var(--brand-dark);
            letter-spacing: .9px;
            text-transform: uppercase;
            background: var(--brand-soft);
            border: 1px solid rgba(0,0,0,0.06);
            padding: 4px 10px;
            border-radius: 999px;
            margin-bottom: 8px;
        }

        .doc-title {
            font-size: 23px;
            font-weight: 800;
            margin: 0 0 6px 0;
            line-height: 1.2;
            color: #0f172a;
        }

        .brand {
            font-size: 12px;
            color: #4b5563;
        }

        .folio-box {
            min-width: 200px;
            text-align: right;
            border: 1px solid var(--line-soft);
            border-radius: 12px;
            padding: 10px 12px;
            background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        }

        .folio-box .label {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .7px;
            margin-bottom: 4px;
        }

        .folio-box .value {
            font-size: 20px;
            font-weight: 800;
            color: var(--brand-dark);
            line-height: 1.1;
        }

        .meta-strip {
            display: grid;
            grid-template-columns: 1.3fr 1fr 1fr;
            gap: 10px;
            margin-bottom: 14px;
        }

        .meta-card {
            border: 1px solid var(--line-soft);
            background: #fbfdff;
            border-radius: 12px;
            padding: 10px 12px;
        }

        .meta-card .meta-label {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .meta-card .meta-value {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
        }

        .badge-estado {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-estado.ok {
            background: #dcfce7;
            color: #166534;
        }

        .badge-estado.warn {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-estado.danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-estado.neutral {
            background: #e5e7eb;
            color: #374151;
        }

        .section {
            margin-top: 16px;
        }

        .section h3 {
            font-size: 15px;
            margin: 0 0 10px 0;
            padding: 9px 12px;
            border-left: 5px solid var(--brand);
            background: linear-gradient(90deg, var(--brand-soft) 0%, rgba(255,255,255,0) 100%);
            border-radius: 10px;
            color: #0f172a;
        }

        .grid {
            display: grid;
            gap: 10px;
        }

        .grid-2 { grid-template-columns: 1fr 1fr; }
        .grid-3 { grid-template-columns: 1fr 1fr 1fr; }
        .grid-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }

        .field {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 9px 11px;
            min-height: 58px;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        }

        .field .label {
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        .field .value {
            font-size: 14px;
            font-weight: 700;
            word-break: break-word;
            color: #111827;
            line-height: 1.35;
        }

        .big-box {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 13px 14px;
            min-height: 92px;
            white-space: pre-wrap;
            line-height: 1.55;
            background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            overflow: hidden;
            border-radius: 12px;
        }

        th, td {
            border: 1px solid var(--line);
            padding: 9px 10px;
            font-size: 13px;
            vertical-align: top;
        }

        th {
            background: #f8fafc;
            text-align: left;
            color: #0f172a;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        tr:nth-child(even) td {
            background: #fcfdff;
        }

        .notes {
            margin-top: 18px;
            border: 1px solid #dbe6f5;
            border-radius: 12px;
            padding: 13px 14px;
            background: linear-gradient(180deg, #f8fbff 0%, #fdfefe 100%);
            font-size: 12px;
            line-height: 1.6;
            color: #334155;
        }

        .signature-area {
            margin-top: 32px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }

        .signature-box {
            text-align: center;
            padding-top: 46px;
        }

        .signature-line {
            border-top: 1px solid #0f172a;
            margin-bottom: 7px;
        }

        .signature-label {
            font-size: 12px;
            color: #475569;
            font-weight: 700;
        }

        .footer-doc {
            margin-top: 28px;
            padding-top: 12px;
            border-top: 1px dashed var(--line);
            display: flex;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            font-size: 11px;
            color: #6b7280;
        }

        .footer-doc strong {
            color: #111827;
        }

        @media print {
            @page {
                size: A4;
                margin: 10mm;
            }

            body {
                background: #fff;
            }

            .toolbar {
                display: none !important;
            }

            .page {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                box-shadow: none;
                background: #fff;
            }

            .page::before {
                height: 6px;
            }

            .field,
            .big-box,
            .meta-card,
            .folio-box,
            .logo-box,
            .notes {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <a class="btn btn-back" href="garantias_detalle.php?id=<?= (int)$caso['id'] ?>">Volver</a>
    <button class="btn btn-print" onclick="window.print()">Imprimir</button>
</div>

<div class="page">
    <?php if ($tieneLogo): ?>
        <div class="watermark">
            <img src="<?= h($logoUrl) ?>" alt="Marca de agua">
        </div>
    <?php endif; ?>

    <div class="content">
        <div class="topbar">
            <div class="brand-wrap">
                <?php if ($tieneLogo): ?>
                    <div class="logo-box">
                        <img src="<?= h($logoUrl) ?>" alt="Logo <?= h($empresaNombre) ?>">
                    </div>
                <?php endif; ?>

                <div class="brand-text">
                    <div class="brand-company"><?= h($empresaNombre) ?> • Gestión de Garantías</div>
                    <h1 class="doc-title"><?= h($tituloDoc) ?></h1>
                    <div class="brand"><?= h($empresaNombreLargo) ?> Central • Módulo de Garantías y Reparaciones</div>
                </div>
            </div>

            <div class="folio-box">
                <div class="label">Folio</div>
                <div class="value"><?= h($caso['folio']) ?></div>
            </div>
        </div>

        <div class="meta-strip">
            <div class="meta-card">
                <div class="meta-label">Sucursal</div>
                <div class="meta-value"><?= h($caso['sucursal_nombre'] ?: '-') ?></div>
            </div>

            <div class="meta-card">
                <div class="meta-label">Estado del caso</div>
                <div class="meta-value">
                    <span class="badge-estado <?= h($estadoBadgeClass) ?>">
                        <?= h($caso['estado'] ?: 'Sin estado') ?>
                    </span>
                </div>
            </div>

            <div class="meta-card">
                <div class="meta-label">Generado</div>
                <div class="meta-value"><?= h($fechaGeneracion) ?></div>
            </div>
        </div>

        <?php if ($tipo === 'recepcion_garantia'): ?>

            <div class="section">
                <h3>1. Información de recepción</h3>
                <div class="grid grid-4">
                    <div class="field">
                        <div class="label">ID de caso</div>
                        <div class="value"><?= h($caso['id']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Fecha</div>
                        <div class="value"><?= h(fmt_datetime($caso['fecha_captura'])) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Tienda</div>
                        <div class="value"><?= h($caso['sucursal_nombre']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Responsable</div>
                        <div class="value"><?= h($caso['capturista_nombre']) ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>2. Datos del cliente</h3>
                <div class="grid grid-3">
                    <div class="field">
                        <div class="label">Nombre</div>
                        <div class="value"><?= h($caso['cliente_nombre']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Teléfono</div>
                        <div class="value"><?= h($caso['cliente_telefono']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Correo</div>
                        <div class="value"><?= h($caso['cliente_correo']) ?: '-' ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>3. Datos del equipo</h3>
                <div class="grid grid-4">
                    <div class="field">
                        <div class="label">Marca</div>
                        <div class="value"><?= h($caso['marca']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Modelo</div>
                        <div class="value"><?= h($caso['modelo']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Color</div>
                        <div class="value"><?= h($caso['color']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Capacidad</div>
                        <div class="value"><?= h($caso['capacidad']) ?></div>
                    </div>
                </div>

                <div class="grid grid-3" style="margin-top:10px;">
                    <div class="field">
                        <div class="label">IMEI 1</div>
                        <div class="value"><?= h($caso['imei_original']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">IMEI 2</div>
                        <div class="value"><?= h($caso['imei2_original']) ?: '-' ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Fecha de compra</div>
                        <div class="value"><?= h(fmt_date($caso['fecha_compra'])) ?></div>
                    </div>
                </div>

                <div class="grid grid-2" style="margin-top:10px;">
                    <div class="field">
                        <div class="label">Ticket / TAG</div>
                        <div class="value"><?= h($caso['tag_venta']) ?: '-' ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Modalidad / Financiera</div>
                        <div class="value">
                            <?= h($caso['modalidad_venta']) ?: '-' ?>
                            <?= !empty($caso['financiera']) ? ' • ' . h($caso['financiera']) : '' ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>4. Descripción de falla</h3>
                <div class="big-box"><?= h($caso['descripcion_falla']) ?: 'Sin descripción registrada.' ?></div>
            </div>

            <div class="section">
                <h3>5. Checklist técnico</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Revisión</th>
                            <th>Resultado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>Encendido</td><td><?= h(check_label_contextual($caso['check_encendido'], 'si_no')) ?></td></tr>
                        <tr><td>Daño físico</td><td><?= h(check_label_contextual($caso['check_dano_fisico'], 'presenta')) ?></td></tr>
                        <tr><td>Humedad</td><td><?= h(check_label_contextual($caso['check_humedad'], 'detecta')) ?></td></tr>
                        <tr><td>Pantalla</td><td><?= h(check_label_contextual($caso['check_pantalla'], 'funciona')) ?></td></tr>
                        <tr><td>Cámara</td><td><?= h(check_label_contextual($caso['check_camara'], 'funciona')) ?></td></tr>
                        <tr><td>Bocina / Micrófono</td><td><?= h(check_label_contextual($caso['check_bocina_microfono'], 'funciona')) ?></td></tr>
                        <tr><td>Puerto de carga</td><td><?= h(check_label_contextual($caso['check_puerto_carga'], 'funciona')) ?></td></tr>
                        <tr><td>App financiera instalada</td><td><?= h(check_label_contextual($caso['check_app_financiera'], 'tiene')) ?></td></tr>
                        <tr><td>Bloqueo patrón / Google</td><td><?= h(check_label_contextual($caso['check_bloqueo_patron_google'], 'tiene')) ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="section">
                <h3>6. Resultado preliminar</h3>
                <div class="grid grid-3">
                    <div class="field">
                        <div class="label">Dictamen preliminar</div>
                        <div class="value"><?= h($caso['dictamen_preliminar']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Motivo no procede</div>
                        <div class="value"><?= h($caso['motivo_no_procede']) ?: '-' ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Estado actual</div>
                        <div class="value"><?= h($caso['estado']) ?></div>
                    </div>
                </div>

                <div class="big-box" style="margin-top:10px; min-height:70px;"><?= h($caso['detalle_no_procede']) ?: 'Sin observación adicional del sistema.' ?></div>
            </div>

            <div class="notes">
                <strong>Condiciones generales de garantía:</strong><br>
                El equipo será evaluado conforme a las políticas internas y, en su caso, por el proveedor.
                La garantía puede no aplicar cuando exista daño físico, humedad, manipulación no autorizada,
                alteraciones en aplicaciones financieras o bloqueos por cuenta/patrón. El cliente será informado
                del resultado del diagnóstico y del siguiente paso correspondiente.
            </div>

            <div class="signature-area">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma del cliente</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma del responsable de tienda</div>
                </div>
            </div>

        <?php elseif ($tipo === 'entrega_garantia'): ?>

            <div class="section">
                <h3>Entrega de equipo</h3>
                <div class="grid grid-3">
                    <div class="field">
                        <div class="label">Cliente</div>
                        <div class="value"><?= h($caso['cliente_nombre']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Fecha de entrega</div>
                        <div class="value"><?= h(fmt_datetime($caso['fecha_entrega'])) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Sucursal</div>
                        <div class="value"><?= h($caso['sucursal_nombre']) ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>Equipo original</h3>
                <div class="grid grid-2">
                    <div class="field">
                        <div class="label">Equipo</div>
                        <div class="value"><?= h(trim(($caso['marca'] ?? '') . ' ' . ($caso['modelo'] ?? ''))) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">IMEI original</div>
                        <div class="value"><?= h($caso['imei_original']) ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>Equipo entregado</h3>
                <?php if ($reemplazo): ?>
                    <div class="grid grid-2">
                        <div class="field">
                            <div class="label">Equipo reemplazo</div>
                            <div class="value"><?= h(trim(($reemplazo['marca_reemplazo'] ?? '') . ' ' . ($reemplazo['modelo_reemplazo'] ?? ''))) ?></div>
                        </div>
                        <div class="field">
                            <div class="label">IMEI reemplazo</div>
                            <div class="value"><?= h($reemplazo['imei_reemplazo']) ?></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="big-box">No existe reemplazo capturado para este caso.</div>
                <?php endif; ?>
            </div>

            <div class="signature-area">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma del cliente</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma del responsable de entrega</div>
                </div>
            </div>

        <?php elseif ($tipo === 'no_procede'): ?>

            <div class="section">
                <h3>Resultado del dictamen</h3>
                <div class="grid grid-3">
                    <div class="field">
                        <div class="label">Cliente</div>
                        <div class="value"><?= h($caso['cliente_nombre']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Folio</div>
                        <div class="value"><?= h($caso['folio']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Fecha</div>
                        <div class="value"><?= h(fmt_datetime($caso['fecha_dictamen'] ?: $caso['fecha_captura'])) ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>Motivo</h3>
                <div class="big-box"><?= h($caso['motivo_no_procede']) ?: 'Sin motivo especificado.' ?></div>
            </div>

            <div class="section">
                <h3>Detalle</h3>
                <div class="big-box"><?= h($caso['detalle_no_procede']) ?: 'Sin detalle adicional.' ?></div>
            </div>

            <div class="signature-area">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma del cliente</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma del responsable</div>
                </div>
            </div>

        <?php elseif ($tipo === 'cotizacion_reparacion'): ?>

            <div class="section">
                <h3>Cotización de reparación</h3>
                <div class="grid grid-4">
                    <div class="field">
                        <div class="label">Cliente</div>
                        <div class="value"><?= h($caso['cliente_nombre']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Equipo</div>
                        <div class="value"><?= h(trim(($caso['marca'] ?? '') . ' ' . ($caso['modelo'] ?? ''))) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">IMEI</div>
                        <div class="value"><?= h($caso['imei_original']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Proveedor</div>
                        <div class="value"><?= h($proveedorNombre ?: '-') ?></div>
                    </div>
                </div>

                <div class="grid grid-3" style="margin-top:10px;">
                    <div class="field">
                        <div class="label">Fecha respuesta</div>
                        <div class="value"><?= h(fmt_datetime($fechaRespuestaProveedor)) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Reparable</div>
                        <div class="value"><?= h(si_no($reparable)) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Tiempo estimado</div>
                        <div class="value"><?= h($tiempoEstimadoTexto) ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>Diagnóstico</h3>
                <div class="big-box"><?= h($diagnosticoProveedor ?: 'Sin diagnóstico.') ?></div>
            </div>

            <div class="section">
                <h3>Importes</h3>
                <div class="grid grid-3">
                    <div class="field">
                        <div class="label">Costo revisión</div>
                        <div class="value"><?= money($costoRevision) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Costo reparación</div>
                        <div class="value"><?= money($costoReparacion) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Costo total</div>
                        <div class="value"><?= money($costoTotal) ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>Observaciones</h3>
                <div class="big-box"><?= h($observacionesLogisticaRep ?: $observacionesLogisticaCaso) ?: 'Sin observaciones adicionales.' ?></div>
            </div>

            <div class="signature-area">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Aceptación del cliente</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma del responsable</div>
                </div>
            </div>

        <?php elseif ($tipo === 'envio_diagnostico'): ?>

            <div class="section">
                <h3>1. Datos del envío</h3>
                <div class="grid grid-4">
                    <div class="field">
                        <div class="label">Fecha de envío</div>
                        <div class="value"><?= h(fmt_datetime($fechaEnvioDiagnostico ?: $caso['fecha_captura'])) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Sucursal origen</div>
                        <div class="value"><?= h($caso['sucursal_nombre']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Responsable tienda</div>
                        <div class="value"><?= h($caso['capturista_nombre']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Proveedor / taller</div>
                        <div class="value"><?= h($proveedorNombre ?: 'Por definir') ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>2. Cliente y equipo</h3>
                <div class="grid grid-3">
                    <div class="field">
                        <div class="label">Cliente</div>
                        <div class="value"><?= h($caso['cliente_nombre']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Teléfono</div>
                        <div class="value"><?= h($caso['cliente_telefono']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Correo</div>
                        <div class="value"><?= h($caso['cliente_correo']) ?: '-' ?></div>
                    </div>
                </div>

                <div class="grid grid-4" style="margin-top:10px;">
                    <div class="field">
                        <div class="label">Marca</div>
                        <div class="value"><?= h($caso['marca']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Modelo</div>
                        <div class="value"><?= h($caso['modelo']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">IMEI 1</div>
                        <div class="value"><?= h($caso['imei_original']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">IMEI 2</div>
                        <div class="value"><?= h($caso['imei2_original']) ?: '-' ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>3. Falla reportada y checklist</h3>
                <div class="big-box"><?= h($caso['descripcion_falla']) ?: 'Sin descripción de falla.' ?></div>

                <table style="margin-top:10px;">
                    <thead>
                        <tr>
                            <th>Revisión</th>
                            <th>Resultado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>Encendido</td><td><?= h(check_label_contextual($caso['check_encendido'], 'si_no')) ?></td></tr>
                        <tr><td>Daño físico</td><td><?= h(check_label_contextual($caso['check_dano_fisico'], 'presenta')) ?></td></tr>
                        <tr><td>Humedad</td><td><?= h(check_label_contextual($caso['check_humedad'], 'detecta')) ?></td></tr>
                        <tr><td>Pantalla</td><td><?= h(check_label_contextual($caso['check_pantalla'], 'funciona')) ?></td></tr>
                        <tr><td>Cámara</td><td><?= h(check_label_contextual($caso['check_camara'], 'funciona')) ?></td></tr>
                        <tr><td>Bocina / Micrófono</td><td><?= h(check_label_contextual($caso['check_bocina_microfono'], 'funciona')) ?></td></tr>
                        <tr><td>Puerto de carga</td><td><?= h(check_label_contextual($caso['check_puerto_carga'], 'funciona')) ?></td></tr>
                        <tr><td>App financiera instalada</td><td><?= h(check_label_contextual($caso['check_app_financiera'], 'tiene')) ?></td></tr>
                        <tr><td>Bloqueo patrón / Google</td><td><?= h(check_label_contextual($caso['check_bloqueo_patron_google'], 'tiene')) ?></td></tr>
                    </tbody>
                </table>
            </div>

            <div class="section">
                <h3>4. Observaciones</h3>
                <div class="big-box">
                    <?= h($observacionesLogisticaRep ?: $observacionesLogisticaCaso ?: $observacionesTiendaCaso) ?: 'Equipo enviado a diagnóstico con proveedor conforme a seguimiento del caso.' ?>
                </div>
            </div>

            <div class="notes">
                El presente formato deja constancia de que el equipo descrito fue enviado a revisión técnica con proveedor o taller externo para diagnóstico y determinación de costo/tiempo de posible reparación.
            </div>

            <div class="signature-area">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Entrega tienda / logística</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Recibe proveedor / taller</div>
                </div>
            </div>

        <?php elseif ($tipo === 'respuesta_cliente_reparacion'): ?>

            <div class="section">
                <h3>1. Datos generales</h3>
                <div class="grid grid-4">
                    <div class="field">
                        <div class="label">Cliente</div>
                        <div class="value"><?= h($caso['cliente_nombre']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Teléfono</div>
                        <div class="value"><?= h($caso['cliente_telefono']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Fecha de respuesta</div>
                        <div class="value"><?= h(fmt_datetime($fechaRespuestaCliente ?: $fechaRespuestaProveedor ?: $caso['fecha_captura'])) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Proveedor</div>
                        <div class="value"><?= h($proveedorNombre ?: '-') ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>2. Equipo y diagnóstico</h3>
                <div class="grid grid-3">
                    <div class="field">
                        <div class="label">Equipo</div>
                        <div class="value"><?= h(trim(($caso['marca'] ?? '') . ' ' . ($caso['modelo'] ?? ''))) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">IMEI</div>
                        <div class="value"><?= h($caso['imei_original']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Tiempo estimado</div>
                        <div class="value"><?= h($tiempoEstimadoTexto) ?></div>
                    </div>
                </div>

                <div class="big-box" style="margin-top:10px;"><?= h($diagnosticoProveedor ?: 'Sin diagnóstico registrado.') ?></div>
            </div>

            <div class="section">
                <h3>3. Cotización</h3>
                <div class="grid grid-3">
                    <div class="field">
                        <div class="label">Costo revisión</div>
                        <div class="value"><?= money($costoRevision) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Costo reparación</div>
                        <div class="value"><?= money($costoReparacion) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Costo total</div>
                        <div class="value"><?= money($costoTotal) ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>4. Respuesta del cliente</h3>
                <div class="grid grid-2">
                    <div class="field">
                        <div class="label">Decisión</div>
                        <div class="value"><?= h($respuestaClienteTexto) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Estado actual</div>
                        <div class="value"><?= h($caso['estado']) ?></div>
                    </div>
                </div>

                <div class="big-box" style="margin-top:10px; min-height:70px;">
                    <?= h($observacionesClienteRep ?: $observacionesLogisticaRep ?: $observacionesLogisticaCaso) ?: 'El cliente fue informado del diagnóstico, costo y tiempo estimado, dejando asentada su decisión respecto a la reparación.' ?>
                </div>
            </div>

            <div class="signature-area">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma del cliente</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma del responsable</div>
                </div>
            </div>

        <?php elseif ($tipo === 'entrega_equipo_reparado'): ?>

            <div class="section">
                <h3>1. Datos de entrega</h3>
                <div class="grid grid-4">
                    <div class="field">
                        <div class="label">Cliente</div>
                        <div class="value"><?= h($caso['cliente_nombre']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Fecha equipo reparado</div>
                        <div class="value"><?= h(fmt_datetime($fechaEquipoReparado ?: $fechaEntregaFinal ?: date('Y-m-d H:i:s'))) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Sucursal</div>
                        <div class="value"><?= h($caso['sucursal_nombre']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Responsable</div>
                        <div class="value"><?= h($caso['capturista_nombre']) ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>2. Equipo reparado</h3>
                <div class="grid grid-4">
                    <div class="field">
                        <div class="label">Marca</div>
                        <div class="value"><?= h($caso['marca']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Modelo</div>
                        <div class="value"><?= h($caso['modelo']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">IMEI 1</div>
                        <div class="value"><?= h($caso['imei_original']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">IMEI 2</div>
                        <div class="value"><?= h($caso['imei2_original']) ?: '-' ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>3. Diagnóstico y reparación aplicada</h3>
                <div class="big-box"><?= h($diagnosticoProveedor ?: 'Sin diagnóstico registrado.') ?></div>

                <div class="big-box" style="margin-top:10px; min-height:70px;">
                    <?= h($observacionesLogisticaRep ?: $observacionesCierreCaso ?: $observacionesLogisticaCaso) ?: 'Equipo reparado y devuelto por proveedor, listo para entrega al cliente.' ?>
                </div>
            </div>

            <div class="section">
                <h3>4. Importes</h3>
                <div class="grid grid-3">
                    <div class="field">
                        <div class="label">Costo revisión</div>
                        <div class="value"><?= money($costoRevision) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Costo reparación</div>
                        <div class="value"><?= money($costoReparacion) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Costo total</div>
                        <div class="value"><?= money($costoTotal) ?></div>
                    </div>
                </div>
            </div>

            <div class="notes">
                El cliente recibe el mismo equipo previamente ingresado para diagnóstico y reparación, manifestando de conformidad la recepción del mismo en la fecha indicada.
            </div>

            <div class="signature-area">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma del cliente</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma del responsable de entrega</div>
                </div>
            </div>

        <?php elseif ($tipo === 'devolucion_sin_reparacion'): ?>

            <div class="section">
                <h3>1. Datos de devolución</h3>
                <div class="grid grid-4">
                    <div class="field">
                        <div class="label">Cliente</div>
                        <div class="value"><?= h($caso['cliente_nombre']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Fecha de devolución</div>
                        <div class="value"><?= h(fmt_datetime($fechaDevolucion ?: $fechaRespuestaCliente ?: date('Y-m-d H:i:s'))) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Sucursal</div>
                        <div class="value"><?= h($caso['sucursal_nombre']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Estado del caso</div>
                        <div class="value"><?= h($caso['estado']) ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>2. Equipo devuelto</h3>
                <div class="grid grid-3">
                    <div class="field">
                        <div class="label">Equipo</div>
                        <div class="value"><?= h(trim(($caso['marca'] ?? '') . ' ' . ($caso['modelo'] ?? ''))) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">IMEI 1</div>
                        <div class="value"><?= h($caso['imei_original']) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">IMEI 2</div>
                        <div class="value"><?= h($caso['imei2_original']) ?: '-' ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>3. Diagnóstico y cotización informada</h3>
                <div class="big-box"><?= h($diagnosticoProveedor ?: 'Sin diagnóstico registrado.') ?></div>

                <div class="grid grid-3" style="margin-top:10px;">
                    <div class="field">
                        <div class="label">Costo revisión</div>
                        <div class="value"><?= money($costoRevision) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Costo reparación</div>
                        <div class="value"><?= money($costoReparacion) ?></div>
                    </div>
                    <div class="field">
                        <div class="label">Costo total</div>
                        <div class="value"><?= money($costoTotal) ?></div>
                    </div>
                </div>
            </div>

            <div class="section">
                <h3>4. Motivo de devolución</h3>
                <div class="big-box">
                    <?= h($observacionesClienteRep ?: $observacionesLogisticaRep ?: $observacionesCierreCaso) ?: 'El cliente no aceptó la cotización de reparación, por lo que se realiza la devolución del equipo sin intervención técnica adicional.' ?>
                </div>
            </div>

            <div class="notes">
                El cliente recibe su equipo sin reparación, quedando enterado del diagnóstico y de la cotización previamente presentada por el proveedor o taller.
            </div>

            <div class="signature-area">
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma del cliente</div>
                </div>
                <div class="signature-box">
                    <div class="signature-line"></div>
                    <div class="signature-label">Firma del responsable de entrega</div>
                </div>
            </div>

        <?php endif; ?>

        <div class="footer-doc">
            <div><strong>Documento:</strong> <?= h($tituloDoc) ?></div>
            <div><strong>Folio:</strong> <?= h($caso['folio']) ?></div>
            <div><strong>Generado:</strong> <?= h($fechaGeneracion) ?></div>
        </div>
    </div>
</div>

</body>
</html>