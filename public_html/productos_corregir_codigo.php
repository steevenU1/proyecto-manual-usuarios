<?php
// productos_corregir_codigo.php — Vista temporal para corregir codigo_producto
// Requiere: tablas productos y catalogo_modelos
// ✅ Corrige atributos desde catalogo_modelos
// ✅ Corrige precio_lista con prioridad: Override > Historial > Catálogo > Actual
// ✅ Opción Admin: actualizar catalogo_modelos.precio_lista con el precio aplicado

session_start();

$ROL = $_SESSION['rol'] ?? '';
if (!isset($_SESSION['id_usuario']) || !in_array($ROL, ['Admin','GerenteZona','Logistica'], true)) {
  header("Location: 403.php"); exit();
}

require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function toFloatOrNull($v): ?float {
  if ($v === null) return null;
  $v = trim((string)$v);
  if ($v === '') return null;
  $v = str_replace([',',' '], ['',''], $v);
  if (!is_numeric($v)) return null;
  return (float)$v;
}

/* ===== Estado UI ===== */
$msgOk = $msgErr = '';
$producto = null;
$previewNuevo = null;

// sugerencias de precio
$precioCat = null;          // precio_lista de catalogo_modelos
$precioSugeridoProd = null; // precio_lista sugerido por productos existentes con mismo código
$precioFinalPreview = null; // lo que se aplicaría

/* ===== Buscar por IMEI o ID ===== */
$q = trim($_GET['q'] ?? '');
$modo = $_GET['modo'] ?? 'imei'; // 'imei' | 'id'

if ($q !== '') {
  if ($modo === 'id' && ctype_digit($q)) {
    $stmt = $conn->prepare("SELECT * FROM productos WHERE id=? LIMIT 1");
    $pid = (int)$q;
    $stmt->bind_param("i", $pid);
  } else {
    $like = "%".$q."%";
    $stmt = $conn->prepare("SELECT * FROM productos WHERE imei1 LIKE ? OR imei2 LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("ss", $like, $like);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  $producto = $res->fetch_assoc();
  $stmt->close();
  if (!$producto) {
    $msgErr = "No se encontró el producto para “".h($q)."”.";
  }
}

/* ===== POST: Previsualizar / Aplicar ===== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $accion = $_POST['accion'] ?? '';
  $idProd = (int)($_POST['id_producto'] ?? 0);
  $nuevoCodigo = trim($_POST['nuevo_codigo'] ?? '');

  // override opcional de precio
  $precioOverride = toFloatOrNull($_POST['precio_lista_override'] ?? null);

  // checkbox opcional: actualizar catálogo (solo Admin)
  $actualizarCatalogo = isset($_POST['actualizar_catalogo']) && $ROL === 'Admin';

  // re-cargar producto
  if ($idProd > 0) {
    $rp = $conn->prepare("SELECT * FROM productos WHERE id=? LIMIT 1");
    $rp->bind_param("i", $idProd);
    $rp->execute();
    $producto = $rp->get_result()->fetch_assoc();
    $rp->close();
  }

  if (!$producto) {
    $msgErr = "Producto no válido.";
  } elseif ($nuevoCodigo === '') {
    $msgErr = "Indica el nuevo código.";
  } else {
    // obtener fila del catálogo (sólo activos)
    $rc = $conn->prepare("SELECT * FROM catalogo_modelos WHERE codigo_producto=? AND (activo=1 OR activo IS NULL) LIMIT 1");
    $rc->bind_param("s", $nuevoCodigo);
    $rc->execute();
    $previewNuevo = $rc->get_result()->fetch_assoc();
    $rc->close();

    if (!$previewNuevo) {
      $msgErr = "El código “".h($nuevoCodigo)."” no existe (o está inactivo) en el catálogo.";
    } else {
      // precio catálogo
      $precioCat = toFloatOrNull($previewNuevo['precio_lista'] ?? null);

      // sugerencia por productos existentes con mismo código (excluye el producto actual)
      $rsug = $conn->prepare("
        SELECT precio_lista
        FROM productos
        WHERE codigo_producto = ?
          AND id <> ?
          AND precio_lista IS NOT NULL
          AND precio_lista > 0
        ORDER BY id DESC
        LIMIT 1
      ");
      $rsug->bind_param("si", $nuevoCodigo, $idProd);
      $rsug->execute();
      $rowSug = $rsug->get_result()->fetch_assoc();
      $rsug->close();
      $precioSugeridoProd = toFloatOrNull($rowSug['precio_lista'] ?? null);

      // ✅ ¿Qué precio se aplicaría si confirmas? (prioridad: override > historial > catálogo > actual)
      $precioActualProd = toFloatOrNull($producto['precio_lista'] ?? null);

      if ($precioOverride !== null) {
        $precioFinalPreview = $precioOverride;
      } elseif ($precioSugeridoProd !== null) {
        $precioFinalPreview = $precioSugeridoProd;
      } elseif ($precioCat !== null) {
        $precioFinalPreview = $precioCat;
      } else {
        $precioFinalPreview = $precioActualProd;
      }

      if ($accion === 'aplicar') {
        $conn->begin_transaction();
        try {
          // Campos que SÍ actualizamos desde el catálogo
          $fields = [
            'codigo_producto','marca','modelo','color','ram','capacidad','descripcion',
            'nombre_comercial','compania','financiera','fecha_lanzamiento',
            'tipo_producto','subtipo','gama','ciclo_vida','abc','operador','resurtible'
          ];

          // SET dinámico para atributos
          $set = implode('=?, ', $fields) . '=?';

          // ✅ Añadimos precio_lista como campo extra
          $sql = "UPDATE productos SET $set, precio_lista=? WHERE id=?";
          $stmt = $conn->prepare($sql);

          // Valores en el mismo orden que $fields
          $vals = [];
          foreach ($fields as $k) {
            $vals[] = $previewNuevo[$k] ?? null; // permitimos NULL
          }

          // precio_lista final
          $precioFinal = $precioFinalPreview;
          $precioFinalParam = ($precioFinal === null) ? null : (string)$precioFinal;

          // Tipos: N strings + 1 string (precio) + 1 entero (id)
          $types = str_repeat('s', count($fields)) . 'si';

          // bind_param necesita variables por referencia; armamos array con refs
          $bind = [];
          $bind[] = &$types;

          // convertir $vals a variables referenciables
          $tmp = [];
          foreach ($vals as $i => $v) {
            $tmp[$i] = $v;
            $bind[] = &$tmp[$i];
          }

          $tmpPrecio = $precioFinalParam;
          $tmpId     = $idProd;

          $bind[] = &$tmpPrecio;
          $bind[] = &$tmpId;

          call_user_func_array([$stmt, 'bind_param'], $bind);

          $stmt->execute();
          $stmt->close();

          // ✅ (Opcional) actualizar catálogo con el precio final (solo Admin y si lo marcó)
          if ($actualizarCatalogo && $precioFinal !== null) {
            $uc = $conn->prepare("UPDATE catalogo_modelos SET precio_lista=? WHERE codigo_producto=? LIMIT 1");
            $precioStr = (string)$precioFinal;
            $uc->bind_param("ss", $precioStr, $nuevoCodigo);
            $uc->execute();
            $uc->close();
          }

          $conn->commit();

          $msgOk = "Se actualizó el producto #{$idProd} al código ".h($nuevoCodigo).". ✅ También se sincronizó precio_lista.";

          if ($actualizarCatalogo && $precioFinal !== null) {
            $msgOk .= " (Catálogo actualizado con el nuevo precio).";
          }

          // Recargar producto actualizado para la UI
          $rp = $conn->prepare("SELECT * FROM productos WHERE id=? LIMIT 1");
          $rp->bind_param("i", $idProd);
          $rp->execute();
          $producto = $rp->get_result()->fetch_assoc();
          $rp->close();

          $previewNuevo = null;

        } catch (Throwable $e) {
          $conn->rollback();
          $msgErr = "Error al actualizar: ".$e->getMessage();
        }
      }
    }
  }
}

/* ===== Autocomplete: códigos del catálogo (limit) ===== */
$opCat = $conn->query("SELECT codigo_producto, marca, modelo, color, ram, capacidad, precio_lista FROM catalogo_modelos WHERE (activo=1 OR activo IS NULL) ORDER BY marca, modelo LIMIT 500");
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Corrección temporal de codigo_producto</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{ background:#f7f7fb; }
    .card-elev{ border:0; border-radius:1rem; box-shadow:0 12px 26px rgba(0,0,0,.06), 0 2px 6px rgba(0,0,0,.05); }
    .kv{ display:flex; gap:8px; align-items:flex-start; }
    .kv .k{ width:170px; color:#64748b; }
    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    .diff-old{ background:#fff1f2; }
    .diff-new{ background:#ecfdf5; }
    .pill{ display:inline-block; padding:.15rem .5rem; border-radius:999px; font-size:.75rem; }
  </style>
</head>
<body>
<div class="container my-4" style="max-width:980px;">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">🛠️ Corrección temporal de <code>codigo_producto</code></h3>
    <a href="inventario_global.php" class="btn btn-outline-secondary btn-sm">Volver</a>
  </div>

  <?php if ($msgOk): ?>
    <div class="alert alert-success"><?= $msgOk ?></div>
  <?php endif; ?>
  <?php if ($msgErr): ?>
    <div class="alert alert-danger"><?= $msgErr ?></div>
  <?php endif; ?>

  <div class="card card-elev mb-4">
    <div class="card-body">
      <form class="row g-3" method="get">
        <div class="col-md-3">
          <label class="form-label">Buscar por</label>
          <select name="modo" class="form-select">
            <option value="imei" <?= $modo==='imei'?'selected':'' ?>>IMEI (1 o 2)</option>
            <option value="id"   <?= $modo==='id'  ?'selected':'' ?>>ID producto</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= $modo==='id'?'ID de producto':'IMEI' ?></label>
          <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="<?= $modo==='id'?'Ej. 12345':'Ej. 35364712…' ?>">
        </div>
        <div class="col-md-3 d-flex align-items-end">
          <button class="btn btn-primary w-100">Buscar</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($producto): ?>
    <div class="card card-elev mb-4">
      <div class="card-header fw-bold">Producto encontrado #<?= (int)$producto['id'] ?></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="border rounded p-3 h-100">
              <h6 class="text-muted mb-2">Actual</h6>
              <?php
                $show = [
                  'codigo_producto','marca','modelo','color','ram','capacidad','descripcion',
                  'nombre_comercial','compania','financiera','fecha_lanzamiento',
                  'tipo_producto','subtipo','gama','ciclo_vida','abc','operador','resurtible',
                  'imei1','imei2','costo','costo_con_iva','precio_lista'
                ];
                foreach ($show as $k):
              ?>
                <div class="kv"><div class="k"><?= h($k) ?></div><div class="v mono"><?= h($producto[$k] ?? '—') ?></div></div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="col-md-6">
            <form method="post" class="border rounded p-3 h-100">
              <h6 class="text-muted mb-2">Nuevo código del catálogo</h6>
              <input type="hidden" name="id_producto" value="<?= (int)$producto['id'] ?>">

              <div class="mb-2">
                <label class="form-label">Nuevo código</label>
                <input list="codes" name="nuevo_codigo" class="form-control mono" value="<?= h($_POST['nuevo_codigo'] ?? '') ?>" required>
                <datalist id="codes">
                  <?php if ($opCat) while($c=$opCat->fetch_assoc()): ?>
                    <option value="<?= h($c['codigo_producto']) ?>">
                      <?= h($c['marca'].' '.$c['modelo'].' '.$c['color'].' '.$c['ram'].' '.$c['capacidad'].' | $'.($c['precio_lista'] ?? '—')) ?>
                    </option>
                  <?php endwhile; ?>
                </datalist>
                <div class="form-text">Elige un código activo del catálogo (autocompleta).</div>
              </div>

              <?php if ($previewNuevo): ?>
                <div class="alert alert-info py-2 mb-2">
                  Previsualización de atributos a aplicar desde el catálogo para
                  <strong><?= h($previewNuevo['codigo_producto']) ?></strong>.
                </div>

                <?php
                  $pCat = ($precioCat === null) ? '—' : number_format($precioCat, 2);
                  $pSug = ($precioSugeridoProd === null) ? '—' : number_format($precioSugeridoProd, 2);
                  $pAct = toFloatOrNull($producto['precio_lista'] ?? null);
                  $pActTxt = ($pAct === null) ? '—' : number_format($pAct, 2);
                  $pFin = ($precioFinalPreview === null) ? '—' : number_format($precioFinalPreview, 2);
                ?>

                <div class="border rounded p-2 mb-3">
                  <div class="small text-muted mb-1">Precio lista (referencias)</div>
                  <div class="d-flex flex-wrap gap-2">
                    <div><span class="pill bg-light text-dark">Actual producto: <span class="mono">$<?= h($pActTxt) ?></span></span></div>
                    <div><span class="pill bg-light text-dark">Catálogo: <span class="mono">$<?= h($pCat) ?></span></span></div>
                    <div><span class="pill bg-light text-dark">Sugerido por historial: <span class="mono">$<?= h($pSug) ?></span></span></div>
                    <div><span class="pill bg-success text-white">Se aplicará: <span class="mono">$<?= h($pFin) ?></span></span></div>
                  </div>
                </div>

                <div class="mb-3">
                  <label class="form-label">Precio lista a aplicar (opcional)</label>
                  <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="precio_lista_override"
                    class="form-control mono"
                    value="<?= h($_POST['precio_lista_override'] ?? '') ?>"
                    placeholder="<?= ($precioSugeridoProd !== null) ? 'Sugerido (historial): '.$pSug : (($precioCat !== null) ? 'Sugerido (catálogo): '.$pCat : 'Ej. 4999.00') ?>"
                  >
                  <div class="form-text">
                    Si lo dejas vacío, se usará el precio sugerido por historial (si existe).
                    Si no hay historial, se usa el del catálogo. Si tampoco hay, se conserva el actual.
                  </div>
                </div>

                <?php if ($ROL === 'Admin'): ?>
                  <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="actualizar_catalogo" id="actualizar_catalogo"
                      <?= isset($_POST['actualizar_catalogo']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="actualizar_catalogo">
                      También actualizar <code>catalogo_modelos.precio_lista</code> con el precio que se aplique
                    </label>
                    <div class="form-text">Útil si detectaste que el catálogo está desactualizado.</div>
                  </div>
                <?php endif; ?>

                <div class="mb-3" style="max-height:280px; overflow:auto;">
                  <?php
                    $cmp = [
                      'codigo_producto','marca','modelo','color','ram','capacidad','descripcion',
                      'nombre_comercial','compania','financiera','fecha_lanzamiento',
                      'tipo_producto','subtipo','gama','ciclo_vida','abc','operador','resurtible'
                    ];
                    foreach ($cmp as $k):
                      $old = $producto[$k] ?? '';
                      $new = $previewNuevo[$k] ?? '';
                      $diffClassOld = ($old!==$new)?'diff-old':'';
                      $diffClassNew = ($old!==$new)?'diff-new':'';
                  ?>
                    <div class="kv">
                      <div class="k"><?= h($k) ?></div>
                      <div class="v mono me-2 px-1 <?= $diffClassOld ?>"><?= h($old===''?'—':$old) ?></div>
                      <div class="v mono px-1 <?= $diffClassNew ?>"><?= h($new===''?'—':$new) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>

                <div class="d-flex gap-2">
                  <button name="accion" value="aplicar" class="btn btn-success">✅ Confirmar y actualizar</button>
                  <a href="?q=<?= urlencode($producto['imei1'] ?: (string)$producto['id']) ?>&modo=<?= $modo ?>" class="btn btn-outline-secondary">Cancelar</a>
                </div>
              <?php else: ?>
                <div class="d-flex gap-2">
                  <button name="accion" value="previsualizar" class="btn btn-outline-primary">🔍 Previsualizar</button>
                  <button name="accion" value="aplicar" class="btn btn-success" onclick="return confirm('¿Aplicar directamente los cambios?');">✅ Aplicar directo</button>
                </div>
              <?php endif; ?>
            </form>
          </div>
        </div>
      </div>
    </div>
  <?php elseif ($q !== '' && !$msgErr): ?>
    <div class="alert alert-warning">No se encontró resultado.</div>
  <?php endif; ?>

</div>
</body>
</html>
