<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/funciones.php';
header('Content-Type: application/xml; charset=utf-8');

$base = sitioBase() . rutaApp();
$paginas = [
    ['/',            '1.0', 'weekly'],
    ['/agendar.php', '0.9', 'weekly'],
    ['/tarjeta.php', '0.5', 'monthly'],
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($paginas as [$ruta, $prio, $freq]) {
    $loc = htmlspecialchars($base . $ruta, ENT_XML1);
    echo "  <url><loc>{$loc}</loc><changefreq>{$freq}</changefreq><priority>{$prio}</priority></url>\n";
}
echo '</urlset>';
