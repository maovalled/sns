<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$u = requerirLogin(['admin', 'recepcion']);
$activo = 'bloqueos';
$tituloAdmin = 'Bloqueos de agenda';
$pdo = db();
$mensaje = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['nuevo_bloqueo'])) {
        $maniId  = (int)($_POST['manicurista_id'] ?? 0);
        $fecha   = $_POST['fecha'] ?? '';
        $todoDia = !empty($_POST['todo_dia']);
        $ini     = $_POST['hora_inicio'] ?? '';
        $fin     = $_POST['hora_fin'] ?? '';
        $motivo  = trim($_POST['motivo'] ?? '');

        // La manicurista debe existir y tener ese rol
        $chk = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id = ? AND rol = 'manicurista'");
        $chk->execute([$maniId]);

        if (!$maniId || !$chk->fetchColumn()) {
            $error = 'Selecciona una manicurista válida.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $error = 'Fecha inválida.';
        } elseif ($fecha < date('Y-m-d')) {
            $error = 'No puedes bloquear una fecha pasada.';
        } elseif (!$todoDia && (!preg_match('/^\d{2}:\d{2}$/', $ini) || !preg_match('/^\d{2}:\d{2}$/', $fin) || $ini >= $fin)) {
            $error = 'Indica un rango de horas válido (inicio menor que fin) o marca "Todo el día".';
        } else {
            $st = $pdo->prepare('INSERT INTO bloqueos (usuario_id, fecha, hora_inicio, hora_fin, motivo, creado_por) VALUES (?,?,?,?,?,?)');
            $st->execute([
                $maniId, $fecha,
                $todoDia ? null : $ini,
                $todoDia ? null : $fin,
                $motivo ?: null, $u['id'],
            ]);
            $mensaje = 'Bloqueo agregado. Esas horas ya no aparecerán en el agendamiento.';
        }
    } elseif (isset($_POST['eliminar_id'])) {
        $pdo->prepare('DELETE FROM bloqueos WHERE id = ?')->execute([(int)$_POST['eliminar_id']]);
        $mensaje = 'Bloqueo eliminado.';
    }
}

$manicuristas = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol='manicurista' AND activo=1 ORDER BY nombre")->fetchAll();

// Bloqueos vigentes (de hoy en adelante)
$st = $pdo->prepare("SELECT b.*, m.nombre AS manicurista, r.nombre AS registrado_por
                     FROM bloqueos b
                     JOIN usuarios m ON m.id = b.usuario_id
                     LEFT JOIN usuarios r ON r.id = b.creado_por
                     WHERE b.fecha >= ?
                     ORDER BY b.fecha, m.nombre, b.hora_inicio");
$st->execute([date('Y-m-d')]);
$bloqueos = $st->fetchAll();

require __DIR__ . '/includes/top.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?= e($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card card-servicio p-3 mb-4">
  <h5 class="text-rosa">Bloquear disponibilidad</h5>
  <p class="text-muted small mb-3">Marca un día completo o un rango de horas en que una manicurista <strong>no</strong> estará disponible (vacaciones, cita médica, permiso…). No se ofrecerá en el agendamiento.</p>
  <?php if (!$manicuristas): ?>
    <div class="alert alert-warning mb-0">No hay manicuristas activas. Regístralas primero en <a href="personal.php">Personal</a>.</div>
  <?php else: ?>
  <form method="post" class="row g-2 align-items-end">
    <?= csrfField() ?>
    <input type="hidden" name="nuevo_bloqueo" value="1">
    <div class="col-md-3"><label class="form-label mb-1">Manicurista</label>
      <select name="manicurista_id" class="form-select" required>
        <option value="">Selecciona…</option>
        <?php foreach ($manicuristas as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['nombre']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><label class="form-label mb-1">Fecha</label><input type="date" name="fecha" class="form-control" min="<?= date('Y-m-d') ?>" required></div>
    <div class="col-md-2">
      <div class="form-check mt-4">
        <input class="form-check-input" type="checkbox" name="todo_dia" id="todo_dia" value="1" onchange="document.getElementById('rangoHoras').style.display=this.checked?'none':'flex'">
        <label class="form-check-label" for="todo_dia">Todo el día</label>
      </div>
    </div>
    <div class="col-md-3 d-flex gap-1" id="rangoHoras">
      <div><label class="form-label mb-1">Desde</label><input type="time" name="hora_inicio" class="form-control"></div>
      <div><label class="form-label mb-1">Hasta</label><input type="time" name="hora_fin" class="form-control"></div>
    </div>
    <div class="col-md-2"><label class="form-label mb-1">Motivo (opcional)</label><input name="motivo" class="form-control" placeholder="Permiso, cita…"></div>
    <div class="col-md-12 mt-2"><button class="btn btn-sns">Bloquear ⛔</button></div>
  </form>
  <?php endif; ?>
</div>

<h5 class="text-rosa fw-bold">Bloqueos vigentes</h5>
<div class="table-responsive">
<table class="table align-middle">
  <thead class="table-light"><tr><th>Fecha</th><th>Manicurista</th><th>Horas</th><th>Motivo</th><th>Registró</th><th></th></tr></thead>
  <tbody>
  <?php if (!$bloqueos): ?>
    <tr><td colspan="6" class="text-center text-muted py-4">No hay bloqueos programados 🌙</td></tr>
  <?php endif; ?>
  <?php foreach ($bloqueos as $b): ?>
    <tr>
      <td><?= e(date('d/m/Y', strtotime($b['fecha']))) ?></td>
      <td><?= e($b['manicurista']) ?></td>
      <td>
        <?php if ($b['hora_inicio'] === null): ?>
          <span class="badge text-bg-danger">Todo el día</span>
        <?php else: ?>
          <?= e(substr($b['hora_inicio'], 0, 5)) ?> – <?= e(substr($b['hora_fin'], 0, 5)) ?>
        <?php endif; ?>
      </td>
      <td><?= e($b['motivo'] ?? '—') ?></td>
      <td><small class="text-muted"><?= e($b['registrado_por'] ?? '—') ?></small></td>
      <td>
        <form method="post" onsubmit="return confirm('¿Quitar este bloqueo?')">
          <?= csrfField() ?>
          <input type="hidden" name="eliminar_id" value="<?= (int)$b['id'] ?>">
          <button class="btn btn-outline-danger btn-sm">🗑</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/bottom.php'; ?>
