<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

/* =========================================================
   PERMISOS
========================================================= */
$ROL = (string)($_SESSION['rol'] ?? '');
$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

$ROLES_PERMITIDOS = [
    'Admin', 'Administrador',
    'Gerente', 'GerenteZona',
    'Ejecutivo', 'Logistica',
    'Subdis_Admin', 'Subdis_Gerente', 'Subdis_Ejecutivo'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para acceder al detalle de garantías.');
}

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
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

function column_exists(mysqli $conn, string $table, string $column): bool {
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param("ss", $table, $column);
    $st->execute();
    $ok = ($st->get_result()->num_rows > 0);
    $st->close();
    return $ok;
}

function first_existing_column(mysqli $conn, string $table, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (column_exists($conn, $table, $c)) {
            return $c;
        }
    }
    return null;
}

function badge_estado(string $estado): string {
    $map = [
        'capturada'              => 'secondary',
        'recepcion_registrada'   => 'info',
        'en_revision_logistica'  => 'warning',
        'garantia_autorizada'    => 'success',
        'garantia_rechazada'     => 'danger',
        'enviada_diagnostico'    => 'primary',
        'cotizacion_disponible'  => 'info',
        'cotizacion_aceptada'    => 'success',
        'cotizacion_rechazada'   => 'danger',
        'en_reparacion'          => 'warning',
        'reparado'               => 'success',
        'reemplazo_capturado'    => 'primary',
        'entregado'              => 'success',
        'cerrado'                => 'dark',
        'cancelado'              => 'dark',
    ];

    $cls = $map[$estado] ?? 'secondary';
    return '<span class="badge rounded-pill text-bg-' . $cls . '">' . h($estado) . '</span>';
}

function badge_dictamen(string $dictamen): string {
    $map = [
        'procede'              => 'success',
        'no_procede'           => 'danger',
        'revision_logistica'   => 'warning',
        'revision_proveedor'   => 'warning',
        'imei_no_localizado'   => 'secondary',
    ];

    $cls = $map[$dictamen] ?? 'secondary';
    return '<span class="badge rounded-pill text-bg-' . $cls . '">' . h($dictamen) . '</span>';
}

function icon_check_contextual($value, string $tipo = 'si_no'): string {
    if ($value === null || $value === '') {
        return '<span class="badge text-bg-secondary">Sin revisar</span>';
    }

    $esSi = ((string)$value === '1');

    $labels = [
        'si_no' => [
            'yes' => 'Sí',
            'no'  => 'No',
            'yes_class' => 'success',
            'no_class'  => 'danger',
        ],
        'funciona' => [
            'yes' => 'Funciona',
            'no'  => 'No funciona',
            'yes_class' => 'success',
            'no_class'  => 'danger',
        ],
        'presenta' => [
            'yes' => 'Presenta',
            'no'  => 'No presenta',
            'yes_class' => 'danger',
            'no_class'  => 'success',
        ],
        'detecta' => [
            'yes' => 'Se detecta',
            'no'  => 'No se detecta',
            'yes_class' => 'danger',
            'no_class'  => 'success',
        ],
        'tiene' => [
            'yes' => 'Sí tiene',
            'no'  => 'No tiene',
            'yes_class' => 'warning',
            'no_class'  => 'success',
        ],
    ];

    $cfg = $labels[$tipo] ?? $labels['si_no'];
    $text = $esSi ? $cfg['yes'] : $cfg['no'];
    $class = $esSi ? $cfg['yes_class'] : $cfg['no_class'];

    return '<span class="badge text-bg-' . $class . '">' . h($text) . '</span>';
}

function fmt_datetime(?string $dt): string {
    if (!$dt || $dt === '0000-00-00' || $dt === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i', $ts) : '-';
}

function fmt_date(?string $dt): string {
    if (!$dt || $dt === '0000-00-00' || $dt === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y', $ts) : '-';
}

function parse_date_flexible(?string $dateStr): ?DateTime {
    $dateStr = trim((string)$dateStr);
    if ($dateStr === '') return null;

    $tz = new DateTimeZone('America/Mexico_City');

    $formats = [
        'Y-m-d',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'd/m/Y',
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        DateTime::ATOM,
    ];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $dateStr, $tz);
        if ($dt instanceof DateTime) {
            return $dt;
        }
    }

    $ts = strtotime($dateStr);
    if ($ts === false) return null;

    $dt = new DateTime('now', $tz);
    $dt->setTimestamp($ts);
    return $dt;
}

function diff_days_from_today(?string $dateStr): ?int {
    $base = parse_date_flexible($dateStr);
    if (!$base) return null;

    $tz = new DateTimeZone('America/Mexico_City');
    $today = new DateTime('today', $tz);
    $base->setTime(0, 0, 0);

    $seconds = $today->getTimestamp() - $base->getTimestamp();
    return (int)floor($seconds / 86400);
}

function cobertura_desde_fecha(?string $fechaCompra): array {
    $dias = diff_days_from_today($fechaCompra);

    if ($dias === null) {
        return [
            'dias'   => null,
            'clave'  => 'indefinida',
            'titulo' => 'Cobertura no calculable',
            'texto'  => 'No fue posible interpretar la fecha de compra para calcular la cobertura.',
            'badge'  => 'secondary'
        ];
    }

    if ($dias >= 0 && $dias <= 7) {
        return [
            'dias'   => $dias,
            'clave'  => 'distribuidor_0_7',
            'titulo' => 'Garantía con distribuidor (0 a 7 días)',
            'texto'  => 'El equipo está dentro de la ventana especial de 0 a 7 días. Si logística autoriza reemplazo, debe considerarse ajuste administrativo de venta y traspaso del equipo devuelto.',
            'badge'  => 'success'
        ];
    }

    if ($dias >= 8 && $dias <= 30) {
        return [
            'dias'   => $dias,
            'clave'  => 'distribuidor_8_30',
            'titulo' => 'Garantía con distribuidor (8 a 30 días)',
            'texto'  => 'El equipo está dentro de la garantía con distribuidor, pero fuera de la ventana especial de 0 a 7 días.',
            'badge'  => 'success'
        ];
    }

    if ($dias >= 31 && $dias <= 90) {
        return [
            'dias'   => $dias,
            'clave'  => 'proveedor_31_90',
            'titulo' => 'Revisión con proveedor',
            'texto'  => 'El equipo ya no entra en garantía directa con tienda, pero aún está dentro del rango de 31 a 90 días para revisión con proveedor.',
            'badge'  => 'warning'
        ];
    }

    return [
        'dias'   => $dias,
        'clave'  => 'vencida',
        'titulo' => 'Fuera de cobertura',
        'texto'  => 'El equipo supera los 90 días desde la venta.',
        'badge'  => 'danger'
    ];
}

function puede_ver_caso(array $caso, string $rol, int $idUsuario, int $idSucursal): bool {
    if (in_array($rol, ['Admin', 'Administrador', 'Logistica', 'GerenteZona', 'Subdis_Admin'], true)) {
        return true;
    }

    if (in_array($rol, ['Gerente', 'Subdis_Gerente'], true)) {
        return ((int)($caso['id_sucursal'] ?? 0) === $idSucursal);
    }

    if (in_array($rol, ['Ejecutivo', 'Subdis_Ejecutivo'], true)) {
        return ((int)($caso['id_usuario_captura'] ?? 0) === $idUsuario);
    }

    return false;
}

function es_rol_logistica(string $rol): bool {
    return in_array($rol, ['Admin', 'Administrador', 'Logistica'], true);
}

function es_rol_tienda(string $rol): bool {
    return in_array($rol, ['Admin', 'Administrador', 'Logistica', 'Gerente', 'Ejecutivo', 'Subdis_Admin', 'Subdis_Gerente', 'Subdis_Ejecutivo'], true);
}

function etiqueta_tipo_equipo_venta($tipo, $esCombo = null): string {
    $tipo = strtolower(trim((string)$tipo));

    if ($tipo === 'combo') {
        return 'Combo';
    }

    if ($tipo === 'principal') {
        return 'Principal';
    }

    if ($esCombo !== null && $esCombo !== '') {
        return ((int)$esCombo === 1) ? 'Combo' : 'Principal';
    }

    return '-';
}

function resolver_tipo_documento_inteligente(array $caso, ?array $reparacion, ?array $reemplazo): string {
    $estado = (string)($caso['estado'] ?? '');

    $hayReemplazo = !empty($reemplazo) && !empty($reemplazo['imei_reemplazo']);

    $clienteAceptaRep = $reparacion['cliente_acepta'] ?? null;
    $clienteAceptaCaso = $caso['cliente_acepta_cotizacion'] ?? null;
    $clienteAcepta = ($clienteAceptaRep !== null && $clienteAceptaRep !== '')
        ? (int)$clienteAceptaRep
        : (($clienteAceptaCaso !== null && $clienteAceptaCaso !== '') ? (int)$clienteAceptaCaso : null);

    $hayDiagnostico = !empty($reparacion['diagnostico_proveedor']);
    $hayCotizacion = (
        isset($reparacion['costo_revision']) ||
        isset($reparacion['costo_reparacion']) ||
        isset($reparacion['costo_total'])
    ) && (
        (float)($reparacion['costo_revision'] ?? 0) > 0 ||
        (float)($reparacion['costo_reparacion'] ?? 0) > 0 ||
        (float)($reparacion['costo_total'] ?? 0) > 0
    );

    $fechaEnvio = $reparacion['fecha_envio'] ?? $caso['fecha_envio_proveedor'] ?? null;
    $fechaEquipoReparado = $reparacion['fecha_equipo_reparado'] ?? null;
    $fechaDevolucion = $reparacion['fecha_devolucion'] ?? null;
    $fechaEntrega = $caso['fecha_entrega'] ?? null;

    if ($estado === 'garantia_rechazada') return 'no_procede';
    if ($estado === 'cancelado') return 'no_procede';

    if (in_array($estado, ['capturada', 'recepcion_registrada', 'en_revision_logistica', 'garantia_autorizada'], true)) {
        return 'recepcion_garantia';
    }

    if ($estado === 'enviada_diagnostico') return 'envio_diagnostico';
    if ($estado === 'cotizacion_disponible') return 'cotizacion_reparacion';
    if ($estado === 'cotizacion_aceptada') return 'respuesta_cliente_reparacion';
    if ($estado === 'cotizacion_rechazada') return 'devolucion_sin_reparacion';
    if ($estado === 'en_reparacion') return 'respuesta_cliente_reparacion';
    if ($estado === 'reparado') return 'entrega_equipo_reparado';
    if ($estado === 'reemplazo_capturado') return 'entrega_garantia';

    if ($estado === 'entregado') {
        if ($hayReemplazo) return 'entrega_garantia';
        if (!empty($fechaEquipoReparado) || ($clienteAcepta === 1 && $hayDiagnostico)) return 'entrega_equipo_reparado';
        if ($clienteAcepta === 0 || !empty($fechaDevolucion)) return 'devolucion_sin_reparacion';
        return 'entrega_garantia';
    }

    if ($estado === 'cerrado') {
        if ($hayReemplazo) return 'entrega_garantia';
        if ($clienteAcepta === 0 || !empty($fechaDevolucion)) return 'devolucion_sin_reparacion';
        if (!empty($fechaEquipoReparado) || !empty($fechaEntrega)) return 'entrega_equipo_reparado';
        if ($hayCotizacion || $hayDiagnostico) return 'cotizacion_reparacion';
        return 'recepcion_garantia';
    }

    if (!empty($fechaDevolucion) || $clienteAcepta === 0) return 'devolucion_sin_reparacion';
    if ($hayReemplazo) return 'entrega_garantia';
    if (!empty($fechaEquipoReparado)) return 'entrega_equipo_reparado';
    if ($clienteAcepta === 1) return 'respuesta_cliente_reparacion';
    if ($hayCotizacion || $hayDiagnostico) return 'cotizacion_reparacion';
    if (!empty($fechaEnvio)) return 'envio_diagnostico';

    return 'recepcion_garantia';
}

function etiqueta_tipo_documento(string $tipo): string {
    return match ($tipo) {
        'recepcion_garantia'           => 'Recepción de garantía',
        'entrega_garantia'             => 'Entrega de garantía',
        'no_procede'                   => 'No procede',
        'cotizacion_reparacion'        => 'Cotización de reparación',
        'envio_diagnostico'            => 'Envío a diagnóstico',
        'respuesta_cliente_reparacion' => 'Respuesta del cliente',
        'entrega_equipo_reparado'      => 'Entrega de equipo reparado',
        'devolucion_sin_reparacion'    => 'Devolución sin reparación',
        default                        => $tipo,
    };
}

function texto_boton_documento(string $tipo): string {
    return match ($tipo) {
        'recepcion_garantia'           => 'Generar recepción',
        'entrega_garantia'             => 'Generar entrega garantía',
        'no_procede'                   => 'Generar no procede',
        'cotizacion_reparacion'        => 'Generar cotización',
        'envio_diagnostico'            => 'Generar envío a diagnóstico',
        'respuesta_cliente_reparacion' => 'Generar respuesta cliente',
        'entrega_equipo_reparado'      => 'Generar entrega reparado',
        'devolucion_sin_reparacion'    => 'Generar devolución',
        default                        => 'Generar formato',
    };
}

function clase_boton_documento(string $tipo): string {
    return match ($tipo) {
        'recepcion_garantia'           => 'btn-outline-secondary',
        'entrega_garantia'             => 'btn-outline-success',
        'no_procede'                   => 'btn-outline-danger',
        'cotizacion_reparacion'        => 'btn-outline-warning',
        'envio_diagnostico'            => 'btn-outline-primary',
        'respuesta_cliente_reparacion' => 'btn-outline-warning',
        'entrega_equipo_reparado'      => 'btn-outline-success',
        'devolucion_sin_reparacion'    => 'btn-outline-danger',
        default                        => 'btn-outline-secondary',
    };
}

function money_fmt_local($n): string {
    if ($n === null || $n === '') return 'No disponible';
    return '$' . number_format((float)$n, 2);
}

/* =========================================================
   ID / PARAMS EXTRA
========================================================= */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    exit('ID de garantía inválido.');
}

$idTraspasoAuto = (int)($_GET['id_traspaso_auto'] ?? 0);
$autoAcuse = isset($_GET['auto_acuse']) ? ((int)$_GET['auto_acuse'] === 1) : false;

/* =========================================================
   CASO
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
    exit("Error en consulta del caso: " . h($conn->error));
}
$st->bind_param("i", $id);
$st->execute();
$caso = $st->get_result()->fetch_assoc();
$st->close();

if (!$caso) {
    exit('No se encontró la garantía solicitada.');
}

if (!puede_ver_caso($caso, $ROL, $ID_USUARIO, $ID_SUCURSAL)) {
    http_response_code(403);
    exit('No tienes permiso para ver este expediente.');
}

/* =========================================================
   EVENTOS
========================================================= */
$eventos = [];
if (table_exists($conn, 'garantias_eventos')) {
    $sqlEventos = "SELECT *
                   FROM garantias_eventos
                   WHERE id_garantia = ?
                   ORDER BY fecha_evento ASC, id ASC";
    $st = $conn->prepare($sqlEventos);
    if ($st) {
        $st->bind_param("i", $id);
        $st->execute();
        $resE = $st->get_result();
        while ($row = $resE->fetch_assoc()) {
            $eventos[] = $row;
        }
        $st->close();
    }
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
   DOCUMENTOS GENERADOS
========================================================= */
$documentos = [];
if (table_exists($conn, 'garantias_documentos')) {
    $sqlDocs = "SELECT
                    gd.*,
                    u.nombre AS generado_por_nombre
                FROM garantias_documentos gd
                LEFT JOIN usuarios u ON u.id = gd.generado_por
                WHERE gd.id_garantia = ?
                  AND gd.activo = 1
                ORDER BY gd.fecha_generado DESC, gd.id DESC";
    $st = $conn->prepare($sqlDocs);
    if ($st) {
        $st->bind_param("i", $id);
        $st->execute();
        $resD = $st->get_result();
        while ($row = $resD->fetch_assoc()) {
            $documentos[] = $row;
        }
        $st->close();
    }
}

/* =========================================================
   EXCEPCIÓN DE REEMPLAZO
========================================================= */
$excepcionActual = null;
$puedeResolverExcepcion = in_array($ROL, ['Admin', 'Administrador', 'Logistica'], true);
$puedeSolicitarExcepcion = in_array($ROL, ['Ejecutivo', 'Gerente', 'Subdis_Ejecutivo', 'Subdis_Gerente', 'Admin', 'Administrador'], true);
$precioOriginalExcepcion = null;

$errVista = trim((string)($_GET['err'] ?? ''));
$okExc = (int)($_GET['okexc'] ?? 0);
$okResExc = (int)($_GET['okrexc'] ?? 0);
$okApplyExc = (int)($_GET['okapplyexc'] ?? 0);

if (table_exists($conn, 'detalle_venta')) {
    $sqlPrecioOriginalExc = "SELECT dv.precio_unitario AS precio_original_venta
                             FROM garantias_casos gc
                             LEFT JOIN detalle_venta dv ON dv.imei1 = gc.imei_original
                             WHERE gc.id = ?
                             LIMIT 1";
    $st = $conn->prepare($sqlPrecioOriginalExc);
    if ($st) {
        $st->bind_param("i", $id);
        $st->execute();
        $rowPrecioExc = $st->get_result()->fetch_assoc();
        $st->close();
        if ($rowPrecioExc && isset($rowPrecioExc['precio_original_venta'])) {
            $precioOriginalExcepcion = (float)$rowPrecioExc['precio_original_venta'];
        }
    }
}

if (table_exists($conn, 'garantias_excepciones_reemplazo')) {
    $capacidadColProductos = first_existing_column($conn, 'productos', ['capacidad', 'almacenamiento']);
    $selectCapacidad = $capacidadColProductos ? "p.`{$capacidadColProductos}` AS capacidad" : "NULL AS capacidad";

    $sqlExc = "SELECT
                    ge.*,
                    us.nombre AS solicita_nombre,
                    ur.nombre AS resuelve_nombre,
                    p.marca,
                    p.modelo,
                    p.color,
                    {$selectCapacidad}
               FROM garantias_excepciones_reemplazo ge
               LEFT JOIN usuarios us ON us.id = ge.id_usuario_solicita
               LEFT JOIN usuarios ur ON ur.id = ge.id_usuario_resuelve
               LEFT JOIN productos p ON p.id = ge.id_producto_propuesto
               WHERE ge.id_garantia = ?
               ORDER BY ge.id DESC
               LIMIT 1";
    $st = $conn->prepare($sqlExc);
    if ($st) {
        $st->bind_param("i", $id);
        $st->execute();
        $excepcionActual = $st->get_result()->fetch_assoc();
        $st->close();
    }
}

$ok = isset($_GET['ok']) ? (int)$_GET['ok'] : 0;
$oklog = isset($_GET['oklog']) ? (int)$_GET['oklog'] : 0;
$puedeLogistica = es_rol_logistica($ROL);
$puedeTienda = es_rol_tienda($ROL);
$estado = (string)$caso['estado'];

$linkCapturarReemplazo = "capturar_reemplazo.php?id=" . (int)$caso['id'];
$linkEntregarGarantia = "entregar_garantia.php?id=" . (int)$caso['id'];
$linkRespuestaCliente = "respuesta_cliente_reparacion.php?id=" . (int)$caso['id'];

$tipoDocumento = resolver_tipo_documento_inteligente($caso, $reparacion, $reemplazo);
$linkDocumento = "generar_documento_garantia.php?id=" . (int)$caso['id'] . "&tipo=" . urlencode($tipoDocumento);
$textoBotonDocumento = texto_boton_documento($tipoDocumento);
$claseBotonDocumento = clase_boton_documento($tipoDocumento);

$mostrarBtnCapturarReemplazo = $puedeTienda && in_array($estado, ['garantia_autorizada', 'reemplazo_capturado'], true);
$mostrarBtnEntregar = $puedeTienda && in_array($estado, ['reemplazo_capturado', 'reparado', 'garantia_autorizada'], true);
$mostrarBtnRespuestaCliente = $puedeTienda && in_array($estado, ['cotizacion_disponible', 'cotizacion_aceptada', 'cotizacion_rechazada'], true);

/* =========================================================
   MODAL RECORDATORIO DE CIERRE
========================================================= */
$mostrarModalRecordatorioCierre = (
    $oklog === 1 &&
    $estado === 'reemplazo_capturado'
);

/* =========================================================
   COBERTURA / ANTIGÜEDAD
========================================================= */
$cobertura = cobertura_desde_fecha($caso['fecha_compra'] ?? null);
$diasAntiguedad = $cobertura['dias'];
$esVentana7Dias = (($cobertura['clave'] ?? '') === 'distribuidor_0_7');

$tipoEquipoVentaTexto = etiqueta_tipo_equipo_venta(
    $caso['tipo_equipo_venta'] ?? null,
    $caso['es_combo'] ?? null
);

$puedeVerProveedor = in_array($ROL, ['Admin', 'Administrador', 'Logistica'], true);

/* =========================================================
   AJUSTES ADMINISTRATIVOS POR GARANTÍA
========================================================= */
$ajusteAdmin = [
    'venta_relacionada'      => (int)($caso['id_venta'] ?? 0),
    'tag_original'           => trim((string)($caso['tag_venta'] ?? '')),
    'tag_actual'             => trim((string)($caso['tag_venta'] ?? '')),
    'venta_respaldada'       => false,
    'id_respaldo'            => null,
    'tag_respaldo_anterior'  => null,
    'tag_respaldo_nuevo'     => null,
    'traspaso_generado'      => false,
    'id_traspaso'            => null,
    'folio_traspaso'         => null,
    'destino_traspaso'       => null,
    'acuse_traspaso_url'     => null,
    'observacion'            => null,
];

/* -------- Respaldo venta -------- */
if (table_exists($conn, 'ventas_respaldo_garantia')) {
    $sqlResp = "SELECT *
                FROM ventas_respaldo_garantia
                WHERE id_garantia = ?
                ORDER BY id DESC
                LIMIT 1";
    $st = $conn->prepare($sqlResp);
    if ($st) {
        $st->bind_param("i", $id);
        $st->execute();
        $rowResp = $st->get_result()->fetch_assoc();
        $st->close();

        if ($rowResp) {
            $ajusteAdmin['venta_respaldada']      = true;
            $ajusteAdmin['id_respaldo']           = (int)($rowResp['id'] ?? 0);
            $ajusteAdmin['venta_relacionada']     = (int)($rowResp['id_venta'] ?? $ajusteAdmin['venta_relacionada']);
            $ajusteAdmin['tag_respaldo_anterior'] = trim((string)($rowResp['tag_anterior'] ?? ''));
            $ajusteAdmin['tag_respaldo_nuevo']    = trim((string)($rowResp['tag_nuevo'] ?? ''));
            $ajusteAdmin['tag_original']          = $ajusteAdmin['tag_respaldo_anterior'] !== ''
                ? $ajusteAdmin['tag_respaldo_anterior']
                : $ajusteAdmin['tag_original'];

            if ($ajusteAdmin['tag_respaldo_nuevo'] !== '') {
                $ajusteAdmin['tag_actual'] = $ajusteAdmin['tag_respaldo_nuevo'];
            }
        }
    }
}

if (table_exists($conn, 'ventas') && !empty($ajusteAdmin['venta_relacionada'])) {
    $sqlVentaTag = "SELECT tag
                    FROM ventas
                    WHERE id = ?
                    LIMIT 1";
    $st = $conn->prepare($sqlVentaTag);
    if ($st) {
        $idVentaTmp = (int)$ajusteAdmin['venta_relacionada'];
        $st->bind_param("i", $idVentaTmp);
        $st->execute();
        $rowVentaTag = $st->get_result()->fetch_assoc();
        $st->close();

        if ($rowVentaTag && isset($rowVentaTag['tag']) && trim((string)$rowVentaTag['tag']) !== '') {
            $ajusteAdmin['tag_actual'] = trim((string)$rowVentaTag['tag']);
        }
    }
}

/*
|-----------------------------------------------------------
| Traspaso automático
|-----------------------------------------------------------
| Prioridad:
| 1) id_traspaso_auto recibido por URL
| 2) búsqueda por id_garantia
|
| LUGA: destino sugerido = Eulalia (ID 40)
| NANO: cambiar después por Angelópolis
*/
$ajusteAdmin['destino_traspaso'] = $esVentana7Dias ? 'Eulalia (LUGA, ID 40)' : 'Almacén central / destino configurado';

$colFolioTraspaso = first_existing_column($conn, 'traspasos', ['folio', 'codigo', 'codigo_traspaso']);
$colObsTraspaso   = first_existing_column($conn, 'traspasos', ['observaciones', 'observacion']);
$colDestinoTr     = first_existing_column($conn, 'traspasos', ['id_sucursal_destino']);
$colIdGarantiaTr  = first_existing_column($conn, 'traspasos', ['id_garantia']);

$rowTr = null;

if (table_exists($conn, 'traspasos')) {
    $selects = ["t.id"];
    if ($colFolioTraspaso) $selects[] = "t.`{$colFolioTraspaso}` AS folio_traspaso";
    if ($colObsTraspaso)   $selects[] = "t.`{$colObsTraspaso}` AS observacion_traspaso";
    if ($colDestinoTr)     $selects[] = "sd.nombre AS destino_nombre";

    /* 1) prioridad al traspaso recién creado */
    if ($idTraspasoAuto > 0) {
        $sqlTrAuto = "SELECT " . implode(", ", $selects) . "
                      FROM traspasos t
                      " . ($colDestinoTr ? "LEFT JOIN sucursales sd ON sd.id = t.`{$colDestinoTr}`" : "") . "
                      WHERE t.id = ?
                      LIMIT 1";
        $st = $conn->prepare($sqlTrAuto);
        if ($st) {
            $st->bind_param("i", $idTraspasoAuto);
            $st->execute();
            $rowTr = $st->get_result()->fetch_assoc();
            $st->close();
        }
    }

    /* 2) fallback a búsqueda por id_garantia */
    if (!$rowTr && $colIdGarantiaTr) {
        $sqlTr = "SELECT " . implode(", ", $selects) . "
                  FROM traspasos t
                  " . ($colDestinoTr ? "LEFT JOIN sucursales sd ON sd.id = t.`{$colDestinoTr}`" : "") . "
                  WHERE t.`{$colIdGarantiaTr}` = ?
                  ORDER BY t.id DESC
                  LIMIT 1";

        $st = $conn->prepare($sqlTr);
        if ($st) {
            $st->bind_param("i", $id);
            $st->execute();
            $rowTr = $st->get_result()->fetch_assoc();
            $st->close();
        }
    }

    if ($rowTr) {
        $ajusteAdmin['traspaso_generado'] = true;
        $ajusteAdmin['id_traspaso']       = (int)($rowTr['id'] ?? 0);

        $folioTmp = trim((string)($rowTr['folio_traspaso'] ?? ''));
        if ($folioTmp === '' && !empty($rowTr['id'])) {
            $folioTmp = '#' . (int)$rowTr['id'];
        }
        $ajusteAdmin['folio_traspaso']    = $folioTmp ?: null;

        $ajusteAdmin['destino_traspaso']  = trim((string)($rowTr['destino_nombre'] ?? '')) ?: $ajusteAdmin['destino_traspaso'];
        $ajusteAdmin['observacion']       = trim((string)($rowTr['observacion_traspaso'] ?? '')) ?: null;

        if (!empty($ajusteAdmin['id_traspaso'])) {
            $ajusteAdmin['acuse_traspaso_url'] = 'acuse_traspaso.php?id=' . (int)$ajusteAdmin['id_traspaso'] . '&print=1';
        }
    }
}

$autoAbrirAcuse = ($autoAcuse && !empty($ajusteAdmin['id_traspaso']));
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Detalle de garantía | <?= h($caso['folio']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body{
            background: linear-gradient(180deg, #f8fbff 0%, #f3f6fb 100%);
        }
        .page-wrap{
            max-width: 1480px;
            margin: 24px auto 40px;
            padding: 0 14px;
        }
        .hero{
            border-radius:22px;
            border:1px solid #e8edf3;
            background: linear-gradient(135deg, rgba(13,110,253,.08), rgba(111,66,193,.08));
            box-shadow:0 10px 28px rgba(17,24,39,.06);
        }
        .soft-card{
            border:1px solid #e8edf3;
            border-radius:20px;
            box-shadow:0 8px 24px rgba(17,24,39,.05);
            background:#fff;
        }
        .section-title{
            font-size:1rem;
            font-weight:700;
            margin-bottom:.9rem;
            display:flex;
            align-items:center;
            gap:.55rem;
        }
        .section-title i{
            color:#0d6efd;
        }
        .kv-label{
            font-size:.82rem;
            color:#6b7280;
            font-weight:600;
            margin-bottom:.2rem;
        }
        .kv-value{
            font-size:.95rem;
            font-weight:500;
            word-break:break-word;
        }
        .timeline{
            position:relative;
            margin-left: 8px;
            padding-left: 24px;
        }
        .timeline::before{
            content:'';
            position:absolute;
            left:6px;
            top:0;
            bottom:0;
            width:2px;
            background:#dbe5f0;
        }
        .timeline-item{
            position:relative;
            margin-bottom:18px;
        }
        .timeline-dot{
            position:absolute;
            left:-23px;
            top:4px;
            width:14px;
            height:14px;
            border-radius:50%;
            background:#0d6efd;
            border:3px solid #fff;
            box-shadow:0 0 0 2px #dbe5f0;
        }
        .timeline-card{
            border:1px solid #edf1f6;
            border-radius:16px;
            background:#fafcff;
            padding:14px 16px;
        }
        .check-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
            gap:12px;
        }
        .check-card{
            border:1px solid #edf1f6;
            border-radius:16px;
            background:#fff;
            padding:12px 14px;
            min-height: 92px;
        }
        .check-help{
            font-size: .8rem;
            color: #6c757d;
            line-height: 1.35;
            margin-top: .25rem;
            margin-bottom: .45rem;
        }
        .sticky-box{
            position:sticky;
            top:92px;
        }
        .chip{
            display:inline-flex;
            align-items:center;
            gap:.35rem;
            border-radius:999px;
            padding:.45rem .8rem;
            font-size:.82rem;
            font-weight:600;
            background:#eef4ff;
            color:#2457c5;
        }
        .muted-note{
            color:#6b7280;
            font-size:.92rem;
        }
        .empty-box{
            border:1px dashed #ced4da;
            border-radius:18px;
            background:#fff;
            padding:24px;
            text-align:center;
            color:#6c757d;
        }
        .action-form{
            border:1px solid #edf1f6;
            border-radius:16px;
            padding:14px;
            background:#fbfcfe;
            margin-bottom:12px;
        }
        .action-title{
            font-weight:700;
            margin-bottom:.75rem;
            display:flex;
            align-items:center;
            gap:.45rem;
        }
        .coverage-box{
            border-radius:18px;
            padding:16px 18px;
            border:1px solid #e8edf3;
        }
        .coverage-success{
            background:rgba(25,135,84,.08);
            border-color:rgba(25,135,84,.28);
        }
        .coverage-warning{
            background:rgba(255,193,7,.12);
            border-color:rgba(255,193,7,.35);
        }
        .coverage-danger{
            background:rgba(220,53,69,.08);
            border-color:rgba(220,53,69,.28);
        }
        .coverage-secondary{
            background:#f8f9fa;
            border-color:#dee2e6;
        }
        .modal-reminder-icon{
            width:64px;
            height:64px;
            border-radius:50%;
            display:flex;
            align-items:center;
            justify-content:center;
            background:rgba(255,193,7,.18);
            color:#946200;
            font-size:1.8rem;
            margin:0 auto 12px auto;
        }
    </style>
</head>
<body>

<div class="page-wrap">

    <?php if ($ok === 1): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <i class="bi bi-check-circle me-1"></i> La solicitud se guardó correctamente.
        </div>
    <?php endif; ?>

    <?php if ($oklog === 1): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <i class="bi bi-check2-circle me-1"></i> La actualización del caso se realizó correctamente.
            <?php if ($autoAbrirAcuse): ?>
                <div class="small mt-1">También se abrirá automáticamente el acuse del traspaso generado.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($okExc === 1): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <i class="bi bi-check-circle me-1"></i> Solicitud de excepción registrada correctamente.
        </div>
    <?php endif; ?>

    <?php if ($okResExc === 1): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <i class="bi bi-check-circle me-1"></i> La solicitud de excepción fue resuelta correctamente.
        </div>
    <?php endif; ?>

    <?php if ($okApplyExc === 1): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <i class="bi bi-check-circle me-1"></i> El reemplazo autorizado fue aplicado correctamente.
        </div>
    <?php endif; ?>

    <?php if ($errVista !== ''): ?>
        <div class="alert alert-danger border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle me-1"></i> <?= h($errVista) ?>
        </div>
    <?php endif; ?>

    <div class="hero p-4 p-md-5 mb-4">
        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
            <div>
                <h3 class="mb-1 fw-bold">
                    <i class="bi bi-journal-medical me-2"></i>Expediente de garantía
                </h3>
                <div class="text-muted">
                    Folio <strong><?= h($caso['folio']) ?></strong> • Cliente <strong><?= h($caso['cliente_nombre']) ?></strong>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <span class="chip"><i class="bi bi-person-badge"></i> Rol: <?= h($ROL) ?></span>
                <?= badge_dictamen((string)$caso['dictamen_preliminar']) ?>
                <?= badge_estado((string)$caso['estado']) ?>
                <a href="garantias_mis_casos.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Volver al listado
                </a>
                <?php if ($puedeLogistica): ?>
                    <a href="garantias_logistica.php" class="btn btn-outline-primary">
                        <i class="bi bi-truck me-1"></i>Panel logística
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <div class="col-lg-8">

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-info-circle"></i>
                    <span>Resumen del caso</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="kv-label">Fecha captura</div>
                        <div class="kv-value"><?= h(fmt_datetime($caso['fecha_captura'])) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Fecha recepción</div>
                        <div class="kv-value"><?= h(fmt_date($caso['fecha_recepcion'])) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Sucursal</div>
                        <div class="kv-value"><?= h($caso['sucursal_nombre']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Capturó</div>
                        <div class="kv-value"><?= h($caso['capturista_nombre']) ?></div>
                    </div>

                    <div class="col-md-3">
                        <div class="kv-label">Tipo origen</div>
                        <div class="kv-value"><?= h($caso['tipo_origen']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Venta ID</div>
                        <div class="kv-value"><?= h($caso['id_venta']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Detalle venta ID</div>
                        <div class="kv-value"><?= h($caso['id_detalle_venta']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Nivel reincidencia</div>
                        <div class="kv-value"><?= h($caso['nivel_reincidencia']) ?></div>
                    </div>
                </div>
            </div>

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-person-vcard"></i>
                    <span>Datos del cliente</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="kv-label">Nombre</div>
                        <div class="kv-value"><?= h($caso['cliente_nombre']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="kv-label">Teléfono</div>
                        <div class="kv-value"><?= h($caso['cliente_telefono']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="kv-label">Correo</div>
                        <div class="kv-value"><?= h($caso['cliente_correo']) ?></div>
                    </div>
                </div>
            </div>

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-phone"></i>
                    <span>Datos del equipo</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="kv-label">Marca</div>
                        <div class="kv-value"><?= h($caso['marca']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Modelo</div>
                        <div class="kv-value"><?= h($caso['modelo']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Color</div>
                        <div class="kv-value"><?= h($caso['color']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Capacidad</div>
                        <div class="kv-value"><?= h($caso['capacidad']) ?></div>
                    </div>

                    <div class="col-md-6">
                        <div class="kv-label">IMEI 1</div>
                        <div class="kv-value"><?= h($caso['imei_original']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="kv-label">IMEI 2</div>
                        <div class="kv-value"><?= h($caso['imei2_original']) ?: '-' ?></div>
                    </div>

                    <div class="col-md-3">
                        <div class="kv-label">Fecha compra</div>
                        <div class="kv-value"><?= h(fmt_date($caso['fecha_compra'])) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">TAG venta</div>
                        <div class="kv-value"><?= h($caso['tag_venta']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Modalidad</div>
                        <div class="kv-value"><?= h($caso['modalidad_venta']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Financiera</div>
                        <div class="kv-value"><?= h($caso['financiera']) ?></div>
                    </div>

                    <div class="col-md-3">
                        <div class="kv-label">Tipo en venta</div>
                        <div class="kv-value"><?= h($tipoEquipoVentaTexto) ?></div>
                    </div>

                    <div class="col-md-3">
                        <div class="kv-label">Es combo</div>
                        <div class="kv-value">
                            <?= ((int)($caso['es_combo'] ?? 0) === 1) ? 'Sí' : 'No' ?>
                        </div>
                    </div>

                    <?php if ($puedeVerProveedor): ?>
                        <div class="col-md-6">
                            <div class="kv-label">Proveedor</div>
                            <div class="kv-value"><?= h($caso['proveedor'] ?? '') ?: '-' ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-calendar2-check"></i>
                    <span>Antigüedad y cobertura</span>
                </div>

                <?php
                    $coverageClass = match ($cobertura['badge']) {
                        'success' => 'coverage-success',
                        'warning' => 'coverage-warning',
                        'danger'  => 'coverage-danger',
                        default   => 'coverage-secondary',
                    };
                ?>

                <?php if ($esVentana7Dias): ?>
                    <div class="alert alert-info border-0 shadow-sm mb-3">
                        <div class="fw-semibold mb-1">
                            <i class="bi bi-stars me-1"></i> Ventana especial 0 a 7 días
                        </div>
                        <div class="small">
                            Este caso está dentro de la ventana especial. Si logística autoriza reemplazo, además de entregar el nuevo equipo,
                            debe contemplarse el ajuste administrativo de la venta, respaldo del TAG original y traspaso del equipo devuelto.
                        </div>
                    </div>
                <?php endif; ?>

                <div class="coverage-box <?= h($coverageClass) ?>">
                    <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                        <div>
                            <div class="fw-bold mb-1"><?= h($cobertura['titulo']) ?></div>
                            <div class="muted-note"><?= h($cobertura['texto']) ?></div>
                        </div>
                        <div class="text-md-end">
                            <div class="kv-label">Días desde compra</div>
                            <div class="kv-value">
                                <?php if ($diasAntiguedad === null): ?>
                                    No calculable
                                <?php else: ?>
                                    <?= (int)$diasAntiguedad ?> día(s)
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <div class="kv-label">Cobertura calculada</div>
                        <div class="kv-value">
                            <span class="badge rounded-pill text-bg-<?= h($cobertura['badge']) ?>">
                                <?= h($cobertura['titulo']) ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="kv-label">Dictamen preliminar</div>
                        <div class="kv-value"><?= badge_dictamen((string)$caso['dictamen_preliminar']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="kv-label">Estado actual</div>
                        <div class="kv-value"><?= badge_estado((string)$caso['estado']) ?></div>
                    </div>
                </div>
            </div>

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-pencil-square"></i>
                    <span>Ajustes administrativos por garantía</span>
                </div>

                <?php if ($esVentana7Dias): ?>
                    <div class="alert alert-warning border-0 shadow-sm mb-3">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Este bloque aplica especialmente para garantías dentro de <strong>0 a 7 días</strong>.
                    </div>
                <?php else: ?>
                    <div class="muted-note mb-3">
                        Este caso no está en ventana 0 a 7 días. La información se muestra solo como referencia administrativa.
                    </div>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="kv-label">Venta relacionada</div>
                        <div class="kv-value">
                            <?= !empty($ajusteAdmin['venta_relacionada']) ? '#' . (int)$ajusteAdmin['venta_relacionada'] : '-' ?>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="kv-label">TAG original</div>
                        <div class="kv-value"><?= h($ajusteAdmin['tag_original'] ?: '-') ?></div>
                    </div>

                    <div class="col-md-3">
                        <div class="kv-label">TAG actual</div>
                        <div class="kv-value"><?= h($ajusteAdmin['tag_actual'] ?: '-') ?></div>
                    </div>

                    <div class="col-md-3">
                        <div class="kv-label">Venta respaldada</div>
                        <div class="kv-value">
                            <?= $ajusteAdmin['venta_respaldada']
                                ? '<span class="badge text-bg-success">Sí</span>'
                                : '<span class="badge text-bg-secondary">No</span>' ?>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="kv-label">ID respaldo</div>
                        <div class="kv-value">
                            <?= !empty($ajusteAdmin['id_respaldo']) ? '#' . (int)$ajusteAdmin['id_respaldo'] : '-' ?>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="kv-label">TAG respaldado anterior</div>
                        <div class="kv-value"><?= h($ajusteAdmin['tag_respaldo_anterior'] ?: '-') ?></div>
                    </div>

                    <div class="col-md-3">
                        <div class="kv-label">TAG respaldado nuevo</div>
                        <div class="kv-value"><?= h($ajusteAdmin['tag_respaldo_nuevo'] ?: '-') ?></div>
                    </div>

                    <div class="col-md-3">
                        <div class="kv-label">Traspaso automático</div>
                        <div class="kv-value">
                            <?= $ajusteAdmin['traspaso_generado']
                                ? '<span class="badge text-bg-success">Generado</span>'
                                : '<span class="badge text-bg-secondary">Pendiente</span>' ?>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="kv-label">Folio de traspaso</div>
                        <div class="kv-value"><?= h($ajusteAdmin['folio_traspaso'] ?: '-') ?></div>
                    </div>

                    <div class="col-md-4">
                        <div class="kv-label">Destino de traspaso</div>
                        <div class="kv-value"><?= h($ajusteAdmin['destino_traspaso'] ?: '-') ?></div>
                    </div>

                    <div class="col-md-4">
                        <div class="kv-label">Acuse de traspaso</div>
                        <div class="kv-value">
                            <?php if (!empty($ajusteAdmin['acuse_traspaso_url'])): ?>
                                <a href="<?= h($ajusteAdmin['acuse_traspaso_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-file-earmark-text me-1"></i>Ver acuse
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="kv-label">Observación</div>
                        <div class="kv-value">
                            <?= nl2br(h($ajusteAdmin['observacion'] ?: 'Sin observaciones registradas todavía.')) ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-exclamation-octagon"></i>
                    <span>Falla reportada y observaciones</span>
                </div>

                <div class="mb-3">
                    <div class="kv-label">Descripción de falla</div>
                    <div class="kv-value"><?= nl2br(h($caso['descripcion_falla'])) ?></div>
                </div>

                <div class="mb-3">
                    <div class="kv-label">Observaciones de tienda</div>
                    <div class="kv-value"><?= nl2br(h($caso['observaciones_tienda'])) ?></div>
                </div>

                <div>
                    <div class="kv-label">Observaciones de logística</div>
                    <div class="kv-value"><?= nl2br(h($caso['observaciones_logistica'])) ?></div>
                </div>
            </div>

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-ui-checks-grid"></i>
                    <span>Checklist técnico inicial</span>
                </div>

                <div class="check-grid">
                    <div class="check-card">
                        <div class="kv-label">Encendido</div>
                        <div class="check-help">Validar si el equipo enciende correctamente y logra iniciar.</div>
                        <div class="kv-value"><?= icon_check_contextual($caso['check_encendido'], 'si_no') ?></div>
                    </div>

                    <div class="check-card">
                        <div class="kv-label">Daño físico</div>
                        <div class="check-help">Validar si presenta golpes, quebraduras, piezas rotas o deformaciones visibles.</div>
                        <div class="kv-value"><?= icon_check_contextual($caso['check_dano_fisico'], 'presenta') ?></div>
                    </div>

                    <div class="check-card">
                        <div class="kv-label">Humedad</div>
                        <div class="check-help">Validar si existen indicios de humedad o contacto con líquidos.</div>
                        <div class="kv-value"><?= icon_check_contextual($caso['check_humedad'], 'detecta') ?></div>
                    </div>

                    <div class="check-card">
                        <div class="kv-label">Pantalla</div>
                        <div class="check-help">Validar funcionamiento del display y respuesta táctil.</div>
                        <div class="kv-value"><?= icon_check_contextual($caso['check_pantalla'], 'funciona') ?></div>
                    </div>

                    <div class="check-card">
                        <div class="kv-label">Cámara</div>
                        <div class="check-help">Validar si la cámara abre y captura imagen correctamente.</div>
                        <div class="kv-value"><?= icon_check_contextual($caso['check_camara'], 'funciona') ?></div>
                    </div>

                    <div class="check-card">
                        <div class="kv-label">Bocina / Micrófono</div>
                        <div class="check-help">Validar audio de salida y correcta captación de voz.</div>
                        <div class="kv-value"><?= icon_check_contextual($caso['check_bocina_microfono'], 'funciona') ?></div>
                    </div>

                    <div class="check-card">
                        <div class="kv-label">Puerto de carga</div>
                        <div class="check-help">Validar si el equipo carga correctamente al conectar el cable.</div>
                        <div class="kv-value"><?= icon_check_contextual($caso['check_puerto_carga'], 'funciona') ?></div>
                    </div>

                    <div class="check-card">
                        <div class="kv-label">App financiera instalada</div>
                        <div class="check-help">Validar si el equipo tiene instalada alguna app financiera o de bloqueo.</div>
                        <div class="kv-value"><?= icon_check_contextual($caso['check_app_financiera'], 'tiene') ?></div>
                    </div>

                    <div class="check-card">
                        <div class="kv-label">Bloqueo patrón / Google</div>
                        <div class="check-help">Validar si el equipo tiene patrón, PIN o cuenta Google activa.</div>
                        <div class="kv-value"><?= icon_check_contextual($caso['check_bloqueo_patron_google'], 'tiene') ?></div>
                    </div>
                </div>
            </div>

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-tools"></i>
                    <span>Diagnóstico / reparación con proveedor</span>
                </div>

                <?php if ($reparacion): ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="kv-label">Proveedor</div>
                            <div class="kv-value"><?= h($reparacion['proveedor_nombre']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Estado reparación</div>
                            <div class="kv-value"><?= h($reparacion['estado']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Tiempo estimado</div>
                            <div class="kv-value"><?= h($reparacion['tiempo_estimado_dias']) ?> día(s)</div>
                        </div>

                        <div class="col-md-4">
                            <div class="kv-label">Costo revisión</div>
                            <div class="kv-value">$<?= number_format((float)$reparacion['costo_revision'], 2) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Costo reparación</div>
                            <div class="kv-value">$<?= number_format((float)$reparacion['costo_reparacion'], 2) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Costo total</div>
                            <div class="kv-value fw-bold">$<?= number_format((float)$reparacion['costo_total'], 2) ?></div>
                        </div>

                        <div class="col-md-4">
                            <div class="kv-label">Fecha envío</div>
                            <div class="kv-value"><?= h(fmt_datetime($reparacion['fecha_envio'] ?? null)) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Fecha respuesta proveedor</div>
                            <div class="kv-value"><?= h(fmt_datetime($reparacion['fecha_respuesta_proveedor'] ?? null)) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Fecha equipo reparado</div>
                            <div class="kv-value"><?= h(fmt_datetime($reparacion['fecha_equipo_reparado'] ?? null)) ?></div>
                        </div>

                        <div class="col-12">
                            <div class="kv-label">Diagnóstico proveedor</div>
                            <div class="kv-value"><?= nl2br(h($reparacion['diagnostico_proveedor'])) ?></div>
                        </div>

                        <div class="col-md-6">
                            <div class="kv-label">Observaciones logística</div>
                            <div class="kv-value"><?= nl2br(h($reparacion['observaciones_logistica'] ?? '')) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="kv-label">Observaciones cliente</div>
                            <div class="kv-value"><?= nl2br(h($reparacion['observaciones_cliente'] ?? '')) ?></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-box">
                        Aún no existe información de reparación o cotización para este caso.
                    </div>
                <?php endif; ?>
            </div>

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-arrow-repeat"></i>
                    <span>Equipo de reemplazo</span>
                </div>

                <?php if ($reemplazo): ?>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="kv-label">Marca reemplazo</div>
                            <div class="kv-value"><?= h($reemplazo['marca_reemplazo']) ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="kv-label">Modelo reemplazo</div>
                            <div class="kv-value"><?= h($reemplazo['modelo_reemplazo']) ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="kv-label">Color reemplazo</div>
                            <div class="kv-value"><?= h($reemplazo['color_reemplazo']) ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="kv-label">Capacidad reemplazo</div>
                            <div class="kv-value"><?= h($reemplazo['capacidad_reemplazo']) ?></div>
                        </div>

                        <div class="col-md-6">
                            <div class="kv-label">IMEI reemplazo</div>
                            <div class="kv-value"><?= h($reemplazo['imei_reemplazo']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="kv-label">IMEI 2 reemplazo</div>
                            <div class="kv-value"><?= h($reemplazo['imei2_reemplazo']) ?: '-' ?></div>
                        </div>

                        <div class="col-md-4">
                            <div class="kv-label">Fecha registro</div>
                            <div class="kv-value"><?= h(fmt_datetime($reemplazo['fecha_registro'])) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Fecha entrega</div>
                            <div class="kv-value"><?= h(fmt_datetime($reemplazo['fecha_entrega'])) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Estatus inventario nuevo</div>
                            <div class="kv-value"><?= h($reemplazo['estatus_inventario_nuevo']) ?></div>
                        </div>

                        <div class="col-12">
                            <div class="kv-label">Observaciones</div>
                            <div class="kv-value"><?= nl2br(h($reemplazo['observaciones'])) ?></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-box">
                        Aún no se ha capturado un equipo de reemplazo para este caso.
                    </div>
                <?php endif; ?>
            </div>

            <div class="soft-card p-4 mb-4" id="bloque-excepcion">
                <div class="section-title">
                    <i class="bi bi-shield-exclamation"></i>
                    <span>Excepción de reemplazo</span>
                </div>

                <?php if ($excepcionActual): ?>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="kv-label">Precio original vendido</div>
                            <div class="kv-value"><?= h(money_fmt_local($precioOriginalExcepcion)) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Precio reemplazo propuesto</div>
                            <div class="kv-value"><?= h(money_fmt_local($excepcionActual['precio_reemplazo'] ?? null)) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Diferencia</div>
                            <div class="kv-value">
                                <?php
                                    $difExc = null;
                                    if ($precioOriginalExcepcion !== null && isset($excepcionActual['precio_reemplazo'])) {
                                        $difExc = (float)$excepcionActual['precio_reemplazo'] - (float)$precioOriginalExcepcion;
                                    }
                                    echo h(money_fmt_local($difExc));
                                ?>
                            </div>
                        </div>
                    </div>

                    <div class="border rounded-4 p-3">
                        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                            <h6 class="mb-0">Solicitud actual</h6>
                            <?php
                                $badgeClass = 'bg-secondary';
                                if ($excepcionActual['estatus'] === 'solicitada') $badgeClass = 'bg-warning text-dark';
                                if ($excepcionActual['estatus'] === 'autorizada') $badgeClass = 'bg-success';
                                if ($excepcionActual['estatus'] === 'rechazada') $badgeClass = 'bg-danger';
                            ?>
                            <span class="badge <?= h($badgeClass) ?>">
                                <?= h(ucfirst((string)$excepcionActual['estatus'])) ?>
                            </span>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <div class="kv-label">Equipo propuesto</div>
                                <div class="kv-value">
                                    <?= h(trim(($excepcionActual['marca'] ?? '') . ' ' . ($excepcionActual['modelo'] ?? ''))) ?>
                                    <?php if (!empty($excepcionActual['color']) || !empty($excepcionActual['capacidad'])): ?>
                                        <span class="text-muted">
                                            • <?= h($excepcionActual['color'] ?? '') ?><?= !empty($excepcionActual['capacidad']) ? ' • ' . h($excepcionActual['capacidad']) : '' ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="kv-label">IMEI propuesto</div>
                                <div class="kv-value"><?= h($excepcionActual['imei_propuesto'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-3">
                                <div class="kv-label">IMEI 2 propuesto</div>
                                <div class="kv-value"><?= h($excepcionActual['imei2_propuesto'] ?? '-') ?></div>
                            </div>

                            <div class="col-md-4">
                                <div class="kv-label">Solicitó</div>
                                <div class="kv-value"><?= h($excepcionActual['solicita_nombre'] ?? ('Usuario #' . (int)$excepcionActual['id_usuario_solicita'])) ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="kv-label">Fecha solicitud</div>
                                <div class="kv-value"><?= h($excepcionActual['fecha_solicitud'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="kv-label">Motivo</div>
                                <div class="kv-value"><?= nl2br(h($excepcionActual['motivo_solicitud'] ?? '-')) ?></div>
                            </div>

                            <div class="col-md-4">
                                <div class="kv-label">Resolvió</div>
                                <div class="kv-value"><?= h($excepcionActual['resuelve_nombre'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="kv-label">Fecha resolución</div>
                                <div class="kv-value"><?= h($excepcionActual['fecha_resolucion'] ?? '-') ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="kv-label">Comentario resolución</div>
                                <div class="kv-value"><?= !empty($excepcionActual['comentario_resolucion']) ? nl2br(h($excepcionActual['comentario_resolucion'])) : '-' ?></div>
                            </div>
                        </div>

                        <?php if ($puedeResolverExcepcion && $excepcionActual['estatus'] === 'solicitada'): ?>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAutorizarExcepcion">
                                    <i class="bi bi-check2-circle me-1"></i>Autorizar excepción
                                </button>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalRechazarExcepcion">
                                    <i class="bi bi-x-circle me-1"></i>Rechazar excepción
                                </button>
                            </div>
                        <?php endif; ?>

                        <?php if ($excepcionActual['estatus'] === 'autorizada' && $puedeResolverExcepcion && (string)$caso['estado'] === 'garantia_autorizada'): ?>
                            <div class="mt-3 d-grid d-md-flex justify-content-md-end">
                                <form method="post" action="garantias_aplicar_reemplazo_autorizado.php"
                                      onsubmit="return confirm('¿Aplicar el reemplazo autorizado a este caso?');">
                                    <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                    <input type="hidden" name="id_excepcion" value="<?= (int)$excepcionActual['id'] ?>">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-box-arrow-in-down me-1"></i>Aplicar reemplazo autorizado
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-box">
                        No hay solicitud de excepción registrada para este caso.
                    </div>
                <?php endif; ?>
            </div>

            <div class="soft-card p-4 mb-4" id="documentos">
                <div class="section-title">
                    <i class="bi bi-folder2-open"></i>
                    <span>Documentos generados</span>
                </div>

                <?php if (!$documentos): ?>
                    <div class="empty-box">
                        Aún no hay documentos registrados para este expediente.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Tipo</th>
                                    <th>Nombre</th>
                                    <th>Fecha</th>
                                    <th>Generó</th>
                                    <th class="text-center">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documentos as $doc): ?>
                                    <tr>
                                        <td><?= h(etiqueta_tipo_documento((string)$doc['tipo_documento'])) ?></td>
                                        <td><?= h($doc['nombre_archivo']) ?></td>
                                        <td><?= h(fmt_datetime($doc['fecha_generado'])) ?></td>
                                        <td><?= h($doc['generado_por_nombre'] ?: $doc['generado_por']) ?></td>
                                        <td class="text-center">
                                            <?php if (!empty($doc['ruta_archivo'])): ?>
                                                <a href="<?= h($doc['ruta_archivo']) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-box-arrow-up-right me-1"></i>Abrir
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">Sin ruta</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="soft-card p-4">
                <div class="section-title">
                    <i class="bi bi-clock-history"></i>
                    <span>Timeline del caso</span>
                </div>

                <?php if (!$eventos): ?>
                    <div class="empty-box">
                        No hay eventos registrados todavía.
                    </div>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($eventos as $ev): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div class="timeline-card">
                                    <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
                                        <div>
                                            <div class="fw-semibold"><?= h($ev['tipo_evento']) ?></div>
                                            <div class="muted-note"><?= h($ev['descripcion']) ?></div>
                                        </div>
                                        <div class="text-md-end">
                                            <div class="small fw-semibold"><?= h(fmt_datetime($ev['fecha_evento'])) ?></div>
                                            <div class="small text-muted"><?= h($ev['nombre_usuario']) ?><?= $ev['rol_usuario'] ? ' • ' . h($ev['rol_usuario']) : '' ?></div>
                                        </div>
                                    </div>

                                    <?php if (!empty($ev['estado_anterior']) || !empty($ev['estado_nuevo'])): ?>
                                        <div class="small mt-2">
                                            <span class="text-muted">Estado:</span>
                                            <?= h($ev['estado_anterior']) ?: '—' ?>
                                            <i class="bi bi-arrow-right mx-1"></i>
                                            <?= h($ev['estado_nuevo']) ?: '—' ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($ev['datos_json'])): ?>
                                        <details class="mt-2">
                                            <summary class="small text-primary" style="cursor:pointer;">Ver datos del evento</summary>
                                            <pre class="small bg-light p-2 rounded mt-2 mb-0" style="white-space:pre-wrap;"><?= h($ev['datos_json']) ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="sticky-box">

                <div class="soft-card p-4 mb-4">
                    <div class="section-title">
                        <i class="bi bi-shield-check"></i>
                        <span>Dictamen y resolución</span>
                    </div>

                    <div class="mb-3">
                        <div class="kv-label">Dictamen preliminar</div>
                        <div class="kv-value"><?= badge_dictamen((string)$caso['dictamen_preliminar']) ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="kv-label">Motivo no procede</div>
                        <div class="kv-value"><?= h($caso['motivo_no_procede']) ?: '-' ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="kv-label">Detalle del sistema</div>
                        <div class="kv-value"><?= !empty($caso['detalle_no_procede']) ? nl2br(h($caso['detalle_no_procede'])) : '-' ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="kv-label">Estado actual</div>
                        <div class="kv-value"><?= badge_estado((string)$caso['estado']) ?></div>
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <div class="kv-label">Es reparable</div>
                            <div class="kv-value"><?= (int)$caso['es_reparable'] === 1 ? 'Sí' : 'No' ?></div>
                        </div>
                        <div class="col-6">
                            <div class="kv-label">Requiere cotización</div>
                            <div class="kv-value"><?= (int)$caso['requiere_cotizacion'] === 1 ? 'Sí' : 'No' ?></div>
                        </div>
                    </div>

                    <hr>

                    <div class="mb-2">
                        <div class="kv-label">Días desde compra</div>
                        <div class="kv-value">
                            <?= $diasAntiguedad === null ? 'No calculable' : ((int)$diasAntiguedad . ' día(s)') ?>
                        </div>
                    </div>

                    <div>
                        <div class="kv-label">Cobertura</div>
                        <div class="kv-value">
                            <span class="badge rounded-pill text-bg-<?= h($cobertura['badge']) ?>">
                                <?= h($cobertura['titulo']) ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="soft-card p-4 mb-4">
                    <div class="section-title">
                        <i class="bi bi-signpost-split"></i>
                        <span>Navegación operativa</span>
                    </div>

                    <div class="d-grid gap-2">
                        <?php if ($mostrarBtnCapturarReemplazo): ?>
                            <a href="<?= h($linkCapturarReemplazo) ?>" class="btn btn-success">
                                <i class="bi bi-arrow-repeat me-1"></i>Capturar reemplazo
                            </a>
                        <?php endif; ?>

                        <?php if ($mostrarBtnEntregar): ?>
                            <a href="<?= h($linkEntregarGarantia) ?>" class="btn btn-primary">
                                <i class="bi bi-box2-check me-1"></i>Entregar al cliente
                            </a>
                        <?php endif; ?>

                        <?php if ($mostrarBtnRespuestaCliente): ?>
                            <a href="<?= h($linkRespuestaCliente) ?>" class="btn btn-warning text-dark">
                                <i class="bi bi-chat-dots me-1"></i>Registrar respuesta del cliente
                            </a>
                        <?php endif; ?>

                        <a href="<?= h($linkDocumento) ?>" target="_blank" class="btn <?= h($claseBotonDocumento) ?>">
                            <i class="bi bi-file-earmark-text me-1"></i><?= h($textoBotonDocumento) ?>
                        </a>

                        <?php if (!empty($ajusteAdmin['acuse_traspaso_url'])): ?>
                            <a href="<?= h($ajusteAdmin['acuse_traspaso_url']) ?>" target="_blank" class="btn btn-outline-primary">
                                <i class="bi bi-truck me-1"></i>Ver acuse de traspaso
                            </a>
                        <?php endif; ?>

                        <a href="#documentos" class="btn btn-outline-secondary">
                            <i class="bi bi-folder2-open me-1"></i>Ir a documentos
                        </a>

                        <?php if ($excepcionActual): ?>
                            <a href="#bloque-excepcion" class="btn btn-outline-warning">
                                <i class="bi bi-shield-exclamation me-1"></i>Ir a excepción
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="mt-3 muted-note">
                        Documento sugerido: <strong><?= h(etiqueta_tipo_documento($tipoDocumento)) ?></strong>
                    </div>
                </div>

                <?php if ($puedeLogistica): ?>
                    <div class="soft-card p-4 mb-4">
                        <div class="section-title">
                            <i class="bi bi-lightning-charge"></i>
                            <span>Gestión de logística</span>
                        </div>

                        <?php if (in_array($estado, ['capturada', 'recepcion_registrada', 'en_revision_logistica'], true)): ?>
                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-check2-circle text-success"></i> Autorizar garantía</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="autorizar_garantia">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-success btn-sm w-100">Autorizar garantía</button>
                            </form>

                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-x-circle text-danger"></i> Rechazar garantía</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="rechazar_garantia">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2" required></textarea>
                                </div>
                                <button type="submit" class="btn btn-danger btn-sm w-100">Rechazar garantía</button>
                            </form>

                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-truck text-primary"></i> Enviar a proveedor</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="enviar_proveedor">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Proveedor</label>
                                    <input type="text" name="proveedor_nombre" class="form-control form-control-sm" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm w-100">Enviar a proveedor</button>
                            </form>
                        <?php endif; ?>

                        <?php if (in_array($estado, ['enviada_diagnostico', 'cotizacion_disponible'], true)): ?>
                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-cash-coin text-warning"></i> Registrar cotización</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="cotizacion_disponible">

                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Proveedor</label>
                                    <input type="text" name="proveedor_nombre" class="form-control form-control-sm" value="<?= h($reparacion['proveedor_nombre'] ?? '') ?>" required>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Diagnóstico</label>
                                    <textarea name="diagnostico_proveedor" class="form-control form-control-sm" rows="2" required><?= h($reparacion['diagnostico_proveedor'] ?? '') ?></textarea>
                                </div>

                                <div class="row g-2">
                                    <div class="col-4">
                                        <label class="form-label small fw-semibold">Costo rev.</label>
                                        <input type="number" step="0.01" min="0" name="costo_revision" class="form-control form-control-sm" value="<?= h($reparacion['costo_revision'] ?? '0') ?>">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-semibold">Costo rep.</label>
                                        <input type="number" step="0.01" min="0" name="costo_reparacion" class="form-control form-control-sm" value="<?= h($reparacion['costo_reparacion'] ?? '0') ?>">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-semibold">Días</label>
                                        <input type="number" min="0" name="tiempo_estimado_dias" class="form-control form-control-sm" value="<?= h($reparacion['tiempo_estimado_dias'] ?? '') ?>">
                                    </div>
                                </div>

                                <div class="mt-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>

                                <button type="submit" class="btn btn-warning btn-sm w-100 mt-2">Guardar cotización</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($estado === 'cotizacion_disponible'): ?>
                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-hand-thumbs-up text-success"></i> Marcar cotización aceptada</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="cotizacion_aceptada">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-success btn-sm w-100">Aceptar cotización</button>
                            </form>

                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-hand-thumbs-down text-danger"></i> Marcar cotización rechazada</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="cotizacion_rechazada">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-danger btn-sm w-100">Rechazar cotización</button>
                            </form>
                        <?php endif; ?>

                        <?php if (in_array($estado, ['cotizacion_aceptada', 'en_reparacion'], true)): ?>
                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-tools text-primary"></i> Marcar en reparación</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="en_reparacion">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Proveedor</label>
                                    <input type="text" name="proveedor_nombre" class="form-control form-control-sm" value="<?= h($reparacion['proveedor_nombre'] ?? '') ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm w-100">Marcar en reparación</button>
                            </form>

                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-wrench-adjustable-circle text-success"></i> Marcar reparado</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="marcar_reparado">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-success btn-sm w-100">Marcar reparado</button>
                            </form>
                        <?php endif; ?>

                        <?php if (!in_array($estado, ['cerrado', 'cancelado'], true)): ?>
                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-lock text-dark"></i> Cerrar caso</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="cerrar_caso">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones finales</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-dark btn-sm w-100">Cerrar caso</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="soft-card p-4">
                    <div class="section-title">
                        <i class="bi bi-diagram-3"></i>
                        <span>Trazabilidad</span>
                    </div>

                    <div class="mb-3">
                        <div class="kv-label">ID garantía padre</div>
                        <div class="kv-value">
                            <?php if (!empty($caso['id_garantia_padre'])): ?>
                                <a href="garantias_detalle.php?id=<?= (int)$caso['id_garantia_padre'] ?>">
                                    #<?= (int)$caso['id_garantia_padre'] ?>
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="kv-label">ID garantía raíz</div>
                        <div class="kv-value">
                            <?php if (!empty($caso['id_garantia_raiz'])): ?>
                                <a href="garantias_detalle.php?id=<?= (int)$caso['id_garantia_raiz'] ?>">
                                    #<?= (int)$caso['id_garantia_raiz'] ?>
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="kv-label">Usuario logística</div>
                        <div class="kv-value"><?= h($caso['logistica_nombre']) ?: '-' ?></div>
                    </div>

                    <div>
                        <div class="kv-label">Usuario gerente</div>
                        <div class="kv-value"><?= h($caso['gerente_nombre']) ?: '-' ?></div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php if ($excepcionActual && $puedeResolverExcepcion && $excepcionActual['estatus'] === 'solicitada'): ?>
<div class="modal fade" id="modalAutorizarExcepcion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="garantias_resolver_excepcion.php" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Autorizar excepción</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                <input type="hidden" name="id_excepcion" value="<?= (int)$excepcionActual['id'] ?>">
                <input type="hidden" name="accion" value="autorizar">

                <div class="mb-3">
                    <label class="form-label fw-semibold">Comentario de autorización</label>
                    <textarea name="comentario_resolucion" class="form-control" rows="4" placeholder="Comentario opcional de autorización"></textarea>
                </div>

                <div class="small text-muted">
                    Estás autorizando un reemplazo con mayor valor para este caso.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check2-circle me-1"></i>Autorizar
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalRechazarExcepcion" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="garantias_resolver_excepcion.php" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Rechazar excepción</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                <input type="hidden" name="id_excepcion" value="<?= (int)$excepcionActual['id'] ?>">
                <input type="hidden" name="accion" value="rechazar">

                <div class="mb-3">
                    <label class="form-label fw-semibold">Comentario de rechazo</label>
                    <textarea name="comentario_resolucion" class="form-control" rows="4" placeholder="Motivo del rechazo" required></textarea>
                </div>

                <div class="small text-muted">
                    Al rechazar la excepción, no podrá aplicarse este reemplazo autorizado.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-x-circle me-1"></i>Rechazar
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($mostrarModalRecordatorioCierre): ?>
<div class="modal fade" id="modalRecordatorioCierre" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header border-0 pb-0">
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body pt-0 px-4 pb-4 text-center">
                <div class="modal-reminder-icon">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>

                <h4 class="fw-bold mb-2">Reemplazo capturado correctamente</h4>

                <p class="text-muted mb-3">
                    El equipo de reemplazo ya fue registrado, pero el proceso <strong>aún no está completo</strong>.
                </p>

                <div class="alert alert-warning text-start border-0 shadow-sm mb-3">
                    <div class="fw-semibold mb-2">Para cerrar correctamente esta garantía debes:</div>
                    <ul class="mb-0 ps-3">
                        <li><strong>Generar el documento de garantía</strong></li>
                        <li><strong>Realizar la entrega al cliente</strong></li>
                    </ul>
                </div>

                <p class="small text-muted mb-4">
                    Si no completas estos pasos, el caso quedará pendiente de cierre.
                </p>

                <div class="d-grid gap-2">
                    <a href="<?= h($linkDocumento) ?>" target="_blank" class="btn btn-success">
                        <i class="bi bi-file-earmark-text me-1"></i>Generar documento
                    </a>

                    <?php if ($mostrarBtnEntregar): ?>
                        <a href="<?= h($linkEntregarGarantia) ?>" class="btn btn-primary">
                            <i class="bi bi-box2-check me-1"></i>Ir a entrega al cliente
                        </a>
                    <?php endif; ?>

                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Entendido
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($mostrarModalRecordatorioCierre): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('modalRecordatorioCierre');
    if (el && typeof bootstrap !== 'undefined') {
        var modal = new bootstrap.Modal(el);
        modal.show();
    }
});
</script>
<?php endif; ?>

<?php if ($autoAbrirAcuse): ?>
<script>
window.addEventListener('load', function () {
    window.open(
        'acuse_traspaso.php?id=<?= (int)$ajusteAdmin['id_traspaso'] ?>&print=1',
        '_blank'
    );
});
</script>
<?php endif; ?>

</body>
</html>