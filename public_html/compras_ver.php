<?php
// compras_ver.php — Vista de factura + pagos + cancelación segura (con separación Luga/Subdis)
session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';

// ============================
// Datos de sesión / roles
// ============================
$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);
$ROL        = $_SESSION['rol'] ?? 'Ejecutivo';

$isSubdisAdmin = ($ROL === 'Subdis_Admin');
$ID_SUBDIS     = $isSubdisAdmin ? (int)($_SESSION['id_subdis'] ?? 0) : 0;

// Roles admin-like (Luga)
$ADMIN_ROLES = ['Admin', 'Super', 'SuperAdmin', 'Logistica'];
$isAdminLike = in_array($ROL, $ADMIN_ROLES, true);

// Permisos de cancelación:
// - Luga: Admin-like
// - Subdis: subdis_admin (pero solo sus compras)
$canCancel = $isAdminLike || $isSubdisAdmin;

// ============================
// ID de compra
// ============================
$id = (int)($_POST['id_compra'] ?? ($_GET['id'] ?? 0));
if ($id <= 0) die("ID inválido.");

// ============================
// Helpers
// ============================
function table_exists(mysqli $conn, string $table): bool
{
  $t = $conn->real_escape_string($table);
  $q = $conn->query("SHOW TABLES LIKE '{$t}'");
  return $q && $q->num_rows > 0;
}
function column_exists(mysqli $conn, string $table, string $col): bool
{
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($col);
  $q = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
  return $q && $q->num_rows > 0;
}
function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ============================
// Seguridad: validar propiedad de la compra ANTES de cualquier acción
// ============================
// Traer solo lo necesario para validar acceso
$own = null;
$stOwn = $conn->prepare("SELECT id, propiedad, id_subdis FROM compras WHERE id=? LIMIT 1");
$stOwn->bind_param("i", $id);
$stOwn->execute();
$own = $stOwn->get_result()->fetch_assoc();
$stOwn->close();

if (!$own) {
  http_response_code(404);
  die("Compra no encontrada.");
}

$propCompra = (string)($own['propiedad'] ?? 'Luga');
$idSubCompra = isset($own['id_subdis']) ? (int)$own['id_subdis'] : 0;

// Candado de acceso
if ($isSubdisAdmin) {
  if ($ID_SUBDIS <= 0) {
    http_response_code(403);
    die("Acceso inválido: falta id_subdis en sesión.");
  }
  if ($propCompra !== 'Subdistribuidor' || $idSubCompra !== $ID_SUBDIS) {
    http_response_code(403);
    die("No tienes permiso para ver esta compra.");
  }
} else {
  // Luga: por defecto solo compras de Luga
  if ($propCompra !== 'Luga') {
    http_response_code(403);
    die("No tienes permiso para ver esta compra.");
  }
}

// ============================
// Agregador de ingresos (plural/singular + cantidad opcional)
// ============================
$ingTable = null;
if (table_exists($conn, 'compras_detalle_ingresos')) {
  $ingTable = 'compras_detalle_ingresos';
} elseif (table_exists($conn, 'compras_detalle_ingreso')) {
  $ingTable = 'compras_detalle_ingreso';
}

$hasCantIngreso = false;
if ($ingTable) {
  $hasCantIngreso = column_exists($conn, $ingTable, 'cantidad');
}

// Fragmento SQL para calcular "ingresadas"
if ($ingTable) {
  $ingAgg = $hasCantIngreso
    ? "COALESCE((SELECT COALESCE(SUM(x.cantidad),0) FROM {$ingTable} x WHERE x.id_detalle=d.id),0)"
    : "COALESCE((SELECT COUNT(*) FROM {$ingTable} x WHERE x.id_detalle=d.id),0)";
} else {
  // Si no existe tabla de ingresos, que no truene
  $ingAgg = "0";
}

// ============================
// POST: Agregar pago
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'agregar_pago') {
  // Seguridad extra: solo permitir a roles admin-like o subdis_admin (tu “admin del subdis”)
  if (!($isAdminLike || $isSubdisAdmin)) {
    $_SESSION['flash_error'] = "No tienes permisos para agregar pagos.";
    header("Location: compras_ver.php?id=" . $id);
    exit();
  }

  $fecha_pago  = $_POST['fecha_pago'] ?: date('Y-m-d');
  $monto       = (float)($_POST['monto'] ?? 0);
  $metodo      = substr(trim($_POST['metodo_pago'] ?? ''), 0, 40);
  $referencia  = substr(trim($_POST['referencia'] ?? ''), 0, 120);
  $notas       = substr(trim($_POST['notas'] ?? ''), 0, 1000);

  if ($monto > 0) {
    $stmt = $conn->prepare("INSERT INTO compras_pagos (id_compra, fecha_pago, monto, metodo_pago, referencia, notas) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param("isdsss", $id, $fecha_pago, $monto, $metodo, $referencia, $notas);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
      // Recalcular estatus si ya cubre total
      $resTot = $conn->prepare("SELECT total FROM compras WHERE id=?");
      $resTot->bind_param("i", $id);
      $resTot->execute();
      $rowTot = $resTot->get_result()->fetch_assoc();
      $resTot->close();
      $totalCompra = $rowTot ? (float)$rowTot['total'] : 0;

      $resPag = $conn->prepare("SELECT COALESCE(SUM(monto),0) AS pagado FROM compras_pagos WHERE id_compra=?");
      $resPag->bind_param("i", $id);
      $resPag->execute();
      $rowPag = $resPag->get_result()->fetch_assoc();
      $resPag->close();
      $pagado = $rowPag ? (float)$rowPag['pagado'] : 0;

      if ($pagado >= $totalCompra && $totalCompra > 0) {
        $u = $conn->prepare("UPDATE compras SET estatus='Pagada' WHERE id=?");
        $u->bind_param("i", $id);
        $u->execute();
        $u->close();
      }
    }
  }

  header("Location: compras_ver.php?id=" . $id);
  exit();
}

// ============================
// POST: Eliminar / cancelar compra
// ============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_compra') {
  if (!$canCancel) {
    $_SESSION['flash_error'] = "No tienes permisos para cancelar esta factura.";
    header("Location: compras_ver.php?id=" . $id);
    exit();
  }

  // Si es subdis_admin, solo puede cancelar compras subdis suyas (ya lo validamos arriba, pero repetimos por claridad)
  if ($isSubdisAdmin && ($propCompra !== 'Subdistribuidor' || $idSubCompra !== $ID_SUBDIS)) {
    $_SESSION['flash_error'] = "No tienes permisos para cancelar esta factura.";
    header("Location: compras_ver.php?id=" . $id);
    exit();
  }

  // Si es Luga admin-like, solo compras Luga (ya lo validamos arriba)
  if ($isAdminLike && $propCompra !== 'Luga') {
    $_SESSION['flash_error'] = "No tienes permisos para cancelar esta factura.";
    header("Location: compras_ver.php?id=" . $id);
    exit();
  }

  $password = $_POST['password_admin'] ?? '';

  // Traer hash de contraseña del usuario actual
  $stUser = $conn->prepare("SELECT password, rol FROM usuarios WHERE id = ?");
  $stUser->bind_param("i", $ID_USUARIO);
  $stUser->execute();
  $rowUser = $stUser->get_result()->fetch_assoc();
  $stUser->close();

  // Helper para soportar varios tipos de almacenamiento
  $okPassword = false;
  if ($rowUser && isset($rowUser['password'])) {
    $hash = (string)$rowUser['password'];

    if ($hash !== '') {
      // 1) password_hash (bcrypt/argon)
      if (password_verify($password, $hash)) {
        $okPassword = true;
      } else {
        // 2) MD5 viejo
        if (strlen($hash) === 32 && ctype_xdigit($hash) && hash_equals($hash, md5($password))) {
          $okPassword = true;
        }
        // 3) Texto plano
        if (!$okPassword && hash_equals($hash, $password)) {
          $okPassword = true;
        }
      }
    }
  }

  if (!$okPassword) {
    $_SESSION['flash_error'] = "Contraseña incorrecta. No se canceló la factura.";
    header("Location: compras_ver.php?id=" . $id);
    exit();
  }

  // Verificar rol para cancelar:
  // - Luga: admin-like
  // - Subdis: subdis_admin
  $rolReal = (string)($rowUser['rol'] ?? '');
  $tieneRolCancel = in_array($rolReal, $ADMIN_ROLES, true) || ($rolReal === 'subdis_admin');
  if (!$tieneRolCancel) {
    $_SESSION['flash_error'] = "Tu usuario no tiene rol para cancelar compras.";
    header("Location: compras_ver.php?id=" . $id);
    exit();
  }

  // 1) Verificar si hay inventario ya usado (estatus ≠ 'Disponible')
  $inventUsados = 0;
  if ($ingTable && table_exists($conn, 'inventario')) {
    $sqlCheck = "
      SELECT COUNT(*) AS usados
      FROM {$ingTable} cdi
      JOIN compras_detalle d   ON d.id = cdi.id_detalle
      JOIN inventario i        ON i.id_producto = cdi.id_producto
      WHERE d.id_compra = ?
        AND COALESCE(i.estatus,'Disponible') <> 'Disponible'
    ";
    $stChk = $conn->prepare($sqlCheck);
    $stChk->bind_param("i", $id);
    $stChk->execute();
    $rowChk = $stChk->get_result()->fetch_assoc();
    $stChk->close();
    $inventUsados = (int)($rowChk['usados'] ?? 0);
  }

  if ($inventUsados > 0) {
    $_SESSION['flash_error'] = "No se puede cancelar la factura porque uno o más equipos ya fueron vendidos o movidos de inventario.";
    header("Location: compras_ver.php?id=" . $id);
    exit();
  }

  // 2) Ejecutar reversa en transacción
  $conn->begin_transaction();
  try {
    // 2.1 Eliminar pagos
    $st = $conn->prepare("DELETE FROM compras_pagos WHERE id_compra = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $st->close();

    // 2.2 Revertir inventario (solo registros disponibles)
    if ($ingTable && table_exists($conn, 'inventario')) {
      // Accesorios con cantidad (restar)
      if ($hasCantIngreso && column_exists($conn, 'inventario', 'cantidad')) {
        $sqlUpdCant = "
          UPDATE inventario i
          JOIN {$ingTable} cdi ON i.id_producto = cdi.id_producto
          JOIN compras_detalle d ON d.id = cdi.id_detalle
          SET i.cantidad = GREATEST(
                0,
                COALESCE(i.cantidad, 0) - COALESCE(cdi.cantidad, 0)
              )
          WHERE d.id_compra = ?
            AND COALESCE(i.estatus,'Disponible') = 'Disponible'
            AND cdi.cantidad IS NOT NULL
        ";
        $stUC = $conn->prepare($sqlUpdCant);
        $stUC->bind_param("i", $id);
        $stUC->execute();
        $stUC->close();

        // Eliminar inventario con cantidad 0
        $sqlDelInvCant = "
          DELETE i FROM inventario i
          WHERE i.cantidad <= 0
            AND COALESCE(i.estatus,'Disponible') = 'Disponible'
        ";
        $conn->query($sqlDelInvCant);
      }

      // Equipos por pieza (sin cantidad / o cantidad=1)
      $sqlDelInv = "
        DELETE i
        FROM inventario i
        JOIN {$ingTable} cdi ON i.id_producto = cdi.id_producto
        JOIN compras_detalle d ON d.id = cdi.id_detalle
        WHERE d.id_compra = ?
          AND COALESCE(i.estatus,'Disponible') = 'Disponible'
      ";
      $stDI = $conn->prepare($sqlDelInv);
      $stDI->bind_param("i", $id);
      $stDI->execute();
      $stDI->close();
    }

    // 2.3 Eliminar productos (solo equipos con IMEI que ya no tengan inventario ni ventas)
    if ($ingTable && table_exists($conn, 'productos')) {
      $tieneDetalleVenta = table_exists($conn, 'detalle_venta');

      if ($tieneDetalleVenta) {
        $sqlDelProd = "
          DELETE p
          FROM productos p
          JOIN {$ingTable} cdi ON cdi.id_producto = p.id
          JOIN compras_detalle d ON d.id = cdi.id_detalle
          LEFT JOIN inventario i   ON i.id_producto = p.id
          LEFT JOIN detalle_venta dv ON dv.id_producto = p.id
          WHERE d.id_compra = ?
            AND COALESCE(d.requiere_imei,0) = 1
            AND i.id IS NULL
            AND dv.id IS NULL
        ";
      } else {
        $sqlDelProd = "
          DELETE p
          FROM productos p
          JOIN {$ingTable} cdi ON cdi.id_producto = p.id
          JOIN compras_detalle d ON d.id = cdi.id_detalle
          LEFT JOIN inventario i   ON i.id_producto = p.id
          WHERE d.id_compra = ?
            AND COALESCE(d.requiere_imei,0) = 1
            AND i.id IS NULL
        ";
      }

      $stDP = $conn->prepare($sqlDelProd);
      $stDP->bind_param("i", $id);
      $stDP->execute();
      $stDP->close();
    }

    // 2.4 Eliminar registro de ingresos
    if ($ingTable) {
      $sqlDelIng = "
        DELETE cdi
        FROM {$ingTable} cdi
        JOIN compras_detalle d ON d.id = cdi.id_detalle
        WHERE d.id_compra = ?
      ";
      $stDelIng = $conn->prepare($sqlDelIng);
      $stDelIng->bind_param("i", $id);
      $stDelIng->execute();
      $stDelIng->close();
    }

    // 2.5 Eliminar cargos
    if (table_exists($conn, 'compras_cargos')) {
      $st = $conn->prepare("DELETE FROM compras_cargos WHERE id_compra = ?");
      $st->bind_param("i", $id);
      $st->execute();
      $st->close();
    }

    // 2.6 Eliminar detalle
    $st = $conn->prepare("DELETE FROM compras_detalle WHERE id_compra = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $st->close();

    // 2.7 Eliminar encabezado de compra
    $st = $conn->prepare("DELETE FROM compras WHERE id = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $st->close();

    $conn->commit();

    $_SESSION['flash_success'] = "La factura y sus movimientos asociados se cancelaron correctamente.";
    header("Location: compras_resumen.php");
    exit();
  } catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['flash_error'] = "Error al cancelar la factura: " . $e->getMessage();
    header("Location: compras_ver.php?id=" . $id);
    exit();
  }
}

// ============================
// GET: Excel
// ============================
if (isset($_GET['excel'])) {

  // Encabezado
  $enc = null;
  if ($st = $conn->prepare("
      SELECT c.*, p.nombre AS proveedor, s.nombre AS sucursal
      FROM compras c
      INNER JOIN proveedores p ON p.id = c.id_proveedor
      INNER JOIN sucursales  s ON s.id = c.id_sucursal
      WHERE c.id = ?
  ")) {
    $st->bind_param("i", $id);
    $st->execute();
    $enc = $st->get_result()->fetch_assoc();
    $st->close();
  }
  if (!$enc) die("Compra no encontrada.");

  $hasDto    = column_exists($conn, 'compras_detalle', 'costo_dto');
  $hasDtoIva = column_exists($conn, 'compras_detalle', 'costo_dto_iva');

  // Detalle con "ingresadas" correcto
  $detRows = [];
  $sqlDet = "
    SELECT d.*,
           {$ingAgg} AS ingresadas
    FROM compras_detalle d
    WHERE d.id_compra = ?
    ORDER BY d.id ASC
  ";
  if ($st = $conn->prepare($sqlDet)) {
    $st->bind_param("i", $id);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) $detRows[] = $r;
    $st->close();
  }

  // Totales modelos y cargos
  $sumDet = $conn->prepare("SELECT COALESCE(SUM(subtotal),0) AS sub, COALESCE(SUM(iva),0) AS iva, COALESCE(SUM(total),0) AS tot FROM compras_detalle WHERE id_compra=?");
  $sumDet->bind_param("i", $id);
  $sumDet->execute();
  $rowDet = $sumDet->get_result()->fetch_assoc();
  $sumDet->close();

  $rowCar = ['sub'=>0,'iva'=>0,'tot'=>0];
  if (table_exists($conn, 'compras_cargos')) {
    $sumCar = $conn->prepare("SELECT COALESCE(SUM(monto),0) AS sub, COALESCE(SUM(iva_monto),0) AS iva, COALESCE(SUM(total),0) AS tot FROM compras_cargos WHERE id_compra=?");
    $sumCar->bind_param("i", $id);
    $sumCar->execute();
    $rowCar = $sumCar->get_result()->fetch_assoc();
    $sumCar->close();
  }

  // IMEIs/series (si existe)
  $imeiRows = [];
  $candDynamic = ['imei1', 'imei', 'imei2', 'serial', 'n_serie', 'lote', 'id_producto', 'creado_en', 'cantidad'];
  if ($ingTable) {
    $present = [];
    foreach ($candDynamic as $c) if (column_exists($conn, $ingTable, $c)) $present[] = $c;
    $selectImeis = "";
    foreach ($present as $c) $selectImeis .= ", i.`{$c}` AS `{$c}`";

    $sqlI = "
      SELECT i.id, i.id_detalle {$selectImeis},
             d.marca, d.modelo, d.color, d.ram, d.capacidad
      FROM {$ingTable} i
      JOIN compras_detalle d ON d.id=i.id_detalle
      WHERE d.id_compra={$id}
      ORDER BY i.id ASC
    ";
    $resI = $conn->query($sqlI);
    if ($resI) while ($x = $resI->fetch_assoc()) $imeiRows[] = $x;
  }

  // Headers Excel
  $num   = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($enc['num_factura'] ?? "compra_{$id}"));
  $fname = "factura_{$num}.xls";
  header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
  header("Content-Disposition: attachment; filename=\"{$fname}\"");
  header("Cache-Control: max-age=0, no-cache, no-store, must-revalidate");

  echo "<html><head><meta charset='UTF-8'><title>" . h($fname) . "</title><style>.text{mso-number-format:'\\@';}</style></head><body>";

  echo "<h3>Factura #" . h($enc['num_factura']) . "</h3>";
  echo "<table border='1' cellspacing='0' cellpadding='4'>";
  echo "<tr><th align='left'>Proveedor</th><td>" . h($enc['proveedor']) . "</td></tr>";
  echo "<tr><th align='left'>Sucursal destino</th><td>" . h($enc['sucursal']) . "</td></tr>";
  echo "<tr><th align='left'>Fecha factura</th><td>" . h($enc['fecha_factura']) . "</td></tr>";
  echo "<tr><th align='left'>Vencimiento</th><td>" . h($enc['fecha_vencimiento'] ?? '') . "</td></tr>";
  echo "<tr><th align='left'>Condición</th><td>" . h($enc['condicion_pago'] ?? '') . "</td></tr>";
  echo "<tr><th align='left'>Días vencimiento</th><td>" . (int)($enc['dias_vencimiento'] ?? 0) . "</td></tr>";
  echo "<tr><th align='left'>Estatus</th><td>" . h($enc['estatus'] ?? '') . "</td></tr>";
  echo "<tr><th align='left'>Total factura</th><td>" . number_format((float)$enc['total'], 2, '.', '') . "</td></tr>";
  echo "</table><br>";

  echo "<h3>Detalle de modelos</h3>";
  echo "<table border='1' cellspacing='0' cellpadding='4'>";
  echo "<tr>
          <th>Marca</th><th>Modelo</th><th>Color</th><th>RAM</th><th>Capacidad</th>
          <th>Req. IMEI</th><th>Cantidad</th><th>Ingresadas</th>
          <th>PrecioUnit</th>";
  if ($hasDto)    echo "<th>Costo Dto (s/IVA)</th>";
  if ($hasDtoIva) echo "<th>Costo Dto c/IVA</th>";
  echo "  <th>IVA%</th><th>Subtotal</th><th>IVA</th><th>Total</th>
        </tr>";

  foreach ($detRows as $r) {
    $tieneDto    = $hasDto    && isset($r['costo_dto'])     && $r['costo_dto']     !== null && (float)$r['costo_dto']     > 0;
    $tieneDtoIva = $hasDtoIva && isset($r['costo_dto_iva']) && $r['costo_dto_iva'] !== null && (float)$r['costo_dto_iva'] > 0;

    echo "<tr>";
    echo "<td>" . h($r['marca']) . "</td>";
    $modeloTxt = h($r['modelo']);
    if ($tieneDto || $tieneDtoIva) $modeloTxt .= " <span style='background:#0d6efd;color:#fff;padding:2px 6px;border-radius:10px;font-size:11px;'>Dto</span>";
    echo "<td>{$modeloTxt}</td>";
    echo "<td>" . h($r['color']) . "</td>";
    echo "<td>" . h($r['ram'] ?? '') . "</td>";
    echo "<td>" . h($r['capacidad']) . "</td>";
    echo "<td>" . (($r['requiere_imei'] ?? 0) ? 'Sí' : 'No') . "</td>";
    echo "<td>" . (int)$r['cantidad'] . "</td>";
    echo "<td>" . (int)$r['ingresadas'] . "</td>";
    echo "<td>" . number_format((float)$r['precio_unitario'], 2, '.', '') . "</td>";
    if ($hasDto)    echo "<td>" . ($tieneDto ? number_format((float)$r['costo_dto'], 2, '.', '') : '') . "</td>";
    if ($hasDtoIva) echo "<td>" . ($tieneDtoIva ? number_format((float)$r['costo_dto_iva'], 2, '.', '') : '') . "</td>";
    echo "<td>" . number_format((float)$r['iva_porcentaje'], 2, '.', '') . "</td>";
    echo "<td>" . number_format((float)$r['subtotal'], 2, '.', '') . "</td>";
    echo "<td>" . number_format((float)$r['iva'], 2, '.', '') . "</td>";
    echo "<td>" . number_format((float)$r['total'], 2, '.', '') . "</td>";
    echo "</tr>";
  }
  echo "</table>";

  if ($rowCar && ((float)$rowCar['tot']) > 0) {
    echo "<br><h3>Otros cargos</h3>";
    echo "<table border='1' cellspacing='0' cellpadding='4'>";
    echo "<tr><th>Subtotal (cargos)</th><td>" . number_format((float)$rowCar['sub'], 2, '.', '') . "</td></tr>";
    echo "<tr><th>IVA (cargos)</th><td>" . number_format((float)$rowCar['iva'], 2, '.', '') . "</td></tr>";
    echo "<tr><th>Total (cargos)</th><td>" . number_format((float)$rowCar['tot'], 2, '.', '') . "</td></tr>";
    echo "</table>";
  }

  if (!empty($imeiRows)) {
    echo "<br><h3>Ingresos (IMEI / series) de esta factura</h3>";
    echo "<table border='1' cellspacing='0' cellpadding='4'><tr>";
    echo "<th>#</th><th>Marca</th><th>Modelo</th><th>Color</th><th>RAM</th><th>Capacidad</th>";
    foreach ($candDynamic as $c) if (array_key_exists($c, $imeiRows[0])) echo "<th>" . strtoupper($c) . "</th>";
    echo "</tr>";
    $imeisComoTexto = ['imei1', 'imei', 'imei2', 'serial', 'n_serie', 'lote'];
    $n = 1;
    foreach ($imeiRows as $x) {
      echo "<tr>";
      echo "<td>" . $n++ . "</td>";
      echo "<td>" . h($x['marca']) . "</td>";
      echo "<td>" . h($x['modelo']) . "</td>";
      echo "<td>" . h($x['color']) . "</td>";
      echo "<td>" . h($x['ram']) . "</td>";
      echo "<td>" . h($x['capacidad']) . "</td>";
      foreach ($candDynamic as $c) {
        if (array_key_exists($c, $x)) {
          $val = h((string)$x[$c]);
          $isText = in_array($c, $imeisComoTexto, true);
          echo $isText ? "<td class='text'>{$val}</td>" : "<td>{$val}</td>";
        }
      }
      echo "</tr>";
    }
    echo "</table>";
  }

  echo "</body></html>";
  exit;
}

// ============================
// Encabezado (vista)
// ============================
$enc = null;
if ($st = $conn->prepare("
    SELECT c.*, p.nombre AS proveedor, s.nombre AS sucursal
    FROM compras c
    INNER JOIN proveedores p ON p.id = c.id_proveedor
    INNER JOIN sucursales  s ON s.id = c.id_sucursal
    WHERE c.id = ?
")) {
  $st->bind_param("i", $id);
  $st->execute();
  $enc = $st->get_result()->fetch_assoc();
  $st->close();
}
if (!$enc) die("Compra no encontrada.");

$hasDto    = column_exists($conn, 'compras_detalle', 'costo_dto');
$hasDtoIva = column_exists($conn, 'compras_detalle', 'costo_dto_iva');

// Detalle con "ingresadas" correcto
$det = null;
$sqlDetVista = "
  SELECT d.*,
         {$ingAgg} AS ingresadas
  FROM compras_detalle d
  WHERE d.id_compra = ?
  ORDER BY d.id ASC
";
if ($st = $conn->prepare($sqlDetVista)) {
  $st->bind_param("i", $id);
  $st->execute();
  $det = $st->get_result(); // se itera más abajo
}

// Pagos
$pagos = $conn->prepare("
  SELECT id, fecha_pago, monto, metodo_pago, referencia, notas, creado_en
  FROM compras_pagos
  WHERE id_compra=?
  ORDER BY fecha_pago DESC, id DESC
");
$pagos->bind_param("i", $id);
$pagos->execute();
$resPagos = $pagos->get_result();

// Totales pagados/saldo
$rowSum = $conn->prepare("SELECT COALESCE(SUM(monto),0) AS pagado FROM compras_pagos WHERE id_compra=?");
$rowSum->bind_param("i", $id);
$rowSum->execute();
$sumPag = $rowSum->get_result()->fetch_assoc();
$rowSum->close();
$totalPagado = (float)($sumPag['pagado'] ?? 0);
$saldo = max(0, (float)$enc['total'] - $totalPagado);

// Cargos y sumas
$resCargos = null;
$rowCar = ['sub'=>0,'iva'=>0,'tot'=>0];

if (table_exists($conn, 'compras_cargos')) {
  $cargos = $conn->prepare("
    SELECT id, descripcion, monto, iva_porcentaje, iva_monto, total, afecta_costo, creado_en
    FROM compras_cargos
    WHERE id_compra=?
    ORDER BY id ASC
  ");
  $cargos->bind_param("i", $id);
  $cargos->execute();
  $resCargos = $cargos->get_result();

  $sumCar = $conn->prepare("SELECT COALESCE(SUM(monto),0) AS sub, COALESCE(SUM(iva_monto),0) AS iva, COALESCE(SUM(total),0) AS tot FROM compras_cargos WHERE id_compra=?");
  $sumCar->bind_param("i", $id);
  $sumCar->execute();
  $rowCar = $sumCar->get_result()->fetch_assoc();
  $sumCar->close();
}

$sumDet = $conn->prepare("SELECT COALESCE(SUM(subtotal),0) AS sub, COALESCE(SUM(iva),0) AS iva, COALESCE(SUM(total),0) AS tot FROM compras_detalle WHERE id_compra=?");
$sumDet->bind_param("i", $id);
$sumDet->execute();
$rowDet = $sumDet->get_result()->fetch_assoc();
$sumDet->close();

// Navbar después de headers
require_once __DIR__ . '/navbar.php';

// Mensajes flash
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error']   ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Chip de propiedad para UI
$ownerChip = ($propCompra === 'Subdistribuidor') ? 'Subdistribuidor' : 'Luga';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

<div class="container my-4">

  <?php if ($flashSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <?= h($flashSuccess) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>
  <?php if ($flashError): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <?= h($flashError) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-2">
    <h4 class="mb-0">
      Factura #<?= h($enc['num_factura']) ?>
      <span class="badge rounded-pill ms-2" style="background:#ecfeff;color:#155e75;border:1px solid #a5f3fc;">
        Propiedad: <?= h($ownerChip) ?>
      </span>

      <?php if (!empty($enc['estatus'])): ?>
        <span class="badge <?= $enc['estatus'] === 'Pagada' ? 'bg-success' : 'bg-secondary' ?> ms-2">
          <?= h($enc['estatus']) ?>
        </span>
      <?php endif; ?>
    </h4>
    <div class="btn-group">
      <a href="compras_resumen.php" class="btn btn-outline-secondary">↩︎ Volver a resumen</a>
      <a href="compras_nueva.php" class="btn btn-primary">Nueva compra</a>
      <a href="compras_ver.php?id=<?= $id ?>&excel=1" class="btn btn-success">Descargar Excel</a>

      <?php if ($canCancel): ?>
        <button type="button"
          class="btn btn-outline-danger"
          data-bs-toggle="modal"
          data-bs-target="#modalEliminarCompra">
          Cancelar factura
        </button>
      <?php endif; ?>
    </div>
  </div>

  <p class="text-muted mb-1"><strong>Proveedor:</strong> <?= h($enc['proveedor']) ?></p>
  <p class="text-muted mb-1"><strong>Sucursal destino:</strong> <?= h($enc['sucursal']) ?></p>
  <p class="text-muted mb-3">
    <strong>Fechas:</strong> Factura <?= h($enc['fecha_factura']) ?> · Vence <?= h($enc['fecha_vencimiento'] ?? '-') ?>
    <?php if (!empty($enc['condicion_pago'])): ?>
      · <strong>Condición:</strong> <?= h($enc['condicion_pago']) ?>
      <?php if ($enc['condicion_pago'] === 'Crédito' && $enc['dias_vencimiento'] !== ''): ?>
        (<?= (int)$enc['dias_vencimiento'] ?> días)
      <?php endif; ?>
    <?php endif; ?>
  </p>

  <!-- Detalle de modelos -->
  <div class="table-responsive mb-4">
    <table class="table table-bordered align-middle">
      <thead>
        <tr>
          <th>Marca</th>
          <th>Modelo</th>
          <th>Color</th>
          <th>RAM</th>
          <th>Capacidad</th>
          <th class="text-center">Req. IMEI</th>
          <th class="text-end">Cant.</th>
          <th class="text-end">Ingresadas</th>
          <th class="text-end">P.Unit</th>
          <?php if ($hasDto): ?><th class="text-end">Costo Dto (s/IVA)</th><?php endif; ?>
          <?php if ($hasDtoIva): ?><th class="text-end">Costo Dto c/IVA</th><?php endif; ?>
          <th class="text-end">IVA%</th>
          <th class="text-end">Subtotal</th>
          <th class="text-end">IVA</th>
          <th class="text-end">Total</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php while ($r = $det->fetch_assoc()):
          $ing  = (int)$r['ingresadas'];
          $cant = (int)$r['cantidad'];
          $pend = max(0, $cant - $ing);
          $tieneDto     = $hasDto    && isset($r['costo_dto'])     && $r['costo_dto']     !== null && (float)$r['costo_dto']     > 0;
          $tieneDtoIva  = $hasDtoIva && isset($r['costo_dto_iva']) && $r['costo_dto_iva'] !== null && (float)$r['costo_dto_iva'] > 0;
        ?>
          <tr class="<?= $pend > 0 ? 'table-warning' : 'table-success' ?>">
            <td><?= h($r['marca']) ?></td>
            <td>
              <?= h($r['modelo']) ?>
              <?php if ($tieneDto || $tieneDtoIva): ?>
                <span class="badge rounded-pill bg-primary ms-1" title="Renglón con costo descuento">Dto</span>
              <?php endif; ?>
            </td>
            <td><?= h($r['color']) ?></td>
            <td><?= h($r['ram'] ?? '') ?></td>
            <td><?= h($r['capacidad']) ?></td>
            <td class="text-center"><?= $r['requiere_imei'] ? 'Sí' : 'No' ?></td>
            <td class="text-end"><?= $cant ?></td>
            <td class="text-end"><?= $ing ?></td>
            <td class="text-end">$<?= number_format((float)$r['precio_unitario'], 2) ?></td>

            <?php if ($hasDto): ?>
              <td class="text-end"><?= $tieneDto ? '$' . number_format((float)$r['costo_dto'], 2) : '—' ?></td>
            <?php endif; ?>
            <?php if ($hasDtoIva): ?>
              <td class="text-end"><?= $tieneDtoIva ? '$' . number_format((float)$r['costo_dto_iva'], 2) : '—' ?></td>
            <?php endif; ?>

            <td class="text-end"><?= number_format((float)$r['iva_porcentaje'], 2) ?></td>
            <td class="text-end">$<?= number_format((float)$r['subtotal'], 2) ?></td>
            <td class="text-end">$<?= number_format((float)$r['iva'], 2) ?></td>
            <td class="text-end">$<?= number_format((float)$r['total'], 2) ?></td>
            <td class="text-end">
              <?php if ($pend > 0): ?>
                <a class="btn btn-sm btn-primary" href="compras_ingreso.php?detalle=<?= (int)$r['id'] ?>&compra=<?= $id ?>">Ingresar</a>
              <?php else: ?>
                <span class="badge bg-success">Completado</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endwhile; ?>
      </tbody>
      <tfoot>
        <tr>
          <th colspan="<?= 10 + ($hasDto ? 1 : 0) + ($hasDtoIva ? 1 : 0) ?>" class="text-end">Subtotal (modelos)</th>
          <th class="text-end">$<?= number_format((float)$rowDet['sub'], 2) ?></th>
          <th colspan="3"></th>
        </tr>
        <tr>
          <th colspan="<?= 11 + ($hasDto ? 1 : 0) + ($hasDtoIva ? 1 : 0) ?>" class="text-end">IVA (modelos)</th>
          <th class="text-end">$<?= number_format((float)$rowDet['iva'], 2) ?></th>
          <th colspan="2"></th>
        </tr>
        <tr class="table-light">
          <th colspan="<?= 12 + ($hasDto ? 1 : 0) + ($hasDtoIva ? 1 : 0) ?>" class="text-end fs-6">Total (modelos)</th>
          <th class="text-end fs-6">$<?= number_format((float)$rowDet['tot'], 2) ?></th>
          <th></th>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- Otros cargos -->
  <div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Otros cargos</strong>
      <?php if ($rowCar && ((float)$rowCar['tot']) > 0): ?>
        <span class="text-muted small">
          Subtotal: $<?= number_format((float)$rowCar['sub'], 2) ?> ·
          IVA: $<?= number_format((float)$rowCar['iva'], 2) ?> ·
          Total: $<?= number_format((float)$rowCar['tot'], 2) ?>
        </span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <?php if ($resCargos && $resCargos->num_rows > 0): ?>
        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle">
            <thead class="table-light">
              <tr>
                <th>Descripción</th>
                <th class="text-end">Importe</th>
                <th class="text-end">IVA %</th>
                <th class="text-end">IVA</th>
                <th class="text-end">Total</th>
                <th class="text-muted">Capturado</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($x = $resCargos->fetch_assoc()): ?>
                <tr>
                  <td><?= h($x['descripcion']) ?></td>
                  <td class="text-end">$<?= number_format((float)$x['monto'], 2) ?></td>
                  <td class="text-end"><?= number_format((float)$x['iva_porcentaje'], 2) ?></td>
                  <td class="text-end">$<?= number_format((float)$x['iva_monto'], 2) ?></td>
                  <td class="text-end">$<?= number_format((float)$x['total'], 2) ?></td>
                  <td class="text-muted small"><?= h($x['creado_en']) ?></td>
                </tr>
              <?php endwhile; ?>
            </tbody>
            <tfoot>
              <tr>
                <th class="text-end">Subtotal (cargos)</th>
                <th class="text-end">$<?= number_format((float)$rowCar['sub'], 2) ?></th>
                <th></th>
                <th class="text-end">$<?= number_format((float)$rowCar['iva'], 2) ?></th>
                <th class="text-end">$<?= number_format((float)$rowCar['tot'], 2) ?></th>
                <th></th>
              </tr>
            </tfoot>
          </table>
        </div>
      <?php else: ?>
        <div class="text-muted">No hay otros cargos registrados para esta compra.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Panel de pagos -->
  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <strong>Pagos</strong>
        <span class="ms-2">Total pagado: <strong>$<?= number_format($totalPagado, 2) ?></strong></span>
        <span class="ms-3">Saldo:
          <strong class="<?= $saldo <= 0 ? 'text-success' : 'text-danger' ?>">
            $<?= number_format($saldo, 2) ?>
          </strong>
        </span>
      </div>
      <?php $puedeAgregarPago = $saldo > 0 && ($isAdminLike || $isSubdisAdmin); ?>
      <button class="btn btn-sm btn-outline-primary <?= $puedeAgregarPago ? '' : 'disabled' ?>"
        data-bs-toggle="modal" data-bs-target="#modalPago"
        <?= $puedeAgregarPago ? '' : 'disabled title="No es posible agregar pagos"' ?>>
        + Agregar pago
      </button>
    </div>
    <div class="card-body">
      <?php if ($resPagos && $resPagos->num_rows > 0): ?>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Método</th>
                <th>Referencia</th>
                <th class="text-end">Monto</th>
                <th>Notas</th>
                <th class="text-muted">Capturado</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($p = $resPagos->fetch_assoc()): ?>
                <tr>
                  <td><?= h($p['fecha_pago']) ?></td>
                  <td><?= h($p['metodo_pago'] ?? '') ?></td>
                  <td><?= h($p['referencia'] ?? '') ?></td>
                  <td class="text-end">$<?= number_format((float)$p['monto'], 2) ?></td>
                  <td><?= nl2br(h($p['notas'] ?? '')) ?></td>
                  <td class="text-muted small"><?= h($p['creado_en']) ?></td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="text-muted">No hay pagos registrados.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Modal pago -->
  <div class="modal fade" id="modalPago" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <form method="post" class="modal-content">
        <input type="hidden" name="accion" value="agregar_pago">
        <input type="hidden" name="id_compra" value="<?= $id ?>">
        <div class="modal-header">
          <h5 class="modal-title">Agregar pago</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Fecha</label>
              <input type="date" name="fecha_pago" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Método</label>
              <select name="metodo_pago" class="form-select">
                <option value="Efectivo">Efectivo</option>
                <option value="Transferencia">Transferencia</option>
                <option value="Tarjeta">Tarjeta</option>
                <option value="Depósito">Depósito</option>
                <option value="Otro">Otro</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Monto</label>
              <input type="number" name="monto" class="form-control" step="0.01" min="0.01" required>
            </div>
            <div class="col-12">
              <label class="form-label">Referencia</label>
              <input type="text" name="referencia" class="form-control" maxlength="120" placeholder="Folio, banco, etc. (opcional)">
            </div>
            <div class="col-12">
              <label class="form-label">Notas</label>
              <textarea name="notas" class="form-control" rows="2" maxlength="1000" placeholder="Opcional"></textarea>
            </div>
          </div>
          <small class="text-muted d-block mt-2">Se registrará en <strong>compras_pagos</strong>.</small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-primary">Guardar pago</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($canCancel): ?>
    <!-- Modal eliminar / cancelar factura -->
    <div class="modal fade" id="modalEliminarCompra" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
          <input type="hidden" name="accion" value="eliminar_compra">
          <input type="hidden" name="id_compra" value="<?= $id ?>">
          <div class="modal-header bg-danger text-white">
            <h5 class="modal-title">Cancelar factura</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <p class="mb-2">
              Estás a punto de <strong>cancelar por completo</strong> la factura
              <strong>#<?= h($enc['num_factura']) ?></strong>.
            </p>
            <ul class="small mb-3">
              <li>Se eliminarán los pagos registrados en esta factura.</li>
              <li>Se revertirán los ingresos a inventario asociados (solo equipos/insumos todavía disponibles).</li>
              <li>Se eliminarán los renglones de detalle y cargos de la compra.</li>
            </ul>
            <p class="small text-danger mb-3">
              Si algún equipo ya fue vendido o movido de inventario, la cancelación se bloqueará.
            </p>
            <div class="mb-3">
              <label class="form-label">Confirma tu contraseña</label>
              <input type="password"
                name="password_admin"
                class="form-control"
                required
                autocomplete="current-password"
                placeholder="Contraseña de tu usuario">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
            <button type="submit" class="btn btn-danger">
              Sí, cancelar factura
            </button>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>

</div>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
