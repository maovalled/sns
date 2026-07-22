<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$u = requerirLogin(['admin', 'recepcion']);
$esAdmin = $u['rol'] === 'admin'; // solo el admin ve/edita el costo
$activo = 'inventario';
$tituloAdmin = 'Inventario';
$pdo = db();
$mensaje = null;
$error = null;

// Guarda categoría/ubicación en su catálogo para reutilizarlas en otros productos.
$registrarCat  = function (string $c) use ($pdo) {
    if ($c !== '') $pdo->prepare('INSERT IGNORE INTO categorias_producto (nombre) VALUES (?)')->execute([$c]);
};
$registrarUbic = function (string $x) use ($pdo) {
    if ($x !== '') $pdo->prepare('INSERT IGNORE INTO ubicaciones (nombre) VALUES (?)')->execute([$x]);
};
$fechaValida = fn(string $f): ?string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) ? $f : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['nuevo'])) {
        $nombre    = trim($_POST['nombre'] ?? '');
        $categoria = trim($_POST['categoria'] ?? '');
        $ubicacion = trim($_POST['ubicacion'] ?? '');
        $marca     = trim($_POST['marca'] ?? '');
        $unidad    = trim($_POST['unidad'] ?? 'unidad') ?: 'unidad';
        $stock     = max(0, (int)($_POST['stock'] ?? 0));
        $minimo    = max(0, (int)($_POST['stock_minimo'] ?? 0));
        $valor     = ($esAdmin && ($_POST['valor'] ?? '') !== '') ? max(0, (int)$_POST['valor']) : null;
        $fc        = $fechaValida($_POST['fecha_compra'] ?? '');
        if ($nombre === '') {
            $error = 'El nombre del producto es obligatorio.';
        } else {
            $pdo->prepare('INSERT INTO productos (nombre, categoria, ubicacion, marca, unidad, valor, fecha_compra, stock, stock_minimo) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$nombre, $categoria ?: null, $ubicacion ?: null, $marca ?: null, $unidad, $valor, $fc, $stock, $minimo]);
            $registrarCat($categoria);
            $registrarUbic($ubicacion);
            $mensaje = 'Producto agregado.';
        }
    } elseif (isset($_POST['editar_id'])) {
        // Edición completa de la ficha del producto.
        $id        = (int)$_POST['editar_id'];
        $nombre    = trim($_POST['nombre'] ?? '');
        $categoria = trim($_POST['categoria'] ?? '');
        $ubicacion = trim($_POST['ubicacion'] ?? '');
        $marca     = trim($_POST['marca'] ?? '');
        $unidad    = trim($_POST['unidad'] ?? 'unidad') ?: 'unidad';
        $stock     = max(0, (int)($_POST['stock'] ?? 0));
        $minimo    = max(0, (int)($_POST['stock_minimo'] ?? 0));
        $fc        = $fechaValida($_POST['fecha_compra'] ?? '');
        if ($nombre === '') {
            $error = 'El nombre del producto es obligatorio.';
        } else {
            $pdo->prepare('UPDATE productos SET nombre=?, categoria=?, ubicacion=?, marca=?, unidad=?, fecha_compra=?, stock=?, stock_minimo=? WHERE id=?')
                ->execute([$nombre, $categoria ?: null, $ubicacion ?: null, $marca ?: null, $unidad, $fc, $stock, $minimo, $id]);
            // El costo solo lo toca el admin (y solo si escribió algo).
            if ($esAdmin && ($_POST['valor'] ?? '') !== '') {
                $pdo->prepare('UPDATE productos SET valor=? WHERE id=?')->execute([max(0, (int)$_POST['valor']), $id]);
            }
            $registrarCat($categoria);
            $registrarUbic($ubicacion);
            $mensaje = 'Producto actualizado.';
        }
    } elseif (isset($_POST['ajuste_id'])) {
        $id     = (int)$_POST['ajuste_id'];
        $cant   = (int)($_POST['cantidad'] ?? 0);
        $valorA = ($esAdmin && ($_POST['ajuste_valor'] ?? '') !== '') ? max(0, (int)$_POST['ajuste_valor']) : null;
        $fcA    = $fechaValida($_POST['ajuste_fecha'] ?? '');
        $pdo->prepare('UPDATE productos SET stock = GREATEST(0, stock + ?) WHERE id = ?')->execute([$cant, $id]);
        // Si es una recompra, actualiza el valor y la fecha de compra.
        if ($valorA !== null) $pdo->prepare('UPDATE productos SET valor = ? WHERE id = ?')->execute([$valorA, $id]);
        if ($fcA !== null)    $pdo->prepare('UPDATE productos SET fecha_compra = ? WHERE id = ?')->execute([$fcA, $id]);
        $mensaje = 'Stock actualizado.';
    }
}

$categorias  = $pdo->query('SELECT nombre FROM categorias_producto ORDER BY nombre')->fetchAll(PDO::FETCH_COLUMN);
$ubicaciones = $pdo->query('SELECT nombre FROM ubicaciones ORDER BY orden, nombre')->fetchAll(PDO::FETCH_COLUMN);
$marcas      = $pdo->query("SELECT DISTINCT marca FROM productos WHERE marca IS NOT NULL AND marca <> '' ORDER BY marca")->fetchAll(PDO::FETCH_COLUMN);
$unidades = ['unidad', 'caja', 'paquete', 'frasco', 'libra', 'kilo', 'gramo', 'litro', 'ml', 'botellón', 'par', 'rollo', 'bolsa', 'galón', 'cajonera'];

// KPIs
$totalProductos = (int)$pdo->query('SELECT COUNT(*) FROM productos')->fetchColumn();
$stockBajo      = (int)$pdo->query('SELECT COUNT(*) FROM productos WHERE stock <= stock_minimo')->fetchColumn();

// Resumen por categoría (valor — solo admin)
$resumen = $pdo->query("SELECT COALESCE(NULLIF(categoria,''),'(sin categoría)') AS categoria, COUNT(*) AS n,
                               COALESCE(SUM(valor * stock),0) AS valor_total
                        FROM productos GROUP BY categoria ORDER BY valor_total DESC, categoria")->fetchAll();
$valorInventario = array_sum(array_map(fn($r) => (int)$r['valor_total'], $resumen));

// Resumen por ubicación (para todos: cuántos productos y cuántos en cero por lugar).
// Se ordena en PHP según el catálogo para no cruzar tablas con colaciones distintas.
$resumenUbic = $pdo->query("SELECT COALESCE(NULLIF(ubicacion,''),'(sin ubicación)') AS ubicacion,
                                   COUNT(*) AS n, COALESCE(SUM(stock),0) AS unidades,
                                   SUM(stock <= stock_minimo) AS bajos
                            FROM productos GROUP BY ubicacion")->fetchAll();
$ordenUbic = array_flip($ubicaciones);
usort($resumenUbic, function ($a, $b) use ($ordenUbic) {
    $oa = $ordenUbic[$a['ubicacion']] ?? PHP_INT_MAX;
    $ob = $ordenUbic[$b['ubicacion']] ?? PHP_INT_MAX;
    return $oa === $ob ? (int)$b['n'] <=> (int)$a['n'] : $oa <=> $ob;
});

// Listado (con filtros opcionales por ubicación y categoría)
$filtroUbic = trim($_GET['ubic'] ?? '');
$filtroCat  = trim($_GET['cat'] ?? '');
$where = [];
$paramsP = [];
if ($filtroUbic !== '') { $where[] = 'ubicacion = ?'; $paramsP[] = $filtroUbic; }
if ($filtroCat !== '')  { $where[] = 'categoria = ?'; $paramsP[] = $filtroCat; }
$sqlP = 'SELECT * FROM productos' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ubicacion, categoria, nombre';
$stp = $pdo->prepare($sqlP);
$stp->execute($paramsP);
$productos = $stp->fetchAll();
$colspan = $esAdmin ? 10 : 8;

require __DIR__ . '/includes/top.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?= e($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
  <?php if ($esAdmin): ?>
  <div class="col-6 col-lg-3"><div class="tarjeta-kpi p-3"><div class="valor"><?= precio($valorInventario) ?></div><small>Valor del inventario</small></div></div>
  <?php endif; ?>
  <div class="col-6 col-lg-3"><div class="tarjeta-kpi p-3"><div class="valor"><?= $totalProductos ?></div><small>Productos</small></div></div>
  <div class="col-6 col-lg-3"><div class="tarjeta-kpi p-3"><div class="valor"><?= count($ubicaciones) ?></div><small>Ubicaciones</small></div></div>
  <div class="col-6 col-lg-3"><div class="tarjeta-kpi p-3"><div class="valor"><?= $stockBajo ?></div><small>Agotados / stock bajo</small></div></div>
</div>

<datalist id="listaCategorias"><?php foreach ($categorias as $cat): ?><option value="<?= e($cat) ?>"></option><?php endforeach; ?></datalist>
<datalist id="listaUbicaciones"><?php foreach ($ubicaciones as $ub): ?><option value="<?= e($ub) ?>"></option><?php endforeach; ?></datalist>
<datalist id="listaMarcas"><?php foreach ($marcas as $m): ?><option value="<?= e($m) ?>"></option><?php endforeach; ?></datalist>
<datalist id="listaUnidades"><?php foreach ($unidades as $un): ?><option value="<?= e($un) ?>"></option><?php endforeach; ?></datalist>

<div class="card card-servicio p-3 mb-4">
  <h5 class="text-rosa">Ingresar producto</h5>
  <form method="post" class="row g-2 align-items-end">
    <?= csrfField() ?>
    <input type="hidden" name="nuevo" value="1">
    <div class="col-md-3"><label class="form-label mb-1">Producto</label><input name="nombre" class="form-control" required></div>
    <div class="col-md-3"><label class="form-label mb-1">Ubicación</label><input name="ubicacion" class="form-control" list="listaUbicaciones" value="<?= e($filtroUbic) ?>" placeholder="¿Dónde se guarda?"></div>
    <div class="col-md-2"><label class="form-label mb-1">Categoría</label><input name="categoria" class="form-control" list="listaCategorias" value="<?= e($filtroCat) ?>" placeholder="Elige o escribe"></div>
    <div class="col-md-2"><label class="form-label mb-1">Marca</label><input name="marca" class="form-control" list="listaMarcas"></div>
    <div class="col-md-2"><label class="form-label mb-1">Unidad</label><input name="unidad" class="form-control" list="listaUnidades" value="unidad"></div>
    <?php if ($esAdmin): ?>
    <div class="col-md-2"><label class="form-label mb-1">Valor ($ por unidad)</label><input name="valor" type="number" min="0" step="1" class="form-control" placeholder="Costo real"></div>
    <?php endif; ?>
    <div class="col-md-2"><label class="form-label mb-1">Fecha de compra</label><input name="fecha_compra" type="date" class="form-control" max="<?= date('Y-m-d') ?>"></div>
    <div class="col-md-1"><label class="form-label mb-1">Stock</label><input name="stock" type="number" min="0" class="form-control" value="0"></div>
    <div class="col-md-2"><label class="form-label mb-1">Stock mínimo</label><input name="stock_minimo" type="number" min="0" class="form-control" value="0"></div>
    <div class="col-md-1"><button class="btn btn-sns w-100">＋</button></div>
  </form>
  <small class="text-muted mt-2">La ubicación y la categoría que escribas quedan disponibles para otros productos.</small>
</div>

<form class="row g-2 align-items-end mb-3" method="get">
  <div class="col-6 col-md-3">
    <label class="form-label mb-1">Ubicación</label>
    <select name="ubic" class="form-select" onchange="this.form.submit()">
      <option value="">Todas</option>
      <?php foreach ($ubicaciones as $ub): ?><option value="<?= e($ub) ?>" <?= $filtroUbic === $ub ? 'selected' : '' ?>><?= e($ub) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-6 col-md-3">
    <label class="form-label mb-1">Categoría</label>
    <select name="cat" class="form-select" onchange="this.form.submit()">
      <option value="">Todas</option>
      <?php foreach ($categorias as $cat): ?><option value="<?= e($cat) ?>" <?= $filtroCat === $cat ? 'selected' : '' ?>><?= e($cat) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-4">
    <label class="form-label mb-1">Buscar</label>
    <input type="search" id="buscarProd" class="form-control" placeholder="Nombre, marca…" autocomplete="off">
  </div>
  <?php if ($filtroUbic !== '' || $filtroCat !== ''): ?><div class="col-md-2"><a href="inventario.php" class="btn btn-sns-outline w-100">Ver todo</a></div><?php endif; ?>
</form>

<div class="table-responsive">
<table class="table align-middle">
  <thead class="table-light"><tr>
    <th>Producto</th><th>Ubicación</th><th>Categoría</th><th>Marca</th><th>Stock</th><th>Unidad</th>
    <?php if ($esAdmin): ?><th>Valor unit.</th><?php endif; ?><th>Última compra</th><?php if ($esAdmin): ?><th>Valor total</th><?php endif; ?><th>Ajustar / editar</th>
  </tr></thead>
  <tbody>
  <?php if (!$productos): ?><tr><td colspan="<?= $colspan ?>" class="text-center text-muted py-4">Sin productos<?= ($filtroUbic || $filtroCat) ? ' con este filtro' : ' registrados aún' ?>.</td></tr><?php endif; ?>
  <?php foreach ($productos as $p): ?>
    <?php $id = (int)$p['id']; ?>
    <tr data-buscar="<?= e(mb_strtolower($p['nombre'] . ' ' . ($p['marca'] ?? '') . ' ' . ($p['categoria'] ?? '') . ' ' . ($p['ubicacion'] ?? ''))) ?>">
      <td><?= e($p['nombre']) ?> <?= $p['stock'] <= $p['stock_minimo'] ? '<span class="badge text-bg-danger">' . ((int)$p['stock'] === 0 ? 'agotado' : 'stock bajo') . '</span>' : '' ?></td>
      <td><?= $p['ubicacion'] ? '<span class="badge bg-info-subtle text-info-emphasis border">' . e($p['ubicacion']) . '</span>' : '<small class="text-muted">—</small>' ?></td>
      <td><?= $p['categoria'] ? '<span class="badge text-bg-light border">' . e($p['categoria']) . '</span>' : '<small class="text-muted">—</small>' ?></td>
      <td><?= $p['marca'] ? e($p['marca']) : '<small class="text-muted">—</small>' ?></td>
      <td class="fw-bold"><?= (int)$p['stock'] ?></td>
      <td><?= e($p['unidad']) ?></td>
      <?php if ($esAdmin): ?><td><?= $p['valor'] !== null ? precio($p['valor']) : '<small class="text-muted">—</small>' ?></td><?php endif; ?>
      <td><?= $p['fecha_compra'] ? e(date('d/m/Y', strtotime($p['fecha_compra']))) : '<small class="text-muted">—</small>' ?></td>
      <?php if ($esAdmin): ?><td class="fw-bold text-rosa"><?= $p['valor'] !== null ? precio((int)$p['valor'] * (int)$p['stock']) : '<small class="text-muted">—</small>' ?></td><?php endif; ?>
      <td>
        <form method="post" class="d-flex gap-1 flex-wrap align-items-center">
          <?= csrfField() ?>
          <input type="hidden" name="ajuste_id" value="<?= $id ?>">
          <input type="number" name="cantidad" class="form-control form-control-sm" style="width:82px" placeholder="+5 / -2" required title="Cantidad a sumar o restar">
          <?php if ($esAdmin): ?><input type="number" name="ajuste_valor" class="form-control form-control-sm" style="width:90px" placeholder="Valor $" min="0" title="Nuevo valor (recompra)"><?php endif; ?>
          <input type="date" name="ajuste_fecha" class="form-control form-control-sm" style="width:135px" max="<?= date('Y-m-d') ?>" title="Fecha de compra (recompra)">
          <button class="btn btn-sns btn-sm" title="Aplicar ajuste de stock">✓</button>
          <button type="button" class="btn btn-sns-outline btn-sm" data-bs-toggle="collapse" data-bs-target="#edit-<?= $id ?>" title="Editar ficha">✏️</button>
        </form>
      </td>
    </tr>
    <tr class="fila-edit">
      <td colspan="<?= $colspan ?>" class="p-0 border-0">
        <div class="collapse" id="edit-<?= $id ?>">
          <form method="post" class="row g-2 align-items-end bg-body-tertiary border rounded p-3 mx-1 mb-2">
            <?= csrfField() ?>
            <input type="hidden" name="editar_id" value="<?= $id ?>">
            <div class="col-md-3"><label class="form-label mb-1 small">Producto</label><input name="nombre" class="form-control form-control-sm" value="<?= e($p['nombre']) ?>" required></div>
            <div class="col-md-3"><label class="form-label mb-1 small">Ubicación</label><input name="ubicacion" class="form-control form-control-sm" list="listaUbicaciones" value="<?= e($p['ubicacion'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label mb-1 small">Categoría</label><input name="categoria" class="form-control form-control-sm" list="listaCategorias" value="<?= e($p['categoria'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label mb-1 small">Marca</label><input name="marca" class="form-control form-control-sm" list="listaMarcas" value="<?= e($p['marca'] ?? '') ?>"></div>
            <div class="col-md-2"><label class="form-label mb-1 small">Unidad</label><input name="unidad" class="form-control form-control-sm" list="listaUnidades" value="<?= e($p['unidad']) ?>"></div>
            <div class="col-md-2"><label class="form-label mb-1 small">Stock</label><input name="stock" type="number" min="0" class="form-control form-control-sm" value="<?= (int)$p['stock'] ?>"></div>
            <div class="col-md-2"><label class="form-label mb-1 small">Stock mínimo</label><input name="stock_minimo" type="number" min="0" class="form-control form-control-sm" value="<?= (int)$p['stock_minimo'] ?>"></div>
            <?php if ($esAdmin): ?><div class="col-md-2"><label class="form-label mb-1 small">Valor ($ unit.)</label><input name="valor" type="number" min="0" class="form-control form-control-sm" value="<?= $p['valor'] !== null ? (int)$p['valor'] : '' ?>"></div><?php endif; ?>
            <div class="col-md-2"><label class="form-label mb-1 small">Fecha de compra</label><input name="fecha_compra" type="date" class="form-control form-control-sm" max="<?= date('Y-m-d') ?>" value="<?= e($p['fecha_compra'] ?? '') ?>"></div>
            <div class="col-md-2"><button class="btn btn-sns btn-sm w-100">Guardar cambios</button></div>
          </form>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="row g-4 mt-1">
  <div class="col-12 col-lg-6">
    <h5 class="text-rosa fw-bold">Resumen por ubicación</h5>
    <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-light"><tr><th>Ubicación</th><th>Productos</th><th>Unidades</th><th>Agotados</th></tr></thead>
      <tbody>
        <?php foreach ($resumenUbic as $r): ?>
        <tr>
          <td><a href="?ubic=<?= urlencode($r['ubicacion'] === '(sin ubicación)' ? '' : $r['ubicacion']) ?>"><?= e($r['ubicacion']) ?></a></td>
          <td><?= (int)$r['n'] ?></td>
          <td><?= (int)$r['unidades'] ?></td>
          <td><?= (int)$r['bajos'] > 0 ? '<span class="badge text-bg-danger">' . (int)$r['bajos'] . '</span>' : '0' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <?php if ($esAdmin): ?>
  <div class="col-12 col-lg-6">
    <h5 class="text-rosa fw-bold">Valor por categoría</h5>
    <div class="table-responsive">
    <table class="table table-sm align-middle">
      <thead class="table-light"><tr><th>Categoría</th><th>Productos</th><th>Valor total</th></tr></thead>
      <tbody>
        <?php foreach ($resumen as $r): ?>
        <tr>
          <td><a href="?cat=<?= urlencode($r['categoria'] === '(sin categoría)' ? '' : $r['categoria']) ?>"><?= e($r['categoria']) ?></a></td>
          <td><?= (int)$r['n'] ?></td>
          <td class="fw-bold"><?= precio((int)$r['valor_total']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="table-light"><td class="fw-bold">Total</td><td></td><td class="fw-bold text-rosa"><?= precio($valorInventario) ?></td></tr>
      </tbody>
    </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
// Búsqueda en vivo: filtra las filas del inventario por nombre / marca / categoría / ubicación.
(function () {
  var q = document.getElementById('buscarProd');
  if (!q) return;
  q.addEventListener('input', function () {
    var t = q.value.trim().toLowerCase();
    document.querySelectorAll('tr[data-buscar]').forEach(function (tr) {
      var ok = tr.dataset.buscar.indexOf(t) !== -1;
      tr.style.display = ok ? '' : 'none';
      var ed = tr.nextElementSibling; // la fila de edición que le sigue
      if (ed && ed.classList.contains('fila-edit')) ed.style.display = ok ? '' : 'none';
    });
  });
})();
</script>

<?php require __DIR__ . '/includes/bottom.php'; ?>
