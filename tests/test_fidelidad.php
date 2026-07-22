<?php
// Verifica que al registrar un cobro se sume la visita a la tarjeta de fidelidad.
declare(strict_types=1);
require __DIR__ . '/../includes/fidelidad.php';

$pdo = db();
$ok = 0; $fail = 0;
function check(string $etiqueta, $esperado, $real) {
    global $ok, $fail;
    if ($esperado == $real) { $ok++; echo "  ok   $etiqueta\n"; }
    else { $fail++; echo "  FAIL $etiqueta: esperado [" . var_export($esperado, true) . "], obtuve [" . var_export($real, true) . "]\n"; }
}

$tel = '3007776655';
$cid = null;

// Un cobro siempre nace de una cita (así lo hace el panel), y desde julio de 2026
// solo sellan los servicios marcados con `suma_fidelidad`. Se usa uno que sella.
const SERVICIO_QUE_SELLA = 3;   // Manicure semipermanente

/** Crea la cita + el cobro como lo haría el panel y devuelve el id del cobro. */
function cobrarServicio(int $clienteId, int $monto): int {
    $pdo = db();
    $pdo->prepare('INSERT INTO citas (cliente_id, servicio_id, manicurista_id, fecha, hora, estado_id)
                   VALUES (?,?,3,CURDATE(),?,3)')
        ->execute([$clienteId, SERVICIO_QUE_SELLA, sprintf('%02d:00', random_int(9, 18))]);
    $citaId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO cobros (cita_id, cliente_id, manicurista_id, monto, forma_pago, fecha_pago, registrado_por)
                   VALUES (?,?,3,?,?,CURDATE(),1)')
        ->execute([$citaId, $clienteId, $monto, 'Efectivo']);
    return (int)$pdo->lastInsertId();
}

try {
    $pdo->prepare("INSERT INTO clientes (nombre, telefono, es_nueva) VALUES ('TEST Fidelidad', ?, 0)")->execute([$tel]);
    $cid = (int)$pdo->lastInsertId();

    echo "COBRO -> VISITA AUTOMATICA\n";
    $t0 = tarjetaAbierta($tel);
    check('aún no tiene tarjeta', null, $t0);

    // Simula el registro de un cobro tal como lo hace admin/index.php
    $cobroId = cobrarServicio($cid, 100000);
    $msg = sumarVisitaPorCobro($cid, 1, $cobroId);

    check('devuelve mensaje de visita', true, str_contains($msg, 'Visita registrada'));
    $t = tarjetaAbierta($tel);
    check('la tarjeta se creó sola', true, $t !== null);
    $d = datosTarjeta($t);
    check('quedó 1 visita', 1, $d['visitas']);
    $vc = $pdo->prepare('SELECT cobro_id FROM visitas_tarjeta WHERE tarjeta_id = ?');
    $vc->execute([$t['id']]);
    check('la visita quedó ligada al cobro', $cobroId, (int)$vc->fetchColumn());

    echo "\nCICLO COMPLETO GENERA CUPON\n";
    $p = fidelidadParams();
    for ($i = 2; $i <= $p['visitas']; $i++) {
        $msg = sumarVisitaPorCobro($cid, 1, cobrarServicio($cid, 50000));
    }
    $d = datosTarjeta($t);
    check("llegó a {$p['visitas']} visitas", $p['visitas'], $d['visitas']);
    check('se generó el cupón al completar el ciclo', 1, count($d['cupones']));
    check('el mensaje avisa del cupón', true, str_contains($msg, 'cupón'));

    echo "\nCLIENTA SIN TELEFONO NO ROMPE\n";
    $pdo->prepare("INSERT INTO clientes (nombre, telefono, es_nueva) VALUES ('TEST Sin Tel', '', 0)")->execute();
    $cid2 = (int)$pdo->lastInsertId();
    check('no suma visita ni lanza error', '', sumarVisitaPorCobro($cid2, 1, $cobroId));

} finally {
    $pdo->exec("DELETE v FROM visitas_tarjeta v JOIN tarjetas t ON t.id = v.tarjeta_id WHERE t.telefono = '$tel'");
    $pdo->exec("DELETE cu FROM cupones cu JOIN tarjetas t ON t.id = cu.tarjeta_id WHERE t.telefono = '$tel'");
    $pdo->exec("DELETE FROM tarjetas WHERE telefono = '$tel'");
    $pdo->exec("DELETE c FROM cobros c JOIN clientes cl ON cl.id = c.cliente_id WHERE cl.nombre LIKE 'TEST %'");
    $pdo->exec("DELETE ci FROM citas ci JOIN clientes cl ON cl.id = ci.cliente_id WHERE cl.nombre LIKE 'TEST %'");
    $pdo->exec("DELETE FROM clientes WHERE nombre LIKE 'TEST %'");
    echo "\n(datos de prueba eliminados)\n";
}

echo "\n==== $ok ok · $fail fallos ====\n";
exit($fail > 0 ? 1 : 0);
