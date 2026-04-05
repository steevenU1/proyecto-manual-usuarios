<?php
// compras_guardar.php
// Guarda encabezado y renglones por MODELO del catálogo + otros cargos + pago contado opcional
// ✅ Multi-tenant: propiedad (LUGA/SUBDISTRIBUIDOR) + id_subdis (desde sesión)
// ✅ Subdis: fuerza sucursal destino = sucursal de sesión
// ✅ Anti-duplicados por factura separado por propietario

require_once __DIR__ . '/candado_captura.php';
abortar_si_captura_bloqueada(); // por defecto bloquea POST

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
require_once __DIR__ . '/db.php';

$ID_USUARIO   = (int)($_SESSION['id_usuario'] ?? 0);
$ROL          = (string)($_SESSION['rol'] ?? '');
$ID_SUC_SES   = (int)($_SESSION['id_sucursal'] ?? 0);

/* =========================================================
   ✅ NUEVO: Propiedad + id_subdis desde sesión (NO confiar en POST)
   Estandarizado a: LUGA | SUBDISTRIBUIDOR
========================================================= */
$isSubdisAdmin = ($ROL === 'Subdis_Admin');
$propiedad     = $isSubdisAdmin ? 'Subdistribuidor' : 'Luga';
$id_subdis     = $isSubdisAdmin ? (int)($_SESSION['id_subdis'] ?? 0) : null;

if ($isSubdisAdmin && (!$id_subdis || $id_subdis <= 0)) {
  http_response_code(403);
  die("Acceso inválido: falta id_subdis en sesión.");
}

/* =========================================================
   Helpers
========================================================= */
function str_lim($s, $len){ return substr(trim((string)$s), 0, $len); }

function is_valid_date($s){
  if (!$s) return false;
  $d = DateTime::createFromFormat('Y-m-d', $s);
  return $d && $d->format('Y-m-d') === $s;
}
function add_days($base, $days){
  $d = DateTime::createFromFormat('Y-m-d', $base);
  if (!$d) return null;
  $d->modify('+' . (int)$days . ' days');
  return $d->format('Y-m-d');
}
function diff_days($from, $to){
  $df = DateTime::createFromFormat('Y-m-d', $from);
  $dt = DateTime::createFromFormat('Y-m-d', $to);
  if (!$df || !$dt) return null;
  return (int)$df->diff($dt)->format('%r%a');
}

/* =========================================================
   Encabezado (POST)
========================================================= */
$id_proveedor      = (int)($_POST['id_proveedor'] ?? 0);
$num_factura       = str_lim($_POST['num_factura'] ?? '', 80);
$id_sucursal_post  = (int)($_POST['id_sucursal'] ?? 0);
$fecha_factura     = $_POST['fecha_factura'] ?? date('Y-m-d');
$fecha_venc        = $_POST['fecha_vencimiento'] ?? null;
$condicion_pago    = ($_POST['condicion_pago'] ?? 'Contado') === 'Crédito' ? 'Crédito' : 'Contado';
$dias_vencimiento  = (isset($_POST['dias_vencimiento']) && $_POST['dias_vencimiento'] !== '') ? (int)$_POST['dias_vencimiento'] : null;
$notas             = str_lim($_POST['notas'] ?? '', 250);

/* =========================================================
   ✅ NUEVO: Sucursal destino controlada
   - Subdis_admin: SIEMPRE su sucursal de sesión
   - Otros: usa lo que venga del POST
========================================================= */
$id_sucursal = $isSubdisAdmin ? $ID_SUC_SES : $id_sucursal_post;

/* =========================================================
   Validaciones mínimas
========================================================= */
if ($id_proveedor<=0 || $num_factura==='' || $id_sucursal<=0) {
  http_response_code(422);
  die("Parámetros inválidos.");
}
if (!is_valid_date($fecha_factura)) $fecha_factura = date('Y-m-d');

/* =========================================================
   Lógica de vencimiento
========================================================= */
if ($condicion_pago === 'Contado') {
  $fecha_venc = $fecha_factura;
  $dias_vencimiento = 0;
} else {
  if ($dias_vencimiento !== null) {
    if ($dias_vencimiento < 0) $dias_vencimiento = 0;
    $fv = add_days($fecha_factura, $dias_vencimiento);
    $fecha_venc = $fv ?: $fecha_factura;
  } elseif ($fecha_venc && is_valid_date($fecha_venc)) {
    $d = diff_days($fecha_factura, $fecha_venc);
    if ($d !== null && $d >= 0) {
      $dias_vencimiento = $d;
    } else {
      $dias_vencimiento = 0;
      $fecha_venc = $fecha_factura;
    }
  } else {
    $dias_vencimiento = 0;
    $fecha_venc = $fecha_factura;
  }
}

/* =========================================================
   Anti duplicados (factura por proveedor)
   ✅ NUEVO: separar por propiedad/id_subdis
========================================================= */
if ($isSubdisAdmin) {
  $dupQ = $conn->prepare("
    SELECT id
    FROM compras
    WHERE id_proveedor=? AND num_factura=?
      AND propiedad='Subdistribuidor' AND id_subdis=?
    LIMIT 1
  ");
  $dupQ->bind_param("isi", $id_proveedor, $num_factura, $id_subdis);
} else {
  $dupQ = $conn->prepare("
    SELECT id
    FROM compras
    WHERE id_proveedor=? AND num_factura=?
      AND propiedad='Luga'
    LIMIT 1
  ");
  $dupQ->bind_param("is", $id_proveedor, $num_factura);
}

$dupQ->execute();
$dupQ->store_result();
if ($dupQ->num_rows > 0) {
  $dupQ->close();
  http_response_code(409);
  die("Esta factura ya existe para el proveedor seleccionado (en este propietario).");
}
$dupQ->close();

/* =========================================================
   Detalle (indexados por fila)
========================================================= */
$id_modelo   = $_POST['id_modelo'] ?? [];         // [idx] => id
$color       = $_POST['color'] ?? [];             // [idx] => str
$ram         = $_POST['ram'] ?? [];               // [idx] => str
$capacidad   = $_POST['capacidad'] ?? [];         // [idx] => str
$cantidad    = $_POST['cantidad'] ?? [];          // [idx] => int
$precio      = $_POST['precio_unitario'] ?? [];   // [idx] => float (sin IVA)
$iva_pct     = $_POST['iva_porcentaje'] ?? [];    // [idx] => float
$requiereMap = $_POST['requiere_imei'] ?? [];     // [idx] => "0" | "1"

// Descuento por renglón (modales)
$costo_dto     = $_POST['costo_dto']     ?? [];   // [idx] => float | '' (nullable)
$costo_dto_iva = $_POST['costo_dto_iva'] ?? [];   // [idx] => float | '' (nullable)

if (empty($id_modelo) || !is_array($id_modelo)) {
  http_response_code(422);
  die("Debes incluir al menos un renglón.");
}

/* =========================================================
   Otros cargos (opcional)
========================================================= */
$extra_desc            = $_POST['extra_desc']            ?? []; // [i] => str
$extra_monto           = $_POST['extra_monto']           ?? []; // [i] => float (base sin IVA)
$extra_iva_porcentaje  = $_POST['extra_iva_porcentaje']  ?? []; // [i] => float

$subtotal = 0.0; $iva = 0.0; $total = 0.0;
$rows = [];

/* =========================================================
   Snapshot del catálogo (incluye tipo para Accesorio)
========================================================= */
$stCat = $conn->prepare("
  SELECT marca, modelo, codigo_producto, UPPER(COALESCE(tipo_producto,'')) AS tipo_producto
  FROM catalogo_modelos
  WHERE id=? AND activo=1
");

/* =========================================================
   Construcción de renglones (modelos) y cálculo de totales
========================================================= */
foreach ($id_modelo as $idx => $idmRaw) {
  $idm = (int)$idmRaw;
  if ($idm <= 0) continue;

  $stCat->bind_param("i", $idm);
  $stCat->execute();
  $stCat->bind_result($marca, $modelo, $codigoCat, $tipoProd);
  $ok = $stCat->fetch();
  $stCat->free_result();
  if (!$ok) continue;

  $col = str_lim($color[$idx] ?? '', 40);
  $ramv= str_lim($ram[$idx] ?? '', 50);
  $cap = str_lim($capacidad[$idx] ?? '', 40);
  $qty = max(0, (int)($cantidad[$idx] ?? 0));
  $pu  = max(0, (float)($precio[$idx] ?? 0));     // sin IVA
  $ivp = max(0, (float)($iva_pct[$idx] ?? 0));    // %
  $req = ((int)($requiereMap[$idx] ?? 1) === 1) ? 1 : 0;

  if ($col === '') $col = '—';
  if ($cap === '') $cap = '—';
  if ($ramv === '') $ramv = '—';

  // Accesorios: por defecto sin IMEI, pero respeta si usuario marcó que sí requiere
  $isAcc = ($tipoProd === 'ACCESORIO');
  if ($isAcc && $req !== 1) $req = 0;

  if ($marca === '' || $modelo === '' || $qty <= 0 || $pu <= 0) continue;

  // Normalización de DTOs (por unidad)
  $dto    = (isset($costo_dto[$idx])     && $costo_dto[$idx]     !== '') ? (float)$costo_dto[$idx]     : null;
  $dtoIva = (isset($costo_dto_iva[$idx]) && $costo_dto_iva[$idx] !== '') ? (float)$costo_dto_iva[$idx] : null;

  if ($dto !== null && ($dtoIva === null || $dtoIva <= 0)) {
    $dtoIva = round($dto * (1 + ($ivp/100)), 2);
  } elseif (($dto === null || $dto <= 0) && $dtoIva !== null && $dtoIva > 0) {
    $dto = round($dtoIva / (1 + ($ivp/100)), 2);
  }

  // Cálculos estándar del renglón
  $rsub = round($qty * $pu, 2);
  $riva = round($rsub * ($ivp/100.0), 2);
  $rtot = round($rsub + $riva, 2);

  $subtotal = round($subtotal + $rsub, 2);
  $iva      = round($iva + $riva, 2);
  $total    = round($total + $rtot, 2);

  $rows[] = [
    'id_modelo'       => $idm,
    'marca'           => $marca,
    'modelo'          => $modelo,
    'color'           => $col,
    'ram'             => $ramv,
    'capacidad'       => $cap,
    'cantidad'        => $qty,
    'precio_unitario' => $pu,
    'iva_porcentaje'  => $ivp,
    'subtotal'        => $rsub,
    'iva'             => $riva,
    'total'           => $rtot,
    'requiere_imei'   => $req,
    'codigo_producto' => $codigoCat,
    'tipo_producto'   => $tipoProd,
    'costo_dto'       => $dto,
    'costo_dto_iva'   => $dtoIva
  ];
}
$stCat->close();

if (empty($rows)) {
  http_response_code(422);
  die("Debes incluir al menos un renglón válido.");
}

/* =========================================================
   Calcular extras y sumarlos a los totales
========================================================= */
$extraSub = 0.0; $extraIVA = 0.0;
if (!empty($extra_desc) && is_array($extra_desc)) {
  foreach ($extra_desc as $i => $descRaw) {
    $desc  = str_lim($descRaw, 200);
    $monto = isset($extra_monto[$i]) ? (float)$extra_monto[$i] : 0.0;
    $ivaP  = isset($extra_iva_porcentaje[$i]) ? (float)$extra_iva_porcentaje[$i] : 0.0;
    if ($desc === '' || $monto <= 0) continue;

    $extraSub = round($extraSub + $monto, 2);
    $extraIVA = round($extraIVA + ($monto * ($ivaP/100.0)), 2);
  }
}

$subtotal = round($subtotal + $extraSub, 2);
$iva      = round($iva + $extraIVA, 2);
$total    = round($subtotal + $iva, 2);

/* =========================================================
   Transacción
========================================================= */
$conn->begin_transaction();

try {
  /* -------------------------
     Encabezado (estatus por defecto Pendiente)
     ✅ incluye propiedad e id_subdis
  ------------------------- */
  $sqlC = "INSERT INTO compras
            (num_factura, id_proveedor, id_sucursal, propiedad, id_subdis,
             fecha_factura, fecha_vencimiento, condicion_pago, dias_vencimiento,
             subtotal, iva, total, estatus, notas, creado_por)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'Pendiente',?,?)";

  $stmtC = $conn->prepare($sqlC);
  if (!$stmtC) throw new Exception("Prepare compras: " . $conn->error);

  // Nota: bind_param no soporta NULL real en "i" tan fácil si no lo haces variable.
  // Aquí mandamos NULL cuando no aplique (para LUGA)
  $idSubBind = $id_subdis; // null|int

  $stmtC->bind_param(
    'siisisssidddsi',
    $num_factura,      // s
    $id_proveedor,     // i
    $id_sucursal,      // i
    $propiedad,        // s
    $idSubBind,        // i  (puede ser NULL)
    $fecha_factura,    // s
    $fecha_venc,       // s
    $condicion_pago,   // s
    $dias_vencimiento, // i
    $subtotal,         // d
    $iva,              // d
    $total,            // d
    $notas,            // s
    $ID_USUARIO        // i
  );

  if (!$stmtC->execute()) throw new Exception("Insert compras: " . $stmtC->error);
  $id_compra = (int)$stmtC->insert_id;
  $stmtC->close();

  /* -------------------------
     Detalle (incluye RAM y DTOs)
  ------------------------- */
  $sqlD = "INSERT INTO compras_detalle
            (id_compra, id_modelo, marca, modelo, color, ram, capacidad, requiere_imei, descripcion,
             cantidad, precio_unitario, iva_porcentaje, subtotal, iva, total, costo_dto, costo_dto_iva)
           VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)";

  $stmtD = $conn->prepare($sqlD);
  if (!$stmtD) throw new Exception("Prepare detalle: " . $conn->error);

  $stmtD_types = 'iisssssiiddddddd';

  foreach ($rows as $r) {
    $dto    = $r['costo_dto'];     // null|float
    $dtoIva = $r['costo_dto_iva']; // null|float

    $stmtD->bind_param(
      $stmtD_types,
      $id_compra,
      $r['id_modelo'],
      $r['marca'],
      $r['modelo'],
      $r['color'],
      $r['ram'],
      $r['capacidad'],
      $r['requiere_imei'],
      $r['cantidad'],
      $r['precio_unitario'],
      $r['iva_porcentaje'],
      $r['subtotal'],
      $r['iva'],
      $r['total'],
      $dto,
      $dtoIva
    );

    if (!$stmtD->execute()) throw new Exception("Insert detalle: " . $stmtD->error);
  }
  $stmtD->close();

  /* -------------------------
     Otros cargos (si existe tabla compras_cargos)
  ------------------------- */
  $hasCargosTbl = $conn->query("SHOW TABLES LIKE 'compras_cargos'");
  if ($hasCargosTbl && $hasCargosTbl->num_rows) {
    if (!empty($extra_desc) && is_array($extra_desc)) {
      $sqlX = "INSERT INTO compras_cargos
                (id_compra, descripcion, monto, iva_porcentaje, iva_monto, total, afecta_costo)
               VALUES (?,?,?,?,?,?,?)";

      $stmtX = $conn->prepare($sqlX);
      if (!$stmtX) throw new Exception("Prepare cargos: " . $conn->error);

      foreach ($extra_desc as $i => $descRaw) {
        $desc  = str_lim($descRaw, 200);
        $monto = isset($extra_monto[$i]) ? (float)$extra_monto[$i] : 0.0;
        $ivaP  = isset($extra_iva_porcentaje[$i]) ? (float)$extra_iva_porcentaje[$i] : 0.0;
        if ($desc === '' || $monto <= 0) continue;

        $ivaMonto = round($monto * ($ivaP/100.0), 2);
        $totCargo = round($monto + $ivaMonto, 2);
        $afecta   = 0;

        $stmtX->bind_param("isddddi", $id_compra, $desc, $monto, $ivaP, $ivaMonto, $totCargo, $afecta);
        if (!$stmtX->execute()) throw new Exception("Insert cargos: " . $stmtX->error);
      }
      $stmtX->close();
    }
  }

  /* -------------------------
     Pago contado opcional
  ------------------------- */
  $registrarPago = (($_POST['registrar_pago'] ?? '0') === '1');
  if ($registrarPago && $condicion_pago === 'Contado') {
    $pago_monto  = isset($_POST['pago_monto']) ? (float)$_POST['pago_monto'] : 0.0;
    $pago_metodo = str_lim($_POST['pago_metodo'] ?? '', 40);
    $pago_ref    = str_lim($_POST['pago_referencia'] ?? '', 120);
    $pago_fecha  = $_POST['pago_fecha'] ?? $fecha_factura;
    $pago_notas  = str_lim($_POST['pago_nota'] ?? '', 1000);

    if (!is_valid_date($pago_fecha)) $pago_fecha = $fecha_factura;
    if ($pago_monto < 0) $pago_monto = 0.0;

    $sqlP = "INSERT INTO compras_pagos
             (id_compra, fecha_pago, monto, metodo_pago, referencia, notas)
             VALUES (?,?,?,?,?,?)";

    $stP = $conn->prepare($sqlP);
    if (!$stP) throw new Exception('Prepare pago: ' . $conn->error);

    $stP->bind_param("isdsss", $id_compra, $pago_fecha, $pago_monto, $pago_metodo, $pago_ref, $pago_notas);
    if (!$stP->execute()) throw new Exception('Insert pago: ' . $stP->error);
    $stP->close();

    // Marcar como Pagada si cubre el total
    if ($pago_monto >= $total) {
      $upd = $conn->prepare("UPDATE compras SET estatus='Pagada' WHERE id=?");
      $upd->bind_param("i", $id_compra);
      $upd->execute();
      $upd->close();
    }
  }

  $conn->commit();
  header("Location: compras_ver.php?id=" . $id_compra);
  exit();

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo "Error al guardar la compra: " . $e->getMessage();
}
