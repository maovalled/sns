<?php
// Conexión PDO a MySQL · elige las credenciales según el entorno.
declare(strict_types=1);

// Zona horaria del negocio (Colombia, UTC-5 sin horario de verano).
// El servidor puede correr en UTC; sin esto el agendamiento cree que es más tarde.
date_default_timezone_set('America/Bogota');

// ═══════════════════════════════════════════════════════════════════════
//  CREDENCIALES
// ═══════════════════════════════════════════════════════════════════════

/** Desarrollo: Laragon / XAMPP / WAMP en el equipo local. */
const DB_LOCAL = [
    'host' => 'localhost',
    'port' => '3306',
    'name' => 'sns_principal',
    'user' => 'root',
    'pass' => '',
];

/**
 * Producción: Hostinger.
 * Los datos salen de hPanel → Bases de datos → Administración de bases MySQL.
 * Ojo: allá el nombre de la base y el usuario llevan prefijo (u123456789_...).
 */
const DB_PRODUCCION = [
    'host' => 'localhost',              // en Hostinger casi siempre es localhost
    'port' => '3306',
    'name' => 'u387214240_sns_principal',    // ej: u123456789_sns
    'user' => 'u387214240_root',        // ej: u123456789_admin
    'pass' => '4L3j0.885!',
];

// ═══════════════════════════════════════════════════════════════════════
//  DETECCIÓN DE ENTORNO
// ═══════════════════════════════════════════════════════════════════════

/**
 * ¿Estamos en el equipo local? Se mira, en orden:
 *   1. La variable de entorno SNS_ENTORNO ("local" o "produccion"), que manda
 *      sobre todo lo demás por si hay que forzarlo.
 *   2. El dominio de la petición: localhost, 127.0.0.1, *.local, *.test.
 *   3. Sin petición web (línea de comandos: pruebas, cron) el sistema operativo:
 *      Windows es el equipo de desarrollo; Hostinger es Linux.
 */
function esEntornoLocal(): bool {
    $forzado = getenv('SNS_ENTORNO');
    if (is_string($forzado) && trim($forzado) !== '') {
        return strtolower(trim($forzado)) === 'local';
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = explode(':', $host)[0];              // quitar el puerto
    if ($host !== '') {
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '::1'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }

    return PHP_OS_FAMILY === 'Windows';
}

/** Credenciales del entorno activo. */
function dbConfig(): array {
    return esEntornoLocal() ? DB_LOCAL : DB_PRODUCCION;
}

// Se mantienen las constantes de siempre, por compatibilidad.
$cfgDb = dbConfig();
define('DB_HOST', $cfgDb['host']);
define('DB_PORT', $cfgDb['port']);
define('DB_NAME', $cfgDb['name']);
define('DB_USER', $cfgDb['user']);
define('DB_PASS', $cfgDb['pass']);
unset($cfgDb);

// ═══════════════════════════════════════════════════════════════════════

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // Aviso claro si se publicó sin llenar los datos de Hostinger: sin esto
        // el error sería un "Access denied" difícil de interpretar.
        if (str_starts_with(DB_NAME, 'CAMBIAR') || str_starts_with(DB_USER, 'CAMBIAR')) {
            http_response_code(500);
            exit('Falta configurar la base de datos de producción en config/db.php '
               . '(constante DB_PRODUCCION).');
        }

        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // Que NOW()/CURDATE() en MySQL también usen hora de Colombia (UTC-5).
        // Se usa el desfase y no el nombre de la zona: funciona aunque el
        // servidor no tenga cargadas las tablas de zonas horarias.
        $pdo->exec("SET time_zone = '-05:00'");
    }
    return $pdo;
}
