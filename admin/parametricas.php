<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$u = requerirLogin(['admin']);
$activo = 'parametricas';
$tituloAdmin = 'Tablas paramétricas';
$pdo = db();
$mensaje = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['nuevo_servicio'])) {
        $nombre = trim($_POST['nombre'] ?? '');
        $precio = (float)($_POST['precio'] ?? 0);
        $duracion = (int)($_POST['duracion_min'] ?? 60);
        $orden = (int)($_POST['orden'] ?? 999);
        if ($nombre === '' || $precio <= 0) {
            $error = 'Nombre y precio válido son obligatorios.';
        } else {
            $st = $pdo->prepare('INSERT INTO servicios (nombre, descripcion, precio, duracion_min, orden, suma_fidelidad)
                                 VALUES (?,?,?,?,?,?)');
            $st->execute([$nombre, trim($_POST['descripcion'] ?? '') ?: null, $precio, $duracion ?: 60,
                          max(0, min(9999, $orden)), empty($_POST['suma_fidelidad']) ? 0 : 1]);
            $mensaje = 'Servicio agregado.';
        }
    } elseif (isset($_POST['toggle_servicio'])) {
        $pdo->prepare('UPDATE servicios SET activo = 1 - activo WHERE id = ?')->execute([(int)$_POST['toggle_servicio']]);
        $mensaje = 'Servicio actualizado.';
    } elseif (isset($_POST['sello_id'])) {
        $pdo->prepare('UPDATE servicios SET suma_fidelidad = 1 - suma_fidelidad WHERE id = ?')
            ->execute([(int)$_POST['sello_id']]);
        $mensaje = 'Sello de fidelidad actualizado. Aplica a los cobros que se registren de ahora en adelante.';
    } elseif (isset($_POST['orden_id'])) {
        $orden = (int)($_POST['orden'] ?? 999);
        if ($orden < 0 || $orden > 9999) {
            $error = 'El orden debe estar entre 0 y 9999.';
        } else {
            $pdo->prepare('UPDATE servicios SET orden = ? WHERE id = ?')
                ->execute([$orden, (int)$_POST['orden_id']]);
            $mensaje = 'Orden actualizado.';
        }
    } elseif (isset($_POST['guardar_config'])) {
        $st = $pdo->prepare('UPDATE configuracion SET valor = ? WHERE clave = ?');
        foreach ($_POST['config'] ?? [] as $clave => $valor) {
            $st->execute([trim((string)$valor), (string)$clave]);
        }
        $mensaje = 'Datos del local guardados.';
    }
}

$servicios = $pdo->query('SELECT * FROM servicios ORDER BY orden, precio, nombre')->fetchAll();
$configs = $pdo->query('SELECT * FROM configuracion ORDER BY clave')->fetchAll();
$etiquetas = [
    'direccion' => 'Dirección', 'telefono' => 'Teléfono', 'whatsapp' => 'WhatsApp (solo números con indicativo)',
    'instagram' => 'Instagram', 'horario' => 'Horario de atención (texto visible en el sitio)', 'cuenta_pago' => 'Cuenta para abonos',
    'atencion_lv_inicio' => 'Atención Lun–Vie: inicio (HH:MM)', 'atencion_lv_fin' => 'Atención Lun–Vie: fin (HH:MM)',
    'atencion_sab_inicio' => 'Atención Sábado: inicio (HH:MM)', 'atencion_sab_fin' => 'Atención Sábado: fin (HH:MM)',
    'fidelidad_visitas' => 'Fidelidad: servicios por cupón',
    'fidelidad_descuento' => 'Fidelidad: % de descuento del cupón',
    'fidelidad_max_cupones' => 'Fidelidad: máx. cupones por tarjeta',
    'fidelidad_vigencia_dias' => 'Fidelidad: vigencia del cupón (días)',
    'bono_quincenal' => 'Nómina: bono completo por quincena ($)',
    'bono_valor_dia' => 'Nómina: descuento del bono por día no asistido ($)',
    'marca_agua' => 'Galería: marca de agua en las fotos (1 = sí, 0 = no)',
    'marca_agua_opacidad' => 'Galería: opacidad de la marca (10–100; menos de 60 se pierde en fotos claras)',
    'marca_agua_tamano' => 'Galería: tamaño de la marca (5–40 % del lado menor de la foto)',
];
require __DIR__ . '/includes/top.php';
?>

<?php if ($mensaje): ?><div class="alert alert-success"><?= e($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card card-servicio p-3 mb-3">
      <h5 class="text-rosa">Agregar servicio</h5>
      <form method="post" class="row g-2 align-items-end">
        <?= csrfField() ?>
        <input type="hidden" name="nuevo_servicio" value="1">
        <div class="col-md-4"><label class="form-label mb-1">Nombre</label><input name="nombre" class="form-control" required></div>
        <div class="col-md-3"><label class="form-label mb-1">Descripción</label><input name="descripcion" class="form-control"></div>
        <div class="col-md-2"><label class="form-label mb-1">Precio</label><input name="precio" type="number" min="1" class="form-control" required></div>
        <div class="col-md-1"><label class="form-label mb-1">Min</label><input name="duracion_min" type="number" min="15" step="15" value="60" class="form-control"></div>
        <div class="col-md-1"><label class="form-label mb-1" title="Menor número aparece primero">Orden</label>
          <input name="orden" type="number" min="0" max="9999" value="999" class="form-control"></div>
        <div class="col-md-1"><button class="btn btn-sns w-100">＋</button></div>
        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="suma_fidelidad" value="1" id="sellaNuevo">
            <label class="form-check-label" for="sellaNuevo">
              🎫 Este servicio <b>sella la tarjeta</b> de fidelidad
            </label>
          </div>
        </div>
      </form>
      <small class="text-muted mt-2 d-block">
        El <b>orden</b> define cómo se listan en el sitio y al agendar: menor número, primero.
        Hoy: 10 manicure · 20 pedicure · 30 recubrimiento · 40 retiros · 50 hombres ·
        60 cejas y pestañas · 70 depilación · 80 cabello.
      </small>
      <small class="text-muted d-block mt-1">
        El <b>sello</b> solo lo suman los semipermanentes y superiores. Si una reserva
        incluye al menos un servicio que sella, cuenta <b>una</b> visita (no una por servicio).
      </small>
    </div>

    <div class="table-responsive">
    <table class="table align-middle">
      <thead class="table-light"><tr><th>Orden</th><th>Servicio</th><th>Precio</th><th>Duración</th><th title="Suma visita a la tarjeta de fidelidad">🎫 Sello</th><th>Estado</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($servicios as $s): ?>
        <tr class="<?= $s['activo'] ? '' : 'table-secondary' ?>">
          <td>
            <form method="post" class="d-flex gap-1">
              <?= csrfField() ?>
              <input type="hidden" name="orden_id" value="<?= (int)$s['id'] ?>">
              <input type="number" name="orden" class="form-control form-control-sm" style="width:70px"
                     min="0" max="9999" value="<?= (int)$s['orden'] ?>">
              <button class="btn btn-sns-outline btn-sm">✓</button>
            </form>
          </td>
          <td><?= e($s['nombre']) ?><br><small class="text-muted"><?= e($s['descripcion'] ?? '') ?></small></td>
          <td class="fw-bold text-rosa"><?= precio($s['precio']) ?></td>
          <td><?= (int)$s['duracion_min'] ?> min</td>
          <td>
            <form method="post">
              <?= csrfField() ?>
              <input type="hidden" name="sello_id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-sm <?= $s['suma_fidelidad'] ? 'btn-sns' : 'btn-sns-outline' ?>"
                      title="<?= $s['suma_fidelidad'] ? 'Sella la tarjeta · clic para quitarlo' : 'No sella · clic para activarlo' ?>">
                <?= $s['suma_fidelidad'] ? '🎫 Sella' : '— No' ?>
              </button>
            </form>
          </td>
          <td><?= $s['activo'] ? '<span class="badge text-bg-success">activo</span>' : '<span class="badge text-bg-secondary">inactivo</span>' ?></td>
          <td>
            <form method="post"><?= csrfField() ?><input type="hidden" name="toggle_servicio" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-sns-outline btn-sm"><?= $s['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card card-servicio p-3">
      <h5 class="text-rosa">Datos del local</h5>
      <form method="post" class="d-flex flex-column gap-2">
        <?= csrfField() ?>
        <input type="hidden" name="guardar_config" value="1">
        <?php foreach ($configs as $c): ?>
          <div>
            <label class="form-label mb-1"><?= e($etiquetas[$c['clave']] ?? $c['clave']) ?></label>
            <input name="config[<?= e($c['clave']) ?>]" class="form-control" value="<?= e($c['valor']) ?>">
          </div>
        <?php endforeach; ?>
        <button class="btn btn-sns mt-2">Guardar cambios</button>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/bottom.php'; ?>
