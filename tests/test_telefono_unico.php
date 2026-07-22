<?php
// El teléfono de la clienta es único a nivel de BASE DE DATOS, no solo por código.
declare(strict_types=1);
require __DIR__ . '/../includes/funciones.php';

$pdo = db();
$ok = 0; $fail = 0;
function check(string $etiqueta, $esperado, $real) {
    global $ok, $fail;
    if ($esperado == $real) { $ok++; echo "  ok   $etiqueta\n"; }
    else { $fail++; echo "  FAIL $etiqueta: esperado [" . var_export($esperado, true) . "], obtuve [" . var_export($real, true) . "]\n"; }
}

function postA(string $pagina, array $post, array $usuario): string {
    $tmp = sys_get_temp_dir() . '/sns_uq_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($tmp, sprintf(
        '<?php session_start(); $_SESSION["usuario"] = %s; $_SESSION["csrf"] = "tok"; $_POST = %s; ' .
        '$_POST["_csrf"] = "tok"; $_REQUEST = $_POST; $_SERVER["REQUEST_METHOD"] = "POST"; ' .
        '$_SERVER["SCRIPT_NAME"] = "/sns/admin/%s"; include "C:/laragon/www/sns/admin/%s";',
        var_export($usuario, true), var_export($post, true), $pagina, $pagina));
    $out = (string)shell_exec('"C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    return $out;
}

$admin = ['id' => 1, 'nombre' => 'Administradora', 'rol' => 'admin'];
$tel = '3005550011';

try {
    echo "LA BASE TIENE LA RESTRICCION\n";
    $uq = null;
    foreach ($pdo->query('SHOW INDEX FROM clientes') as $i) {
        if ($i['Column_name'] === 'telefono') $uq = $i;
    }
    check('existe un índice sobre telefono', true, $uq !== null);
    check('y es ÚNICO (Non_unique = 0)', '0', $uq['Non_unique'] ?? null);

    echo "\nLA BASE RECHAZA EL DUPLICADO AUNQUE SE INSERTE DIRECTO\n";
    $pdo->prepare("INSERT INTO clientes (nombre, telefono, es_nueva) VALUES ('TEST Unica', ?, 0)")->execute([$tel]);
    $idOriginal = (int)$pdo->lastInsertId();
    $rechazado = false; $codigo = null;
    try {
        // Saltándose toda la validación del código, como haría un script futuro
        $pdo->prepare("INSERT INTO clientes (nombre, telefono, es_nueva) VALUES ('TEST Colada', ?, 0)")->execute([$tel]);
    } catch (PDOException $ex) {
        $rechazado = true; $codigo = $ex->errorInfo[1] ?? null;
    }
    check('la base lo rechaza', true, $rechazado);
    check('con el código de clave duplicada (1062)', 1062, $codigo);
    check('sigue habiendo una sola ficha', 1,
          (int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE telefono = '$tel'")->fetchColumn());

    echo "\nLA CARRERA SE RESUELVE SOLA (no revienta)\n";
    // Simula el choque: la ficha ya existe cuando obtenerOCrearCliente intenta crearla.
    // Antes esto habría lanzado una excepción y roto el agendamiento.
    $devuelto = obtenerOCrearCliente('TEST Simultanea', $tel, null, 1);
    check('devuelve la ficha que ya existía', $idOriginal, $devuelto);
    check('no creó una segunda', 1,
          (int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE telefono = '$tel'")->fetchColumn());

    echo "\nEL PANEL LO EXPLICA EN VEZ DE MOSTRAR UN ERROR CRUDO\n";
    $html = postA('clientes.php', ['nueva' => '1', 'nombre' => 'TEST Otra Mas', 'telefono' => $tel], $admin);
    check('avisa que ya existe', true, str_contains($html, 'Ya existe una clienta'));
    check('no muestra excepción de PHP', false, stripos($html, 'PDOException') !== false);
    check('no muestra error fatal', false, stripos($html, 'Fatal error') !== false);
    check('ofrece abrir la ficha existente', true, str_contains($html, 'Abrir su ficha'));

    echo "\n  y al EDITAR otra clienta hacia un teléfono ya tomado:\n";
    $pdo->prepare("INSERT INTO clientes (nombre, telefono, es_nueva) VALUES ('TEST Vecina','3005550022',0)")->execute();
    $idVecina = (int)$pdo->lastInsertId();
    $html = postA('clientes.php', ['guardar' => '1', 'cliente_id' => (string)$idVecina,
                                   'nombre' => 'TEST Vecina', 'telefono' => $tel], $admin);
    check('lo impide', true, str_contains($html, 'ya es de otra clienta') || str_contains($html, 'ya está registrado'));
    check('sin error crudo', false, stripos($html, 'Fatal error') !== false);
    check('la vecina conservó su teléfono', '3005550022',
          $pdo->query("SELECT telefono FROM clientes WHERE id = $idVecina")->fetchColumn());

    echo "\nSE SIGUE PUDIENDO BUSCAR POR TELEFONO (el índice sirve igual)\n";
    $c = clientePorTelefono($tel);
    check('la encuentra', true, $c !== null);
    // Al resolver la carrera se devuelve la ficha existente SIN renombrarla:
    // el nombre de la titular manda (ver test_nombre_no_sobrescribe.php).
    check('conservó el nombre original de la ficha', 'TEST Unica', $c['nombre'] ?? null);

} finally {
    $pdo->exec("DELETE FROM clientes WHERE nombre LIKE 'TEST %'");
    echo "\n(datos de prueba eliminados)\n";
}

echo "\n==== $ok ok · $fail fallos ====\n";
exit($fail > 0 ? 1 : 0);
