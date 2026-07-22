<?php
// El nombre de la ficha NO se sobrescribe cuando alguien agenda con un teléfono
// que ya está registrado a otra persona (la hija con el celular de la mamá).
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
    $tmp = sys_get_temp_dir() . '/sns_nom_' . bin2hex(random_bytes(4)) . '.php';
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
$tel = '3006661100';

try {
    $pdo->prepare("INSERT INTO clientes (nombre, telefono, email, es_nueva) VALUES ('TEST Mamá', ?, 'mama@correo.com', 0)")
        ->execute([$tel]);
    $idMama = (int)$pdo->lastInsertId();

    echo "LA FICHA NO SE RENOMBRA\n";
    $nombreReserva = null;
    $id = obtenerOCrearCliente('TEST Hija', $tel, null, 0, $nombreReserva);
    check('devuelve la ficha existente', $idMama, $id);
    check('el nombre NO cambió', 'TEST Mamá',
          $pdo->query("SELECT nombre FROM clientes WHERE id = $idMama")->fetchColumn());
    check('avisa el nombre de la reserva', 'TEST Hija', $nombreReserva);

    echo "\n  el correo de la titular tampoco se pisa:\n";
    obtenerOCrearCliente('TEST Hija', $tel, 'hija@correo.com', 0, $x);
    check('conserva el correo de la mamá', 'mama@correo.com',
          $pdo->query("SELECT email FROM clientes WHERE id = $idMama")->fetchColumn());

    echo "\n  pero sí completa un correo que faltaba:\n";
    $pdo->prepare("INSERT INTO clientes (nombre, telefono, es_nueva) VALUES ('TEST SinCorreo','3006662200',0)")->execute();
    $idSin = (int)$pdo->lastInsertId();
    obtenerOCrearCliente('TEST SinCorreo', '3006662200', 'nuevo@correo.com', 0, $x);
    check('rellena el correo vacío', 'nuevo@correo.com',
          $pdo->query("SELECT email FROM clientes WHERE id = $idSin")->fetchColumn());

    echo "\nEL MISMO NOMBRE NO SE TOMA COMO DISTINTO\n";
    foreach (['TEST Mamá', 'test mamá', '  TEST   Mamá  ', 'TEST Mama'] as $variante) {
        obtenerOCrearCliente($variante, $tel, null, 0, $nr);
        check("'$variante' se reconoce como la misma", null, $nr);
    }
    check('un nombre realmente distinto sí se detecta', 'Otra Persona',
          (function () use ($tel) { obtenerOCrearCliente('Otra Persona', $tel, null, 0, $nr); return $nr; })());

    echo "\nLA CITA DEL PANEL ANOTA QUIEN ASISTE\n";
    $html = postA('index.php', ['crear_cita' => '1', 'c_nombre' => 'TEST Hija', 'c_telefono' => $tel,
                                'c_servicio' => '1', 'c_manicurista' => '0',
                                'c_fecha' => date('Y-m-d', strtotime('+3 days')), 'c_hora' => '11:00',
                                'c_estado' => '2', 'c_notas' => 'uñas cortas'], $admin);
    check('la cita se creó', true, str_contains($html, 'Cita creada'));
    check('avisa que el teléfono es de la titular', true, str_contains($html, 'es de TEST Mamá'));
    check('la ficha sigue siendo la de la mamá', 'TEST Mamá',
          $pdo->query("SELECT nombre FROM clientes WHERE id = $idMama")->fetchColumn());

    $cita = $pdo->query("SELECT cliente_id, notas FROM citas WHERE cliente_id = $idMama ORDER BY id DESC LIMIT 1")->fetch();
    check('la cita quedó en la ficha de la mamá', $idMama, (int)$cita['cliente_id']);
    check('anota quién asiste', true, str_contains((string)$cita['notas'], 'Asiste: TEST Hija'));
    check('conserva la nota original', true, str_contains((string)$cita['notas'], 'uñas cortas'));

    $aud = $pdo->query("SELECT detalle FROM citas_auditoria WHERE cita_id =
                        (SELECT MAX(id) FROM citas WHERE cliente_id = $idMama)")->fetchColumn();
    check('queda en la bitácora', true, str_contains((string)$aud, 'a nombre de TEST Hija'));

    echo "\n  y si va la titular, no anota nada raro:\n";
    $html = postA('index.php', ['crear_cita' => '1', 'c_nombre' => 'TEST Mamá', 'c_telefono' => $tel,
                                'c_servicio' => '1', 'c_manicurista' => '0',
                                'c_fecha' => date('Y-m-d', strtotime('+4 days')), 'c_hora' => '12:00',
                                'c_estado' => '2', 'c_notas' => ''], $admin);
    check('no aparece el aviso', false, str_contains($html, 'se conservó su ficha'));
    $notas = $pdo->query("SELECT notas FROM citas WHERE cliente_id = $idMama ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('la cita no lleva "Asiste:"', false, str_contains((string)$notas, 'Asiste:'));

} finally {
    $pdo->exec("DELETE a FROM citas_auditoria a JOIN citas c ON c.id = a.cita_id
                JOIN clientes cl ON cl.id = c.cliente_id WHERE cl.nombre LIKE 'TEST %'");
    $pdo->exec("DELETE c FROM citas c JOIN clientes cl ON cl.id = c.cliente_id WHERE cl.nombre LIKE 'TEST %'");
    $pdo->exec("DELETE FROM clientes WHERE nombre LIKE 'TEST %'");
    echo "\n(datos de prueba eliminados)\n";
}

echo "\n==== $ok ok · $fail fallos ====\n";
exit($fail > 0 ? 1 : 0);
