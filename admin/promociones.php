<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$u = requerirLogin(['admin', 'recepcion']);
$activo = 'promociones';
$tituloAdmin = 'Promociones';
$pdo = db();
$mensaje = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['nueva'])) {
        $nombre = trim($_POST['nombre'] ?? '');
        $desc   = trim($_POST['descripcion'] ?? '');
        $fecha  = $_POST['fecha'] ?? '';
        if ($nombre === '') {
            $error = 'Ponle un nombre a la promoción.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $error = 'Fecha inválida.';
        } elseif ($fecha < date('Y-m-d')) {
            $error = 'La fecha de la promoción no puede ser en el pasado.';
        } else {
            $st = $pdo->prepare('INSERT INTO promociones (nombre, descripcion, fecha, creado_por) VALUES (?,?,?,?)');
            $st->execute([$nombre, $desc ?: null, $fecha, $u['id']]);
            $mensaje = 'Promoción creada. Se mostrará en el sitio el día anterior y el día de la promo.';
        }
    } elseif (isset($_POST['toggle_id'])) {
        $pdo->prepare('UPDATE promociones SET activo = 1 - activo WHERE id = ?')->execute([(int)$_POST['toggle_id']]);
        $mensaje = 'Promoción actualizada.';
    } elseif (isset($_POST['eliminar_id'])) {
        $pdo->prepare('DELETE FROM promociones WHERE id = ?')->execute([(int)$_POST['eliminar_id']]);
        $mensaje = 'Promoción eliminada.';
    }
}

$promos = $pdo->query('SELECT p.*, r.nombre AS registrado_por
                       FROM promociones p LEFT JOIN usuarios r ON r.id = p.creado_por
                       ORDER BY p.fecha DESC, p.id DESC')->fetchAll();
$hoy = date('Y-m-d');
$manana = date('Y-m-d', strtotime('+1 day'));

require __DIR__ . '/includes/top.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?= e($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card card-servicio p-3 mb-4">
  <h5 class="text-rosa">Nueva promoción</h5>
  <p class="text-muted small mb-3">Aparece en el sitio de forma llamativa <strong>el día anterior y el día de la promoción</strong>; después se oculta sola.</p>
  <form method="post" class="row g-2 align-items-end">
    <?= csrfField() ?>
    <input type="hidden" name="nueva" value="1">
    <div class="col-md-3"><label class="form-label mb-1">Nombre *</label><input name="nombre" class="form-control" maxlength="120" required placeholder="Luna Llena · 2x1 en semipermanente"></div>
    <div class="col-md-5"><label class="form-label mb-1">Descripción</label><input name="descripcion" class="form-control" maxlength="500" placeholder="Solo este día: trae a una amiga y…"></div>
    <div class="col-md-2"><label class="form-label mb-1">Fecha</label><input type="date" name="fecha" class="form-control" min="<?= date('Y-m-d') ?>" required></div>
    <div class="col-md-2"><button class="btn btn-sns w-100">Crear ✦</button></div>
  </form>
</div>

<div class="table-responsive">
<table class="table align-middle">
  <thead class="table-light"><tr><th>Fecha</th><th>Promoción</th><th>Estado</th><th>Registró</th><th>Acciones</th></tr></thead>
  <tbody>
  <?php if (!$promos): ?>
    <tr><td colspan="5" class="text-center text-muted py-4">Aún no hay promociones 🌙</td></tr>
  <?php endif; ?>
  <?php foreach ($promos as $p):
        $visible = $p['activo'] && $p['fecha'] >= $hoy && $p['fecha'] <= $manana;
        $futura  = $p['fecha'] > $manana;
        $pasada  = $p['fecha'] < $hoy; ?>
    <tr class="<?= $p['activo'] ? '' : 'table-secondary' ?>">
      <td><?= e(ucfirst(fechaLarga($p['fecha']))) ?><br><small class="text-muted"><?= e($p['fecha']) ?></small></td>
      <td><strong><?= e($p['nombre']) ?></strong><?php if ($p['descripcion']): ?><br><small class="text-muted"><?= e($p['descripcion']) ?></small><?php endif; ?></td>
      <td>
        <?php if (!$p['activo']): ?><span class="badge text-bg-secondary">inactiva</span>
        <?php elseif ($visible): ?><span class="badge text-bg-success">👁 Visible ahora</span>
        <?php elseif ($futura): ?><span class="badge text-bg-info">programada</span>
        <?php else: ?><span class="badge text-bg-light border">finalizada</span><?php endif; ?>
      </td>
      <td><small class="text-muted"><?= e($p['registrado_por'] ?? '—') ?></small></td>
      <td>
        <div class="d-flex gap-1 flex-wrap">
          <form method="post"><?= csrfField() ?><input type="hidden" name="toggle_id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sns-outline btn-sm"><?= $p['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
          <form method="post" onsubmit="return confirm('¿Eliminar esta promoción?')"><?= csrfField() ?>
            <input type="hidden" name="eliminar_id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-outline-danger btn-sm">🗑</button></form>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/bottom.php'; ?>
