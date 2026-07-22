<?php
// La detección de entorno de config/db.php: local vs producción (Hostinger).
// Cada caso corre en un proceso aparte porque las constantes solo se definen una vez.
declare(strict_types=1);

$ok = 0; $fail = 0;
function check(string $etiqueta, $esperado, $real) {
    global $ok, $fail;
    if ($esperado === $real) { $ok++; echo "  ok   $etiqueta\n"; }
    else { $fail++; echo "  FAIL $etiqueta: esperado [" . var_export($esperado, true) . "], obtuve [" . var_export($real, true) . "]\n"; }
}

/**
 * Carga db.php simulando un escenario y devuelve qué base eligió.
 * $host = dominio de la petición (null = línea de comandos).
 * $env  = valor de la variable de entorno SNS_ENTORNO (null = sin definir).
 */
function entornoCon(?string $host, ?string $env = null): array {
    $tmp = sys_get_temp_dir() . '/sns_env_' . bin2hex(random_bytes(4)) . '.php';
    $code = '<?php ';
    if ($env !== null)  $code .= 'putenv("SNS_ENTORNO=' . $env . '"); ';
    if ($host !== null) $code .= '$_SERVER["HTTP_HOST"] = ' . var_export($host, true) . '; ';
    else                $code .= 'unset($_SERVER["HTTP_HOST"]); ';
    $code .= 'require "C:/laragon/www/sns/config/db.php"; '
           . 'echo json_encode(["local" => esEntornoLocal(), "base" => DB_NAME, "user" => DB_USER]);';
    file_put_contents($tmp, $code);
    $out = (string)shell_exec('"C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    $r = json_decode(trim($out), true);
    return is_array($r) ? $r : ['error' => trim($out)];
}

// Los nombres reales configurados, sin darlos por sentado: así la prueba sigue
// valiendo cuando se llenen los datos del hosting.
require_once __DIR__ . '/../config/db.php';
$baseLocal = DB_LOCAL['name'];
$baseProd  = DB_PRODUCCION['name'];

echo "CONFIGURACION\n";
check('hay una base local definida', true, $baseLocal !== '');
check('hay una base de producción definida', true, $baseProd !== '');
check('son distintas (si no, la separación no sirve)', true, $baseLocal !== $baseProd);
echo "  local: $baseLocal · producción: $baseProd\n";

echo "\nPETICION WEB LOCAL → credenciales locales\n";
foreach (['localhost', 'localhost:8080', '127.0.0.1', 'sns.test', 'miapp.local'] as $h) {
    $r = entornoCon($h);
    check("'$h' se detecta como local", true, $r['local'] ?? null);
    check("  → usa la base local", $baseLocal, $r['base'] ?? null);
}

echo "\nPETICION WEB EN HOSTINGER → credenciales de producción\n";
foreach (['sailornailsspa.com', 'www.sailornailsspa.com', 'sailornailsspa.com:443'] as $h) {
    $r = entornoCon($h);
    check("'$h' se detecta como producción", false, $r['local'] ?? null);
    check("  → usa la base de producción", $baseProd, $r['base'] ?? null);
}

echo "\nLINEA DE COMANDOS (pruebas, cron)\n";
// Sin HTTP_HOST decide el sistema operativo: aquí Windows = desarrollo.
$r = entornoCon(null);
check('en Windows se toma como local', true, $r['local'] ?? null);
check('  → las pruebas siguen usando la base local', $baseLocal, $r['base'] ?? null);

echo "\nSNS_ENTORNO MANDA SOBRE TODO LO DEMAS\n";
$r = entornoCon('localhost', 'produccion');
check('forzar produccion desde localhost', false, $r['local'] ?? null);
check('  → usa credenciales de producción', $baseProd, $r['base'] ?? null);
$r = entornoCon('sailornailsspa.com', 'local');
check('forzar local desde el dominio real', true, $r['local'] ?? null);
check('  → usa la base local', $baseLocal, $r['base'] ?? null);

echo "\nAVISO SI SE PUBLICA SIN CONFIGURAR\n";
// Se prueba sobre una copia con los valores de ejemplo sin reemplazar, para que
// la comprobación siga sirviendo aunque el db.php real ya esté configurado.
$copia = sys_get_temp_dir() . '/sns_db_sin_configurar.php';
$fuente = file_get_contents(__DIR__ . '/../config/db.php');
$fuente = preg_replace(
    "/const DB_PRODUCCION = \[.*?\];/s",
    "const DB_PRODUCCION = ['host'=>'localhost','port'=>'3306','name'=>'CAMBIAR_nombre_base','user'=>'CAMBIAR_usuario','pass'=>'CAMBIAR_clave'];",
    $fuente, 1, $reemplazos);
check('la copia de prueba se generó', 1, $reemplazos);
file_put_contents($copia, $fuente);

$runner = sys_get_temp_dir() . '/sns_env_aviso.php';
file_put_contents($runner, '<?php $_SERVER["HTTP_HOST"] = "sailornailsspa.com"; '
    . 'require ' . var_export($copia, true) . '; db();');
$out = (string)shell_exec('"C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" ' . escapeshellarg($runner) . ' 2>&1');
unlink($runner); unlink($copia);
check('explica qué falta en vez de "Access denied"', true, str_contains($out, 'Falta configurar la base de datos de producción'));
check('no muestra una excepción cruda de PDO', false, str_contains($out, 'SQLSTATE'));

echo "\n==== $ok ok · $fail fallos ====\n";
exit($fail > 0 ? 1 : 0);
