<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

/** Versión del sistema. Se muestra en el login del panel; súbela al publicar cambios. */
const APP_VERSION = '1.1.0';

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function config(string $clave, string $defecto = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT clave, valor FROM configuracion') as $r) {
            $cache[$r['clave']] = $r['valor'];
        }
    }
    return $cache[$clave] ?? $defecto;
}

function precio(float|int|string $n): string {
    return '$' . number_format((float)$n, 0, ',', '.');
}

/** Días de la semana (ISO: 1=Lunes … 7=Domingo, igual que date('N')). */
function diasSemana(): array {
    return [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
}

/** Horario de atención del local por día de la semana (1=Lun..7=Dom); null = cerrado. */
function horarioAtencion(): array {
    $lvI = config('atencion_lv_inicio', '10:00');  $lvF = config('atencion_lv_fin', '19:00');
    $sI  = config('atencion_sab_inicio', '09:00'); $sF  = config('atencion_sab_fin', '19:00');
    return [1 => [$lvI, $lvF], 2 => [$lvI, $lvF], 3 => [$lvI, $lvF], 4 => [$lvI, $lvF], 5 => [$lvI, $lvF], 6 => [$sI, $sF], 7 => null];
}

/** Disponibilidad semanal de un usuario: [dia_semana => ['HH:MM inicio', 'HH:MM fin']]. */
function disponibilidadUsuario(int $usuarioId): array {
    $st = db()->prepare('SELECT dia_semana, hora_inicio, hora_fin FROM disponibilidad WHERE usuario_id = ? ORDER BY dia_semana');
    $st->execute([$usuarioId]);
    $out = [];
    foreach ($st as $r) $out[(int)$r['dia_semana']] = [substr($r['hora_inicio'], 0, 5), substr($r['hora_fin'], 0, 5)];
    return $out;
}

/** Siembra el horario de atención por defecto para una manicurista recién creada. */
function seedDisponibilidad(int $usuarioId): void {
    $st = db()->prepare('INSERT INTO disponibilidad (usuario_id, dia_semana, hora_inicio, hora_fin) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE hora_inicio = VALUES(hora_inicio), hora_fin = VALUES(hora_fin)');
    foreach (horarioAtencion() as $dia => $rango) {
        if ($rango) $st->execute([$usuarioId, $dia, $rango[0], $rango[1]]);
    }
}

/** 'HH:MM[:SS]' → minutos desde medianoche. */
function hm2min(string $hhmm): int {
    return ((int)substr($hhmm, 0, 2)) * 60 + (int)substr($hhmm, 3, 2);
}
/** minutos desde medianoche → 'HH:MM'. */
function min2hm(int $m): string {
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}

/** Duración (min) de un servicio; 60 por defecto si no existe. */
function duracionServicio(int $servicioId): int {
    $sd = db()->prepare('SELECT duracion_min FROM servicios WHERE id = ?');
    $sd->execute([$servicioId]);
    $d = (int)$sd->fetchColumn();
    return $d > 0 ? $d : 60;
}

/** Día especial del local (festivo / apertura especial) para una fecha, o null. */
function diaEspecial(string $fecha): ?array {
    static $cache = [];
    if (!array_key_exists($fecha, $cache)) {
        $st = db()->prepare('SELECT * FROM dias_especiales WHERE fecha = ?');
        $st->execute([$fecha]);
        $cache[$fecha] = $st->fetch() ?: null;
    }
    return $cache[$fecha];
}

/** Próximos días especiales de hoy en adelante (para el banner del sitio). */
function diasEspecialesProximos(int $limite = 4): array {
    $st = db()->prepare('SELECT * FROM dias_especiales WHERE fecha >= ? ORDER BY fecha LIMIT ?');
    $st->bindValue(1, date('Y-m-d'));
    $st->bindValue(2, $limite, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** Fecha en español larga: "domingo 20 de julio". */
function fechaLarga(string $fecha): string {
    $dias  = ['Sunday'=>'domingo','Monday'=>'lunes','Tuesday'=>'martes','Wednesday'=>'miércoles','Thursday'=>'jueves','Friday'=>'viernes','Saturday'=>'sábado'];
    $meses = [1=>'enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $ts = strtotime($fecha);
    return ($dias[date('l', $ts)] ?? '') . ' ' . date('j', $ts) . ' de ' . ($meses[(int)date('n', $ts)] ?? '');
}

/**
 * Rango de atención por manicurista en una fecha, considerando días especiales:
 *  - día especial cerrado → [] (nadie atiende).
 *  - apertura especial     → todas las manicuristas activas en ese horario.
 *  - normal                → su disponibilidad semanal.
 */
function rangoAtencionDia(string $fecha, array $manicuristas): array {
    $esp = diaEspecial($fecha);
    if ($esp) {
        if (!$esp['abierto']) return []; // el local cierra ese día
        $ini = hm2min($esp['hora_inicio']); $fin = hm2min($esp['hora_fin']);
        $rango = [];
        foreach ($manicuristas as $m) $rango[(int)$m] = [$ini, $fin];
        return $rango;
    }
    $pdo = db();
    $dow = (int)date('N', strtotime($fecha));
    $in = implode(',', array_fill(0, count($manicuristas), '?'));
    $st = $pdo->prepare("SELECT usuario_id, hora_inicio, hora_fin FROM disponibilidad
                         WHERE dia_semana = ? AND usuario_id IN ($in)");
    $st->execute(array_merge([$dow], $manicuristas));
    $rango = [];
    foreach ($st as $r) $rango[(int)$r['usuario_id']] = [hm2min($r['hora_inicio']), hm2min($r['hora_fin'])];
    return $rango;
}

/**
 * Carga la ocupación de un conjunto de manicuristas en una fecha.
 * Devuelve [$rango, $ocup, $diaBloqueado]:
 *  - $rango[mId] = [iniMin, finMin] que atiende ese día (respeta días especiales).
 *  - $ocup[mId]  = [[iniMin, finMin], ...] intervalos ocupados (citas + bloqueos parciales).
 *  - $diaBloqueado[mId] = true si tiene bloqueo de todo el día.
 */
function agendaOcupacion(string $fecha, array $manicuristas): array {
    $pdo = db();
    $in = implode(',', array_fill(0, count($manicuristas), '?'));
    $rango = rangoAtencionDia($fecha, $manicuristas);

    $ocup = [];
    $st = $pdo->prepare("SELECT c.manicurista_id, c.hora, s.duracion_min
                         FROM citas c JOIN servicios s ON s.id = c.servicio_id
                         WHERE c.fecha = ? AND c.estado_id != 4 AND c.manicurista_id IN ($in)");
    $st->execute(array_merge([$fecha], $manicuristas));
    foreach ($st as $r) {
        $ini = hm2min($r['hora']);
        $ocup[(int)$r['manicurista_id']][] = [$ini, $ini + (int)$r['duracion_min']];
    }

    $diaBloqueado = [];
    $st = $pdo->prepare("SELECT usuario_id, hora_inicio, hora_fin FROM bloqueos
                         WHERE fecha = ? AND usuario_id IN ($in)");
    $st->execute(array_merge([$fecha], $manicuristas));
    foreach ($st as $r) {
        $mId = (int)$r['usuario_id'];
        if ($r['hora_inicio'] === null || $r['hora_fin'] === null) {
            $diaBloqueado[$mId] = true;
        } else {
            $ocup[$mId][] = [hm2min($r['hora_inicio']), hm2min($r['hora_fin'])];
        }
    }
    return [$rango, $ocup, $diaBloqueado];
}

/**
 * Horas disponibles para un bloque de $durMin minutos en una fecha, para una
 * manicurista (null = cualquiera). El bloque debe caber en el horario de atención
 * y no chocar con citas ni bloqueos. Devuelve ['HH:MM' => manicurista_id].
 */
function horasDisponiblesDuracion(string $fecha, int $durMin, ?int $manicuristaId): array {
    $pdo = db();
    if ($durMin <= 0) $durMin = 60;

    $manicuristas = $manicuristaId
        ? [$manicuristaId]
        : array_column($pdo->query("SELECT id FROM usuarios WHERE rol='manicurista' AND activo=1")->fetchAll(), 'id');
    if (!$manicuristas) return [];

    [$rango, $ocup, $diaBloqueado] = agendaOcupacion($fecha, $manicuristas);
    if (!$rango) return []; // nadie atiende ese día (p. ej. domingo)

    $ahora = ($fecha === date('Y-m-d')) ? hm2min(date('H:i')) : -1;
    $horas = [];
    foreach ($manicuristas as $mId) {
        if (!isset($rango[$mId]) || !empty($diaBloqueado[$mId])) continue;
        [$ini, $fin] = $rango[$mId];
        for ($t = $ini; $t + $durMin <= $fin; $t += 60) {
            if ($t <= $ahora) continue;
            $slot = min2hm($t);
            if (isset($horas[$slot])) continue;
            $libre = true;
            foreach ($ocup[$mId] ?? [] as [$oi, $of]) {
                if ($t < $of && $oi < $t + $durMin) { $libre = false; break; }
            }
            if ($libre) $horas[$slot] = (int)$mId;
        }
    }
    ksort($horas);
    return $horas;
}

/** Horas disponibles para un servicio concreto (usa su duración). */
function horasDisponibles(string $fecha, int $servicioId, ?int $manicuristaId): array {
    return horasDisponiblesDuracion($fecha, duracionServicio($servicioId), $manicuristaId);
}

/** Manicuristas que pueden atender un servicio en una fecha/hora exacta (libres y en horario). */
function manicuristasLibresParaSlot(string $fecha, int $servicioId, string $hora): array {
    $pdo = db();
    $dur = duracionServicio($servicioId);
    $ini = hm2min($hora); $fin = $ini + $dur;

    $manicuristas = array_column($pdo->query("SELECT id FROM usuarios WHERE rol='manicurista' AND activo=1")->fetchAll(), 'id');
    if (!$manicuristas) return [];
    [$rango, $ocup, $diaBloqueado] = agendaOcupacion($fecha, $manicuristas);

    $libres = [];
    foreach ($manicuristas as $mId) {
        if (!isset($rango[$mId]) || !empty($diaBloqueado[$mId])) continue;
        [$ri, $rf] = $rango[$mId];
        if ($ini < $ri || $fin > $rf) continue; // no cabe en su horario
        $ok = true;
        foreach ($ocup[$mId] ?? [] as [$oi, $of]) if ($ini < $of && $oi < $fin) { $ok = false; break; }
        if ($ok) $libres[] = (int)$mId;
    }
    return $libres;
}

/**
 * Asigna una manicurista a un servicio en una fecha/hora, respetando las ya
 * reservadas en la misma reserva ($reservado se va acumulando). $pref = manicurista
 * pedida (null = cualquiera). Devuelve el id asignado o null si no hay cupo.
 */
function asignarManicurista(string $fecha, int $servicioId, string $hora, ?int $pref, array &$reservado): ?int {
    $dur = duracionServicio($servicioId);
    $ini = hm2min($hora); $fin = $ini + $dur;

    $candidatas = [];
    foreach (manicuristasLibresParaSlot($fecha, $servicioId, $hora) as $mId) {
        $choca = false;
        foreach ($reservado as [$rm, $ri, $rf]) if ($rm === $mId && $ini < $rf && $ri < $fin) { $choca = true; break; }
        if (!$choca) $candidatas[] = $mId;
    }
    if ($pref) {
        if (!in_array($pref, $candidatas, true)) return null;
        $mId = $pref;
    } else {
        if (!$candidatas) return null;
        $mId = $candidatas[0];
    }
    $reservado[] = [$mId, $ini, $fin];
    return $mId;
}

/** Código para agrupar las citas de una misma reserva. */
function generarGrupo(): string {
    return 'GRP-' . strtoupper(bin2hex(random_bytes(4)));
}

/** Tipos de clienta para tipificación (usados en el panel). */
function tiposCliente(): array {
    return ['Nueva', 'Frecuente', 'VIP', 'Corporativa', 'Ocasional'];
}

/** Formas de pago aceptadas. */
function formasPago(): array {
    return ['Nequi', 'Daviplata', 'Bancolombia', 'Efectivo', 'Tarjeta'];
}

/**
 * Control de tasa por IP (anti-barrido). Devuelve true si se permite la petición,
 * false si superó $maxPorMinuto en la última ventana de 1 minuto.
 */
function rateLimit(string $endpoint, int $maxPorMinuto): bool {
    $pdo = db();
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    if (random_int(1, 20) === 1) { // limpieza best-effort ocasional
        $pdo->prepare('DELETE FROM rate_limit WHERE creado_en < (NOW() - INTERVAL 5 MINUTE)')->execute();
    }
    $c = $pdo->prepare('SELECT COUNT(*) FROM rate_limit WHERE ip = ? AND endpoint = ? AND creado_en > (NOW() - INTERVAL 1 MINUTE)');
    $c->execute([$ip, $endpoint]);
    if ((int)$c->fetchColumn() >= $maxPorMinuto) return false;
    $pdo->prepare('INSERT INTO rate_limit (ip, endpoint) VALUES (?, ?)')->execute([$ip, $endpoint]);
    return true;
}

/** Enmascara un nombre: "Maria Ovalle" → "M**** O*****". */
function enmascararNombre(string $n): string {
    $partes = preg_split('/\s+/', trim($n)) ?: [];
    $out = array_map(function ($p) {
        $len = mb_strlen($p);
        return $len <= 1 ? $p : mb_substr($p, 0, 1) . str_repeat('*', $len - 1);
    }, array_filter($partes, fn($p) => $p !== ''));
    return implode(' ', $out);
}

/** Enmascara un documento: "1017254321" → "10******21". */
function enmascararDocumento(string $d): string {
    $d = trim($d);
    $len = mb_strlen($d);
    if ($len === 0) return '';
    if ($len <= 4) return mb_substr($d, 0, 1) . str_repeat('*', $len - 1);
    return mb_substr($d, 0, 2) . str_repeat('*', $len - 4) . mb_substr($d, -2);
}

/** Enmascara un correo: "maria@correo.com" → "m****@c*****.com". */
function enmascararEmail(string $e): string {
    $e = trim($e);
    if ($e === '') return '';
    if (!str_contains($e, '@')) return str_repeat('*', mb_strlen($e));
    [$loc, $dom] = explode('@', $e, 2);
    $mask = fn(string $s) => mb_strlen($s) <= 1 ? $s : mb_substr($s, 0, 1) . str_repeat('*', mb_strlen($s) - 1);
    $partesDom = explode('.', $dom);
    $tld = count($partesDom) > 1 ? array_pop($partesDom) : '';
    $nombreDom = implode('.', $partesDom);
    return $mask($loc) . '@' . $mask($nombreDom) . ($tld !== '' ? '.' . $tld : '');
}

/** Dígitos que debe tener el teléfono de una clienta: el número, sin indicativo. */
const TELEFONO_CLIENTE_DIGITOS = 10;

/**
 * Deja un teléfono en solo dígitos: así se guarda el de las clientas.
 * Si viene con el indicativo de Colombia (+57 y 12 dígitos en total) se le quita,
 * porque el número real son los 10 finales: recortarlo al revés guardaría un
 * teléfono equivocado que además parecería válido.
 */
function normalizarTelefono(string $tel): string {
    $d = preg_replace('/\D+/', '', $tel) ?? '';
    if (strlen($d) === 12 && str_starts_with($d, '57')) $d = substr($d, 2);
    return $d;
}

/**
 * Valida el teléfono de una clienta: obligatorio y de exactamente 10 dígitos,
 * sin indicativo. Devuelve el mensaje de error, o null si es válido.
 * (No aplica al personal, que puede tener fijo, extensión, etc.)
 */
function errorTelefonoCliente(string $tel): ?string {
    $d = normalizarTelefono($tel);
    if ($d === '') return 'El teléfono de la clienta es obligatorio.';
    if (strlen($d) !== TELEFONO_CLIENTE_DIGITOS) {
        return 'El teléfono debe tener exactamente ' . TELEFONO_CLIENTE_DIGITOS
             . ' dígitos, sin indicativo (recibí ' . strlen($d) . ').';
    }
    return null;
}

/**
 * Expresión SQL que deja un teléfono en solo dígitos, para poder compararlo
 * sin importar cómo se haya guardado (espacios, guiones, paréntesis, puntos, +57).
 */
function sqlTelefonoNormalizado(string $col = 'telefono'): string {
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col,' ',''),'-',''),'(',''),')',''),'+',''),'.','')";
}

/** Formas equivalentes de un teléfono: con y sin indicativo del país. */
function variantesTelefono(string $telefono): array {
    $d = preg_replace('/\D+/', '', $telefono) ?? '';
    if ($d === '') return [];
    $v = [$d];
    if (str_starts_with($d, '57') && strlen($d) > 10) $v[] = substr($d, 2);   // 573001234567 → 3001234567
    if (strlen($d) === 10) $v[] = '57' . $d;                                   // 3001234567 → 573001234567
    return array_values(array_unique($v));
}

/**
 * Busca una clienta por teléfono tolerando el formato con que quedó guardado.
 * "300 123 4567", "+57 300-123-4567" y "3001234567" reconocen a la misma persona.
 */
function clientePorTelefono(string $telefono): ?array {
    $variantes = variantesTelefono($telefono);
    if (!$variantes) return null;
    $in = implode(',', array_fill(0, count($variantes), '?'));
    $st = db()->prepare('SELECT * FROM clientes WHERE ' . sqlTelefonoNormalizado()
                      . " IN ($in) ORDER BY id DESC LIMIT 1");
    $st->execute($variantes);
    return $st->fetch() ?: null;
}

/** Nombre en forma comparable: sin mayúsculas, tildes ni espacios de más. */
function nombreComparable(string $n): string {
    $n = mb_strtolower(trim($n), 'UTF-8');
    $n = strtr($n, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    return preg_replace('/\s+/', ' ', $n) ?? $n;
}

/**
 * Devuelve el id de la clienta buscándola por teléfono (para no duplicar y poder
 * tipificarla). Si no existe la crea.
 *
 * Si YA existe, su ficha NO se sobrescribe: el nombre guardado manda. El teléfono
 * identifica a la clienta, así que un nombre distinto suele significar que alguien
 * más está usando ese número (la hija con el celular de la mamá) — pisar la ficha
 * renombraría a la titular y mezclaría su historial. En ese caso $nombreReserva
 * devuelve el nombre que se escribió, para que quien llama lo deje anotado en la cita.
 * El correo solo se completa si la ficha no tenía.
 */
function obtenerOCrearCliente(string $nombre, string $telefono, ?string $email, int $esNueva = 0, ?string &$nombreReserva = null): int {
    $pdo = db();
    $nombreReserva = null;
    // Mismo reconocimiento que usan los endpoints: evita duplicar a la clienta
    // solo porque escribió el número con espacios o con indicativo.
    $existente = clientePorTelefono($telefono);
    $id = $existente ? (int)$existente['id'] : 0;
    if ($id) {
        if (nombreComparable($nombre) !== nombreComparable((string)$existente['nombre'])) {
            $nombreReserva = trim($nombre);
        }
        if (empty($existente['email']) && $email) {
            $pdo->prepare('UPDATE clientes SET email = ? WHERE id = ?')->execute([$email, $id]);
        }
        return $id;
    }
    $telefono = normalizarTelefono($telefono);
    try {
        $st = $pdo->prepare('INSERT INTO clientes (nombre, telefono, email, es_nueva) VALUES (?,?,?,?)');
        $st->execute([$nombre, $telefono, $email ?: null, $esNueva]);
        return (int)$pdo->lastInsertId();
    } catch (PDOException $ex) {
        // Choque con la unicidad del teléfono: dos reservas simultáneas con el mismo
        // número nuevo. La otra ya creó la ficha, así que se usa esa en vez de fallar.
        if (($ex->errorInfo[1] ?? 0) !== 1062) throw $ex;
        $otra = clientePorTelefono($telefono);
        if (!$otra) throw $ex;
        return (int)$otra['id'];
    }
}

/** Promociones visibles ahora: el día anterior y el día de la promo (activas). */
function promocionesVisibles(): array {
    $st = db()->prepare('SELECT * FROM promociones WHERE activo = 1 AND fecha BETWEEN ? AND ? ORDER BY fecha, id');
    $st->execute([date('Y-m-d'), date('Y-m-d', strtotime('+1 day'))]);
    return $st->fetchAll();
}

// ─── Utilidades para SEO / URLs absolutas ───

/** Origen del sitio (https://dominio). Usa config('sitio_url') o lo deduce del request. */
function sitioBase(): string {
    $cfg = trim(config('sitio_url', ''));
    if ($cfg !== '') return rtrim($cfg, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** Ruta base de la app (p. ej. "/sns" en local, "" en la raíz del dominio). */
function rutaApp(): string {
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
}

/** URL absoluta a un recurso relativo del sitio. */
function urlAbsoluta(string $rel): string {
    return sitioBase() . rutaApp() . '/' . ltrim($rel, '/');
}

/** URL canónica de la página actual (sin querystring). */
function urlCanonica(): string {
    return sitioBase() . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
}

function generarCodigoPago(): string {
    return 'SNS-' . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * Registra un evento en la bitácora de una cita.
 * $usuarioId = null cuando la acción proviene de la web (clienta).
 * $extra admite: fecha_anterior, hora_anterior, fecha_nueva, hora_nueva, detalle.
 */
function auditarCita(int $citaId, ?int $usuarioId, string $accion, array $extra = []): void {
    db()->prepare(
        'INSERT INTO citas_auditoria
           (cita_id, usuario_id, accion, fecha_anterior, hora_anterior, fecha_nueva, hora_nueva, detalle)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $citaId,
        $usuarioId,
        $accion,
        $extra['fecha_anterior'] ?? null,
        $extra['hora_anterior'] ?? null,
        $extra['fecha_nueva'] ?? null,
        $extra['hora_nueva'] ?? null,
        $extra['detalle'] ?? null,
    ]);
}
