<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$u = requerirLogin(['admin', 'recepcion']);
$activo = 'dias_especiales';
$tituloAdmin = 'Días especiales del local';
$pdo = db();
$mensaje = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['nuevo_dia'])) {
        $fecha   = $_POST['fecha'] ?? '';
        $abierto = ($_POST['tipo'] ?? 'cerrado') === 'abierto' ? 1 : 0;
        $ini     = $_POST['hora_inicio'] ?? '';
        $fin     = $_POST['hora_fin'] ?? '';
        $titulo  = trim($_POST['titulo'] ?? '');
        $nota    = trim($_POST['nota'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $error = 'Fecha inválida.';
        } elseif ($fecha < date('Y-m-d')) {
            $error = 'No puedes configurar una fecha pasada.';
        } elseif ($abierto && (!preg_match('/^\d{2}:\d{2}$/', $ini) || !preg_match('/^\d{2}:\d{2}$/', $fin) || $ini >= $fin)) {
            $error = 'Para una apertura especial indica un horario válido (inicio menor que fin).';
        } else {
            $st = $pdo->prepare('INSERT INTO dias_especiales (fecha, abierto, hora_inicio, hora_fin, titulo, nota, creado_por)
                                 VALUES (?,?,?,?,?,?,?)
                                 ON DUPLICATE KEY UPDATE abierto=VALUES(abierto), hora_inicio=VALUES(hora_inicio),
                                   hora_fin=VALUES(hora_fin), titulo=VALUES(titulo), nota=VALUES(nota), creado_por=VALUES(creado_por)');
            $st->execute([
                $fecha, $abierto,
                $abierto ? $ini : null,
                $abierto ? $fin : null,
                $titulo ?: null, $nota ?: null, $u['id'],
            ]);
            $mensaje = $abierto
                ? 'Apertura especial guardada. Se anunciará en el banner del sitio.'
                : 'Día marcado como cerrado. Se anunciará en el banner del sitio.';
        }
    } elseif (isset($_POST['eliminar_id'])) {
        $pdo->prepare('DELETE FROM dias_especiales WHERE id = ?')->execute([(int)$_POST['eliminar_id']]);
        $mensaje = 'Día especial eliminado (vuelve a regirse por el horario normal).';
    }
}

$st = $pdo->prepare("SELECT d.*, r.nombre AS registrado_por
                     FROM dias_especiales d LEFT JOIN usuarios r ON r.id = d.creado_por
                     WHERE d.fecha >= ? ORDER BY d.fecha");
$st->execute([date('Y-m-d')]);
$dias = $st->fetchAll();

require __DIR__ . '/includes/top.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?= e($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card card-servicio p-3 mb-4">
  <h5 class="text-rosa">Configurar un día especial</h5>
  <p class="text-muted small mb-3">
    Marca un <strong>festivo</strong> en que el local cierra (aunque sea entre semana) o una <strong>apertura especial</strong>
    (por ejemplo un domingo). Se anuncia automáticamente en el banner del sitio y ajusta el agendamiento.
  </p>
  <form method="post" class="row g-2 align-items-end">
    <?= csrfField() ?>
    <input type="hidden" name="nuevo_dia" value="1">
    <div class="col-md-2"><label class="form-label mb-1">Fecha</label><input type="date" name="fecha" class="form-control" min="<?= date('Y-m-d') ?>" required></div>
    <div class="col-md-2"><label class="form-label mb-1">Tipo</label>
      <select name="tipo" id="tipo" class="form-select" onchange="document.getElementById('horarioEsp').style.display = this.value==='abierto' ? 'flex' : 'none'">
        <option value="cerrado">🌑 Cerrado (festivo)</option>
        <option value="abierto">🌙 Abierto especial</option>
      </select>
    </div>
    <div class="col-md-3 d-flex gap-1" id="horarioEsp" style="display:none">
      <div><label class="form-label mb-1">Desde</label><input type="time" name="hora_inicio" class="form-control" value="10:00"></div>
      <div><label class="form-label mb-1">Hasta</label><input type="time" name="hora_fin" class="form-control" value="15:00"></div>
    </div>
    <div class="col-md-3"><label class="form-label mb-1">Título (opcional)</label><input name="titulo" class="form-control" placeholder="Festivo · Independencia" maxlength="100"></div>
    <div class="col-md-4"><label class="form-label mb-1">Nota para el banner (opcional)</label><input name="nota" class="form-control" placeholder="¡Feliz día! Volvemos el lunes ✨" maxlength="255"></div>
    <div class="col-md-2 mt-2"><button class="btn btn-sns w-100">Guardar día ✦</button></div>
  </form>
</div>

<h5 class="text-rosa fw-bold">Próximos días especiales</h5>
<div class="table-responsive">
<table class="table align-middle">
  <thead class="table-light"><tr><th>Fecha</th><th>Estado</th><th>Horario</th><th>Título / Nota</th><th>Registró</th><th></th></tr></thead>
  <tbody>
  <?php if (!$dias): ?>
    <tr><td colspan="6" class="text-center text-muted py-4">No hay días especiales programados 🌙</td></tr>
  <?php endif; ?>
  <?php foreach ($dias as $d): ?>
    <tr>
      <td><?= e(ucfirst(fechaLarga($d['fecha']))) ?><br><small class="text-muted"><?= e($d['fecha']) ?></small></td>
      <td>
        <?php if ($d['abierto']): ?><span class="badge text-bg-success">Abierto especial</span>
        <?php else: ?><span class="badge text-bg-danger">Cerrado</span><?php endif; ?>
      </td>
      <td><?= $d['abierto'] ? e(substr($d['hora_inicio'], 0, 5)) . ' – ' . e(substr($d['hora_fin'], 0, 5)) : '—' ?></td>
      <td>
        <?php if ($d['titulo']): ?><strong><?= e($d['titulo']) ?></strong><br><?php endif; ?>
        <small class="text-muted"><?= e($d['nota'] ?? '') ?></small>
      </td>
      <td><small class="text-muted"><?= e($d['registrado_por'] ?? '—') ?></small></td>
      <td>
        <form method="post" onsubmit="return confirm('¿Quitar este día especial?')">
          <?= csrfField() ?>
          <input type="hidden" name="eliminar_id" value="<?= (int)$d['id'] ?>">
          <button class="btn btn-outline-danger btn-sm">🗑</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/bottom.php'; ?>
