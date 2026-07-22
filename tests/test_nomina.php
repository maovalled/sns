<?php
declare(strict_types=1);
require __DIR__ . '/../includes/nomina.php';

$pdo = db();
$ok = 0; $fail = 0;
function check(string $etiqueta, $esperado, $real) {
    global $ok, $fail;
    if ($esperado == $real) { $ok++; echo "  ok   $etiqueta = $real\n"; }
    else { $fail++; echo "  FAIL $etiqueta: esperado $esperado, obtuve $real\n"; }
}

$hoy = date('Y-m-d');
$corte = corteDe($hoy);
echo "Hoy: $hoy · Corte: {$corte['etiqueta']} ({$corte['inicio']} a {$corte['fin']}) · pago {$corte['pago']}\n\n";

// ── Cortes ──
echo "CORTES\n";
check('1 jul -> inicio', '2026-07-01', corteDe('2026-07-01')['inicio']);
check('15 jul -> fin',   '2026-07-15', corteDe('2026-07-15')['fin']);
check('16 jul -> inicio','2026-07-16', corteDe('2026-07-16')['inicio']);
check('31 jul -> fin',   '2026-07-31', corteDe('2026-07-31')['fin']);
check('feb 2026 2a quincena termina el 28', '2026-02-28', corteDe('2026-02-20')['fin']);
check('feb 2028 bisiesto termina el 29',    '2028-02-29', corteDe('2028-02-20')['fin']);
check('abril termina el 30', '2026-04-30', corteDe('2026-04-20')['fin']);

// ── Escenario temporal ──
$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO usuarios (nombre, usuario, clave_hash, rol, telefono, porcentaje_comision)
                   VALUES ('TEST Estefani','test_estefani',?, 'manicurista', NULL, 45.00)")
        ->execute([password_hash('x123456', PASSWORD_DEFAULT)]);
    $mid = (int)$pdo->lastInsertId();

    // Horario lun–sáb
    $ins = $pdo->prepare('INSERT INTO disponibilidad (usuario_id, dia_semana, hora_inicio, hora_fin) VALUES (?,?,?,?)');
    foreach ([1,2,3,4,5,6] as $d) $ins->execute([$mid, $d, '10:00', '19:00']);

    // Clienta de prueba
    $pdo->prepare("INSERT INTO clientes (nombre, telefono) VALUES ('TEST Clienta','0000000000')")->execute();
    $cid = (int)$pdo->lastInsertId();

    // Dos cobros dentro del corte: 100.000 y 200.000 (netos)
    $ic = $pdo->prepare('INSERT INTO cobros (cliente_id, manicurista_id, monto, forma_pago, fecha_pago, registrado_por)
                         VALUES (?,?,?,?,?,1)');
    $ic->execute([$cid, $mid, 100000, 'Efectivo', $corte['inicio']]);
    $ic->execute([$cid, $mid, 200000, 'Nequi',    $hoy]);
    // Un cobro FUERA del corte (no debe contar)
    $ic->execute([$cid, $mid, 999000, 'Efectivo', date('Y-m-d', strtotime($corte['inicio'] . ' -1 day'))]);

    echo "\nCOMISION (45% de 300.000)\n";
    $b = baseServicios($mid, $corte['inicio'], $corte['fin']);
    check('base del corte', 300000, $b['base']);
    check('servicios contados', 2, $b['servicios']);
    check('porcentaje', 45.0, porcentajeComision($mid));
    check('comision', 135000, (int)round($b['base'] * porcentajeComision($mid) / 100));

    echo "\nBONO\n";
    check('bono sin faltas', 120000, bonoPorFaltas(0));
    check('bono con 2 faltas', 104000, bonoPorFaltas(2));
    check('bono con 15 faltas', 0, bonoPorFaltas(15));
    check('bono no baja de 0 con 20 faltas', 0, bonoPorFaltas(20));

    // Marcar 2 faltas en días programados ya transcurridos
    // Días realmente programados: ni domingos ni días especiales cerrados
    // (las aperturas especiales sí cuentan, incluso en domingo).
    $prog = [];
    $cur = new DateTimeImmutable($corte['inicio']);
    $tope = new DateTimeImmutable(min($corte['fin'], $hoy));
    while ($cur <= $tope) {
        $f = $cur->format('Y-m-d');
        $esp = diaEspecial($f);
        if ($esp) {
            if ((int)$esp['abierto'] === 1) $prog[] = $f;
        } elseif ((int)$cur->format('N') !== 7) {
            $prog[] = $f;
        }
        $cur = $cur->modify('+1 day');
    }
    $faltasTest = array_slice($prog, 0, 2);
    $ia = $pdo->prepare('INSERT INTO asistencia (manicurista_id, fecha, asistio, motivo, registrado_por) VALUES (?,?,0,?,1)');
    foreach ($faltasTest as $f) $ia->execute([$mid, $f, 'prueba']);

    check('faltas detectadas en el corte', 2, faltasPeriodo($mid, $corte['inicio'], $corte['fin']));
    check('dias programados hasta hoy', count($prog), diasProgramados($mid, $corte['inicio'], $corte['fin'], $hoy));

    echo "\nDOMINGOS Y DIAS ESPECIALES NO CUENTAN\n";
    // Días programados = días del corte − domingos − días especiales cerrados (+ aperturas especiales en domingo)
    $domingos = 0; $cerrados = 0; $aperturaDomingo = 0;
    $cur = new DateTimeImmutable($corte['inicio']); $tope = new DateTimeImmutable($corte['fin']);
    while ($cur <= $tope) {
        $f = $cur->format('Y-m-d');
        $esDom = (int)$cur->format('N') === 7;
        $esp = diaEspecial($f);
        if ($esDom) $domingos++;
        if ($esp && (int)$esp['abierto'] === 0 && !$esDom) $cerrados++;
        if ($esp && (int)$esp['abierto'] === 1 && $esDom) $aperturaDomingo++;
        $cur = $cur->modify('+1 day');
    }
    $totalDias = (int)((new DateTimeImmutable($corte['fin']))->diff(new DateTimeImmutable($corte['inicio']))->days) + 1;
    echo "  (corte de $totalDias días · $domingos domingo(s) · $cerrados día(s) especial(es) cerrado(s))\n";
    check('programados del corte completo', $totalDias - $domingos - $cerrados + $aperturaDomingo,
          diasProgramados($mid, $corte['inicio'], $corte['fin']));

    // Una falta marcada en un día NO programado (domingo) no debe descontar bono
    $domingoEnCorte = null;
    $cur = new DateTimeImmutable($corte['inicio']);
    while ($cur <= $tope) { if ((int)$cur->format('N') === 7) { $domingoEnCorte = $cur->format('Y-m-d'); break; } $cur = $cur->modify('+1 day'); }
    if ($domingoEnCorte) {
        $ia->execute([$mid, $domingoEnCorte, 'domingo, no debe contar']);
        check('falta en domingo NO descuenta bono', 2, faltasPeriodo($mid, $corte['inicio'], $corte['fin']));
        $pdo->prepare('DELETE FROM asistencia WHERE manicurista_id = ? AND fecha = ?')->execute([$mid, $domingoEnCorte]);
    }

    echo "\nCUPO DE PRESTAMO\n";
    $c = cupoPrestamo($mid, $hoy);
    $bonoCausado = min(120000, 8000 * (count($prog) - 2));
    check('comision acumulada', 135000, $c['comision']);
    check('bono causado', $bonoCausado, $c['bono_causado']);
    check('ganado', 135000 + $bonoCausado, $c['ganado']);
    check('cupo sin prestamos previos', 135000 + $bonoCausado, $c['cupo']);

    // Préstamo que consume parte del cupo
    $pdo->prepare('INSERT INTO prestamos (manicurista_id, monto, saldo, fecha, motivo, autorizado_por) VALUES (?,?,?,?,?,1)')
        ->execute([$mid, 50000, 50000, $hoy, 'prueba']);
    check('saldo prestamos', 50000, saldoPrestamos($mid));
    $c2 = cupoPrestamo($mid, $hoy);
    check('cupo baja por el prestamo', 135000 + $bonoCausado - 50000, $c2['cupo']);

    echo "\nMANICURISTA SIN SERVICIOS -> SIN CUPO\n";
    $pdo->prepare("INSERT INTO usuarios (nombre, usuario, clave_hash, rol, porcentaje_comision)
                   VALUES ('TEST Sin Servicios','test_sinserv',?, 'manicurista', 50.00)")
        ->execute([password_hash('x123456', PASSWORD_DEFAULT)]);
    $mid2 = (int)$pdo->lastInsertId();
    foreach ([1,2,3,4,5,6] as $d) $ins->execute([$mid2, $d, '10:00', '19:00']);
    $c3 = cupoPrestamo($mid2, $hoy);
    check('servicios = 0', 0, $c3['servicios']);
    check('comision = 0', 0, $c3['comision']);

    echo "\nLIQUIDACION Y ABONO DE PRESTAMOS\n";
    $l = liquidacionPreview($mid, $corte['inicio'], $corte['fin']);
    check('comision en liquidacion', 135000, $l['comision']);
    check('bono en liquidacion (2 faltas)', 104000, $l['bono']);
    check('descuento sugerido = saldo', 50000, $l['descuento']);
    check('neto', 135000 + 104000 - 50000, $l['neto']);

    // Segundo préstamo para probar el abono FIFO
    $pdo->prepare('INSERT INTO prestamos (manicurista_id, monto, saldo, fecha, motivo, autorizado_por) VALUES (?,?,?,?,?,1)')
        ->execute([$mid, 30000, 30000, $hoy, 'prueba 2']);
    check('saldo total', 80000, saldoPrestamos($mid));

    $abonado = abonarPrestamos($mid, 60000, $hoy, null, 1);
    check('abonado', 60000, $abonado);
    check('saldo tras abono', 20000, saldoPrestamos($mid));
    $est = $pdo->prepare("SELECT estado, saldo FROM prestamos WHERE manicurista_id = ? ORDER BY id");
    $est->execute([$mid]);
    $rows = $est->fetchAll();
    check('prestamo 1 (mas antiguo) queda pagado', 'pagado', $rows[0]['estado']);
    check('prestamo 2 queda pendiente con 20.000', '20000', $rows[1]['saldo']);

    // Abono mayor al saldo: solo abona lo que hay
    $abonado2 = abonarPrestamos($mid, 999999, $hoy, null, 1);
    check('abono tope al saldo real', 20000, $abonado2);
    check('saldo final', 0, saldoPrestamos($mid));

    echo "\nABONO PARCIAL MAYOR A LA MITAD (no debe darse por pagado)\n";
    // Caso que rompía con IF() dentro del UPDATE: abonar 50.000 sobre 80.000
    // dejaba saldo 30.000 pero marcaba el préstamo como 'pagado', borrando la deuda.
    $pdo->prepare('INSERT INTO prestamos (manicurista_id, monto, saldo, fecha, motivo, autorizado_por) VALUES (?,?,?,?,?,1)')
        ->execute([$mid, 80000, 80000, $hoy, 'abono parcial']);
    $pid = (int)$pdo->lastInsertId();
    abonarPrestamos($mid, 50000, $hoy, null, 1);
    $r = $pdo->query("SELECT saldo, estado FROM prestamos WHERE id = $pid")->fetch();
    check('saldo restante correcto', 30000, (int)$r['saldo']);
    check('sigue PENDIENTE (no pagado)', 'pendiente', $r['estado']);
    check('el saldo sigue contando en la deuda', 30000, saldoPrestamos($mid));

    // Y al abonar el resto sí queda pagado
    abonarPrestamos($mid, 30000, $hoy, null, 1);
    $r2 = $pdo->query("SELECT saldo, estado FROM prestamos WHERE id = $pid")->fetch();
    check('tras abonar el resto queda pagado', 'pagado', $r2['estado']);
    check('saldo en cero', 0, (int)$r2['saldo']);

} finally {
    $pdo->rollBack();   // no dejar rastro en la base
    echo "\n(rollback: los datos de prueba no quedaron guardados)\n";
}

echo "\n==== $ok ok · $fail fallos ====\n";
exit($fail > 0 ? 1 : 0);
