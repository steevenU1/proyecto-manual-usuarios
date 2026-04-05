<?php
// acuse_traspaso.php — Acuse imprimible de un traspaso (equipos + accesorios)
// Soporta scope=original|recibidos e ids=csv (para filtrar equipos).
// Si ?print=1 → dispara window.print() al cargar.

session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

require_once __DIR__ . '/db.php';

// ---------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function hasColumn(mysqli $conn, string $table, string $column): bool {
  $table  = $conn->real_escape_string($table);
  $column = $conn->real_escape_string($column);
  $rs = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
  return $rs && $rs->num_rows > 0;
}
function table_exists(mysqli $conn, string $table): bool {
  $table = $conn->real_escape_string($table);
  $rs = $conn->query("SHOW TABLES LIKE '{$table}'");
  return $rs && $rs->num_rows > 0;
}

// ---------- Logo (versión ligera, sin file_get_contents lento) ----------
$LOGO_URL = './assets/logo_ticket.png';
$logoSrc  = $LOGO_URL; // el navegador se encarga, ya no descargamos desde PHP

// ---------- Params ----------
$idTraspaso = (int)($_GET['id'] ?? 0);
$scope      = strtolower(trim((string)($_GET['scope'] ?? 'original'))); // original|recibidos
$idsCsv     = trim((string)($_GET['ids'] ?? ''));
$doPrint    = (int)($_GET['print'] ?? 0) === 1;

if ($idTraspaso <= 0) {
  http_response_code(400);
  echo "<!doctype html><meta charset='utf-8'><div style='padding:2rem;font:14px system-ui'>Falta parámetro <b>id</b> de traspaso.</div>";
  exit();
}

// ---------- Header del traspaso ----------
$hasT_FechaRecep     = hasColumn($conn, 'traspasos', 'fecha_recepcion');
$hasT_UsuarioRecibio = hasColumn($conn, 'traspasos', 'usuario_recibio');
$hasT_Observaciones  = hasColumn($conn, 'traspasos', 'observaciones');

$sqlHead = "
  SELECT 
    t.id,
    t.id_sucursal_origen, t.id_sucursal_destino,
    t.fecha_traspaso,
    t.estatus,
    ".($hasT_Observaciones ? "t.observaciones," : "NULL AS observaciones,")."
    ".($hasT_FechaRecep ? "t.fecha_recepcion," : "NULL AS fecha_recepcion,")."
    uo.nombre AS usuario_origen,
    ".($hasT_UsuarioRecibio ? "ur.nombre AS usuario_recibio," : "NULL AS usuario_recibio,")."
    so.nombre AS sucursal_origen,
    sd.nombre AS sucursal_destino
  FROM traspasos t
  LEFT JOIN usuarios  uo ON uo.id = t.usuario_creo
  ".($hasT_UsuarioRecibio ? "LEFT JOIN usuarios ur ON ur.id = t.usuario_recibio" : "LEFT JOIN usuarios ur ON 1=0")."
  LEFT JOIN sucursales so ON so.id = t.id_sucursal_origen
  LEFT JOIN sucursales sd ON sd.id = t.id_sucursal_destino
  WHERE t.id = ?
";
$stHead = $conn->prepare($sqlHead);
$stHead->bind_param("i", $idTraspaso);
$stHead->execute();
$head = $stHead->get_result()->fetch_assoc();
$stHead->close();

if (!$head) {
  http_response_code(404);
  echo "<!doctype html><meta charset='utf-8'><div style='padding:2rem;font:14px system-ui'>No existe el traspaso solicitado.</div>";
  exit();
}

$folio         = (int)$head['id'];
$idOrigen      = (int)$head['id_sucursal_origen'];
$idDestino     = (int)$head['id_sucursal_destino'];
$origenNom     = $head['sucursal_origen'] ?? '—';
$destNom       = $head['sucursal_destino'] ?? '—';
$fCreacion     = $head['fecha_traspaso'] ? date('d/m/Y H:i', strtotime($head['fecha_traspaso'])) : '—';
$fRecepcion    = $head['fecha_recepcion'] ? date('d/m/Y H:i', strtotime($head['fecha_recepcion'])) : null;
$estatus       = $head['estatus'] ?? '—';
$usrCrea       = $head['usuario_origen'] ?? '—';
$usrRecibio    = $head['usuario_recibio'] ?? null;
$observaciones = trim((string)($head['observaciones'] ?? ''));
$esGarantiaObs = ($observaciones !== '' && (
  stripos($observaciones, 'garantía') !== false ||
  stripos($observaciones, 'garantia') !== false
));

// ---------- Filtros detalle (equipos) ----------
$hasDT_Resultado = hasColumn($conn, 'detalle_traspaso', 'resultado');

$idsFiltro = [];
if ($idsCsv !== '') {
  foreach (explode(',', $idsCsv) as $x) {
    $n = (int)trim($x);
    if ($n > 0) $idsFiltro[] = $n;
  }
  $idsFiltro = array_values(array_unique($idsFiltro));
}

// ---------- Detalle EQUIPOS ----------
$sqlDet = "
  SELECT i.id AS id_inventario, p.marca, p.modelo, p.color, p.imei1, p.imei2
  FROM detalle_traspaso dt
  INNER JOIN inventario i ON i.id = dt.id_inventario
  INNER JOIN productos  p ON p.id = i.id_producto
  WHERE dt.id_traspaso = ?
";
$params = [$idTraspaso];
$types  = "i";

if ($scope === 'recibidos' && $hasDT_Resultado) {
  $sqlDet .= " AND dt.resultado = ?";
  $params[] = 'Recibido';
  $types .= "s";
}
if (!empty($idsFiltro)) {
  $in = implode(',', array_fill(0, count($idsFiltro), '?'));
  $sqlDet .= " AND i.id IN ($in)";
  foreach ($idsFiltro as $idv) {
    $params[] = $idv;
    $types .= "i";
  }
}
$sqlDet .= " ORDER BY p.marca, p.modelo, i.id ASC";

$stDet = $conn->prepare($sqlDet);
$stDet->bind_param($types, ...$params);
$stDet->execute();
$itemsEq = ($r = $stDet->get_result()) ? $r->fetch_all(MYSQLI_ASSOC) : [];
$stDet->close();

$showScopeWarning = ($scope === 'recibidos' && !$hasDT_Resultado && empty($idsFiltro));

// ---------- Detalle ACCESORIOS ----------
$itemsAcc = [];
if (table_exists($conn, 'detalle_traspaso_acc')) {
  $sqlAcc = "
    SELECT dta.id_inventario_origen AS id_inventario, dta.cantidad,
           p.marca, p.modelo, p.color
    FROM detalle_traspaso_acc dta
    JOIN inventario i ON i.id = dta.id_inventario_origen
    JOIN productos  p ON p.id = dta.id_producto
    WHERE dta.id_traspaso = ?
    ORDER BY p.marca, p.modelo, p.color, dta.id_inventario_origen
  ";
  $stA = $conn->prepare($sqlAcc);
  $stA->bind_param('i', $idTraspaso);
  $stA->execute();
  $ra = $stA->get_result();
  $itemsAcc = $ra ? $ra->fetch_all(MYSQLI_ASSOC) : [];
  $stA->close();
}

// ---------- Totales y títulos ----------
$esRecepcion  = ($scope === 'recibidos');
$tituloAcuse  = $esRecepcion ? "Acuse de recepción" : "Acuse de entrega";
$totalEq      = count($itemsEq);
$totalAccPzas = array_sum(array_map(fn($r) => (int)$r['cantidad'], $itemsAcc));
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= h($tituloAcuse) ?> · #<?= (int)$folio ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      --ink:#0f172a; --muted:#64748b; --line:#e2e8f0; --brand:#0d6efd;
      --ok:#16a34a; --warn:#f59e0b; --soft:#f8fafc;
      --obs-bg:#f8fafc; --obs-border:#e2e8f0; --obs-title:#0f172a;
    }
    * { box-sizing: border-box; }
    html, body { margin:0; padding:0; color:var(--ink); font:13px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; background:#fff; }

    .wrap { max-width: 1080px; margin: 24px auto; padding: 0 16px 32px; }

    /* Header */
    header { display:flex; align-items:center; justify-content:space-between; gap:16px; padding-bottom:14px; border-bottom:2px solid var(--ink); }
    .brand { display:flex; align-items:center; gap:12px; }
    .brand .logo-img { width:56px; height:56px; object-fit:contain; border-radius:12px; border:2px solid var(--ink); background:#fff; }
    .title { display:flex; flex-direction:column; gap:4px; }
    h1 { font-size:20px; margin:0; }
    .meta { margin-top:2px; color:var(--muted); }
    .badge { display:inline-block; padding:3px 8px; border-radius:999px; font-size:11px; border:1px solid var(--line); background:#fff; }
    .badge.ok { border-color:#bbf7d0; background:#ecfdf5; }
    .badge.warn { border-color:#fde68a; background:#fffbeb; }

    /* Cards superiores */
    .cards-top { margin-top:14px; display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .card {
      position:relative; border:1px solid var(--line); border-radius:14px; padding:14px 14px 12px;
      background: radial-gradient(600px 200px at 100% -50%, rgba(13,110,253,.06), transparent),
                  radial-gradient(600px 200px at -20% 120%, rgba(34,197,94,.06), transparent),
                  #fff;
      box-shadow: 0 8px 22px rgba(2,8,20,.06), 0 2px 6px rgba(2,8,20,.05);
    }
    .card .sup { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:8px; }
    .tag { display:inline-flex; align-items:center; gap:6px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; font-size:11px; color:#334155; }
    .pill { display:inline-block; padding:3px 8px; border-radius:8px; font-size:11px; background:#eef2ff; border:1px solid #c7d2fe; color:#1e40af; }
    .kv { display:grid; grid-template-columns:140px 1fr; gap:6px 10px; }
    .kv .k { color:var(--muted); }
    .kv .v { font-weight:600; }
    .sep { height:10px; }

    /* Observaciones */
    .obs-box {
      margin-top:16px;
      border:1px solid var(--obs-border);
      border-radius:12px;
      padding:14px;
      background:var(--obs-bg);
    }
    .obs-title {
      font-weight:700;
      margin-bottom:6px;
      color:var(--obs-title);
    }
    .obs-text {
      white-space:pre-line;
      color:#334155;
    }
    .obs-garantia {
      --obs-bg:#fff7ed;
      --obs-border:#fdba74;
      --obs-title:#9a3412;
    }

    /* Tablas */
    table { width:100%; border-collapse:collapse; margin-top:18px; border:1px solid var(--line); }
    thead th { text-transform:uppercase; letter-spacing:.4px; font-size:11px; background:var(--soft); border-bottom:1px solid var(--line); }
    th, td { padding:8px 8px; border-bottom:1px solid var(--line); }
    tbody tr:nth-child(even){ background:#fcfdff; }
    code { font-family: ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace; background:#f1f5f9; padding:2px 6px; border-radius:6px; }

    .tot { margin:10px 0 18px; font-weight:700; }
    .note { margin-top:12px; color:#7c3aed; background:#faf5ff; border:1px solid #e9d5ff; padding:8px 10px; border-radius:8px; }

    .footer { margin-top:18px; display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .sign { border-top:1px dashed var(--line); padding-top:10px; min-height:84px; }

    .toolbar { display:flex; gap:8px; justify-content:flex-end; margin-bottom:10px; }
    .toolbar .btn { border:1px solid var(--line); background:#fff; padding:6px 10px; border-radius:8px; cursor:pointer; text-decoration:none; color:inherit; }
    .toolbar .btn.primary { border-color:#c7d2fe; background:#eef2ff; }

    @media print {
      .wrap{margin:0;max-width:none;padding:0}
      header{margin:0 0 8px 0}
      .no-print{display:none!important}
      body{-webkit-print-color-adjust:exact; print-color-adjust:exact}
      .card,.obs-box{box-shadow:none}
    }
  </style>
</head>
<body>
  <div class="wrap">

    <div class="no-print toolbar">
      <button class="btn" onclick="window.history.back()">← Regresar</button>
      <button class="btn" onclick="window.print()"><b>🖨️ Imprimir</b></button>
      <a class="btn primary" href="<?= h($_SERVER['REQUEST_URI']) ?>">↻ Recargar</a>
    </div>

    <header>
      <div class="brand">
        <img class="logo-img" src="<?= h($logoSrc) ?>" alt="Logo">
        <div class="title">
          <h1><?= h($tituloAcuse) ?></h1>
          <div class="meta">
            Folio <b>#<?= (int)$folio ?></b> · Generado el <?= date('d/m/Y H:i') ?>
          </div>
        </div>
      </div>
      <div class="title" style="align-items:flex-end">
        <div>
          <span class="badge <?= ($estatus === 'Recibido' || $estatus === 'Completado') ? 'ok' : 'warn' ?>">Estatus: <?= h($estatus) ?></span>
        </div>
        <div class="meta">
          Traspaso: <b><?= h($fCreacion) ?></b>
          <?php if ($esRecepcion && $fRecepcion): ?>
            · Recepción: <b><?= h($fRecepcion) ?></b>
          <?php endif; ?>
        </div>
      </div>
    </header>

    <section class="cards-top">
      <!-- Origen -->
      <div class="card">
        <div class="sup">
          <span class="tag">Sucursal Origen</span>
          <span class="pill">ID #<?= (int)$idOrigen ?></span>
        </div>
        <div class="kv">
          <div class="k">Sucursal:</div><div class="v"><?= h($origenNom) ?></div>
          <div class="k">Quién envía:</div><div class="v"><?= h($usrCrea ?: '—') ?></div>
          <div class="k">Fecha envío:</div><div class="v"><?= h($fCreacion) ?></div>
        </div>
      </div>

      <!-- Destino -->
      <div class="card">
        <div class="sup">
          <span class="tag">Sucursal Destino</span>
          <span class="pill">ID #<?= (int)$idDestino ?></span>
        </div>
        <div class="kv">
          <div class="k">Sucursal:</div><div class="v"><?= h($destNom) ?></div>
          <div class="k"><?= $esRecepcion ? 'Quién recibe:' : 'Por recibir:' ?></div>
          <div class="v"><?= h($usrRecibio ?: '—') ?></div>
          <div class="k">Fecha recepción:</div>
          <div class="v"><?= $fRecepcion ? h($fRecepcion) : '—' ?></div>
        </div>
      </div>
    </section>

    <?php if (!empty($observaciones)): ?>
      <div class="obs-box <?= $esGarantiaObs ? 'obs-garantia' : '' ?>">
        <div class="obs-title">
          <?= $esGarantiaObs ? '🛡️ Observaciones del traspaso por garantía' : '📝 Observaciones del traspaso' ?>
        </div>
        <div class="obs-text"><?= nl2br(h($observaciones)) ?></div>
      </div>
    <?php endif; ?>

    <?php if ($showScopeWarning): ?>
      <div class="note">
        <b>Nota:</b> Modo <b>recibidos</b> pero <code>detalle_traspaso.resultado</code> no existe/no se usa y no llegaron <code>ids</code>. Se muestra el detalle completo.
      </div>
    <?php endif; ?>

    <!-- EQUIPOS -->
    <table>
      <thead>
        <tr>
          <th style="width:80px">ID Inv</th>
          <th>Marca</th>
          <th>Modelo</th>
          <th>Color</th>
          <th>IMEI 1</th>
          <th>IMEI 2</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($itemsEq)): ?>
          <?php foreach ($itemsEq as $it): ?>
            <tr>
              <td><b><?= (int)$it['id_inventario'] ?></b></td>
              <td><?= h($it['marca']) ?></td>
              <td><?= h($it['modelo']) ?></td>
              <td><?= h($it['color']) ?></td>
              <td><code><?= h($it['imei1']) ?></code></td>
              <td><?= $it['imei2'] ? '<code>'.h($it['imei2']).'</code>' : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="6" style="text-align:center;color:#64748b;padding:18px 8px">Sin equipos en este traspaso.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <div class="tot">Total de equipos: <?= (int)$totalEq ?></div>

    <!-- ACCESORIOS -->
    <table>
      <thead>
        <tr>
          <th style="width:120px">ID Inv Origen</th>
          <th>Marca</th>
          <th>Modelo</th>
          <th>Color</th>
          <th style="text-align:right">Piezas</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!empty($itemsAcc)): ?>
          <?php foreach ($itemsAcc as $it): ?>
            <tr>
              <td><b><?= (int)$it['id_inventario'] ?></b></td>
              <td><?= h($it['marca']) ?></td>
              <td><?= h($it['modelo']) ?></td>
              <td><?= h($it['color']) ?></td>
              <td style="text-align:right"><b><?= (int)$it['cantidad'] ?></b></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="5" style="text-align:center;color:#64748b;padding:18px 8px">Sin accesorios en este traspaso.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
    <div class="tot">Total de accesorios (piezas): <?= (int)$totalAccPzas ?></div>

    <!-- Firmas -->
    <div class="footer">
      <div class="sign">
        <div><b>Entrega (Origen)</b></div>
        <div style="height:52px"></div>
        <small>Nombre y firma</small>
      </div>
      <div class="sign">
        <div><b>Recepción (Destino)</b></div>
        <div style="height:52px"></div>
        <small>Nombre y firma</small>
      </div>
    </div>

    <div class="no-print" style="margin-top:10px;color:#475569">
      <small>Imprimir en 1 copia para almacenar en destino y 1 para el archivo del origen.</small>
    </div>
  </div>

  <?php if ($doPrint): ?>
  <script>
    window.addEventListener('load', function(){ try{ window.focus(); window.print(); }catch(e){} });
  </script>
  <?php endif; ?>
</body>
</html>