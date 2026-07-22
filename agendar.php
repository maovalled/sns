<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/funciones.php';
$titulo = 'Agenda tu cita de uñas online · Sailor Nails Spa';
$metaDescripcion = 'Reserva en minutos tu cita de manicure, pedicure, semipermanente o nail art en Sailor Nails Spa. Elige servicio, manicurista, fecha y hora ✨';

$servicios = db()->query('SELECT * FROM servicios WHERE activo=1 ORDER BY orden, precio, nombre')->fetchAll();
$manicuristas = db()->query("SELECT id, nombre FROM usuarios WHERE rol='manicurista' AND activo=1 ORDER BY nombre")->fetchAll();

$exito = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $modo     = ($_POST['modo'] ?? 'misma') === 'separado' ? 'separado' : 'misma';
    $servIds  = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['servicios'] ?? [])))));
    $fecha    = $_POST['fecha'] ?? '';
    $nombre    = trim($_POST['nombre'] ?? '');
    $telefono  = normalizarTelefono($_POST['telefono'] ?? '');   // se guarda en solo dígitos
    $email     = trim($_POST['email'] ?? '');
    $documento = trim($_POST['documento'] ?? '');

    // Validar servicios elegidos contra la BD y conservar el orden enviado
    $serviciosDb = [];
    if ($servIds) {
        $ph = implode(',', array_fill(0, count($servIds), '?'));
        $q = db()->prepare("SELECT id, nombre, duracion_min FROM servicios WHERE activo=1 AND id IN ($ph)");
        $q->execute($servIds);
        foreach ($q as $r) $serviciosDb[(int)$r['id']] = $r;
    }
    $serviciosSel = array_values(array_filter(array_map(fn($id) => $serviciosDb[$id] ?? null, $servIds)));

    if (!$serviciosSel || $nombre === '' || $telefono === '' || $fecha === '') {
        $error = 'Completa al menos un servicio, tu nombre, teléfono y la fecha.';
    } elseif ($errTel = errorTelefonoCliente($telefono)) {
        $error = $errTel;
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha < date('Y-m-d')) {
        $error = 'La fecha no es válida o está en el pasado.';
    } else {
        $pdo = db();
        try {
            // Reconocer clienta por teléfono (la validación final la hace el admin llamando).
            // Mismo reconocimiento que api/cliente.php: si el aviso en pantalla dijo
            // "te reconocimos", el servidor debe reconocerla igual y no duplicarla.
            $cliRow = clientePorTelefono($telefono);
            $esNueva = $cliRow ? 0 : 1;

            if ($cliRow) {
                if (!empty($cliRow['email'])) {
                    // Con correo registrado → confirmar el correo COMPLETO
                    if (mb_strtolower(trim($email)) !== mb_strtolower(trim((string)$cliRow['email']))) {
                        throw new RuntimeException('Para confirmar tu identidad, escribe tu correo completo tal como lo registraste.');
                    }
                } else {
                    // Sin correo → confirmar/ingresar el documento de identidad
                    if ($documento === '') {
                        throw new RuntimeException('Para confirmar tu identidad, ingresa tu documento de identidad.');
                    }
                    if (!empty($cliRow['documento']) && $documento !== trim((string)$cliRow['documento'])) {
                        throw new RuntimeException('El documento no coincide con el registrado, por favor confírmalo.');
                    }
                }
            } elseif (str_contains($nombre, '*')) {
                throw new RuntimeException('No pudimos reconocer tu teléfono; recarga la página e ingresa tus datos.');
            }

            $pdo->beginTransaction();
            if ($cliRow) {
                $clienteId = (int)$cliRow['id']; // usa el nombre ya guardado
                if (empty($cliRow['email']) && $email !== '') {
                    $pdo->prepare('UPDATE clientes SET email = ? WHERE id = ?')->execute([$email, $clienteId]);
                }
                if (empty($cliRow['documento']) && $documento !== '') {
                    $pdo->prepare('UPDATE clientes SET documento = ? WHERE id = ?')->execute([$documento, $clienteId]);
                }
            } else {
                $clienteId = obtenerOCrearCliente($nombre, $telefono, $email ?: null, 1);
                if ($documento !== '') {
                    $pdo->prepare('UPDATE clientes SET documento = ? WHERE id = ?')->execute([$documento, $clienteId]);
                }
            }

            $grupo  = generarGrupo();
            $codigo = $esNueva ? generarCodigoPago() : null;
            $estado = $esNueva ? 1 : 2; // 1=Esperando pago, 2=Confirmada
            $insCita = $pdo->prepare('INSERT INTO citas (cliente_id, servicio_id, manicurista_id, fecha, hora, estado_id, codigo_pago, grupo_id, creado_por)
                                      VALUES (?,?,?,?,?,?,?,?,NULL)');
            $items = [];

            if ($modo === 'misma') {
                $maniPref = (int)($_POST['manicurista_id'] ?? 0) ?: null;
                $hora = $_POST['hora'] ?? '';
                $total = array_sum(array_map(fn($s) => (int)$s['duracion_min'], $serviciosSel));
                $disp = horasDisponiblesDuracion($fecha, $total, $maniPref);
                if (!preg_match('/^\d{2}:\d{2}$/', $hora) || !isset($disp[$hora])) {
                    throw new RuntimeException('Esa hora ya no está disponible para todos los servicios seguidos, elige otra.');
                }
                $mani = $disp[$hora];
                $t = hm2min($hora);
                foreach ($serviciosSel as $s) {
                    $h = min2hm($t);
                    $insCita->execute([$clienteId, (int)$s['id'], $mani, $fecha, $h, $estado, $codigo, $grupo]);
                    auditarCita((int)$pdo->lastInsertId(), null, 'creada', ['fecha_nueva' => $fecha, 'hora_nueva' => $h, 'detalle' => 'Reserva web ' . $grupo]);
                    $items[] = ['servicio' => $s['nombre'], 'hora' => $h, 'manicurista' => $mani, 'dur' => (int)$s['duracion_min']];
                    $t += (int)$s['duracion_min'];
                }
            } else { // separado: cada servicio su hora y manicurista, citas independientes
                $reservado = [];
                foreach ($serviciosSel as $s) {
                    $sid  = (int)$s['id'];
                    $pref = (int)($_POST["manicurista_$sid"] ?? 0) ?: null;
                    $h    = $_POST["hora_$sid"] ?? '';
                    if (!preg_match('/^\d{2}:\d{2}$/', $h)) {
                        throw new RuntimeException('Falta elegir la hora de "' . $s['nombre'] . '".');
                    }
                    $mani = asignarManicurista($fecha, $sid, $h, $pref, $reservado);
                    if ($mani === null) {
                        throw new RuntimeException('El horario de "' . $s['nombre'] . '" ya no está disponible, elige otro.');
                    }
                    $insCita->execute([$clienteId, $sid, $mani, $fecha, $h, $estado, $codigo, $grupo]);
                    auditarCita((int)$pdo->lastInsertId(), null, 'creada', ['fecha_nueva' => $fecha, 'hora_nueva' => $h, 'detalle' => 'Reserva web ' . $grupo]);
                    $items[] = ['servicio' => $s['nombre'], 'hora' => $h, 'manicurista' => $mani, 'dur' => (int)$s['duracion_min']];
                }
                usort($items, fn($a, $b) => strcmp($a['hora'], $b['hora']));
            }

            $pdo->commit();
            $mapMani = [];
            foreach ($manicuristas as $m) $mapMani[(int)$m['id']] = $m['nombre'];
            foreach ($items as &$it) $it['manicurista_nombre'] = $mapMani[$it['manicurista']] ?? 'Por asignar';
            unset($it);
            $exito = ['items' => $items, 'codigo' => $codigo, 'es_nueva' => $esNueva, 'fecha' => $fecha, 'modo' => $modo];
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $ex instanceof RuntimeException ? $ex->getMessage() : 'Ocurrió un error al guardar la reserva. Intenta de nuevo.';
        }
    }
}

$serviciosJs = array_map(fn($s) => ['id' => (int)$s['id'], 'nombre' => $s['nombre'], 'dur' => (int)$s['duracion_min']], $servicios);
$manisJs = array_map(fn($m) => ['id' => (int)$m['id'], 'nombre' => $m['nombre']], $manicuristas);

// Datos para deshabilitar días en el calendario:
//  - días de la semana en que SÍ se atiende (getDay JS: 0=Dom..6=Sab)
$diasAbiertosJs = array_values(array_unique(array_map(
    fn($r) => ((int)$r['dia_semana']) % 7,
    db()->query('SELECT DISTINCT dia_semana FROM disponibilidad')->fetchAll()
)));
//  - días especiales de hoy en adelante
$espFut = db()->prepare('SELECT fecha, abierto FROM dias_especiales WHERE fecha >= ?');
$espFut->execute([date('Y-m-d')]);
$cerradosJs = [];
$abiertosEspJs = [];
foreach ($espFut as $r) {
    if ((int)$r['abierto']) $abiertosEspJs[] = $r['fecha']; else $cerradosJs[] = $r['fecha'];
}

require __DIR__ . '/includes/header_publico.php';
?>

<div class="container py-5" style="max-width:820px">
  <p class="separador-magico">☾ ✦ ☾</p>
  <h1 class="text-center text-rosa fw-bold mb-4">Agenda tu cita</h1>

  <?php if ($exito): ?>
    <div class="card card-servicio p-4 text-center">
      <p class="estrellas fs-3 mb-2">✦ ☾ ✦</p>
      <h4 class="text-rosa">¡Tu reserva quedó registrada!</h4>
      <p class="mb-2"><?= e(date('d/m/Y', strtotime($exito['fecha']))) ?></p>
      <ul class="list-unstyled text-start mx-auto" style="max-width:460px">
        <?php foreach ($exito['items'] as $it): ?>
          <li class="d-flex justify-content-between border-bottom py-2">
            <span><strong><?= e($it['hora']) ?></strong> · <?= e($it['servicio']) ?> <small class="text-muted">(<?= (int)$it['dur'] ?> min)</small></span>
            <span class="text-muted">con <?= e($it['manicurista_nombre']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($exito['es_nueva']): ?>
        <div class="alert alert-warning mt-3 mb-0 text-start">
          <strong>Falta un paso ✨ (clienta nueva):</strong> para confirmar tu reserva realiza el abono a
          <strong><?= e(config('cuenta_pago')) ?></strong> y envía el comprobante por WhatsApp indicando tu código de pago:
          <div class="fs-4 fw-bold text-center my-2" style="letter-spacing:2px"><?= e($exito['codigo']) ?></div>
          <a class="btn btn-sns btn-sm" href="https://wa.me/<?= e(config('whatsapp')) ?>?text=Hola!%20Env%C3%ADo%20mi%20comprobante.%20C%C3%B3digo:%20<?= e($exito['codigo']) ?>" target="_blank" rel="noopener">Enviar comprobante 💬</a>
        </div>
      <?php else: ?>
        <p class="text-muted mb-0">Tu reserva está <strong>confirmada</strong>. ¡Te esperamos! 🌙</p>
      <?php endif; ?>
      <a href="index.php" class="btn btn-sns-outline mt-3 mx-auto">Volver al inicio</a>
    </div>
  <?php else: ?>

  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

  <form method="post" id="formCita" class="d-flex flex-column gap-4">

    <!-- Paso 1: servicios (varios) -->
    <section>
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="paso-indicador activo">1</span><h5 class="mb-0">Elige tus servicios</h5>
        <small class="text-muted">(puedes marcar varios)</small>
      </div>
      <div class="row g-2">
        <?php $preSel = (int)($_GET['servicio'] ?? 0); ?>
        <?php foreach ($servicios as $s): ?>
        <div class="col-6 col-md-4">
          <input type="checkbox" class="btn-check" name="servicios[]" id="serv<?= (int)$s['id'] ?>" value="<?= (int)$s['id'] ?>" <?= $preSel === (int)$s['id'] ? 'checked' : '' ?>>
          <label class="opcion-card d-block p-3 h-100" for="serv<?= (int)$s['id'] ?>">
            <strong class="d-block"><?= e($s['nombre']) ?></strong>
            <small class="text-muted d-block"><?= (int)$s['duracion_min'] ?> min</small>
          </label>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Paso 2: modo -->
    <section>
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="paso-indicador">2</span><h5 class="mb-0">¿Cómo te atendemos?</h5>
      </div>
      <div class="row g-2">
        <div class="col-md-6">
          <input type="radio" class="btn-check" name="modo" id="modoMisma" value="misma" checked>
          <label class="opcion-card d-block p-3 h-100" for="modoMisma">
            <strong class="d-block">👩‍🎨 La misma manicurista</strong>
            <small class="text-muted">Tus servicios uno tras otro con la misma persona.</small>
          </label>
        </div>
        <div class="col-md-6">
          <input type="radio" class="btn-check" name="modo" id="modoSeparado" value="separado">
          <label class="opcion-card d-block p-3 h-100" for="modoSeparado">
            <strong class="d-block">✂️ Por separado</strong>
            <small class="text-muted">Cada servicio a su hora y, si quieres, con distinta manicurista.</small>
          </label>
        </div>
      </div>
    </section>

    <!-- Paso 3: fecha -->
    <section>
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="paso-indicador">3</span><h5 class="mb-0">Fecha</h5>
      </div>
      <input type="text" class="form-control" id="fecha" name="fecha" placeholder="Elige una fecha 🌙" autocomplete="off" style="max-width:260px" required>
      <small class="text-muted d-block mt-1">Los días cerrados aparecen deshabilitados.</small>
    </section>

    <!-- Tiempo estimado -->
    <div class="alert alert-light border mb-0" id="avisoTiempo" style="display:none"></div>

    <!-- Paso 4a: misma manicurista -->
    <section id="zonaMisma">
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="paso-indicador">4</span><h5 class="mb-0">Manicurista y hora</h5>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
          <input type="radio" class="btn-check" name="manicurista_id" id="mmani0" value="0" checked>
          <label class="opcion-card d-block p-3 text-center" for="mmani0">✨<br><strong>Cualquiera</strong></label>
        </div>
        <?php foreach ($manicuristas as $m): ?>
        <div class="col-6 col-md-3">
          <input type="radio" class="btn-check" name="manicurista_id" id="mmani<?= (int)$m['id'] ?>" value="<?= (int)$m['id'] ?>">
          <label class="opcion-card d-block p-3 text-center" for="mmani<?= (int)$m['id'] ?>">👤<br><strong><?= e($m['nombre']) ?></strong></label>
        </div>
        <?php endforeach; ?>
      </div>
      <label class="form-label d-block">Horas disponibles</label>
      <div id="horasMisma" class="d-flex flex-wrap gap-2"><small class="text-muted">Elige servicios y fecha 🌙</small></div>
    </section>

    <!-- Paso 4b: separado -->
    <section id="zonaSeparado" style="display:none">
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="paso-indicador">4</span><h5 class="mb-0">Hora de cada servicio</h5>
      </div>
      <div id="filasServicios"></div>
      <small class="text-muted">Elige tus servicios y la fecha para ver las horas de cada uno.</small>
    </section>

    <!-- Paso 5: datos -->
    <section>
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="paso-indicador">5</span><h5 class="mb-0">Tus datos</h5>
      </div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" for="nombre">Nombre completo *</label>
          <input type="text" class="form-control" id="nombre" name="nombre" required>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="telefono">Teléfono / WhatsApp *</label>
          <input type="tel" class="form-control" id="telefono" name="telefono" required
                 data-telefono-cliente maxlength="10" inputmode="numeric">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="email" id="emailLabel">Correo (opcional)</label>
          <input type="email" class="form-control" id="email" name="email">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="documento" id="documentoLabel">Documento (opcional)</label>
          <input type="text" class="form-control" id="documento" name="documento" inputmode="numeric" autocomplete="off">
        </div>
      </div>
      <div class="alert alert-success mt-3 d-none" id="avisoConocida">
        ✨ ¡Hola de nuevo, <strong id="nombreConocida"></strong>! 🌙 Te reconocimos por tu teléfono.
        <span id="pistaCorreo" class="d-none">Para agendar, <strong>confirma tu correo completo</strong> (<span id="emailMascara" class="fw-bold"></span>).</span>
        <span id="pistaDoc" class="d-none"></span>
      </div>
      <div class="alert alert-info mt-3 d-none" id="avisoNueva">
        Pareces <strong>clienta nueva</strong> 💖 Tu reserva se confirma con un <strong>abono anticipado</strong>: al finalizar te daremos un
        <strong>código de pago</strong> y los datos de la cuenta.
      </div>
    </section>

    <div class="text-center">
      <button type="submit" class="btn btn-sns btn-lg px-5">Confirmar reserva ☾</button>
    </div>
  </form>

  <!-- Confirmación de datos de contacto antes de enviar -->
  <div class="modal fade" id="modalConfirmar" tabindex="-1" aria-labelledby="modalConfirmarTitulo" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content" style="border:2px solid var(--sns-rosa-claro);border-radius:1rem;overflow:hidden">
        <div class="modal-header" style="background:var(--sns-crema)">
          <h5 class="modal-title text-rosa fw-bold" id="modalConfirmarTitulo">Confirma tus datos ☾</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <p class="mb-3">Tu cita se <strong>validará</strong> al número de contacto o correo que registraste. Revisa que estén correctos 🌙</p>
          <ul class="list-unstyled d-flex flex-column gap-2 mb-3">
            <li>👤 <strong>Nombre:</strong> <span id="cfmNombre"></span></li>
            <li>📞 <strong>Teléfono:</strong> <span id="cfmTelefono"></span></li>
            <li id="cfmEmailLi">✉️ <strong>Correo:</strong> <span id="cfmEmail"></span></li>
            <li id="cfmDocLi" class="d-none">🪪 <strong>Documento:</strong> <span id="cfmDoc"></span></li>
          </ul>
          <p class="small text-muted mb-0">¿Deseas continuar o corregir los datos?</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sns-outline" data-bs-dismiss="modal">✏️ Corregir datos</button>
          <button type="button" class="btn btn-sns" id="btnContinuarReserva">Sí, continuar ✦</button>
        </div>
      </div>
    </div>
  </div>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
  <script>
  const SERVICIOS = <?= json_encode($serviciosJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const MANIS     = <?= json_encode($manisJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const DIAS_ABIERTOS = new Set(<?= json_encode($diasAbiertosJs) ?>);
  const CERRADO_ESP   = new Set(<?= json_encode($cerradosJs, JSON_HEX_TAG) ?>);
  const ABIERTO_ESP   = new Set(<?= json_encode($abiertosEspJs, JSON_HEX_TAG) ?>);
  const $ = s => document.querySelector(s);
  const fechaEl = $('#fecha');

  const fmtYmd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  if (window.flatpickr) {
    flatpickr(fechaEl, {
      locale: 'es', minDate: 'today', dateFormat: 'Y-m-d',
      altInput: true, altFormat: 'l, j F', disableMobile: true,
      disable: [ d => {
        const ymd = fmtYmd(d);
        if (ABIERTO_ESP.has(ymd)) return false;   // apertura especial → habilitado
        if (CERRADO_ESP.has(ymd)) return true;    // festivo/cierre → deshabilitado
        return !DIAS_ABIERTOS.has(d.getDay());    // día sin atención (p. ej. domingo) → deshabilitado
      } ],
      onChange: () => recalc()
    });
  }
  const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

  const serviciosSel = () => [...document.querySelectorAll('input[name="servicios[]"]:checked')].map(c => +c.value);
  const modo = () => document.querySelector('input[name="modo"]:checked').value;
  const infoServicios = () => SERVICIOS.filter(s => serviciosSel().includes(s.id));
  const durTotal = () => infoServicios().reduce((a, s) => a + s.dur, 0);

  async function fetchHoras(params) {
    const q = new URLSearchParams({ fecha: fechaEl.value, ...params });
    const r = await fetch('api/horas.php?' + q.toString());
    return r.json();
  }
  function radiosHoras(hours, name) {
    if (!hours.length) return '<small class="text-muted">No hay horas disponibles 😔 prueba otra fecha.</small>';
    return hours.map((h, i) =>
      `<input type="radio" class="btn-check" name="${name}" id="${name}_${i}" value="${h}">` +
      `<label class="btn btn-sns-outline hora-btn" for="${name}_${i}">${h}</label>`).join('');
  }

  async function recalcMisma() {
    const cont = $('#horasMisma');
    if (!serviciosSel().length || !fechaEl.value) { cont.innerHTML = '<small class="text-muted">Elige servicios y fecha 🌙</small>'; return; }
    cont.innerHTML = '<small class="text-muted">Buscando horas…</small>';
    const mani = document.querySelector('input[name="manicurista_id"]:checked').value;
    cont.innerHTML = radiosHoras(await fetchHoras({ dur: durTotal(), manicurista: mani }), 'hora');
  }

  async function recalcSeparado() {
    const wrap = $('#filasServicios');
    const ids = serviciosSel();
    if (!ids.length) { wrap.innerHTML = ''; return; }
    wrap.innerHTML = ids.map(id => {
      const s = SERVICIOS.find(x => x.id === id);
      const opts = ['<option value="0">✨ Cualquiera</option>'].concat(MANIS.map(m => `<option value="${m.id}">${esc(m.nombre)}</option>`)).join('');
      return `<div class="card card-servicio p-3 mb-2" data-sid="${id}">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
          <strong class="text-rosa">${esc(s.nombre)}</strong><small class="text-muted">${s.dur} min</small>
          <select class="form-select form-select-sm ms-auto" style="width:auto" name="manicurista_${id}" data-role="mani">${opts}</select>
        </div>
        <div class="d-flex flex-wrap gap-2" data-role="horas"><small class="text-muted">Elige la fecha…</small></div>
      </div>`;
    }).join('');
    for (const id of ids) await cargarHorasFila(id);
  }

  async function cargarHorasFila(id) {
    const row = document.querySelector(`#filasServicios [data-sid="${id}"]`);
    if (!row || !fechaEl.value) return;
    const cont = row.querySelector('[data-role="horas"]');
    const mani = row.querySelector('[data-role="mani"]').value;
    cont.innerHTML = '<small class="text-muted">Buscando…</small>';
    cont.innerHTML = radiosHoras(await fetchHoras({ servicio: id, manicurista: mani }), 'hora_' + id);
  }

  function actualizarTiempo() {
    const aviso = $('#avisoTiempo');
    const info = infoServicios();
    if (!info.length) { aviso.style.display = 'none'; return; }
    aviso.style.display = 'block';
    const total = durTotal();
    if (modo() === 'misma') {
      aviso.innerHTML = `⏱️ <strong>Tiempo total: ${total} min</strong> (~${(total / 60).toFixed(1)} h) — todos tus servicios seguidos con la misma manicurista.`;
    } else {
      const mayor = Math.max(...info.map(s => s.dur));
      aviso.innerHTML = `⏱️ Cada servicio va por separado: ${info.map(s => `${esc(s.nombre)} <strong>${s.dur} min</strong>`).join(' · ')}. ` +
        `Si los tomas en paralelo con distintas manicuristas, tu tiempo en salón puede ser tan corto como <strong>${mayor} min</strong>.`;
    }
  }

  function recalc() {
    $('#zonaMisma').style.display = modo() === 'misma' ? '' : 'none';
    $('#zonaSeparado').style.display = modo() === 'separado' ? '' : 'none';
    actualizarTiempo();
    if (modo() === 'misma') { $('#filasServicios').innerHTML = ''; recalcMisma(); }
    else { $('#horasMisma').innerHTML = '<small class="text-muted">—</small>'; recalcSeparado(); }
  }

  document.querySelectorAll('input[name="servicios[]"]').forEach(c => c.addEventListener('change', recalc));
  document.querySelectorAll('input[name="modo"]').forEach(r => r.addEventListener('change', recalc));
  fechaEl.addEventListener('change', recalc);
  document.querySelectorAll('input[name="manicurista_id"]').forEach(r => r.addEventListener('change', () => { if (modo() === 'misma') recalcMisma(); }));
  $('#filasServicios').addEventListener('change', e => {
    if (e.target.dataset.role === 'mani') cargarHorasFila(+e.target.closest('[data-sid]').dataset.sid);
  });
  // Reconoce a la clienta por teléfono mostrando datos ENMASCARADOS; para agendar debe
  // confirmar el correo completo (el servidor lo valida). El nombre queda de solo lectura.
  const telEl = document.getElementById('telefono');
  const nombreEl = document.getElementById('nombre');
  const emailEl = document.getElementById('email');
  const emailLabel = document.getElementById('emailLabel');
  const docEl = document.getElementById('documento');
  const docLabel = document.getElementById('documentoLabel');
  function resetConfirm() {
    emailLabel.textContent = 'Correo (opcional)'; emailEl.required = false;
    docLabel.textContent = 'Documento (opcional)'; docEl.required = false;
    $('#pistaCorreo').classList.add('d-none');
    $('#pistaDoc').classList.add('d-none');
  }
  function modoNueva() {
    nombreEl.readOnly = false;
    if (nombreEl.value.includes('*')) nombreEl.value = '';
    resetConfirm();
  }
  async function lookupCliente() {
    const tel = telEl.value.trim();
    const conocida = $('#avisoConocida'), nueva = $('#avisoNueva');
    if (tel.length < 7) { conocida.classList.add('d-none'); nueva.classList.add('d-none'); modoNueva(); return; }
    try {
      const c = await (await fetch('api/cliente.php?telefono=' + encodeURIComponent(tel))).json();
      if (c.existe) {
        nombreEl.value = c.nombreMascara || '';   // enmascarado y de solo lectura
        nombreEl.readOnly = true;
        $('#nombreConocida').textContent = c.nombreMascara || '';
        resetConfirm();
        if (c.tieneEmail) {
          // Con correo → confirmar correo completo
          $('#emailMascara').textContent = c.emailMascara || '';
          $('#pistaCorreo').classList.remove('d-none');
          emailLabel.textContent = 'Confirma tu correo completo *';
          emailEl.value = ''; emailEl.required = true;
        } else {
          // Sin correo → confirmar / ingresar documento
          docEl.value = ''; docEl.required = true;
          docLabel.textContent = 'Confirma tu documento *';
          $('#pistaDoc').innerHTML = c.tieneDocumento
            ? 'Para agendar, <strong>confirma tu documento de identidad</strong> (<span class="fw-bold">' + c.documentoMascara + '</span>).'
            : 'Para agendar, <strong>ingresa tu documento de identidad</strong> para validar tu reserva.';
          $('#pistaDoc').classList.remove('d-none');
        }
        conocida.classList.remove('d-none'); nueva.classList.add('d-none');
      } else {
        conocida.classList.add('d-none'); nueva.classList.remove('d-none');
        modoNueva();
      }
    } catch (e) { /* silencioso */ }
  }
  // 'change' solo dispara al salir del campo: la clienta escribía el teléfono y no pasaba nada.
  // Se consulta mientras escribe, con un respiro de 500 ms para no golpear el endpoint
  // (api/cliente.php limita a 20 consultas por minuto por IP).
  let lookupTimer = null;
  telEl.addEventListener('input', () => {
    clearTimeout(lookupTimer);
    lookupTimer = setTimeout(lookupCliente, 500);
  });
  telEl.addEventListener('change', () => { clearTimeout(lookupTimer); lookupCliente(); });

  const formCita = $('#formCita');
  formCita.addEventListener('submit', e => {
    const ids = serviciosSel();
    if (!ids.length) { e.preventDefault(); alert('Elige al menos un servicio.'); return; }
    if (!fechaEl.value) { e.preventDefault(); alert('Elige una fecha.'); return; }
    if (modo() === 'misma') {
      if (!document.querySelector('input[name="hora"]:checked')) { e.preventDefault(); alert('Elige una hora.'); return; }
    } else {
      for (const id of ids) if (!document.querySelector(`input[name="hora_${id}"]:checked`)) { e.preventDefault(); alert('Falta elegir la hora de un servicio.'); return; }
    }
    // Todo válido → mostrar confirmación de datos de contacto (si hay Bootstrap)
    if (!window.bootstrap) return; // sin Bootstrap: enviar directo
    e.preventDefault();
    const emailVal = emailEl.value.trim(), docVal = docEl.value.trim();
    $('#cfmNombre').textContent   = nombreEl.value.trim();
    $('#cfmTelefono').textContent = telEl.value.trim();
    $('#cfmEmail').textContent    = emailVal;
    $('#cfmEmailLi').classList.toggle('d-none', emailVal === '');
    $('#cfmDoc').textContent      = docVal;
    $('#cfmDocLi').classList.toggle('d-none', docVal === '');
    bootstrap.Modal.getOrCreateInstance($('#modalConfirmar')).show();
  });
  $('#btnContinuarReserva').addEventListener('click', () => {
    bootstrap.Modal.getOrCreateInstance($('#modalConfirmar')).hide();
    formCita.submit(); // envía sin re-disparar el listener
  });

  recalc();
  </script>

  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer_publico.php'; ?>
