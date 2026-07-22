<?php
// Pruebas de alta de clientas y de los endpoints de búsqueda (público e interno).
// Crea sus propios datos y los borra al terminar.
declare(strict_types=1);
require __DIR__ . '/../includes/funciones.php';

$pdo = db();
$ok = 0; $fail = 0;
function check(string $etiqueta, $esperado, $real) {
    global $ok, $fail;
    if ($esperado == $real) { $ok++; echo "  ok   $etiqueta\n"; }
    else { $fail++; echo "  FAIL $etiqueta: esperado [" . var_export($esperado, true) . "], obtuve [" . var_export($real, true) . "]\n"; }
}

/** Ejecuta una página del panel con POST simulado y devuelve su HTML. */
function postA(string $pagina, array $post, ?array $usuario): string {
    $tmp = sys_get_temp_dir() . '/sns_cli_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($tmp, sprintf(
        '<?php session_start(); %s $_SESSION["csrf"] = "tok"; $_POST = %s; $_POST["_csrf"] = "tok"; ' .
        '$_REQUEST = $_POST; $_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["SCRIPT_NAME"] = "/sns/admin/%s"; ' .
        'include "C:/laragon/www/sns/admin/%s";',
        $usuario ? '$_SESSION["usuario"] = ' . var_export($usuario, true) . ';' : '',
        var_export($post, true), $pagina, $pagina));
    $out = (string)shell_exec('"C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    return $out;
}

/** Ejecuta un endpoint GET (con o sin sesión) y devuelve el JSON decodificado. */
function getJson(string $ruta, array $query, ?array $usuario): array {
    $tmp = sys_get_temp_dir() . '/sns_get_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($tmp, sprintf(
        '<?php session_start(); %s $_GET = %s; $_REQUEST = $_GET; $_SERVER["REQUEST_METHOD"] = "GET"; ' .
        '$_SERVER["REMOTE_ADDR"] = "127.0.0.1"; $_SERVER["SCRIPT_NAME"] = "%s"; include "C:/laragon/www/sns%s";',
        $usuario ? '$_SESSION["usuario"] = ' . var_export($usuario, true) . ';' : 'unset($_SESSION["usuario"]);',
        var_export($query, true), $ruta, $ruta));
    $out = (string)shell_exec('"C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    $json = json_decode(trim($out), true);
    return is_array($json) ? $json : ['__raw' => trim($out)];
}

$admin  = ['id' => 1, 'nombre' => 'Administradora', 'rol' => 'admin'];
$recep  = ['id' => 2, 'nombre' => 'Jennifer Rosero', 'rol' => 'recepcion'];
$mani   = ['id' => 3, 'nombre' => 'Valentina', 'rol' => 'manicurista'];
$tel    = '3009998877';

try {
    echo "ALTA DE CLIENTA\n";
    $html = postA('clientes.php', ['nueva' => '1', 'nombre' => 'TEST Carolina Ruiz', 'telefono' => $tel,
                                   'email' => 'caro@correo.com', 'tipo' => 'Nueva', 'documento' => '1017254321'], $admin);
    check('la clienta se registra', true, str_contains($html, 'registrada'));
    $c = $pdo->prepare('SELECT * FROM clientes WHERE telefono = ?'); $c->execute([$tel]);
    $row = $c->fetch();
    check('quedó en la base', 'TEST Carolina Ruiz', $row['nombre'] ?? null);
    check('guardó el correo', 'caro@correo.com', $row['email'] ?? null);
    check('guardó el documento', '1017254321', $row['documento'] ?? null);

    echo "\nNO PERMITE TELEFONO DUPLICADO\n";
    $html = postA('clientes.php', ['nueva' => '1', 'nombre' => 'TEST Otra Persona', 'telefono' => $tel], $admin);
    check('rechaza el duplicado', true, str_contains($html, 'Ya existe una clienta'));
    check('ofrece abrir la ficha existente', true, str_contains($html, 'Abrir su ficha'));
    $n = $pdo->prepare('SELECT COUNT(*) FROM clientes WHERE telefono = ?'); $n->execute([$tel]);
    check('sigue habiendo una sola', 1, (int)$n->fetchColumn());

    echo "\nVALIDACIONES\n";
    $html = postA('clientes.php', ['nueva' => '1', 'nombre' => '', 'telefono' => '3001112222'], $admin);
    check('exige nombre', true, str_contains($html, 'obligatorios'));
    $html = postA('clientes.php', ['nueva' => '1', 'nombre' => 'X', 'telefono' => '3001112222', 'tipo' => 'Inventado'], $admin);
    check('rechaza tipo inválido', true, str_contains($html, 'Tipo de clienta inválido'));
    $html = postA('clientes.php', ['nueva' => '1', 'nombre' => 'X', 'telefono' => '3001112222', 'fecha_nacimiento' => '31-12-1990'], $admin);
    check('rechaza fecha inválida', true, str_contains($html, 'Fecha de nacimiento inválida'));
    $n = $pdo->query("SELECT COUNT(*) FROM clientes WHERE telefono = '3001112222'");
    check('ninguna inválida se guardó', 0, (int)$n->fetchColumn());

    echo "\nENDPOINT INTERNO (datos reales, exige staff)\n";
    $r = getJson('/admin/buscar_cliente.php', ['telefono' => $tel], $admin);
    check('encuentra la clienta', true, $r['existe'] ?? false);
    check('devuelve el nombre REAL (sin enmascarar)', 'TEST Carolina Ruiz', $r['cliente']['nombre'] ?? null);
    check('devuelve el correo real', 'caro@correo.com', $r['cliente']['email'] ?? null);
    check('recepción también puede consultar', true, (getJson('/admin/buscar_cliente.php', ['telefono' => $tel], $recep))['existe'] ?? false);

    echo "\n  seguridad del endpoint interno:\n";
    $r = getJson('/admin/buscar_cliente.php', ['telefono' => $tel], null);
    check('sin sesión NO devuelve datos', true, isset($r['error']));
    check('sin sesión no filtra el nombre', false, str_contains(json_encode($r), 'Carolina'));
    $r = getJson('/admin/buscar_cliente.php', ['telefono' => $tel], $mani);
    check('una manicurista NO puede consultar', true, isset($r['error']));

    echo "\n  sugerencias por nombre:\n";
    $r = getJson('/admin/buscar_cliente.php', ['q' => 'Carolina'], $admin);
    check('sugiere por nombre parcial', 1, count($r['sugerencias'] ?? []));
    $r = getJson('/admin/buscar_cliente.php', ['q' => 'C'], $admin);
    check('ignora búsquedas de 1 letra', 0, count($r['sugerencias'] ?? []));

    echo "\nENDPOINT PUBLICO (sigue enmascarando)\n";
    $r = getJson('/api/cliente.php', ['telefono' => $tel], null);
    check('reconoce el teléfono', true, $r['existe'] ?? false);
    check('el nombre va enmascarado', 'T*** C******* R***', $r['nombreMascara'] ?? null);
    check('NO expone el nombre real', false, str_contains(json_encode($r), 'Carolina'));
    check('NO expone el correo real', false, str_contains(json_encode($r), 'caro@correo.com'));
    check('indica que tiene correo', true, $r['tieneEmail'] ?? false);
    check('el correo va enmascarado', 'c***@c*****.com', $r['emailMascara'] ?? null);

    echo "\nRECONOCE EL TELEFONO SIN IMPORTAR EL FORMATO\n";
    // La clienta quedó guardada como '3009998877'; debe reconocerse escrito de otras formas.
    foreach (['3009998877', '300 999 8877', '300-999-8877', '+57 3009998877', '573009998877', '(300) 999 8877'] as $forma) {
        $r = getJson('/admin/buscar_cliente.php', ['telefono' => $forma], $admin);
        check("interno reconoce '$forma'", 'TEST Carolina Ruiz', $r['cliente']['nombre'] ?? null);
    }
    $r = getJson('/api/cliente.php', ['telefono' => '+57 300 999 8877'], null);
    check('público también lo reconoce', true, $r['existe'] ?? false);
    check('y lo sigue enmascarando', 'T*** C******* R***', $r['nombreMascara'] ?? null);

    echo "\n  y si la clienta quedó guardada CON formato:\n";
    $pdo->prepare("INSERT INTO clientes (nombre, telefono, es_nueva) VALUES ('TEST Marcela Diaz','+57 301-555-4433',0)")->execute();
    foreach (['3015554433', '301 555 4433', '+573015554433'] as $forma) {
        $r = getJson('/admin/buscar_cliente.php', ['telefono' => $forma], $admin);
        check("reconoce '$forma' guardado con formato", 'TEST Marcela Diaz', $r['cliente']['nombre'] ?? null);
    }

    echo "\nNUMERO INCOMPLETO OFRECE COINCIDENCIAS\n";
    $r = getJson('/admin/buscar_cliente.php', ['telefono' => '30099'], $admin);
    check('no da falso positivo', false, $r['existe'] ?? true);
    // Puede haber otras clientas reales con ese mismo prefijo: lo que importa es
    // que la nuestra aparezca entre las sugerencias.
    $nombres = array_column($r['parciales'] ?? [], 'nombre');
    check('ofrece al menos una coincidencia', true, count($nombres) >= 1);
    check('la nuestra está entre las sugerencias', true, in_array('TEST Carolina Ruiz', $nombres, true));
    check('todas comparten el prefijo buscado', true,
          count($nombres) === count(array_filter($r['parciales'] ?? [],
              fn($c) => str_contains(preg_replace('/\D+/', '', $c['telefono']), '30099'))));
    $r = getJson('/admin/buscar_cliente.php', ['telefono' => '30'], $admin);
    check('con menos de 3 dígitos no sugiere nada', 0, count($r['parciales'] ?? []));

    echo "\nEL SERVIDOR RECONOCE IGUAL QUE LA PANTALLA (no duplica)\n";
    // Si el aviso dice "te reconocimos", obtenerOCrearCliente debe devolver ESA clienta,
    // no crear otra ficha por haber escrito el número con otro formato.
    $idOriginal = (int)$pdo->query("SELECT id FROM clientes WHERE nombre = 'TEST Carolina Ruiz'")->fetchColumn();
    $antes = (int)$pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn();
    foreach (['300 999 8877', '+573009998877', '(300)999-8877'] as $forma) {
        check("'$forma' devuelve la MISMA clienta", $idOriginal,
              obtenerOCrearCliente('TEST Carolina Ruiz', $forma, null, 0));
    }
    check('no se creó ninguna ficha nueva', $antes, (int)$pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn());

    check('el alta manual también detecta el duplicado con otro formato', true,
          str_contains(postA('clientes.php', ['nueva' => '1', 'nombre' => 'TEST Duplicada',
                                              'telefono' => '+57 300 999 8877'], $admin), 'Ya existe una clienta'));

    echo "\nTELEFONO DESCONOCIDO\n";
    check('interno: no existe', false, (getJson('/admin/buscar_cliente.php', ['telefono' => '3990000000'], $admin))['existe'] ?? true);
    check('público: no existe', false, (getJson('/api/cliente.php', ['telefono' => '3990000000'], null))['existe'] ?? true);

} finally {
    $pdo->exec("DELETE FROM clientes WHERE nombre LIKE 'TEST %'");
    $pdo->exec("DELETE FROM rate_limit WHERE ip = '127.0.0.1'");
    echo "\n(datos de prueba eliminados)\n";
}

echo "\n==== $ok ok · $fail fallos ====\n";
exit($fail > 0 ? 1 : 0);
