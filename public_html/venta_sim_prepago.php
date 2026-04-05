<?php
/* venta_sim_prepago.php — Alta express de SIM (misma página) + venta normal
   Reglas de comisiones SIM (fijas):
   - comision_ejecutivo:
       * Si rol = Gerente → 0
       * Nueva + Bait = 10, Nueva + ATT = 5
       * Portabilidad + Bait = 50, Portabilidad + ATT = 10
       * Otros (Regalo, etc.) = 0
   - comision_gerente:
       * Nueva + Bait = 5, Nueva + ATT = 5
       * Portabilidad + Bait = 10, Portabilidad + ATT = 5
       * Otros = 0
   - tipo_sim se toma del inventario y se normaliza a {Bait, ATT, Unefon}.
     * Para comisiones: Unefon se trata como ATT.
   - Ahora también amarramos la venta a cliente:
       * id_cliente, nombre_cliente, numero_cliente (teléfono)

   ✅ Promo 2X1:
   - El checkbox SOLO aplica en "Regalo"
   - Si se marca, se fuerza tipo_venta="Regalo" y precio=0 (front y backend)
*/

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';

$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);
$rolUsuario  = (string)($_SESSION['rol'] ?? 'Ejecutivo'); // ← usamos esto para comision_ejecutivo
$nombreUser  = trim($_SESSION['nombre'] ?? 'Usuario');
$mensaje     = '';

/* ===== Flags para alta rápida ===== */
$selSimId = isset($_GET['sel_sim']) ? (int)$_GET['sel_sim'] : 0; // para preseleccionar tras alta
$flash    = $_GET['msg'] ?? ''; // sim_ok, sim_dup, sim_err

/* =========================
   FUNCIONES AUXILIARES
========================= */
function redir($msg, $extra = []) {
    $qs = array_merge(['msg'=>$msg], $extra);
    $url = basename($_SERVER['PHP_SELF']).'?'.http_build_query($qs);
    header("Location: $url"); exit();
}

/**
 * Normaliza operador de inventario a tipo_sim:
 * Regresa: 'Bait' | 'ATT' | 'Unefon'
 * - Acepta variantes (AT&T, ATT, UNEFON, Une-fon, Une fón, etc.)
 */
function normalizarOperadorSIM(string $op): string {
    $op = strtoupper(trim($op));

    // Limpieza robusta
    $op = str_replace([' ', '-', '_', '.'], '', $op);
    // Por si viene con acento
    $op = str_replace(['Á','É','Í','Ó','Ú','Ü'], ['A','E','I','O','U','U'], $op);

    if ($op === 'ATT' || $op === 'AT&T' || $op === 'ATANDT' || $op === 'ATYT') return 'ATT';
    if ($op === 'UNEFON') return 'Unefon';
    return 'Bait';
}

/** Para comisiones: tratamos Unefon como ATT */
function tipoSimParaComision(string $tipoSim): string {
    $t = strtoupper(trim($tipoSim));
    if ($t === 'UNEFON') return 'ATT';
    if ($t === 'BAIT') return 'BAIT';
    if ($t === 'ATT')  return 'ATT';
    // fallback seguro
    return 'BAIT';
}

/** Calcula comisión del ejecutivo según reglas fijas */
function calcComisionEjecutivoSIM(string $rolUsuario, string $tipoVenta, string $tipoSim): float {
    $tipoVenta = strtolower($tipoVenta);

    // Normalizamos para comisiones (UNEFON -> ATT)
    $tipoSim = tipoSimParaComision($tipoSim); // 'BAIT' | 'ATT'

    // Si el vendedor es Gerente → 0
    if (strcasecmp($rolUsuario, 'Gerente') === 0) return 0.0;

    if ($tipoVenta === 'nueva') {
        if ($tipoSim === 'BAIT') return 10.0;
        if ($tipoSim === 'ATT')  return 5.0;
    } elseif ($tipoVenta === 'portabilidad') {
        if ($tipoSim === 'BAIT') return 50.0;
        if ($tipoSim === 'ATT')  return 10.0;
    }
    return 0.0; // Regalo u otros
}

/** Calcula comisión del gerente según reglas fijas */
function calcComisionGerenteSIM(string $tipoVenta, string $tipoSim): float {
    $tipoVenta = strtolower($tipoVenta);

    // Normalizamos para comisiones (UNEFON -> ATT)
    $tipoSim = tipoSimParaComision($tipoSim); // 'BAIT' | 'ATT'

    if ($tipoVenta === 'nueva') {
        return 5.0; // Bait o ATT
    } elseif ($tipoVenta === 'portabilidad') {
        if ($tipoSim === 'BAIT') return 10.0;
        if ($tipoSim === 'ATT')  return 5.0;
    }
    return 0.0;
}

/* =========================
   ALTA RÁPIDA DE SIM (Prepago)
   (antes del candado; no es venta)
========================= */
if (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['accion'] ?? '') === 'alta_sim')) {
    $iccid    = strtoupper(trim($_POST['iccid'] ?? ''));
    $operador = trim($_POST['operador'] ?? '');
    $dn       = trim($_POST['dn'] ?? '');
    $caja_id  = trim($_POST['caja_id'] ?? '');

    // Validaciones
    if (!preg_match('/^\d{19}[A-Z]$/', $iccid)) {
        redir('sim_err', ['e'=>'ICCID inválido. Debe ser 19 dígitos + 1 letra mayúscula (ej. ...1909F).']);
    }

    // ✅ Ahora aceptamos Unefon también
    if (!in_array($operador, ['Bait','AT&T','Unefon'], true)) {
        redir('sim_err', ['e'=>'Operador inválido. Elige Bait, AT&T o Unefon.']);
    }

    // DN OBLIGATORIO
    if ($dn === '' || !preg_match('/^\d{10}$/', $dn)) {
        redir('sim_err', ['e'=>'El DN es obligatorio y debe tener 10 dígitos.']);
    }

    // Duplicado global con nombre de sucursal
    $stmt = $conn->prepare("
        SELECT i.id, i.id_sucursal, i.estatus, s.nombre AS sucursal_nombre
        FROM inventario_sims i
        JOIN sucursales s ON s.id = i.id_sucursal
        WHERE i.iccid=? LIMIT 1
    ");
    $stmt->bind_param('s', $iccid);
    $stmt->execute();
    $dup = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($dup) {
        if ((int)$dup['id_sucursal'] === $idSucursal && $dup['estatus'] === 'Disponible') {
            redir('sim_dup', ['sel_sim'=>(int)$dup['id']]);
        }
        $msg = "El ICCID ya existe (ID {$dup['id']}) en la sucursal {$dup['sucursal_nombre']} con estatus {$dup['estatus']}.";
        redir('sim_err', ['e'=>$msg]);
    }

    // Insert como PREPAGO Disponible en esta sucursal
    $sql = "INSERT INTO inventario_sims (iccid, dn, operador, caja_id, tipo_plan, estatus, id_sucursal)
            VALUES (?,?,?,?, 'Prepago', 'Disponible', ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssi', $iccid, $dn, $operador, $caja_id, $idSucursal);

    try {
        $stmt->execute();
        $newId = (int)$stmt->insert_id;
        $stmt->close();
        redir('sim_ok', ['sel_sim'=>$newId]);
    } catch (mysqli_sql_exception $e) {
        redir('sim_err', ['e'=>'No se pudo guardar: '.$e->getMessage()]);
    }
}

/* =========================
   PROCESAR VENTA SIM (reglas fijas)
========================= */
require_once __DIR__ . '/candado_captura.php';
abortar_si_captura_bloqueada(); // por defecto bloquea POST

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['accion'] ?? '') !== 'alta_sim')) {

    $idSim       = (int)($_POST['id_sim'] ?? 0);
    $tipoVenta   = trim($_POST['tipo_venta'] ?? '');
    $precio      = (float)($_POST['precio'] ?? 0);
    $comentarios = trim($_POST['comentarios'] ?? '');

    // ✅ Promo 2X1 (SOLO aplica en Regalo)
    $promo2x1 = isset($_POST['promo_2x1']) && $_POST['promo_2x1'] == '1';
    $tagPromo = 'Promoción 2X1';

    if ($promo2x1) {
        $tipoVenta = 'Regalo';
        $precio = 0.0;

        if (stripos($comentarios, $tagPromo) === false) {
            $comentarios = $comentarios !== '' ? ($comentarios . ' | ' . $tagPromo) : $tagPromo;
        }
    } else {
        // Si no está marcada, removemos el tag si venía manual
        $comentarios = preg_replace('/(^|\s*\|\s*)'.preg_quote($tagPromo,'/').'(\s*\|\s*|$)/i', '$1', $comentarios);
        $comentarios = trim(preg_replace('/\s*\|\s*/', ' | ', $comentarios));
        $comentarios = preg_replace('/^\|\s*/','', $comentarios);
        $comentarios = preg_replace('/\s*\|$/','', $comentarios);
        $comentarios = trim($comentarios);
    }

    // 🔗 Datos de cliente desde el formulario
    $idCliente       = (int)($_POST['id_cliente'] ?? 0);
    $nombreCliente   = trim($_POST['nombre_cliente'] ?? '');
    $telefonoCliente = preg_replace('/\D+/', '', (string)($_POST['telefono_cliente'] ?? '')); // solo dígitos
    $correoCliente   = trim($_POST['correo_cliente'] ?? '');

    // 1) Verificar SIM y OBTENER operador DESDE INVENTARIO (ignorar POST)
    $sql = "SELECT id, iccid, operador
            FROM inventario_sims
            WHERE id=? AND estatus='Disponible' AND id_sucursal=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $idSim, $idSucursal);
    $stmt->execute();
    $sim = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sim) {
        $mensaje = '<div class="alert alert-danger">La SIM seleccionada no está disponible.</div>';
    } else {
        // ✅ Normalizar operador -> tipoSim válido para ventas_sims.tipo_sim (Bait | ATT | Unefon)
        $tipoSim = normalizarOperadorSIM($sim['operador']); // 'Bait' | 'ATT' | 'Unefon'

        // Reglas de precio
        if ($tipoVenta === 'Regalo') {
            if (round($precio, 2) != 0.00) {
                $mensaje = '<div class="alert alert-danger">Para "Regalo" el precio debe ser 0.</div>';
            }
        } else {
            if ($precio <= 0) {
                $mensaje = '<div class="alert alert-danger">El precio debe ser mayor a 0 para Nueva/Portabilidad.</div>';
            }
        }

        // Validaciones mínimas de cliente para Portabilidad
        if ($mensaje === '' && strcasecmp($tipoVenta, 'Portabilidad') === 0) {
            if ($idCliente <= 0) {
                $mensaje = '<div class="alert alert-danger">Debes seleccionar un cliente para Portabilidad.</div>';
            } elseif ($telefonoCliente === '' || !preg_match('/^\d{10}$/', $telefonoCliente)) {
                $mensaje = '<div class="alert alert-danger">El cliente debe tener un teléfono válido de 10 dígitos.</div>';
            }
        }

        // 🔴 Validación: si la tabla maneja columnas de cliente, obligamos a que haya cliente SIEMPRE
        $tieneColsCliente = columnExists($conn, 'ventas_sims', 'id_cliente')
                         && columnExists($conn, 'ventas_sims', 'numero_cliente')
                         && columnExists($conn, 'ventas_sims', 'nombre_cliente');

        if ($mensaje === '' && $tieneColsCliente) {
            if ($idCliente <= 0 || $nombreCliente === '') {
                $mensaje = '<div class="alert alert-danger">Debes seleccionar un cliente antes de registrar la venta.</div>';
            }
        }

        if ($mensaje === '') {
            // 2) Comisiones fijas (UNEFON se trata como ATT en comisiones)
            $comisionEjecutivo = calcComisionEjecutivoSIM($rolUsuario, $tipoVenta, $tipoSim);
            $comisionGerente   = calcComisionGerenteSIM($tipoVenta, $tipoSim);

            // ✅ Propiedad de venta (LUGA vs SUBDISTRIBUIDOR)
            $propietario = 'LUGA';
            $id_subdis   = null;

            $esRolSubdis = ($rolUsuario === 'Subdistribuidor') || (strpos($rolUsuario, 'Subdis_') === 0);

            if ($esRolSubdis) {
                $propietario = 'SUBDISTRIBUIDOR';

                // 1) Desde sesión
                if (isset($_SESSION['id_subdis'])) {
                    $tmp = (int)$_SESSION['id_subdis'];
                    $id_subdis = $tmp > 0 ? $tmp : null;
                } elseif (isset($_SESSION['id_subdistribuidor'])) {
                    $tmp = (int)$_SESSION['id_subdistribuidor'];
                    $id_subdis = $tmp > 0 ? $tmp : null;
                }

                // 2) Desde usuarios (compatibilidad)
                if ($id_subdis === null) {
                    $colSub = null;
                    if (columnExists($conn, 'usuarios', 'id_subdis')) $colSub = 'id_subdis';
                    elseif (columnExists($conn, 'usuarios', 'id_subdistribuidor')) $colSub = 'id_subdistribuidor';

                    if ($colSub) {
                        $st = $conn->prepare("SELECT `$colSub` AS id_sub FROM usuarios WHERE id=? LIMIT 1");
                        $st->bind_param("i", $idUsuario);
                        $st->execute();
                        $ru = $st->get_result()->fetch_assoc();
                        $st->close();

                        $tmp = (int)($ru['id_sub'] ?? 0);
                        $id_subdis = $tmp > 0 ? $tmp : null;
                    }
                }

                // 3) Seguridad: evita SUBDISTRIBUIDOR sin id_subdis
                if ($id_subdis === null) {
                    $propietario = 'LUGA';
                }
            }

            // ✅ Columnas de propiedad en ventas_sims (compatibilidad)
            $tienePropietario = columnExists($conn, 'ventas_sims', 'propietario');
            $tieneIdSubdis    = columnExists($conn, 'ventas_sims', 'id_subdis');

            // 3) Insertar venta
            $numeroCliente = $telefonoCliente; // mapeamos teléfono a numero_cliente

            if ($tieneColsCliente) {

                if ($tienePropietario && $tieneIdSubdis) {
                    $sqlVenta = "INSERT INTO ventas_sims
                        (tipo_venta, tipo_sim, numero_cliente, nombre_cliente, comentarios, precio_total,
                         comision_ejecutivo, comision_gerente, id_usuario, id_sucursal, id_cliente,
                         propietario, id_subdis, fecha_venta)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())";
                    $stmt = $conn->prepare($sqlVenta);
                    $stmt->bind_param(
                        "sssssdddiiisi",
                        $tipoVenta,
                        $tipoSim,
                        $numeroCliente,
                        $nombreCliente,
                        $comentarios,
                        $precio,
                        $comisionEjecutivo,
                        $comisionGerente,
                        $idUsuario,
                        $idSucursal,
                        $idCliente,
                        $propietario,
                        $id_subdis
                    );
                } else {
                    $sqlVenta = "INSERT INTO ventas_sims
                        (tipo_venta, tipo_sim, numero_cliente, nombre_cliente, comentarios, precio_total,
                         comision_ejecutivo, comision_gerente, id_usuario, id_sucursal, id_cliente, fecha_venta)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?, NOW())";
                    $stmt = $conn->prepare($sqlVenta);
                    $stmt->bind_param(
                        "sssssdddiii",
                        $tipoVenta,
                        $tipoSim,
                        $numeroCliente,
                        $nombreCliente,
                        $comentarios,
                        $precio,
                        $comisionEjecutivo,
                        $comisionGerente,
                        $idUsuario,
                        $idSucursal,
                        $idCliente
                    );
                }

            } else {

                if ($tienePropietario && $tieneIdSubdis) {
                    $sqlVenta = "INSERT INTO ventas_sims
                        (tipo_venta, tipo_sim, comentarios, precio_total,
                         comision_ejecutivo, comision_gerente, id_usuario, id_sucursal,
                         propietario, id_subdis, fecha_venta)
                        VALUES (?,?,?,?,?,?,?,?,?,?, NOW())";
                    $stmt = $conn->prepare($sqlVenta);
                    $stmt->bind_param(
                        "sssdddiisi",
                        $tipoVenta,
                        $tipoSim,
                        $comentarios,
                        $precio,
                        $comisionEjecutivo,
                        $comisionGerente,
                        $idUsuario,
                        $idSucursal,
                        $propietario,
                        $id_subdis
                    );
                } else {
                    $sqlVenta = "INSERT INTO ventas_sims
                        (tipo_venta, tipo_sim, comentarios, precio_total,
                         comision_ejecutivo, comision_gerente, id_usuario, id_sucursal, fecha_venta)
                        VALUES (?,?,?,?,?,?,?,?, NOW())";
                    $stmt = $conn->prepare($sqlVenta);
                    $stmt->bind_param(
                        "sssddiii",
                        $tipoVenta,
                        $tipoSim,
                        $comentarios,
                        $precio,
                        $comisionEjecutivo,
                        $comisionGerente,
                        $idUsuario,
                        $idSucursal
                    );
                }
            }

            $stmt->execute();
            $idVenta = (int)$stmt->insert_id;
            $stmt->close();

            // 4) Detalle (si manejas tabla detalle_venta_sims)
            if (columnExists($conn, 'detalle_venta_sims', 'id_venta')) {
                $sqlDetalle = "INSERT INTO detalle_venta_sims (id_venta, id_sim, precio_unitario) VALUES (?,?,?)";
                $stmt = $conn->prepare($sqlDetalle);
                $stmt->bind_param("iid", $idVenta, $idSim, $precio);
                $stmt->execute();
                $stmt->close();
            }

            // 5) Actualizar inventario
            $sqlUpdate = "UPDATE inventario_sims
                          SET estatus='Vendida', id_usuario_venta=?, fecha_venta=NOW()
                          WHERE id=?";
            $stmt = $conn->prepare($sqlUpdate);
            $stmt->bind_param("ii", $idUsuario, $idSim);
            $stmt->execute();
            $stmt->close();

            $mensaje = '<div class="alert alert-success">✅ Venta de SIM registrada correctamente.</div>';
        }
    }
}

/* ===== Util: verifica columna ===== */
function columnExists(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = '$t'
      AND COLUMN_NAME  = '$c'
    LIMIT 1
  ";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

/* ===== Nombre de sucursal del usuario ===== */
$nomSucursal = '—';
$stmtNS = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1");
$stmtNS->bind_param("i", $idSucursal);
$stmtNS->execute();
$rowNS = $stmtNS->get_result()->fetch_assoc();
if ($rowNS) { $nomSucursal = $rowNS['nombre']; }
$stmtNS->close();

/* ===== Listar SIMs disponibles (incluye operador) ===== */
$sql = "SELECT id, iccid, caja_id, fecha_ingreso, operador
        FROM inventario_sims
        WHERE estatus='Disponible' AND id_sucursal=?
        ORDER BY fecha_ingreso ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $idSucursal);
$stmt->execute();
$disponiblesRes = $stmt->get_result();
$disponibles = $disponiblesRes->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Venta SIM Prepago</title>

  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <!-- Select2 -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

  <style>
    :root{ --brand:#0d6efd; --brand-100: rgba(13,110,253,.08); }
    body.bg-light{
      background:
        radial-gradient(1200px 400px at 100% -50%, var(--brand-100), transparent),
        radial-gradient(1200px 400px at -10% 120%, rgba(25,135,84,.06), transparent),
        #f8fafc;
    }
    .page-title{font-weight:700; letter-spacing:.3px;}
    .card-elev{border:0; box-shadow:0 10px 24px rgba(2,8,20,0.06), 0 2px 6px rgba(2,8,20,0.05); border-radius:1rem;}
    .section-title{font-size:.95rem; font-weight:700; color:#334155; text-transform:uppercase; letter-spacing:.8px; margin-bottom:.75rem; display:flex; gap:.5rem; align-items:center;}
    .help-text{font-size:.85rem; color:#64748b;}
    .select2-container .select2-selection--single { height: 38px; border-radius:.5rem; }
    .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 36px; }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px; }
    .btn-gradient{background:linear-gradient(90deg,#16a34a,#22c55e); border:0;}
    .btn-gradient:disabled{opacity:.7;}
    .badge-soft{background:#eef2ff; color:#1e40af; border:1px solid #dbeafe;}
    .list-compact{margin:0; padding-left:1rem;} .list-compact li{margin-bottom:.25rem;}
    .readonly-hint{background:#f1f5f9;}

    .cliente-summary-label{
      font-size:.85rem;
      text-transform:uppercase;
      letter-spacing:.08em;
      color:#64748b;
      margin-bottom:.25rem;
    }
    .cliente-summary-main{
      font-weight:600;
      font-size:1.05rem;
      color:#111827;
    }
    .cliente-summary-sub{
      font-size:.9rem;
      color:#6b7280;
    }
  </style>
</head>
<body class="bg-light">

<?php include 'navbar.php'; ?>

<div class="container my-4">

  <?php if ($flash === 'sim_ok'): ?>
    <div class="alert alert-success">✅ SIM agregada a tu inventario y preseleccionada.</div>
  <?php elseif ($flash === 'sim_dup'): ?>
    <div class="alert alert-info">ℹ️ Ese ICCID ya existía en tu inventario y quedó seleccionado.</div>
  <?php elseif ($flash === 'sim_err'): ?>
    <div class="alert alert-danger">❌ No se pudo agregar la SIM. <?= htmlspecialchars($_GET['e'] ?? '') ?></div>
  <?php endif; ?>

  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h2 class="page-title mb-1"><i class="bi bi-sim me-2"></i>Venta de SIM Prepago</h2>
      <div class="help-text">Selecciona la SIM, vincula al cliente y confirma los datos en el modal antes de enviar.</div>
    </div>
  </div>

  <!-- Contexto de sesión -->
  <div class="mb-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="badge rounded-pill text-bg-primary"><i class="bi bi-person-badge me-1"></i> Usuario: <?= htmlspecialchars($nombreUser) ?></span>
        <span class="badge rounded-pill text-bg-info"><i class="bi bi-shop me-1"></i> Tu sucursal: <?= htmlspecialchars($nomSucursal) ?></span>
        <span class="badge rounded-pill badge-soft"><i class="bi bi-shield-check me-1"></i> Sesión activa</span>
      </div>
    </div>
  </div>

  <?= $mensaje ?>

  <form method="POST" class="card card-elev p-3 mb-4" id="formVentaSim" novalidate>
    <input type="hidden" name="accion" value="venta">

    <!-- 🔗 Cliente seleccionado -->
    <input type="hidden" name="id_cliente" id="id_cliente" value="">
    <input type="hidden" name="nombre_cliente" id="nombre_cliente" value="">
    <input type="hidden" name="telefono_cliente" id="telefono_cliente" value="">
    <input type="hidden" name="correo_cliente" id="correo_cliente" value="">

    <div class="card-body">

      <div class="section-title"><i class="bi bi-people"></i> Datos del cliente</div>
      <div class="row g-3 mb-3">
        <div class="col-md-8">
          <div class="border rounded-3 p-3 bg-light">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <div class="cliente-summary-label">Cliente seleccionado</div>
                <div class="cliente-summary-main" id="cliente_resumen_nombre">
                  Ninguno seleccionado
                </div>
                <div class="cliente-summary-sub" id="cliente_resumen_detalle">
                  Usa el botón <strong>Buscar / crear cliente</strong> para seleccionar uno.
                </div>
              </div>
              <div class="text-end">
                <span class="badge rounded-pill text-bg-secondary" id="badge_tipo_cliente">
                  <i class="bi bi-person-dash me-1"></i> Sin cliente
                </span>
              </div>
            </div>
          </div>
        </div>
        <div class="col-md-4 d-flex align-items-center justify-content-md-end">
          <button type="button" class="btn btn-outline-primary w-100" id="btn_open_modal_clientes">
            <i class="bi bi-search me-1"></i> Buscar / crear cliente
          </button>
        </div>
      </div>

      <hr class="my-4">

      <div class="section-title"><i class="bi bi-collection"></i> Selección de SIM</div>
      <div class="row g-3 mb-3">
        <!-- SIM con buscador -->
        <div class="col-md-7">
          <label class="form-label">SIM disponible</label>
          <select name="id_sim" id="selectSim" class="form-select select2-sims" required>
            <option value="">-- Selecciona SIM --</option>
            <?php foreach($disponibles as $row): ?>
              <option
                value="<?= (int)$row['id'] ?>"
                data-operador="<?= htmlspecialchars($row['operador']) ?>"
                data-iccid="<?= htmlspecialchars($row['iccid']) ?>"
                <?= ($selSimId && $selSimId==(int)$row['id']) ? 'selected' : '' ?>
              >
                <?= htmlspecialchars($row['iccid']) ?> | <?= htmlspecialchars($row['operador']) ?> | Caja: <?= htmlspecialchars($row['caja_id']) ?> | Ingreso: <?= htmlspecialchars($row['fecha_ingreso']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Escribe ICCID, operador o caja para filtrar.</div>

          <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAltaSim">
              <i class="bi bi-plus-circle me-1"></i> Agregar SIM (no está en inventario)
            </button>
          </div>
        </div>

        <!-- Operador solo lectura -->
        <div class="col-md-5">
          <label class="form-label">Operador (solo lectura)</label>
          <input type="text" id="tipoSimView" class="form-control" value="" readonly>
        </div>
      </div>

      <hr class="my-4">

      <div class="section-title"><i class="bi bi-receipt"></i> Datos de la venta</div>
      <div class="row g-3 mb-3">
        <div class="col-md-3">
          <label class="form-label">Tipo de venta</label>
          <select name="tipo_venta" id="tipo_venta" class="form-select" required>
            <option value="Nueva">Nueva</option>
            <option value="Portabilidad">Portabilidad</option>
            <option value="Regalo">Regalo (costo 0)</option>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Precio</label>
          <input type="number" step="0.01" name="precio" id="precio" class="form-control" value="0" required>
          <div class="form-text" id="precio_help">Para “Regalo”, el precio debe ser 0.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label">Comentarios</label>
          <input type="text" name="comentarios" id="comentarios" class="form-control" placeholder="Notas (opcional)">

          <!-- ✅ Promo 2X1 -->
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" value="1" id="promo_2x1" name="promo_2x1">
            <label class="form-check-label" for="promo_2x1">
              Promoción <strong>2X1</strong> (solo en <strong>Regalo</strong>)
            </label>
          </div>
          <div class="form-text" id="promo_help">
            Si la marcas, se forzará el tipo de venta a “Regalo” y precio 0.
          </div>
        </div>
      </div>

    </div>
    <div class="card-footer bg-white border-0 p-3">
      <button type="submit" class="btn btn-gradient text-white w-100 py-2" id="btn_submit">
        <i class="bi bi-check2-circle me-2"></i> Registrar Venta
      </button>
    </div>
  </form>
</div>

<!-- Modal: Alta rápida de SIM (Prepago) -->
<div class="modal fade" id="modalAltaSim" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-sim me-2 text-primary"></i>Alta de SIM a inventario (Prepago)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <form method="POST" id="formAltaSim">
        <input type="hidden" name="accion" value="alta_sim">
        <div class="modal-body">
          <div class="alert alert-secondary py-2">
            Se agregará a tu inventario de <b><?= htmlspecialchars($nomSucursal) ?></b> como <b>Disponible</b>.
          </div>

          <div class="mb-3">
            <label class="form-label">ICCID</label>
            <input type="text" name="iccid" id="alta_iccid" class="form-control" placeholder="8952140063250341909F" maxlength="20" required>
            <div class="form-text">Formato: 19 dígitos + 1 letra mayúscula.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Operador</label>
            <select name="operador" id="alta_operador" class="form-select" required>
              <option value="">-- Selecciona --</option>
              <option value="Bait">Bait</option>
              <option value="AT&T">AT&T</option>
              <option value="Unefon">Unefon</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">DN (10 dígitos)</label>
            <input type="text" name="dn" id="alta_dn" class="form-control" placeholder="5512345678" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Caja ID (opcional)</label>
            <input type="text" name="caja_id" id="alta_caja" class="form-control" placeholder="Etiqueta/caja">
          </div>

          <?php if ($flash==='sim_err' && !empty($_GET['e'])): ?>
            <div class="text-danger small mt-2"><?= htmlspecialchars($_GET['e']) ?></div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Guardar y usar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal de Confirmación -->
<div class="modal fade" id="modalConfirmacion" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title"><i class="bi bi-patch-question me-2 text-primary"></i>Confirma la venta de SIM</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info mb-3">
          <i class="bi bi-info-circle me-1"></i>
          <strong>Validación de identidad:</strong> verifica que se registrará con el <u>usuario correcto</u> y en la <u>sucursal correcta</u>.
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="section-title"><i class="bi bi-person-check"></i> Usuario y sucursal</div>
                <ul class="list-compact">
                  <li><strong>Usuario:</strong> <span id="conf_usuario"><?= htmlspecialchars($nombreUser) ?></span></li>
                  <li><strong>Sucursal:</strong> <span id="conf_sucursal"><?= htmlspecialchars($nomSucursal) ?></span></li>
                  <li><strong>Cliente:</strong> <span id="conf_cliente">—</span></li>
                </ul>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <div class="section-title"><i class="bi bi-sim"></i> Detalle de venta</div>
                <ul class="list-compact">
                  <li><strong>ICCID:</strong> <span id="conf_iccid">—</span></li>
                  <li><strong>Operador:</strong> <span id="conf_operador">—</span></li>
                  <li><strong>Tipo de venta:</strong> <span id="conf_tipo">—</span></li>
                  <li><strong>Precio:</strong> $<span id="conf_precio">0.00</span></li>
                  <li class="text-muted"><em>Comentarios:</em> <span id="conf_comentarios">—</span></li>
                </ul>
              </div>
            </div>
          </div>
        </div>

        <hr>
        <div class="help-text">
          Si detectas un error, cierra este modal y corrige los datos. Si todo es correcto, confirma para enviar.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">
          <i class="bi bi-pencil-square me-1"></i> Corregir
        </button>
        <button class="btn btn-primary" id="btn_confirmar_envio">
          <i class="bi bi-send-check me-1"></i> Confirmar y enviar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal de clientes -->
<div class="modal fade" id="modalClientes" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title">
          <i class="bi bi-people me-2 text-primary"></i>Buscar o crear cliente
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <!-- Buscador -->
        <div class="mb-3">
          <label class="form-label">Buscar por nombre, teléfono o código de cliente</label>
          <div class="input-group">
            <input type="text" class="form-control" id="cliente_buscar_q" placeholder="Ej. LUCIA, 5587967699 o CL-40-000001">
            <button class="btn btn-primary" type="button" id="btn_buscar_modal">
              <i class="bi bi-search"></i> Buscar
            </button>
          </div>
          <div class="form-text">
            La búsqueda se realiza a nivel <strong>global.</strong>
          </div>
        </div>

        <hr>

        <!-- Resultados -->
        <div class="mb-2 d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Resultados</span>
          <span class="text-muted small" id="lbl_resultados_clientes">Sin buscar aún.</span>
        </div>
        <div class="table-responsive mb-3">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Teléfono</th>
                <th>Correo</th>
                <th>Fecha alta</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="tbody_clientes">
              <!-- JS -->
            </tbody>
          </table>
        </div>

        <hr>

        <!-- Crear nuevo cliente -->
        <div class="mb-2">
          <button class="btn btn-outline-success btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNuevoCliente">
            <i class="bi bi-person-plus me-1"></i> Crear nuevo cliente
          </button>
        </div>
        <div class="collapse" id="collapseNuevoCliente">
          <div class="border rounded-3 p-3 bg-light">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Nombre completo</label>
                <input type="text" class="form-control" id="nuevo_nombre">
              </div>
              <div class="col-md-4">
                <label class="form-label">Teléfono (10 dígitos)</label>
                <input type="text" class="form-control" id="nuevo_telefono">
              </div>
              <div class="col-md-4">
                <label class="form-label">Correo</label>
                <input type="email" class="form-control" id="nuevo_correo">
              </div>
            </div>
            <div class="mt-3 text-end">
              <button type="button" class="btn btn-success" id="btn_guardar_nuevo_cliente">
                <i class="bi bi-check2-circle me-1"></i> Guardar y seleccionar
              </button>
            </div>
            <div class="form-text">
              El cliente se creará en la sucursal de esta venta (<?= htmlspecialchars($nomSucursal) ?>).
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
          Cerrar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(function(){
  const modalConfirm  = new bootstrap.Modal(document.getElementById('modalConfirmacion'));
  const modalClientes = new bootstrap.Modal(document.getElementById('modalClientes'));

  const $form        = $('#formVentaSim');
  const $simSel      = $('#selectSim');
  const $precio      = $('#precio');
  const $tipo        = $('#tipo_venta');
  const $coment      = $('#comentarios');
  const $tipoSimView = $('#tipoSimView');

  // ✅ Promo
  const $promo2x1    = $('#promo_2x1');

  const idSucursal = <?= (int)$idSucursal ?>;

  // Select2 SIMs
  $simSel.select2({
    placeholder: '-- Selecciona SIM --',
    width: '100%',
    language: { noResults: () => 'Sin resultados', searching: () => 'Buscando…' }
  });

  function actualizarTipo() {
    const $opt = $simSel.find(':selected');
    const operador = ($opt.data('operador') || '').toString().trim();
    $tipoSimView.val(operador || '');
  }
  actualizarTipo();
  $simSel.on('change', actualizarTipo);

  // Reglas de precio
  function ajustarAyudaPrecio(){
    if ($tipo.val() === 'Regalo') {
      $precio.val('0.00').prop('readonly', true).addClass('readonly-hint');
      $('#precio_help').text('Para “Regalo”, el precio es 0 y no se puede editar.');
    } else {
      $precio.prop('readonly', false).removeClass('readonly-hint');
      if ($tipo.val() === 'Nueva' || $tipo.val() === 'Portabilidad') {
        $('#precio_help').text('Para “Nueva” o “Portabilidad”, el precio debe ser mayor a 0.');
      } else { $('#precio_help').text('Define el precio de la SIM.'); }
    }
  }
  ajustarAyudaPrecio();

  // ✅ Promo 2X1
  const TAG_PROMO = 'Promoción 2X1';

  function limpiarTagPromo(txt){
    txt = (txt || '').toString().trim();
    const escTag = TAG_PROMO.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const re = new RegExp('(?:^|\\s*\\|\\s*)' + escTag + '(?=\\s*\\|\\s*|$)', 'gi');
    txt = txt.replace(re, '').trim();
    txt = txt.replace(/\s*\|\s*/g, ' | ').replace(/\s{2,}/g,' ').trim();
    txt = txt.replace(/^\|\s*/,'').replace(/\s*\|$/,'').trim();
    return txt;
  }

  function asegurarTagPromo(){
    let txt = limpiarTagPromo($coment.val());
    txt = txt.length ? (txt + ' | ' + TAG_PROMO) : TAG_PROMO;
    $coment.val(txt);
  }

  function aplicarReglaPromo(){
    if ($promo2x1.is(':checked')) {
      $tipo.val('Regalo');
      ajustarAyudaPrecio();
      $precio.val('0.00').prop('readonly', true).addClass('readonly-hint');
      asegurarTagPromo();
      $tipo.prop('disabled', true);
      $('#promo_help').text('Promo activa: tipo de venta forzado a “Regalo” y precio 0.');
    } else {
      $tipo.prop('disabled', false);
      $coment.val(limpiarTagPromo($coment.val()));
      ajustarAyudaPrecio();
      $('#promo_help').text('Si la marcas, se forzará el tipo de venta a “Regalo” y precio 0.');
    }
  }

  $tipo.on('change', function(){
    ajustarAyudaPrecio();
    if ($tipo.val() !== 'Regalo' && $promo2x1.is(':checked')) {
      $promo2x1.prop('checked', false);
      aplicarReglaPromo();
    }
  });

  $promo2x1.on('change', function(){
    aplicarReglaPromo();
  });

  // ========= LÓGICA DE CLIENTE =========
  function limpiarCliente() {
    $('#id_cliente').val('');
    $('#nombre_cliente').val('');
    $('#telefono_cliente').val('');
    $('#correo_cliente').val('');

    $('#cliente_resumen_nombre').text('Ninguno seleccionado');
    $('#cliente_resumen_detalle').html('Usa el botón <strong>Buscar / crear cliente</strong> para seleccionar uno.');
    $('#badge_tipo_cliente')
      .removeClass('text-bg-success')
      .addClass('text-bg-secondary')
      .html('<i class="bi bi-person-dash me-1"></i> Sin cliente');
  }

  function setClienteSeleccionado(c) {
    $('#id_cliente').val(c.id || '');
    $('#nombre_cliente').val(c.nombre || '');
    $('#telefono_cliente').val(c.telefono || '');
    $('#correo_cliente').val(c.correo || '');

    const nombre = c.nombre || '(Sin nombre)';
    const detParts = [];
    if (c.telefono) detParts.push('Tel: ' + c.telefono);
    if (c.codigo_cliente) detParts.push('Código: ' + c.codigo_cliente);
    if (c.correo) detParts.push('Correo: ' + c.correo);

    $('#cliente_resumen_nombre').text(nombre);
    $('#cliente_resumen_detalle').text(detParts.join(' · ') || 'Sin más datos.');

    $('#badge_tipo_cliente')
      .removeClass('text-bg-secondary')
      .addClass('text-bg-success')
      .html('<i class="bi bi-person-check me-1"></i> Cliente seleccionado');
  }

  $('#btn_open_modal_clientes').on('click', function() {
    $('#cliente_buscar_q').val('');
    $('#tbody_clientes').empty();
    $('#lbl_resultados_clientes').text('Sin buscar aún.');
    $('#collapseNuevoCliente').removeClass('show');
    modalClientes.show();
  });

  $('#btn_buscar_modal').on('click', function() {
    const q = $('#cliente_buscar_q').val().trim();
    if (!q) { alert('Escribe algo para buscar (nombre, teléfono o código).'); return; }

    $.post('ajax_clientes_buscar_modal.php', { q: q, id_sucursal: idSucursal }, function(res) {
      if (!res || !res.ok) { alert(res && res.message ? res.message : 'No se pudo buscar clientes.'); return; }

      const clientes = res.clientes || [];
      const $tbody = $('#tbody_clientes');
      $tbody.empty();

      if (clientes.length === 0) {
        $('#lbl_resultados_clientes').text('Sin resultados. Puedes crear un cliente nuevo.');
        return;
      }

      $('#lbl_resultados_clientes').text('Se encontraron ' + clientes.length + ' cliente(s).');

      clientes.forEach(function(c) {
        const $tr = $('<tr>');
        $tr.append($('<td>').text(c.codigo_cliente || '—'));
        $tr.append($('<td>').text(c.nombre || ''));
        $tr.append($('<td>').text(c.telefono || ''));
        $tr.append($('<td>').text(c.correo || ''));
        $tr.append($('<td>').text(c.fecha_alta || ''));
        const $btnSel = $('<button type="button" class="btn btn-sm btn-primary">')
          .html('<i class="bi bi-check2-circle me-1"></i> Seleccionar')
          .data('cliente', c)
          .on('click', function() {
            const cliente = $(this).data('cliente');
            setClienteSeleccionado(cliente);
            modalClientes.hide();
          });
        $tr.append($('<td>').append($btnSel));
        $tbody.append($tr);
      });
    }, 'json').fail(function() {
      alert('Error al buscar en la base de clientes.');
    });
  });

  $('#btn_guardar_nuevo_cliente').on('click', function() {
    const nombre = $('#nuevo_nombre').val().trim();
    let tel = $('#nuevo_telefono').val().trim();
    const correo = $('#nuevo_correo').val().trim();

    if (!nombre) { alert('Captura el nombre del cliente.'); return; }
    tel = tel.replace(/\D+/g, '');
    if (!/^\d{10}$/.test(tel)) { alert('El teléfono debe tener exactamente 10 dígitos.'); return; }

    $.post('ajax_crear_cliente.php', { nombre: nombre, telefono: tel, correo: correo, id_sucursal: idSucursal }, function(res) {
      if (!res || !res.ok) { alert(res && res.message ? res.message : 'No se pudo guardar el cliente.'); return; }

      const c = res.cliente || {};
      setClienteSeleccionado(c);
      modalClientes.hide();

      $('#nuevo_nombre').val('');
      $('#nuevo_telefono').val('');
      $('#nuevo_correo').val('');
      $('#collapseNuevoCliente').removeClass('show');

      alert(res.message || 'Cliente creado y vinculado.');
    }, 'json').fail(function(xhr) {
      alert('Error al guardar el cliente: ' + (xhr.responseText || 'desconocido'));
    });
  });

  // ========= Validación + Modal =========
  let allowSubmit = false;

  function validar() {
    const errores = [];
    const idSim = $simSel.val();
    const tipo  = $tipo.val();
    const precio = parseFloat($precio.val());

    if (!idSim) errores.push('Selecciona una SIM disponible.');
    if (!tipo) errores.push('Selecciona el tipo de venta.');

    if (tipo === 'Regalo') {
      if (isNaN(precio) || Number(precio.toFixed(2)) !== 0) errores.push('En “Regalo”, el precio debe ser exactamente 0.');
    } else {
      if (isNaN(precio) || precio <= 0) errores.push('El precio debe ser mayor a 0 para Nueva/Portabilidad.');
    }

    const idCliente   = ($('#id_cliente').val() || '').trim();
    const nombreCli   = ($('#nombre_cliente').val() || '').trim();
    const telCliente  = ($('#telefono_cliente').val() || '').trim();

    if (!idCliente || !nombreCli) errores.push('Debes seleccionar un cliente para registrar la venta.');

    if (tipo === 'Portabilidad') {
      if (!telCliente) errores.push('El cliente debe tener teléfono registrado.');
      else if (!/^\d{10}$/.test(telCliente)) errores.push('El teléfono del cliente debe tener 10 dígitos.');
    }

    return errores;
  }

  function poblarModal(){
    const $opt = $simSel.find(':selected');
    const iccid = ($opt.data('iccid') || '').toString();
    const operador = ($opt.data('operador') || '').toString();
    const tipo = $tipo.val() || '—';

    aplicarReglaPromo();

    const precio = parseFloat($precio.val()) || 0;
    const comentarios = ($coment.val() || '').trim();

    $('#conf_iccid').text(iccid || '—');
    $('#conf_operador').text(operador || '—');
    $('#conf_tipo').text(tipo);
    $('#conf_precio').text(precio.toFixed(2));
    $('#conf_comentarios').text(comentarios || '—');

    const nombreCliente = $('#cliente_resumen_nombre').text() || '—';
    $('#conf_cliente').text(nombreCliente);
  }

  $form.on('submit', function(e){
    if (allowSubmit) return;
    e.preventDefault();
    const errs = validar();
    if (errs.length) { alert('Corrige lo siguiente:\n• ' + errs.join('\n• ')); return; }
    poblarModal(); modalConfirm.show();
  });

  $('#btn_confirmar_envio').on('click', function(){
    if ($tipo.is(':disabled')) $tipo.prop('disabled', false);
    $('#btn_submit').prop('disabled', true).text('Enviando...');
    allowSubmit = true; modalConfirm.hide(); $form[0].submit();
  });

  limpiarCliente();
  aplicarReglaPromo();
});
</script>

<!-- Bootstrap bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>