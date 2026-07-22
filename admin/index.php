<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fidelidad.php';
$u = requerirLogin();
$activo = 'citas';
$tituloAdmin = 'Citas';
$pdo = db();
$mensaje = null;
$error = null;
$esStaff = in_array($u['rol'], ['admin', 'recepcion'], true);

$estados = $pdo->query('SELECT * FROM estados_cita ORDER BY id')->fetchAll();
$estadosMap = [];
foreach ($estados as $es) $estadosMap[(int)$es['id']] = $es['nombre'];

// ── Crear cita desde el panel (admin y recepción) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_cita']) && $esStaff) {
    $nombre     = trim($_POST['c_nombre'] ?? '');
    $telefono   = normalizarTelefono($_POST['c_telefono'] ?? '');   // se guarda en solo dígitos
    $email      = trim($_POST['c_email'] ?? '');
    $servicioId = (int)($_POST['c_servicio'] ?? 0);
    $maniId     = (int)($_POST['c_manicurista'] ?? 0) ?: null;
    $fCita      = $_POST['c_fecha'] ?? '';
    $hCita      = $_POST['c_hora'] ?? '';
    $estadoId   = (int)($_POST['c_estado'] ?? 2) ?: 2;
    $notas      = trim($_POST['c_notas'] ?? '');

    if ($nombre === '' || $telefono === '' || !$servicioId || $fCita === '' || $hCita === '') {
        $error = 'Completa clienta, teléfono, servicio, fecha y hora.';
    } elseif ($errTel = errorTelefonoCliente($telefono)) {
        $error = $errTel;
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fCita) || !preg_match('/^\d{2}:\d{2}$/', $hCita)) {
        $error = 'Fecha u hora con formato inválido.';
    } elseif ($fCita < date('Y-m-d')) {
        $error = 'La fecha no puede ser en el pasado.';
    } elseif (!isset($estadosMap[$estadoId])) {
        $error = 'Estado inválido.';
    } else {
        // Cumple las mismas restricciones del front: horario de la manicurista, duración
        // del servicio, bloqueos, días especiales/cerrados y que el cupo esté libre.
        $disp = horasDisponibles($fCita, $servicioId, $maniId);
        if (!isset($disp[$hCita])) {
            $error = 'Esa hora no está disponible: revisa el horario de la manicurista, bloqueos, día cerrado o que el cupo esté libre.';
        } else {
            $maniAsignada = $maniId ?: (int)$disp[$hCita]; // si es "sin asignar", toma la manicurista libre
            $pdo->beginTransaction();
            try {
                $nombreReserva = null;
                $clienteId = obtenerOCrearCliente($nombre, $telefono, $email ?: null, 0, $nombreReserva);
                $titular = $pdo->prepare('SELECT nombre FROM clientes WHERE id = ?');
                $titular->execute([$clienteId]);
                $nombreTitular = (string)$titular->fetchColumn();

                // El nombre de la ficha no se pisa; si se escribió otro, queda anotado
                // en la cita para saber quién asiste realmente.
                $notasCita = $notas;
                if ($nombreReserva !== null) {
                    $notasCita = 'Asiste: ' . $nombreReserva . ($notas !== '' ? ' · ' . $notas : '');
                }

                $st = $pdo->prepare('INSERT INTO citas (cliente_id, servicio_id, manicurista_id, fecha, hora, estado_id, notas, creado_por)
                                     VALUES (?,?,?,?,?,?,?,?)');
                $st->execute([$clienteId, $servicioId, $maniAsignada, $fCita, $hCita, $estadoId,
                              $notasCita !== '' ? mb_substr($notasCita, 0, 255) : null, $u['id']]);
                $citaId = (int)$pdo->lastInsertId();

                auditarCita($citaId, $u['id'], 'creada', [
                    'fecha_nueva' => $fCita,
                    'hora_nueva'  => $hCita,
                    'detalle'     => 'Creada desde el panel por ' . $u['nombre']
                                   . ($nombreReserva !== null ? ' · a nombre de ' . $nombreReserva : ''),
                ]);
                $pdo->commit();
                $mensaje = 'Cita creada ✔ (registrada por ' . $u['nombre'] . ').';
                if ($nombreReserva !== null) {
                    $mensaje .= ' Ese teléfono es de ' . $nombreTitular . ': se conservó su ficha'
                              . ' y la cita quedó anotada como «Asiste: ' . $nombreReserva . '».';
                }
            } catch (Throwable $ex) {
                $pdo->rollBack();
                $error = 'No se pudo crear la cita. Verifica los datos.';
            }
        }
    }
}

// ── Reprogramar / actualizar estado de una cita (admin y recepción) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cita_id']) && $esStaff) {
    $citaId = (int)$_POST['cita_id'];
    $cur = $pdo->prepare('SELECT fecha, hora, estado_id FROM citas WHERE id = ?');
    $cur->execute([$citaId]);
    if ($actual = $cur->fetch()) {
        $curHoraHM   = substr($actual['hora'], 0, 5);
        $nuevoEstado = (int)($_POST['estado_id'] ?? $actual['estado_id']);
        $nuevaFecha  = $_POST['fecha_cita'] ?? $actual['fecha'];
        $nuevaHora   = $_POST['hora_cita'] ?? $curHoraHM;
        $comprobante = trim($_POST['comprobante'] ?? '');
        $reprogramada = false;

        // Reprogramación (cambia fecha u hora) → queda auditada
        if (($nuevaFecha !== $actual['fecha'] || $nuevaHora !== $curHoraHM)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nuevaFecha)
            && preg_match('/^\d{2}:\d{2}$/', $nuevaHora)) {
            $pdo->prepare('UPDATE citas SET fecha = ?, hora = ? WHERE id = ?')
                ->execute([$nuevaFecha, $nuevaHora, $citaId]);
            auditarCita($citaId, $u['id'], 'reprogramada', [
                'fecha_anterior' => $actual['fecha'], 'hora_anterior' => $actual['hora'],
                'fecha_nueva'    => $nuevaFecha,       'hora_nueva'    => $nuevaHora,
            ]);
            $reprogramada = true;
        }

        // Estado y/o comprobante
        $pdo->prepare('UPDATE citas SET estado_id = ?, comprobante = COALESCE(NULLIF(?, ""), comprobante) WHERE id = ?')
            ->execute([$nuevoEstado, $comprobante, $citaId]);
        if ($nuevoEstado !== (int)$actual['estado_id'] && isset($estadosMap[$nuevoEstado])) {
            auditarCita($citaId, $u['id'], 'estado', [
                'detalle' => 'Estado: ' . ($estadosMap[(int)$actual['estado_id']] ?? $actual['estado_id'])
                           . ' → ' . $estadosMap[$nuevoEstado],
            ]);
        }
        $mensaje = $reprogramada ? 'Cita reprogramada ✔' : 'Cita actualizada ✔';
    }
}

// ── Registrar cobro de una reserva: total único + forma de pago + cupón (admin y recepción) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_cobro']) && $esStaff) {
    $citaId = (int)($_POST['cobro_cita_id'] ?? 0);
    $ci = $pdo->prepare('SELECT c.cliente_id, c.manicurista_id, c.grupo_id, cl.telefono
                         FROM citas c JOIN clientes cl ON cl.id = c.cliente_id WHERE c.id = ?');
    $ci->execute([$citaId]);
    $crow = $ci->fetch() ?: null;
    $monto = max(0, (int)($_POST['cobro_monto'] ?? 0));
    $forma = trim($_POST['cobro_forma'] ?? '');
    $servicios = trim($_POST['cobro_servicios'] ?? '') ?: null;
    $cuponId = (int)($_POST['cobro_cupon'] ?? 0);
    if (!$crow) {
        $error = 'Cita no encontrada.';
    } elseif ($monto <= 0 || !in_array($forma, formasPago(), true)) {
        $error = 'Indica el total y una forma de pago válida.';
    } else {
        // Evitar doble cobro de la misma reserva/cita
        if ($crow['grupo_id']) {
            $ya = $pdo->prepare('SELECT COUNT(*) FROM cobros WHERE grupo_id = ?');
            $ya->execute([$crow['grupo_id']]);
        } else {
            $ya = $pdo->prepare('SELECT COUNT(*) FROM cobros WHERE cita_id = ?');
            $ya->execute([$citaId]);
        }
        if ((int)$ya->fetchColumn() > 0) {
            $error = 'Esa reserva ya tiene un cobro registrado.';
        } else {
            // Cupón (opcional): validar que sea de la clienta, vigente y sin usar; calcular descuento
            $descuento = 0; $cuponRow = null;
            if ($cuponId) {
                $cq = $pdo->prepare("SELECT c.id, c.descuento_pct FROM cupones c JOIN tarjetas t ON t.id = c.tarjeta_id
                                     WHERE c.id = ? AND t.telefono = ? AND c.usado_en IS NULL AND c.vence_el >= CURDATE()");
                $cq->execute([$cuponId, $crow['telefono']]);
                $cuponRow = $cq->fetch() ?: null;
                if (!$cuponRow) $error = 'El cupón no es válido, ya se usó o venció.';
                else $descuento = (int)round($monto * (int)$cuponRow['descuento_pct'] / 100);
            }
            if (!$error) {
                $montoNeto = max(0, $monto - $descuento);
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO cobros (grupo_id, cita_id, cliente_id, manicurista_id, monto, descuento, forma_pago, cupon_id, servicios_realizados, fecha_pago, registrado_por)
                                   VALUES (?,?,?,?,?,?,?,?,?,CURDATE(),?)')
                        ->execute([$crow['grupo_id'], $crow['grupo_id'] ? null : $citaId, $crow['cliente_id'], $crow['manicurista_id'],
                                   $montoNeto, $descuento ?: null, $forma, $cuponRow ? (int)$cuponRow['id'] : null, $servicios, $u['id']]);
                    $cobroId = (int)$pdo->lastInsertId();
                    if ($cuponRow) {
                        [$okC, $msgC] = aplicarCupon((int)$cuponRow['id'], $u['id']); // marca usado + renueva tarjeta si aplica
                        if (!$okC) throw new RuntimeException($msgC);
                    }
                    // Fidelidad automática: suma la visita a la tarjeta (crea la tarjeta si no existe)
                    $msgFid = sumarVisitaPorCobro((int)$crow['cliente_id'], $u['id'], $cobroId);
                    $pdo->commit();
                    $mensaje = 'Cobro registrado ✔'
                             . ($descuento > 0 ? ' · cupón -' . (int)$cuponRow['descuento_pct'] . '% (' . precio($descuento) . ')' : '')
                             . ($msgFid ? ' · ' . $msgFid : '');
                } catch (Throwable $ex) {
                    $pdo->rollBack();
                    $error = $ex instanceof RuntimeException ? $ex->getMessage() : 'No se pudo registrar el cobro.';
                }
            }
        }
    }
}

// ── Eliminar un cobro (admin y recepción) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_cobro']) && $esStaff) {
    $pdo->prepare('DELETE FROM cobros WHERE id = ?')->execute([(int)$_POST['eliminar_cobro']]);
    $mensaje = 'Cobro eliminado.';
}

$fecha = $_GET['fecha'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');
$todas = isset($_GET['todas']); // ver todas las citas sin importar la fecha

$sql = "SELECT c.*, cl.nombre AS cliente, cl.telefono, cl.es_nueva, cl.validado AS cliente_validado, s.nombre AS servicio, s.precio,
               ec.nombre AS estado, ec.color, m.nombre AS manicurista, reg.nombre AS registrado_por
        FROM citas c
        JOIN clientes cl ON cl.id = c.cliente_id
        JOIN servicios s ON s.id = c.servicio_id
        JOIN estados_cita ec ON ec.id = c.estado_id
        LEFT JOIN usuarios m ON m.id = c.manicurista_id
        LEFT JOIN usuarios reg ON reg.id = c.creado_por";
$cond = [];
$params = [];
if (!$todas) { $cond[] = 'c.fecha = ?'; $params[] = $fecha; }
if ($u['rol'] === 'manicurista') { $cond[] = 'c.manicurista_id = ?'; $params[] = $u['id']; }
if ($cond) $sql .= ' WHERE ' . implode(' AND ', $cond);
$sql .= $todas ? ' ORDER BY c.fecha DESC, c.hora' : ' ORDER BY c.hora';
$st = $pdo->prepare($sql);
$st->execute($params);
$citas = $st->fetchAll();

// Bitácora de las citas mostradas (para el historial por fila)
$auditPorCita = [];
if ($citas) {
    $ids = array_column($citas, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $qa = $pdo->prepare("SELECT a.*, u.nombre AS quien
                         FROM citas_auditoria a
                         LEFT JOIN usuarios u ON u.id = a.usuario_id
                         WHERE a.cita_id IN ($ph)
                         ORDER BY a.creado_en DESC, a.id DESC");
    $qa->execute($ids);
    foreach ($qa as $r) $auditPorCita[$r['cita_id']][] = $r;
}

// Reservas con varios servicios (mismo grupo_id): color, orden agrupado y posición
$grupoCount = [];
$grupoMinHora = [];
foreach ($citas as $c) {
    $g = $c['grupo_id'];
    if (!$g) continue;
    $grupoCount[$g] = ($grupoCount[$g] ?? 0) + 1;
    $h = substr($c['hora'], 0, 5);
    if (!isset($grupoMinHora[$g]) || $h < $grupoMinHora[$g]) $grupoMinHora[$g] = $h;
}

// Ordena manteniendo juntas las citas de una misma reserva, ancladas a su hora más temprana
usort($citas, function ($a, $b) use ($grupoCount, $grupoMinHora, $todas) {
    if ($todas && $a['fecha'] !== $b['fecha']) return strcmp($b['fecha'], $a['fecha']); // fecha DESC
    $ga = $a['grupo_id']; $gb = $b['grupo_id'];
    $aEnGrupo = $ga && $grupoCount[$ga] > 1;
    $bEnGrupo = $gb && $grupoCount[$gb] > 1;
    $anclaA = $aEnGrupo ? $grupoMinHora[$ga] : substr($a['hora'], 0, 5);
    $anclaB = $bEnGrupo ? $grupoMinHora[$gb] : substr($b['hora'], 0, 5);
    if ($anclaA !== $anclaB) return strcmp($anclaA, $anclaB);          // 1) hora de inicio del bloque
    $claveA = $aEnGrupo ? $ga : ('_' . $a['id']);
    $claveB = $bEnGrupo ? $gb : ('_' . $b['id']);
    if ($claveA !== $claveB) return strcmp($claveA, $claveB);          // 2) misma ancla: agrupa por reserva
    return strcmp($a['hora'], $b['hora']);                             // 3) dentro de la reserva, por hora
});

$paletaGrupo = ['#e75da0', '#8e7cc3', '#5aa9c9', '#d98c5f', '#5bab77', '#b06fb3'];
$grupoColor = [];
$grupoPos = [];
$vistos = [];
$idxColor = 0;
foreach ($citas as $c) {           // recorrer YA ordenado
    $g = $c['grupo_id'];
    if (!$g || ($grupoCount[$g] ?? 0) < 2) continue;
    if (!isset($grupoColor[$g])) { $grupoColor[$g] = $paletaGrupo[$idxColor % count($paletaGrupo)]; $idxColor++; }
    $vistos[$g] = ($vistos[$g] ?? 0) + 1;
    $grupoPos[$c['id']] = $vistos[$g];
}

// Cobros de las reservas/citas mostradas (un cobro por reserva)
$cobros = []; // reservaKey => cobro
if ($citas) {
    $gids = array_values(array_unique(array_filter(array_column($citas, 'grupo_id'))));
    $cids = array_column($citas, 'id');
    $cond = []; $pc = [];
    if ($gids) { $cond[] = 'co.grupo_id IN (' . implode(',', array_fill(0, count($gids), '?')) . ')'; $pc = array_merge($pc, $gids); }
    if ($cids) { $cond[] = 'co.cita_id IN (' . implode(',', array_fill(0, count($cids), '?')) . ')'; $pc = array_merge($pc, $cids); }
    if ($cond) {
        $qc = $pdo->prepare('SELECT co.*, m.nombre AS manicurista_nombre, r.nombre AS registrado_nombre
                             FROM cobros co
                             LEFT JOIN usuarios m ON m.id = co.manicurista_id
                             LEFT JOIN usuarios r ON r.id = co.registrado_por
                             WHERE ' . implode(' OR ', $cond));
        $qc->execute($pc);
        foreach ($qc as $co) {
            $cobros[$co['grupo_id'] ?: ('cita:' . $co['cita_id'])] = $co;
        }
    }
}

// Cupones activos de las clientas mostradas (para aplicarlos al cobrar)
$cuponesActivos = [];
if ($esStaff && $citas) {
    $tels = array_values(array_unique(array_filter(array_column($citas, 'telefono'))));
    if ($tels) {
        $ph = implode(',', array_fill(0, count($tels), '?'));
        $qk = $pdo->prepare("SELECT c.id, c.descuento_pct, c.vence_el, t.telefono
                             FROM cupones c JOIN tarjetas t ON t.id = c.tarjeta_id
                             WHERE t.abierta = 1 AND c.usado_en IS NULL AND c.vence_el >= CURDATE() AND t.telefono IN ($ph)
                             ORDER BY c.vence_el");
        $qk->execute($tels);
        foreach ($qk as $r) if (!isset($cuponesActivos[$r['telefono']])) $cuponesActivos[$r['telefono']] = $r;
    }
}

// KPIs del día
$totCitas = count($citas);
$pendPago = count(array_filter($citas, fn($c) => (int)$c['estado_id'] === 1));
$cobradoHoy = array_sum(array_map(fn($co) => (int)$co['monto'], $cobros));
$stockBajo = (int)$pdo->query('SELECT COUNT(*) FROM productos WHERE stock <= stock_minimo')->fetchColumn();

// Catálogos para el formulario "Nueva cita"
// Mismo orden que en el sitio: recepción encuentra manicure y pedicure de primeras.
$serviciosCat = $esStaff ? $pdo->query('SELECT id, nombre FROM servicios WHERE activo=1 ORDER BY orden, precio, nombre')->fetchAll() : [];
$manicuristasCat = $esStaff ? $pdo->query("SELECT id, nombre FROM usuarios WHERE rol='manicurista' AND activo=1 ORDER BY nombre")->fetchAll() : [];

require __DIR__ . '/includes/top.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?= e($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="tarjeta-kpi p-3"><div class="valor"><?= $totCitas ?></div><small><?= $todas ? 'Citas (todas)' : 'Citas del día' ?></small></div></div>
  <div class="col-6 col-lg-3"><div class="tarjeta-kpi p-3"><div class="valor"><?= $pendPago ?></div><small>Esperando pago</small></div></div>
  <div class="col-6 col-lg-3"><div class="tarjeta-kpi p-3"><div class="valor"><?= precio($cobradoHoy) ?></div><small><?= $todas ? 'Cobrado (mostrado)' : 'Cobrado hoy' ?></small></div></div>
  <?php if (puedeVer('inventario')): ?>
  <div class="col-6 col-lg-3"><div class="tarjeta-kpi p-3"><div class="valor"><?= $stockBajo ?></div><small>Productos con stock bajo</small></div></div>
  <?php endif; ?>
</div>

<?php if ($esStaff): ?>
<div class="mb-3">
  <button class="btn btn-sns" type="button" data-bs-toggle="collapse" data-bs-target="#formNuevaCita">＋ Nueva cita</button>
</div>
<div class="collapse mb-4" id="formNuevaCita">
  <div class="card card-servicio p-3">
    <h5 class="text-rosa">Nueva cita <small class="text-muted fs-6">— quedará registrada a tu nombre (<?= e($u['nombre']) ?>)</small></h5>
    <form method="post" class="row g-2 align-items-end">
      <?= csrfField() ?>
      <input type="hidden" name="crear_cita" value="1">
      <div class="col-md-2"><label class="form-label mb-1">Teléfono *</label>
        <input name="c_telefono" id="c_telefono" type="tel" class="form-control" autocomplete="off" required
               data-telefono-cliente maxlength="10" inputmode="numeric">
      </div>
      <div class="col-md-3"><label class="form-label mb-1">Clienta *</label>
        <input name="c_nombre" id="c_nombre" class="form-control" list="listaClientas" autocomplete="off" required>
        <datalist id="listaClientas"></datalist>
      </div>
      <div class="col-md-3"><label class="form-label mb-1">Correo (opcional)</label><input name="c_email" id="c_email" type="email" class="form-control"></div>
      <div class="col-12"><small id="avisoCliente" class="d-none"></small></div>
      <div class="col-md-2"><label class="form-label mb-1">Servicio *</label>
        <select name="c_servicio" id="c_servicio" class="form-select" required>
          <option value="">Selecciona…</option>
          <?php foreach ($serviciosCat as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label mb-1">Manicurista</label>
        <select name="c_manicurista" id="c_manicurista" class="form-select">
          <option value="0">Cualquiera / sin asignar</option>
          <?php foreach ($manicuristasCat as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label mb-1">Fecha *</label><input name="c_fecha" id="c_fecha" type="date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= e(max($fecha, date('Y-m-d'))) ?>" required></div>
      <div class="col-md-2"><label class="form-label mb-1">Hora *</label>
        <select name="c_hora" id="c_hora" class="form-select" required>
          <option value="">Elige servicio y fecha…</option>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label mb-1">Estado</label>
        <select name="c_estado" class="form-select">
          <?php foreach ($estados as $es): ?><option value="<?= (int)$es['id'] ?>" <?= (int)$es['id'] === 2 ? 'selected' : '' ?>><?= e($es['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4"><label class="form-label mb-1">Notas (opcional)</label><input name="c_notas" class="form-control" placeholder="Preferencias, observaciones…"></div>
      <div class="col-md-2"><button class="btn btn-sns w-100">Crear cita ☾</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="d-flex gap-2 align-items-end mb-3 flex-wrap">
  <form class="d-flex gap-2 align-items-end" method="get">
    <div>
      <label class="form-label mb-1" for="fecha">Fecha</label>
      <input type="date" id="fecha" name="fecha" class="form-control" value="<?= e($fecha) ?>">
    </div>
    <button class="btn btn-sns">Ver</button>
  </form>
  <?php if ($todas): ?>
    <a href="index.php" class="btn btn-sns-outline">📅 Ver por fecha</a>
    <span class="badge text-bg-info align-self-center">Mostrando <strong>todas</strong> las citas</span>
  <?php else: ?>
    <a href="?todas=1" class="btn btn-sns-outline">Ver todas 🗓️</a>
  <?php endif; ?>
</div>

<div class="table-responsive">
<table class="table align-middle">
  <thead class="table-light">
    <tr><th>Hora</th><th>Clienta</th><th>Servicio</th><th>Manicurista</th><th>Estado</th><th>Pago</th><?php if ($u['rol'] !== 'manicurista'): ?><th>Reprogramar / actualizar</th><?php endif; ?></tr>
  </thead>
  <tbody>
  <?php if (!$citas): ?>
    <tr><td colspan="7" class="text-center text-muted py-4"><?= $todas ? 'No hay citas registradas 🌙' : 'No hay citas para esta fecha 🌙' ?></td></tr>
  <?php endif; ?>
  <?php $cobroVisto = []; ?>
  <?php foreach ($citas as $c): $hist = $auditPorCita[$c['id']] ?? [];
        $g = $c['grupo_id']; $enGrupo = $g && ($grupoCount[$g] ?? 0) > 1;
        $gColor = $enGrupo ? $grupoColor[$g] : null;
        $reservaKey = $c['grupo_id'] ?: ('cita:' . $c['id']);
        $cobro = $cobros[$reservaKey] ?? null;
        $primeraDeReserva = !isset($cobroVisto[$reservaKey]);
        $cobroVisto[$reservaKey] = true; ?>
    <tr<?= $enGrupo ? ' title="Reserva ' . e($g) . '"' : '' ?>>
      <td class="fw-bold" style="<?= $enGrupo ? "border-left:5px solid {$gColor}" : '' ?>"><?php if ($todas): ?><small class="text-muted d-block"><?= e(date('d/m/Y', strtotime($c['fecha']))) ?></small><?php endif; ?><?= e(substr($c['hora'], 0, 5)) ?></td>
      <td>
        <?= e($c['cliente']) ?>
        <?= $c['es_nueva'] ? '<span class="badge text-bg-warning">nueva</span>' : '' ?>
        <?= $c['cliente_validado'] ? '<span class="badge text-bg-success">validada</span>' : '<span class="badge text-bg-secondary" title="Llama a la clienta para validarla">sin validar</span>' ?>
        <br><small class="text-muted"><?= e($c['telefono']) ?></small>
        <br><small class="text-muted">Registró: <?= $c['registrado_por'] ? e($c['registrado_por']) : '<em>Clienta (web)</em>' ?></small>
      </td>
      <td>
        <?= e($c['servicio']) ?>
        <?php if ($enGrupo): ?>
          <br><span class="badge" style="background:<?= e($gColor) ?>;color:#fff" title="Reserva <?= e($g) ?>">🔗 Reserva · <?= (int)$grupoPos[$c['id']] ?> de <?= (int)$grupoCount[$g] ?></span>
        <?php endif; ?>
      </td>
      <td><?= e($c['manicurista'] ?? '—') ?></td>
      <td>
        <span class="badge text-bg-<?= e($c['color']) ?>"><?= e($c['estado']) ?></span>
        <?php if ($hist): ?>
        <details class="mt-1">
          <summary class="small text-muted" style="cursor:pointer">Historial (<?= count($hist) ?>)</summary>
          <ul class="list-unstyled small mt-1 mb-0">
            <?php foreach ($hist as $a): ?>
              <li class="border-start ps-2 mb-1" style="border-color:var(--sns-rosa-claro)!important">
                <?php
                  $quien = $a['quien'] ? e($a['quien']) : 'Clienta (web)';
                  $cuando = e(date('d/m/Y H:i', strtotime($a['creado_en'])));
                  if ($a['accion'] === 'reprogramada'):
                ?>
                  <strong>Reprogramada</strong> por <?= $quien ?>:
                  <?= e(date('d/m H:i', strtotime($a['fecha_anterior'] . ' ' . $a['hora_anterior']))) ?>
                  → <?= e(date('d/m H:i', strtotime($a['fecha_nueva'] . ' ' . $a['hora_nueva']))) ?>
                <?php elseif ($a['accion'] === 'creada'): ?>
                  <strong>Creada</strong> por <?= $quien ?>
                <?php else: ?>
                  <?= e($a['detalle'] ?? 'Actualización') ?> por <?= $quien ?>
                <?php endif; ?>
                <span class="text-muted">· <?= $cuando ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        </details>
        <?php endif; ?>
      </td>
      <td style="min-width:200px">
        <?php if ($c['codigo_pago']): ?><code><?= e($c['codigo_pago']) ?></code><br><?php endif; ?>
        <?php if ($c['comprobante']): ?><small class="text-muted d-block">Comprobante: <?= e($c['comprobante']) ?></small><?php endif; ?>
        <?php if ($cobro && $primeraDeReserva): ?>
          <div class="mt-1">
            <span class="badge text-bg-success"><?= precio($cobro['monto']) ?></span>
            <span class="badge text-bg-light border"><?= e($cobro['forma_pago']) ?></span>
          </div>
          <?php if (!empty($cobro['descuento']) && (int)$cobro['descuento'] > 0): ?><small class="text-success d-block">🎫 cupón aplicado −<?= precio($cobro['descuento']) ?></small><?php endif; ?>
          <?php if ($cobro['servicios_realizados']): ?><small class="text-muted d-block"><?= e($cobro['servicios_realizados']) ?></small><?php endif; ?>
          <small class="text-muted d-block"><?= e(date('d/m/Y', strtotime($cobro['fecha_pago']))) ?><?php if ($cobro['manicurista_nombre']): ?> · <?= e($cobro['manicurista_nombre']) ?><?php endif; ?></small>
          <?php if ($u['rol'] !== 'manicurista'): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar este cobro?')">
            <?= csrfField() ?><input type="hidden" name="eliminar_cobro" value="<?= (int)$cobro['id'] ?>">
            <button class="btn btn-link btn-sm text-danger p-0 mt-1">quitar cobro</button>
          </form>
          <?php endif; ?>
        <?php elseif ($cobro): ?>
          <small class="text-muted d-block">✓ cobrado en la reserva</small>
        <?php elseif ($u['rol'] !== 'manicurista' && $primeraDeReserva): ?>
          <form method="post" class="mt-1 d-flex flex-column gap-1" style="min-width:180px">
            <?= csrfField() ?>
            <input type="hidden" name="registrar_cobro" value="1">
            <input type="hidden" name="cobro_cita_id" value="<?= (int)$c['id'] ?>">
            <input type="number" name="cobro_monto" min="0" step="1" class="form-control form-control-sm" placeholder="Total $" required>
            <select name="cobro_forma" class="form-select form-select-sm" required>
              <option value="">Forma de pago…</option>
              <?php foreach (formasPago() as $fp): ?><option><?= e($fp) ?></option><?php endforeach; ?>
            </select>
            <input type="text" name="cobro_servicios" class="form-control form-control-sm" placeholder="Servicios (+ extras)">
            <?php $cupCli = $cuponesActivos[$c['telefono']] ?? null; ?>
            <?php if ($cupCli): ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="cobro_cupon" value="<?= (int)$cupCli['id'] ?>" id="cup<?= (int)$c['id'] ?>">
              <label class="form-check-label small text-rosa" for="cup<?= (int)$c['id'] ?>">🎫 Cupón −<?= (int)$cupCli['descuento_pct'] ?>% (vence <?= e(date('d/m', strtotime($cupCli['vence_el']))) ?>)</label>
            </div>
            <?php endif; ?>
            <button class="btn btn-sns btn-sm">💰 Cobrar</button>
          </form>
        <?php elseif (!$primeraDeReserva): ?>
          <small class="text-muted">se cobra en la reserva ↑</small>
        <?php endif; ?>
      </td>
      <?php if ($u['rol'] !== 'manicurista'): ?>
      <td>
        <form method="post" class="d-flex gap-1 flex-wrap align-items-center">
          <?= csrfField() ?>
          <input type="hidden" name="cita_id" value="<?= (int)$c['id'] ?>">
          <input type="date" name="fecha_cita" class="form-control form-control-sm" style="width:140px" value="<?= e($c['fecha']) ?>" title="Fecha (reprogramar)">
          <input type="time" name="hora_cita" class="form-control form-control-sm" style="width:105px" value="<?= e(substr($c['hora'], 0, 5)) ?>" title="Hora (reprogramar)">
          <select name="estado_id" class="form-select form-select-sm" style="width:auto">
            <?php foreach ($estados as $es): ?>
              <option value="<?= (int)$es['id'] ?>" <?= (int)$es['id'] === (int)$c['estado_id'] ? 'selected' : '' ?>><?= e($es['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="comprobante" class="form-control form-control-sm" placeholder="Nº comprobante" style="width:120px" value="">
          <button class="btn btn-sns btn-sm">Guardar ✓</button>
        </form>
      </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($esStaff): ?>
<script>
(function () {
  const serv = document.getElementById('c_servicio');
  const mani = document.getElementById('c_manicurista');
  const fecha = document.getElementById('c_fecha');
  const hora = document.getElementById('c_hora');
  if (!serv || !hora) return;
  async function cargar() {
    if (!serv.value || !fecha.value) { hora.innerHTML = '<option value="">Elige servicio y fecha…</option>'; return; }
    hora.innerHTML = '<option value="">Buscando…</option>';
    const q = new URLSearchParams({ fecha: fecha.value, servicio: serv.value, manicurista: mani.value || 0 });
    try {
      const horas = await (await fetch('../api/horas.php?' + q.toString())).json();
      hora.innerHTML = horas.length
        ? '<option value="">Elige hora…</option>' + horas.map(h => `<option value="${h}">${h}</option>`).join('')
        : '<option value="">Sin horas disponibles ese día</option>';
    } catch (e) { hora.innerHTML = '<option value="">No se pudo cargar</option>'; }
  }
  [serv, mani, fecha].forEach(el => el && el.addEventListener('change', cargar));
})();

// Precarga de la clienta por teléfono (datos reales: el endpoint exige sesión de staff).
(function () {
  const tel    = document.getElementById('c_telefono');
  const nombre = document.getElementById('c_nombre');
  const email  = document.getElementById('c_email');
  const aviso  = document.getElementById('avisoCliente');
  const lista  = document.getElementById('listaClientas');
  if (!tel || !nombre) return;

  let idActual = null;   // clienta reconocida por el teléfono actual

  function limpiarAviso() {
    aviso.className = 'd-none';
    aviso.textContent = '';
    idActual = null;
  }

  async function buscarPorTelefono() {
    const v = tel.value.trim();
    if (v.length < 3) { limpiarAviso(); return; }
    try {
      const r = await (await fetch('buscar_cliente.php?telefono=' + encodeURIComponent(v))).json();
      if (r.existe) {
        const c = r.cliente;
        nombre.value = c.nombre;
        if (c.email) email.value = c.email;
        idActual = c.id;
        aviso.className = 'text-success d-block';
        aviso.innerHTML = '✔ ' + c.nombre + ' · ' + c.citas + ' cita(s)'
                        + (c.validado ? ' · validada' : ' · sin validar');
      } else {
        // Teléfono nuevo: si el nombre venía de una clienta reconocida, se limpia.
        if (idActual !== null) { nombre.value = ''; email.value = ''; }
        limpiarAviso();
        const parciales = r.parciales || [];
        if (parciales.length) {
          // Número a medias: se ofrecen las coincidencias para elegir.
          aviso.className = 'd-block';
          aviso.innerHTML = '<span class="text-muted">¿Buscas a…</span> ' + parciales.map(c =>
            `<a href="#" class="badge text-bg-light border text-decoration-none me-1 sug"
                data-tel="${c.telefono}">${c.nombre} · ${c.telefono}</a>`).join('');
          aviso.querySelectorAll('.sug').forEach(a => a.addEventListener('click', ev => {
            ev.preventDefault();
            tel.value = a.dataset.tel;
            buscarPorTelefono();
          }));
        } else {
          aviso.className = 'text-muted d-block';
          aviso.textContent = 'Clienta nueva — se creará al guardar la cita.';
        }
      }
    } catch (e) { limpiarAviso(); }
  }

  // Sugerencias al escribir el nombre (por si no recuerdan el teléfono).
  async function sugerir() {
    const v = nombre.value.trim();
    if (v.length < 2 || idActual !== null) return;
    try {
      const r = await (await fetch('buscar_cliente.php?q=' + encodeURIComponent(v))).json();
      lista.innerHTML = (r.sugerencias || [])
        .map(c => `<option value="${c.nombre.replace(/"/g, '&quot;')}">${c.telefono}</option>`).join('');
    } catch (e) { /* silencioso */ }
  }

  // Al elegir una sugerencia del datalist se completa el teléfono.
  async function completarDesdeNombre() {
    const opt = Array.from(lista.options).find(o => o.value === nombre.value.trim());
    if (opt && !tel.value.trim()) { tel.value = opt.textContent; buscarPorTelefono(); }
  }

  let t1 = null, t2 = null;
  tel.addEventListener('input', () => { clearTimeout(t1); t1 = setTimeout(buscarPorTelefono, 400); });
  tel.addEventListener('change', () => { clearTimeout(t1); buscarPorTelefono(); });
  nombre.addEventListener('input', () => { clearTimeout(t2); t2 = setTimeout(sugerir, 400); });
  nombre.addEventListener('change', completarDesdeNombre);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/bottom.php'; ?>
