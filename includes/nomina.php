<?php
declare(strict_types=1);
require_once __DIR__ . '/funciones.php';

/*
 * Nómina de manicuristas.
 *
 * Se paga por corte quincenal:
 *   comisión  = % propio de la manicurista sobre lo cobrado (neto) de los servicios que atendió
 *   bono      = 120.000 por quincena, menos 8.000 por cada día programado al que no asistió
 *   préstamos = se descuentan del neto en la liquidación del corte
 *
 * Cortes: 1–15 (se paga el 15) y 16–último día del mes (se paga el 30, 31 o el
 * último día de febrero, según el mes).
 */

/** Corte quincenal que contiene una fecha: ['inicio','fin','pago','etiqueta']. */
function corteDe(string $fecha): array {
    $ts  = strtotime($fecha);
    $ano = (int)date('Y', $ts);
    $mes = (int)date('n', $ts);
    $dia = (int)date('j', $ts);
    $ultimo = (int)date('t', $ts);

    if ($dia <= 15) {
        $ini = sprintf('%04d-%02d-01', $ano, $mes);
        $fin = sprintf('%04d-%02d-15', $ano, $mes);
    } else {
        $ini = sprintf('%04d-%02d-16', $ano, $mes);
        $fin = sprintf('%04d-%02d-%02d', $ano, $mes, $ultimo);
    }
    // El día de pago es el último día del corte (15, o 30/31/28-29 en febrero).
    return ['inicio' => $ini, 'fin' => $fin, 'pago' => $fin, 'etiqueta' => etiquetaCorte($ini, $fin)];
}

/** "1–15 de julio 2026" / "16–31 de julio 2026". */
function etiquetaCorte(string $ini, string $fin): string {
    $meses = [1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $ts = strtotime($ini);
    return (int)date('j', $ts) . '–' . (int)date('j', strtotime($fin))
         . ' de ' . ($meses[(int)date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
}

/** Corte anterior al dado. */
function corteAnterior(array $corte): array {
    return corteDe(date('Y-m-d', strtotime($corte['inicio'] . ' -1 day')));
}

/** Los $n cortes más recientes (del actual hacia atrás) para el selector. */
function cortesRecientes(int $n = 8): array {
    $out = [];
    $c = corteDe(date('Y-m-d'));
    for ($i = 0; $i < $n; $i++) {
        $out[] = $c;
        $c = corteAnterior($c);
    }
    return $out;
}

/** Valor del bono completo de la quincena y del día (paramétricas). */
function bonoQuincenal(): int { return (int)config('bono_quincenal', '120000'); }
function bonoValorDia(): int { return (int)config('bono_valor_dia', '8000'); }

/**
 * Lo cobrado (neto, ya con cupones descontados) por los servicios que atendió
 * la manicurista en el período, y cuántos servicios fueron.
 */
function baseServicios(int $manicuristaId, string $ini, string $fin): array {
    $st = db()->prepare('SELECT COALESCE(SUM(monto),0) AS base, COUNT(*) AS servicios
                         FROM cobros
                         WHERE manicurista_id = ? AND fecha_pago BETWEEN ? AND ?');
    $st->execute([$manicuristaId, $ini, $fin]);
    $r = $st->fetch() ?: ['base' => 0, 'servicios' => 0];
    return ['base' => (int)$r['base'], 'servicios' => (int)$r['servicios']];
}

/** % de comisión de la manicurista. */
function porcentajeComision(int $manicuristaId): float {
    $st = db()->prepare('SELECT porcentaje_comision FROM usuarios WHERE id = ?');
    $st->execute([$manicuristaId]);
    $p = $st->fetchColumn();
    return $p === false ? 0.0 : (float)$p;
}

/**
 * Días que la manicurista tenía programado trabajar entre dos fechas: los de su
 * horario semanal, descontando los días en que el local no abre y sumando las
 * aperturas especiales. Si $hasta se indica, no cuenta más allá de esa fecha
 * (para el bono causado a mitad de corte).
 */
function diasProgramados(int $manicuristaId, string $ini, string $fin, ?string $hasta = null): int {
    $disp = disponibilidadUsuario($manicuristaId);
    if ($hasta !== null && $hasta < $fin) $fin = $hasta;
    if ($fin < $ini) return 0;

    $dias = 0;
    $cursor = new DateTimeImmutable($ini);
    $tope   = new DateTimeImmutable($fin);
    while ($cursor <= $tope) {
        $f = $cursor->format('Y-m-d');
        $esp = diaEspecial($f);
        if ($esp) {
            // Cierre del local → no cuenta. Apertura especial → atienden todas.
            if ((int)$esp['abierto'] === 1) $dias++;
        } else {
            $dow = (int)$cursor->format('N');
            if (isset($disp[$dow])) $dias++;   // su horario ya respeta el del local
        }
        $cursor = $cursor->modify('+1 day');
    }
    return $dias;
}

/** Faltas registradas en el período (solo cuentan las de días programados). */
function faltasPeriodo(int $manicuristaId, string $ini, string $fin, ?string $hasta = null): int {
    if ($hasta !== null && $hasta < $fin) $fin = $hasta;
    if ($fin < $ini) return 0;
    $st = db()->prepare('SELECT fecha FROM asistencia
                         WHERE manicurista_id = ? AND asistio = 0 AND fecha BETWEEN ? AND ?');
    $st->execute([$manicuristaId, $ini, $fin]);
    $disp = disponibilidadUsuario($manicuristaId);

    $faltas = 0;
    foreach ($st as $r) {
        $f = $r['fecha'];
        $esp = diaEspecial($f);
        if ($esp) {
            if ((int)$esp['abierto'] === 1) $faltas++;
        } elseif (isset($disp[(int)date('N', strtotime($f))])) {
            $faltas++;
        }
    }
    return $faltas;
}

/** Bono del corte: quincena completa menos el valor del día por cada falta. */
function bonoPorFaltas(int $faltas): int {
    return max(0, bonoQuincenal() - bonoValorDia() * $faltas);
}

/** Saldo vigente de préstamos (lo que aún falta por descontarle). */
function saldoPrestamos(int $manicuristaId): int {
    $st = db()->prepare("SELECT COALESCE(SUM(saldo),0) FROM prestamos
                         WHERE manicurista_id = ? AND estado = 'pendiente'");
    $st->execute([$manicuristaId]);
    return (int)$st->fetchColumn();
}

/** Liquidación ya registrada de un corte, o null. */
function liquidacionRegistrada(int $manicuristaId, string $ini, string $fin): ?array {
    $st = db()->prepare('SELECT * FROM liquidaciones
                         WHERE manicurista_id = ? AND periodo_inicio = ? AND periodo_fin = ?');
    $st->execute([$manicuristaId, $ini, $fin]);
    return $st->fetch() ?: null;
}

/**
 * Cálculo completo del corte para una manicurista. Si el corte ya se liquidó
 * devuelve los valores congelados de la liquidación (para que un cobro
 * registrado después no altere el histórico).
 */
function liquidacionPreview(int $manicuristaId, string $ini, string $fin): array {
    $ya = liquidacionRegistrada($manicuristaId, $ini, $fin);
    if ($ya) {
        return [
            'liquidada'   => true,
            'liquidacion' => $ya,
            'base'        => (int)$ya['base_servicios'],
            'servicios'   => null,
            'porcentaje'  => (float)$ya['porcentaje'],
            'comision'    => (int)$ya['comision'],
            'programados' => (int)$ya['dias_programados'],
            'faltas'      => (int)$ya['dias_falta'],
            'bono'        => (int)$ya['bono'],
            'ajuste'      => (int)$ya['ajuste'],
            'descuento'   => (int)$ya['descuento_prestamos'],
            'saldo_prest' => saldoPrestamos($manicuristaId),
            'neto'        => (int)$ya['neto'],
        ];
    }

    ['base' => $base, 'servicios' => $servicios] = baseServicios($manicuristaId, $ini, $fin);
    $pct         = porcentajeComision($manicuristaId);
    $comision    = (int)round($base * $pct / 100);
    $programados = diasProgramados($manicuristaId, $ini, $fin);
    $faltas      = faltasPeriodo($manicuristaId, $ini, $fin);
    $bono        = bonoPorFaltas($faltas);
    $saldo       = saldoPrestamos($manicuristaId);
    $descuento   = min($saldo, max(0, $comision + $bono));  // no dejar el pago en negativo

    return [
        'liquidada'   => false,
        'liquidacion' => null,
        'base'        => $base,
        'servicios'   => $servicios,
        'porcentaje'  => $pct,
        'comision'    => $comision,
        'programados' => $programados,
        'faltas'      => $faltas,
        'bono'        => $bono,
        'ajuste'      => 0,
        'descuento'   => $descuento,
        'saldo_prest' => $saldo,
        'neto'        => $comision + $bono - $descuento,
    ];
}

/**
 * Cupo de préstamo a una fecha: lo que la manicurista lleva GANADO en el corte
 * (comisión acumulada + bono causado por los días ya trabajados) menos lo que
 * ya se le prestó y sigue pendiente. Nunca negativo.
 */
function cupoPrestamo(int $manicuristaId, ?string $fecha = null): array {
    $fecha = $fecha ?: date('Y-m-d');
    $corte = corteDe($fecha);

    ['base' => $base, 'servicios' => $servicios] = baseServicios($manicuristaId, $corte['inicio'], $fecha);
    $pct      = porcentajeComision($manicuristaId);
    $comision = (int)round($base * $pct / 100);

    // Bono causado: solo por los días programados que ya transcurrieron y a los que sí asistió.
    $progHoy   = diasProgramados($manicuristaId, $corte['inicio'], $corte['fin'], $fecha);
    $faltasHoy = faltasPeriodo($manicuristaId, $corte['inicio'], $corte['fin'], $fecha);
    $bonoCausado = min(bonoQuincenal(), bonoValorDia() * max(0, $progHoy - $faltasHoy));

    $saldo   = saldoPrestamos($manicuristaId);
    $ganado  = $comision + $bonoCausado;
    $cupo    = max(0, $ganado - $saldo);

    return [
        'corte'        => $corte,
        'servicios'    => $servicios,
        'base'         => $base,
        'porcentaje'   => $pct,
        'comision'     => $comision,
        'dias'         => $progHoy,
        'faltas'       => $faltasHoy,
        'bono_causado' => $bonoCausado,
        'ganado'       => $ganado,
        'saldo_prest'  => $saldo,
        'cupo'         => $cupo,
    ];
}

/**
 * Aplica un abono al saldo de préstamos de una manicurista, del más antiguo al
 * más nuevo. Devuelve cuánto se alcanzó a abonar. Debe llamarse dentro de una
 * transacción abierta por quien lo invoca.
 */
function abonarPrestamos(int $manicuristaId, int $monto, string $fecha, ?int $liquidacionId, int $registradoPor): int {
    if ($monto <= 0) return 0;
    $pdo = db();
    $st = $pdo->prepare("SELECT id, saldo FROM prestamos
                         WHERE manicurista_id = ? AND estado = 'pendiente' AND saldo > 0
                         ORDER BY fecha, id");
    $st->execute([$manicuristaId]);
    $pendientes = $st->fetchAll();

    $insAbono = $pdo->prepare('INSERT INTO prestamos_abonos (prestamo_id, monto, fecha, liquidacion_id, registrado_por)
                               VALUES (?,?,?,?,?)');
    // El saldo y el estado se calculan aquí, no dentro del UPDATE: MySQL evalúa las
    // asignaciones de izquierda a derecha, así que un IF() sobre `saldo` leería el valor ya actualizado.
    $updPrest = $pdo->prepare('UPDATE prestamos SET saldo = ?, estado = ? WHERE id = ?');
    $restante = $monto;
    foreach ($pendientes as $p) {
        if ($restante <= 0) break;
        $aplica = (int)min((int)$p['saldo'], $restante);
        $nuevoSaldo = (int)$p['saldo'] - $aplica;
        $insAbono->execute([(int)$p['id'], $aplica, $fecha, $liquidacionId, $registradoPor]);
        $updPrest->execute([$nuevoSaldo, $nuevoSaldo <= 0 ? 'pagado' : 'pendiente', (int)$p['id']]);
        $restante -= $aplica;
    }
    return $monto - $restante;
}

/** Manicuristas activas (para los selectores de los módulos de nómina). */
function manicuristasActivas(): array {
    return db()->query("SELECT id, nombre, porcentaje_comision FROM usuarios
                        WHERE rol = 'manicurista' AND activo = 1 ORDER BY nombre")->fetchAll();
}
