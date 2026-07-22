<?php
/* Lógica de la tarjeta virtual de fidelidad */
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';

function fidelidadParams(): array {
    return [
        'visitas'  => max(1, (int)config('fidelidad_visitas', '5')),
        'descuento'=> (int)config('fidelidad_descuento', '10'),
        'max'      => max(1, (int)config('fidelidad_max_cupones', '2')),
        'vigencia' => max(1, (int)config('fidelidad_vigencia_dias', '30')),
    ];
}

/** Tarjeta abierta por teléfono; la crea si no existe (con nombre). */
function tarjetaAbierta(string $telefono, string $nombre = ''): ?array {
    $pdo = db();
    // Reconoce el número aunque la tarjeta se haya guardado con espacios o con
    // indicativo: si no, la clienta no encontraría su tarjeta en tarjeta.php.
    $variantes = variantesTelefono($telefono);
    if (!$variantes) return null;
    $in = implode(',', array_fill(0, count($variantes), '?'));
    $st = $pdo->prepare('SELECT * FROM tarjetas WHERE ' . sqlTelefonoNormalizado()
                      . " IN ($in) AND abierta = 1 ORDER BY id DESC LIMIT 1");
    $st->execute($variantes);
    $t = $st->fetch();
    if ($t) return $t;
    if ($nombre === '') return null; // solo consulta: no crear
    $codigo = strtoupper(bin2hex(random_bytes(2)));
    $pdo->prepare('INSERT INTO tarjetas (telefono, nombre, codigo) VALUES (?,?,?)')
        ->execute([normalizarTelefono($telefono), $nombre, $codigo]);
    $st->execute($variantes);
    return $st->fetch();
}

/** Visitas y cupones de una tarjeta. */
function datosTarjeta(array $tarjeta): array {
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM visitas_tarjeta WHERE tarjeta_id = ?');
    $st->execute([$tarjeta['id']]);
    $visitas = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT * FROM cupones WHERE tarjeta_id = ? ORDER BY id');
    $st->execute([$tarjeta['id']]);
    $cupones = $st->fetchAll();
    $activos = array_filter($cupones, fn($c) => !$c['usado_en'] && $c['vence_el'] >= date('Y-m-d'));
    return ['visitas' => $visitas, 'cupones' => $cupones, 'activos' => array_values($activos)];
}

/**
 * ¿La reserva de este cobro incluye algún servicio que selle la tarjeta?
 *
 * El cobro se registra por RESERVA (un total por todos los servicios), así que
 * hay que mirar las citas que la componen: basta con que UNA sea de un servicio
 * marcado como `suma_fidelidad` para que la visita cuente. Es un sello por
 * visita, no uno por servicio.
 */
function cobroSumaSello(int $cobroId): bool {
    $st = db()->prepare(
        'SELECT COUNT(*)
           FROM cobros co
           JOIN citas ci ON (co.grupo_id IS NOT NULL AND ci.grupo_id = co.grupo_id)
                         OR (co.grupo_id IS NULL AND ci.id = co.cita_id)
           JOIN servicios s ON s.id = ci.servicio_id
          WHERE co.id = ? AND s.suma_fidelidad = 1 AND ci.estado_id != 4');
    $st->execute([$cobroId]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * Suma una visita a la tarjeta de la clienta a partir de un cobro (crea la tarjeta
 * si no existe). Devuelve el mensaje (incluye si se generó cupón), o '' si no aplica.
 *
 * Solo sellan los servicios semipermanentes y superiores: si la reserva no trae
 * ninguno, no se registra visita ni se crea tarjeta.
 */
function sumarVisitaPorCobro(int $clienteId, int $usuarioId, int $cobroId): string {
    $pdo = db();
    if (!cobroSumaSello($cobroId)) {
        return 'sin sello de fidelidad (el servicio no aplica)';
    }
    $c = $pdo->prepare('SELECT nombre, telefono FROM clientes WHERE id = ?');
    $c->execute([$clienteId]);
    $cl = $c->fetch();
    if (!$cl || trim((string)$cl['telefono']) === '') return '';
    $t = tarjetaAbierta($cl['telefono'], $cl['nombre'] !== '' ? $cl['nombre'] : 'Clienta');
    if (!$t) return '';
    return registrarVisita((int)$t['id'], $usuarioId, $cobroId);
}

/** Registra una visita y genera cupón si completó un ciclo. */
function registrarVisita(int $tarjetaId, int $usuarioId, ?int $cobroId = null): string {
    $pdo = db();
    $p = fidelidadParams();
    $pdo->prepare('INSERT INTO visitas_tarjeta (tarjeta_id, fecha, registrado_por, cobro_id) VALUES (?, CURDATE(), ?, ?)')
        ->execute([$tarjetaId, $usuarioId, $cobroId]);

    $st = $pdo->prepare('SELECT COUNT(*) FROM visitas_tarjeta WHERE tarjeta_id = ?');
    $st->execute([$tarjetaId]);
    $visitas = (int)$st->fetchColumn();

    $st = $pdo->prepare('SELECT COUNT(*) FROM cupones WHERE tarjeta_id = ?');
    $st->execute([$tarjetaId]);
    $generados = (int)$st->fetchColumn();

    $ciclos = intdiv($visitas, $p['visitas']);
    if ($ciclos > $generados && $generados < $p['max']) {
        $vence = date('Y-m-d', strtotime('+' . $p['vigencia'] . ' days'));
        $pdo->prepare('INSERT INTO cupones (tarjeta_id, descuento_pct, vence_el) VALUES (?,?,?)')
            ->execute([$tarjetaId, $p['descuento'], $vence]);
        return "Visita registrada ✔ ¡Completó {$p['visitas']} servicios! Se generó un cupón de -{$p['descuento']}% (vence $vence).";
    }
    return 'Visita registrada ✔';
}

/**
 * Aplica un cupón. NO acumulable: máximo un cupón usado por día por tarjeta.
 * Si tras usarlo la tarjeta agotó sus cupones, se cierra y se genera una nueva.
 */
function aplicarCupon(int $cuponId, int $usuarioId): array {
    $pdo = db();
    $p = fidelidadParams();
    $st = $pdo->prepare('SELECT c.*, t.telefono, t.nombre FROM cupones c JOIN tarjetas t ON t.id = c.tarjeta_id WHERE c.id = ?');
    $st->execute([$cuponId]);
    $c = $st->fetch();
    if (!$c) return [false, 'Cupón no encontrado.'];
    if ($c['usado_en']) return [false, 'Ese cupón ya fue usado.'];
    if ($c['vence_el'] < date('Y-m-d')) return [false, 'Ese cupón está vencido.'];

    // No acumulable: otro cupón de la misma tarjeta usado hoy
    $st = $pdo->prepare('SELECT COUNT(*) FROM cupones WHERE tarjeta_id = ? AND DATE(usado_en) = CURDATE()');
    $st->execute([$c['tarjeta_id']]);
    if ((int)$st->fetchColumn() > 0) {
        return [false, 'Los descuentos no son acumulables: ya se aplicó un cupón de esta tarjeta hoy.'];
    }

    $pdo->prepare('UPDATE cupones SET usado_en = NOW(), usado_por = ? WHERE id = ?')->execute([$usuarioId, $cuponId]);
    $msg = "Cupón de -{$c['descuento_pct']}% aplicado ✔";

    // ¿Renovar tarjeta? (todos los cupones del máximo generados y ninguno vigente sin usar)
    $st = $pdo->prepare("SELECT
        SUM(1) AS generados,
        SUM(CASE WHEN usado_en IS NULL AND vence_el >= CURDATE() THEN 1 ELSE 0 END) AS vigentes
        FROM cupones WHERE tarjeta_id = ?");
    $st->execute([$c['tarjeta_id']]);
    $r = $st->fetch();
    if ((int)$r['generados'] >= $p['max'] && (int)$r['vigentes'] === 0) {
        $pdo->prepare('UPDATE tarjetas SET abierta = 0, cerrada_en = NOW() WHERE id = ?')->execute([$c['tarjeta_id']]);
        $codigo = strtoupper(bin2hex(random_bytes(2)));
        $pdo->prepare('INSERT INTO tarjetas (telefono, nombre, codigo) VALUES (?,?,?)')
            ->execute([$c['telefono'], $c['nombre'], $codigo]);
        $msg .= " La tarjeta se completó: se generó una nueva tarjeta #$codigo ♻";
    }
    return [true, $msg];
}
