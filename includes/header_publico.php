<?php
/* Header público · requiere includes/funciones.php cargado */
$__titulo   = $titulo ?? 'Sailor Nails Spa';
$__ciudad   = config('ciudad', 'Bogotá');
$__desc     = $metaDescripcion ?? ('Spa de uñas en ' . $__ciudad . ': manicure, pedicure, semipermanente, acrílicas y nail art temático estilo Sailor Moon. Agenda tu cita online.');
$__canon    = urlCanonica();
$__ogimg    = urlAbsoluta('assets/img/logo.jpg');
$__inicio   = sitioBase() . rutaApp() . '/';
// Datos estructurados del negocio (Schema.org NailSalon) para SEO local / resultados enriquecidos
$__ld = [
    '@context' => 'https://schema.org',
    '@type' => 'NailSalon',
    'name' => 'Sailor Nails Spa',
    'description' => $__desc,
    'image' => $__ogimg,
    'url' => $__inicio,
    'telephone' => config('telefono'),
    'priceRange' => '$$',
    'address' => [
        '@type' => 'PostalAddress',
        'streetAddress' => config('direccion'),
        'addressLocality' => $__ciudad,
        'addressCountry' => 'CO',
    ],
    'openingHoursSpecification' => [
        ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday'], 'opens' => config('atencion_lv_inicio', '10:00'), 'closes' => config('atencion_lv_fin', '19:00')],
        ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Saturday', 'opens' => config('atencion_sab_inicio', '09:00'), 'closes' => config('atencion_sab_fin', '19:00')],
    ],
    'sameAs' => array_values(array_filter([
        config('instagram') ? 'https://instagram.com/' . ltrim(config('instagram'), '@') : null,
    ])),
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($__titulo) ?></title>
<meta name="description" content="<?= e($__desc) ?>">
<meta name="keywords" content="manicure, pedicure, semipermanente, uñas acrílicas, nail art, spa de uñas, <?= e($__ciudad) ?>, Sailor Nails Spa">
<meta name="author" content="Sailor Nails Spa">
<meta name="robots" content="index, follow, max-image-preview:large">
<meta name="theme-color" content="#e75da0">
<meta name="geo.placename" content="<?= e($__ciudad) ?>">
<link rel="canonical" href="<?= e($__canon) ?>">
<link rel="icon" href="<?= e($base ?? '') ?>assets/img/logo.jpg">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Sailor Nails Spa">
<meta property="og:title" content="<?= e($__titulo) ?>">
<meta property="og:description" content="<?= e($__desc) ?>">
<meta property="og:image" content="<?= e($__ogimg) ?>">
<meta property="og:url" content="<?= e($__canon) ?>">
<meta property="og:locale" content="es_CO">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= e($__titulo) ?>">
<meta name="twitter:description" content="<?= e($__desc) ?>">
<meta name="twitter:image" content="<?= e($__ogimg) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@600;700&family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="<?= e($base ?? '') ?>assets/css/estilo.css" rel="stylesheet">
<script type="application/ld+json"><?= json_encode($__ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-sns sticky-top">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center gap-2 text-rosa fw-bold" href="<?= e($base ?? '') ?>index.php">
      <img src="<?= e($base ?? '') ?>assets/img/logo.jpg" alt="Sailor Nails Spa" width="42" height="42" class="rounded-circle border border-2" style="border-color:var(--sns-rosa-claro)!important;object-fit:cover">
      Sailor Nails Spa
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menuPrincipal" aria-label="Menú">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="menuPrincipal">
      <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
        <li class="nav-item"><a class="nav-link" href="<?= e($base ?? '') ?>index.php#servicios">Servicios</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= e($base ?? '') ?>index.php#galeria">Galería</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= e($base ?? '') ?>index.php#contacto">Contacto</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= e($base ?? '') ?>tarjeta.php">Mi tarjeta ☾</a></li>
        <li class="nav-item mt-2 mt-lg-0"><a class="btn btn-sns btn-sm px-3" href="<?= e($base ?? '') ?>agendar.php">Agendar ✦</a></li>
      </ul>
    </div>
  </div>
</nav>

<?php foreach (diasEspecialesProximos(4) as $__d): ?>
  <div class="banner-especial <?= $__d['abierto'] ? 'abierto' : 'cerrado' ?>">
    <span class="titulo-esp">
      <span class="luna"><?= $__d['abierto'] ? '🌙' : '🌑' ?></span>
      <?php if ($__d['abierto']): ?>
        ¡Abrimos especial! <?= e(fechaLarga($__d['fecha'])) ?>
        <span class="horario-esp">🕐 <?= e(substr($__d['hora_inicio'], 0, 5)) ?> – <?= e(substr($__d['hora_fin'], 0, 5)) ?></span>
      <?php else: ?>
        Cerrado <?= e(fechaLarga($__d['fecha'])) ?>
      <?php endif; ?>
      <?php if ($__d['titulo']): ?> · <?= e($__d['titulo']) ?><?php endif; ?>
      <span class="luna"><?= $__d['abierto'] ? '✨' : '🌙' ?></span>
    </span>
    <?php if ($__d['nota']): ?><div class="small mt-1"><?= e($__d['nota']) ?></div><?php endif; ?>
  </div>
<?php endforeach; ?>
