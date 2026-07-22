<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/funciones.php';

// El orden lo manda la columna `orden` (Admin → Paramétricas): así encabezan
// manicure y pedicure. Dentro de cada grupo, del más económico al más completo.
$servicios = db()->query('SELECT * FROM servicios WHERE activo=1 ORDER BY orden, precio, nombre')->fetchAll();
$fotos = db()->query('SELECT * FROM galeria ORDER BY subido_en DESC LIMIT 9')->fetchAll();
$promos = promocionesVisibles();

$ciudad = config('ciudad', 'Bogotá');
$titulo = 'Sailor Nails Spa · Manicure, Pedicure y Nail Art en ' . $ciudad;
$metaDescripcion = 'Spa de uñas en ' . $ciudad . ': manicure, pedicure, semipermanente, uñas acrílicas y nail art temático estilo Sailor Moon. ¡Agenda tu cita online! ✨';

require __DIR__ . '/includes/header_publico.php';
?>

<!-- Hero -->
<header class="hero-sns text-center py-5">
  <div class="container py-4">
    <img src="assets/img/logo.jpg" alt="Logo Sailor Nails Spa" class="logo-hero mb-3">
    <p class="estrellas mb-1">✦ ✧ ✦</p>
    <h1 class="display-5 fw-bold text-rosa">Belleza en nombre de la luna</h1>
    <p class="lead col-lg-6 mx-auto">Manicure, pedicure y nail art temático en un spa mágico pensado para ti.</p>
    <div class="d-flex justify-content-center gap-3 flex-wrap mt-3">
      <a href="agendar.php" class="btn btn-sns btn-lg">Agendar cita ☾</a>
      <a href="#galeria" class="btn btn-sns-outline btn-lg">Ver trabajos</a>
    </div>
  </div>
</header>

<!-- Promociones (visibles el día anterior y el día de la promo) -->
<?php if ($promos): ?>
<section class="promo-seccion bg-crema" id="promociones" aria-label="Promociones del spa">
  <div class="container">
    <p class="separador-magico">✦ ☾ ✦</p>
    <h2 class="text-center text-rosa fw-bold mb-4">Promo lunar del momento ✨</h2>
    <div class="row g-3 justify-content-center">
      <?php foreach ($promos as $p): $esHoy = $p['fecha'] === date('Y-m-d'); ?>
      <div class="col-12 col-lg-8">
        <article class="promo-card">
          <span class="promo-badge"><?= $esHoy ? '🌟 ¡HOY!' : '🌙 ¡MAÑANA!' ?></span>
          <div><span class="luna-grande">🌙</span></div>
          <h3><?= e($p['nombre']) ?></h3>
          <?php if ($p['descripcion']): ?><p class="promo-desc"><?= e($p['descripcion']) ?></p><?php endif; ?>
          <p class="mt-2 mb-3"><small>✨ <?= e(ucfirst(fechaLarga($p['fecha']))) ?> ✨</small></p>
          <a href="agendar.php" class="btn btn-lg btn-promo px-4">Agendar ahora ☾</a>
        </article>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Servicios -->
<section class="py-5" id="servicios">
  <div class="container">
    <p class="separador-magico">✦ ✧ ✦</p>
    <h2 class="text-center text-rosa fw-bold mb-4">Nuestros servicios</h2>
    <div class="row g-4">
      <?php foreach ($servicios as $s): ?>
      <div class="col-12 col-sm-6 col-lg-4">
        <div class="card card-servicio h-100">
          <div class="card-body d-flex flex-column">
            <h5 class="card-title text-rosa"><?= e($s['nombre']) ?></h5>
            <p class="card-text flex-grow-1"><?= e($s['descripcion']) ?></p>
            <div class="d-flex justify-content-end align-items-center">
              <small class="text-muted"><?= (int)$s['duracion_min'] ?> min</small>
            </div>
            <a href="agendar.php?servicio=<?= (int)$s['id'] ?>" class="btn btn-sns-outline btn-sm mt-3">Agendar este servicio</a>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Galería -->
<section class="py-5 bg-crema" id="galeria">
  <div class="container">
    <p class="separador-magico">☾ ✦ ☾</p>
    <h2 class="text-center text-rosa fw-bold mb-4">Trabajos realizados</h2>
    <?php if (!$fotos): ?>
      <p class="text-center text-muted">Pronto subiremos fotos de nuestros trabajos ✨</p>
    <?php else: ?>
    <div class="row g-3">
      <?php foreach ($fotos as $f): ?>
      <div class="col-6 col-md-4">
        <img src="uploads/galeria/<?= e($f['archivo']) ?>" alt="<?= e($f['titulo'] ?: 'Trabajo realizado') ?>" class="galeria-img" loading="lazy">
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- Contacto / datos del local -->
<section class="py-5" id="contacto">
  <div class="container">
    <p class="separador-magico">✦ ✧ ✦</p>
    <h2 class="text-center text-rosa fw-bold mb-4">Encuéntranos</h2>
    <div class="row g-4 align-items-center">
      <div class="col-md-6">
        <ul class="list-unstyled fs-5 d-flex flex-column gap-2">
          <li>📍 <?= e(config('direccion')) ?></li>
          <li>📞 <a href="tel:<?= e(config('telefono')) ?>"><?= e(config('telefono')) ?></a></li>
          <li>💬 <a href="https://wa.me/<?= e(config('whatsapp')) ?>" target="_blank" rel="noopener">WhatsApp</a></li>
          <li>📷 <a href="https://instagram.com/<?= e(ltrim(config('instagram'), '@')) ?>" target="_blank" rel="noopener"><?= e(config('instagram')) ?></a></li>
          <li>🕐 <?= e(config('horario')) ?></li>
        </ul>
      </div>
      <div class="col-md-6">
        <div class="ratio ratio-4x3 rounded-4 overflow-hidden border" style="border-color:var(--sns-rosa-claro)!important">
          <iframe src="https://www.google.com/maps?q=<?= urlencode(config('direccion')) ?>&output=embed" loading="lazy" title="Ubicación del spa"></iframe>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer_publico.php'; ?>
