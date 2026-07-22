<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fidelidad.php';
$u = requerirLogin(['admin', 'recepcion']);
$activo = 'fidelidad';
$tituloAdmin = 'Fidelidad · Tarjetas virtuales';
$pdo = db();
$mensaje = null;
$error = null;
$p = fidelidadParams();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['aplicar_cupon_id'])) {
        [$ok, $msg] = aplicarCupon((int)$_POST['aplicar_cupon_id'], $u['id']);
        $ok ? $mensaje = $msg : $error = $msg;
    }
}

$q = trim($_GET['q'] ?? '');
$sql = 'SELECT * FROM tarjetas WHERE abierta = 1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (telefono LIKE ? OR nombre LIKE ?)';
    $params = ["%$q%", "%$q%"];
}
$sql .= ' ORDER BY creada_en DESC LIMIT 100';
$st = $pdo->prepare($sql);
$st->execute($params);
$tarjetas = $st->fetchAll();

require __DIR__ . '/includes/top.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?= e($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="alert alert-info border small">
  🎫 Las visitas se cuentan <strong>automáticamente al registrar el cobro</strong> en Citas (una por reserva pagada). Aquí solo consultas las tarjetas y <strong>aplicas los cupones</strong> ganados.
  <br>Reglas (editables en <a href="parametricas.php">Paramétricas</a>): cupón de <strong>-<?= $p['descuento'] ?>%</strong> cada
  <strong><?= $p['visitas'] ?></strong> servicios · máx. <strong><?= $p['max'] ?></strong> cupones por tarjeta ·
  vigencia <strong><?= $p['vigencia'] ?></strong> días · <strong>no acumulables</strong> (1 por día por tarjeta).
</div>

<form class="d-flex gap-2 mb-3" method="get">
  <input name="q" class="form-control" style="max-width:320px" placeholder="Buscar por teléfono o nombre 🔍" value="<?= e($q) ?>">
  <button class="btn btn-sns-outline">Buscar</button>
</form>

<div class="table-responsive">
<table class="table align-middle">
  <thead class="table-light"><tr><th>Clienta</th><th>Teléfono</th><th>Tarjeta</th><th>Visitas</th><th>Cupones</th><th>Acciones</th></tr></thead>
  <tbody>
  <?php if (!$tarjetas): ?><tr><td colspan="6" class="text-center text-muted py-4">No hay tarjetas abiertas<?= $q ? ' con esa búsqueda' : '' ?> 🌙</td></tr><?php endif; ?>
  <?php foreach ($tarjetas as $t): $d = datosTarjeta($t); ?>
    <tr>
      <td><?= e($t['nombre']) ?></td>
      <td><?= e($t['telefono']) ?></td>
      <td><span class="badge text-bg-light border">#<?= e($t['codigo']) ?></span></td>
      <td>
        <strong><?= $d['visitas'] ?></strong>
        <small class="text-muted">(<?= $d['visitas'] % $p['visitas'] ?>/<?= $p['visitas'] ?> del ciclo)</small>
      </td>
      <td>
        <?php if (!$d['cupones']): ?><small class="text-muted">—</small><?php endif; ?>
        <?php foreach ($d['cupones'] as $c):
          $vigente = !$c['usado_en'] && $c['vence_el'] >= date('Y-m-d'); ?>
          <span class="badge <?= $c['usado_en'] ? 'text-bg-secondary' : ($vigente ? 'text-bg-success' : 'text-bg-danger') ?>">
            -<?= (int)$c['descuento_pct'] ?>% <?= $c['usado_en'] ? 'usado' : ($vigente ? 'vence ' . date('d/m', strtotime($c['vence_el'])) : 'vencido') ?>
          </span>
        <?php endforeach; ?>
      </td>
      <td>
        <div class="d-flex gap-1 flex-wrap">
          <?php if (!$d['activos']): ?><small class="text-muted">—</small><?php endif; ?>
          <?php foreach ($d['activos'] as $c): ?>
          <form method="post" onsubmit="return confirm('¿Aplicar cupón de -<?= (int)$c['descuento_pct'] ?>%? Recuerda: no acumulable.')">
            <?= csrfField() ?>
            <input type="hidden" name="aplicar_cupon_id" value="<?= (int)$c['id'] ?>">
            <button class="btn btn-sns-outline btn-sm">🎫 aplicar -<?= (int)$c['descuento_pct'] ?>%</button>
          </form>
          <?php endforeach; ?>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/bottom.php'; ?>
