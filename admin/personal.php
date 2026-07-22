<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$u = requerirLogin(['admin']);
$activo = 'personal';
$tituloAdmin = 'Personal';
$pdo = db();
$mensaje = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['nuevo'])) {
        $nombre = trim($_POST['nombre'] ?? '');
        $usuario = trim($_POST['usuario'] ?? '');
        $clave = $_POST['clave'] ?? '';
        $rol = $_POST['rol'] ?? 'manicurista';
        $telefono = trim($_POST['telefono'] ?? '');
        $pct = (float)($_POST['porcentaje_comision'] ?? 50);
        if ($nombre === '' || $usuario === '' || strlen($clave) < 6) {
            $error = 'Completa nombre, usuario y una clave de mínimo 6 caracteres.';
        } elseif (!in_array($rol, ['admin','recepcion','manicurista'], true)) {
            $error = 'Rol inválido.';
        } elseif ($pct < 0 || $pct > 100) {
            $error = 'El porcentaje de comisión debe estar entre 0 y 100.';
        } else {
            try {
                $st = $pdo->prepare('INSERT INTO usuarios (nombre, usuario, clave_hash, rol, telefono, porcentaje_comision) VALUES (?,?,?,?,?,?)');
                $st->execute([$nombre, $usuario, password_hash($clave, PASSWORD_DEFAULT), $rol, $telefono ?: null, $pct]);
                $nuevoId = (int)$pdo->lastInsertId();
                if ($rol === 'manicurista') {
                    seedDisponibilidad($nuevoId); // horario de atención por defecto
                    $mensaje = 'Manicurista agregada con el horario de atención por defecto. Ajústalo abajo si lo necesitas.';
                } else {
                    $mensaje = 'Integrante agregado.';
                }
            } catch (PDOException $ex) {
                $error = 'Ese nombre de usuario ya existe.';
            }
        }
    } elseif (isset($_POST['usuario_horario'])) {
        $uid = (int)$_POST['usuario_horario'];
        $rolU = $pdo->prepare('SELECT rol FROM usuarios WHERE id = ?');
        $rolU->execute([$uid]);
        if ($rolU->fetchColumn() !== 'manicurista') {
            $error = 'Solo las manicuristas tienen horario de atención.';
        } else {
            $atencion = horarioAtencion(); // límites del local por día
            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM disponibilidad WHERE usuario_id = ?')->execute([$uid]);
                $ins = $pdo->prepare('INSERT INTO disponibilidad (usuario_id, dia_semana, hora_inicio, hora_fin) VALUES (?,?,?,?)');
                $guardados = 0;
                $ajustados = false;
                foreach (array_keys(diasSemana()) as $dia) {
                    if (empty($_POST["trabaja_$dia"])) continue;
                    $limite = $atencion[$dia] ?? null;
                    if ($limite === null) continue; // el local está cerrado ese día (domingo)
                    $ini = $_POST["inicio_$dia"] ?? '';
                    $fin = $_POST["fin_$dia"] ?? '';
                    if (!preg_match('/^\d{2}:\d{2}$/', $ini) || !preg_match('/^\d{2}:\d{2}$/', $fin)) continue;
                    // Acotar al horario de atención del local
                    if ($ini < $limite[0]) { $ini = $limite[0]; $ajustados = true; }
                    if ($fin > $limite[1]) { $fin = $limite[1]; $ajustados = true; }
                    if ($ini >= $fin) continue;
                    $ins->execute([$uid, $dia, $ini, $fin]);
                    $guardados++;
                }
                $pdo->commit();
                $mensaje = "Horario actualizado ($guardados día(s) de atención)."
                         . ($ajustados ? ' Algunas horas se ajustaron al horario del local.' : '');
            } catch (Throwable $ex) {
                $pdo->rollBack();
                $error = 'No se pudo guardar el horario.';
            }
        }
    } elseif (isset($_POST['editar_id'])) {
        // Edición completa de la ficha del integrante (nombre, usuario, rol, teléfono, % comisión).
        $id       = (int)$_POST['editar_id'];
        $nombre   = trim($_POST['nombre'] ?? '');
        $usuario  = trim($_POST['usuario'] ?? '');
        $rol      = $_POST['rol'] ?? '';
        $telefono = trim($_POST['telefono'] ?? '');
        $pct      = (float)($_POST['porcentaje_comision'] ?? 50);
        $rolActualSt = $pdo->prepare('SELECT rol FROM usuarios WHERE id = ?');
        $rolActualSt->execute([$id]);
        $rolActual = $rolActualSt->fetchColumn();
        if ($rolActual === false) {
            $error = 'Ese integrante no existe.';
        } elseif ($nombre === '' || $usuario === '') {
            $error = 'El nombre y el usuario son obligatorios.';
        } elseif (!in_array($rol, ['admin', 'recepcion', 'manicurista'], true)) {
            $error = 'Rol inválido.';
        } elseif ($pct < 0 || $pct > 100) {
            $error = 'El porcentaje de comisión debe estar entre 0 y 100.';
        } elseif ($id === (int)$u['id'] && $rol !== 'admin') {
            $error = 'No puedes quitarte a ti mismo el rol de administrador.';
        } else {
            try {
                $pdo->prepare('UPDATE usuarios SET nombre = ?, usuario = ?, rol = ?, telefono = ?, porcentaje_comision = ? WHERE id = ?')
                    ->execute([$nombre, $usuario, $rol, $telefono ?: null, $pct, $id]);
                // Si acaba de pasar a manicurista y aún no tiene horario, siémbralo por defecto.
                if ($rol === 'manicurista' && $rolActual !== 'manicurista' && !disponibilidadUsuario($id)) {
                    seedDisponibilidad($id);
                }
                // Si edité mi propia cuenta, refresca la sesión para que el panel muestre lo nuevo.
                if ($id === (int)$u['id']) {
                    $_SESSION['usuario']['nombre']  = $nombre;
                    $_SESSION['usuario']['usuario'] = $usuario;
                    $u = usuarioActual();
                }
                $mensaje = 'Datos del integrante actualizados.';
            } catch (PDOException $ex) {
                $error = 'Ese nombre de usuario ya existe.';
            }
        }
    } elseif (isset($_POST['toggle_id'])) {
        $pdo->prepare('UPDATE usuarios SET activo = 1 - activo WHERE id = ? AND id != ?')
            ->execute([(int)$_POST['toggle_id'], $u['id']]);
        $mensaje = 'Estado actualizado.';
    } elseif (isset($_POST['reset_id'])) {
        $nueva = $_POST['nueva_clave'] ?? '';
        if (strlen($nueva) < 6) {
            $error = 'La nueva clave debe tener mínimo 6 caracteres.';
        } else {
            $pdo->prepare('UPDATE usuarios SET clave_hash = ? WHERE id = ?')
                ->execute([password_hash($nueva, PASSWORD_DEFAULT), (int)$_POST['reset_id']]);
            $mensaje = 'Clave restablecida.';
        }
    }
}

$personal = $pdo->query('SELECT * FROM usuarios ORDER BY rol, nombre')->fetchAll();
require __DIR__ . '/includes/top.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?= e($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="card card-servicio p-3 mb-4">
  <h5 class="text-rosa">Agregar integrante</h5>
  <form method="post" class="row g-2 align-items-end">
    <?= csrfField() ?>
    <input type="hidden" name="nuevo" value="1">
    <div class="col-md-3"><label class="form-label mb-1">Nombre</label><input name="nombre" class="form-control" required></div>
    <div class="col-md-2"><label class="form-label mb-1">Usuario</label><input name="usuario" class="form-control" required></div>
    <div class="col-md-2"><label class="form-label mb-1">Clave</label><input name="clave" type="password" class="form-control" minlength="6" required></div>
    <div class="col-md-2"><label class="form-label mb-1">Rol</label>
      <select name="rol" class="form-select">
        <option value="manicurista">Manicurista</option>
        <option value="recepcion">Recepción</option>
        <option value="admin">Admin</option>
      </select>
    </div>
    <div class="col-md-1"><label class="form-label mb-1">Teléfono</label><input name="telefono" class="form-control"></div>
    <div class="col-md-1"><label class="form-label mb-1" title="Solo aplica a manicuristas">% comisión</label>
      <input name="porcentaje_comision" type="number" min="0" max="100" step="0.5" class="form-control" value="50"></div>
    <div class="col-md-1"><button class="btn btn-sns w-100">＋</button></div>
  </form>
</div>

<div class="table-responsive">
<table class="table align-middle">
  <thead class="table-light"><tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Teléfono</th><th>% comisión</th><th>Estado</th><th>Acciones</th></tr></thead>
  <tbody>
  <?php foreach ($personal as $p): ?>
    <tr class="<?= $p['activo'] ? '' : 'table-secondary' ?>">
      <td><?= e($p['nombre']) ?></td>
      <td><code><?= e($p['usuario']) ?></code></td>
      <td><span class="badge text-bg-light border"><?= e($p['rol']) ?></span></td>
      <td><?= e($p['telefono'] ?? '—') ?></td>
      <td>
        <?php if ($p['rol'] === 'manicurista'): ?><?= e(rtrim(rtrim(number_format((float)$p['porcentaje_comision'], 2, '.', ''), '0'), '.')) ?> %<?php else: ?><small class="text-muted">—</small><?php endif; ?>
      </td>
      <td><?= $p['activo'] ? '<span class="badge text-bg-success">activo</span>' : '<span class="badge text-bg-secondary">inactivo</span>' ?></td>
      <td>
        <div class="d-flex gap-1 flex-wrap">
          <button type="button" class="btn btn-sns-outline btn-sm" data-bs-toggle="collapse" data-bs-target="#edit-user-<?= (int)$p['id'] ?>" title="Editar datos">✏️ Editar</button>
          <?php if ((int)$p['id'] !== $u['id']): ?>
          <form method="post"><?= csrfField() ?><input type="hidden" name="toggle_id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sns-outline btn-sm"><?= $p['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
          <?php endif; ?>
          <form method="post" class="d-flex gap-1">
            <?= csrfField() ?>
            <input type="hidden" name="reset_id" value="<?= (int)$p['id'] ?>">
            <input type="password" name="nueva_clave" class="form-control form-control-sm" placeholder="Nueva clave" style="width:110px" minlength="6" required>
            <button class="btn btn-sns-outline btn-sm">Restablecer</button>
          </form>
        </div>
      </td>
    </tr>
    <tr class="fila-edit-personal">
      <td colspan="7" class="p-0 border-0">
        <div class="collapse" id="edit-user-<?= (int)$p['id'] ?>">
          <form method="post" class="row g-2 align-items-end bg-body-tertiary border rounded p-3 mx-1 mb-2">
            <?= csrfField() ?>
            <input type="hidden" name="editar_id" value="<?= (int)$p['id'] ?>">
            <div class="col-md-3"><label class="form-label mb-1 small">Nombre</label><input name="nombre" class="form-control form-control-sm" value="<?= e($p['nombre']) ?>" required></div>
            <div class="col-md-2"><label class="form-label mb-1 small">Usuario</label><input name="usuario" class="form-control form-control-sm" value="<?= e($p['usuario']) ?>" required></div>
            <div class="col-md-2"><label class="form-label mb-1 small">Rol</label>
              <select name="rol" class="form-select form-select-sm">
                <option value="manicurista" <?= $p['rol'] === 'manicurista' ? 'selected' : '' ?>>Manicurista</option>
                <option value="recepcion" <?= $p['rol'] === 'recepcion' ? 'selected' : '' ?>>Recepción</option>
                <option value="admin" <?= $p['rol'] === 'admin' ? 'selected' : '' ?>>Admin</option>
              </select>
            </div>
            <div class="col-md-2"><label class="form-label mb-1 small">Teléfono</label><input name="telefono" class="form-control form-control-sm" value="<?= e($p['telefono'] ?? '') ?>"></div>
            <div class="col-md-2"><label class="form-label mb-1 small" title="Solo aplica a manicuristas">% comisión</label><input name="porcentaje_comision" type="number" min="0" max="100" step="0.5" class="form-control form-control-sm" value="<?= e(rtrim(rtrim(number_format((float)$p['porcentaje_comision'], 2, '.', ''), '0'), '.')) ?>"></div>
            <div class="col-md-1"><button class="btn btn-sns btn-sm w-100">Guardar</button></div>
            <?php if ((int)$p['id'] === (int)$u['id']): ?><div class="col-12"><small class="text-muted">Estás editando tu propia cuenta: no puedes quitarte el rol de administrador ni desactivarte.</small></div><?php endif; ?>
          </form>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php $manicuristas = array_values(array_filter($personal, fn($p) => $p['rol'] === 'manicurista')); ?>
<?php if ($manicuristas): $at = horarioAtencion(); $dias = diasSemana(); ?>
<h4 class="text-rosa fw-bold mt-5 mb-1">Horarios de atención por manicurista</h4>
<p class="text-muted small mb-3">
  Marca los días que atiende cada manicurista y sus horas. El agendamiento mostrará <strong>únicamente</strong> esas horas (y solo si están libres).
  Horario del local: <strong>Lun–Vie 10:00–19:00</strong> · <strong>Sáb 09:00–19:00</strong> · Dom cerrado.
</p>
<div class="row g-3">
  <?php foreach ($manicuristas as $m): $disp = disponibilidadUsuario((int)$m['id']); ?>
  <div class="col-lg-6">
    <div class="card card-servicio p-3 <?= $m['activo'] ? '' : 'opacity-50' ?>">
      <h5 class="text-rosa mb-2"><?= e($m['nombre']) ?> <small class="text-muted fs-6">@<?= e($m['usuario']) ?><?= $m['activo'] ? '' : ' · inactiva' ?></small></h5>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="usuario_horario" value="<?= (int)$m['id'] ?>">
        <?php foreach ($dias as $dia => $nombreDia):
            $cerradoLocal = ($at[$dia] ?? null) === null; // domingo
            $activoDia = isset($disp[$dia]);
            $def = $at[$dia] ?? ['10:00', '19:00'];
            $ini = $disp[$dia][0] ?? $def[0];
            $fin = $disp[$dia][1] ?? $def[1]; ?>
        <div class="d-flex align-items-center gap-2 mb-1">
          <div class="form-check mb-0" style="width:130px">
            <input class="form-check-input" type="checkbox" name="trabaja_<?= $dia ?>" id="d<?= (int)$m['id'] ?>_<?= $dia ?>" <?= $activoDia ? 'checked' : '' ?> <?= $cerradoLocal ? 'disabled' : '' ?>>
            <label class="form-check-label" for="d<?= (int)$m['id'] ?>_<?= $dia ?>"><?= e($nombreDia) ?></label>
          </div>
          <?php if ($cerradoLocal): ?>
            <small class="text-muted fst-italic">local cerrado</small>
          <?php else: ?>
            <input type="time" name="inicio_<?= $dia ?>" class="form-control form-control-sm" style="width:115px" value="<?= e($ini) ?>" min="<?= e($at[$dia][0]) ?>" max="<?= e($at[$dia][1]) ?>">
            <span class="text-muted">a</span>
            <input type="time" name="fin_<?= $dia ?>" class="form-control form-control-sm" style="width:115px" value="<?= e($fin) ?>" min="<?= e($at[$dia][0]) ?>" max="<?= e($at[$dia][1]) ?>">
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <button class="btn btn-sns btn-sm mt-2">Guardar horario</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/bottom.php'; ?>
