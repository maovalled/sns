<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/fidelidad.php';
$titulo = 'Mi tarjeta de fidelidad · Sailor Nails Spa';
$metaDescripcion = 'Consulta tu tarjeta de fidelidad Sailor Nails Spa: suma visitas y gana cupones de descuento en tus uñas 🌙';

$tarjeta = null;
$datos = null;
$buscado = false;
$telefono = trim($_GET['telefono'] ?? '');
if ($telefono !== '') {
    $buscado = true;
    $tarjeta = tarjetaAbierta($telefono); // solo consulta, no crea
    if ($tarjeta) $datos = datosTarjeta($tarjeta);
}
$p = fidelidadParams();
$totalCasillas = $p['visitas'] * $p['max'];

require __DIR__ . '/includes/header_publico.php';
?>

<div class="container py-5" style="max-width:560px">
  <p class="separador-magico">☾ ✦ ☾</p>
  <h1 class="text-center text-rosa fw-bold mb-2">Mi tarjeta lunar</h1>
  <p class="text-center text-muted">Cada <?= $p['visitas'] ?> servicios ganas un cupón de <strong>-<?= $p['descuento'] ?>%</strong> ✨</p>

  <form method="get" class="d-flex gap-2 mb-4">
    <input type="tel" name="telefono" class="form-control" placeholder="Tu teléfono (10 dígitos)"
           value="<?= e($telefono) ?>" required data-telefono-cliente maxlength="10" inputmode="numeric">
    <button class="btn btn-sns text-nowrap">Ver mi tarjeta</button>
  </form>

  <?php if ($buscado && !$tarjeta): ?>
    <div class="alert alert-info text-center">
      Aún no tienes tarjeta con ese número 🌙 Se crea en tu primera visita al spa — ¡pregunta en recepción!
    </div>
  <?php elseif ($tarjeta): ?>
    <div class="card card-servicio p-4" style="background:var(--sns-crema)">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong class="fs-5"><?= e($tarjeta['nombre']) ?></strong>
        <span class="badge text-bg-light border">Tarjeta #<?= e($tarjeta['codigo']) ?></span>
      </div>

      <?php $v = min($datos['visitas'], $totalCasillas); ?>
      <div class="d-grid gap-2 my-3" style="grid-template-columns:repeat(<?= $p['visitas'] ?>,1fr)">
        <?php for ($i = 1; $i <= $totalCasillas; $i++):
          $esPremio = $i % $p['visitas'] === 0;
          $sellada = $i <= $v; ?>
          <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold"
               style="aspect-ratio:1;font-size:1.2rem;<?= $sellada
                 ? 'background:var(--sns-rosa-claro);border:2px solid var(--sns-rosa-oscuro);color:var(--sns-rosa-oscuro)'
                 : 'background:#fff;border:2px dashed #d9b3c6;color:#d9b3c6' ?>">
            <?= $sellada ? '☾' : ($esPremio ? '🎁' : $i) ?>
          </div>
        <?php endfor; ?>
      </div>

      <?php $faltan = $p['visitas'] - ($datos['visitas'] % $p['visitas']);
            $cicloCompleto = $datos['visitas'] > 0 && $datos['visitas'] % $p['visitas'] === 0; ?>
      <p class="text-center mb-0">
        <?php if ($datos['visitas'] >= $totalCasillas): ?>
          ¡Tarjeta completa! ✨ Usa tus cupones antes de que venzan.
        <?php elseif ($cicloCompleto): ?>
          ¡Completaste <?= $p['visitas'] ?> servicios! Revisa tu cupón abajo 🎉
        <?php else: ?>
          Llevas <strong><?= $datos['visitas'] ?></strong> · te faltan <strong><?= $faltan ?></strong> para tu próximo cupón ✨
        <?php endif; ?>
      </p>
    </div>

    <h2 class="h5 text-rosa fw-bold mt-4">Tus cupones</h2>
    <?php if (!$datos['cupones']): ?>
      <p class="text-muted">Aún no has ganado cupones — ¡sigue sumando lunas! 🌙</p>
    <?php else: ?>
    <div class="row g-2">
      <?php foreach ($datos['cupones'] as $c):
        $vigente = !$c['usado_en'] && $c['vence_el'] >= date('Y-m-d'); ?>
      <div class="col-6">
        <div class="p-3 rounded-3 text-center" style="border:2px dashed <?= $vigente ? 'var(--sns-rosa-oscuro)' : '#ccc' ?>;<?= $vigente ? '' : 'color:#aaa' ?>">
          <div class="fs-4 fw-bold <?= $vigente ? 'text-rosa' : '' ?>">🎫 -<?= (int)$c['descuento_pct'] ?>%</div>
          <?php if ($c['usado_en']): ?>
            <small>Usado el <?= e(date('d/m/Y', strtotime($c['usado_en']))) ?></small>
          <?php elseif (!$vigente): ?>
            <small>Venció el <?= e(date('d/m/Y', strtotime($c['vence_el']))) ?></small>
          <?php else: ?>
            <small class="text-success fw-semibold">Disponible</small><br>
            <small>Vence el <?= e(date('d/m/Y', strtotime($c['vence_el']))) ?></small>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="alert alert-light border mt-3 small mb-0">
      🎀 Los cupones se aplican en recepción al pagar tu servicio. <strong>No son acumulables</strong> (uno por visita).
      Al usar tus <?= $p['max'] ?> cupones, tu tarjeta se renueva automáticamente ♻
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer_publico.php'; ?>
