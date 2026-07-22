<?php
// El teléfono de CLIENTA es obligatorio y de exactamente 10 dígitos (sin indicativo),
// en todos los formularios donde se captura.
// El del personal no tiene esa restricción.
declare(strict_types=1);
require __DIR__ . '/../includes/fidelidad.php';

$pdo = db();
$ok = 0; $fail = 0;
function check(string $etiqueta, $esperado, $real) {
    global $ok, $fail;
    if ($esperado == $real) { $ok++; echo "  ok   $etiqueta\n"; }
    else { $fail++; echo "  FAIL $etiqueta: esperado [" . var_export($esperado, true) . "], obtuve [" . var_export($real, true) . "]\n"; }
}

function postA(string $pagina, array $post, ?array $usuario, bool $publico = false): string {
    $tmp = sys_get_temp_dir() . '/sns_tel_' . bin2hex(random_bytes(4)) . '.php';
    $ruta = $publico ? "C:/laragon/www/sns/$pagina" : "C:/laragon/www/sns/admin/$pagina";
    file_put_contents($tmp, sprintf(
        '<?php session_start(); %s $_SESSION["csrf"] = "tok"; $_POST = %s; $_POST["_csrf"] = "tok"; ' .
        '$_REQUEST = $_POST; $_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["REMOTE_ADDR"] = "127.0.0.1"; ' .
        '$_SERVER["SCRIPT_NAME"] = "/sns/%s"; include "%s";',
        $usuario ? '$_SESSION["usuario"] = ' . var_export($usuario, true) . ';' : '',
        var_export($post, true), $pagina, $ruta));
    $out = (string)shell_exec('"C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    return $out;
}

$admin = ['id' => 1, 'nombre' => 'Administradora', 'rol' => 'admin'];

try {
    echo "LA REGLA EN SI\n";
    check('vacío → obligatorio', true, str_contains((string)errorTelefonoCliente(''), 'obligatorio'));
    check('solo espacios → obligatorio', true, str_contains((string)errorTelefonoCliente('   '), 'obligatorio'));
    check('sin dígitos → obligatorio', true, str_contains((string)errorTelefonoCliente('abc-def'), 'obligatorio'));
    check('10 dígitos → válido', null, errorTelefonoCliente('3009998877'));
    check('10 dígitos con formato → válido', null, errorTelefonoCliente('300 999-8877'));
    check('11 dígitos → rechaza', true, str_contains((string)errorTelefonoCliente('30099988770'), 'exactamente 10'));
    check('13 dígitos → rechaza', true, str_contains((string)errorTelefonoCliente('3009998877000'), 'exactamente 10'));

    echo "\n  ahora son EXACTAMENTE 10: los cortos también se rechazan\n";
    check('3 dígitos → rechaza', true, str_contains((string)errorTelefonoCliente('300'), 'exactamente 10'));
    check('9 dígitos → rechaza', true, str_contains((string)errorTelefonoCliente('300999887'), 'exactamente 10'));
    check('el mensaje dice cuántos recibió', true, str_contains((string)errorTelefonoCliente('300'), 'recibí 3'));
    check('el mensaje aclara que es sin indicativo', true, str_contains((string)errorTelefonoCliente('300'), 'sin indicativo'));

    echo "\n  el indicativo +57 se entiende, no se recorta al revés:\n";
    check('+57 se acepta', null, errorTelefonoCliente('+573009998877'));
    check('y queda el número real de 10', '3009998877', normalizarTelefono('+573009998877'));
    check('con espacios también', '3009998877', normalizarTelefono('+57 300 999 8877'));
    check('12 dígitos que NO son +57 se rechazan', true,
          str_contains((string)errorTelefonoCliente('123456789012'), 'exactamente 10'));

    echo "\n  normalización de lo que se guarda:\n";
    check('quita espacios y guiones', '3009998877', normalizarTelefono('300 999-8877'));
    check('quita paréntesis y signos', '3009998877', normalizarTelefono('(300) 999.8877'));
    check('deja vacío si no hay dígitos', '', normalizarTelefono('sin numero'));

    echo "\nALTA DE CLIENTA (admin/clientes.php)\n";
    $html = postA('clientes.php', ['nueva' => '1', 'nombre' => 'TEST Larga', 'telefono' => '30099988770'], $admin);
    check('rechaza 11 dígitos', true, str_contains($html, 'exactamente 10'));
    check('no la guardó', 0, (int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE nombre='TEST Larga'")->fetchColumn());

    $html = postA('clientes.php', ['nueva' => '1', 'nombre' => 'TEST Sin Tel', 'telefono' => ''], $admin);
    check('exige el teléfono', true, str_contains($html, 'obligatorio'));

    $html = postA('clientes.php', ['nueva' => '1', 'nombre' => 'TEST Valida', 'telefono' => '300 999-8877'], $admin);
    check('acepta 10 dígitos con formato', true, str_contains($html, 'registrada'));
    check('lo guardó en solo dígitos', '3009998877',
          $pdo->query("SELECT telefono FROM clientes WHERE nombre='TEST Valida'")->fetchColumn());

    echo "\nEDICION DE CLIENTA\n";
    $cid = (int)$pdo->query("SELECT id FROM clientes WHERE nombre='TEST Valida'")->fetchColumn();
    $html = postA('clientes.php', ['guardar' => '1', 'cliente_id' => (string)$cid,
                                   'nombre' => 'TEST Valida', 'telefono' => '30012345678'], $admin);
    check('rechaza 11 dígitos al editar', true, str_contains($html, 'exactamente 10'));
    check('el teléfono no cambió', '3009998877',
          $pdo->query("SELECT telefono FROM clientes WHERE id=$cid")->fetchColumn());

    // No debe poder quedarse con el teléfono de otra clienta
    $pdo->prepare("INSERT INTO clientes (nombre, telefono, es_nueva) VALUES ('TEST Otra','3011112233',0)")->execute();
    $html = postA('clientes.php', ['guardar' => '1', 'cliente_id' => (string)$cid,
                                   'nombre' => 'TEST Valida', 'telefono' => '3011112233'], $admin);
    check('rechaza tomar el teléfono de otra', true, str_contains($html, 'ya es de otra clienta'));
    $html = postA('clientes.php', ['guardar' => '1', 'cliente_id' => (string)$cid,
                                   'nombre' => 'TEST Valida', 'telefono' => '3009998877'], $admin);
    check('sí deja guardar con su MISMO teléfono', true, str_contains($html, 'actualizados'));

    echo "\nAL CAMBIAR EL TELEFONO, LA TARJETA SE MUEVE CON ELLA\n";
    // Si la tarjeta se quedara con el número viejo, el siguiente cobro le crearía
    // una tarjeta nueva y perdería sus visitas.
    $pdo->prepare("INSERT INTO tarjetas (telefono, nombre, codigo) VALUES ('3009998877','TEST Valida','TST1')")->execute();
    $tid = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO visitas_tarjeta (tarjeta_id, fecha, registrado_por) VALUES (?,CURDATE(),1)')->execute([$tid]);

    $html = postA('clientes.php', ['guardar' => '1', 'cliente_id' => (string)$cid,
                                   'nombre' => 'TEST Valida', 'telefono' => '3007776655'], $admin);
    check('avisa que movió la tarjeta', true, str_contains($html, 'tarjeta de fidelidad se movió'));
    check('la tarjeta quedó con el número nuevo', '3007776655',
          $pdo->query("SELECT telefono FROM tarjetas WHERE id=$tid")->fetchColumn());
    check('conservó su visita', 1,
          (int)$pdo->query("SELECT COUNT(*) FROM visitas_tarjeta WHERE tarjeta_id=$tid")->fetchColumn());
    check('y se encuentra con el número nuevo', 'TST1',
          (tarjetaAbierta('3007776655')['codigo'] ?? null));
    check('ya no aparece con el viejo', null, tarjetaAbierta('3009998877'));

    // Guardar sin cambiar el teléfono no debe tocar la tarjeta
    $html = postA('clientes.php', ['guardar' => '1', 'cliente_id' => (string)$cid,
                                   'nombre' => 'TEST Valida', 'telefono' => '3007776655'], $admin);
    check('sin cambio de teléfono no avisa nada de la tarjeta', false, str_contains($html, 'se movió'));

    echo "\nNUEVA CITA EN EL PANEL (admin/index.php)\n";
    $html = postA('index.php', ['crear_cita' => '1', 'c_nombre' => 'TEST Cita', 'c_telefono' => '30099988770',
                                'c_servicio' => '1', 'c_fecha' => date('Y-m-d', strtotime('+2 days')), 'c_hora' => '11:00'], $admin);
    check('rechaza 11 dígitos', true, str_contains($html, 'exactamente 10'));
    check('no creó la clienta', 0, (int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE nombre='TEST Cita'")->fetchColumn());

    echo "\nAGENDAMIENTO PUBLICO (agendar.php)\n";
    $html = postA('agendar.php', ['servicios' => ['1'], 'modo' => 'misma', 'nombre' => 'TEST Publica',
                                  'telefono' => '30099900001', 'fecha' => date('Y-m-d', strtotime('+2 days')),
                                  'hora' => '11:00'], null, true);
    check('rechaza 11 dígitos', true, str_contains($html, 'exactamente 10'));
    check('no creó la clienta', 0, (int)$pdo->query("SELECT COUNT(*) FROM clientes WHERE nombre='TEST Publica'")->fetchColumn());

    echo "\nEL PERSONAL NO TIENE ESA RESTRICCION\n";
    $html = postA('personal.php', ['nuevo' => '1', 'nombre' => 'TEST Empleada', 'usuario' => 'test_emp',
                                   'clave' => 'clave123', 'rol' => 'recepcion', 'telefono' => '+57 (601) 555 4433 ext 12'], $admin);
    check('acepta el teléfono largo del personal', true, str_contains($html, 'agregado'));
    check('lo guardó tal cual', '+57 (601) 555 4433 ext 12',
          $pdo->query("SELECT telefono FROM usuarios WHERE usuario='test_emp'")->fetchColumn());

    echo "\nLOS CAMPOS DEL FORMULARIO ESTAN MARCADOS\n";
    foreach ([['agendar.php', true], ['tarjeta.php', true]] as [$pag, $pub]) {
        $h = file_get_contents("C:/laragon/www/sns/$pag");
        check("$pag tiene el campo marcado", true, str_contains($h, 'data-telefono-cliente'));
        check("$pag lo exige", true, str_contains($h, 'required'));
    }
    foreach (['clientes.php', 'index.php'] as $pag) {
        $h = file_get_contents("C:/laragon/www/sns/admin/$pag");
        check("admin/$pag tiene el campo marcado", true, str_contains($h, 'data-telefono-cliente'));
    }
    $h = file_get_contents('C:/laragon/www/sns/admin/clientes.php');
    check('clientes.php marca los DOS campos (alta y edición)', 2, substr_count($h, 'data-telefono-cliente'));
    $h = file_get_contents('C:/laragon/www/sns/admin/personal.php');
    check('personal.php NO quedó marcado', false, str_contains($h, 'data-telefono-cliente'));

} finally {
    $pdo->exec("DELETE v FROM visitas_tarjeta v JOIN tarjetas t ON t.id=v.tarjeta_id WHERE t.nombre LIKE 'TEST %'");
    $pdo->exec("DELETE FROM tarjetas WHERE nombre LIKE 'TEST %'");
    $pdo->exec("DELETE ci FROM citas ci JOIN clientes cl ON cl.id=ci.cliente_id WHERE cl.nombre LIKE 'TEST %'");
    $pdo->exec("DELETE FROM clientes WHERE nombre LIKE 'TEST %'");
    $pdo->exec("DELETE FROM usuarios WHERE usuario = 'test_emp'");
    $pdo->exec("DELETE FROM rate_limit WHERE ip = '127.0.0.1'");
    echo "\n(datos de prueba eliminados)\n";
}

echo "\n==== $ok ok · $fail fallos ====\n";
exit($fail > 0 ? 1 : 0);
