<?php
// Solo los servicios marcados con `suma_fidelidad` sellan la tarjeta.
// El cobro es por reserva, así que se evalúan los servicios de toda la reserva.
declare(strict_types=1);
require __DIR__ . '/../includes/fidelidad.php';

$pdo = db();
$ok = 0; $fail = 0;
function check(string $etiqueta, $esperado, $real) {
    global $ok, $fail;
    if ($esperado == $real) { $ok++; echo "  ok   $etiqueta\n"; }
    else { $fail++; echo "  FAIL $etiqueta: esperado [" . var_export($esperado, true) . "], obtuve [" . var_export($real, true) . "]\n"; }
}

$tel = '3004445566';
$cid = null;

/** Crea una reserva con los servicios dados, la cobra y devuelve el mensaje de fidelidad. */
function cobrar(int $clienteId, array $servicioIds, ?string $grupo = null): string {
    $pdo = db();
    $ins = $pdo->prepare('INSERT INTO citas (cliente_id, servicio_id, manicurista_id, fecha, hora, estado_id, grupo_id)
                          VALUES (?,?,3,CURDATE(),?,3,?)');
    $primera = null; $h = 9;
    foreach ($servicioIds as $sid) {
        $ins->execute([$clienteId, $sid, sprintf('%02d:00', $h++), $grupo]);
        $primera = $primera ?? (int)$pdo->lastInsertId();
    }
    $pdo->prepare('INSERT INTO cobros (grupo_id, cita_id, cliente_id, manicurista_id, monto, forma_pago, fecha_pago, registrado_por)
                   VALUES (?,?,?,3,50000,?,CURDATE(),1)')
        ->execute([$grupo, $grupo ? null : $primera, $clienteId, 'Efectivo']);
    return sumarVisitaPorCobro($clienteId, 1, (int)$pdo->lastInsertId());
}

function visitas(string $tel): int {
    $t = tarjetaAbierta($tel);
    return $t ? datosTarjeta($t)['visitas'] : 0;
}

try {
    echo "LA MARCA ESTA EN EL CATALOGO\n";
    $sellan = $pdo->query('SELECT COUNT(*) FROM servicios WHERE suma_fidelidad = 1 AND activo = 1')->fetchColumn();
    $no = $pdo->query('SELECT COUNT(*) FROM servicios WHERE suma_fidelidad = 0 AND activo = 1')->fetchColumn();
    check('hay servicios que sellan', true, (int)$sellan > 0);
    check('y servicios que no', true, (int)$no > 0);
    foreach ([3 => 'Manicure semipermanente', 11 => 'Pedicure semipermanente',
              5 => 'Manicure soft gel o press-on', 6 => 'Recubrimiento polygel'] as $id => $n) {
        check("«{$n}» sella", 1, (int)$pdo->query("SELECT suma_fidelidad FROM servicios WHERE id=$id")->fetchColumn());
    }
    foreach ([1 => 'Manicure tradicional', 9 => 'Pedicure tradicional',
              7 => 'Retiro de semipermanente', 14 => 'Depilación de bigote',
              22 => 'Shampoo'] as $id => $n) {
        check("«{$n}» NO sella", 0, (int)$pdo->query("SELECT suma_fidelidad FROM servicios WHERE id=$id")->fetchColumn());
    }

    $pdo->prepare("INSERT INTO clientes (nombre, telefono, es_nueva) VALUES ('TEST Sello', ?, 0)")->execute([$tel]);
    $cid = (int)$pdo->lastInsertId();

    echo "\nSERVICIO QUE NO SELLA → NI SIQUIERA CREA TARJETA\n";
    $msg = cobrar($cid, [1]);   // Manicure tradicional
    check('avisa que no aplica', true, str_contains($msg, 'sin sello'));
    check('no se creó tarjeta', null, tarjetaAbierta($tel));
    check('cero visitas', 0, visitas($tel));

    echo "\nSERVICIO SEMIPERMANENTE → SELLA\n";
    $msg = cobrar($cid, [3]);   // Manicure semipermanente
    check('registra la visita', true, str_contains($msg, 'Visita registrada'));
    check('la tarjeta se creó', true, tarjetaAbierta($tel) !== null);
    check('1 visita', 1, visitas($tel));

    echo "\nOTRO SERVICIO QUE NO SELLA → LA TARJETA NO AVANZA\n";
    $msg = cobrar($cid, [9]);   // Pedicure tradicional
    check('no aplica', true, str_contains($msg, 'sin sello'));
    check('sigue en 1 visita', 1, visitas($tel));

    echo "\nRESERVA MIXTA → UN SOLO SELLO\n";
    // Tradicional + semipermanente + depilación en la misma reserva: 1 visita, no 3.
    $msg = cobrar($cid, [1, 3, 14], 'GRP-TESTMIX');
    check('registra la visita', true, str_contains($msg, 'Visita registrada'));
    check('suma UNA sola visita, no una por servicio', 2, visitas($tel));

    echo "\nRESERVA SOLO DE SERVICIOS QUE NO SELLAN\n";
    $msg = cobrar($cid, [1, 9, 22], 'GRP-TESTNO');
    check('no aplica', true, str_contains($msg, 'sin sello'));
    check('sigue en 2 visitas', 2, visitas($tel));

    echo "\nUNA CITA CANCELADA NO ARRASTRA EL SELLO\n";
    // La reserva trae un semipermanente pero cancelado, más un tradicional atendido.
    $ins = $pdo->prepare('INSERT INTO citas (cliente_id, servicio_id, manicurista_id, fecha, hora, estado_id, grupo_id)
                          VALUES (?,?,3,CURDATE(),?,?,?)');
    $ins->execute([$cid, 3, '15:00', 4, 'GRP-TESTCAN']);   // semipermanente CANCELADA
    $ins->execute([$cid, 1, '16:00', 3, 'GRP-TESTCAN']);   // tradicional atendida
    $pdo->prepare('INSERT INTO cobros (grupo_id, cliente_id, manicurista_id, monto, forma_pago, fecha_pago, registrado_por)
                   VALUES (?,?,3,23000,?,CURDATE(),1)')->execute(['GRP-TESTCAN', $cid, 'Efectivo']);
    $msg = sumarVisitaPorCobro($cid, 1, (int)$pdo->lastInsertId());
    check('no sella por una cita cancelada', true, str_contains($msg, 'sin sello'));
    check('sigue en 2 visitas', 2, visitas($tel));

    echo "\nCAMBIAR LA BANDERA CAMBIA EL COMPORTAMIENTO\n";
    $pdo->exec('UPDATE servicios SET suma_fidelidad = 1 WHERE id = 1');   // tradicional pasa a sellar
    $msg = cobrar($cid, [1]);
    check('ahora sí sella', true, str_contains($msg, 'Visita registrada'));
    check('3 visitas', 3, visitas($tel));
    $pdo->exec('UPDATE servicios SET suma_fidelidad = 0 WHERE id = 1');   // se deja como estaba

    echo "\nEL CUPON SIGUE SALIENDO AL COMPLETAR EL CICLO\n";
    $p = fidelidadParams();
    while (visitas($tel) < $p['visitas'] - 1) cobrar($cid, [3]);
    check("va en " . ($p['visitas'] - 1) . " visitas", $p['visitas'] - 1, visitas($tel));
    $msg = cobrar($cid, [3]);
    check('la visita que completa el ciclo genera cupón', true, str_contains($msg, 'cupón'));
    $t = tarjetaAbierta($tel);
    check('el cupón quedó en la tarjeta', 1, count(datosTarjeta($t)['cupones']));

} finally {
    $pdo->exec('UPDATE servicios SET suma_fidelidad = 0 WHERE id = 1');
    $pdo->exec("DELETE cu FROM cupones cu JOIN tarjetas t ON t.id = cu.tarjeta_id WHERE t.telefono = '$tel'");
    $pdo->exec("DELETE v FROM visitas_tarjeta v JOIN tarjetas t ON t.id = v.tarjeta_id WHERE t.telefono = '$tel'");
    $pdo->exec("DELETE FROM tarjetas WHERE telefono = '$tel'");
    $pdo->exec("DELETE co FROM cobros co JOIN clientes cl ON cl.id = co.cliente_id WHERE cl.nombre LIKE 'TEST %'");
    $pdo->exec("DELETE ci FROM citas ci JOIN clientes cl ON cl.id = ci.cliente_id WHERE cl.nombre LIKE 'TEST %'");
    $pdo->exec("DELETE FROM clientes WHERE nombre LIKE 'TEST %'");
    echo "\n(datos de prueba eliminados)\n";
}

echo "\n==== $ok ok · $fail fallos ====\n";
exit($fail > 0 ? 1 : 0);
