<?php
// compras_ingreso.php
// Ingreso de unidades a inventario por renglón
// - Equipos (requiere IMEI): 1 producto + 1 inventario por pieza; ingresos con cantidad=1
// - Accesorios (sin IMEI): UPSERT por sucursal → reusar producto/inventario y sumar cantidad
//
// Copia atributos del catálogo a productos (nombre_comercial, descripcion, compania,
// financiera, fecha_lanzamiento, tipo_producto, gama, ciclo_vida, abc, operador, resurtible, subtipo)
//
// NUEVO (Subdis):
// - Candado de acceso por compra (propiedad / id_subdis).
// - Inserta productos.propiedad e productos.id_subdis para cada producto ingresado (si existen columnas).
// - En accesorios sin IMEI, el "reusar producto" filtra por propiedad/id_subdis para evitar mezcla.
// - ✅ inventario.propiedad usa ENUM: 'LUGA' | 'SUBDISTRIBUIDOR' (exacto). Se mapea desde compras.propiedad.
//
// NUEVO (UX escáner):
// - Switch "Requiere IMEI2" (aplica a CUALQUIER requiere_imei=1) para ocultar/ignorar IMEI2.
// - Enter del escáner SOLO avanza al siguiente campo; nunca enfoca submit.
// - En el último campo se queda ahí (selecciona texto) para evitar submit accidental.

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__.'/db.php';

/* ============================
   Helpers de metadata (tablas/columnas)
============================ */
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

/* ============================
   ✅ Normaliza propiedad de compras -> valores exactos de inventario
   compras: 'Luga' / 'Subdistribuidor' (o variantes)
   inventario: 'LUGA' / 'SUBDISTRIBUIDOR'
============================ */
function norm_prop_inv(string $v): string {
  $x = mb_strtolower(trim((string)$v), 'UTF-8');

  if ($x === '' || $x === 'luga' || $x === 'luga ph' || $x === 'propio' || $x === 'luga propio') return 'LUGA';
  if ($x === 'subdistribuidor' || $x === 'subdis' || $x === 'sub-distribuidor' || $x === 'sub distribuidor') return 'SUBDISTRIBUIDOR';

  $u = strtoupper($x);
  if ($u === 'LUGA' || $u === 'SUBDISTRIBUIDOR') return $u;

  return 'LUGA';
}

/* ============================
   Mini API: validación AJAX de IMEI (SOLO equipos numéricos)
============================ */
if (isset($_GET['action']) && $_GET['action'] === 'check_imei') {
  header('Content-Type: application/json; charset=utf-8');
  $imei = preg_replace('/\D+/', '', (string)($_GET['imei'] ?? ''));
  $resp = ['ok'=>false, 'msg'=>'', 'exists'=>false, 'field'=>null];

  if ($imei === '' || !preg_match('/^\d{15}$/', $imei)) {
    $resp['msg'] = 'Formato inválido: se requieren 15 dígitos.';
    echo json_encode($resp); exit;
  }

  // Luhn
  $luhn_ok = (function($s){
    $s = preg_replace('/\D+/', '', $s);
    if (strlen($s) !== 15) return false;
    $sum = 0;
    for ($i=0; $i<15; $i++) {
      $d = (int)$s[$i];
      if (($i % 2) === 1) { $d *= 2; if ($d > 9) $d -= 9; }
      $sum += $d;
    }
    return ($sum % 10) === 0;
  })($imei);

  if (!$luhn_ok) {
    $resp['msg'] = 'IMEI inválido (Luhn).';
    echo json_encode($resp); exit;
  }

  // Buscar en productos (IMEI globalmente único)
  $sql = "SELECT CASE WHEN imei1 = ? THEN 'imei1' WHEN imei2 = ? THEN 'imei2' ELSE NULL END AS campo
          FROM productos WHERE imei1 = ? OR imei2 = ? LIMIT 1";
  if ($st = $conn->prepare($sql)) {
    $st->bind_param("ssss", $imei, $imei, $imei, $imei);
    $st->execute();
    $res = $st->get_result();
    if ($row = $res->fetch_assoc()) {
      $resp['exists'] = true;
      $resp['field']  = $row['campo'] ?: 'desconocido';
      $resp['ok']     = true;
      $resp['msg']    = 'Duplicado en BD (productos.'.($resp['field']).').';
      echo json_encode($resp); exit;
    }
    $st->close();
  }

  $resp['ok'] = true;
  $resp['msg'] = 'Disponible.';
  echo json_encode($resp); exit;
}

/* ============================
   Parámetros
============================ */
$detalleId = (int)($_GET['detalle'] ?? 0);
$compraId  = (int)($_GET['compra'] ?? 0);
if ($detalleId<=0 || $compraId<=0) die("Parámetros inválidos.");

/* ============================
   Helpers
============================ */
function esc($v){ return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function parse_money($s) {
  $s = trim((string)$s);
  if ($s === '') return null;
  if (preg_match('/^\d{1,3}(\.\d{3})*,\d+$/', $s)) { // 1.234,56
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  } else { // 1,234.56 o 1234.56
    $s = str_replace(',', '', $s);
  }
  return is_numeric($s) ? round((float)$s, 2) : null;
}

/** Sugerir precio de lista: inventario (mismo código) > catálogo > último por modelo > costo + IVA */
function sugerirPrecioLista(mysqli $conn, ?string $codigoProd, string $marca, string $modelo, string $ram, string $capacidad, float $cBaseIva, ?float $precioCat) {
  if ($codigoProd) {
    $qInv = $conn->prepare("
      SELECT p.precio_lista
      FROM inventario i
      INNER JOIN productos p ON p.id = i.id_producto
      WHERE p.codigo_producto = ?
        AND TRIM(i.estatus) IN ('Disponible','En tránsito')
        AND p.precio_lista IS NOT NULL AND p.precio_lista > 0
      ORDER BY p.id DESC
      LIMIT 1
    ");
    $qInv->bind_param("s", $codigoProd);
    $qInv->execute(); $qInv->bind_result($plInv);
    if ($qInv->fetch()) { $qInv->close(); return ['precio'=>(float)$plInv, 'fuente'=>'inventario vigente (mismo código)']; }
    $qInv->close();
  }

  if ($precioCat !== null && $precioCat > 0) {
    return ['precio'=>(float)$precioCat, 'fuente'=>'catálogo de modelos'];
  }

  $q2 = $conn->prepare("SELECT precio_lista FROM productos
                        WHERE marca=? AND modelo=? AND ram=? AND capacidad=? AND precio_lista IS NOT NULL AND precio_lista>0
                        ORDER BY id DESC LIMIT 1");
  $q2->bind_param("ssss", $marca, $modelo, $ram, $capacidad);
  $q2->execute(); $q2->bind_result($pl2);
  if ($q2->fetch()) { $q2->close(); return ['precio'=>(float)$pl2, 'fuente'=>'último por modelo (RAM/cap)']; }
  $q2->close();

  return ['precio'=>$cBaseIva, 'fuente'=>'costo + IVA'];
}

/** Último subtipo usado (auto) */
function ultimoSubtipo(mysqli $conn, ?string $codigoProd, string $marca, string $modelo, string $ram, string $capacidad) {
  if ($codigoProd) {
    $q = $conn->prepare("SELECT subtipo FROM productos
                         WHERE codigo_producto=? AND subtipo IS NOT NULL AND subtipo<>'' ORDER BY id DESC LIMIT 1");
    $q->bind_param("s", $codigoProd);
    $q->execute(); $q->bind_result($st);
    if ($q->fetch()) { $q->close(); return ['subtipo'=>$st, 'fuente'=>'por código']; }
    $q->close();
  }
  $q2 = $conn->prepare("SELECT subtipo FROM productos
                        WHERE marca=? AND modelo=? AND ram=? AND capacidad=? AND subtipo IS NOT NULL AND subtipo<>'' ORDER BY id DESC LIMIT 1");
  $q2->bind_param("ssss", $marca, $modelo, $ram, $capacidad);
  $q2->execute(); $q2->bind_result($st2);
  if ($q2->fetch()) { $q2->close(); return ['subtipo'=>$st2, 'fuente'=>'por modelo (RAM/cap)']; }
  $q2->close();
  return ['subtipo'=>null, 'fuente'=>null];
}

/* ============================
   Luhn (para equipos)
============================ */
if (!function_exists('luhn_ok')) {
  function luhn_ok(string $s): bool {
    $s = preg_replace('/\D+/', '', $s);
    if (strlen($s) !== 15) return false;
    $sum = 0;
    for ($i=0; $i<15; $i++) {
      $d = (int)$s[$i];
      if (($i % 2) === 1) { $d *= 2; if ($d > 9) $d -= 9; }
      $sum += $d;
    }
    return ($sum % 10) === 0;
  }
}

/* ============================
   Seguridad / Dueño (Luga vs Subdis)
============================ */
$ROL = $_SESSION['rol'] ?? 'Ejecutivo';
$isSubdisAdmin = ($ROL === 'Subdis_Admin');
$ID_SUBDIS_SES = $isSubdisAdmin ? (int)($_SESSION['id_subdis'] ?? 0) : 0;

if ($isSubdisAdmin && $ID_SUBDIS_SES <= 0) {
  http_response_code(403);
  die("Acceso inválido: falta id_subdis en sesión.");
}

/* ============================
   Consultas base
============================ */
// Encabezado de compra (incluye propiedad/id_subdis)
$enc = $conn->query("
  SELECT c.*, s.nombre AS sucursal_nombre, p.nombre AS proveedor_nombre
  FROM compras c
  INNER JOIN sucursales s ON s.id=c.id_sucursal
  LEFT JOIN proveedores p ON p.id=c.id_proveedor
  WHERE c.id={$compraId}
")->fetch_assoc();

if (!$enc) die("Compra no encontrada.");

// Normalizar propiedad compra para candado y mapeo inventario
$propCompraRaw = (string)($enc['propiedad'] ?? 'Luga'); // en compras: "Luga" / "Subdistribuidor"
$propCompraInv = norm_prop_inv($propCompraRaw);         // en inventario: "LUGA" / "SUBDISTRIBUIDOR"
$idSubCompra   = isset($enc['id_subdis']) ? (int)$enc['id_subdis'] : 0;

// Candado por propietario
if ($isSubdisAdmin) {
  if ($propCompraInv !== 'SUBDISTRIBUIDOR' || $idSubCompra !== $ID_SUBDIS_SES) {
    http_response_code(403);
    die("No tienes permiso para ingresar inventario en esta compra.");
  }
} else {
  if ($propCompraInv !== 'LUGA') {
    http_response_code(403);
    die("No tienes permiso para ingresar inventario en esta compra.");
  }
}

// Owner que se aplicará a productos
$owner_propiedad = ($propCompraInv === 'SUBDISTRIBUIDOR') ? 'Subdistribuidor' : 'Luga';
$owner_id_subdis = ($propCompraInv === 'SUBDISTRIBUIDOR') ? $idSubCompra : null;

// Detectar tabla ingresos plural/singular
$ingTable = null;
if (table_exists($conn, 'compras_detalle_ingresos')) {
  $ingTable = 'compras_detalle_ingresos';
} elseif (table_exists($conn, 'compras_detalle_ingreso')) {
  $ingTable = 'compras_detalle_ingreso';
}
if (!$ingTable) die("No existe tabla de ingresos (compras_detalle_ingresos).");

// Detectar columna cantidad en ingresos
$hasCantCol = column_exists($conn, $ingTable, 'cantidad');
$sumExpr    = $hasCantCol ? "COALESCE(SUM(x.cantidad),0)" : "COUNT(*)";

// Detalle + ingresadas
$det = $conn->query("
  SELECT d.*,
         (SELECT {$sumExpr} FROM {$ingTable} x WHERE x.id_detalle = d.id) AS ingresadas
  FROM compras_detalle d
  WHERE d.id={$detalleId} AND d.id_compra={$compraId}
")->fetch_assoc();

if (!$det) die("Detalle no encontrado.");

$pendientes      = max(0, (int)$det['cantidad'] - (int)$det['ingresadas']);
$requiereImei    = (int)$det['requiere_imei'] === 1;
$proveedorCompra = trim((string)($enc['proveedor_nombre'] ?? ''));
if ($proveedorCompra !== '') { $proveedorCompra = mb_substr($proveedorCompra, 0, 120, 'UTF-8'); }

/* ============================
   Precálculos por renglón
============================ */
$codigoCat = null;
$cat = [
  'codigo_producto'=>null,'nombre_comercial'=>null,'descripcion'=>null,'compania'=>null,'financiera'=>null,
  'fecha_lanzamiento'=>null,'precio_lista'=>null,'tipo_producto'=>null,'gama'=>null,'ciclo_vida'=>null,
  'abc'=>null,'operador'=>null,'resurtible'=>null,'subtipo'=>null
];

if (!empty($det['id_modelo'])) {
  $stm = $conn->prepare("
    SELECT codigo_producto, nombre_comercial, descripcion, compania, financiera,
           fecha_lanzamiento, precio_lista, tipo_producto, gama, ciclo_vida, abc, operador, resurtible,
           subtipo
    FROM catalogo_modelos WHERE id=?
  ");
  $stm->bind_param("i", $det['id_modelo']);
  $stm->execute();
  $stm->bind_result(
    $cat['codigo_producto'], $cat['nombre_comercial'], $cat['descripcion'], $cat['compania'], $cat['financiera'],
    $cat['fecha_lanzamiento'], $cat['precio_lista'], $cat['tipo_producto'], $cat['gama'], $cat['ciclo_vida'],
    $cat['abc'], $cat['operador'], $cat['resurtible'],
    $cat['subtipo']
  );
  if ($stm->fetch()) { $codigoCat = $cat['codigo_producto']; }
  $stm->close();
}

$tipoProdCat        = strtolower(trim((string)($cat['tipo_producto'] ?? '')));
$esAccesorioConImei = ($tipoProdCat === 'accesorio');

// Costos del detalle (base)
$ivaPct      = (float)$det['iva_porcentaje'];
$costo       = (float)$det['precio_unitario'];
$costoConIva = round($costo * (1 + ($ivaPct/100)), 2);

// Descuentos del detalle
$costoDto    = array_key_exists('costo_dto', $det)     && $det['costo_dto']     !== null ? (float)$det['costo_dto']     : null;
$costoDtoIva = array_key_exists('costo_dto_iva', $det) && $det['costo_dto_iva'] !== null ? (float)$det['costo_dto_iva'] : null;

// Datos del detalle
$marcaDet  = (string)$det['marca'];
$modeloDet = (string)$det['modelo'];
$ramDet    = (string)($det['ram'] ?? '');
$capDet    = (string)$det['capacidad'];
$colorDet  = (string)$det['color'];

// Sugerencias precio
$precioCat = isset($cat['precio_lista']) && $cat['precio_lista'] !== null ? (float)$cat['precio_lista'] : null;
$baseIvaParaSugerencia = ($costoDtoIva !== null && $costoDtoIva > 0) ? $costoDtoIva : $costoConIva;
$sugerencia      = sugerirPrecioLista($conn, $codigoCat, $marcaDet, $modeloDet, $ramDet, $capDet, $baseIvaParaSugerencia, $precioCat);
$precioSugerido  = $sugerencia['precio'];
$fuenteSugerido  = $sugerencia['fuente'];

// Subtipo (auto)
$ultimoST    = ultimoSubtipo($conn, $codigoCat, $marcaDet, $modeloDet, $ramDet, $capDet);
$subtipoForm = $ultimoST['subtipo'] ?? ($cat['subtipo'] ?? null);

// Para repoblar inputs si hubo error
$errorMsg         = "";
$precioListaForm  = number_format($precioSugerido, 2, '.', '');
$oldImei1         = [];
$oldImei2         = [];
$oldCantSinImei   = $pendientes;

// Defaults del switch (por UX): encendido por defecto
$defaultUsaImei2 = true;

// ===== Detectar si productos tiene propiedad/id_subdis =====
$prodHasProp = table_exists($conn, 'productos') && column_exists($conn, 'productos', 'propiedad');
$prodHasSub  = table_exists($conn, 'productos') && column_exists($conn, 'productos', 'id_subdis');

// ===== Detectar si inventario tiene propiedad/id_subdis =====
$invHasProp = table_exists($conn, 'inventario') && column_exists($conn, 'inventario', 'propiedad');
$invHasSub  = table_exists($conn, 'inventario') && column_exists($conn, 'inventario', 'id_subdis');

// Valores EXACTOS para inventario.propiedad
$inv_propiedad = ($propCompraInv === 'SUBDISTRIBUIDOR') ? 'SUBDISTRIBUIDOR' : 'LUGA';
$inv_id_subdis = ($inv_propiedad === 'SUBDISTRIBUIDOR') ? (int)$owner_id_subdis : null;

/* ============================
   POST: guardar ingresos
============================ */
if ($_SERVER['REQUEST_METHOD']==='POST') {

  // Asegurar columna 'cantidad' en ingresos
  if (!$hasCantCol) {
    $conn->query("ALTER TABLE {$ingTable} ADD COLUMN cantidad INT NULL AFTER id_producto");
    $hasCantCol = true;
  }

  // Precio de lista por renglón
  $precioListaForm      = trim($_POST['precio_lista'] ?? '');
  $precioListaCapturado = parse_money($precioListaForm);
  if ($precioListaCapturado === null || $precioListaCapturado <= 0) {
    $errorMsg = "Precio de lista inválido. Usa números, ejemplo: 3999.00";
  }

  if ($requiereImei) {

    // ✅ Switch IMEI2: aplica para cualquier requiere_imei=1 (equipos o accesorios con serie)
    $usaImei2 = isset($_POST['usa_imei2']) ? 1 : 0;

    $n = max(0, (int)($_POST['n'] ?? 0));
    if ($n <= 0) { header("Location: compras_ver.php?id=".$compraId); exit(); }
    if ($n > $pendientes) $n = $pendientes;

    // Guardar para repintar si hay error
    for ($i=0; $i<$n; $i++) {
      if ($esAccesorioConImei) {
        $oldImei1[$i] = trim((string)($_POST['imei1'][$i] ?? ''));
        $oldImei2[$i] = $usaImei2 ? trim((string)($_POST['imei2'][$i] ?? '')) : '';
      } else {
        $oldImei1[$i] = preg_replace('/\D+/', '', (string)($_POST['imei1'][$i] ?? ''));
        $oldImei2[$i] = $usaImei2 ? preg_replace('/\D+/', '', (string)($_POST['imei2'][$i] ?? '')) : '';
      }
    }

    // Duplicados en formulario (considera IMEI2 solo si usaImei2)
    if ($errorMsg === "") {
      $seen     = [];
      $dupsForm = [];

      for ($i=0; $i<$n; $i++) {
        // imei1 siempre
        $raw1 = trim((string)($_POST['imei1'][$i] ?? ''));
        $val1 = $esAccesorioConImei ? strtoupper($raw1) : preg_replace('/\D+/', '', $raw1);
        if ($val1 !== '') {
          if (!isset($seen[$val1])) $seen[$val1] = [];
          $seen[$val1][] = $i+1;
        }

        // imei2 solo si switch ON
        if ($usaImei2) {
          $raw2 = trim((string)($_POST['imei2'][$i] ?? ''));
          $val2 = $esAccesorioConImei ? strtoupper($raw2) : preg_replace('/\D+/', '', $raw2);
          if ($val2 !== '') {
            if (!isset($seen[$val2])) $seen[$val2] = [];
            $seen[$val2][] = $i+1;
          }
        }
      }

      foreach ($seen as $val => $rowsIx) {
        if (count($rowsIx) > 1) $dupsForm[$val] = $rowsIx;
      }
      if (!empty($dupsForm)) {
        $msg = "Se detectaron IMEI/series duplicados en el formulario:\n";
        foreach ($dupsForm as $val => $rowsIx) { $msg .= " - $val repetido en filas ".implode(', ', $rowsIx)."\n"; }
        $errorMsg = nl2br(esc($msg));
      }
    }

    // Validación formato / Luhn / duplicados en BD
    if ($errorMsg === "") {
      for ($i=0; $i<$n && $errorMsg === ""; $i++) {

        // IMEI1 requerido
        $raw1 = trim((string)($_POST['imei1'][$i] ?? ''));
        $val1 = $esAccesorioConImei ? strtoupper($raw1) : preg_replace('/\D+/', '', $raw1);
        if ($val1 === '') { $errorMsg = "IMEI1 / Serie requerido en la fila ".($i+1)."."; break; }

        // Si es equipo numérico: validar 15 + Luhn
        if (!$esAccesorioConImei) {
          if (!preg_match('/^\d{15}$/', $val1)) { $errorMsg = "IMEI1 inválido en la fila ".($i+1)." (15 dígitos)."; break; }
          if (!luhn_ok($val1)) { $errorMsg = "IMEI1 inválido (Luhn) en la fila ".($i+1)."."; break; }
        }

        // Duplicado en BD para IMEI1 o Serie (si la BD lo guarda en imei1/imei2)
        $st = $conn->prepare("SELECT COUNT(*) c FROM productos WHERE imei1=? OR imei2=?");
        $st->bind_param("ss", $val1, $val1);
        $st->execute(); $st->bind_result($cdup1); $st->fetch(); $st->close();
        if ($cdup1 > 0) { $errorMsg = "IMEI1 / Serie duplicado en BD en la fila ".($i+1).": $val1"; break; }

        // IMEI2 solo si switch ON
        if ($usaImei2) {
          $raw2 = trim((string)($_POST['imei2'][$i] ?? ''));
          $val2 = $esAccesorioConImei ? strtoupper($raw2) : preg_replace('/\D+/', '', $raw2);

          if ($val2 !== '') {
            if (!$esAccesorioConImei) {
              if (!preg_match('/^\d{15}$/', $val2)) { $errorMsg = "IMEI2 inválido en la fila ".($i+1)." (15 dígitos)."; break; }
              if (!luhn_ok($val2)) { $errorMsg = "IMEI2 inválido (Luhn) en la fila ".($i+1)."."; break; }
            }

            $st2 = $conn->prepare("SELECT COUNT(*) c FROM productos WHERE imei1=? OR imei2=?");
            $st2->bind_param("ss", $val2, $val2);
            $st2->execute(); $st2->bind_result($cdup2); $st2->fetch(); $st2->close();
            if ($cdup2 > 0) { $errorMsg = "IMEI2 / Serie 2 duplicado en BD en la fila ".($i+1).": $val2"; break; }
          }
        }
      }
    }

    if ($errorMsg === "") {
      $conn->begin_transaction();
      try {
        for ($i=0; $i<$n; $i++) {

          // ✅ construir valores finales
          if ($esAccesorioConImei) {
            $imei1Raw = trim((string)($_POST['imei1'][$i] ?? ''));
            $imei1    = strtoupper($imei1Raw);

            if ($usaImei2) {
              $imei2Raw = trim((string)($_POST['imei2'][$i] ?? ''));
              $imei2    = ($imei2Raw === '') ? null : strtoupper($imei2Raw);
            } else {
              $imei2 = null;
            }

          } else {
            $imei1 = preg_replace('/\D+/', '', (string)($_POST['imei1'][$i] ?? ''));

            if ($usaImei2) {
              $imei2 = preg_replace('/\D+/', '', (string)($_POST['imei2'][$i] ?? ''));
              if ($imei2 === '') $imei2 = null;
            } else {
              $imei2 = null;
            }
          }

          // Variables catálogo → pasar SIEMPRE como variables para bind_param
          $bp_codigo_producto = $codigoCat;
          $bp_marca           = $marcaDet;
          $bp_modelo          = $modeloDet;
          $bp_color           = $colorDet;
          $bp_ram             = $ramDet;
          $bp_capacidad       = $capDet;
          $bp_imei1           = $imei1;
          $bp_imei2           = $imei2;
          $bp_costo           = $costo;
          $bp_costo_iva       = $costoConIva;
          $bp_costo_dto       = $costoDto;
          $bp_costo_dto_iva   = $costoDtoIva;
          $bp_proveedor       = ($proveedorCompra !== '') ? $proveedorCompra : null;
          $bp_precio_lista    = $precioListaCapturado;

          $bp_desc            = $cat['descripcion']        ?? null;
          $bp_nombre_com      = $cat['nombre_comercial']   ?? null;
          $bp_compania        = $cat['compania']           ?? null;
          $bp_financiera      = $cat['financiera']         ?? null;
          $bp_fecha_lanz      = $cat['fecha_lanzamiento']  ?? null;
          $bp_tipo_prod       = $cat['tipo_producto']      ?? null;
          $bp_subtipo         = $subtipoForm               ?? null;
          $bp_gama            = $cat['gama']               ?? null;
          $bp_ciclo_vida      = $cat['ciclo_vida']         ?? null;
          $bp_abc             = $cat['abc']                ?? null;
          $bp_operador        = $cat['operador']           ?? null;
          $bp_resurtible      = $cat['resurtible']         ?? null;

          $bp_propiedad       = $owner_propiedad;   // 'Luga' | 'Subdistribuidor'
          $bp_id_subdis       = $owner_id_subdis;   // null si Luga

          // INSERT productos (unidad) — dinámico según columnas
          if ($prodHasProp && $prodHasSub) {
            $sqlP = "INSERT INTO productos (
                propiedad, id_subdis,
                codigo_producto, marca, modelo, color, ram, capacidad,
                imei1, imei2, costo, costo_con_iva, costo_dto, costo_dto_iva,
                proveedor, precio_lista,
                descripcion, nombre_comercial, compania, financiera, fecha_lanzamiento,
                tipo_producto, subtipo, gama, ciclo_vida, abc, operador, resurtible
              ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmtP = $conn->prepare($sqlP);
            if (!$stmtP) { throw new Exception('Prepare productos (equipos/serie): '.$conn->error); }

            $stmtP->bind_param(
              "sissssssssddddsdssssssssssss",
              $bp_propiedad, $bp_id_subdis,
              $bp_codigo_producto, $bp_marca, $bp_modelo, $bp_color, $bp_ram, $bp_capacidad,
              $bp_imei1, $bp_imei2,
              $bp_costo, $bp_costo_iva, $bp_costo_dto, $bp_costo_dto_iva,
              $bp_proveedor, $bp_precio_lista,
              $bp_desc, $bp_nombre_com, $bp_compania, $bp_financiera, $bp_fecha_lanz,
              $bp_tipo_prod, $bp_subtipo, $bp_gama, $bp_ciclo_vida, $bp_abc, $bp_operador, $bp_resurtible
            );
          } elseif ($prodHasProp) {
            $sqlP = "INSERT INTO productos (
                propiedad,
                codigo_producto, marca, modelo, color, ram, capacidad,
                imei1, imei2, costo, costo_con_iva, costo_dto, costo_dto_iva,
                proveedor, precio_lista,
                descripcion, nombre_comercial, compania, financiera, fecha_lanzamiento,
                tipo_producto, subtipo, gama, ciclo_vida, abc, operador, resurtible
              ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmtP = $conn->prepare($sqlP);
            if (!$stmtP) { throw new Exception('Prepare productos (equipos/serie): '.$conn->error); }

            $stmtP->bind_param(
              "ssssssssssddddsdssssssssssss",
              $bp_propiedad,
              $bp_codigo_producto, $bp_marca, $bp_modelo, $bp_color, $bp_ram, $bp_capacidad,
              $bp_imei1, $bp_imei2,
              $bp_costo, $bp_costo_iva, $bp_costo_dto, $bp_costo_dto_iva,
              $bp_proveedor, $bp_precio_lista,
              $bp_desc, $bp_nombre_com, $bp_compania, $bp_financiera, $bp_fecha_lanz,
              $bp_tipo_prod, $bp_subtipo, $bp_gama, $bp_ciclo_vida, $bp_abc, $bp_operador, $bp_resurtible
            );
          } else {
            $sqlP = "INSERT INTO productos (
                codigo_producto, marca, modelo, color, ram, capacidad,
                imei1, imei2, costo, costo_con_iva, costo_dto, costo_dto_iva,
                proveedor, precio_lista,
                descripcion, nombre_comercial, compania, financiera, fecha_lanzamiento,
                tipo_producto, subtipo, gama, ciclo_vida, abc, operador, resurtible
              ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmtP = $conn->prepare($sqlP);
            if (!$stmtP) { throw new Exception('Prepare productos (equipos/serie): '.$conn->error); }

            $stmtP->bind_param(
              "ssssssssddddsdssssssssssss",
              $bp_codigo_producto, $bp_marca, $bp_modelo, $bp_color, $bp_ram, $bp_capacidad,
              $bp_imei1, $bp_imei2,
              $bp_costo, $bp_costo_iva, $bp_costo_dto, $bp_costo_dto_iva,
              $bp_proveedor, $bp_precio_lista,
              $bp_desc, $bp_nombre_com, $bp_compania, $bp_financiera, $bp_fecha_lanz,
              $bp_tipo_prod, $bp_subtipo, $bp_gama, $bp_ciclo_vida, $bp_abc, $bp_operador, $bp_resurtible
            );
          }

          $stmtP->execute();
          $idProducto = (int)$stmtP->insert_id;
          $stmtP->close();

          // inventario: una pieza
          $idSuc = (int)$enc['id_sucursal'];

          if ($invHasProp && $invHasSub) {
            $stmtI = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, cantidad, estatus, propiedad, id_subdis) VALUES (?, ?, 1, 'Disponible', ?, ?)");
            if (!$stmtI) throw new Exception("Prepare inventario: ".$conn->error);
            $tmpSub = $inv_id_subdis;
            $stmtI->bind_param("iisi", $idProducto, $idSuc, $inv_propiedad, $tmpSub);
          } elseif ($invHasProp) {
            $stmtI = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, cantidad, estatus, propiedad) VALUES (?, ?, 1, 'Disponible', ?)");
            if (!$stmtI) throw new Exception("Prepare inventario: ".$conn->error);
            $stmtI->bind_param("iis", $idProducto, $idSuc, $inv_propiedad);
          } else {
            $stmtI = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, cantidad, estatus) VALUES (?, ?, 1, 'Disponible')");
            if (!$stmtI) throw new Exception("Prepare inventario: ".$conn->error);
            $stmtI->bind_param("ii", $idProducto, $idSuc);
          }
          $stmtI->execute(); $stmtI->close();

          // ingreso vinculado (cantidad=1)
          $stmtR = $conn->prepare("INSERT INTO {$ingTable} (id_detalle, imei1, imei2, id_producto, cantidad) VALUES (?,?,?,?,1)");
          if (!$stmtR) throw new Exception("Prepare ingreso: ".$conn->error);
          $stmtR->bind_param("issi", $detalleId, $imei1, $imei2, $idProducto);
          $stmtR->execute(); $stmtR->close();
        }

        $conn->commit();
        header("Location: compras_ver.php?id=".$compraId);
        exit();

      } catch (Exception $e) {
        $conn->rollback();
        $errorMsg = $e->getMessage();
      }
    }

  } else {
    /* ======== ACCESORIOS (sin IMEI): UPSERT por sucursal ======== */
    $cant = max(0, (int)($_POST['cant_sin_imei'] ?? 0));
    if ($cant <= 0) {
      $errorMsg = "Indica la cantidad a ingresar (debe ser mayor a 0).";
    } elseif ($cant > $pendientes) {
      $errorMsg = "No puedes ingresar más de las pendientes ({$pendientes}).";
    }
    $oldCantSinImei = $cant;

    if ($errorMsg === "") {
      $conn->begin_transaction();
      try {
        $idProducto = null;

        $buscaPorCodigo = !empty($codigoCat);

        $condOwner = "";
        $typesOwner = "";
        $paramsOwner = [];

        if ($prodHasProp) {
          $condOwner .= " AND propiedad = ? ";
          $typesOwner .= "s";
          $paramsOwner[] = $owner_propiedad;
        }
        if ($prodHasSub) {
          if ($owner_propiedad === 'Subdistribuidor') {
            $condOwner .= " AND id_subdis = ? ";
            $typesOwner .= "i";
            $paramsOwner[] = (int)$owner_id_subdis;
          } else {
            $condOwner .= " AND (id_subdis IS NULL OR id_subdis=0) ";
          }
        }

        if ($buscaPorCodigo) {
          $sqlFind = "SELECT id FROM productos
                      WHERE codigo_producto = ?
                        AND (imei1 IS NULL OR imei1='') AND (imei2 IS NULL OR imei2='')
                      {$condOwner}
                      ORDER BY id ASC LIMIT 1";
          $q = $conn->prepare($sqlFind);

          $types = "s" . $typesOwner;
          $args = array_merge([$codigoCat], $paramsOwner);
          $q->bind_param($types, ...$args);

          $q->execute(); $r = $q->get_result();
          if ($row = $r->fetch_assoc()) { $idProducto = (int)$row['id']; }
          $q->close();
        }

        if ($idProducto === null) {
          $sqlFind = "SELECT id FROM productos
                      WHERE marca=? AND modelo=? AND color=? AND ram=? AND capacidad=?
                        AND (imei1 IS NULL OR imei1='') AND (imei2 IS NULL OR imei2='')
                      {$condOwner}
                      ORDER BY id ASC LIMIT 1";
          $q = $conn->prepare($sqlFind);

          $types = "sssss" . $typesOwner;
          $args  = array_merge([$marcaDet, $modeloDet, $colorDet, $ramDet, $capDet], $paramsOwner);
          $q->bind_param($types, ...$args);

          $q->execute(); $r = $q->get_result();
          if ($row = $r->fetch_assoc()) { $idProducto = (int)$row['id']; }
          $q->close();
        }

        if ($idProducto === null) {
          $bp_codigo_producto = $codigoCat;
          $bp_marca           = $marcaDet;
          $bp_modelo          = $modeloDet;
          $bp_color           = $colorDet;
          $bp_ram             = $ramDet;
          $bp_capacidad       = $capDet;
          $bp_imei1           = null;
          $bp_imei2           = null;
          $bp_costo           = $costo;
          $bp_costo_iva       = $costoConIva;
          $bp_costo_dto       = $costoDto;
          $bp_costo_dto_iva   = $costoDtoIva;
          $bp_proveedor       = ($proveedorCompra !== '') ? $proveedorCompra : null;
          $bp_precio_lista    = $precioListaCapturado;

          $bp_desc            = $cat['descripcion']        ?? null;
          $bp_nombre_com      = $cat['nombre_comercial']   ?? null;
          $bp_compania        = $cat['compania']           ?? null;
          $bp_financiera      = $cat['financiera']         ?? null;
          $bp_fecha_lanz      = $cat['fecha_lanzamiento']  ?? null;
          $bp_tipo_prod       = ($cat['tipo_producto'] ?? null) ?: 'Accesorio';
          $bp_subtipo         = $subtipoForm               ?? null;
          $bp_gama            = $cat['gama']               ?? null;
          $bp_ciclo_vida      = $cat['ciclo_vida']         ?? null;
          $bp_abc             = $cat['abc']                ?? null;
          $bp_operador        = $cat['operador']           ?? null;
          $bp_resurtible      = $cat['resurtible']         ?? null;

          $bp_propiedad       = $owner_propiedad;
          $bp_id_subdis       = $owner_id_subdis;

          if ($prodHasProp && $prodHasSub) {
            $sqlP = "INSERT INTO productos (
                propiedad, id_subdis,
                codigo_producto, marca, modelo, color, ram, capacidad,
                imei1, imei2, costo, costo_con_iva, costo_dto, costo_dto_iva,
                proveedor, precio_lista,
                descripcion, nombre_comercial, compania, financiera, fecha_lanzamiento,
                tipo_producto, subtipo, gama, ciclo_vida, abc, operador, resurtible
              ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmtP = $conn->prepare($sqlP);
            if (!$stmtP) { throw new Exception('Prepare productos (accesorios sin IMEI): '.$conn->error); }

            $stmtP->bind_param(
              "sissssssssddddsdssssssssssss",
              $bp_propiedad, $bp_id_subdis,
              $bp_codigo_producto, $bp_marca, $bp_modelo, $bp_color, $bp_ram, $bp_capacidad,
              $bp_imei1, $bp_imei2,
              $bp_costo, $bp_costo_iva, $bp_costo_dto, $bp_costo_dto_iva,
              $bp_proveedor, $bp_precio_lista,
              $bp_desc, $bp_nombre_com, $bp_compania, $bp_financiera, $bp_fecha_lanz,
              $bp_tipo_prod, $bp_subtipo, $bp_gama, $bp_ciclo_vida, $bp_abc, $bp_operador, $bp_resurtible
            );
          } elseif ($prodHasProp) {
            $sqlP = "INSERT INTO productos (
                propiedad,
                codigo_producto, marca, modelo, color, ram, capacidad,
                imei1, imei2, costo, costo_con_iva, costo_dto, costo_dto_iva,
                proveedor, precio_lista,
                descripcion, nombre_comercial, compania, financiera, fecha_lanzamiento,
                tipo_producto, subtipo, gama, ciclo_vida, abc, operador, resurtible
              ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmtP = $conn->prepare($sqlP);
            if (!$stmtP) { throw new Exception('Prepare productos (accesorios sin IMEI): '.$conn->error); }

            $stmtP->bind_param(
              "ssssssssssddddsdssssssssssss",
              $bp_propiedad,
              $bp_codigo_producto, $bp_marca, $bp_modelo, $bp_color, $bp_ram, $bp_capacidad,
              $bp_imei1, $bp_imei2,
              $bp_costo, $bp_costo_iva, $bp_costo_dto, $bp_costo_dto_iva,
              $bp_proveedor, $bp_precio_lista,
              $bp_desc, $bp_nombre_com, $bp_compania, $bp_financiera, $bp_fecha_lanz,
              $bp_tipo_prod, $bp_subtipo, $bp_gama, $bp_ciclo_vida, $bp_abc, $bp_operador, $bp_resurtible
            );
          } else {
            $sqlP = "INSERT INTO productos (
                codigo_producto, marca, modelo, color, ram, capacidad,
                imei1, imei2, costo, costo_con_iva, costo_dto, costo_dto_iva,
                proveedor, precio_lista,
                descripcion, nombre_comercial, compania, financiera, fecha_lanzamiento,
                tipo_producto, subtipo, gama, ciclo_vida, abc, operador, resurtible
              ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            $stmtP = $conn->prepare($sqlP);
            if (!$stmtP) { throw new Exception('Prepare productos (accesorios sin IMEI): '.$conn->error); }

            $stmtP->bind_param(
              "ssssssssddddsdssssssssssss",
              $bp_codigo_producto, $bp_marca, $bp_modelo, $bp_color, $bp_ram, $bp_capacidad,
              $bp_imei1, $bp_imei2,
              $bp_costo, $bp_costo_iva, $bp_costo_dto, $bp_costo_dto_iva,
              $bp_proveedor, $bp_precio_lista,
              $bp_desc, $bp_nombre_com, $bp_compania, $bp_financiera, $bp_fecha_lanz,
              $bp_tipo_prod, $bp_subtipo, $bp_gama, $bp_ciclo_vida, $bp_abc, $bp_operador, $bp_resurtible
            );
          }

          $stmtP->execute();
          $idProducto = (int)$stmtP->insert_id;
          $stmtP->close();

        } else {
          if ($precioListaCapturado > 0) {
            $up = $conn->prepare("UPDATE productos SET precio_lista=? WHERE id=?");
            $up->bind_param("di", $precioListaCapturado, $idProducto);
            $up->execute(); $up->close();
          }
        }

        $idInventario = null;
        $idSuc = (int)$enc['id_sucursal'];

        $condInvOwner = "";
        $typesInv = "ii";
        $argsInv = [$idProducto, $idSuc];

        if ($invHasProp) {
          $condInvOwner .= " AND propiedad = ? ";
          $typesInv .= "s";
          $argsInv[] = $inv_propiedad;
        }
        if ($invHasSub) {
          if ($inv_propiedad === 'SUBDISTRIBUIDOR') {
            $condInvOwner .= " AND id_subdis = ? ";
            $typesInv .= "i";
            $argsInv[] = (int)$inv_id_subdis;
          } else {
            $condInvOwner .= " AND (id_subdis IS NULL OR id_subdis=0) ";
          }
        }

        $sqlInvFind = "SELECT id, cantidad FROM inventario
                       WHERE id_producto=? AND id_sucursal=? AND TRIM(estatus)='Disponible'
                       {$condInvOwner}
                       LIMIT 1";
        $qInv = $conn->prepare($sqlInvFind);
        $qInv->bind_param($typesInv, ...$argsInv);
        $qInv->execute(); $rInv = $qInv->get_result();

        if ($inv = $rInv->fetch_assoc()) {
          $idInventario  = (int)$inv['id'];
          $nuevaCantidad = (int)$inv['cantidad'] + $cant;
          $upInv = $conn->prepare("UPDATE inventario SET cantidad=? WHERE id=?");
          $upInv->bind_param("ii", $nuevaCantidad, $idInventario);
          $upInv->execute(); $upInv->close();
        } else {
          if ($invHasProp && $invHasSub) {
            $insInv = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, cantidad, estatus, propiedad, id_subdis) VALUES (?,?,?,'Disponible',?,?)");
            $tmpSub = $inv_id_subdis;
            $insInv->bind_param("iiisi", $idProducto, $idSuc, $cant, $inv_propiedad, $tmpSub);
          } elseif ($invHasProp) {
            $insInv = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, cantidad, estatus, propiedad) VALUES (?,?,?,'Disponible',?)");
            $insInv->bind_param("iiis", $idProducto, $idSuc, $cant, $inv_propiedad);
          } else {
            $insInv = $conn->prepare("INSERT INTO inventario (id_producto, id_sucursal, cantidad, estatus) VALUES (?,?,?,'Disponible')");
            $insInv->bind_param("iii", $idProducto, $idSuc, $cant);
          }
          $insInv->execute();
          $idInventario = (int)$insInv->insert_id;
          $insInv->close();
        }
        $qInv->close();

        $null = null;
        $stmtR = $conn->prepare("INSERT INTO {$ingTable} (id_detalle, imei1, imei2, id_producto, cantidad) VALUES (?,?,?,?,?)");
        $stmtR->bind_param("issii", $detalleId, $null, $null, $idProducto, $cant);
        $stmtR->execute(); $stmtR->close();

        $conn->commit();
        header("Location: compras_ver.php?id=".$compraId);
        exit();

      } catch (Exception $e) {
        $conn->rollback();
        $errorMsg = $e->getMessage();
      }
    }
  }
}

// ===== HTML =====
require_once __DIR__.'/navbar.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="container my-4">
  <h4>Ingreso a inventario</h4>
  <p class="text-muted">
    <strong>Factura:</strong> <?= esc($enc['num_factura']) ?> ·
    <strong>Sucursal destino:</strong> <?= esc($enc['sucursal_nombre']) ?><br>
    <strong>Modelo:</strong>
      <?= esc($marcaDet.' '.$modeloDet) ?> ·
      <?= $ramDet!=='' ? '<strong>RAM:</strong> '.esc($ramDet).' · ' : '' ?>
      <strong>Capacidad:</strong> <?= esc($capDet) ?> ·
      <strong>Color:</strong> <?= esc($colorDet) ?> ·
      <strong>Req. IMEI:</strong> <?= $requiereImei ? 'Sí' : 'No (Accesorio)' ?><br>
    <strong>Proveedor (compra):</strong> <?= esc($proveedorCompra ?: '—') ?>
  </p>

  <?php if (!empty($cat['codigo_producto']) || !empty($cat['nombre_comercial'])): ?>
    <div class="alert alert-secondary py-2">
      <?php if(!empty($cat['codigo_producto'])): ?>
        <span class="me-3"><strong>Código:</strong> <?= esc($cat['codigo_producto']) ?></span>
      <?php endif; ?>
      <?php if(!empty($cat['nombre_comercial'])): ?>
        <span class="me-3"><strong>Nombre comercial:</strong> <?= esc($cat['nombre_comercial']) ?></span>
      <?php endif; ?>
      <?php if(!empty($cat['compania'])): ?>
        <span class="me-3"><strong>Compañía:</strong> <?= esc($cat['compania']) ?></span>
      <?php endif; ?>
      <?php if(!empty($cat['financiera'])): ?>
        <span class="me-3"><strong>Financiera:</strong> <?= esc($cat['financiera']) ?></span>
      <?php endif; ?>
      <?php if(!empty($cat['tipo_producto'])): ?>
        <span class="me-3"><strong>Tipo:</strong> <?= esc($cat['tipo_producto']) ?></span>
      <?php endif; ?>
      <?php if(!empty($cat['gama'])): ?>
        <span class="me-3"><strong>Gama:</strong> <?= esc($cat['gama']) ?></span>
      <?php endif; ?>
      <?php if(!empty($cat['ciclo_vida'])): ?>
        <span class="me-3"><strong>Ciclo de vida:</strong> <?= esc($cat['ciclo_vida']) ?></span>
      <?php endif; ?>
      <?php if(!empty($cat['abc'])): ?>
        <span class="me-3"><strong>ABC:</strong> <?= esc($cat['abc']) ?></span>
      <?php endif; ?>
      <?php if(!empty($cat['operador'])): ?>
        <span class="me-3"><strong>Operador:</strong> <?= esc($cat['operador']) ?></span>
      <?php endif; ?>
      <?php if(!empty($cat['resurtible'])): ?>
        <span class="me-3"><strong>Resurtible:</strong> <?= esc($cat['resurtible']) ?></span>
      <?php endif; ?>
      <?php if(!empty($cat['subtipo'])): ?>
        <span class="me-3"><strong>Subtipo (catálogo):</strong> <?= esc($cat['subtipo']) ?></span>
      <?php endif; ?>
      <?php if(!empty($cat['fecha_lanzamiento'])): ?>
        <span class="me-3"><strong>Lanzamiento:</strong> <?= esc($cat['fecha_lanzamiento']) ?></span>
      <?php endif; ?>
      <?php if(!empty($cat['descripcion'])): ?>
        <div class="small text-muted mt-1"><?= esc($cat['descripcion']) ?></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($errorMsg)): ?>
    <div class="alert alert-danger">
      <?= $errorMsg ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-body">
      <p><strong>Cantidad total:</strong> <?= (int)$det['cantidad'] ?> ·
         <strong>Ingresadas:</strong> <?= (int)$det['ingresadas'] ?> ·
         <strong>Pendientes:</strong> <?= $pendientes ?></p>

      <?php if ($pendientes <= 0): ?>
        <div class="alert alert-success">Este renglón ya está completamente ingresado.</div>
      <?php else: ?>

        <form id="formIngreso" method="POST" autocomplete="off" novalidate>
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label">Subtipo (asignación automática)</label>
              <input type="text" class="form-control" value="<?= esc($subtipoForm ?? '—') ?>" disabled>
              <small class="text-muted">
                <?= $ultimoST['subtipo'] ? 'Origen: '.esc($ultimoST['fuente'] ?? 'historial') : 'Sin historial, se usa catálogo si existe.' ?>
              </small>
            </div>

            <div class="col-md-4">
              <label class="form-label">Precio de lista (por modelo)</label>
              <input
                type="text"
                name="precio_lista"
                class="form-control"
                inputmode="decimal"
                placeholder="Ej. 3999.00"
                value="<?= esc($precioListaForm) ?>"
                required
                autocomplete="off"
              >
              <small class="text-muted">
                Sugerido: $<?= number_format((float)$precioSugerido, 2) ?> (<?= esc($fuenteSugerido) ?>).
                Se aplicará a todas las unidades de este renglón.
              </small>
            </div>

            <?php if ($requiereImei): ?>
              <div class="col-md-4">
                <label class="form-label d-block">Modo de captura</label>
                <div class="form-check form-switch mt-1">
                  <input class="form-check-input" type="checkbox" role="switch" id="usaImei2Switch" name="usa_imei2" value="1" <?= $defaultUsaImei2 ? 'checked' : '' ?>>
                  <label class="form-check-label" for="usaImei2Switch">
                    Requiere IMEI2 / Serie 2
                  </label>
                </div>
                <small class="text-muted">Apágalo si ese producto solo lleva 1 IMEI/serie (oculta IMEI2 y acelera el escaneo).</small>
              </div>
            <?php endif; ?>
          </div>

          <?php if ($requiereImei): ?>
            <input type="hidden" name="n" value="<?= $pendientes ?>">

            <style>
              .imei2-hidden .imei2-col { display: none !important; }
              .imei2-hidden .imei2-cell { display: none !important; }
            </style>

            <div class="table-responsive">
              <table class="table table-sm align-middle" id="tablaImei">
                <thead>
                  <tr>
                    <th>#</th>
                    <th style="min-width:220px">IMEI1 / Serie *</th>
                    <th class="imei2-col" style="min-width:220px">IMEI2 / Serie 2 (opcional)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php for ($i=0;$i<$pendientes;$i++): ?>
                    <tr>
                      <td><?= $i+1 ?></td>
                      <td>
                        <input
                          id="imei1-<?= $i ?>"
                          data-index="<?= $i ?>"
                          class="form-control imei-input imei1"
                          name="imei1[]"
                          required
                          <?php if (!$esAccesorioConImei): ?>
                            inputmode="numeric"
                            minlength="15"
                            maxlength="15"
                            pattern="[0-9]{15}"
                            placeholder="15 dígitos"
                            title="Debe contener exactamente 15 dígitos"
                          <?php else: ?>
                            placeholder="Serie / código"
                            title="Captura la serie o código del accesorio (letras y números)"
                          <?php endif; ?>
                          autocomplete="off"
                          value="<?= esc($oldImei1[$i] ?? '') ?>"
                          <?= $i===0 ? 'autofocus' : '' ?>
                        >
                        <div class="invalid-feedback small">
                          <?php if (!$esAccesorioConImei): ?>
                            Corrige el IMEI (15 dígitos, Luhn).
                          <?php else: ?>
                            Corrige la serie o código del accesorio.
                          <?php endif; ?>
                        </div>
                        <div class="form-text text-danger d-none" id="dupmsg-imei1-<?= $i ?>"></div>
                      </td>
                      <td class="imei2-cell">
                        <input
                          id="imei2-<?= $i ?>"
                          data-index="<?= $i ?>"
                          class="form-control imei-input imei2"
                          name="imei2[]"
                          <?php if (!$esAccesorioConImei): ?>
                            inputmode="numeric"
                            minlength="15"
                            maxlength="15"
                            pattern="[0-9]{15}"
                            placeholder="15 dígitos (opcional)"
                            title="Si lo capturas, deben ser 15 dígitos"
                          <?php else: ?>
                            placeholder="Serie / código (opcional)"
                            title="Serie/código opcional"
                          <?php endif; ?>
                          autocomplete="off"
                          value="<?= esc($oldImei2[$i] ?? '') ?>"
                        >
                        <div class="invalid-feedback small">
                          <?php if (!$esAccesorioConImei): ?>
                            Corrige el IMEI (15 dígitos, Luhn) o déjalo vacío.
                          <?php else: ?>
                            Corrige el valor o déjalo vacío.
                          <?php endif; ?>
                        </div>
                        <div class="form-text text-danger d-none" id="dupmsg-imei2-<?= $i ?>"></div>
                      </td>
                    </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>

          <?php else: ?>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Cantidad a ingresar (sin IMEI)</label>
                <input type="number" name="cant_sin_imei" class="form-control" min="1" max="<?= $pendientes ?>"
                       value="<?= (int)$oldCantSinImei ?>" required>
                <small class="text-muted">Pendientes: <?= $pendientes ?>. Se reusará producto/inventario si existe; de lo contrario se crearán.</small>
              </div>
            </div>
          <?php endif; ?>

          <div class="text-end mt-3">
            <button id="btnSubmit" type="submit" class="btn btn-success">Ingresar a inventario</button>
            <a href="compras_ver.php?id=<?= (int)$compraId ?>" class="btn btn-outline-secondary">Cancelar</a>
          </div>
        </form>

      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($requiereImei): ?>
<script>
(function() {
  const form = document.getElementById('formIngreso');
  if (!form) return;

  const btnSubmit = document.getElementById('btnSubmit');
  const esAccesorioConImei = <?= $esAccesorioConImei ? 'true' : 'false' ?>;

  const switchImei2 = document.getElementById('usaImei2Switch');
  const tabla = document.getElementById('tablaImei');

  function usaImei2Actual(){
    if (!switchImei2) return true;
    return !!switchImei2.checked;
  }

  function aplicarModoImei2(){
    const usar = usaImei2Actual();
    if (tabla) tabla.classList.toggle('imei2-hidden', !usar);

    // deshabilitar IMEI2 para que NO se envíen y no entren a lógica de Enter/duplicados
    document.querySelectorAll('.imei-input.imei2').forEach(el=>{
      if (!usar) {
        el.value = '';
        el.setCustomValidity('');
        el.classList.remove('is-invalid','dup-bad');
        el.disabled = true;
      } else {
        el.disabled = false;
      }
    });

    // limpiar mensajes duplicado IMEI2 al apagar
    if (!usar) {
      document.querySelectorAll('[id^="dupmsg-imei2-"]').forEach(h=>{
        h.classList.add('d-none');
        h.textContent = '';
      });
    }

    // refrescar duplicates
    checkLocalDuplicates();
  }

  if (switchImei2){
    switchImei2.addEventListener('change', ()=>{
      aplicarModoImei2();
      const a = document.activeElement;
      if (a && a.classList && a.classList.contains('imei2') && a.disabled) {
        const idx = parseInt(a.dataset.index || '0', 10);
        const next = document.getElementById('imei1-' + (idx + 1)) || document.getElementById('imei1-' + idx) || document.getElementById('imei1-0');
        if (next) { next.focus(); next.select?.(); }
      }
    });
    aplicarModoImei2();
  }

  // Bloquear doble submit
  form.addEventListener('submit', (e)=>{
    if (form.dataset.busy === '1') { e.preventDefault(); e.stopPropagation(); return; }
    const anyDup = form.querySelector('.dup-bad');
    if (anyDup) { e.preventDefault(); alert('Hay IMEI/series duplicadas. Corrige los campos marcados en rojo.'); return; }
    form.dataset.busy = '1';
    if (btnSubmit){ btnSubmit.disabled = true; btnSubmit.textContent = 'Ingresando...'; }
  }, { capture: true });

  function debounce(fn, ms) { let t; return (...args)=>{ clearTimeout(t); t = setTimeout(()=>fn(...args), ms); }; }

  // Normalización/validación solo para IMEI numérico
  function normalize15(input) {
    const v = input.value.replace(/\D+/g, '').slice(0, 15);
    if (v !== input.value) input.value = v;
    return v;
  }
  function imeiLuhnOk(s){
    s = (s||'').replace(/\D+/g,'');
    if (s.length !== 15) return false;
    let sum = 0;
    for (let i=0;i<15;i++){
      let d = s.charCodeAt(i) - 48;
      if ((i % 2) === 1){ d *= 2; if (d > 9) d -= 9; }
      sum += d;
    }
    return (sum % 10) === 0;
  }

  function markDup(el, msg, isBad=true) {
    const id = el.id.replace(/^(.+)-(\d+)$/, (m,a,b)=>`${a}-${b}`);
    const help = document.getElementById('dupmsg-'+id);
    if (isBad) {
      el.classList.add('is-invalid', 'dup-bad');
      if (help){ help.classList.remove('d-none'); help.textContent = msg || 'Duplicado.'; }
    } else {
      el.classList.remove('dup-bad');
      if (!el.classList.contains('is-invalid')) el.classList.remove('is-invalid');
      if (help){ help.classList.add('d-none'); help.textContent = ''; }
    }
  }

  // Duplicados locales:
  // - siempre considera IMEI1/Serie1
  // - considera IMEI2/Serie2 solo si switch ON
  function checkLocalDuplicates() {
    const usarImei2 = usaImei2Actual();
    const inputs = Array.from(document.querySelectorAll('.imei-input')).filter(el=>!el.disabled);

    const map = new Map();

    inputs.forEach(el=>{
      if (el.classList.contains('imei2') && !usarImei2) return;

      let v = (el.value||'').trim();
      if (!v) return;

      if (!esAccesorioConImei) {
        v = v.replace(/\D+/g,'');
        if (v.length !== 15) return;
      } else {
        v = v.toUpperCase();
      }

      if (!map.has(v)) map.set(v, []);
      map.get(v).push(el);
    });

    // limpia flags previos solo en inputs activos
    inputs.forEach(el=> markDup(el, '', false));

    map.forEach((arr, val)=>{
      if (arr.length > 1) arr.forEach(el=> markDup(el, `Duplicado en formulario: ${val}`, true));
    });
  }

  const checkRemote = debounce(async (el)=>{
    // remoto solo para IMEI numérico
    if (esAccesorioConImei) return;
    const v = (el.value||'').replace(/\D+/g,'');
    if (v.length !== 15 || !imeiLuhnOk(v)) return;
    try {
      const url = `<?= esc(basename(__FILE__)) ?>?action=check_imei&imei=${encodeURIComponent(v)}`;
      const r = await fetch(url, { headers: { 'Accept': 'application/json' }});
      const data = await r.json();
      if (data && data.ok) {
        if (data.exists) markDup(el, `Duplicado en BD (${data.field}): ${v}`, true);
        else markDup(el, '', false);
      }
    } catch(e) {}
  }, 220);

  // listeners inputs
  const inputsAll = Array.from(document.querySelectorAll('.imei-input'));
  inputsAll.forEach((el)=>{
    el.addEventListener('input', ()=>{
      if (el.disabled) return;
      markDup(el, '', false);

      if (!esAccesorioConImei) {
        const v = normalize15(el);
        if (v.length === 15) {
          if (!imeiLuhnOk(v)) { el.classList.add('is-invalid'); el.setCustomValidity('IMEI inválido (Luhn).'); }
          else { el.classList.remove('is-invalid'); el.setCustomValidity(''); checkLocalDuplicates(); checkRemote(el); }
        } else { el.classList.remove('is-invalid'); el.setCustomValidity(''); checkLocalDuplicates(); }
      } else {
        // accesorio con serie: sin Luhn
        checkLocalDuplicates();
      }
    });

    el.addEventListener('blur', ()=>{
      if (el.disabled) return;
      if (!esAccesorioConImei) {
        const v = (el.value||'').replace(/\D+/g,'');
        if (v && v.length === 15) {
          if (!imeiLuhnOk(v)) { el.classList.add('is-invalid'); el.setCustomValidity('IMEI inválido (Luhn).'); }
          else { checkLocalDuplicates(); checkRemote(el); }
        }
      } else {
        checkLocalDuplicates();
      }
    });
  });

  // ✅ Enter del escáner: solo mover foco (sin submit)
  form.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    const t = e.target;

    // nunca permitir que Enter dispare submit
    if (t && (t.matches('button[type="submit"]') || t.id === 'btnSubmit')) {
      e.preventDefault();
      return;
    }

    if (!t.matches('.imei-input, input[name="precio_lista"]')) return;
    if (e.ctrlKey || e.metaKey) return;

    e.preventDefault();

    if (t.matches('input[name="precio_lista"]')) {
      const first = document.getElementById('imei1-0');
      if (first) { first.focus(); first.select?.(); }
      return;
    }

    const idx = parseInt(t.dataset.index || '0', 10);
    const usarImei2 = usaImei2Actual();

    if (t.classList.contains('imei1')) {
      if (usarImei2) {
        const next2 = document.getElementById('imei2-' + idx);
        if (next2 && !next2.disabled) { next2.focus(); next2.select?.(); return; }
      }
      const next1 = document.getElementById('imei1-' + (idx + 1));
      if (next1) { next1.focus(); next1.select?.(); return; }
      t.focus(); t.select?.(); return;
    }

    if (t.classList.contains('imei2')) {
      const next1 = document.getElementById('imei1-' + (idx + 1));
      if (next1) { next1.focus(); next1.select?.(); return; }
      t.focus(); t.select?.(); return;
    }
  });

  // Ctrl+Enter = enviar manualmente
  document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      if (form && form.dataset.busy !== '1') form.requestSubmit();
    }
  });

  // init
  checkLocalDuplicates();

})();
</script>
<?php endif; ?>