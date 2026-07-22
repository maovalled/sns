<?php
// El instalador toma las credenciales según el entorno y monta el esquema completo.
// Se prueba contra bases desechables; no toca sns_principal.
declare(strict_types=1);

$ok = 0; $fail = 0;
function check(string $etiqueta, $esperado, $real) {
    global $ok, $fail;
    if ($esperado === $real) { $ok++; echo "  ok   $etiqueta\n"; }
    else { $fail++; echo "  FAIL $etiqueta: esperado [" . var_export($esperado, true) . "], obtuve [" . var_export($real, true) . "]\n"; }
}

const PHP_BIN   = '"C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe"';
const MYSQL_BIN = '"C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe"';

function mysql(string $sql): string {
    return (string)shell_exec(MYSQL_BIN . ' -u root -N -e ' . escapeshellarg($sql) . ' 2>&1');
}

/**
 * Ejecuta instalar.php simulando un entorno, con un config/db.php falso que
 * apunta a una base desechable. Devuelve el HTML que produjo.
 */
function instalar(string $base, bool $comoProduccion, string $clave = ''): string {
    $dir = sys_get_temp_dir() . '/sns_inst_' . bin2hex(random_bytes(4));
    mkdir($dir . '/config', 0777, true);
    mkdir($dir . '/sql', 0777, true);
    copy('C:/laragon/www/sns/instalar.php', $dir . '/instalar.php');
    copy('C:/laragon/www/sns/sql/sns_instalacion_completa.sql', $dir . '/sql/sns_instalacion_completa.sql');

    // db.php de mentira: mismas funciones, credenciales apuntando a la base de prueba
    $cfg = '<?php declare(strict_types=1); date_default_timezone_set("America/Bogota");' . "\n"
         . 'define("DB_HOST","localhost"); define("DB_PORT","3306");' . "\n"
         . 'define("DB_NAME",' . var_export($base, true) . '); define("DB_USER","root"); define("DB_PASS","");' . "\n"
         . 'function esEntornoLocal(): bool { return ' . ($comoProduccion ? 'false' : 'true') . '; }' . "\n";
    file_put_contents($dir . '/config/db.php', $cfg);

    $runner = $dir . '/run.php';
    file_put_contents($runner,
        '<?php $_SERVER["REQUEST_METHOD"]="POST"; $_POST=["clave_admin"=>' . var_export($clave, true) . '];'
      . ' include ' . var_export($dir . '/instalar.php', true) . ';');
    $out = (string)shell_exec(PHP_BIN . ' ' . escapeshellarg($runner) . ' 2>&1');

    array_map('unlink', glob($dir . '/config/*') ?: []);
    array_map('unlink', glob($dir . '/sql/*') ?: []);
    array_map('unlink', glob($dir . '/*.php') ?: []);
    @rmdir($dir . '/config'); @rmdir($dir . '/sql'); @rmdir($dir);
    return $out;
}

$baseLocal = 'sns_test_local';
$baseProd  = 'u999999_sns_prod';

try {
    echo "ENTORNO LOCAL · la base NO existe → la crea\n";
    mysql("DROP DATABASE IF EXISTS `$baseLocal`;");
    $html = instalar($baseLocal, false);
    check('avisa que creó la base', true, str_contains($html, 'creada'));
    check('monta las 24 tablas', '24', trim(mysql(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$baseLocal';")));
    check('carga el personal', '5', trim(mysql("SELECT COUNT(*) FROM `$baseLocal`.usuarios;")));
    // El número esperado se saca del propio script: así agregar una paramétrica
    // nueva no rompe la prueba, pero sí se detecta si alguna no llegó a la base.
    $sqlSrc = file_get_contents('C:/laragon/www/sns/sql/sns_instalacion_completa.sql');
    preg_match('/INSERT INTO configuracion \(clave, valor\) VALUES(.*?);/s', $sqlSrc, $mCfg);
    $esperados = preg_match_all("/\('[a-z_]+',/", $mCfg[1] ?? '');
    check('carga todos los parámetros del script', (string)$esperados,
          trim(mysql("SELECT COUNT(*) FROM `$baseLocal`.configuracion;")));
    // Sin comodín: en Windows escapeshellarg convierte el % en espacio y la
    // consulta llegaría mutilada a mysql.
    check('incluye los de la marca de agua', '3', trim(mysql(
        "SELECT COUNT(*) FROM `$baseLocal`.configuracion"
      . " WHERE clave IN ('marca_agua','marca_agua_opacidad','marca_agua_tamano');")));
    // En una sola línea: los saltos de línea se pierden al pasar la consulta por consola.
    check('incluye las tablas de préstamos', '4', trim(mysql(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$baseLocal' AND table_name IN ('prestamos','prestamos_abonos','liquidaciones','asistencia');")));
    check('sin errores', false, str_contains($html, 'Error:'));

    echo "\nENTORNO PRODUCCION · la base YA existe, con prefijo del hosting\n";
    // Igual que en Hostinger: la base la crea el panel, el instalador solo la puebla.
    mysql("DROP DATABASE IF EXISTS `$baseProd`; CREATE DATABASE `$baseProd` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    $html = instalar($baseProd, true);
    check('sin errores', false, str_contains($html, 'Error:'));
    check('NO intenta crear la base', false, str_contains($html, 'creada ✔'));
    check('monta las 24 tablas en la base con prefijo', '24', trim(mysql(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$baseProd';")));
    check('el CREATE DATABASE/USE del script se ignoró', '0', trim(mysql(
        "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='sns_principal_inexistente';")));
    check('carga el personal', '5', trim(mysql("SELECT COUNT(*) FROM `$baseProd`.usuarios;")));

    echo "\nENTORNO PRODUCCION · la base NO existe → explica qué hacer\n";
    mysql("DROP DATABASE IF EXISTS `$baseProd`;");
    $html = instalar($baseProd, true);
    check('avisa que no pudo conectar', true, str_contains($html, 'No se pudo conectar a la base'));
    check('indica que se crea desde el panel', true, str_contains($html, 'hPanel'));
    check('no la creó por su cuenta', '0', trim(mysql(
        "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$baseProd';")));

    echo "\nCLAVE DEL ADMIN\n";
    mysql("DROP DATABASE IF EXISTS `$baseLocal`;");
    $html = instalar($baseLocal, false, 'NuevaClave123');
    check('confirma que la estableció', true, str_contains($html, 'Clave del usuario'));
    $hash = trim(mysql("SELECT clave_hash FROM `$baseLocal`.usuarios WHERE usuario='admin';"));
    check('la clave nueva funciona', true, password_verify('NuevaClave123', $hash));

    mysql("DROP DATABASE IF EXISTS `$baseLocal`;");
    $html = instalar($baseLocal, false, '');
    check('si se deja vacía, conserva la actual', true, str_contains($html, 'claves actuales'));
    $hash2 = trim(mysql("SELECT clave_hash FROM `$baseLocal`.usuarios WHERE usuario='admin';"));
    check('la clave del script sigue puesta', false, password_verify('NuevaClave123', $hash2));

    echo "\nGUARDIA DE SEGURIDAD\n";
    // Con usuarios ya cargados, el instalador debe negarse a correr de nuevo.
    $html = instalar($baseLocal, false);
    check('se bloquea si ya hay usuarios', true, str_contains($html, 'deshabilitado por seguridad'));
    check('pide borrar el archivo', true, str_contains($html, 'elimina el archivo instalar.php'));

} finally {
    mysql("DROP DATABASE IF EXISTS `$baseLocal`; DROP DATABASE IF EXISTS `$baseProd`;");
    echo "\n(bases de prueba eliminadas)\n";
}

echo "\n==== $ok ok · $fail fallos ====\n";
exit($fail > 0 ? 1 : 0);
