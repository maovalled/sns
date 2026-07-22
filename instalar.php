<?php
/**
 * Instalador · ejecutar UNA sola vez.
 *
 * Toma las credenciales de config/db.php según el entorno:
 *   · Local      → crea la base si no existe y la puebla.
 *   · Producción → la base ya debe existir (en Hostinger se crea desde hPanel,
 *                  el usuario de MySQL no tiene permiso para crearla); aquí solo
 *                  se crean las tablas y los datos dentro de ella.
 *
 * Cuando termine, ELIMINA este archivo del servidor.
 */
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

$dsnBase   = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
$dsnConBd  = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$opciones  = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
$esLocal   = esEntornoLocal();
$entorno   = $esLocal ? 'local' : 'producción';

// ─── Guardia de seguridad ───
// Una vez que el sistema ya tiene usuarios, este instalador queda deshabilitado.
// Evita que un anónimo lo re-ejecute para crear cuentas con credenciales conocidas.
try {
    $chk = new PDO($dsnConBd, DB_USER, DB_PASS, $opciones);
    $yaInstalado = (int)$chk->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
} catch (Throwable $e) {
    $yaInstalado = false; // La BD o la tabla aún no existen → permitir la instalación inicial.
}
if ($yaInstalado) {
    http_response_code(403);
    exit('El instalador ya fue ejecutado y está deshabilitado por seguridad. '
        . 'Por favor elimina el archivo instalar.php del servidor.');
}

$salida = [];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // ── 1. Conectarse a la base destino, creándola si hace falta y se puede ──
        try {
            $pdo = new PDO($dsnConBd, DB_USER, DB_PASS, $opciones);
        } catch (PDOException $ex) {
            if (!$esLocal) {
                throw new RuntimeException(
                    'No se pudo conectar a la base «' . DB_NAME . '». En el hosting la base se '
                  . 'crea desde el panel (hPanel → Bases de datos MySQL); verifica también el '
                  . 'usuario y la clave en config/db.php.');
            }
            $tmp = new PDO($dsnBase, DB_USER, DB_PASS, $opciones);
            $tmp->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` '
                     . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = new PDO($dsnConBd, DB_USER, DB_PASS, $opciones);
            $salida[] = 'Base de datos <b>' . htmlspecialchars(DB_NAME) . '</b> creada ✔';
        }

        // ── 2. Ejecutar el script completo ──
        // Se quitan el CREATE DATABASE y el USE: ya estamos conectados a la base
        // correcta, y en el hosting se llama distinto (lleva prefijo del usuario).
        $sql = file_get_contents(__DIR__ . '/sql/sns_instalacion_completa.sql');
        if ($sql === false) throw new RuntimeException('No se encontró sql/sns_instalacion_completa.sql');
        $sql = preg_replace('/^\s*(CREATE\s+DATABASE|USE)\s[^;]*;/mi', '', $sql);
        $pdo->exec($sql);

        // Conexión nueva: la anterior queda con el resultado de la consulta final
        // del script sin consumir, y eso bloquearía las siguientes.
        $pdo = new PDO($dsnConBd, DB_USER, DB_PASS, $opciones);

        $tablas = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables
                                    WHERE table_schema = DATABASE()')->fetchColumn();
        $salida[] = "Tablas y datos iniciales creados ✔ <small>($tablas tablas)</small>";

        // ── 3. Clave del admin (opcional) ──
        // El script ya crea al personal con sus claves actuales; esto solo sirve
        // para cambiarle la clave al admin durante la instalación.
        $clave = $_POST['clave_admin'] ?? '';
        if ($clave !== '') {
            if (strlen($clave) < 6) throw new RuntimeException('La clave del admin debe tener mínimo 6 caracteres.');
            $st = $pdo->prepare("UPDATE usuarios SET clave_hash = ? WHERE usuario = 'admin'");
            $st->execute([password_hash($clave, PASSWORD_DEFAULT)]);
            $salida[] = $st->rowCount() > 0
                ? 'Clave del usuario <b>admin</b> establecida ✔'
                : 'No se encontró el usuario admin para cambiarle la clave.';
        } else {
            $salida[] = 'El personal quedó con sus claves actuales.';
        }

        // ── 4. Carpeta de galería ──
        $dir = __DIR__ . '/uploads/galeria';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $salida[] = is_dir($dir) && is_writable($dir)
            ? 'Carpeta uploads/galeria lista ✔'
            : '⚠ Crea la carpeta <code>uploads/galeria</code> con permisos de escritura (755).';

        $salida[] = '<b>Instalación completa.</b> Entra en <a href="admin/login.php">admin/login.php</a> '
                  . '— y <b>borra este archivo instalar.php</b>.';
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Instalador · Sailor Nails Spa</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/estilo.css" rel="stylesheet">
</head>
<body class="bg-crema d-flex align-items-center justify-content-center min-vh-100 p-3">
  <div class="card login-card w-100 p-4 bg-white">
    <h1 class="h4 text-rosa fw-bold">Instalador · Sailor Nails Spa</h1>

    <div class="alert alert-light border py-2 small mb-3">
      Entorno detectado: <b><?= htmlspecialchars($entorno) ?></b><br>
      Base: <code><?= htmlspecialchars(DB_NAME) ?></code> ·
      usuario: <code><?= htmlspecialchars(DB_USER) ?></code> ·
      host: <code><?= htmlspecialchars(DB_HOST) ?></code>
      <?php if (!$esLocal): ?>
        <br><span class="text-muted">La base debe existir ya (se crea desde el panel del hosting).</span>
      <?php endif; ?>
    </div>

    <?php foreach ($salida as $s): ?><div class="alert alert-success py-2"><?= $s ?></div><?php endforeach; ?>
    <?php if ($error): ?><div class="alert alert-danger py-2">Error: <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$salida): ?>
    <form method="post" class="d-flex flex-column gap-3">
      <div>
        <label class="form-label">Clave para el usuario <code>admin</code></label>
        <input type="password" name="clave_admin" class="form-control" minlength="6">
        <small class="text-muted">Opcional: déjala vacía para conservar la clave actual del personal.</small>
      </div>
      <button class="btn btn-sns">Instalar ☾</button>
    </form>
    <?php endif; ?>
  </div>
</body>
</html>
