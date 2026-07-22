<?php
/*
 * Reparación puntual: cobros registrados ANTES de que existiera la suma
 * automática de visitas de fidelidad quedaron sin su visita en la tarjeta.
 * Este script las crea, respetando la fecha del cobro y generando el cupón
 * si con ellas se completa un ciclo.
 *
 *   php sql/reparar_visitas_fidelidad.php          → simulación (no escribe)
 *   php sql/reparar_visitas_fidelidad.php --aplicar → aplica los cambios
 */
declare(strict_types=1);
require __DIR__ . '/../includes/fidelidad.php';

$aplicar = in_array('--aplicar', $argv, true);
$pdo = db();
$p = fidelidadParams();

// Cobros sin visita asociada, de clientas con teléfono, del más antiguo al más nuevo
// (el orden importa: define en qué cobro se completa cada ciclo).
$sql = "SELECT co.id, co.cliente_id, co.fecha_pago, co.monto, cl.nombre, cl.telefono
        FROM cobros co
        JOIN clientes cl ON cl.id = co.cliente_id
        LEFT JOIN visitas_tarjeta v ON v.cobro_id = co.id
        WHERE v.id IS NULL AND TRIM(COALESCE(cl.telefono,'')) <> ''
        ORDER BY co.fecha_pago, co.id";
$pendientes = $pdo->query($sql)->fetchAll();

if (!$pendientes) {
    echo "No hay cobros sin visita: nada que reparar.\n";
    exit;
}

echo ($aplicar ? "APLICANDO" : "SIMULACIÓN (no se escribe nada; usa --aplicar para ejecutar)") . "\n";
echo str_repeat('─', 70) . "\n";

if ($aplicar) $pdo->beginTransaction();
try {
    $creadas = 0; $cupones = 0; $tarjetasNuevas = 0;
    foreach ($pendientes as $c) {
        $tel = trim((string)$c['telefono']);

        // Tarjeta abierta de la clienta (se crea si no tiene)
        $st = $pdo->prepare('SELECT * FROM tarjetas WHERE telefono = ? AND abierta = 1 ORDER BY id DESC LIMIT 1');
        $st->execute([$tel]);
        $t = $st->fetch();
        if (!$t) {
            if ($aplicar) {
                $codigo = strtoupper(bin2hex(random_bytes(2)));
                $pdo->prepare('INSERT INTO tarjetas (telefono, nombre, codigo) VALUES (?,?,?)')
                    ->execute([$tel, $c['nombre'] !== '' ? $c['nombre'] : 'Clienta', $codigo]);
                $st->execute([$tel]);
                $t = $st->fetch();
            } else {
                $t = ['id' => 0, 'codigo' => '(nueva)'];
            }
            $tarjetasNuevas++;
        }

        printf("Cobro #%-3d %s  %-22s %10s → tarjeta #%s\n",
               $c['id'], $c['fecha_pago'], mb_substr($c['nombre'], 0, 22), precio($c['monto']), $t['codigo']);
        $creadas++;

        if (!$aplicar) continue;

        // La visita conserva la fecha del cobro, no la de hoy.
        $pdo->prepare('INSERT INTO visitas_tarjeta (tarjeta_id, fecha, registrado_por, cobro_id) VALUES (?,?,?,?)')
            ->execute([(int)$t['id'], $c['fecha_pago'], 1, (int)$c['id']]);

        // ¿Se completó un ciclo con esta visita?
        $v = $pdo->prepare('SELECT COUNT(*) FROM visitas_tarjeta WHERE tarjeta_id = ?');
        $v->execute([(int)$t['id']]);
        $visitas = (int)$v->fetchColumn();
        $g = $pdo->prepare('SELECT COUNT(*) FROM cupones WHERE tarjeta_id = ?');
        $g->execute([(int)$t['id']]);
        $generados = (int)$g->fetchColumn();

        if (intdiv($visitas, $p['visitas']) > $generados && $generados < $p['max']) {
            $pdo->prepare('INSERT INTO cupones (tarjeta_id, descuento_pct, vence_el) VALUES (?,?,?)')
                ->execute([(int)$t['id'], $p['descuento'],
                           date('Y-m-d', strtotime('+' . $p['vigencia'] . ' days'))]);
            $cupones++;
            echo "    ↳ completó ciclo: cupón de -{$p['descuento']}%\n";
        }
    }
    if ($aplicar) $pdo->commit();

    echo str_repeat('─', 70) . "\n";
    echo ($aplicar ? "Listo: " : "Se crearían: ")
       . "$creadas visita(s), $tarjetasNuevas tarjeta(s) nueva(s), $cupones cupón(es).\n";
    if (!$aplicar) echo "Vuelve a ejecutar con --aplicar para guardar los cambios.\n";

} catch (Throwable $ex) {
    if ($aplicar) $pdo->rollBack();
    echo "ERROR: " . $ex->getMessage() . " (no se aplicó ningún cambio)\n";
    exit(1);
}
