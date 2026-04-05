<?php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array(($_SESSION['rol'] ?? ''), ['Admin','Subdis_Admin'], true)) {
  header("Location: 403.php");
  exit();
}

require_once __DIR__ . '/db.php';


$ROL = $_SESSION['rol'] ?? '';
$isAdmin = ($ROL === 'Admin');
$isSubdisAdmin = ($ROL === 'Subdis_Admin');

$id_subdis = (int)($_SESSION['id_subdis'] ?? 0);
if ($isSubdisAdmin && $id_subdis <= 0) {
  header("Location: 403.php");
  exit();
}


/* =======================
   Helpers
   ======================= */
function hasColumn(mysqli $conn, string $table, string $column): bool
{
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$t}'
              AND COLUMN_NAME = '{$c}'
            LIMIT 1";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

/* =======================
   Scope multi-tenant (filtros)
   ======================= */
$SCOPE_PROP = $isSubdisAdmin ? 'SUBDIS' : 'LUGA';

$DS_HAS_PROP   = hasColumn($conn, 'depositos_sucursal', 'propiedad');
$DS_HAS_SUBDIS = hasColumn($conn, 'depositos_sucursal', 'id_subdis');
$CC_HAS_PROP   = hasColumn($conn, 'cortes_caja', 'propiedad');
$CC_HAS_SUBDIS = hasColumn($conn, 'cortes_caja', 'id_subdis');
$SUC_HAS_SUB   = hasColumn($conn, 'sucursales', 'id_subdis');

$scopeDS = '';          // usa alias ds.
$scopeDS_noalias = '';  // sin alias (para UPDATE/WHERE simple)
$scopeCC = '';          // usa alias cc.

if ($DS_HAS_PROP) {
  if ($SCOPE_PROP === 'LUGA') {
    $scopeDS = " AND ds.propiedad = 'LUGA' ";
    $scopeDS_noalias = " AND propiedad = 'LUGA' ";
    if ($DS_HAS_SUBDIS) {
      $scopeDS .= " AND (ds.id_subdis IS NULL OR ds.id_subdis = 0) ";
      $scopeDS_noalias .= " AND (id_subdis IS NULL OR id_subdis = 0) ";
    }
  } else {
    $scopeDS = " AND ds.propiedad = 'SUBDIS' ";
    $scopeDS_noalias = " AND propiedad = 'SUBDIS' ";
    if ($DS_HAS_SUBDIS) {
      $scopeDS .= " AND ds.id_subdis = ".(int)$id_subdis." ";
      $scopeDS_noalias .= " AND id_subdis = ".(int)$id_subdis." ";
    }
  }
}

if ($CC_HAS_PROP) {
  if ($SCOPE_PROP === 'LUGA') {
    $scopeCC = " AND cc.propiedad = 'LUGA' ";
    if ($CC_HAS_SUBDIS) $scopeCC .= " AND (cc.id_subdis IS NULL OR cc.id_subdis = 0) ";
  } else {
    $scopeCC = " AND cc.propiedad = 'SUBDIS' ";
    if ($CC_HAS_SUBDIS) $scopeCC .= " AND cc.id_subdis = ".(int)$id_subdis." ";
  }
}


function csv_escape($v)
{
  $v = (string)$v;
  $v = str_replace(["\r", "\n"], [' ', ' '], $v);
  $needs = strpbrk($v, ",\"\t") !== false;
  return $needs ? '"' . str_replace('"', '""', $v) . '"' : $v;
}

function renderDetalleCorteHTML(mysqli $conn, int $idCorte): string
{
  // Cobros
  $qc = $conn->prepare("
      SELECT cb.id, cb.motivo, cb.tipo_pago, cb.monto_total, cb.monto_efectivo, cb.monto_tarjeta,
             cb.comision_especial, cb.fecha_cobro, u.nombre AS ejecutivo
      FROM cobros cb
      LEFT JOIN usuarios u ON u.id = cb.id_usuario
      WHERE cb.id_corte = ?
      ORDER BY cb.fecha_cobro ASC, cb.id ASC
    ");
  $qc->bind_param('i', $idCorte);
  $qc->execute();
  $rowsCobros = $qc->get_result()->fetch_all(MYSQLI_ASSOC);
  $qc->close();

  // Depósitos
  $qd = $conn->prepare("
      SELECT ds.id, ds.monto_depositado, ds.banco, ds.referencia, ds.estado, ds.fecha_deposito,
             ds.comprobante_archivo, ds.comentario_admin, s.nombre AS sucursal
      FROM depositos_sucursal ds
      INNER JOIN sucursales s ON s.id = ds.id_sucursal
      WHERE ds.id_corte = ?
      $scopeDS
      ORDER BY ds.id ASC
    ");
  $qd->bind_param('i', $idCorte);
  $qd->execute();
  $rowsDep = $qd->get_result()->fetch_all(MYSQLI_ASSOC);
  $qd->close();

  ob_start(); ?>
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h5 class="mb-0"><i class="bi bi-list-ul me-1"></i> Detalle del corte #<?= (int)$idCorte ?></h5>
    <a class="btn btn-outline-success btn-sm" href="?export=csv_corte&id=<?= (int)$idCorte ?>" target="_blank">
      <i class="bi bi-filetype-csv me-1"></i> Exportar CSV
    </a>
  </div>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="fw-semibold mb-2"><i class="bi bi-receipt"></i> Cobros del corte</div>
      <div class="table-responsive">
        <table class="table table-sm table-striped mb-0">
          <thead>
            <tr>
              <th>ID Cobro</th>
              <th>Fecha/Hora</th>
              <th>Ejecutivo</th>
              <th>Motivo</th>
              <th>Tipo pago</th>
              <th class="text-end">Total</th>
              <th class="text-end">Efectivo</th>
              <th class="text-end">Tarjeta</th>
              <th class="text-end">Com. Esp.</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rowsCobros as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= htmlspecialchars($r['fecha_cobro']) ?></td>
                <td><?= htmlspecialchars($r['ejecutivo'] ?? 'N/D') ?></td>
                <td><?= htmlspecialchars($r['motivo']) ?></td>
                <td><?= htmlspecialchars($r['tipo_pago']) ?></td>
                <td class="text-end">$<?= number_format($r['monto_total'], 2) ?></td>
                <td class="text-end">$<?= number_format($r['monto_efectivo'], 2) ?></td>
                <td class="text-end">$<?= number_format($r['monto_tarjeta'], 2) ?></td>
                <td class="text-end">$<?= number_format($r['comision_especial'], 2) ?></td>
              </tr>
            <?php endforeach;
            if (!$rowsCobros): ?>
              <tr>
                <td colspan="9" class="text-muted">Sin cobros ligados a este corte.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="fw-semibold mb-2"><i class="bi bi-bank"></i> Depósitos del corte</div>
      <div class="table-responsive">
        <table class="table table-sm table-striped mb-0">
          <thead>
            <tr>
              <th>ID Dep.</th>
              <th>Sucursal</th>
              <th class="text-end">Monto</th>
              <th>Banco</th>
              <th>Ref</th>
              <th>Estado</th>
              <th>Comp.</th>
              <th class="text-center">Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rowsDep as $d):
              $estado = (string)$d['estado'];
              $badge = 'bg-warning text-dark';
              if ($estado === 'Validado') $badge = 'bg-success';
              elseif ($estado === 'Correccion') $badge = 'bg-danger';
            ?>
              <tr class="<?= $estado === 'Validado' ? 'table-success' : '' ?>">
                <td><?= (int)$d['id'] ?></td>
                <td><?= htmlspecialchars($d['sucursal']) ?></td>
                <td class="text-end">$<?= number_format($d['monto_depositado'], 2) ?></td>
                <td><?= htmlspecialchars($d['banco']) ?></td>
                <td><code><?= htmlspecialchars($d['referencia']) ?></code></td>
                <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($estado) ?></span></td>
                <td>
                  <?php if (!empty($d['comprobante_archivo'])): ?>
                    <button class="btn btn-outline-primary btn-sm js-ver"
                      data-src="deposito_comprobante.php?id=<?= (int)$d['id'] ?>"
                      data-bs-toggle="modal" data-bs-target="#visorModal">
                      Ver
                    </button>
                  <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td class="text-center">
                  <?php if ($estado === 'Pendiente'): ?>
                    <form method="POST"
                      class="d-inline js-validar-deposito-modal"
                      data-id="<?= (int)$d['id'] ?>"
                      data-sucursal="<?= htmlspecialchars($d['sucursal'], ENT_QUOTES) ?>"
                      data-monto="<?= number_format($d['monto_depositado'], 2) ?>">
                      <input type="hidden" name="id_deposito" value="<?= (int)$d['id'] ?>">
                      <button type="submit" name="accion" value="Validar" class="btn btn-success btn-sm">
                        Validar
                      </button>
                    </form>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach;
            if (!$rowsDep): ?>
              <tr>
                <td colspan="8" class="text-muted">Sin depósitos ligados a este corte.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php
  return ob_get_clean();
}

// /* =======================
//    Auto-migración segura
//    ======================= */
// if (!hasColumn($conn, 'depositos_sucursal', 'comentario_admin')) {
//   @$conn->query("ALTER TABLE depositos_sucursal
//                    ADD COLUMN comentario_admin TEXT NULL AFTER referencia");
// }

// // Columnas para flujo de corrección
// if (!hasColumn($conn, 'depositos_sucursal', 'correccion_motivo')) {
//   @$conn->query("ALTER TABLE depositos_sucursal
//                    ADD COLUMN correccion_motivo TEXT NULL AFTER comentario_admin");
// }
// if (!hasColumn($conn, 'depositos_sucursal', 'correccion_solicitada_en')) {
//   @$conn->query("ALTER TABLE depositos_sucursal
//                    ADD COLUMN correccion_solicitada_en DATETIME NULL AFTER correccion_motivo");
// }
// if (!hasColumn($conn, 'depositos_sucursal', 'correccion_solicitada_por')) {
//   @$conn->query("ALTER TABLE depositos_sucursal
//                    ADD COLUMN correccion_solicitada_por INT(11) NULL AFTER correccion_solicitada_en");
// }
// if (!hasColumn($conn, 'depositos_sucursal', 'correccion_resuelta_en')) {
//   @$conn->query("ALTER TABLE depositos_sucursal
//                    ADD COLUMN correccion_resuelta_en DATETIME NULL AFTER correccion_solicitada_por");
// }
// if (!hasColumn($conn, 'depositos_sucursal', 'correccion_resuelta_por')) {
//   @$conn->query("ALTER TABLE depositos_sucursal
//                    ADD COLUMN correccion_resuelta_por INT(11) NULL AFTER correccion_resuelta_en");
// }

// // Intento seguro de extender ENUM para permitir 'Correccion' (si ya existe, no pasa nada)
// @$conn->query("ALTER TABLE depositos_sucursal
//                 MODIFY estado ENUM('Pendiente','Parcial','Correccion','Validado')
//                 NOT NULL DEFAULT 'Pendiente'");

/* =======================
   AJAX flag (para POST y GET)
   ======================= */
$esAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
  strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

/* ======================================================
   1) AJAX DETALLE DE CORTE (GET) — SIN NAVBAR
   ====================================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'detalle_corte') {
  $id = (int)($_GET['id'] ?? 0);
  header('Content-Type: text/html; charset=UTF-8');
  if ($id > 0) {
    echo renderDetalleCorteHTML($conn, $id);
  } else {
    echo '<div class="alert alert-warning mb-0">Corte inválido.</div>';
  }
  exit;
}

/* ======================================================
   2) EXPORT CSV DEL CORTE (GET) — SIN NAVBAR
   ====================================================== */
if (isset($_GET['export']) && $_GET['export'] === 'csv_corte') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id <= 0) {
    http_response_code(400);
    echo 'Corte inválido';
    exit;
  }

  $meta = $conn->query("
      SELECT cc.id, s.nombre AS sucursal, cc.fecha_operacion, cc.fecha_corte,
             cc.total_efectivo, cc.total_tarjeta, cc.total_comision_especial, cc.total_general
      FROM cortes_caja cc
      INNER JOIN sucursales s ON s.id = cc.id_sucursal
      WHERE cc.id = {$id}
      LIMIT 1
    ")->fetch_assoc();

  $qc = $conn->prepare("
      SELECT cb.id, cb.fecha_cobro, u.nombre AS ejecutivo, cb.motivo, cb.tipo_pago,
             cb.monto_total, cb.monto_efectivo, cb.monto_tarjeta, cb.comision_especial
      FROM cobros cb
      LEFT JOIN usuarios u ON u.id = cb.id_usuario
      WHERE cb.id_corte = ?
      ORDER BY cb.fecha_cobro ASC, cb.id ASC
    ");
  $qc->bind_param('i', $id);
  $qc->execute();
  $rowsCobros = $qc->get_result()->fetch_all(MYSQLI_ASSOC);
  $qc->close();

  $qd = $conn->prepare("
      SELECT ds.id, s.nombre AS sucursal, ds.monto_depositado, ds.banco, ds.referencia,
             ds.estado, ds.fecha_deposito
      FROM depositos_sucursal ds
      INNER JOIN sucursales s ON s.id = ds.id_sucursal
      WHERE ds.id_corte = ?
      ORDER BY ds.id ASC
    ");
  $qd->bind_param('i', $id);
  $qd->execute();
  $rowsDep = $qd->get_result()->fetch_all(MYSQLI_ASSOC);
  $qd->close();

  while (ob_get_level()) {
    ob_end_clean();
  }

  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="corte_' . $id . '_detalle.csv"');
  header('Pragma: no-cache');
  header('Expires: 0');

  $out = fopen('php://output', 'w');
  fputs($out, "\xEF\xBB\xBF"); // BOM

  if ($meta) {
    fputs($out, "Corte," . csv_escape($meta['id']) . ",Sucursal," . csv_escape($meta['sucursal']) . ",Fecha Operación," . csv_escape($meta['fecha_operacion']) . ",Fecha Corte," . csv_escape($meta['fecha_corte']) . "\r\n");
    fputs($out, "Total Efectivo," . csv_escape($meta['total_efectivo']) . ",Total Tarjeta," . csv_escape($meta['total_tarjeta']) . ",Com. Esp.," . csv_escape($meta['total_comision_especial']) . ",Total General," . csv_escape($meta['total_general']) . "\r\n\r\n");
  }

  fputs($out, "Sección,Cobros\r\n");
  fputcsv($out, ['ID Cobro', 'Fecha/Hora', 'Ejecutivo', 'Motivo', 'Tipo pago', 'Total', 'Efectivo', 'Tarjeta', 'Com. Esp.']);
  foreach ($rowsCobros as $r) {
    fputcsv($out, [
      $r['id'],
      $r['fecha_cobro'],
      $r['ejecutivo'],
      $r['motivo'],
      $r['tipo_pago'],
      $r['monto_total'],
      $r['monto_efectivo'],
      $r['monto_tarjeta'],
      $r['comision_especial']
    ]);
  }
  fputs($out, "\r\n");

  fputs($out, "Sección,Depósitos\r\n");
  fputcsv($out, ['ID Depósito', 'Sucursal', 'Monto', 'Banco', 'Referencia', 'Estado', 'Fecha Depósito']);
  foreach ($rowsDep as $d) {
    fputcsv($out, [
      $d['id'],
      $d['sucursal'],
      $d['monto_depositado'],
      $d['banco'],
      $d['referencia'],
      $d['estado'],
      $d['fecha_deposito']
    ]);
  }
  fclose($out);
  exit;
}

/* ======================================================
   3) POST (Validar / Guardar comentario / Pedir corrección)
   ====================================================== */
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_deposito'], $_POST['accion'])) {
  $idDeposito = intval($_POST['id_deposito']);
  $accion     = (string)$_POST['accion'];

  if ($accion === 'Validar') {
    $ok = false;

    // 1) Marcar depósito como validado SOLO si está en Pendiente
    $stmt = $conn->prepare("
            UPDATE depositos_sucursal
            SET estado='Validado',
                id_admin_valida = ?,
                actualizado_en  = NOW()
            WHERE id = ? AND estado = 'Pendiente' $scopeDS_noalias
        ");
    $stmt->bind_param("ii", $_SESSION['id_usuario'], $idDeposito);
    $stmt->execute();
    $ok = ($stmt->affected_rows > 0);
    $stmt->close();

    // 2) Cierre de corte si procede
    if ($ok) {
      $sqlCorte = "
                SELECT ds.id_corte, cc.total_efectivo,
                       IFNULL(SUM(ds2.monto_depositado),0) AS suma_depositos
                FROM depositos_sucursal ds
                INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
                INNER JOIN depositos_sucursal ds2
                        ON ds2.id_corte = ds.id_corte
                       AND ds2.estado  = 'Validado'
                WHERE ds.id = ?
                GROUP BY ds.id_corte
            ";
      $stmtCorte = $conn->prepare($sqlCorte);
      $stmtCorte->bind_param("i", $idDeposito);
      $stmtCorte->execute();
      $corteData = $stmtCorte->get_result()->fetch_assoc();
      $stmtCorte->close();

      if ($corteData && $corteData['suma_depositos'] >= $corteData['total_efectivo']) {
        $stmtClose = $conn->prepare("
                    UPDATE cortes_caja
                    SET estado          = 'Cerrado',
                        depositado      = 1,
                        monto_depositado = ?,
                        fecha_deposito   = NOW()
                    WHERE id = ?
                ");
        $stmtClose->bind_param("di", $corteData['suma_depositos'], $corteData['id_corte']);
        $stmtClose->execute();
        $stmtClose->close();
      }
    }

    if ($esAjax) {
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode([
        'ok'      => $ok,
        'message' => $ok ? 'Depósito validado correctamente.' : 'No se pudo validar (puede que ya no esté en Pendiente).'
      ], JSON_UNESCAPED_UNICODE);
      exit;
    } else {
      $msg = $ok
        ? "<div class='alert alert-success mb-3'>✅ Depósito validado correctamente.</div>"
        : "<div class='alert alert-danger mb-3'>❌ No se pudo validar (puede que ya no esté en Pendiente).</div>";
    }
  } elseif ($accion === 'GuardarComentario') {
    $comentario = trim($_POST['comentario_admin'] ?? '');
    $stmt = $conn->prepare("
            UPDATE depositos_sucursal
            SET comentario_admin = ?, actualizado_en = NOW()
            WHERE id = ?
        ");
    $stmt->bind_param("si", $comentario, $idDeposito);
    $stmt->execute();

    if ($esAjax) {
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
      exit;
    } else {
      $msg = "<div class='alert alert-primary mb-3'>📝 Comentario guardado.</div>";
    }
  } elseif ($accion === 'PedirCorreccion') {
    $motivo = trim($_POST['correccion_motivo'] ?? '');

    if ($motivo === '' || mb_strlen($motivo) < 5) {
      $ok = false;
      $msg = "<div class='alert alert-warning mb-3'>⚠️ Escribe un motivo de corrección (mínimo 5 caracteres).</div>";
      if ($esAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'message' => 'Motivo inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
      }
    } else {
      $stmt = $conn->prepare("
                UPDATE depositos_sucursal
                SET estado='Correccion',
                    correccion_motivo = ?,
                    correccion_solicitada_en = NOW(),
                    correccion_solicitada_por = ?,
                    actualizado_en = NOW()
                WHERE id = ?
                  AND estado IN ('Pendiente','Correccion')
            ");
      $adminId = (int)$_SESSION['id_usuario'];
      $stmt->bind_param("sii", $motivo, $adminId, $idDeposito);
      $stmt->execute();
      $ok = ($stmt->affected_rows > 0);
      $stmt->close();

      if ($esAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
          'ok' => $ok,
          'message' => $ok ? 'Corrección solicitada. La sucursal debe re-subir el comprobante.' : 'No se pudo solicitar corrección.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
      } else {
        $msg = $ok
          ? "<div class='alert alert-warning mb-3'>🟠 Corrección solicitada. La sucursal debe re-subir el comprobante.</div>"
          : "<div class='alert alert-danger mb-3'>❌ No se pudo solicitar corrección.</div>";
      }
    }
  }
}

/* ======================================================
   4) A PARTIR DE AQUÍ YA PODEMOS PINTAR NAVBAR + HTML
   ====================================================== */
require_once __DIR__ . '/navbar.php';

/* =======================
   Consultas principales
   ======================= */

// Pendientes incluye Pendiente + Correccion (Correccion arriba)
$sqlPendientes = "
    SELECT ds.id AS id_deposito,
           s.nombre AS sucursal,
           ds.id_corte,
           cc.fecha_corte,
           cc.total_efectivo,
           ds.monto_depositado,
           ds.banco,
           ds.referencia,
           ds.estado,
           ds.comprobante_archivo,
           ds.comentario_admin,
           ds.correccion_motivo,
           ds.correccion_solicitada_en
    FROM depositos_sucursal ds
    INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
    INNER JOIN sucursales s ON s.id = ds.id_sucursal
    WHERE ds.estado IN ('Pendiente','Correccion')
    $scopeDS
    $scopeCC
    ORDER BY FIELD(ds.estado,'Correccion','Pendiente') ASC, cc.fecha_corte ASC, ds.id_corte ASC, ds.id ASC
";
$pendientes = $conn->query($sqlPendientes)->fetch_all(MYSQLI_ASSOC);


if ($isSubdisAdmin) {
  if ($SUC_HAS_SUB) {
    $sucursales = $conn->query("SELECT id, nombre FROM sucursales WHERE id_subdis = ".(int)$id_subdis." ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
  } else {
    $sucursales = [];
  }
} else {
  $sucursales = $conn->query("SELECT id, nombre FROM sucursales ORDER BY nombre")->fetch_all(MYSQLI_ASSOC);
}


$sucursal_id = isset($_GET['sucursal_id']) ? (int)$_GET['sucursal_id'] : 0;
if ($isSubdisAdmin && $sucursal_id > 0) {
  if ($SUC_HAS_SUB) {
    $chk = $conn->prepare("SELECT 1 FROM sucursales WHERE id=? AND id_subdis=? LIMIT 1");
    $chk->bind_param('ii', $sucursal_id, $id_subdis);
    $chk->execute();
    $ok = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$ok) { $sucursal_id = 0; }
  } else {
    $sucursal_id = 0;
  }
}

$desde       = trim($_GET['desde'] ?? '');
$hasta       = trim($_GET['hasta'] ?? '');
$semana      = trim($_GET['semana'] ?? '');

if ($semana && preg_match('/^(\d{4})-W(\d{2})$/', $semana, $m)) {
  $yr = (int)$m[1];
  $wk = (int)$m[2];
  $dt = new DateTime();
  $dt->setISODate($yr, $wk);
  $desde = $dt->format('Y-m-d');
  $dt->modify('+6 days');
  $hasta = $dt->format('Y-m-d');
}

$sqlHistorial = "
    SELECT ds.id AS id_deposito,
           s.nombre AS sucursal,
           ds.id_corte,
           cc.fecha_corte,
           ds.fecha_deposito,
           ds.monto_depositado,
           ds.banco,
           ds.referencia,
           ds.estado,
           ds.comprobante_archivo,
           ds.comentario_admin,
           ds.correccion_motivo,
           ds.correccion_solicitada_en
    FROM depositos_sucursal ds
    INNER JOIN cortes_caja cc ON cc.id = ds.id_corte
    INNER JOIN sucursales s ON s.id = ds.id_sucursal
    WHERE 1=1
      $scopeDS
      $scopeCC
";
$types = '';
$params = [];
if ($sucursal_id > 0) {
  $sqlHistorial .= " AND s.id = ? ";
  $types .= 'i';
  $params[] = $sucursal_id;
}
if ($desde !== '') {
  $sqlHistorial .= " AND DATE(ds.fecha_deposito) >= ? ";
  $types .= 's';
  $params[] = $desde;
}
if ($hasta !== '') {
  $sqlHistorial .= " AND DATE(ds.fecha_deposito) <= ? ";
  $types .= 's';
  $params[] = $hasta;
}
$sqlHistorial .= " ORDER BY ds.fecha_deposito DESC, ds.id DESC";
$stmtH = $conn->prepare($sqlHistorial);
if ($types) {
  $stmtH->bind_param($types, ...$params);
}
$stmtH->execute();
$historial = $stmtH->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtH->close();

$c_sucursal_id = isset($_GET['c_sucursal_id']) ? (int)$_GET['c_sucursal_id'] : 0;
if ($isSubdisAdmin && $c_sucursal_id > 0) {
  if ($SUC_HAS_SUB) {
    $chk2 = $conn->prepare("SELECT 1 FROM sucursales WHERE id=? AND id_subdis=? LIMIT 1");
    $chk2->bind_param('ii', $c_sucursal_id, $id_subdis);
    $chk2->execute();
    $ok2 = $chk2->get_result()->fetch_assoc();
    $chk2->close();
    if (!$ok2) { $c_sucursal_id = 0; }
  } else {
    $c_sucursal_id = 0;
  }
}

$c_desde       = trim($_GET['c_desde'] ?? '');
$c_hasta       = trim($_GET['c_hasta'] ?? '');

$sqlCortes = "
  SELECT cc.id,
         s.nombre AS sucursal,
         cc.fecha_operacion,
         cc.fecha_corte,
         cc.estado,
         cc.total_efectivo,
         cc.total_tarjeta,
         cc.total_comision_especial,
         cc.total_general,
         cc.depositado,
         cc.monto_depositado,
         (SELECT COUNT(*) FROM cobros cb WHERE cb.id_corte = cc.id) AS num_cobros
  FROM cortes_caja cc
  INNER JOIN sucursales s ON s.id = cc.id_sucursal
  WHERE 1=1
      $scopeCC
";
$typesC = '';
$paramsC = [];
if ($c_sucursal_id > 0) {
  $sqlCortes .= " AND cc.id_sucursal = ? ";
  $typesC .= 'i';
  $paramsC[] = $c_sucursal_id;
}
if ($c_desde !== '') {
  $sqlCortes .= " AND cc.fecha_operacion >= ? ";
  $typesC .= 's';
  $paramsC[] = $c_desde;
}
if ($c_hasta !== '') {
  $sqlCortes .= " AND cc.fecha_operacion <= ? ";
  $typesC .= 's';
  $paramsC[] = $c_hasta;
}
$sqlCortes .= " ORDER BY cc.fecha_operacion DESC, cc.id DESC";
$stmtC = $conn->prepare($sqlCortes);
if ($typesC) {
  $stmtC->bind_param($typesC, ...$paramsC);
}
$stmtC->execute();
$cortes = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtC->close();

/* =======================
   Métricas UI
   ======================= */
$pendCount = count($pendientes);
$pendMonto = 0.0;
foreach ($pendientes as $p) {
  $pendMonto += (float)$p['monto_depositado'];
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8" />
  <title>Validación de Depósitos</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    :root {
      --brand: #0d6efd;
      --bg1: #f8fafc;
      --ink: #0f172a;
      --muted: #64748b;
      --soft: #eef2ff;
    }

    body {
      background:
        radial-gradient(1200px 400px at 120% -50%, rgba(13, 110, 253, .07), transparent),
        radial-gradient(1000px 380px at -10% 120%, rgba(25, 135, 84, .06), transparent),
        var(--bg1);
    }

    .page-title {
      font-weight: 800;
      letter-spacing: .2px;
      color: var(--ink);
    }

    .card-elev {
      border: 0;
      border-radius: 1rem;
      box-shadow: 0 10px 24px rgba(15, 23, 42, .06), 0 2px 6px rgba(15, 23, 42, .05);
    }

    .section-title {
      font-size: .95rem;
      font-weight: 700;
      color: #334155;
      letter-spacing: .6px;
      text-transform: uppercase;
      display: flex;
      align-items: center;
      gap: .5rem;
    }

    .badge-soft {
      background: var(--soft);
      color: #1e40af;
      border: 1px solid #dbeafe;
    }

    .help-text {
      color: var(--muted);
      font-size: .9rem;
    }

    .table thead th {
      position: sticky;
      top: 0;
      background: #0f172a;
      color: #fff;
      z-index: 1;
    }

    .table-hover tbody tr:hover {
      background: rgba(13, 110, 253, .06);
    }

    .table-xs td,
    .table-xs th {
      padding: .45rem .6rem;
      font-size: .92rem;
      vertical-align: middle;
    }

    .nav-tabs .nav-link {
      border: 0;
      border-bottom: 2px solid transparent;
    }

    .nav-tabs .nav-link.active {
      border-bottom-color: var(--brand);
      font-weight: 700;
    }

    .sticky-actions {
      position: sticky;
      bottom: 0;
      background: #fff;
      padding: .5rem;
      border-top: 1px solid #e5e7eb;
    }

    code {
      background: #f1f5f9;
      padding: .1rem .35rem;
      border-radius: .35rem;
    }

    .comment-cell textarea {
      min-width: 240px;
      min-height: 38px;
    }

    @media (max-width: 992px) {
      .comment-cell textarea {
        min-width: 160px;
      }
    }

    .loading-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, .35);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 2000;
    }

    .loading-card {
      background: #fff;
      border-radius: .75rem;
      padding: 1.25rem 1.5rem;
      box-shadow: 0 10px 24px rgba(15, 23, 42, .18);
      display: flex;
      align-items: center;
      gap: .75rem;
    }

    .spinner {
      width: 26px;
      height: 26px;
      border: 3px solid #e5e7eb;
      border-top-color: var(--brand);
      border-radius: 50%;
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    #visorWrap {
      position: relative;
    }

    #visorSpinner {
      position: absolute;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      background: rgba(255, 255, 255, .65);
      z-index: 5;
    }

    #detalleCorteModal .modal-dialog {
      max-width: 80vw;
    }

    #detalleCorteModal .modal-content {
      height: 80vh;
    }

    #detalleCorteModal .modal-body {
      overflow: auto;
    }

    #detalleCorteModal .navbar,
    #detalleCorteBody .navbar {
      display: none !important;
      height: 0 !important;
      overflow: hidden !important;
    }

    #detalleCorteModal table thead th {
      position: sticky;
      top: 0;
      background: #0f172a;
      color: #fff;
      z-index: 2;
    }

    .badge-correccion {
      background: #dc2626;
    }

    .row-correccion {
      background: rgba(220, 38, 38, .06);
    }

    /* ====== FIX producción: evitar texto “vertical” en Sucursal ====== */
    .table-fit {
      width: 100%;
      table-layout: auto;
      /* <- NO fixed */
    }

    .table-fit th,
    .table-fit td {
      white-space: nowrap;
      /* <- por defecto NO romper */
      word-break: normal;
      overflow-wrap: normal;
      vertical-align: middle;
    }

    /* Permitir wrap SOLO en columnas que sí deben crecer hacia abajo */
    .table-fit td.comment-cell,
    .table-fit td.comment-cell *,
    .table-fit td.col-estado,
    .table-fit td.col-estado * {
      white-space: normal !important;
      overflow-wrap: anywhere;
    }

    /* Anchos mínimos para evitar columnas micro */
    .table-fit th:nth-child(2),
    .table-fit td:nth-child(2) {
      /* Sucursal */
      min-width: 200px;
    }

    .table-fit th:nth-child(8),
    .table-fit td:nth-child(8) {
      /* Comprobante */
      min-width: 140px;
    }

    .table-fit th:nth-child(9),
    .table-fit td:nth-child(9) {
      /* Comentario */
      min-width: 260px;
    }

    .table-fit th:nth-child(10),
    .table-fit td:nth-child(10) {
      /* Estado/Corrección */
      min-width: 220px;
    }

    .table-fit th:nth-child(11),
    .table-fit td:nth-child(11) {
      /* Acciones */
      min-width: 240px;
    }

    /* En desktop deja scroll si hace falta, mejor que deformar */
    .table-responsive {
      overflow-x: auto;
    }

    /* ===== Acciones SIEMPRE visibles (sticky a la derecha) ===== */
    .table-responsive {
      overflow-x: auto;
    }

    /* Marca la última columna como sticky right */
    .table-fit th.col-acciones,
    .table-fit td.col-acciones {
      position: sticky;
      right: 0;
      z-index: 3;
      background: #fff;
      box-shadow: -10px 0 14px rgba(15, 23, 42, .06);
      border-left: 1px solid #e5e7eb;
    }

    /* Encabezado sticky: que también se pegue bien */
    .table-fit thead th.col-acciones {
      background: #0f172a !important;
      color: #fff !important;
      z-index: 5;
    }

    /* Cuando la fila es "Correccion", que la col sticky respete el color */
    .table-fit tbody tr.row-correccion td.col-acciones {
      background: rgba(220, 38, 38, .06) !important;
    }

    /* Layout de botones: vertical, grande, y abajo */
    .table-fit td.col-acciones .actions-cell {
      min-width: 240px;
      display: flex;
      flex-direction: column;
      gap: .5rem;
      align-items: stretch;
      justify-content: flex-end;
    }

    .table-fit td.col-acciones .btn {
      width: 100%;
    }

    /* Opcional: que el textarea no crezca feo */
    .comment-cell textarea {
      min-height: 44px;
    }
  </style>
</head>

<body>

  <div class="container-fluid my-4 px-3 px-xl-4">


    <div class="d-flex flex-wrap justify-content-between align-items-end mb-3">
      <div>
        <h2 class="page-title mb-1"><i class="bi bi-bank2 me-2"></i>Validación de Depósitos
          <span class="badge rounded-pill text-bg-light border ms-2 align-middle" style="font-weight:700;">
            <?= ($isSubdisAdmin ? ('SUBDIS #'.(int)$id_subdis) : 'LUGA') ?>
          </span>
        </h2>
        <div class="help-text">Administra <b>pendientes</b>, consulta <b>historial</b>, revisa <b>cortes</b> y <b>saldos</b>. Ahora con flujo de <b>correcciones</b>.</div>
      </div>
      <?php if (!empty($msg)) echo $msg; ?>
    </div>

    <!-- EXPORTAR POR DÍA -->
    <div class="card card-elev mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div class="section-title mb-0"><i class="bi bi-download"></i> Exportar transacciones por día</div>
        <span class="help-text">Cobros de todas las sucursales en CSV</span>
      </div>
      <div class="card-body">
        <form class="row g-2 align-items-end" method="get" action="export_transacciones_dia.php" target="_blank">
          <div class="col-sm-4 col-md-3">
            <label class="form-label mb-0">Día</label>
            <input type="date" name="dia" class="form-control" required>
          </div>
          <div class="col-sm-8 col-md-5">
            <div class="help-text">Descarga el detalle de <b>cobros</b> del día seleccionado, con sucursal y ejecutivo.</div>
          </div>
          <div class="col-md-4 text-end">
            <button class="btn btn-outline-success"><i class="bi bi-filetype-csv me-1"></i> Descargar CSV</button>
          </div>
        </form>
      </div>
    </div>

    <!-- TABS -->
    <ul class="nav nav-tabs mb-3" id="depTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="pend-tab" data-bs-toggle="tab" data-bs-target="#pend" type="button" role="tab">
          <i class="bi bi-inbox me-1"></i>Pendientes
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="hist-tab" data-bs-toggle="tab" data-bs-target="#hist" type="button" role="tab">
          <i class="bi bi-clock-history me-1"></i>Historial
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="cortes-tab" data-bs-toggle="tab" data-bs-target="#cortes" type="button" role="tab">
          <i class="bi bi-clipboard-data me-1"></i>Cortes
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="saldos-tab" data-bs-toggle="tab" data-bs-target="#saldos" type="button" role="tab">
          <i class="bi bi-graph-up me-1"></i>Saldos
        </button>
      </li>
    </ul>

    <div class="tab-content" id="depTabsContent">
      <!-- PENDIENTES -->
      <div class="tab-pane fade show active" id="pend" role="tabpanel" tabindex="0">
        <div class="card card-elev mb-4">
          <div class="card-header d-flex align-items-center justify-content-between">
            <div class="section-title mb-0"><i class="bi bi-inbox"></i> Depósitos pendientes / en corrección</div>
            <span class="badge rounded-pill badge-soft"><?= (int)$pendCount ?> en cola</span>
          </div>
          <div class="card-body p-0">
            <?php if ($pendCount === 0): ?>
              <div class="p-3">
                <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1"></i>No hay depósitos pendientes.</div>
              </div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-hover table-xs align-middle mb-0 table-fit">
                  <thead>
                    <tr>
                      <th>ID Depósito</th>
                      <th>Sucursal</th>
                      <th>ID Corte</th>
                      <th>Fecha Corte</th>
                      <th class="text-end">Monto</th>
                      <th>Banco</th>
                      <th>Referencia</th>
                      <th>Comprobante</th>
                      <!-- <th>Comentario admin</th> -->
                      <th>Estado / Corrección</th>
                      <th class="text-center col-acciones">Acciones</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                    $lastCorte = null;
                    foreach ($pendientes as $p):
                      $estado = (string)$p['estado'];
                      $badge = 'bg-warning text-dark';
                      if ($estado === 'Validado') $badge = 'bg-success';
                      elseif ($estado === 'Correccion') $badge = 'bg-danger';

                      if ($lastCorte !== $p['id_corte']): ?>
                        <tr class="table-secondary">
                          <td colspan="11" class="fw-semibold">
                            <i class="bi bi-journal-check me-1"></i>Corte #<?= (int)$p['id_corte'] ?> ·
                            <span class="text-primary"><?= htmlspecialchars($p['sucursal']) ?></span>
                            <span class="ms-2 text-muted">Fecha: <?= htmlspecialchars($p['fecha_corte']) ?></span>
                            <span class="ms-2 badge rounded-pill bg-light text-dark">Efectivo corte: $<?= number_format($p['total_efectivo'], 2) ?></span>
                            <button class="btn btn-sm btn-outline-primary ms-2 js-corte-modal" data-id="<?= (int)$p['id_corte'] ?>">
                              <i class="bi bi-list-ul me-1"></i> Ver detalle
                            </button>
                          </td>
                        </tr>
                      <?php endif; ?>

                      <tr class="<?= $estado === 'Correccion' ? 'row-correccion' : '' ?>">
                        <td>#<?= (int)$p['id_deposito'] ?></td>
                        <td><?= htmlspecialchars($p['sucursal']) ?></td>
                        <td><?= (int)$p['id_corte'] ?></td>
                        <td><?= htmlspecialchars($p['fecha_corte']) ?></td>
                        <td class="text-end">$<?= number_format($p['monto_depositado'], 2) ?></td>
                        <td><?= htmlspecialchars($p['banco']) ?></td>
                        <td><code><?= htmlspecialchars($p['referencia']) ?></code></td>

                        <td>
                          <?php if (!empty($p['comprobante_archivo'])): ?>
                            <button class="btn btn-outline-primary btn-sm js-ver"
                              data-src="deposito_comprobante.php?id=<?= (int)$p['id_deposito'] ?>"
                              data-bs-toggle="modal" data-bs-target="#visorModal">
                              <i class="bi bi-eye"></i> Ver
                            </button>
                          <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>

                        <!-- <td class="comment-cell">
                          <form method="POST" class="d-flex gap-2 align-items-start js-comment-form">
                            <input type="hidden" name="id_deposito" value="<?= (int)$p['id_deposito'] ?>">
                            <textarea name="comentario_admin" class="form-control form-control-sm" placeholder="Ej. Depósito incompleto / aclarar referencia"><?= htmlspecialchars($p['comentario_admin'] ?? '') ?></textarea>
                            <button name="accion" value="GuardarComentario" class="btn btn-outline-primary btn-sm">
                              <i class="bi bi-floppy"></i>
                            </button>
                          </form>
                        </td> -->

                        <td class="col-estado">
                          <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge <?= $badge ?>"><?= htmlspecialchars($estado) ?></span>
                            <?php if ($estado === 'Correccion' && !empty($p['correccion_solicitada_en'])): ?>
                              <span class="text-muted small">· <?= htmlspecialchars($p['correccion_solicitada_en']) ?></span>
                            <?php endif; ?>
                          </div>
                          <?php if ($estado === 'Correccion'): ?>
                            <div class="small text-danger text-break">
                              <i class="bi bi-exclamation-triangle me-1"></i>
                              <?= nl2br(htmlspecialchars($p['correccion_motivo'] ?? '')) ?>
                            </div>
                          <?php else: ?>
                            <div class="small text-muted">—</div>
                          <?php endif; ?>
                        </td>

                        <td class="text-center col-acciones">
                          <div class="actions-cell">
                            <?php if ($estado === 'Pendiente'): ?>
                              <form method="POST" class="d-inline" onsubmit="return confirmarValidacion(<?= (int)$p['id_deposito'] ?>, '<?= htmlspecialchars($p['sucursal'], ENT_QUOTES) ?>', '<?= number_format($p['monto_depositado'], 2) ?>');">
                                <input type="hidden" name="id_deposito" value="<?= (int)$p['id_deposito'] ?>">
                                <button name="accion" value="Validar" class="btn btn-success btn-sm">
                                  <i class="bi bi-check2-circle me-1"></i> Validar
                                </button>
                              </form>
                            <?php else: ?>
                              <button class="btn btn-outline-secondary btn-sm" disabled title="En corrección. Se valida cuando la sucursal re-suban el comprobante y regrese a Pendiente.">
                                <i class="bi bi-lock"></i>
                              </button>
                            <?php endif; ?>

                            <button type="button"
                              class="btn btn-warning btn-sm ms-1 js-pedir-correccion"
                              data-id="<?= (int)$p['id_deposito'] ?>"
                              data-sucursal="<?= htmlspecialchars($p['sucursal'], ENT_QUOTES) ?>"
                              data-monto="<?= number_format($p['monto_depositado'], 2) ?>"
                              data-motivo="<?= htmlspecialchars($p['correccion_motivo'] ?? '', ENT_QUOTES) ?>"
                              data-bs-toggle="modal" data-bs-target="#correccionModal">
                              <i class="bi bi-arrow-repeat me-1"></i> Pedir corrección
                            </button>
                        </td>
                      </tr>
                    <?php $lastCorte = $p['id_corte'];
                    endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- HISTORIAL -->
      <div class="tab-pane fade" id="hist" role="tabpanel" tabindex="0">
        <div class="card card-elev mb-4">
          <div class="card-header">
            <div class="section-title mb-0"><i class="bi bi-clock-history"></i> Historial de depósitos</div>
            <div class="help-text">Al elegir <b>semana</b>, se ignoran las fechas.</div>
          </div>
          <div class="card-body">
            <form class="row g-2 align-items-end mb-3" method="get">
              <div class="col-md-4 col-lg-3">
                <label class="form-label mb-0">Sucursal</label>
                <select name="sucursal_id" class="form-select form-select-sm">
                  <option value="0">Todas</option>
                  <?php foreach ($sucursales as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $sucursal_id === (int)$s['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($s['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4 col-lg-3">
                <label class="form-label mb-0">Desde</label>
                <input type="date" name="desde" class="form-control form-select-sm" value="<?= htmlspecialchars($desde) ?>" <?= $semana ? 'disabled' : '' ?>>
              </div>
              <div class="col-md-4 col-lg-3">
                <label class="form-label mb-0">Hasta</label>
                <input type="date" name="hasta" class="form-control form-select-sm" value="<?= htmlspecialchars($hasta) ?>" <?= $semana ? 'disabled' : '' ?>>
              </div>
              <div class="col-md-4 col-lg-3">
                <label class="form-label mb-0">Semana (ISO)</label>
                <input type="week" name="semana" class="form-control form-select-sm" value="<?= htmlspecialchars($semana) ?>">
              </div>
              <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Aplicar filtros</button>
                <a class="btn btn-outline-secondary btn-sm" href="depositos.php"><i class="bi bi-eraser me-1"></i> Limpiar</a>
              </div>
            </form>

            <div class="table-responsive">
              <table class="table table-hover table-xs align-middle mb-0">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Sucursal</th>
                    <th>ID Corte</th>
                    <th>Fecha Corte</th>
                    <th>Fecha Depósito</th>
                    <th class="text-end">Monto</th>
                    <th>Banco</th>
                    <th>Referencia</th>
                    <th>Comprobante</th>
                    <th>Estado</th>
                    <th>Comentario admin</th>
                    <th>Motivo corrección</th>
                    <th class="text-center">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$historial): ?>
                    <tr>
                      <td colspan="13" class="text-muted">Sin resultados con los filtros actuales.</td>
                    </tr>
                  <?php endif; ?>
                  <?php foreach ($historial as $h):
                    $estado = (string)$h['estado'];
                    $badge = 'bg-warning text-dark';
                    if ($estado === 'Validado') $badge = 'bg-success';
                    elseif ($estado === 'Correccion') $badge = 'bg-danger';
                  ?>
                    <tr class="<?= $estado === 'Validado' ? 'table-success' : '' ?> <?= $estado === 'Correccion' ? 'row-correccion' : '' ?>">
                      <td>#<?= (int)$h['id_deposito'] ?></td>
                      <td><?= htmlspecialchars($h['sucursal']) ?></td>
                      <td>
                        <?= (int)$h['id_corte'] ?>
                        <button class="btn btn-outline-primary btn-xs btn-sm ms-1 js-corte-modal" data-id="<?= (int)$h['id_corte'] ?>">
                          <i class="bi bi-list-ul"></i>
                        </button>
                      </td>
                      <td><?= htmlspecialchars($h['fecha_corte']) ?></td>
                      <td><?= htmlspecialchars($h['fecha_deposito']) ?></td>
                      <td class="text-end">$<?= number_format($h['monto_depositado'], 2) ?></td>
                      <td><?= htmlspecialchars($h['banco']) ?></td>
                      <td><code><?= htmlspecialchars($h['referencia']) ?></code></td>
                      <td>
                        <?php if (!empty($h['comprobante_archivo'])): ?>
                          <button class="btn btn-outline-primary btn-sm js-ver"
                            data-src="deposito_comprobante.php?id=<?= (int)$h['id_deposito'] ?>"
                            data-bs-toggle="modal" data-bs-target="#visorModal">
                            <i class="bi bi-eye"></i> Ver
                          </button>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                      </td>
                      <td>
                        <span class="badge <?= $badge ?>"><?= htmlspecialchars($estado) ?></span>
                      </td>
                      <td style="max-width:320px;">
                        <div class="text-break"><?= nl2br(htmlspecialchars($h['comentario_admin'] ?? '')) ?></div>
                      </td>
                      <td style="max-width:360px;">
                        <div class="text-break text-danger"><?= nl2br(htmlspecialchars($h['correccion_motivo'] ?? '')) ?></div>
                        <?php if (!empty($h['correccion_solicitada_en'])): ?>
                          <div class="small text-muted">Solicitado: <?= htmlspecialchars($h['correccion_solicitada_en']) ?></div>
                        <?php endif; ?>
                      </td>
                      <td class="text-center" style="min-width:220px;">
                        <?php if ($estado === 'Pendiente'): ?>
                          <form method="POST" class="d-inline" onsubmit="return confirmarValidacion(<?= (int)$h['id_deposito'] ?>, '<?= htmlspecialchars($h['sucursal'], ENT_QUOTES) ?>', '<?= number_format($h['monto_depositado'], 2) ?>');">
                            <input type="hidden" name="id_deposito" value="<?= (int)$h['id_deposito'] ?>">
                            <button name="accion" value="Validar" class="btn btn-success btn-sm">
                              <i class="bi bi-check2-circle me-1"></i> Validar
                            </button>
                          </form>
                        <?php else: ?>
                          <span class="text-muted">—</span>
                        <?php endif; ?>

                        <?php if ($estado !== 'Validado'): ?>
                          <button type="button"
                            class="btn btn-warning btn-sm ms-1 js-pedir-correccion"
                            data-id="<?= (int)$h['id_deposito'] ?>"
                            data-sucursal="<?= htmlspecialchars($h['sucursal'], ENT_QUOTES) ?>"
                            data-monto="<?= number_format($h['monto_depositado'], 2) ?>"
                            data-motivo="<?= htmlspecialchars($h['correccion_motivo'] ?? '', ENT_QUOTES) ?>"
                            data-bs-toggle="modal" data-bs-target="#correccionModal">
                            <i class="bi bi-arrow-repeat me-1"></i> Pedir corrección
                          </button>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

          </div>
        </div>
      </div>

      <!-- CORTES -->
      <div class="tab-pane fade" id="cortes" role="tabpanel" tabindex="0">
        <div class="card card-elev mb-4">
          <div class="card-header">
            <div class="section-title mb-0"><i class="bi bi-clipboard-data"></i> Cortes de caja</div>
            <div class="help-text">Filtra por sucursal y fechas; despliega cobros y depósitos por corte.</div>
          </div>
          <div class="card-body">
            <form class="row g-2 align-items-end mb-3" method="get">
              <div class="col-md-4 col-lg-3">
                <label class="form-label mb-0">Sucursal</label>
                <select name="c_sucursal_id" class="form-select form-select-sm">
                  <option value="0">Todas</option>
                  <?php foreach ($sucursales as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $c_sucursal_id === (int)$s['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($s['nombre']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4 col-lg-3">
                <label class="form-label mb-0">Desde</label>
                <input type="date" name="c_desde" class="form-control form-select-sm" value="<?= htmlspecialchars($c_desde) ?>">
              </div>
              <div class="col-md-4 col-lg-3">
                <label class="form-label mb-0">Hasta</label>
                <input type="date" name="c_hasta" class="form-control form-select-sm" value="<?= htmlspecialchars($c_hasta) ?>">
              </div>
              <div class="col-12 d-flex gap-2">
                <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Filtrar cortes</button>
                <a class="btn btn-outline-secondary btn-sm" href="depositos.php"><i class="bi bi-eraser me-1"></i> Limpiar</a>
              </div>
            </form>

            <div class="table-responsive">
              <table class="table table-hover table-xs align-middle mb-0">
                <thead>
                  <tr>
                    <th>ID Corte</th>
                    <th>Sucursal</th>
                    <th>Fecha Operación</th>
                    <th>Fecha Corte</th>
                    <th class="text-end">Efectivo</th>
                    <th class="text-end">Tarjeta</th>
                    <th class="text-end">Com. Esp.</th>
                    <th class="text-end">Total</th>
                    <th>Depositado</th>
                    <th>Estado</th>
                    <th>Detalle</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$cortes): ?>
                    <tr>
                      <td colspan="11" class="text-muted">Sin cortes con los filtros seleccionados.</td>
                    </tr>
                    <?php else: foreach ($cortes as $c): ?>
                      <tr>
                        <td>#<?= (int)$c['id'] ?></td>
                        <td><?= htmlspecialchars($c['sucursal']) ?></td>
                        <td><?= htmlspecialchars($c['fecha_operacion']) ?></td>
                        <td><?= htmlspecialchars($c['fecha_corte']) ?></td>
                        <td class="text-end">$<?= number_format($c['total_efectivo'], 2) ?></td>
                        <td class="text-end">$<?= number_format($c['total_tarjeta'], 2) ?></td>
                        <td class="text-end">$<?= number_format($c['total_comision_especial'], 2) ?></td>
                        <td class="text-end fw-semibold">$<?= number_format($c['total_general'], 2) ?></td>
                        <td><?= $c['depositado'] ? ('$' . number_format($c['monto_depositado'], 2)) : '<span class="text-muted">No</span>' ?></td>
                        <td><span class="badge <?= $c['estado'] === 'Cerrado' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= htmlspecialchars($c['estado']) ?></span></td>
                        <td>
                          <button class="btn btn-sm btn-outline-primary js-corte-modal" data-id="<?= (int)$c['id'] ?>">
                            <i class="bi bi-list-ul me-1"></i> Ver detalle
                          </button>
                        </td>
                      </tr>
                  <?php endforeach;
                  endif; ?>
                </tbody>
              </table>
            </div>

          </div>
        </div>
      </div>

      <!-- SALDOS -->
      <div class="tab-pane fade" id="saldos" role="tabpanel" tabindex="0">
        <div class="card card-elev mb-4">
          <div class="card-header d-flex align-items-center justify-content-between">
            <div class="section-title mb-0"><i class="bi bi-graph-up"></i> Saldos por sucursal</div>
            <span class="help-text">Comparativo de efectivo cobrado vs depositado</span>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover table-xs align-middle mb-0">
                <thead>
                  <tr>
                    <th>Sucursal</th>
                    <th class="text-end">Total Efectivo Cobrado</th>
                    <th class="text-end">Total Depositado</th>
                    <th class="text-end">Saldo Pendiente</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                                    // 🔒 Scope SALDOS: Subdis solo ve sus sucursales
                  $wSaldos = $isSubdisAdmin ? (" WHERE s.id_subdis = " . (int)$id_subdis . " ") : "";
$sqlSaldos = "
                    SELECT 
                        s.id,
                        s.nombre AS sucursal,
                        IFNULL(SUM(c.monto_efectivo),0) AS total_efectivo,
                        IFNULL((SELECT SUM(d.monto_depositado) FROM depositos_sucursal d WHERE d.id_sucursal = s.id AND d.estado='Validado'),0) AS total_depositado,
                        GREATEST(
                            IFNULL(SUM(c.monto_efectivo),0) - IFNULL((SELECT SUM(d.monto_depositado) FROM depositos_sucursal d WHERE d.id_sucursal = s.id AND d.estado='Validado'),0),
                        0) AS saldo_pendiente
                    FROM sucursales s
                    LEFT JOIN cobros c 
                        ON c.id_sucursal = s.id 
                       AND c.corte_generado = 1
                    {$wSaldos}
                    GROUP BY s.id
                    ORDER BY saldo_pendiente DESC
                  ";
                  $saldos = $conn->query($sqlSaldos)->fetch_all(MYSQLI_ASSOC);
                  foreach ($saldos as $s): ?>
                    <tr class="<?= $s['saldo_pendiente'] > 0 ? 'table-warning' : '' ?>">
                      <td><?= htmlspecialchars($s['sucursal']) ?></td>
                      <td class="text-end">$<?= number_format($s['total_efectivo'], 2) ?></td>
                      <td class="text-end">$<?= number_format($s['total_depositado'], 2) ?></td>
                      <td class="text-end fw-semibold">$<?= number_format($s['saldo_pendiente'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="sticky-actions text-end">
              <span class="help-text"><i class="bi bi-info-circle me-1"></i>Los saldos consideran cobros con corte generado y depósitos validados.</span>
            </div>
          </div>
        </div>
      </div>
    </div> <!-- /tab-content -->

  </div>

  <!-- Modal visor (comprobante) con spinner -->
  <div class="modal fade" id="visorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-file-earmark-image me-1"></i> Comprobante de depósito</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body p-0" id="visorWrap">
          <div id="visorSpinner">
            <div class="spinner"></div>
          </div>
          <iframe id="visorFrame" src="" style="width:100%;height:80vh;border:0;"></iframe>
        </div>
        <div class="modal-footer">
          <a id="btnAbrirNueva" href="#" target="_blank" class="btn btn-outline-secondary">Abrir en nueva pestaña</a>
          <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Listo</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Detalle de Corte -->
  <div class="modal fade" id="detalleCorteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-list-ul me-1"></i> Detalle del corte</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body" id="detalleCorteBody"></div>
        <div class="modal-footer">
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal rápido de éxito de comentario -->
  <div class="modal fade" id="comentarioOkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-body text-center py-4">
          <i class="bi bi-check2-circle fs-1 text-success d-block mb-2"></i>
          <div class="fw-semibold">Comentario guardado</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Pedir Corrección -->
  <div class="modal fade" id="correccionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form method="POST" id="correccionForm">
          <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-arrow-repeat me-1"></i> Solicitar corrección de comprobante</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body">
            <input type="hidden" name="id_deposito" id="correccion_id_deposito" value="">
            <input type="hidden" name="accion" value="PedirCorreccion">

            <div class="alert alert-warning py-2 small mb-3">
              <i class="bi bi-info-circle me-1"></i>
              Esto marcará el depósito como <b>Correccion</b> y la sucursal deberá re-subir el comprobante para regresarlo a <b>Pendiente</b>.
            </div>

            <div class="mb-2">
              <div class="fw-semibold" id="correccion_info"></div>
            </div>

            <label class="form-label fw-semibold">Motivo / instrucciones</label>
            <textarea class="form-control" name="correccion_motivo" id="correccion_motivo" rows="4"
              placeholder="Ej. El comprobante no corresponde al monto, falta referencia visible, imagen borrosa, etc." required></textarea>
            <div class="form-text">Mínimo 5 caracteres. Sé claro para que la sucursal lo corrija a la primera.</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" class="btn btn-warning">
              <i class="bi bi-send me-1"></i> Solicitar corrección
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Overlay de carga general -->
  <div class="loading-backdrop" id="loadingBackdrop">
    <div class="loading-card">
      <div class="spinner"></div>
      <div class="fw-semibold">Cargando detalle…</div>
    </div>
  </div>

  <!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
  <script>
    const semanaInput = document.querySelector('input[name="semana"]');
    const desdeInput = document.querySelector('input[name="desde"]');
    const hastaInput = document.querySelector('input[name="hasta"]');
    if (semanaInput) {
      semanaInput.addEventListener('input', () => {
        const usingWeek = semanaInput.value.trim() !== '';
        if (desdeInput && hastaInput) {
          desdeInput.disabled = usingWeek;
          hastaInput.disabled = usingWeek;
          if (usingWeek) {
            desdeInput.value = '';
            hastaInput.value = '';
          }
        }
      });
    }

    const visorModal = document.getElementById('visorModal');
    const visorFrame = document.getElementById('visorFrame');
    const btnAbrir = document.getElementById('btnAbrirNueva');
    const visorSpinner = document.getElementById('visorSpinner');
    document.querySelectorAll('.js-ver').forEach(btn => {
      btn.addEventListener('click', () => {
        const src = btn.getAttribute('data-src');
        if (visorSpinner) visorSpinner.style.display = 'flex';
        visorFrame.src = src;
        btnAbrir.href = src;
      });
    });
    if (visorModal) {
      visorModal.addEventListener('hidden.bs.modal', () => {
        visorFrame.src = '';
        btnAbrir.href = '#';
        if (visorSpinner) visorSpinner.style.display = 'none';
      });
    }
    if (visorFrame) {
      visorFrame.addEventListener('load', () => {
        if (visorSpinner) visorSpinner.style.display = 'none';
      });
    }

    function confirmarValidacion(id, sucursal, monto) {
      return confirm(`¿Validar el depósito #${id} de ${sucursal} por $${monto}?`);
    }
    window.confirmarValidacion = confirmarValidacion;

    const okModalEl = document.getElementById('comentarioOkModal');
    const okModal = (window.bootstrap && okModalEl) ? new bootstrap.Modal(okModalEl, {
      backdrop: 'static',
      keyboard: false
    }) : null;

    document.querySelectorAll('.js-comment-form').forEach(form => {
      form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        const fd = new FormData(form);
        if (!fd.get('accion')) fd.append('accion', 'GuardarComentario');

        try {
          const resp = await fetch(location.href, {
            method: 'POST',
            headers: {
              'X-Requested-With': 'XMLHttpRequest'
            },
            body: fd
          });
          let ok = false;
          try {
            ok = !!(await resp.json()).ok;
          } catch (e) {
            ok = resp.ok;
          }
          if (ok) {
            if (okModal) {
              okModal.show();
              setTimeout(() => okModal.hide(), 1200);
            } else {
              alert('Comentario guardado');
            }
          } else {
            alert('No se pudo guardar el comentario. Intenta de nuevo.');
          }
        } catch (err) {
          console.error(err);
          alert('Error de red al guardar comentario.');
        }
      }, {
        passive: false
      });
    });

    const detalleModalEl = document.getElementById('detalleCorteModal');
    const detalleBody = document.getElementById('detalleCorteBody');
    const loadingBackdrop = document.getElementById('loadingBackdrop');
    const detalleModal = (window.bootstrap && detalleModalEl) ? new bootstrap.Modal(detalleModalEl) : null;

    function showLoading(show) {
      if (loadingBackdrop) loadingBackdrop.style.display = show ? 'flex' : 'none';
    }

    function wireDetalleHandlers() {
      detalleBody.querySelectorAll('.js-ver').forEach(btn => {
        btn.addEventListener('click', () => {
          const src = btn.getAttribute('data-src');
          if (document.getElementById('visorSpinner')) document.getElementById('visorSpinner').style.display = 'flex';
          document.getElementById('visorFrame').src = src;
          document.getElementById('btnAbrirNueva').href = src;
          new bootstrap.Modal(document.getElementById('visorModal')).show();
        });
      });

      detalleBody.querySelectorAll('.js-validar-deposito-modal').forEach(form => {
        form.addEventListener('submit', async (ev) => {
          ev.preventDefault();
          const id = form.dataset.id;
          const sucursal = form.dataset.sucursal || '';
          const monto = form.dataset.monto || '';

          if (!confirm(`¿Validar el depósito #${id} de ${sucursal} por $${monto}?`)) return;

          const fd = new FormData(form);
          if (!fd.get('accion')) fd.append('accion', 'Validar');

          showLoading(true);
          try {
            const resp = await fetch(location.href, {
              method: 'POST',
              headers: {
                'X-Requested-With': 'XMLHttpRequest'
              },
              body: fd
            });
            let data = null;
            try {
              data = await resp.json();
            } catch (e) {}

            if (data && data.ok) {
              alert(data.message || 'Depósito validado correctamente.');
              location.reload();
            } else {
              alert((data && data.message) || 'No se pudo validar el depósito.');
            }
          } catch (err) {
            console.error(err);
            alert('Error de red al validar el depósito.');
          } finally {
            showLoading(false);
          }
        });
      });
    }

    async function cargarDetalleCorte(idCorte) {
      showLoading(true);
      try {
        const resp = await fetch(`?ajax=detalle_corte&id=${encodeURIComponent(idCorte)}`, {
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        });
        const html = await resp.text();
        detalleBody.innerHTML = html;
        wireDetalleHandlers();
        if (detalleModal) detalleModal.show();
      } catch (err) {
        console.error(err);
        detalleBody.innerHTML = '<div class="alert alert-danger">No se pudo cargar el detalle del corte.</div>';
        if (detalleModal) detalleModal.show();
      } finally {
        showLoading(false);
      }
    }

    document.querySelectorAll('.js-corte-modal').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-id');
        cargarDetalleCorte(id);
      });
    });

    // Modal "Pedir corrección"
    const corrId = document.getElementById('correccion_id_deposito');
    const corrInfo = document.getElementById('correccion_info');
    const corrMot = document.getElementById('correccion_motivo');

    document.querySelectorAll('.js-pedir-correccion').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.id || '';
        const suc = btn.dataset.sucursal || '';
        const mon = btn.dataset.monto || '';
        const mot = btn.dataset.motivo || '';
        corrId.value = id;
        corrInfo.textContent = `Depósito #${id} · ${suc} · $${mon}`;
        corrMot.value = mot ? mot : '';
        setTimeout(() => {
          corrMot.focus();
        }, 150);
      });
    });
  </script>
</body>

</html>