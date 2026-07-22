<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nomina.php';
$u = requerirLogin(['admin']);
$activo = 'asistencia';
$tituloAdmin = 'Asistencia (bono quincenal)';
$pdo = db();
$mensaje = null;
$error = null;

$cortes = cortesRecientes(8);
$sel = $_REQUEST['corte'] ?? $cortes[0]['inicio'];
$corte = null;
foreach ($cortes as $c) if ($c['inicio'] === $sel) $corte = $c;
$corte = $corte ?? $cortes[0];

$manicuristas = manicuristasActivas();

// Días programados de cada manicurista dentro del corte (hasta hoy, no marcamos el futuro)
$hoy = date('Y-m-d');
$topeVista = min($corte['fin'], $hoy);

/** Fechas programadas de una manicurista dentro del corte, hasta $tope. */
function fechasProgramadas(int $manicuristaId, string $ini, string $fin): array {
    $disp = disponibilidadUsuario($manicuristaId);
    $out = [];
    if ($fin < $ini) return $out;
    $cursor = new DateTimeImmutable($ini);
    $tope = new DateTimeImmutable($fin);
    while ($cursor <= $tope) {
        $f = $cursor->format('Y-m-d');
        $esp = diaEspecial($f);
        if ($esp) {
            if ((int)$esp['abierto'] === 1) $out[] = $f;
        } elseif (isset($disp[(int)$cursor->format('N')])) {
            $out[] = $f;
        }
        $cursor = $cursor->modify('+1 day');
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar'])) {
    $marcadas = $_POST['falta'] ?? [];   // [manicuristaId][fecha] = 1
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM asistencia WHERE manicurista_id = ? AND fecha BETWEEN ? AND ?');
        $ins = $pdo->prepare('INSERT INTO asistencia (manicurista_id, fecha, asistio, motivo, registrado_por)
                              VALUES (?,?,0,?,?)
                              ON DUPLICATE KEY UPDATE asistio = 0, motivo = VALUES(motivo), registrado_por = VALUES(registrado_por)');
        $total = 0;
        foreach ($manicuristas as $m) {
            $mid = (int)$m['id'];
            $del->execute([$mid, $corte['inicio'], $topeVista]);
            $validas = fechasProgramadas($mid, $corte['inicio'], $topeVista);
            foreach (array_keys($marcadas[$mid] ?? []) as $fecha) {
                if (!in_array($fecha, $validas, true)) continue;   // ignora fechas fuera del corte o no programadas
                $motivo = trim((string)($_POST['motivo'][$mid][$fecha] ?? ''));
                $ins->execute([$mid, $fecha, $motivo ?: null, $u['id']]);
                $total++;
            }
        }
        $pdo->commit();
        $mensaje = "Asistencia guardada · $total falta(s) registrada(s) en el corte {$corte['etiqueta']}.";
    } catch (Throwable $ex) {
        $pdo->rollBack();
        $error = 'No se pudo guardar la asistencia.';
    }
}

// Faltas ya registradas en el corte
$faltas = [];
if ($manicuristas) {
    $ids = array_column($manicuristas, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $qf = $pdo->prepare("SELECT manicurista_id, fecha, motivo FROM asistencia
                         WHERE asistio = 0 AND fecha BETWEEN ? AND ? AND manicurista_id IN ($in)");
    $qf->execute(array_merge([$corte['inicio'], $corte['fin']], $ids));
    foreach ($qf as $r) $faltas[(int)$r['manicurista_id']][$r['fecha']] = $r['motivo'];
}

require __DIR__ . '/includes/top.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?= e($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<form method="get" class="row g-2 align-items-end mb-3">
  <div class="col-md-4"><label class="form-label mb-1">Corte</label>
    <select name="corte" class="form-select" onchange="this.form.submit()">
      <?php foreach ($cortes as $c): ?>
        <option value="<?= e($c['inicio']) ?>" <?= $c['inicio'] === $corte['inicio'] ? 'selected' : '' ?>><?= e($c['etiqueta']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<div class="alert alert-light border">
  <small class="text-muted">
    Marca <strong>solo los días que la manicurista NO fue a trabajar</strong>. Cada falta descuenta
    <strong><?= precio(bonoValorDia()) ?></strong> del bono de <strong><?= precio(bonoQuincenal()) ?></strong> de la quincena.
    Solo aparecen los días que tenía programados según su horario (y que ya pasaron).
  </small>
</div>

<form method="post">
  <?= csrfField() ?>
  <input type="hidden" name="corte" value="<?= e($corte['inicio']) ?>">
  <input type="hidden" name="guardar" value="1">

  <?php foreach ($manicuristas as $m): $mid = (int)$m['id'];
        $fechas = fechasProgramadas($mid, $corte['inicio'], $topeVista);
        $nFaltas = count(array_filter(array_keys($faltas[$mid] ?? []), fn($f) => in_array($f, $fechas, true)));
        $bono = bonoPorFaltas($nFaltas); ?>
  <div class="card card-servicio p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <h5 class="text-rosa mb-0"><?= e($m['nombre']) ?></h5>
      <div>
        <span class="badge text-bg-light border"><?= count($fechas) ?> día(s) programado(s)</span>
        <span class="badge text-bg-<?= $nFaltas ? 'danger' : 'success' ?>"><?= $nFaltas ?> falta(s)</span>
        <span class="badge text-bg-info">Bono: <?= precio($bono) ?></span>
      </div>
    </div>
    <?php if (!$fechas): ?>
      <p class="text-muted small mb-0">Sin días programados en este corte (revisa su horario en <a href="personal.php">Personal</a>).</p>
    <?php else: ?>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($fechas as $f): $marcada = array_key_exists($f, $faltas[$mid] ?? []); $ts = strtotime($f); ?>
      <div class="border rounded p-2 <?= $marcada ? 'border-danger bg-danger-subtle' : '' ?>" style="width:110px">
        <div class="form-check mb-1">
          <input class="form-check-input" type="checkbox" id="f<?= $mid ?>_<?= $f ?>"
                 name="falta[<?= $mid ?>][<?= e($f) ?>]" value="1" <?= $marcada ? 'checked' : '' ?>>
          <label class="form-check-label small" for="f<?= $mid ?>_<?= $f ?>">
            <strong><?= e(date('d/m', $ts)) ?></strong><br>
            <span class="text-muted"><?= e(['Mon'=>'lun','Tue'=>'mar','Wed'=>'mié','Thu'=>'jue','Fri'=>'vie','Sat'=>'sáb','Sun'=>'dom'][date('D', $ts)] ?? '') ?></span>
          </label>
        </div>
        <input type="text" name="motivo[<?= $mid ?>][<?= e($f) ?>]" class="form-control form-control-sm"
               placeholder="motivo" value="<?= e($faltas[$mid][$f] ?? '') ?>" style="font-size:.72rem">
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <?php if (!$manicuristas): ?><p class="text-muted">No hay manicuristas activas.</p><?php endif; ?>
  <button class="btn btn-sns">Guardar asistencia del corte</button>
</form>

<?php require __DIR__ . '/includes/bottom.php'; ?>
