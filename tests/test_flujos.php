<?php
// Prueba los manejadores POST reales de prestamos.php y pagos.php.
// Crea datos de prueba, ejecuta los flujos y borra todo al final.
declare(strict_types=1);
require __DIR__ . '/../includes/nomina.php';

$pdo = db();
$ok = 0; $fail = 0;
function check(string $etiqueta, $esperado, $real) {
    global $ok, $fail;
    if ($esperado == $real) { $ok++; echo "  ok   $etiqueta\n"; }
    else { $fail++; echo "  FAIL $etiqueta: esperado [$esperado], obtuve [$real]\n"; }
}

$hoy = date('Y-m-d');
$corte = corteDe($hoy);

/** Ejecuta una página del panel con POST simulado y devuelve su HTML. */
function postA(string $pagina, array $post, array $usuario): string {
    $tmp = sys_get_temp_dir() . '/sns_post_' . bin2hex(random_bytes(4)) . '.php';
    $codigo = sprintf(
        'session_start(); $_SESSION["usuario"] = %s; $_SESSION["csrf"] = "tok"; ' .
        '$_POST = %s; $_POST["_csrf"] = "tok"; $_REQUEST = $_POST; ' .
        '$_SERVER["REQUEST_METHOD"] = "POST"; $_SERVER["SCRIPT_NAME"] = "/sns/admin/%s"; ' .
        'include "C:/laragon/www/sns/admin/%s";',
        var_export($usuario, true), var_export($post, true), $pagina, $pagina
    );
    file_put_contents($tmp, "<?php $codigo");
    $salida = (string)shell_exec('"C:/laragon/bin/php/php-8.1.10-Win32-vs16-x64/php.exe" ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    return $salida;
}

$admin = ['id' => 1, 'nombre' => 'Administradora', 'rol' => 'admin'];
$mid = null; $cid = null;

try {
    // ── Datos de prueba ──
    $pdo->prepare("INSERT INTO usuarios (nombre, usuario, clave_hash, rol, porcentaje_comision)
                   VALUES ('TEST Lorena','test_lorena',?, 'manicurista', 50.00)")
        ->execute([password_hash('x123456', PASSWORD_DEFAULT)]);
    $mid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO disponibilidad (usuario_id, dia_semana, hora_inicio, hora_fin) VALUES (?,?,?,?)');
    foreach ([1,2,3,4,5,6] as $d) $ins->execute([$mid, $d, '10:00', '19:00']);

    $pdo->prepare("INSERT INTO clientes (nombre, telefono) VALUES ('TEST Clienta Flujo','0000000001')")->execute();
    $cid = (int)$pdo->lastInsertId();

    echo "PRESTAMO SIN SERVICIOS (debe rechazarse)\n";
    $html = postA('prestamos.php', ['nuevo' => '1', 'manicurista_id' => (string)$mid, 'monto' => '50000', 'fecha' => $hoy, 'motivo' => 'prueba'], $admin);
    check('rechaza por no tener servicios en el corte', true, str_contains($html, 'no tiene servicios realizados'));
    check('no se creó el préstamo', 0, (int)$pdo->query("SELECT COUNT(*) FROM prestamos WHERE manicurista_id = $mid")->fetchColumn());

    // Ahora sí, un cobro de 200.000 → comisión 50% = 100.000
    $pdo->prepare('INSERT INTO cobros (cliente_id, manicurista_id, monto, forma_pago, fecha_pago, registrado_por)
                   VALUES (?,?,200000,?,?,1)')->execute([$cid, $mid, 'Efectivo', $hoy]);

    $c = cupoPrestamo($mid, $hoy);
    echo "\nCUPO REAL: " . precio($c['cupo']) . " (comisión " . precio($c['comision']) . " + bono causado " . precio($c['bono_causado']) . ")\n";

    echo "\nPRESTAMO POR ENCIMA DEL CUPO (debe rechazarse)\n";
    $exceso = $c['cupo'] + 10000;
    $html = postA('prestamos.php', ['nuevo' => '1', 'manicurista_id' => (string)$mid, 'monto' => (string)$exceso, 'fecha' => $hoy], $admin);
    check('rechaza por superar lo ganado', true, str_contains($html, 'supera lo que lleva ganado'));
    check('sigue sin préstamos', 0, (int)$pdo->query("SELECT COUNT(*) FROM prestamos WHERE manicurista_id = $mid")->fetchColumn());

    echo "\nPRESTAMO CON FECHA FUTURA (debe rechazarse)\n";
    $html = postA('prestamos.php', ['nuevo' => '1', 'manicurista_id' => (string)$mid, 'monto' => '10000', 'fecha' => date('Y-m-d', strtotime('+3 days'))], $admin);
    check('rechaza fecha futura', true, str_contains($html, 'no puede ser futura'));

    echo "\nPRESTAMO DENTRO DEL CUPO (debe aceptarse)\n";
    $html = postA('prestamos.php', ['nuevo' => '1', 'manicurista_id' => (string)$mid, 'monto' => '60000', 'fecha' => $hoy, 'motivo' => 'mercado'], $admin);
    check('acepta el préstamo', true, str_contains($html, 'registrado'));
    check('préstamo creado', 1, (int)$pdo->query("SELECT COUNT(*) FROM prestamos WHERE manicurista_id = $mid")->fetchColumn());
    check('saldo = 60.000', 60000, saldoPrestamos($mid));

    echo "\nEL CUPO YA NO ALCANZA PARA OTRO PRESTAMO GRANDE\n";
    $c2 = cupoPrestamo($mid, $hoy);
    check('cupo bajó en 60.000', $c['cupo'] - 60000, $c2['cupo']);
    $html = postA('prestamos.php', ['nuevo' => '1', 'manicurista_id' => (string)$mid, 'monto' => (string)($c2['cupo'] + 5000), 'fecha' => $hoy], $admin);
    check('segundo préstamo excedido se rechaza', true, str_contains($html, 'supera lo que lleva ganado'));

    echo "\nLIQUIDACION DEL CORTE\n";
    $prev = liquidacionPreview($mid, $corte['inicio'], $corte['fin']);
    echo "  (comisión " . precio($prev['comision']) . " + bono " . precio($prev['bono']) . " − préstamos " . precio($prev['descuento']) . " = " . precio($prev['neto']) . ")\n";
    $html = postA('pagos.php', ['corte' => $corte['inicio'], 'liquidar' => (string)$mid,
                                'descuento' => (string)$prev['descuento'], 'ajuste' => '0', 'nota' => 'prueba'], $admin);
    check('liquidación exitosa', true, str_contains($html, 'Corte liquidado'));

    $liq = liquidacionRegistrada($mid, $corte['inicio'], $corte['fin']);
    check('liquidación guardada', true, $liq !== null);
    check('neto guardado', $prev['neto'], (int)$liq['neto']);
    check('comisión congelada', $prev['comision'], (int)$liq['comision']);
    check('préstamos quedaron en cero', 0, saldoPrestamos($mid));
    check('préstamo marcado pagado', 'pagado', $pdo->query("SELECT estado FROM prestamos WHERE manicurista_id = $mid")->fetchColumn());
    check('pago registrado en caja', $prev['neto'],
          (int)$pdo->query("SELECT monto FROM pagos_manicuristas WHERE liquidacion_id = " . (int)$liq['id'])->fetchColumn());
    check('abono ligado a la liquidación', 1,
          (int)$pdo->query("SELECT COUNT(*) FROM prestamos_abonos WHERE liquidacion_id = " . (int)$liq['id'])->fetchColumn());

    echo "\nNO SE PUEDE LIQUIDAR DOS VECES\n";
    $html = postA('pagos.php', ['corte' => $corte['inicio'], 'liquidar' => (string)$mid, 'descuento' => '0', 'ajuste' => '0'], $admin);
    check('rechaza doble liquidación', true, str_contains($html, 'ya está liquidado'));
    check('sigue habiendo una sola liquidación', 1,
          (int)$pdo->query("SELECT COUNT(*) FROM liquidaciones WHERE manicurista_id = $mid")->fetchColumn());

    echo "\nUN COBRO POSTERIOR NO ALTERA EL CORTE YA LIQUIDADO\n";
    $pdo->prepare('INSERT INTO cobros (cliente_id, manicurista_id, monto, forma_pago, fecha_pago, registrado_por)
                   VALUES (?,?,500000,?,?,1)')->execute([$cid, $mid, 'Nequi', $hoy]);
    $post = liquidacionPreview($mid, $corte['inicio'], $corte['fin']);
    check('el histórico sigue congelado', $prev['comision'], $post['comision']);

    echo "\nDESHACER LIQUIDACION\n";
    $html = postA('pagos.php', ['corte' => $corte['inicio'], 'deshacer' => (string)$liq['id']], $admin);
    check('deshace la liquidación', true, str_contains($html, 'deshecha'));
    check('liquidación eliminada', 0, (int)$pdo->query("SELECT COUNT(*) FROM liquidaciones WHERE manicurista_id = $mid")->fetchColumn());
    check('préstamo volvió a pendiente', 60000, saldoPrestamos($mid));
    check('pago revertido', 0, (int)$pdo->query("SELECT COUNT(*) FROM pagos_manicuristas WHERE manicurista_id = $mid AND liquidacion_id IS NOT NULL")->fetchColumn());

    echo "\nSEGURIDAD: MANICURISTA NO PUEDE PRESTARSE A SI MISMA\n";
    $mani = ['id' => $mid, 'nombre' => 'TEST Lorena', 'rol' => 'manicurista'];
    $antes = (int)$pdo->query("SELECT COUNT(*) FROM prestamos WHERE manicurista_id = $mid")->fetchColumn();
    postA('prestamos.php', ['nuevo' => '1', 'manicurista_id' => (string)$mid, 'monto' => '1000', 'fecha' => $hoy], $mani);
    check('el POST de una manicurista se ignora', $antes,
          (int)$pdo->query("SELECT COUNT(*) FROM prestamos WHERE manicurista_id = $mid")->fetchColumn());

} finally {
    // ── Limpieza (orden inverso a las claves foráneas) ──
    if ($mid) {
        $pdo->exec("DELETE a FROM prestamos_abonos a JOIN prestamos p ON p.id = a.prestamo_id WHERE p.manicurista_id = $mid");
        $pdo->exec("DELETE FROM pagos_manicuristas WHERE manicurista_id = $mid");
        $pdo->exec("DELETE FROM prestamos WHERE manicurista_id = $mid");
        $pdo->exec("DELETE FROM liquidaciones WHERE manicurista_id = $mid");
        $pdo->exec("DELETE FROM asistencia WHERE manicurista_id = $mid");
        $pdo->exec("DELETE FROM cobros WHERE manicurista_id = $mid");
        $pdo->exec("DELETE FROM disponibilidad WHERE usuario_id = $mid");
        $pdo->exec("DELETE FROM usuarios WHERE id = $mid");
    }
    if ($cid) $pdo->exec("DELETE FROM clientes WHERE id = $cid");
    echo "\n(datos de prueba eliminados)\n";
}

echo "\n==== $ok ok · $fail fallos ====\n";
exit($fail > 0 ? 1 : 0);
