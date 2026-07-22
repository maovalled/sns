<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nomina.php';
$u = requerirLogin(['admin', 'manicurista']);
$activo = 'pagos';
$tituloAdmin = 'Pagos a manicuristas';
$pdo = db();
$mensaje = null;
$error = null;
$esAdmin = $u['rol'] === 'admin';

$cortes = cortesRecientes(8);
$sel = $_REQUEST['corte'] ?? $cortes[0]['inicio'];
$corte = null;
foreach ($cortes as $c) if ($c['inicio'] === $sel) $corte = $c;
$corte = $corte ?? $cortes[0];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $esAdmin) {

    // ── Liquidar el corte de una manicurista ──
    if (isset($_POST['liquidar'])) {
        $maniId    = (int)$_POST['liquidar'];
        $ajuste    = (int)($_POST['ajuste'] ?? 0);
        $descuento = (int)($_POST['descuento'] ?? 0);
        $nota      = trim($_POST['nota'] ?? '');

        $rolOk = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id = ? AND rol = 'manicurista'");
        $rolOk->execute([$maniId]);

        if (!(int)$rolOk->fetchColumn()) {
            $error = 'Manicurista no válida.';
        } elseif (liquidacionRegistrada($maniId, $corte['inicio'], $corte['fin'])) {
            $error = 'Ese corte ya está liquidado para esa manicurista.';
        } else {
            $l = liquidacionPreview($maniId, $corte['inicio'], $corte['fin']);
            $saldo = $l['saldo_prest'];
            if ($descuento < 0 || $descuento > $saldo) {
                $error = 'El descuento por préstamos debe estar entre 0 y el saldo vigente (' . precio($saldo) . ').';
            } else {
                $neto = $l['comision'] + $l['bono'] + $ajuste - $descuento;
                if ($neto < 0) {
                    $error = 'El neto quedaría negativo (' . precio($neto) . '). Ajusta el descuento de préstamos.';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('INSERT INTO liquidaciones
                            (manicurista_id, periodo_inicio, periodo_fin, base_servicios, porcentaje, comision,
                             dias_programados, dias_falta, bono, ajuste, descuento_prestamos, neto, nota, liquidado_por)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                            ->execute([$maniId, $corte['inicio'], $corte['fin'], $l['base'], $l['porcentaje'], $l['comision'],
                                       $l['programados'], $l['faltas'], $l['bono'], $ajuste, $descuento, $neto,
                                       $nota ?: null, $u['id']]);
                        $liqId = (int)$pdo->lastInsertId();

                        $abonado = abonarPrestamos($maniId, $descuento, $corte['pago'], $liqId, $u['id']);
                        if ($abonado !== $descuento) {
                            throw new RuntimeException('El descuento no coincide con los préstamos pendientes.');
                        }

                        $pdo->prepare('INSERT INTO pagos_manicuristas (manicurista_id, monto, fecha_pago, concepto, liquidacion_id, registrado_por)
                                       VALUES (?,?,?,?,?,?)')
                            ->execute([$maniId, $neto, $corte['pago'], 'Liquidación ' . $corte['etiqueta'], $liqId, $u['id']]);

                        $pdo->commit();
                        $mensaje = 'Corte liquidado ✔ Neto pagado: ' . precio($neto)
                                 . ($descuento > 0 ? ' · préstamos descontados ' . precio($descuento) : '');
                    } catch (Throwable $ex) {
                        $pdo->rollBack();
                        $error = $ex instanceof RuntimeException ? $ex->getMessage() : 'No se pudo liquidar el corte.';
                    }
                }
            }
        }

    // ── Deshacer una liquidación (revierte abonos y el pago) ──
    } elseif (isset($_POST['deshacer'])) {
        $liqId = (int)$_POST['deshacer'];
        $pdo->beginTransaction();
        try {
            $qa = $pdo->prepare('SELECT prestamo_id, monto FROM prestamos_abonos WHERE liquidacion_id = ?');
            $qa->execute([$liqId]);
            $rev = $pdo->prepare("UPDATE prestamos SET saldo = saldo + ?, estado = 'pendiente' WHERE id = ?");
            foreach ($qa as $a) $rev->execute([(int)$a['monto'], (int)$a['prestamo_id']]);

            $pdo->prepare('DELETE FROM prestamos_abonos WHERE liquidacion_id = ?')->execute([$liqId]);
            $pdo->prepare('DELETE FROM pagos_manicuristas WHERE liquidacion_id = ?')->execute([$liqId]);
            $pdo->prepare('DELETE FROM liquidaciones WHERE id = ?')->execute([$liqId]);
            $pdo->commit();
            $mensaje = 'Liquidación deshecha: los préstamos volvieron a quedar pendientes.';
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $error = 'No se pudo deshacer la liquidación.';
        }

    // ── Pago suelto fuera de nómina ──
    } elseif (isset($_POST['pago_manual'])) {
        $maniId = (int)($_POST['manicurista_id'] ?? 0);
        $monto  = (int)($_POST['monto'] ?? 0);
        $fechaPago = $_POST['fecha_pago'] ?? date('Y-m-d');
        $concepto = trim($_POST['concepto'] ?? '');
        if (!$maniId || $monto <= 0) {
            $error = 'Selecciona manicurista y un monto válido.';
        } else {
            $pdo->prepare('INSERT INTO pagos_manicuristas (manicurista_id, monto, fecha_pago, concepto, registrado_por) VALUES (?,?,?,?,?)')
                ->execute([$maniId, $monto, $fechaPago, $concepto ?: null, $u['id']]);
            $mensaje = 'Pago registrado.';
        }
    }
}

$manicuristas = $esAdmin
    ? manicuristasActivas()
    : array_filter(manicuristasActivas(), fn($m) => (int)$m['id'] === (int)$u['id']);

// Cálculo del corte para cada manicurista visible
$liq = [];
foreach ($manicuristas as $m) $liq[(int)$m['id']] = liquidacionPreview((int)$m['id'], $corte['inicio'], $corte['fin']);

$corteAbierto = $corte['fin'] >= date('Y-m-d');

// Historial de pagos
$sql = 'SELECT p.*, m.nombre AS manicurista, r.nombre AS registrado
        FROM pagos_manicuristas p
        JOIN usuarios m ON m.id = p.manicurista_id
        JOIN usuarios r ON r.id = p.registrado_por';
$params = [];
if (!$esAdmin) { $sql .= ' WHERE p.manicurista_id = ?'; $params[] = $u['id']; }
$sql .= ' ORDER BY p.fecha_pago DESC, p.id DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($params);
$pagos = $st->fetchAll();

require __DIR__ . '/includes/top.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?= e($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<form method="get" class="row g-2 align-items-end mb-3">
  <div class="col-md-4"><label class="form-label mb-1">Corte a liquidar</label>
    <select name="corte" class="form-select" onchange="this.form.submit()">
      <?php foreach ($cortes as $c): ?>
        <option value="<?= e($c['inicio']) ?>" <?= $c['inicio'] === $corte['inicio'] ? 'selected' : '' ?>><?= e($c['etiqueta']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-8">
    <small class="text-muted">
      Se paga el <strong><?= e(date('d/m/Y', strtotime($corte['pago']))) ?></strong>.
      Comisión = % de la manicurista sobre lo cobrado · Bono = <?= precio(bonoQuincenal()) ?> − <?= precio(bonoValorDia()) ?> por día no asistido
      (se marca en <a href="asistencia.php">Asistencia</a>) · menos <a href="prestamos.php">préstamos</a> vigentes.
    </small>
  </div>
</form>

<?php if ($corteAbierto): ?>
<div class="alert alert-warning py-2">
  <small>Este corte <strong>aún no termina</strong> (cierra el <?= e(date('d/m/Y', strtotime($corte['fin']))) ?>).
  Puedes liquidarlo, pero los cobros que se registren después ya no entrarán en él.</small>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ($manicuristas as $m): $mid = (int)$m['id']; $l = $liq[$mid]; ?>
  <div class="col-lg-6">
    <div class="card card-servicio p-3 h-100">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <h5 class="text-rosa mb-0"><?= e($m['nombre']) ?></h5>
        <?php if ($l['liquidada']): ?>
          <span class="badge text-bg-success">Liquidado</span>
        <?php else: ?>
          <span class="badge text-bg-light border">Pendiente</span>
        <?php endif; ?>
      </div>

      <table class="table table-sm align-middle mb-2">
        <tr>
          <td class="text-muted">Servicios cobrados<?= $l['servicios'] !== null ? ' (' . (int)$l['servicios'] . ')' : '' ?></td>
          <td class="text-end"><?= precio($l['base']) ?></td>
        </tr>
        <tr>
          <td class="text-muted">Comisión <?= rtrim(rtrim(number_format($l['porcentaje'], 2, ',', '.'), '0'), ',') ?>%</td>
          <td class="text-end fw-bold"><?= precio($l['comision']) ?></td>
        </tr>
        <tr>
          <td class="text-muted">
            Bono quincenal
            <small class="d-block"><?= (int)$l['programados'] ?> día(s) programado(s) · <?= (int)$l['faltas'] ?> falta(s)</small>
          </td>
          <td class="text-end fw-bold"><?= precio($l['bono']) ?></td>
        </tr>
        <?php if ((int)$l['ajuste'] !== 0): ?>
        <tr><td class="text-muted">Ajuste</td><td class="text-end"><?= precio($l['ajuste']) ?></td></tr>
        <?php endif; ?>
        <tr class="<?= $l['descuento'] > 0 ? 'text-danger' : 'text-muted' ?>">
          <td>− Préstamos<?= !$l['liquidada'] && $l['saldo_prest'] > 0 ? ' <small class="d-block">saldo vigente ' . precio($l['saldo_prest']) . '</small>' : '' ?></td>
          <td class="text-end">−<?= precio($l['descuento']) ?></td>
        </tr>
        <tr class="border-top">
          <td class="fw-bold">Neto a pagar</td>
          <td class="text-end fw-bold text-rosa fs-5"><?= precio($l['neto']) ?></td>
        </tr>
      </table>

      <?php if ($l['liquidada']): ?>
        <small class="text-muted">
          Liquidado el <?= e(date('d/m/Y', strtotime($l['liquidacion']['creado_en']))) ?>
          <?php if ($l['liquidacion']['nota']): ?> · <?= e($l['liquidacion']['nota']) ?><?php endif; ?>
        </small>
        <?php if ($esAdmin): ?>
        <form method="post" class="mt-2" onsubmit="return confirm('¿Deshacer esta liquidación? Los préstamos descontados volverán a quedar pendientes.')">
          <?= csrfField() ?>
          <input type="hidden" name="corte" value="<?= e($corte['inicio']) ?>">
          <input type="hidden" name="deshacer" value="<?= (int)$l['liquidacion']['id'] ?>">
          <button class="btn btn-outline-danger btn-sm">Deshacer liquidación</button>
        </form>
        <?php endif; ?>

      <?php elseif ($esAdmin): ?>
        <form method="post" class="row g-2 align-items-end">
          <?= csrfField() ?>
          <input type="hidden" name="corte" value="<?= e($corte['inicio']) ?>">
          <input type="hidden" name="liquidar" value="<?= $mid ?>">
          <div class="col-6">
            <label class="form-label mb-1 small">Descontar préstamos</label>
            <input type="number" name="descuento" class="form-control form-control-sm"
                   min="0" max="<?= (int)$l['saldo_prest'] ?>" step="1" value="<?= (int)$l['descuento'] ?>">
          </div>
          <div class="col-6">
            <label class="form-label mb-1 small">Ajuste (+/−)</label>
            <input type="number" name="ajuste" class="form-control form-control-sm" step="1" value="0">
          </div>
          <div class="col-12">
            <input type="text" name="nota" class="form-control form-control-sm" placeholder="Nota (opcional)">
          </div>
          <div class="col-12">
            <button class="btn btn-sns btn-sm w-100"
                    onclick="return confirm('¿Liquidar el corte <?= e($corte['etiqueta']) ?> de <?= e($m['nombre']) ?>?')">
              Liquidar y registrar pago
            </button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$manicuristas): ?><div class="col-12 text-muted">No hay manicuristas activas.</div><?php endif; ?>
</div>

<?php if ($esAdmin): ?>
<div class="card card-servicio p-3 mb-4">
  <h5 class="text-rosa">Pago suelto (fuera de nómina)</h5>
  <form method="post" class="row g-2 align-items-end">
    <?= csrfField() ?>
    <input type="hidden" name="pago_manual" value="1">
    <input type="hidden" name="corte" value="<?= e($corte['inicio']) ?>">
    <div class="col-md-3"><label class="form-label mb-1">Manicurista</label>
      <select name="manicurista_id" class="form-select" required>
        <option value="">Selecciona…</option>
        <?php foreach ($manicuristas as $m): ?>
          <option value="<?= (int)$m['id'] ?>"><?= e($m['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><label class="form-label mb-1">Monto</label><input name="monto" type="number" min="1" step="1" class="form-control" required></div>
    <div class="col-md-2"><label class="form-label mb-1">Fecha</label><input name="fecha_pago" type="date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
    <div class="col-md-4"><label class="form-label mb-1">Concepto</label><input name="concepto" class="form-control" placeholder="Reembolso, transporte…"></div>
    <div class="col-md-1"><button class="btn btn-sns w-100">＋</button></div>
  </form>
  <small class="text-muted mt-2">Para adelantos de dinero usa <a href="prestamos.php">Préstamos</a>: así se descuentan solos en la liquidación.</small>
</div>
<?php endif; ?>

<h5 class="text-rosa">Historial de pagos</h5>
<div class="table-responsive">
<table class="table align-middle">
  <thead class="table-light"><tr><th>Fecha</th><th>Manicurista</th><th>Monto</th><th>Concepto</th><th>Registrado por</th></tr></thead>
  <tbody>
  <?php if (!$pagos): ?><tr><td colspan="5" class="text-center text-muted py-4">Sin pagos registrados aún.</td></tr><?php endif; ?>
  <?php foreach ($pagos as $p): ?>
    <tr>
      <td><?= e(date('d/m/Y', strtotime($p['fecha_pago']))) ?></td>
      <td><?= e($p['manicurista']) ?></td>
      <td class="fw-bold text-rosa"><?= precio($p['monto']) ?></td>
      <td><?= e($p['concepto'] ?? '—') ?><?= $p['liquidacion_id'] ? ' <span class="badge text-bg-light border">nómina</span>' : '' ?></td>
      <td><small><?= e($p['registrado']) ?></small></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/bottom.php'; ?>
