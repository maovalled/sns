<?php
// Marca de agua en las fotos de la galería: se graba en el archivo publicado
// y el original sin marca se conserva aparte.
declare(strict_types=1);
require __DIR__ . '/../includes/imagen.php';

$pdo = db();
$ok = 0; $fail = 0;
function check(string $etiqueta, $esperado, $real) {
    global $ok, $fail;
    if ($esperado == $real) { $ok++; echo "  ok   $etiqueta\n"; }
    else { $fail++; echo "  FAIL $etiqueta: esperado [" . var_export($esperado, true) . "], obtuve [" . var_export($real, true) . "]\n"; }
}

$tmp = sys_get_temp_dir() . '/sns_img_' . bin2hex(random_bytes(4));
mkdir($tmp);

/** Crea una foto de prueba de color liso. */
function fotoLisa(string $ruta, int $w, int $h, array $rgb, string $mime = 'image/jpeg'): void {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
    match ($mime) {
        'image/jpeg' => imagejpeg($im, $ruta, 95),
        'image/png'  => imagepng($im, $ruta),
        'image/webp' => imagewebp($im, $ruta, 95),
    };
    imagedestroy($im);
}

/** ¿Cuántos píxeles cambiaron respecto del color liso original? */
function pixelesMarcados(string $ruta, array $rgb, string $zona = 'inferior-derecha'): int {
    $im = imagecreatefromstring(file_get_contents($ruta));
    $w = imagesx($im); $h = imagesy($im);
    [$x0, $y0, $x1, $y1] = $zona === 'inferior-derecha'
        ? [(int)($w * 0.55), (int)($h * 0.55), $w, $h]
        : [0, 0, (int)($w * 0.45), (int)($h * 0.45)];
    $n = 0;
    for ($y = $y0; $y < $y1; $y += 2) {
        for ($x = $x0; $x < $x1; $x += 2) {
            $c = imagecolorat($im, $x, $y);
            $d = max(abs((($c >> 16) & 255) - $rgb[0]), abs((($c >> 8) & 255) - $rgb[1]), abs(($c & 255) - $rgb[2]));
            if ($d > 12) $n++;
        }
    }
    imagedestroy($im);
    return $n;
}

$blanco = [255, 255, 255];
$valorOriginal = config('marca_agua', '1');

try {
    echo "LA MARCA SE GRABA EN LA FOTO\n";
    fotoLisa("$tmp/a.jpg", 1000, 800, $blanco);
    [$r, $err] = procesarFotoGaleria("$tmp/a.jpg", "$tmp/a_out.jpg", 'image/jpeg');
    check('procesa sin error', true, $r);
    check('genera el archivo', true, is_file("$tmp/a_out.jpg"));
    $marcados = pixelesMarcados("$tmp/a_out.jpg", $blanco);
    check('la esquina inferior derecha quedó marcada', true, $marcados > 200);
    check('el resto de la foto quedó intacto', 0, pixelesMarcados("$tmp/a_out.jpg", $blanco, 'superior-izquierda'));

    echo "\nSE PUEDE APAGAR DESDE PARAMETRICAS\n";
    $pdo->exec("UPDATE configuracion SET valor='0' WHERE clave='marca_agua'");
    // config() cachea, así que la comprobación corre en un proceso aparte
    $script = sys_get_temp_dir() . '/sns_sinmarca.php';
    file_put_contents($script, '<?php require "C:/laragon/www/sns/includes/imagen.php";'
        . ' procesarFotoGaleria(' . var_export("$tmp/a.jpg", true) . ', ' . var_export("$tmp/b_out.jpg", true) . ', "image/jpeg");');
    shell_exec('"C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" ' . escapeshellarg($script));
    unlink($script);
    check('sin marca la foto queda limpia', 0, pixelesMarcados("$tmp/b_out.jpg", $blanco));
    $pdo->exec("UPDATE configuracion SET valor='1' WHERE clave='marca_agua'");

    echo "\nFOTOS GRANDES SE REDUCEN\n";
    fotoLisa("$tmp/grande.jpg", 4000, 3000, $blanco);
    procesarFotoGaleria("$tmp/grande.jpg", "$tmp/grande_out.jpg", 'image/jpeg');
    [$w, $h] = getimagesize("$tmp/grande_out.jpg");
    check('el lado mayor baja a ' . FOTO_LADO_MAX, FOTO_LADO_MAX, max($w, $h));
    check('conserva la proporción', round(4000 / 3000, 2), round($w / $h, 2));
    check('pesa menos que el original', true, filesize("$tmp/grande_out.jpg") < filesize("$tmp/grande.jpg"));

    echo "\nUNA FOTO PEQUEÑA NO SE AGRANDA\n";
    fotoLisa("$tmp/chica.jpg", 600, 400, $blanco);
    procesarFotoGaleria("$tmp/chica.jpg", "$tmp/chica_out.jpg", 'image/jpeg');
    check('mantiene su tamaño', [600, 400], array_slice(getimagesize("$tmp/chica_out.jpg"), 0, 2));
    check('igual lleva marca', true, pixelesMarcados("$tmp/chica_out.jpg", $blanco) > 30);

    echo "\nFORMATOS SOPORTADOS\n";
    foreach (['png' => 'image/png', 'webp' => 'image/webp'] as $ext => $mime) {
        fotoLisa("$tmp/f.$ext", 800, 800, $blanco, $mime);
        [$r] = procesarFotoGaleria("$tmp/f.$ext", "$tmp/f_out.$ext", $mime);
        check("procesa $ext", true, $r);
        check("  $ext queda marcado", true, pixelesMarcados("$tmp/f_out.$ext", $blanco) > 100);
    }

    echo "\nARCHIVO DAÑADO NO REVIENTA\n";
    file_put_contents("$tmp/roto.jpg", 'esto no es una imagen');
    [$r, $err] = procesarFotoGaleria("$tmp/roto.jpg", "$tmp/roto_out.jpg", 'image/jpeg');
    check('devuelve error controlado', false, $r);
    check('con un motivo entendible', true, $err !== '');
    check('no deja archivo a medias', false, is_file("$tmp/roto_out.jpg"));

    echo "\nLA MARCA ES MAS VISIBLE A MAYOR OPACIDAD\n";
    $m40 = marcaAguaDesdeLogo(200, 40);
    $m90 = marcaAguaDesdeLogo(200, 90);
    check('se genera la marca', true, $m40 !== null && $m90 !== null);
    // El centro del logo: a mayor opacidad, menor valor alfa (más opaco)
    $a40 = (imagecolorat($m40, 100, 100) >> 24) & 0x7F;
    $a90 = (imagecolorat($m90, 100, 100) >> 24) & 0x7F;
    check('al 90 % es más opaca que al 40 %', true, $a90 < $a40);
    check('el fondo del logo quedó transparente', 127, (imagecolorat($m40, 2, 2) >> 24) & 0x7F);

    echo "\nSUBIDA COMPLETA (como la hace el panel)\n";
    // Se inyecta `rename` porque move_uploaded_file solo acepta archivos que
    // llegaron por una petición HTTP real.
    fotoLisa("$tmp/subir.jpg", 900, 700, $blanco);
    copy("$tmp/subir.jpg", "$tmp/tmp_upload.jpg");
    [$nombre, $motivo] = guardarFotoGaleria(
        ['tmp_name' => "$tmp/tmp_upload.jpg", 'error' => UPLOAD_ERR_OK, 'size' => filesize("$tmp/subir.jpg")],
        'rename');
    check('la subida devuelve nombre de archivo', true, $nombre !== null);
    check('sin error', '', $motivo);

    $publicada = __DIR__ . '/../uploads/galeria/' . $nombre;
    $original  = dirOriginalesGaleria() . '/' . $nombre;
    check('quedó la foto publicada', true, is_file($publicada));
    check('y el original aparte', true, is_file($original));
    check('la publicada lleva marca', true, pixelesMarcados($publicada, $blanco) > 100);
    check('el original NO lleva marca', 0, pixelesMarcados($original, $blanco));
    @unlink($publicada); @unlink($original);

    echo "\n  y rechaza lo que no debe:\n";
    file_put_contents("$tmp/doc.txt", 'no soy una imagen');
    copy("$tmp/doc.txt", "$tmp/tmp2");
    [$n2, $m2] = guardarFotoGaleria(['tmp_name' => "$tmp/tmp2", 'error' => UPLOAD_ERR_OK, 'size' => 20], 'rename');
    check('no acepta un archivo que no es imagen', null, $n2);
    check('lo explica', true, str_contains($m2, 'JPG, PNG o WebP'));

    fotoLisa("$tmp/pesada.jpg", 900, 700, $blanco);
    copy("$tmp/pesada.jpg", "$tmp/tmp3.jpg");
    [$n3, $m3] = guardarFotoGaleria(
        ['tmp_name' => "$tmp/tmp3.jpg", 'error' => UPLOAD_ERR_OK, 'size' => 6*1024*1024], 'rename');
    check('rechaza más de 5 MB', null, $n3);
    check('lo explica', true, str_contains($m3, '5 MB'));

    echo "\nLA MARCA SOBREVIVE AL RECORTE CUADRADO DE LA GALERIA\n";
    // El sitio muestra las fotos en cuadrado (object-fit: cover): de una foto
    // vertical recorta arriba y abajo. La marca no puede quedar en esa zona.
    foreach ([[900, 1600, 'vertical'], [1600, 900, 'horizontal'], [1000, 1000, 'cuadrada']] as [$w, $h, $forma]) {
        fotoLisa("$tmp/f_$forma.jpg", $w, $h, $blanco);
        procesarFotoGaleria("$tmp/f_$forma.jpg", "$tmp/f_{$forma}_out.jpg", 'image/jpeg');

        // Recortar al cuadrado central, igual que hace el navegador
        $im = imagecreatefromjpeg("$tmp/f_{$forma}_out.jpg");
        $rw = imagesx($im); $rh = imagesy($im); $lado = min($rw, $rh);
        $cuad = imagecreatetruecolor($lado, $lado);
        imagecopy($cuad, $im, 0, 0, (int)(($rw - $lado) / 2), (int)(($rh - $lado) / 2), $lado, $lado);
        imagejpeg($cuad, "$tmp/crop_$forma.jpg", 92);
        imagedestroy($im); imagedestroy($cuad);

        $enRecorte = pixelesMarcados("$tmp/crop_$forma.jpg", $blanco);
        $enCompleta = pixelesMarcados("$tmp/f_{$forma}_out.jpg", $blanco);
        check("foto $forma: la marca se ve en la foto completa", true, $enCompleta > 50);
        check("foto $forma: y también en el recorte de la galería", true, $enRecorte > 50);
        // Si estuviera pegada al borde inferior, en el recorte se vería mucho menos
        check("foto $forma: no se pierde al recortar", true, $enRecorte >= $enCompleta * 0.9);
    }

    echo "\nREGENERAR LAS MARCAS DESDE EL PANEL\n";
    fotoLisa("$tmp/regen.jpg", 800, 800, $blanco);
    copy("$tmp/regen.jpg", "$tmp/tmp_regen.jpg");
    [$nr] = guardarFotoGaleria(
        ['tmp_name' => "$tmp/tmp_regen.jpg", 'error' => UPLOAD_ERR_OK, 'size' => filesize("$tmp/regen.jpg")], 'rename');
    $pdo->prepare('INSERT INTO galeria (archivo, titulo) VALUES (?,?)')->execute([$nr, 'PRUEBA regenerar']);
    $idr = (int)$pdo->lastInsertId();
    $antes = pixelesMarcados(__DIR__ . '/../uploads/galeria/' . $nr, $blanco);

    // Se sube la opacidad y se regenera: la marca debe quedar más presente
    $pdo->exec("UPDATE configuracion SET valor='100' WHERE clave='marca_agua_opacidad'");
    $html = (function () {
        $t = sys_get_temp_dir() . '/sns_regen_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($t, '<?php session_start();'
            . ' $_SESSION["usuario"]=["id"=>1,"nombre"=>"Administradora","rol"=>"admin"]; $_SESSION["csrf"]="tok";'
            . ' $_POST=["_csrf"=>"tok","regenerar"=>"1"];'
            . ' $_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["SCRIPT_NAME"]="/sns/admin/galeria.php";'
            . ' include "C:/laragon/www/sns/admin/galeria.php";');
        $o = (string)shell_exec('"C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" ' . escapeshellarg($t) . ' 2>&1');
        unlink($t);
        return $o;
    })();
    $pdo->exec("UPDATE configuracion SET valor='70' WHERE clave='marca_agua_opacidad'");

    check('el panel confirma la regeneración', true, str_contains($html, 'Marcas regeneradas'));
    check('avisa de las que no tienen original', true, str_contains($html, 'sin original guardado'));
    $despues = pixelesMarcados(__DIR__ . '/../uploads/galeria/' . $nr, $blanco);
    check('la marca cambió al subir la opacidad', true, $despues > $antes);
    check('el original quedó intacto (sin marca)', 0, pixelesMarcados(dirOriginalesGaleria() . '/' . $nr, $blanco));
    $pdo->exec("DELETE FROM galeria WHERE id = $idr");
    @unlink(__DIR__ . '/../uploads/galeria/' . $nr);
    @unlink(dirOriginalesGaleria() . '/' . $nr);

    echo "\nBORRAR UNA FOTO SE LLEVA TAMBIEN SU ORIGINAL\n";
    fotoLisa("$tmp/borrar.jpg", 500, 500, $blanco);
    copy("$tmp/borrar.jpg", "$tmp/tmp_bor.jpg");
    [$nb] = guardarFotoGaleria(
        ['tmp_name' => "$tmp/tmp_bor.jpg", 'error' => UPLOAD_ERR_OK, 'size' => filesize("$tmp/borrar.jpg")], 'rename');
    $pdo->prepare('INSERT INTO galeria (archivo, titulo) VALUES (?,?)')->execute([$nb, 'PRUEBA borrado']);
    $idb = (int)$pdo->lastInsertId();

    $html = (function (int $id) {
        $t = sys_get_temp_dir() . '/sns_del_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($t, '<?php session_start();'
            . ' $_SESSION["usuario"]=["id"=>1,"nombre"=>"Administradora","rol"=>"admin"]; $_SESSION["csrf"]="tok";'
            . ' $_POST=["_csrf"=>"tok","eliminar_id"=>' . $id . '];'
            . ' $_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["SCRIPT_NAME"]="/sns/admin/galeria.php";'
            . ' include "C:/laragon/www/sns/admin/galeria.php";');
        $o = (string)shell_exec('"C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" ' . escapeshellarg($t) . ' 2>&1');
        unlink($t);
        return $o;
    })($idb);

    check('el panel confirma el borrado', true, str_contains($html, 'Foto eliminada'));
    check('borró la foto publicada', false, is_file(__DIR__ . '/../uploads/galeria/' . $nb));
    check('y también su original', false, is_file(dirOriginalesGaleria() . '/' . $nb));
    check('la sacó de la base', 0, (int)$pdo->query("SELECT COUNT(*) FROM galeria WHERE id = $idb")->fetchColumn());

    echo "\n  y una foto VIEJA (sin original guardado) también se borra bien:\n";
    // Es el caso de las fotos que ya estaban antes de la marca de agua.
    $vieja = 'trabajo_vieja_' . bin2hex(random_bytes(3)) . '.jpg';
    fotoLisa(__DIR__ . '/../uploads/galeria/' . $vieja, 400, 400, $blanco);
    $pdo->prepare('INSERT INTO galeria (archivo, titulo) VALUES (?,?)')->execute([$vieja, 'PRUEBA vieja']);
    $idv = (int)$pdo->lastInsertId();
    check('no existe original para ella', false, is_file(dirOriginalesGaleria() . '/' . $vieja));
    $html = (function (int $id) {
        $t = sys_get_temp_dir() . '/sns_del2_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($t, '<?php session_start();'
            . ' $_SESSION["usuario"]=["id"=>1,"nombre"=>"Administradora","rol"=>"admin"]; $_SESSION["csrf"]="tok";'
            . ' $_POST=["_csrf"=>"tok","eliminar_id"=>' . $id . '];'
            . ' $_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["SCRIPT_NAME"]="/sns/admin/galeria.php";'
            . ' include "C:/laragon/www/sns/admin/galeria.php";');
        $o = (string)shell_exec('"C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" ' . escapeshellarg($t) . ' 2>&1');
        unlink($t);
        return $o;
    })($idv);
    check('se borra sin errores', true, str_contains($html, 'Foto eliminada'));
    check('sin avisos de PHP por el original que no existe', false,
          stripos($html, 'Warning') !== false || stripos($html, 'Fatal') !== false);
    check('el archivo se fue', false, is_file(__DIR__ . '/../uploads/galeria/' . $vieja));

    echo "\nEL ORIGINAL DE LA GALERIA ESTA PROTEGIDO\n";
    $dirOrig = dirOriginalesGaleria();   // la crea si hace falta
    check('existe la carpeta de originales', true, is_dir($dirOrig));
    check('con .htaccess que bloquea el acceso web', true,
          is_file("$dirOrig/.htaccess") && str_contains(file_get_contents("$dirOrig/.htaccess"), 'denied'));

} finally {
    $pdo->exec("UPDATE configuracion SET valor=" . $pdo->quote($valorOriginal) . " WHERE clave='marca_agua'");
    // Por si alguna comprobación falló antes de limpiar sus propios archivos
    foreach ($pdo->query("SELECT id, archivo FROM galeria WHERE titulo LIKE 'PRUEBA %'") as $r) {
        @unlink(__DIR__ . '/../uploads/galeria/' . $r['archivo']);
        @unlink(dirOriginalesGaleria() . '/' . $r['archivo']);
        $pdo->exec('DELETE FROM galeria WHERE id = ' . (int)$r['id']);
    }
    array_map('unlink', glob("$tmp/*") ?: []);
    @rmdir($tmp);
    echo "\n(archivos de prueba eliminados)\n";
}

echo "\n==== $ok ok · $fail fallos ====\n";
exit($fail > 0 ? 1 : 0);
