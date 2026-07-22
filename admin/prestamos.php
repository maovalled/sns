<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nomina.php';
$u = requerirLogin(['admin', 'manicurista']);
$activo = 'prestamos';
$tituloAdmin = 'Préstamos a manicuristas';
$pdo = db();
$mensaje = null;
$error = null;
$esAdmin = $u['rol'] === 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $esAdmin) {

    // ── Registrar préstamo ──
    if (isset($_POST['nuevo'])) {
        $maniId = (int)($_POST['manicurista_id'] ?? 0);
        $monto  = (int)($_POST['monto'] ?? 0);
        $fecha  = $_POST['fecha'] ?? date('Y-m-d');
        $motivo = trim($_POST['motivo'] ?? '');

        $rolOk = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id = ? AND rol = 'manicurista' AND activo = 1");
        $rolOk->execute([$maniId]);

        if (!$maniId || !(int)$rolOk->fetchColumn()) {
            $error = 'Selecciona una manicurista activa.';
        } elseif ($monto <= 0) {
            $error = 'El monto del préstamo debe ser mayor a cero.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > date('Y-m-d')) {
            $error = 'La fecha del préstamo no puede ser futura.';
        } else {
            $c = cupoPrestamo($maniId, $fecha);
            if ($c['servicios'] === 0) {
                $error = 'No se puede prestar: no tiene servicios realizados en el corte '
                       . $c['corte']['etiqueta'] . '.';
            } elseif ($monto > $c['cupo']) {
                $error = 'El préstamo (' . precio($monto) . ') supera lo que lleva ganado en el corte. '
                       . 'Cupo disponible: ' . precio($c['cupo'])
                       . ' (ganado ' . precio($c['ganado'])
                       . ($c['saldo_prest'] > 0 ? ' − préstamos vigentes ' . precio($c['saldo_prest']) : '') . ').';
            } else {
                $pdo->prepare('INSERT INTO prestamos (manicurista_id, monto, saldo, fecha, motivo, cupo_al_prestar, autorizado_por)
                               VALUES (?,?,?,?,?,?,?)')
                    ->execute([$maniId, $monto, $monto, $fecha, $motivo ?: null, $c['cupo'], $u['id']]);
                $mensaje = 'Préstamo de ' . precio($monto) . ' registrado. Se descontará en la liquidación del corte '
                         . $c['corte']['etiqueta'] . '.';
            }
        }

    // ── Abono manual (fuera de nómina, p. ej. devuelve en efectivo) ──
    } elseif (isset($_POST['abonar'])) {
        $prestamoId = (int)$_POST['abonar'];
        $monto = (int)($_POST['abono_monto'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM prestamos WHERE id = ? AND estado = 'pendiente'");
        $st->execute([$prestamoId]);
        $p = $st->fetch();
        if (!$p) {
            $error = 'Préstamo no encontrado o ya cerrado.';
        } elseif ($monto <= 0 || $monto > (int)$p['saldo']) {
            $error = 'El abono debe estar entre 1 y el saldo pendiente (' . precio($p['saldo']) . ').';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO prestamos_abonos (prestamo_id, monto, fecha, liquidacion_id, registrado_por)
                               VALUES (?,?,?,NULL,?)')
                    ->execute([$prestamoId, $monto, date('Y-m-d'), $u['id']]);
                $nuevoSaldo = (int)$p['saldo'] - $monto;
                $pdo->prepare('UPDATE prestamos SET saldo = ?, estado = ? WHERE id = ?')
                    ->execute([$nuevoSaldo, $nuevoSaldo <= 0 ? 'pagado' : 'pendiente', $prestamoId]);
                $pdo->commit();
                $mensaje = 'Abono de ' . precio($monto) . ' registrado.';
            } catch (Throwable $ex) {
                $pdo->rollBack();
                $error = 'No se pudo registrar el abono.';
            }
        }

    // ── Anular (solo si no tiene abonos) ──
    } elseif (isset($_POST['anular'])) {
        $prestamoId = (int)$_POST['anular'];
        $ab = $pdo->prepare('SELECT COUNT(*) FROM prestamos_abonos WHERE prestamo_id = ?');
        $ab->execute([$prestamoId]);
        if ((int)$ab->fetchColumn() > 0) {
            $error = 'Ese préstamo ya tiene abonos: no se puede anular.';
        } else {
            $pdo->prepare("UPDATE prestamos SET estado = 'anulado', saldo = 0 WHERE id = ? AND estado = 'pendiente'")
                ->execute([$prestamoId]);
            $mensaje = 'Préstamo anulado.';
        }
    }
}

$manicuristas = manicuristasActivas();

// Cupo disponible hoy, por manicurista (solo admin lo necesita para decidir)
$cupos = [];
if ($esAdmin) {
    foreach ($manicuristas as $m) $cupos[(int)$m['id']] = cupoPrestamo((int)$m['id']);
}

// Listado de préstamos
$sql = 'SELECT p.*, m.nombre AS manicurista, a.nombre AS autorizado
        FROM prestamos p
        JOIN usuarios m ON m.id = p.manicurista_id
        JOIN usuarios a ON a.id = p.autorizado_por';
$params = [];
if (!$esAdmin) { $sql .= ' WHERE p.manicurista_id = ?'; $params[] = $u['id']; }
$sql .= " ORDER BY FIELD(p.estado,'pendiente','pagado','anulado'), p.fecha DESC, p.id DESC LIMIT 300";
$st = $pdo->prepare($sql);
$st->execute($params);
$prestamos = $st->fetchAll();

// Abonos de los préstamos listados
$abonos = [];
if ($prestamos) {
    $ids = array_column($prestamos, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $qa = $pdo->prepare("SELECT a.*, l.periodo_inicio, l.periodo_fin
                         FROM prestamos_abonos a
                         LEFT JOIN liquidaciones l ON l.id = a.liquidacion_id
                         WHERE a.prestamo_id IN ($in) ORDER BY a.fecha, a.id");
    $qa->execute($ids);
    foreach ($qa as $a) $abonos[(int)$a['prestamo_id']][] = $a;
}

$corteHoy = corteDe(date('Y-m-d'));
require __DIR__ . '/includes/top.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?= e($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<?php if ($esAdmin): ?>
<div class="alert alert-light border">
  <strong class="text-rosa">Corte actual: <?= e($corteHoy['etiqueta']) ?></strong><br>
  <small class="text-muted">
    Solo se puede prestar hasta lo que la manicurista <strong>lleva ganado en este corte</strong>
    (comisión por servicios ya cobrados + bono causado por los días trabajados), menos los préstamos que aún debe.
  </small>
</div>

<h5 class="text-rosa">Cupo disponible hoy</h5>
<div class="row g-3 mb-4">
  <?php foreach ($manicuristas as $m): $c = $cupos[(int)$m['id']]; ?>
  <div class="col-md-6 col-xl-4">
    <div class="card card-servicio p-3 h-100">
      <div class="d-flex justify-content-between align-items-start">
        <h6 class="text-rosa mb-1"><?= e($m['nombre']) ?></h6>
        <span class="badge text-bg-light border"><?= rtrim(rtrim(number_format((float)$m['porcentaje_comision'], 2, ',', '.'), '0'), ',') ?>%</span>
      </div>
      <?php if ($c['servicios'] === 0): ?>
        <div class="alert alert-warning py-2 px-2 my-2 small mb-0">
          Sin servicios realizados en el corte — <strong>no se le puede prestar</strong>.
        </div>
      <?php else: ?>
        <table class="table table-sm mb-0 small">
          <tr><td class="text-muted"><?= (int)$c['servicios'] ?> servicio(s) · base</td><td class="text-end"><?= precio($c['base']) ?></td></tr>
          <tr><td class="text-muted">Comisión</td><td class="text-end"><?= precio($c['comision']) ?></td></tr>
          <tr><td class="text-muted">Bono causado (<?= (int)max(0, $c['dias'] - $c['faltas']) ?> día<?= max(0, $c['dias'] - $c['faltas']) === 1 ? '' : 's' ?>)</td><td class="text-end"><?= precio($c['bono_causado']) ?></td></tr>
          <?php if ($c['saldo_prest'] > 0): ?>
          <tr class="text-danger"><td>− Préstamos vigentes</td><td class="text-end">−<?= precio($c['saldo_prest']) ?></td></tr>
          <?php endif; ?>
          <tr class="border-top"><td class="fw-bold">Cupo</td><td class="text-end fw-bold text-rosa"><?= precio($c['cupo']) ?></td></tr>
        </table>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$manicuristas): ?><div class="col-12 text-muted">No hay manicuristas activas.</div><?php endif; ?>
</div>

<div class="card card-servicio p-3 mb-4">
  <h5 class="text-rosa">Registrar préstamo</h5>
  <form method="post" class="row g-2 align-items-end">
    <?= csrfField() ?>
    <input type="hidden" name="nuevo" value="1">
    <div class="col-md-3"><label class="form-label mb-1">Manicurista</label>
      <select name="manicurista_id" class="form-select" required>
        <option value="">Selecciona…</option>
        <?php foreach ($manicuristas as $m): $c = $cupos[(int)$m['id']]; ?>
          <option value="<?= (int)$m['id'] ?>" <?= $c['cupo'] <= 0 ? 'disabled' : '' ?>>
            <?= e($m['nombre']) ?> — cupo <?= precio($c['cupo']) ?><?= $c['servicios'] === 0 ? ' (sin servicios)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><label class="form-label mb-1">Monto</label><input name="monto" type="number" min="1" step="1" class="form-control" required></div>
    <div class="col-md-2"><label class="form-label mb-1">Fecha</label><input name="fecha" type="date" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
    <div class="col-md-4"><label class="form-label mb-1">Motivo</label><input name="motivo" class="form-control" placeholder="Calamidad, adelanto, mercado…"></div>
    <div class="col-md-1"><button class="btn btn-sns w-100">＋</button></div>
  </form>
</div>
<?php endif; ?>

<h5 class="text-rosa"><?= $esAdmin ? 'Historial de préstamos' : 'Mis préstamos' ?></h5>
<div class="table-responsive">
<table class="table align-middle">
  <thead class="table-light"><tr>
    <th>Fecha</th><?php if ($esAdmin): ?><th>Manicurista</th><?php endif; ?>
    <th>Monto</th><th>Saldo</th><th>Estado</th><th>Motivo</th><th>Abonos</th><?php if ($esAdmin): ?><th>Acciones</th><?php endif; ?>
  </tr></thead>
  <tbody>
  <?php if (!$prestamos): ?>
    <tr><td colspan="<?= $esAdmin ? 8 : 6 ?>" class="text-center text-muted py-4">Sin préstamos registrados.</td></tr>
  <?php endif; ?>
  <?php foreach ($prestamos as $p): $ab = $abonos[(int)$p['id']] ?? []; ?>
    <tr class="<?= $p['estado'] === 'anulado' ? 'table-secondary opacity-75' : '' ?>">
      <td><?= e(date('d/m/Y', strtotime($p['fecha']))) ?></td>
      <?php if ($esAdmin): ?><td><?= e($p['manicurista']) ?></td><?php endif; ?>
      <td class="fw-bold"><?= precio($p['monto']) ?></td>
      <td class="<?= (int)$p['saldo'] > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= precio($p['saldo']) ?></td>
      <td>
        <?php $badge = ['pendiente' => 'warning', 'pagado' => 'success', 'anulado' => 'secondary'][$p['estado']]; ?>
        <span class="badge text-bg-<?= $badge ?>"><?= e($p['estado']) ?></span>
      </td>
      <td><small><?= e($p['motivo'] ?? '—') ?></small></td>
      <td>
        <?php if (!$ab): ?><small class="text-muted">—</small><?php endif; ?>
        <?php foreach ($ab as $a): ?>
          <small class="d-block">
            <?= e(date('d/m/Y', strtotime($a['fecha']))) ?> · <?= precio($a['monto']) ?>
            <span class="text-muted"><?= $a['liquidacion_id'] ? '(liquidación ' . e(etiquetaCorte($a['periodo_inicio'], $a['periodo_fin'])) . ')' : '(manual)' ?></span>
          </small>
        <?php endforeach; ?>
      </td>
      <?php if ($esAdmin): ?>
      <td>
        <?php if ($p['estado'] === 'pendiente'): ?>
        <div class="d-flex flex-column gap-1">
          <form method="post" class="d-flex gap-1">
            <?= csrfField() ?>
            <input type="hidden" name="abonar" value="<?= (int)$p['id'] ?>">
            <input type="number" name="abono_monto" class="form-control form-control-sm" style="width:100px"
                   min="1" max="<?= (int)$p['saldo'] ?>" placeholder="Abono" required>
            <button class="btn btn-sns-outline btn-sm">Abonar</button>
          </form>
          <?php if (!$ab): ?>
          <form method="post" onsubmit="return confirm('¿Anular este préstamo?')">
            <?= csrfField() ?>
            <input type="hidden" name="anular" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-outline-danger btn-sm w-100">Anular</button>
          </form>
          <?php endif; ?>
        </div>
        <?php else: ?><small class="text-muted">—</small><?php endif; ?>
      </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/bottom.php'; ?>
