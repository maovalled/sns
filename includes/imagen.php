<?php
/*
 * Procesamiento de las fotos de la galería: corrige la orientación, reduce el
 * tamaño y estampa la marca de agua del spa.
 *
 * La marca queda GRABADA en el archivo que se publica: quien descargue la foto
 * desde el sitio se la lleva con la marca. El archivo original se conserva
 * aparte (uploads/galeria/originales/), fuera del alcance del navegador, por si
 * hay que rehacerla con otro tamaño u opacidad.
 */
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';

/** Lado mayor al que se reduce la foto publicada (las de celular pesan de más). */
const FOTO_LADO_MAX = 1600;
/** Calidad JPEG/WebP de la foto publicada. */
const FOTO_CALIDAD = 85;
/** Color de fondo del logo, el que se vuelve transparente al recortarlo. */
const LOGO_FONDO = [0xFC, 0xEB, 0xF3];

/**
 * Carpeta donde se guardan los originales SIN marca. Se crea si no existe, con
 * su .htaccess: la marca queda grabada en el archivo publicado, así que sin esta
 * copia no habría manera de rehacerla con otro tamaño u opacidad. Y como son las
 * fotos limpias, no deben servirse por web.
 */
function dirOriginalesGaleria(): string {
    $dir = __DIR__ . '/../uploads/galeria/originales';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $htaccess = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($htaccess)) {
        @file_put_contents($htaccess,
            "# Fotos originales sin marca de agua: no deben servirse por web.\n"
          . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
          . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

/** ¿Está activada la marca de agua? (Admin → Paramétricas) */
function marcaAguaActiva(): bool {
    return config('marca_agua', '1') === '1';
}

/** Opacidad de la marca, 10–100 %. Con menos de 60 se pierde sobre fondos claros. */
function marcaAguaOpacidad(): int {
    return max(10, min(100, (int)config('marca_agua_opacidad', '70')));
}

/** Ancho de la marca como % del lado menor de la foto, 5–40 %. */
function marcaAguaTamano(): int {
    return max(5, min(40, (int)config('marca_agua_tamano', '22')));
}

/**
 * Arma la marca de agua a partir del logo: le quita el fondo rosa y le aplica
 * la opacidad. Los bordes se difuminan según qué tan cerca esté cada píxel del
 * color de fondo, para que no quede un halo recortado.
 */
function marcaAguaDesdeLogo(int $anchoDestino, int $opacidadPct): ?GdImage {
    $ruta = __DIR__ . '/../assets/img/logo.jpg';
    if (!is_file($ruta)) return null;
    $logo = @imagecreatefromjpeg($ruta);
    if (!$logo) return null;

    $w = imagesx($logo); $h = imagesy($logo);
    $recortado = imagecreatetruecolor($w, $h);
    imagealphablending($recortado, false);
    imagesavealpha($recortado, true);
    imagefill($recortado, 0, 0, imagecolorallocatealpha($recortado, 0, 0, 0, 127));

    [$kr, $kg, $kb] = LOGO_FONDO;
    $alphaMin = (int)round(127 * (1 - $opacidadPct / 100));  // 0 = opaco, 127 = invisible
    $cerca = 10;   // hasta aquí es fondo puro
    $lejos = 40;   // desde aquí es logo puro

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($logo, $x, $y);
            $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
            $d = max(abs($r - $kr), abs($g - $kg), abs($b - $kb));

            if ($d <= $cerca)      $a = 127;                                   // fondo
            elseif ($d >= $lejos)  $a = $alphaMin;                             // logo
            else                   $a = (int)round(127 - (127 - $alphaMin) * ($d - $cerca) / ($lejos - $cerca));

            imagesetpixel($recortado, $x, $y, imagecolorallocatealpha($recortado, $r, $g, $b, $a));
        }
    }
    imagedestroy($logo);

    // Reducir al tamaño final: al hacerlo después del recorte, el borde queda suave.
    $anchoDestino = max(40, $anchoDestino);
    $altoDestino = (int)round($anchoDestino * $h / $w);
    $marca = imagecreatetruecolor($anchoDestino, $altoDestino);
    imagealphablending($marca, false);
    imagesavealpha($marca, true);
    imagefill($marca, 0, 0, imagecolorallocatealpha($marca, 0, 0, 0, 127));
    imagecopyresampled($marca, $recortado, 0, 0, 0, 0, $anchoDestino, $altoDestino, $w, $h);
    imagedestroy($recortado);

    return $marca;
}

/** Endereza la foto según la orientación que guardó la cámara del celular. */
function corregirOrientacion(GdImage $img, string $ruta, string $mime): GdImage {
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) return $img;
    $exif = @exif_read_data($ruta);
    $o = (int)($exif['Orientation'] ?? 0);
    if ($o === 3)      $img = imagerotate($img, 180, 0);
    elseif ($o === 6)  $img = imagerotate($img, -90, 0);
    elseif ($o === 8)  $img = imagerotate($img, 90, 0);
    return $img;
}

/**
 * Recibe una foto subida y la deja publicada: valida, guarda el original sin
 * marca y genera la versión con marca que se sirve por web.
 *
 * $mover permite inyectar cómo se traslada el archivo temporal. Por defecto usa
 * move_uploaded_file (lo correcto para una subida real); las pruebas pasan
 * `rename` porque esa función solo acepta archivos de una petición HTTP.
 *
 * Devuelve [nombreArchivo|null, 'motivo del error'].
 */
function guardarFotoGaleria(array $file, ?callable $mover = null): array {
    $mover ??= 'move_uploaded_file';

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [null, 'No se recibió la imagen.'];
    }
    $mime = @mime_content_type($file['tmp_name']) ?: '';
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext)                              return [null, 'Solo se permiten imágenes JPG, PNG o WebP.'];
    if (($file['size'] ?? 0) > 5*1024*1024) return [null, 'La imagen no puede pesar más de 5 MB.'];

    $archivo   = 'trabajo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $original  = dirOriginalesGaleria() . '/' . $archivo;
    $publicada = __DIR__ . '/../uploads/galeria/' . $archivo;

    if (!$mover($file['tmp_name'], $original)) {
        return [null, 'No se pudo guardar el archivo (revisa permisos de uploads/).'];
    }
    [$ok, $motivo] = procesarFotoGaleria($original, $publicada, $mime);
    if (!$ok) {
        @unlink($original);
        return [null, $motivo];
    }
    return [$archivo, ''];
}

/**
 * Deja lista la foto para publicar: la endereza, la reduce y le pone la marca.
 * Devuelve [true, ''] o [false, 'motivo'].
 */
function procesarFotoGaleria(string $origen, string $destino, string $mime): array {
    $crear = ['image/jpeg' => 'imagecreatefromjpeg',
              'image/png'  => 'imagecreatefrompng',
              'image/webp' => 'imagecreatefromwebp'][$mime] ?? null;
    if (!$crear || !function_exists($crear)) {
        return [false, 'El servidor no puede procesar ese formato de imagen.'];
    }
    $img = @$crear($origen);
    if (!$img) return [false, 'La imagen está dañada o no se pudo leer.'];

    $img = corregirOrientacion($img, $origen, $mime);

    // Reducir si viene muy grande (una foto de celular puede pasar de 4000 px)
    $w = imagesx($img); $h = imagesy($img);
    $lado = max($w, $h);
    if ($lado > FOTO_LADO_MAX) {
        $nw = (int)round($w * FOTO_LADO_MAX / $lado);
        $nh = (int)round($h * FOTO_LADO_MAX / $lado);
        $chico = imagecreatetruecolor($nw, $nh);
        imagealphablending($chico, false);
        imagesavealpha($chico, true);
        imagecopyresampled($chico, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $chico; $w = $nw; $h = $nh;
    }

    // Estampar la marca abajo a la derecha, pero DENTRO del cuadrado central.
    //
    // La galería muestra las fotos en cuadrado (object-fit: cover), así que de una
    // foto vertical recorta arriba y abajo. Anclada al borde inferior, la marca
    // quedaba cortada y casi no se veía en el sitio. Anclándola al cuadrado central
    // —la parte que siempre sobrevive al recorte— se ve completa tanto en la
    // galería como en la foto descargada.
    if (marcaAguaActiva()) {
        $lado  = min($w, $h);
        $ancho = (int)round($lado * marcaAguaTamano() / 100);
        $marca = marcaAguaDesdeLogo($ancho, marcaAguaOpacidad());
        if ($marca) {
            $margen = (int)round($lado * 0.03);
            $areaDerecha = (int)(($w - $lado) / 2) + $lado;   // borde derecho del cuadrado central
            $areaAbajo   = (int)(($h - $lado) / 2) + $lado;   // borde inferior del cuadrado central
            $x = $areaDerecha - imagesx($marca) - $margen;
            $y = $areaAbajo   - imagesy($marca) - $margen;
            imagealphablending($img, true);   // que la transparencia de la marca se respete
            imagecopy($img, $marca, max(0, $x), max(0, $y), 0, 0, imagesx($marca), imagesy($marca));
            imagedestroy($marca);
        }
    }

    imagealphablending($img, false);
    imagesavealpha($img, true);
    $ok = match ($mime) {
        'image/jpeg' => imagejpeg($img, $destino, FOTO_CALIDAD),
        'image/png'  => imagepng($img, $destino, 6),
        'image/webp' => imagewebp($img, $destino, FOTO_CALIDAD),
    };
    imagedestroy($img);

    return $ok ? [true, ''] : [false, 'No se pudo guardar la imagen procesada.'];
}
