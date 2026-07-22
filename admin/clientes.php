<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fidelidad.php';
$u = requerirLogin(['admin', 'recepcion']);
$activo = 'clientes';
$tituloAdmin = 'Clientas';
$pdo = db();
$mensaje = null;
$error = null;
$errorFicha = null;   // id de clienta existente, para enlazar desde el aviso de duplicado

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['nueva'])) {
        $nombre = trim($_POST['nombre'] ?? '');
        $telefono = normalizarTelefono($_POST['telefono'] ?? '');   // se guarda en solo dígitos
        $email = trim($_POST['email'] ?? '');
        $tipo = trim($_POST['tipo'] ?? '');
        $documento = trim($_POST['documento'] ?? '');
        $nacimiento = $_POST['fecha_nacimiento'] ?? '';
        $direccion = trim($_POST['direccion'] ?? '');
        $notas = trim($_POST['notas'] ?? '');

        // El teléfono es la llave con la que se reconoce a la clienta en el
        // agendamiento y en fidelidad: si ya existe, no se duplica.
        $yaExiste = $telefono !== '' ? clientePorTelefono($telefono) : null;
        $errTel = errorTelefonoCliente($_POST['telefono'] ?? '');

        if ($nombre === '') {
            $error = 'Nombre y teléfono son obligatorios.';
        } elseif ($errTel) {
            $error = $errTel;
        } elseif ($yaExiste) {
            $error = 'Ya existe una clienta con el teléfono ' . $telefono . ': ' . $yaExiste['nombre'] . '.';
            $errorFicha = (int)$yaExiste['id'];   // el aviso ofrece abrir su ficha
        } elseif ($tipo !== '' && !in_array($tipo, tiposCliente(), true)) {
            $error = 'Tipo de clienta inválido.';
        } elseif ($nacimiento !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nacimiento)) {
            $error = 'Fecha de nacimiento inválida.';
        } else {
            try {
                $st = $pdo->prepare('INSERT INTO clientes (nombre, telefono, email, tipo, documento, fecha_nacimiento, direccion, notas, es_nueva)
                                     VALUES (?,?,?,?,?,?,?,?,0)');
                $st->execute([$nombre, $telefono, $email ?: null, $tipo ?: null, $documento ?: null,
                              $nacimiento ?: null, $direccion ?: null, $notas ?: null]);
                $mensaje = 'Clienta ' . $nombre . ' registrada.';
            } catch (PDOException $ex) {
                // La base rechaza el teléfono repetido aunque la verificación de arriba
                // haya pasado (dos altas a la vez con el mismo número).
                if (($ex->errorInfo[1] ?? 0) !== 1062) throw $ex;
                $ya = clientePorTelefono($telefono);
                $error = 'Ese teléfono acaba de quedar registrado a nombre de ' . ($ya['nombre'] ?? 'otra clienta') . '.';
                if ($ya) $errorFicha = (int)$ya['id'];
            }
        }
    } elseif (isset($_POST['guardar'])) {
        $cid = (int)$_POST['cliente_id'];
        $nombre = trim($_POST['nombre'] ?? '');
        $telefono = normalizarTelefono($_POST['telefono'] ?? '');   // se guarda en solo dígitos
        $email = trim($_POST['email'] ?? '');
        $tipo = trim($_POST['tipo'] ?? '');
        $documento = trim($_POST['documento'] ?? '');
        $nacimiento = $_POST['fecha_nacimiento'] ?? '';
        $direccion = trim($_POST['direccion'] ?? '');
        $notas = trim($_POST['notas'] ?? '');
        $errTel = errorTelefonoCliente($_POST['telefono'] ?? '');
        // Cambiar el teléfono al de otra clienta rompería el reconocimiento de ambas.
        $otra = $telefono !== '' ? clientePorTelefono($telefono) : null;
        if ($otra && (int)$otra['id'] === $cid) $otra = null;

        if ($nombre === '') {
            $error = 'Nombre y teléfono son obligatorios.';
        } elseif ($errTel) {
            $error = $errTel;
        } elseif ($otra) {
            $error = 'Ese teléfono ya es de otra clienta: ' . $otra['nombre'] . '.';
            $errorFicha = (int)$otra['id'];
        } elseif ($tipo !== '' && !in_array($tipo, tiposCliente(), true)) {
            $error = 'Tipo de clienta inválido.';
        } elseif ($nacimiento !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nacimiento)) {
            $error = 'Fecha de nacimiento inválida.';
        } else {
            // La tarjeta de fidelidad se referencia por teléfono: si cambia el de la
            // clienta hay que moverlo también, o su tarjeta quedaría huérfana y en el
            // siguiente cobro se le crearía una nueva, perdiendo las visitas.
            $ant = $pdo->prepare('SELECT telefono FROM clientes WHERE id = ?');
            $ant->execute([$cid]);
            $telAnterior = (string)$ant->fetchColumn();

            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('UPDATE clientes SET nombre=?, telefono=?, email=?, tipo=?, documento=?, fecha_nacimiento=?, direccion=?, notas=? WHERE id=?');
                $st->execute([$nombre, $telefono, $email ?: null, $tipo ?: null, $documento ?: null, $nacimiento ?: null, $direccion ?: null, $notas ?: null, $cid]);

                $mensaje = 'Datos de la clienta actualizados.';
                if (normalizarTelefono($telAnterior) !== $telefono) {
                    $variantes = variantesTelefono($telAnterior);
                    if ($variantes) {
                        $in = implode(',', array_fill(0, count($variantes), '?'));
                        $upd = $pdo->prepare('UPDATE tarjetas SET telefono = ?, nombre = ? WHERE '
                                           . sqlTelefonoNormalizado() . " IN ($in)");
                        $upd->execute(array_merge([$telefono, $nombre], $variantes));
                        if ($upd->rowCount() > 0) {
                            $mensaje .= ' Su tarjeta de fidelidad se movió al nuevo número (conserva sus visitas).';
                        }
                    }
                }
                $pdo->commit();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                $mensaje = null;
                if ($ex instanceof PDOException && ($ex->errorInfo[1] ?? 0) === 1062) {
                    $ya = clientePorTelefono($telefono);
                    $error = 'Ese teléfono ya está registrado a nombre de ' . ($ya['nombre'] ?? 'otra clienta') . '.';
                    if ($ya) $errorFicha = (int)$ya['id'];
                } else {
                    $error = 'No se pudieron guardar los cambios.';
                }
            }
        }
    } elseif (isset($_POST['validar_id'])) {
        $cid = (int)$_POST['validar_id'];
        $c = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
        $c->execute([$cid]);
        if ($cl = $c->fetch()) {
            $pdo->prepare('UPDATE clientes SET validado=1, validado_por=?, validado_en=NOW() WHERE id=?')->execute([$u['id'], $cid]);
            $t = tarjetaAbierta($cl['telefono'], $cl['nombre'] !== '' ? $cl['nombre'] : 'Clienta'); // crea la tarjeta si no existe
            $mensaje = 'Clienta validada' . ($t ? ' y tarjeta generada (#' . $t['codigo'] . ').' : '.');
        } else {
            $error = 'Clienta no encontrada.';
        }
    }
}

$q = trim($_GET['q'] ?? '');
$sql = "SELECT c.*, v.nombre AS validador,
               (SELECT COUNT(*) FROM citas ci WHERE ci.cliente_id = c.id) AS num_citas,
               (SELECT COUNT(*) FROM tarjetas t WHERE t.telefono = c.telefono AND t.abierta = 1) AS tiene_tarjeta
        FROM clientes c LEFT JOIN usuarios v ON v.id = c.validado_por";
$params = [];
if ($q !== '') {
    $sql .= ' WHERE c.nombre LIKE ? OR c.telefono LIKE ? OR c.documento LIKE ?';
    $params = ["%$q%", "%$q%", "%$q%"];
}
$sql .= ' ORDER BY c.creado_en DESC LIMIT 200';
$st = $pdo->prepare($sql);
$st->execute($params);
$clientes = $st->fetchAll();

// Fidelidad de las clientas mostradas (por teléfono): visitas + cupones vigentes
$fp = fidelidadParams();
$fidel = []; // telefono => ['visitas'=>n, 'cupones'=>n, 'codigo'=>...]
$telsList = array_values(array_unique(array_filter(array_column($clientes, 'telefono'))));
if ($telsList) {
    $ph = implode(',', array_fill(0, count($telsList), '?'));
    $tq = $pdo->prepare("SELECT id, telefono, codigo FROM tarjetas WHERE abierta = 1 AND telefono IN ($ph)");
    $tq->execute($telsList);
    $tarjPorTel = []; $tarjIds = [];
    foreach ($tq as $t) { $tarjPorTel[$t['telefono']] = $t; $tarjIds[] = (int)$t['id']; }
    if ($tarjIds) {
        $inT = implode(',', array_fill(0, count($tarjIds), '?'));
        $vis = []; $cup = [];
        $vq = $pdo->prepare("SELECT tarjeta_id, COUNT(*) n FROM visitas_tarjeta WHERE tarjeta_id IN ($inT) GROUP BY tarjeta_id");
        $vq->execute($tarjIds);
        foreach ($vq as $r) $vis[(int)$r['tarjeta_id']] = (int)$r['n'];
        $cq = $pdo->prepare("SELECT tarjeta_id, COUNT(*) n FROM cupones WHERE tarjeta_id IN ($inT) AND usado_en IS NULL AND vence_el >= CURDATE() GROUP BY tarjeta_id");
        $cq->execute($tarjIds);
        foreach ($cq as $r) $cup[(int)$r['tarjeta_id']] = (int)$r['n'];
        foreach ($tarjPorTel as $tel => $t) {
            $fidel[$tel] = ['visitas' => $vis[(int)$t['id']] ?? 0, 'cupones' => $cup[(int)$t['id']] ?? 0, 'codigo' => $t['codigo']];
        }
    }
}

// Clienta en edición
$editar = null;
if (isset($_GET['editar'])) {
    $e = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
    $e->execute([(int)$_GET['editar']]);
    $editar = $e->fetch() ?: null;
}

require __DIR__ . '/includes/top.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?= e($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?>
  <div class="alert alert-danger">
    <?= e($error) ?>
    <?php if ($errorFicha): ?>
      <a href="clientes.php?editar=<?= (int)$errorFicha ?>" class="alert-link">Abrir su ficha</a>.
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$editar): ?>
<div class="mb-3">
  <button class="btn btn-sns" type="button" data-bs-toggle="collapse" data-bs-target="#formNuevaClienta">＋ Nueva clienta</button>
</div>
<div class="collapse mb-4" id="formNuevaClienta">
  <div class="card card-servicio p-3">
    <h5 class="text-rosa">Registrar clienta</h5>
    <form method="post" class="row g-2">
      <?= csrfField() ?>
      <input type="hidden" name="nueva" value="1">
      <div class="col-md-4"><label class="form-label mb-1">Nombre *</label><input name="nombre" class="form-control" required></div>
      <div class="col-md-4"><label class="form-label mb-1">Teléfono *</label>
        <input name="telefono" type="tel" class="form-control" required
               data-telefono-cliente maxlength="10" inputmode="numeric"></div>
      <div class="col-md-4"><label class="form-label mb-1">Correo</label><input name="email" type="email" class="form-control"></div>
      <div class="col-md-3"><label class="form-label mb-1">Tipo</label>
        <select name="tipo" class="form-select">
          <option value="">— Sin tipificar —</option>
          <?php foreach (tiposCliente() as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label mb-1">Documento</label><input name="documento" class="form-control"></div>
      <div class="col-md-3"><label class="form-label mb-1">Fecha de nacimiento</label><input name="fecha_nacimiento" type="date" class="form-control"></div>
      <div class="col-md-3"><label class="form-label mb-1">Dirección</label><input name="direccion" class="form-control"></div>
      <div class="col-12"><label class="form-label mb-1">Notas</label><textarea name="notas" class="form-control" rows="2" maxlength="500"></textarea></div>
      <div class="col-12">
        <button class="btn btn-sns">Guardar clienta</button>
        <small class="text-muted ms-2">El teléfono no se puede repetir: es con lo que se reconoce a la clienta al agendar y en fidelidad.</small>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($editar): ?>
<div class="card card-servicio p-3 mb-4">
  <div class="d-flex justify-content-between align-items-center">
    <h5 class="text-rosa mb-0">Editar clienta: <?= e($editar['nombre']) ?></h5>
    <a href="clientes.php" class="btn btn-sns-outline btn-sm">← Volver a la lista</a>
  </div>
  <form method="post" class="row g-2 mt-2">
    <?= csrfField() ?>
    <input type="hidden" name="guardar" value="1">
    <input type="hidden" name="cliente_id" value="<?= (int)$editar['id'] ?>">
    <div class="col-md-4"><label class="form-label mb-1">Nombre *</label><input name="nombre" class="form-control" value="<?= e($editar['nombre']) ?>" required></div>
    <div class="col-md-4"><label class="form-label mb-1">Teléfono *</label>
      <input name="telefono" type="tel" class="form-control" value="<?= e($editar['telefono']) ?>" required
             data-telefono-cliente maxlength="10" inputmode="numeric"></div>
    <div class="col-md-4"><label class="form-label mb-1">Correo</label><input name="email" type="email" class="form-control" value="<?= e($editar['email'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label mb-1">Tipo</label>
      <select name="tipo" class="form-select">
        <option value="">— Sin tipificar —</option>
        <?php foreach (tiposCliente() as $t): ?><option value="<?= e($t) ?>" <?= $editar['tipo'] === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3"><label class="form-label mb-1">Documento</label><input name="documento" class="form-control" value="<?= e($editar['documento'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label mb-1">Fecha de nacimiento</label><input name="fecha_nacimiento" type="date" class="form-control" value="<?= e($editar['fecha_nacimiento'] ?? '') ?>"></div>
    <div class="col-md-3"><label class="form-label mb-1">Dirección</label><input name="direccion" class="form-control" value="<?= e($editar['direccion'] ?? '') ?>"></div>
    <div class="col-12"><label class="form-label mb-1">Notas</label><textarea name="notas" class="form-control" rows="2" maxlength="500"><?= e($editar['notas'] ?? '') ?></textarea></div>
    <div class="col-12"><button class="btn btn-sns">Guardar datos</button></div>
  </form>
</div>

<?php $tarjE = tarjetaAbierta($editar['telefono']); // solo consulta ?>
<?php if ($tarjE): $dE = datosTarjeta($tarjE); $enCicloE = $dE['visitas'] % $fp['visitas']; ?>
<div class="card card-servicio p-3 mb-4" style="background:var(--sns-crema)">
  <h6 class="text-rosa mb-2">🎫 Fidelidad · Tarjeta #<?= e($tarjE['codigo']) ?></h6>
  <p class="mb-2">
    Visitas totales: <strong><?= (int)$dE['visitas'] ?></strong> ·
    ciclo actual <strong><?= $enCicloE ?>/<?= $fp['visitas'] ?></strong>
    (cupón de -<?= $fp['descuento'] ?>% cada <?= $fp['visitas'] ?> visitas)
  </p>
  <div class="d-flex flex-wrap gap-1 mb-2">
    <?php for ($i = 1; $i <= (int)$fp['visitas']; $i++): $sellada = $i <= $enCicloE; ?>
      <span class="d-inline-flex align-items-center justify-content-center rounded-circle fw-bold" style="width:30px;height:30px;<?= $sellada ? 'background:var(--sns-rosa);color:#fff' : 'background:#fff;border:2px dashed var(--sns-rosa-claro);color:var(--sns-rosa-claro)' ?>"><?= $sellada ? '☾' : $i ?></span>
    <?php endfor; ?>
  </div>
  <?php if ($dE['cupones']): ?>
    <div class="d-flex flex-wrap gap-1">
      <?php foreach ($dE['cupones'] as $cup): $vig = !$cup['usado_en'] && $cup['vence_el'] >= date('Y-m-d'); ?>
        <span class="badge <?= $cup['usado_en'] ? 'text-bg-secondary' : ($vig ? 'text-bg-success' : 'text-bg-danger') ?>">-<?= (int)$cup['descuento_pct'] ?>% <?= $cup['usado_en'] ? 'usado ' . e(date('d/m', strtotime($cup['usado_en']))) : ($vig ? 'vence ' . e(date('d/m', strtotime($cup['vence_el']))) : 'vencido') ?></span>
      <?php endforeach; ?>
    </div>
  <?php else: ?><small class="text-muted">Sin cupones todavía.</small><?php endif; ?>
</div>
<?php else: ?>
  <div class="alert alert-light border small">Esta clienta aún no tiene tarjeta de fidelidad. Se crea automáticamente al registrar su primer cobro (o al validarla).</div>
<?php endif; ?>
<?php endif; ?>

<form class="d-flex gap-2 mb-3" method="get">
  <input name="q" class="form-control" style="max-width:340px" placeholder="Buscar por nombre, teléfono o documento 🔍" value="<?= e($q) ?>">
  <button class="btn btn-sns-outline">Buscar</button>
</form>

<div class="table-responsive">
<table class="table align-middle">
  <thead class="table-light"><tr><th>Clienta</th><th>Contacto</th><th>Tipo</th><th>Citas</th><th>Fidelidad</th><th>Validación</th><th>Acciones</th></tr></thead>
  <tbody>
  <?php if (!$clientes): ?>
    <tr><td colspan="7" class="text-center text-muted py-4">No hay clientas<?= $q ? ' con esa búsqueda' : ' registradas aún' ?> 🌙</td></tr>
  <?php endif; ?>
  <?php foreach ($clientes as $c): ?>
    <tr>
      <td>
        <strong><?= e($c['nombre']) ?></strong>
        <?php if ($c['documento']): ?><br><small class="text-muted">Doc: <?= e($c['documento']) ?></small><?php endif; ?>
      </td>
      <td><small><?= e($c['telefono']) ?><?php if ($c['email']): ?><br><?= e($c['email']) ?><?php endif; ?></small></td>
      <td><?= $c['tipo'] ? '<span class="badge text-bg-light border">' . e($c['tipo']) . '</span>' : '<small class="text-muted">—</small>' ?></td>
      <td><span class="badge text-bg-secondary"><?= (int)$c['num_citas'] ?></span></td>
      <td>
        <?php $f = $fidel[$c['telefono']] ?? null; if ($f): $enCiclo = $f['visitas'] % $fp['visitas']; ?>
          <span class="badge text-bg-light border" title="Visitas del ciclo actual · <?= (int)$f['visitas'] ?> en total">🎫 <?= $enCiclo ?>/<?= $fp['visitas'] ?></span>
          <?php if ($f['cupones']): ?><br><span class="badge text-bg-success"><?= (int)$f['cupones'] ?> cupón<?= $f['cupones'] > 1 ? 'es' : '' ?></span><?php endif; ?>
        <?php else: ?><small class="text-muted">—</small><?php endif; ?>
      </td>
      <td>
        <?php if ($c['validado']): ?>
          <span class="badge text-bg-success">✔ Validada</span>
          <?php if ($c['tiene_tarjeta']): ?><br><small class="text-rosa">🎫 con tarjeta</small><?php endif; ?>
          <?php if ($c['validador']): ?><br><small class="text-muted">por <?= e($c['validador']) ?></small><?php endif; ?>
        <?php else: ?>
          <span class="badge text-bg-warning">sin validar</span>
        <?php endif; ?>
      </td>
      <td>
        <div class="d-flex gap-1 flex-wrap">
          <a href="clientes.php?editar=<?= (int)$c['id'] ?><?= $q ? '&q=' . urlencode($q) : '' ?>" class="btn btn-sns-outline btn-sm">✏️ Editar</a>
          <?php if (!$c['validado'] || !$c['tiene_tarjeta']): ?>
          <form method="post" onsubmit="return confirm('¿Validar a <?= e($c['nombre']) ?> y generar su tarjeta de fidelidad?')">
            <?= csrfField() ?>
            <input type="hidden" name="validar_id" value="<?= (int)$c['id'] ?>">
            <button class="btn btn-sns btn-sm"><?= $c['validado'] ? '🎫 Generar tarjeta' : '✔ Validar y generar tarjeta' ?></button>
          </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php require __DIR__ . '/includes/bottom.php'; ?>
