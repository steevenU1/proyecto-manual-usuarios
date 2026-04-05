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
   PERMISOS
========================================================= */
$ROL = (string)($_SESSION['rol'] ?? '');
$ROLES_PERMITIDOS = [
    'Admin', 'Administrador',
    'Gerente', 'GerenteZona',
    'Ejecutivo', 'Logistica',
    'Subdis_Admin', 'Subdis_Gerente', 'Subdis_Ejecutivo'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para guardar garantías.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método no permitido.');
}

/* =========================================================
   HELPERS
========================================================= */
function htrim($v): string {
    return trim((string)$v);
}

function sanitize_imei($v): string {
    $v = preg_replace('/\s+/', '', (string)$v);
    $v = preg_replace('/[^0-9A-Za-z]/', '', $v);
    return strtoupper(trim($v));
}

function null_if_empty($v): ?string {
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function int_or_null($v): ?int {
    if ($v === null || $v === '') return null;
    return (int)$v;
}

function yn_to_nullable_int($v): ?int {
    if ($v === '' || $v === null) return null;
    return ((string)$v === '1') ? 1 : 0;
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

function bindParamsDynamic(mysqli_stmt $stmt, string $types, array &$params): void {
    $refs = [];
    $refs[] = $types;

    foreach ($params as $k => $v) {
        $refs[] = &$params[$k];
    }

    array_unshift($refs, $stmt);
    call_user_func_array('mysqli_stmt_bind_param', $refs);
}

function registrar_evento(
    mysqli $conn,
    int $idGarantia,
    string $tipoEvento,
    ?string $estadoAnterior,
    ?string $estadoNuevo,
    ?string $descripcion,
    ?array $datosJson,
    ?int $idUsuario,
    ?string $nombreUsuario,
    ?string $rolUsuario
): void {
    $datos = $datosJson ? json_encode($datosJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $sql = "INSERT INTO garantias_eventos
            (id_garantia, tipo_evento, estado_anterior, estado_nuevo, descripcion, datos_json, id_usuario, nombre_usuario, rol_usuario, fecha_evento)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $st = $conn->prepare($sql);
    if (!$st) {
        throw new Exception("Error en prepare() de garantias_eventos: " . $conn->error);
    }

    $st->bind_param(
        "isssssiss",
        $idGarantia,
        $tipoEvento,
        $estadoAnterior,
        $estadoNuevo,
        $descripcion,
        $datos,
        $idUsuario,
        $nombreUsuario,
        $rolUsuario
    );

    if (!$st->execute()) {
        throw new Exception("Error al insertar en garantias_eventos: " . $st->error);
    }

    $st->close();
}

function generar_folio_garantia(mysqli $conn): string {
    $prefijo = 'GAR-' . date('Ymd') . '-';

    $sql = "SELECT folio
            FROM garantias_casos
            WHERE folio LIKE CONCAT(?, '%')
            ORDER BY id DESC
            LIMIT 1";
    $st = $conn->prepare($sql);
    if (!$st) {
        throw new Exception("Error en prepare() al generar folio: " . $conn->error);
    }

    $st->bind_param("s", $prefijo);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    $next = 1;
    if ($row && !empty($row['folio']) && preg_match('/(\d{4})$/', $row['folio'], $m)) {
        $next = ((int)$m[1]) + 1;
    }

    return $prefijo . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

/**
 * Política:
 * 0-30 días   => garantía con distribuidor
 * 31-90 días  => revisión con proveedor
 * >90 días    => no procede por garantía vencida
 */
function dictaminar_backend(array $data): array {
    $origen = (string)($data['origen'] ?? '');
    $fechaCompra = $data['fecha_compra'] ?? null;
    $garantiaAbiertaId = $data['garantia_abierta_id'] ?? null;

    $danoFisico = $data['check_dano_fisico'] ?? null;
    $humedad = $data['check_humedad'] ?? null;
    $bloqueo = $data['check_bloqueo_patron_google'] ?? null;
    $appFin = $data['check_app_financiera'] ?? null;

    $diasCompra = diff_days_from_today($fechaCompra);
    $esVentana7Dias = ($diasCompra !== null && $diasCompra >= 0 && $diasCompra <= 7) ? 1 : 0;

    if (!$origen && empty($data['imei_original'])) {
        return [
            'dias_compra'         => $diasCompra,
            'es_ventana_7_dias'   => $esVentana7Dias,
            'dictamen_preliminar' => 'revision_logistica',
            'motivo_no_procede'   => null,
            'detalle_no_procede'  => 'No existe información suficiente para calcular el dictamen.',
            'estado'              => 'capturada',
            'es_reparable'        => 0,
            'requiere_cotizacion' => 0,
            'tipo_atencion'       => 'revision_tecnica',
            'cobertura'           => 'indefinida'
        ];
    }

    if (!empty($garantiaAbiertaId)) {
        return [
            'dias_compra'         => $diasCompra,
            'es_ventana_7_dias'   => $esVentana7Dias,
            'dictamen_preliminar' => 'no_procede',
            'motivo_no_procede'   => 'GARANTIA_PREVIA_ABIERTA',
            'detalle_no_procede'  => 'El IMEI ya cuenta con una garantía activa en proceso.',
            'estado'              => 'capturada',
            'es_reparable'        => 0,
            'requiere_cotizacion' => 0,
            'tipo_atencion'       => 'postventa',
            'cobertura'           => 'bloqueada'
        ];
    }

    if ($origen === 'manual') {
        return [
            'dias_compra'         => $diasCompra,
            'es_ventana_7_dias'   => $esVentana7Dias,
            'dictamen_preliminar' => 'imei_no_localizado',
            'motivo_no_procede'   => 'IMEI_NO_LOCALIZADO',
            'detalle_no_procede'  => 'No se encontró el IMEI en ventas ni en reemplazos previos. Requiere validación manual.',
            'estado'              => 'capturada',
            'es_reparable'        => 0,
            'requiere_cotizacion' => 0,
            'tipo_atencion'       => 'revision_tecnica',
            'cobertura'           => 'indefinida'
        ];
    }

    if ((string)$danoFisico === '1') {
        return [
            'dias_compra'         => $diasCompra,
            'es_ventana_7_dias'   => $esVentana7Dias,
            'dictamen_preliminar' => 'no_procede',
            'motivo_no_procede'   => 'DANO_FISICO',
            'detalle_no_procede'  => 'Se detectó daño físico imputable al cliente.',
            'estado'              => 'capturada',
            'es_reparable'        => 1,
            'requiere_cotizacion' => 1,
            'tipo_atencion'       => 'postventa',
            'cobertura'           => 'excluida'
        ];
    }

    if ((string)$humedad === '1') {
        return [
            'dias_compra'         => $diasCompra,
            'es_ventana_7_dias'   => $esVentana7Dias,
            'dictamen_preliminar' => 'no_procede',
            'motivo_no_procede'   => 'HUMEDAD',
            'detalle_no_procede'  => 'Se detectó humedad en el equipo.',
            'estado'              => 'capturada',
            'es_reparable'        => 1,
            'requiere_cotizacion' => 1,
            'tipo_atencion'       => 'postventa',
            'cobertura'           => 'excluida'
        ];
    }

    if ((string)$bloqueo === '1') {
        return [
            'dias_compra'         => $diasCompra,
            'es_ventana_7_dias'   => $esVentana7Dias,
            'dictamen_preliminar' => 'no_procede',
            'motivo_no_procede'   => 'BLOQUEO_CUENTA',
            'detalle_no_procede'  => 'El bloqueo por patrón, PIN o cuenta no forma parte de la garantía.',
            'estado'              => 'capturada',
            'es_reparable'        => 0,
            'requiere_cotizacion' => 0,
            'tipo_atencion'       => 'postventa',
            'cobertura'           => 'excluida'
        ];
    }

    if ((string)$appFin === '0') {
        return [
            'dias_compra'         => $diasCompra,
            'es_ventana_7_dias'   => $esVentana7Dias,
            'dictamen_preliminar' => 'revision_logistica',
            'motivo_no_procede'   => null,
            'detalle_no_procede'  => 'La app financiera no está presente y requiere validación adicional por logística.',
            'estado'              => 'en_revision_logistica',
            'es_reparable'        => 0,
            'requiere_cotizacion' => 0,
            'tipo_atencion'       => 'revision_tecnica',
            'cobertura'           => 'revision_logistica'
        ];
    }

    if ($diasCompra === null) {
        return [
            'dias_compra'         => null,
            'es_ventana_7_dias'   => 0,
            'dictamen_preliminar' => 'revision_logistica',
            'motivo_no_procede'   => null,
            'detalle_no_procede'  => 'No se pudo calcular la antigüedad del equipo con la fecha de compra recibida.',
            'estado'              => 'en_revision_logistica',
            'es_reparable'        => 0,
            'requiere_cotizacion' => 0,
            'tipo_atencion'       => 'revision_tecnica',
            'cobertura'           => 'indefinida'
        ];
    }

    if ($diasCompra > 90) {
        return [
            'dias_compra'         => $diasCompra,
            'es_ventana_7_dias'   => 0,
            'dictamen_preliminar' => 'no_procede',
            'motivo_no_procede'   => 'GARANTIA_VENCIDA',
            'detalle_no_procede'  => "El equipo supera el periodo máximo de cobertura ({$diasCompra} días desde compra).",
            'estado'              => 'capturada',
            'es_reparable'        => 1,
            'requiere_cotizacion' => 1,
            'tipo_atencion'       => 'postventa',
            'cobertura'           => 'vencida'
        ];
    }

    if ($diasCompra >= 31 && $diasCompra <= 90) {
        return [
            'dias_compra'         => $diasCompra,
            'es_ventana_7_dias'   => 0,
            'dictamen_preliminar' => 'revision_proveedor',
            'motivo_no_procede'   => 'REVISION_PROVEEDOR_31_90',
            'detalle_no_procede'  => "El equipo tiene {$diasCompra} días desde compra. Ya no entra en garantía directa con distribuidor y debe canalizarse a revisión con proveedor.",
            'estado'              => 'en_revision_logistica',
            'es_reparable'        => 0,
            'requiere_cotizacion' => 0,
            'tipo_atencion'       => 'revision_tecnica',
            'cobertura'           => 'proveedor_31_90'
        ];
    }

    if ($diasCompra >= 0 && $diasCompra <= 30) {
        $detalle = "Cumple condiciones iniciales para garantía con distribuidor. Antigüedad: {$diasCompra} días.";

        if ($esVentana7Dias === 1) {
            $detalle = "Cumple condiciones iniciales para garantía con distribuidor. Antigüedad: {$diasCompra} días. Está dentro de la ventana especial de 0 a 7 días.";
        }

        return [
            'dias_compra'         => $diasCompra,
            'es_ventana_7_dias'   => $esVentana7Dias,
            'dictamen_preliminar' => 'procede',
            'motivo_no_procede'   => null,
            'detalle_no_procede'  => $detalle,
            'estado'              => 'en_revision_logistica',
            'es_reparable'        => 0,
            'requiere_cotizacion' => 0,
            'tipo_atencion'       => 'garantia',
            'cobertura'           => ($esVentana7Dias === 1 ? 'distribuidor_0_7' : 'distribuidor_8_30')
        ];
    }

    return [
        'dias_compra'         => $diasCompra,
        'es_ventana_7_dias'   => $esVentana7Dias,
        'dictamen_preliminar' => 'revision_logistica',
        'motivo_no_procede'   => null,
        'detalle_no_procede'  => 'No se detectó rechazo automático, pero el caso requiere validación logística.',
        'estado'              => 'en_revision_logistica',
        'es_reparable'        => 0,
        'requiere_cotizacion' => 0,
        'tipo_atencion'       => 'revision_tecnica',
        'cobertura'           => 'revision_logistica'
    ];
}

/* =========================================================
   VALIDAR TABLAS
========================================================= */
if (!table_exists($conn, 'garantias_casos') || !table_exists($conn, 'garantias_eventos')) {
    exit('No existen las tablas del módulo de garantías. Ejecuta primero el SQL del módulo.');
}

/* =========================================================
   LEER POST
========================================================= */
$idUsuarioSesion = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursalSesion = (int)($_SESSION['id_sucursal'] ?? 0);
$nombreUsuarioSesion = (string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario');
$rolUsuarioSesion = $ROL;

$imeiBusqueda = sanitize_imei($_POST['imei_busqueda'] ?? '');
$origen = htrim($_POST['origen'] ?? 'manual');

$idVenta = int_or_null($_POST['id_venta'] ?? null);
$idDetalleVenta = int_or_null($_POST['id_detalle_venta'] ?? null);
$idProducto = int_or_null($_POST['id_producto'] ?? null);
$idCliente = int_or_null($_POST['id_cliente'] ?? null);
$idGarantiaPadre = int_or_null($_POST['id_garantia_padre'] ?? null);
$idGarantiaRaiz = int_or_null($_POST['id_garantia_raiz'] ?? null);

$clienteNombre = htrim($_POST['cliente_nombre'] ?? '');
$clienteTelefono = null_if_empty($_POST['cliente_telefono'] ?? null);
$clienteCorreo = null_if_empty($_POST['cliente_correo'] ?? null);

$marca = null_if_empty($_POST['marca'] ?? null);
$modelo = null_if_empty($_POST['modelo'] ?? null);
$color = null_if_empty($_POST['color'] ?? null);
$capacidad = null_if_empty($_POST['capacidad'] ?? null);
$proveedor = null_if_empty($_POST['proveedor'] ?? null);

$esCombo = yn_to_nullable_int($_POST['es_combo'] ?? 0);
if ($esCombo === null) {
    $esCombo = 0;
}

$tipoEquipoVenta = null_if_empty($_POST['tipo_equipo_venta'] ?? null);
if ($tipoEquipoVenta !== null) {
    $tipoEquipoVenta = strtolower(trim($tipoEquipoVenta));
    if (!in_array($tipoEquipoVenta, ['principal', 'combo'], true)) {
        $tipoEquipoVenta = null;
    }
}
if ($tipoEquipoVenta === null) {
    $tipoEquipoVenta = ((int)$esCombo === 1) ? 'combo' : 'principal';
}

$imeiOriginal = sanitize_imei($_POST['imei_original'] ?? $imeiBusqueda);
$imei2Original = sanitize_imei($_POST['imei2_original'] ?? '');
if ($imei2Original === '') {
    $imei2Original = null;
}

$fechaCompra = null_if_empty($_POST['fecha_compra'] ?? null);
$tagVenta = null_if_empty($_POST['tag_venta'] ?? null);
$modalidadVenta = null_if_empty($_POST['modalidad_venta'] ?? null);
$financiera = null_if_empty($_POST['financiera_hidden'] ?? $_POST['financiera'] ?? null);

$diasDesdeCompraPost = int_or_null($_POST['dias_desde_compra'] ?? null);
$esVentana7DiasPost = isset($_POST['es_ventana_7_dias']) ? (int)$_POST['es_ventana_7_dias'] : 0;

$fechaRecepcion = null_if_empty($_POST['fecha_recepcion'] ?? date('Y-m-d'));
$tipoAtencionPost = null_if_empty($_POST['tipo_atencion'] ?? 'garantia');

$descripcionFalla = htrim($_POST['descripcion_falla'] ?? '');
$observacionesTienda = null_if_empty($_POST['observaciones_tienda'] ?? null);

$checkEncendido = yn_to_nullable_int($_POST['check_encendido'] ?? null);
$checkDanoFisico = yn_to_nullable_int($_POST['check_dano_fisico'] ?? null);
$checkHumedad = yn_to_nullable_int($_POST['check_humedad'] ?? null);
$checkPantalla = yn_to_nullable_int($_POST['check_pantalla'] ?? null);
$checkCamara = yn_to_nullable_int($_POST['check_camara'] ?? null);
$checkBocinaMicrofono = yn_to_nullable_int($_POST['check_bocina_microfono'] ?? null);
$checkPuertoCarga = yn_to_nullable_int($_POST['check_puerto_carga'] ?? null);
$checkAppFinanciera = yn_to_nullable_int($_POST['check_app_financiera'] ?? null);
$checkBloqueoPatronGoogle = yn_to_nullable_int($_POST['check_bloqueo_patron_google'] ?? null);

/*
 * Mientras la vista de cotización esté oculta, forzamos backend a 0
 * para que nadie lo altere desde el cliente.
 */
$requiereCotizacionPost = 0;
$prioridad = null_if_empty($_POST['prioridad'] ?? 'normal');
if ($prioridad === null || !in_array($prioridad, ['normal', 'alta', 'urgente'], true)) {
    $prioridad = 'normal';
}

$garantiaAbiertaId = int_or_null($_POST['garantia_abierta_id'] ?? null);

/* =========================================================
   PARCHE: RECUPERAR PROVEEDOR DESDE PRODUCTOS SI NO LLEGÓ
========================================================= */
if (
    $proveedor === null &&
    $idProducto !== null &&
    $idProducto > 0 &&
    table_exists($conn, 'productos') &&
    column_exists($conn, 'productos', 'proveedor')
) {
    $sqlProveedor = "SELECT proveedor
                     FROM productos
                     WHERE id = ?
                     LIMIT 1";
    $stProveedor = $conn->prepare($sqlProveedor);
    if ($stProveedor) {
        $stProveedor->bind_param("i", $idProducto);
        $stProveedor->execute();
        $rowProveedor = $stProveedor->get_result()->fetch_assoc();
        $stProveedor->close();

        if ($rowProveedor && array_key_exists('proveedor', $rowProveedor)) {
            $proveedor = null_if_empty($rowProveedor['proveedor']);
        }
    }
}

/* =========================================================
   VALIDACIONES
========================================================= */
$errores = [];

if ($imeiOriginal === '') {
    $errores[] = 'No se recibió un IMEI válido.';
}

if ($descripcionFalla === '') {
    $errores[] = 'Debes capturar la descripción de la falla.';
}

if ($fechaRecepcion === null) {
    $errores[] = 'La fecha de recepción es obligatoria.';
}

if ($origen !== 'manual' && $clienteNombre === '') {
    $errores[] = 'No se recuperó el nombre del cliente desde la venta. Revisa el IMEI consultado.';
}

if ($origen !== 'manual' && (!$idVenta || $idVenta <= 0)) {
    $errores[] = 'No se recibió la venta relacionada del equipo localizado.';
}

if ($garantiaAbiertaId) {
    $errores[] = 'El IMEI ya cuenta con una garantía abierta.';
}

if ($clienteCorreo !== null && !filter_var($clienteCorreo, FILTER_VALIDATE_EMAIL)) {
    $errores[] = 'El correo del cliente no tiene un formato válido.';
}

if (!empty($errores)) {
    http_response_code(422);
    echo "<h3>No se pudo guardar la garantía</h3><ul>";
    foreach ($errores as $e) {
        echo "<li>" . htmlspecialchars($e, ENT_QUOTES, 'UTF-8') . "</li>";
    }
    echo "</ul><p><a href='javascript:history.back()'>Volver</a></p>";
    exit();
}

/* =========================================================
   CALCULAR NIVEL / RAIZ
========================================================= */
$nivelReincidencia = 0;

if ($idGarantiaPadre) {
    $sqlPadre = "SELECT id, id_garantia_raiz, nivel_reincidencia
                 FROM garantias_casos
                 WHERE id = ?
                 LIMIT 1";
    $stPadre = $conn->prepare($sqlPadre);
    if (!$stPadre) {
        throw new Exception("Error en prepare() de consulta padre: " . $conn->error);
    }

    $stPadre->bind_param("i", $idGarantiaPadre);
    $stPadre->execute();
    $padre = $stPadre->get_result()->fetch_assoc();
    $stPadre->close();

    if ($padre) {
        $nivelReincidencia = ((int)$padre['nivel_reincidencia']) + 1;
        if (!$idGarantiaRaiz) {
            $idGarantiaRaiz = !empty($padre['id_garantia_raiz']) ? (int)$padre['id_garantia_raiz'] : (int)$padre['id'];
        }
    }
}

/* =========================================================
   DICTAMEN BACKEND
========================================================= */
$dictamen = dictaminar_backend([
    'origen' => $origen,
    'fecha_compra' => $fechaCompra,
    'garantia_abierta_id' => $garantiaAbiertaId,
    'imei_original' => $imeiOriginal,
    'check_dano_fisico' => $checkDanoFisico,
    'check_humedad' => $checkHumedad,
    'check_bloqueo_patron_google' => $checkBloqueoPatronGoogle,
    'check_app_financiera' => $checkAppFinanciera,
]);

$diasCompra = $dictamen['dias_compra'];
$esVentana7Dias = (int)($dictamen['es_ventana_7_dias'] ?? 0);
$dictamenPreliminar = $dictamen['dictamen_preliminar'];
$motivoNoProcede = $dictamen['motivo_no_procede'];
$detalleNoProcede = $dictamen['detalle_no_procede'];
$estadoInicial = $dictamen['estado'];
$esReparable = (int)$dictamen['es_reparable'];
$tipoAtencion = (string)$dictamen['tipo_atencion'];
$cobertura = (string)$dictamen['cobertura'];

$requiereCotizacion = $requiereCotizacionPost === 1 ? 1 : (int)$dictamen['requiere_cotizacion'];

if ($tipoAtencionPost && $dictamenPreliminar === 'procede') {
    $tipoAtencion = 'garantia';
}

/* =========================================================
   TIPO ORIGEN
========================================================= */
$tipoOrigen = 'manual';
if ($origen === 'venta') {
    $tipoOrigen = 'venta';
} elseif ($origen === 'reemplazo_garantia') {
    $tipoOrigen = 'reemplazo_garantia';
}

/* =========================================================
   VALIDAR EXISTENCIA DE COLUMNAS OPCIONALES
========================================================= */
$garantiasTieneIdCliente = column_exists($conn, 'garantias_casos', 'id_cliente');
$garantiasTieneEsCombo = column_exists($conn, 'garantias_casos', 'es_combo');
$garantiasTieneTipoEquipoVenta = column_exists($conn, 'garantias_casos', 'tipo_equipo_venta');
$garantiasTieneProveedor = column_exists($conn, 'garantias_casos', 'proveedor');
$garantiasTieneTipoAtencion = column_exists($conn, 'garantias_casos', 'tipo_atencion');
$garantiasTienePrioridad = column_exists($conn, 'garantias_casos', 'prioridad');
$garantiasTieneDiasCompra = column_exists($conn, 'garantias_casos', 'dias_compra');
$garantiasTieneEsVentana7Dias = column_exists($conn, 'garantias_casos', 'es_ventana_7_dias');
$garantiasTieneCobertura = column_exists($conn, 'garantias_casos', 'cobertura');
$clientesDisponible = table_exists($conn, 'clientes');

/* =========================================================
   INSERT / TRANSACCION
========================================================= */
$conn->begin_transaction();

try {
    /* -----------------------------------------
       1) Actualizar catálogo de clientes
    ----------------------------------------- */
    if ($clientesDisponible && $idCliente && $idCliente > 0) {
        $sqlCliente = "SELECT id, id_sucursal
                       FROM clientes
                       WHERE id = ?
                       LIMIT 1";
        $stCliente = $conn->prepare($sqlCliente);
        if (!$stCliente) {
            throw new Exception("Error en prepare() al consultar cliente: " . $conn->error);
        }

        $stCliente->bind_param("i", $idCliente);
        $stCliente->execute();
        $clienteDB = $stCliente->get_result()->fetch_assoc();
        $stCliente->close();

        if ($clienteDB) {
            $sqlUpdCliente = "UPDATE clientes
                              SET nombre = ?,
                                  telefono = ?,
                                  correo = ?,
                                  ultima_compra = NOW(),
                                  id_sucursal = CASE
                                      WHEN id_sucursal IS NULL OR id_sucursal = 0 THEN ?
                                      ELSE id_sucursal
                                  END
                              WHERE id = ?
                              LIMIT 1";
            $stUpdCliente = $conn->prepare($sqlUpdCliente);
            if (!$stUpdCliente) {
                throw new Exception("Error en prepare() al actualizar cliente: " . $conn->error);
            }

            $stUpdCliente->bind_param(
                "sssii",
                $clienteNombre,
                $clienteTelefono,
                $clienteCorreo,
                $idSucursalSesion,
                $idCliente
            );

            if (!$stUpdCliente->execute()) {
                throw new Exception("Error al actualizar datos del cliente: " . $stUpdCliente->error);
            }
            $stUpdCliente->close();
        }
    }

    /* -----------------------------------------
       2) Insertar garantía
    ----------------------------------------- */
    $folio = generar_folio_garantia($conn);

    $columnas = [
        'folio',
        'tipo_origen',
        'id_venta',
        'id_detalle_venta',
        'id_garantia_padre',
        'id_garantia_raiz',
        'nivel_reincidencia',
        'id_sucursal',
        'id_usuario_captura'
    ];

    $params = [
        $folio,
        $tipoOrigen,
        $idVenta,
        $idDetalleVenta,
        $idGarantiaPadre,
        $idGarantiaRaiz,
        $nivelReincidencia,
        $idSucursalSesion,
        $idUsuarioSesion
    ];

    if ($garantiasTieneIdCliente) {
        $columnas[] = 'id_cliente';
        $params[] = $idCliente;
    }

    $columnas = array_merge($columnas, [
        'cliente_nombre',
        'cliente_telefono',
        'cliente_correo',
        'id_producto_original'
    ]);

    $params = array_merge($params, [
        $clienteNombre,
        $clienteTelefono,
        $clienteCorreo,
        $idProducto
    ]);

    if ($garantiasTieneEsCombo) {
        $columnas[] = 'es_combo';
        $params[] = $esCombo;
    }

    if ($garantiasTieneTipoEquipoVenta) {
        $columnas[] = 'tipo_equipo_venta';
        $params[] = $tipoEquipoVenta;
    }

    $columnas = array_merge($columnas, [
        'marca',
        'modelo',
        'color',
        'capacidad'
    ]);

    $params = array_merge($params, [
        $marca,
        $modelo,
        $color,
        $capacidad
    ]);

    if ($garantiasTieneProveedor) {
        $columnas[] = 'proveedor';
        $params[] = $proveedor;
    }

    if ($garantiasTieneTipoAtencion) {
        $columnas[] = 'tipo_atencion';
        $params[] = $tipoAtencion;
    }

    if ($garantiasTienePrioridad) {
        $columnas[] = 'prioridad';
        $params[] = $prioridad;
    }

    if ($garantiasTieneDiasCompra) {
        $columnas[] = 'dias_compra';
        $params[] = $diasCompra;
    }

    if ($garantiasTieneEsVentana7Dias) {
        $columnas[] = 'es_ventana_7_dias';
        $params[] = $esVentana7Dias;
    }

    if ($garantiasTieneCobertura) {
        $columnas[] = 'cobertura';
        $params[] = $cobertura;
    }

    $columnas = array_merge($columnas, [
        'imei_original',
        'imei2_original',
        'fecha_compra',
        'tag_venta',
        'modalidad_venta',
        'financiera',
        'descripcion_falla',
        'observaciones_tienda',
        'check_encendido',
        'check_dano_fisico',
        'check_humedad',
        'check_pantalla',
        'check_camara',
        'check_bocina_microfono',
        'check_puerto_carga',
        'check_app_financiera',
        'check_bloqueo_patron_google',
        'dictamen_preliminar',
        'motivo_no_procede',
        'detalle_no_procede',
        'estado',
        'es_reparable',
        'requiere_cotizacion',
        'fecha_recepcion'
    ]);

    $params = array_merge($params, [
        $imeiOriginal,
        $imei2Original,
        $fechaCompra,
        $tagVenta,
        $modalidadVenta,
        $financiera,
        $descripcionFalla,
        $observacionesTienda,
        $checkEncendido,
        $checkDanoFisico,
        $checkHumedad,
        $checkPantalla,
        $checkCamara,
        $checkBocinaMicrofono,
        $checkPuertoCarga,
        $checkAppFinanciera,
        $checkBloqueoPatronGoogle,
        $dictamenPreliminar,
        $motivoNoProcede,
        $detalleNoProcede,
        $estadoInicial,
        $esReparable,
        $requiereCotizacion,
        $fechaRecepcion
    ]);

    $placeholders = array_fill(0, count($columnas), '?');
    $columnasSql = implode(",\n                    ", $columnas);
    $placeholdersSql = implode(", ", $placeholders);

    $sql = "INSERT INTO garantias_casos (
                    {$columnasSql},
                    fecha_dictamen,
                    created_at,
                    updated_at
                ) VALUES (
                    {$placeholdersSql},
                    NOW(), NOW(), NOW()
                )";

    $st = $conn->prepare($sql);
    if (!$st) {
        throw new Exception("Error en prepare() de garantias_casos: " . $conn->error);
    }

    $types = '';
    foreach ($params as $p) {
        $types .= is_int($p) ? 'i' : 's';
    }

    bindParamsDynamic($st, $types, $params);

    if (!$st->execute()) {
        throw new Exception("Error al ejecutar INSERT en garantias_casos: " . $st->error);
    }

    $idGarantia = (int)$st->insert_id;
    $st->close();

    /* -----------------------------------------
       3) Ajustar raíz si aplica
    ----------------------------------------- */
    if (!$idGarantiaRaiz) {
        $sqlRaiz = "UPDATE garantias_casos
                    SET id_garantia_raiz = ?
                    WHERE id = ?";
        $stRaiz = $conn->prepare($sqlRaiz);
        if (!$stRaiz) {
            throw new Exception("Error en prepare() de update raíz: " . $conn->error);
        }

        $stRaiz->bind_param("ii", $idGarantia, $idGarantia);
        if (!$stRaiz->execute()) {
            throw new Exception("Error al actualizar id_garantia_raiz: " . $stRaiz->error);
        }
        $stRaiz->close();

        $idGarantiaRaiz = $idGarantia;
    }

    /* -----------------------------------------
       4) Eventos
    ----------------------------------------- */
    registrar_evento(
        $conn,
        $idGarantia,
        'creacion',
        null,
        $estadoInicial,
        'Se creó la solicitud de garantía / reparación.',
        [
            'folio' => $folio,
            'tipo_origen' => $tipoOrigen,
            'tipo_atencion' => $tipoAtencion,
            'prioridad' => $prioridad,
            'imei_original' => $imeiOriginal,
            'imei2_original' => $imei2Original,
            'dictamen_preliminar' => $dictamenPreliminar,
            'dias_compra' => $diasCompra,
            'dias_desde_compra_post' => $diasDesdeCompraPost,
            'es_ventana_7_dias' => $esVentana7Dias,
            'es_ventana_7_dias_post' => $esVentana7DiasPost,
            'cobertura' => $cobertura,
            'id_cliente' => $idCliente,
            'es_combo' => $esCombo,
            'tipo_equipo_venta' => $tipoEquipoVenta,
            'proveedor' => $proveedor
        ],
        $idUsuarioSesion,
        $nombreUsuarioSesion,
        $rolUsuarioSesion
    );

    registrar_evento(
        $conn,
        $idGarantia,
        'recepcion',
        null,
        $estadoInicial,
        'Se registró la recepción inicial del equipo en tienda.',
        [
            'fecha_recepcion' => $fechaRecepcion,
            'cliente_nombre' => $clienteNombre,
            'cliente_telefono' => $clienteTelefono,
            'cliente_correo' => $clienteCorreo,
            'descripcion_falla' => $descripcionFalla,
            'tipo_atencion' => $tipoAtencion,
            'prioridad' => $prioridad,
            'es_combo' => $esCombo,
            'tipo_equipo_venta' => $tipoEquipoVenta,
            'proveedor' => $proveedor
        ],
        $idUsuarioSesion,
        $nombreUsuarioSesion,
        $rolUsuarioSesion
    );

    registrar_evento(
        $conn,
        $idGarantia,
        'dictamen_preliminar',
        null,
        $estadoInicial,
        'El sistema calculó el dictamen preliminar del caso.',
        [
            'dictamen_preliminar' => $dictamenPreliminar,
            'motivo_no_procede' => $motivoNoProcede,
            'detalle_no_procede' => $detalleNoProcede,
            'es_reparable' => $esReparable,
            'requiere_cotizacion' => $requiereCotizacion,
            'tipo_atencion' => $tipoAtencion,
            'dias_compra' => $diasCompra,
            'es_ventana_7_dias' => $esVentana7Dias,
            'cobertura' => $cobertura,
            'es_combo' => $esCombo,
            'tipo_equipo_venta' => $tipoEquipoVenta,
            'proveedor' => $proveedor
        ],
        $idUsuarioSesion,
        $nombreUsuarioSesion,
        $rolUsuarioSesion
    );

    $conn->commit();

    header("Location: garantias_detalle.php?id=" . $idGarantia . "&ok=1");
    exit();

} catch (Throwable $e) {
    $conn->rollback();

    http_response_code(500);
    echo "<h3>Error al guardar la garantía</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
    echo "<p><a href='javascript:history.back()'>Volver</a></p>";
    exit();
}