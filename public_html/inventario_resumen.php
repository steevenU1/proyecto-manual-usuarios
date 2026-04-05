<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit; }

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/navbar.php')) require_once __DIR__ . '/navbar.php';

/* =======================
   Helpers
======================= */
if (!function_exists('nombreCortoSucursal')) {
  function nombreCortoSucursal(string $nom): string {
    $n = trim($nom);
    $n = preg_replace('/^\s*LUGA[\s\-\:_]*/i', '', $n);
    return $n === '' ? $nom : $n;
  }
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* =======================
   Filtros (GET)
======================= */
$fSucursal  = isset($_GET['sucursal'])  ? (int)$_GET['sucursal'] : 0;
$fTipo      = isset($_GET['tipo'])      ? strtolower(trim($_GET['tipo'])) : 'ambos'; // 'ambos' | 'equipo' | 'modem' | 'accesorio'
$fMarca     = isset($_GET['marca'])     ? trim($_GET['marca'])    : '';
$fModelo    = isset($_GET['modelo'])    ? trim($_GET['modelo'])   : '';
$fCapacidad = isset($_GET['capacidad']) ? trim($_GET['capacidad']): '';
$verSuc     = isset($_GET['ver_suc'])   ? (int)$_GET['ver_suc']   : 0;

/* =======================
   Catálogos para filtros
======================= */
$catSuc = [];
$rs = $conn->query("
  SELECT id, nombre
  FROM sucursales
  WHERE COALESCE(subtipo,'') NOT IN ('Subdistribuidor','Master Admin')
  ORDER BY nombre
");
while($r = $rs->fetch_assoc()){
  $catSuc[(int)$r['id']] = nombreCortoSucursal($r['nombre']);
}

/* Construye condición por tipo (incluyendo Accesorio) */
function sqlTipo(string $fTipo): string {
  // Normalizamos categorías que suelen aparecer como módems
  $tiposModem = ["'Modem'","'Módem'","'MiFi'","'MIFI'","'Mi-Fi'"];

  $fTipo = strtolower($fTipo);

  if ($fTipo === 'equipo') {
    return "p.tipo_producto = 'Equipo'";
  } elseif ($fTipo === 'modem') {
    return "p.tipo_producto IN (" . implode(',', $tiposModem) . ")";
  } elseif ($fTipo === 'accesorio') {
    return "p.tipo_producto = 'Accesorio'";
  }

  // 'ambos' (por defecto) = Equipo + Módem/MiFi + Accesorios
  return "(p.tipo_producto = 'Equipo' 
           OR p.tipo_producto IN (" . implode(',', $tiposModem) . ")
           OR p.tipo_producto = 'Accesorio')";
}

/* Marcas/Modelos/Capacidades existentes (para combos de filtro) */
$whereCat   = [];
$whereCat[] = "i.estatus IN ('Disponible','En tránsito')";
$whereCat[] = "COALESCE(i.cantidad,0) > 0"; // ✅ ignorar registros con cantidad 0 (accesorios fantasma)
$whereCat[] = sqlTipo($fTipo);
if ($fSucursal > 0)     $whereCat[] = "i.id_sucursal = " . (int)$fSucursal;
if ($fMarca !== '')     $whereCat[] = "p.marca = '" . $conn->real_escape_string($fMarca) . "'";
if ($fCapacidad !== '') $whereCat[] = "p.capacidad = '" . $conn->real_escape_string($fCapacidad) . "'";
$whereCatSql = $whereCat ? "WHERE " . implode(" AND ", $whereCat) : '';

$catMarcas = $catModelos = $catCaps = [];
$sqlCat = "
  SELECT DISTINCT p.marca, p.modelo, p.capacidad
  FROM inventario i
  INNER JOIN productos p ON p.id = i.id_producto
  $whereCatSql
";
$rs = $conn->query($sqlCat);
while($r=$rs->fetch_assoc()){
  if ($r['marca']     !== null && $r['marca']     !== '') $catMarcas[$r['marca']] = true;
  if ($r['modelo']    !== null && $r['modelo']    !== '') $catModelos[$r['modelo']] = true;
  if ($r['capacidad'] !== null && $r['capacidad'] !== '') $catCaps[$r['capacidad']] = true;
}
$catMarcas  = array_keys($catMarcas);
$catModelos = array_keys($catModelos);
$catCaps    = array_keys($catCaps);

/* =======================
   Query agregado (pivot)
======================= */
$where   = [];
$where[] = "i.estatus IN ('Disponible','En tránsito')";
$where[] = "COALESCE(i.cantidad,0) > 0"; // ✅ igual que en inventario_global: nada con cantidad 0
$where[] = sqlTipo($fTipo);
if ($fSucursal > 0)     $where[] = "i.id_sucursal = " . (int)$fSucursal;
if ($fMarca !== '')     $where[] = "p.marca = '" . $conn->real_escape_string($fMarca) . "'";
if ($fModelo !== '')    $where[] = "p.modelo = '" . $conn->real_escape_string($fModelo) . "'";
if ($fCapacidad !== '') $where[] = "p.capacidad = '" . $conn->real_escape_string($fCapacidad) . "'";
$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : '';

$sql = "
  SELECT 
    p.tipo_producto,
    p.marca, p.modelo, p.capacidad,
    s.id   AS suc_id, 
    s.nombre AS sucursal,
    -- ✅ Cantidad: equipos/módems = 1, accesorios sin IMEI = inventario.cantidad
    SUM(
      CASE 
        WHEN (p.imei1 IS NULL OR p.imei1 = '') THEN COALESCE(i.cantidad,0)
        ELSE 1
      END
    ) AS qty
  FROM inventario i
  INNER JOIN productos  p ON p.id = i.id_producto
  INNER JOIN sucursales s ON s.id = i.id_sucursal
  $whereSql
  GROUP BY p.tipo_producto, p.marca, p.modelo, p.capacidad, s.id, s.nombre
  ORDER BY 
    CASE 
      WHEN p.tipo_producto='Equipo' THEN 0
      WHEN p.tipo_producto IN ('Modem','Módem','MiFi','MIFI','Mi-Fi') THEN 1
      ELSE 2            -- 🔹 Aquí entra Accesorio y cualquier otro tipo: van al final
    END,
    p.marca, p.modelo, p.capacidad, s.nombre
";
$res = $conn->query($sql);

/* Pivot en PHP */
$rows   = [];
$sucSet = [];

while($r = $res->fetch_assoc()){
  $tipo = (string)$r['tipo_producto'];
  $key = $tipo.'|'.$r['marca'].'|'.$r['modelo'].'|'.$r['capacidad'];
  if (!isset($rows[$key])) {
    $rows[$key] = [
      'tipo'      => $tipo,
      'marca'     => $r['marca'],
      'modelo'    => $r['modelo'],
      'capacidad' => $r['capacidad'],
      'sucs'      => [],
      'total'     => 0
    ];
  }
  $sid = (int)$r['suc_id'];
  $qty = (int)$r['qty'];
  $rows[$key]['sucs'][$sid] = ($rows[$key]['sucs'][$sid] ?? 0) + $qty;
  $rows[$key]['total']     += $qty;

  $sucSet[$sid] = nombreCortoSucursal($r['sucursal']);
}

/* Sucursales para columnas (solo si se pide verSuc=1) */
$useSuc = [];
if ($verSuc) {
  if ($fSucursal > 0) {
    if (isset($catSuc[$fSucursal])) $useSuc[$fSucursal] = $catSuc[$fSucursal];
  } else {
    $useSuc = $sucSet;
  }
  asort($useSuc, SORT_NATURAL | SORT_FLAG_CASE);
}

/* Orden filas */
uasort($rows, function($A,$B){
  // Primero por tipo (Equipo → Modem/MiFi → Accesorio/otros)
  $rank = function($t){
    $t = mb_strtolower($t,'UTF-8');
    if ($t === 'equipo') return 0;
    if (in_array($t, ['modem','módem','mifi','mi-fi','mifi'], true)) return 1;
    if ($t === 'accesorio') return 2;
    return 3;
  };
  $rt = $rank($A['tipo']) <=> $rank($B['tipo']);
  if ($rt !== 0) return $rt;

  $byTotal = $B['total'] <=> $A['total'];
  if ($byTotal !== 0) return $byTotal;

  $c = strnatcasecmp($A['marca'],$B['marca']); if ($c!==0) return $c;
  $c = strnatcasecmp($A['modelo'],$B['modelo']); if ($c!==0) return $c;
  return strnatcasecmp($A['capacidad'],$B['capacidad']);
});

/* Totales */
$colTotals  = [];
$grandTotal = 0;
if ($verSuc) { foreach ($useSuc as $sid => $name) $colTotals[$sid] = 0; }

foreach ($rows as $row) {
  if ($verSuc) {
    foreach ($useSuc as $sid => $name) {
      $q = $row['sucs'][$sid] ?? 0;
      $colTotals[$sid] += $q;
    }
  }
  $grandTotal += (int)$row['total'];
}

// 4 columnas fijas (Tipo, Marca, Modelo, Capacidad) + (sucursales?) + Total
$colspan = 4 + ($verSuc ? max(1, count($useSuc)) : 0) + 1;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Resumen de inventario</title>
<link rel="icon" href="/img/favicon.ico?v=5" sizes="any">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --header-bg:#fff;
    --z-body-fixed: 6;
    --z-head: 8;
    --z-head-fixed-1: 15;
    --z-head-fixed-2: 14;
    --z-head-fixed-3: 13;
    --z-head-fixed-4: 12;
  }
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;background:#f8fafc}
  .container{max-width:1300px;margin:14px auto;padding:0 12px}
  h2{margin:0 0 10px 0}

  .filters{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:8px 0 12px}
  .filters .group{display:flex;flex-direction:column;gap:4px}
  .filters select{padding:6px 8px;border:1px solid #cbd5e1;border-radius:8px;background:#fff}
  .btn{padding:8px 12px;border:1px solid #2563eb;background:#2563eb;color:#fff;border-radius:8px;cursor:pointer}
  .btn.secondary{background:#fff;color:#111;border-color:#cbd5e1}
  .btn.ghost{background:#eef2ff;color:#0b5ed7;border-color:#dbeafe}

  .table-scroll{position:relative;max-height:70vh;overflow:auto;border:1px solid #e5e7eb;background:#fff;border-radius:8px}

  table{border-collapse:separate;border-spacing:0;width:100%;white-space:nowrap}
  th,td{border-bottom:1px solid #edf2f7;padding:8px 10px;text-align:right}
  thead th{background:var(--header-bg);font-weight:600;border-bottom:2px solid #e5e7eb;position:sticky;top:0;z-index:var(--z-head)}
  tbody td:first-child, tbody td:nth-child(2), tbody td:nth-child(3), tbody td:nth-child(4){text-align:left}
  .num{font-variant-numeric:tabular-nums}

  /* Columnas fijas (desktop) */
  .fixed{position:sticky;background:#fff}
  .fixed-1{left:0}
  .fixed-2{left:var(--left-col-2,110px)}
  .fixed-3{left:var(--left-col-3,270px)}
  .fixed-4{left:var(--left-col-4,450px)}
  .fixed-1, .fixed-2, .fixed-3, .fixed-4{box-shadow:1px 0 0 0 #e5e7eb inset}

  thead th.fixed-1{z-index:var(--z-head-fixed-1)}
  thead th.fixed-2{z-index:var(--z-head-fixed-2)}
  thead th.fixed-3{z-index:var(--z-head-fixed-3)}
  thead th.fixed-4{z-index:var(--z-head-fixed-4)}
  tbody td.fixed{z-index:var(--z-body-fixed)}
  tfoot th.fixed{z-index:var(--z-body-fixed)}

  /* Encabezados verticales para columnas dinámicas */
  thead th{
    writing-mode:vertical-rl;
    transform: rotate(180deg);
    text-align:left;
    vertical-align:bottom;
    min-width:28px;
    padding:6px 4px;
  }
  tbody tr:hover td, tbody tr:hover .fixed{background:#eef4ff !important}

  .badge-tipo{
    display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:700;
    border:1px solid #dbeafe; background:#eef2ff; color:#0b5ed7;
  }
  .badge-modem{ background:#ffeef2; border-color:#ffdbe6; color:#b1083e; }

  .cell-modelo{
    display:inline-flex; align-items:center; gap:8px;
    padding:4px 10px; border-radius:999px;
    background:#eef2ff; color:#0b5ed7;
    border:1px solid #dbeafe; cursor:pointer;
    transition:background .15s, border-color .15s, box-shadow .15s;
    font-weight:600;
  }
  .cell-modelo:hover{ background:#e0e7ff; border-color:#c7d2fe; box-shadow:0 0 0 2px #e0e7ff; }
  .item-row.open .cell-modelo{ background:#dbeafe; border-color:#93c5fd; }

  .cell-modelo .chev{
    width:0; height:0;
    border-top:5px solid transparent;
    border-bottom:5px solid transparent;
    border-left:6px solid currentColor;
    transition:transform .2s ease;
  }
  .item-row.open .cell-modelo .chev{ transform:rotate(90deg); }

  .detail-row td{ background:#fbfbff; border-bottom:1px solid #e5e7eb; }
  .detail-row .detail-box{ padding:10px 12px; animation:fadeIn .15s ease-in; }
  @keyframes fadeIn{ from{opacity:.4} to{opacity:1} }

  .spinner{display:inline-block; width:18px; height:18px; border:2px solid #cbd5e1; border-top-color:#2563eb; border-radius:50%; animation:spin 0.8s linear infinite; vertical-align:middle}
  @keyframes spin{to{transform:rotate(360deg)}}
  .mini{font-size:13px}

  /* ===== RESPONSIVE (MÓVIL) ===== */
  @media (max-width: 768px){
    .container{padding:0 8px}
    .table-scroll{max-height:none; overflow:auto}
    table{white-space:normal}
    th,td{padding:8px 8px}

    thead th{ position:static !important; top:auto !important; z-index:auto !important; }

    thead th{
      writing-mode:horizontal-tb;
      transform:none;
      text-align:left;
      min-width:auto;
      padding:8px 6px;
    }
    thead th.suc-th{
      writing-mode:vertical-rl !important;
      transform: rotate(180deg) !important;
      text-align:left !important;
      vertical-align:bottom !important;
      min-width:26px !important;
      padding:6px 4px !important;
    }
  }
</style>

</head>
<body>
<div class="container">
  <h2>Resumen de inventario</h2>

  <form class="filters" method="get">
    <div class="group">
      <label for="sucursal">Sucursal</label>
      <select name="sucursal" id="sucursal">
        <option value="0">Todas</option>
        <?php foreach($catSuc as $id=>$nom): ?>
          <option value="<?=$id?>" <?=$id===$fSucursal?'selected':''?>><?=h($nom)?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="group">
      <label for="tipo">Tipo</label>
      <select name="tipo" id="tipo">
        <option value="ambos"  <?=$fTipo==='ambos' ? 'selected':''?>>Equipo + Módem + Accesorios</option>
        <option value="equipo" <?=$fTipo==='equipo'? 'selected':''?>>Solo Equipo</option>
        <option value="modem"  <?=$fTipo==='modem' ? 'selected':''?>>Solo Módem/MiFi</option>
        <option value="accesorio" <?=$fTipo==='accesorio' ? 'selected':''?>>Solo Accesorios</option>
      </select>
    </div>

    <div class="group">
      <label for="marca">Marca</label>
      <select name="marca" id="marca">
        <option value="">Todas</option>
        <?php foreach($catMarcas as $m): ?>
          <option value="<?=h($m)?>" <?=($m===$fMarca)?'selected':''?>><?=h($m)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="group">
      <label for="modelo">Modelo</label>
      <select name="modelo" id="modelo">
        <option value="">Todos</option>
        <?php foreach($catModelos as $m): ?>
          <option value="<?=h($m)?>" <?=($m===$fModelo)?'selected':''?>><?=h($m)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="group">
      <label for="capacidad">Capacidad</label>
      <select name="capacidad" id="capacidad">
        <option value="">Todas</option>
        <?php foreach($catCaps as $c): ?>
          <option value="<?=h($c)?>" <?=($c===$fCapacidad)?'selected':''?>><?=h($c)?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="group">
      <label>&nbsp;</label>
      <button class="btn" type="submit">Filtrar</button>
    </div>

    <?php if ($fSucursal||$fMarca||$fModelo||$fCapacidad||$verSuc||($fTipo!=='ambos')): ?>
    <div class="group">
      <label>&nbsp;</label>
      <a class="btn secondary" href="inventario_resumen.php">Limpiar</a>
    </div>
    <?php endif; ?>

    <div class="group" style="margin-left:auto">
      <label>&nbsp;</label>
      <?php if ($verSuc): ?>
        <a class="btn ghost" href="?<?=http_build_query(array_merge($_GET, ['ver_suc'=>0]))?>">Vista compacta</a>
      <?php else: ?>
        <a class="btn ghost" href="?<?=http_build_query(array_merge($_GET, ['ver_suc'=>1]))?>">Ver por sucursal</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="table-scroll" id="tblWrap">
    <table id="resumenTbl">
      <thead>
        <tr>
          <th class="fixed fixed-1" style="text-align:left">Tipo</th>
          <th class="fixed fixed-2" style="text-align:left">Marca</th>
          <th class="fixed fixed-3" style="text-align:left">Modelo</th>
          <th class="fixed fixed-4" style="text-align:left">Capacidad</th>

          <?php if ($verSuc): ?>
            <?php if (empty($useSuc)): ?>
              <th class="suc-th">—</th>
            <?php else: foreach($useSuc as $sid=>$sNom): ?>
              <th class="suc-th"><?=h($sNom)?></th>
            <?php endforeach; endif; ?>
          <?php endif; ?>

          <th class="total-th">Total</th>
        </tr>
      </thead>

      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td class="fixed fixed-1" colspan="<?=$colspan?>" style="text-align:center;color:#64748b">
              Sin resultados con los filtros seleccionados.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach($rows as $row): 
                $tipoLower = mb_strtolower($row['tipo'],'UTF-8');
                $isModem   = in_array($tipoLower, ['modem','módem','mifi','mi-fi','mifi'], true);
          ?>
            <tr class="item-row"
                data-tipo="<?=h($row['tipo'])?>"
                data-marca="<?=h($row['marca'])?>"
                data-modelo="<?=h($row['modelo'])?>"
                data-capacidad="<?=h($row['capacidad'])?>"
                data-sucursal="<?= (int)$fSucursal ?>">
              <td class="fixed fixed-1">
                <span class="badge-tipo <?= $isModem ? 'badge-modem' : '' ?>">
                  <?=h($row['tipo'])?>
                </span>
              </td>
              <td class="fixed fixed-2"><?=h($row['marca'])?></td>
              <td class="fixed fixed-3" title="Ver detalle por sucursal y color">
                <button type="button" class="cell-modelo" aria-expanded="false">
                  <span class="chev" aria-hidden="true"></span>
                  <span class="label"><?=h($row['modelo'])?></span>
                </button>
              </td>
              <td class="fixed fixed-4"><?=h($row['capacidad'])?></td>

              <?php if ($verSuc): ?>
                <?php if (empty($useSuc)): ?>
                  <td class="num">0</td>
                <?php else: foreach($useSuc as $sid=>$sNom):
                        $q = $row['sucs'][$sid] ?? 0; ?>
                  <td class="num"><?= $q ?: '0' ?></td>
                <?php endforeach; endif; ?>
              <?php endif; ?>

              <td class="total-td num"><?= (int)$row['total'] ?></td>
            </tr>
            <tr class="detail-row" style="display:none">
              <td colspan="<?=$colspan?>" class="mini">
                <div class="detail-box">
                  <span class="spinner"></span> Cargando detalle...
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>

      <tfoot>
        <tr>
          <th class="fixed fixed-1" style="text-align:left">Totales</th>
          <th class="fixed fixed-2"></th>
          <th class="fixed fixed-3"></th>
          <th class="fixed fixed-4"></th>

          <?php if ($verSuc): ?>
            <?php if (empty($useSuc)): ?>
              <th class="num">0</th>
            <?php else: foreach($useSuc as $sid=>$sNom): ?>
              <th class="num"><?= (int)$colTotals[$sid] ?></th>
            <?php endforeach; endif; ?>
          <?php endif; ?>

          <th class="num"><?= (int)$grandTotal ?></th>
        </tr>
      </tfoot>
    </table>
  </div>

  
</div>

<script>
/* Forzar modo móvil quitando clases sticky y restaurar en desktop */
function stripFixedForMobile(){
  const isMobile = window.matchMedia('(max-width: 768px)').matches;
  const tbl = document.getElementById('resumenTbl');
  if (!tbl) return;

  const fixedNodes = tbl.querySelectorAll('.fixed-1, .fixed-2, .fixed-3, .fixed-4, td.fixed, th.fixed');

  if (isMobile){
    fixedNodes.forEach(el=>{
      if (!el.dataset.origClass){
        el.dataset.origClass = el.className;
      }
      el.classList.remove('fixed','fixed-1','fixed-2','fixed-3','fixed-4');
      el.style.left = '';
      el.style.position = '';
      el.style.zIndex = '';
    });
    document.querySelectorAll('thead th').forEach(th=>{
      th.style.position = 'static';
      th.style.top = 'auto';
      th.style.zIndex = 'auto';
    });
  } else {
    document.querySelectorAll('[data-orig-class]').forEach(el=>{
      el.className = el.dataset.origClass;
    });
    fixedNodes.forEach(el=>{
      if (el.dataset.origClass){ el.className = el.dataset.origClass; }
      el.style.left = '';
      el.style.position = '';
      el.style.zIndex = '';
    });
    document.querySelectorAll('thead th').forEach(th=>{
      th.style.position = '';
      th.style.top = '';
      th.style.zIndex = '';
    });
    setStickyOffsets();
  }
}

/* Recalcular offsets para 4 columnas fijas (desktop) */
function setStickyOffsets(){
  const isMobile = window.matchMedia('(max-width: 768px)').matches;
  const tbl = document.getElementById('resumenTbl');
  if (!tbl || isMobile) return;

  const c1 = tbl.querySelector('thead .fixed-1');
  const c2 = tbl.querySelector('thead .fixed-2');
  const c3 = tbl.querySelector('thead .fixed-3');
  if (!c1 || !c2 || !c3) return;

  const w1 = c1.getBoundingClientRect().width;
  const w2 = c2.getBoundingClientRect().width;
  const w3 = c3.getBoundingClientRect().width;

  const left2 = Math.round(w1);
  const left3 = Math.round(w1 + w2);
  const left4 = Math.round(w1 + w2 + w3);

  tbl.style.setProperty('--left-col-2', left2 + 'px');
  tbl.style.setProperty('--left-col-3', left3 + 'px');
  tbl.style.setProperty('--left-col-4', left4 + 'px');
}

window.addEventListener('load', () => { stripFixedForMobile(); setStickyOffsets(); });
window.addEventListener('resize', () => { stripFixedForMobile(); setStickyOffsets(); });
const wrap = document.getElementById('tblWrap');
if (wrap) { new ResizeObserver(() => { stripFixedForMobile(); setStickyOffsets(); }).observe(wrap); }

/* Toggle detalle por modelo */
document.querySelectorAll('#resumenTbl tbody tr.item-row').forEach(function(row){
  const btn = row.querySelector('.cell-modelo');
  const detailRow = row.nextElementSibling;

  btn.addEventListener('click', async function(e){
    e.preventDefault();
    document.querySelectorAll('#resumenTbl tbody tr.detail-row').forEach(dr=>{
      if (dr !== detailRow) dr.style.display = 'none';
    });
    document.querySelectorAll('#resumenTbl tbody tr.item-row.open').forEach(r=>{
      if (r !== row) { r.classList.remove('open'); const b=r.querySelector('.cell-modelo'); if(b) b.setAttribute('aria-expanded','false'); }
    });

    const isOpen = detailRow.style.display === 'table-row';
    if (isOpen) {
      detailRow.style.display = 'none';
      row.classList.remove('open');
      btn.setAttribute('aria-expanded','false');
      return;
    }

    detailRow.style.display = 'table-row';
    row.classList.add('open');
    btn.setAttribute('aria-expanded','true');

    const box = detailRow.querySelector('.detail-box');
    box.innerHTML = '<span class="spinner"></span> Cargando detalle...';

    const tipo      = encodeURIComponent(row.dataset.tipo || '');
    const marca     = encodeURIComponent(row.dataset.marca || '');
    const modelo    = encodeURIComponent(row.dataset.modelo || '');
    const capacidad = encodeURIComponent(row.dataset.capacidad || '');
    const sucursal  = encodeURIComponent(row.dataset.sucursal || '0');

    try{
      // Le pasamos "tipo" por si tu inventario_resumen_detalle.php decide filtrar también accesorios
      const resp = await fetch(`inventario_resumen_detalle.php?tipo=${tipo}&marca=${marca}&modelo=${modelo}&capacidad=${capacidad}&sucursal=${sucursal}`);
      const html = await resp.text();
      box.innerHTML = html;
    }catch(err){
      box.innerHTML = '<span class="badge" style="background:#fee2e2;color:#991b1b">Error al cargar detalle</span>';
    }
  });

  btn.addEventListener('keydown', (e)=>{
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); btn.click(); }
  });
});
</script>
</body>
</html>
