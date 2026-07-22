<?php
declare(strict_types=1);

// ─── Sesión endurecida (antes de iniciarla) ───
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,     // el cookie no es accesible desde JS (mitiga robo por XSS)
        'secure'   => $https,    // solo por HTTPS cuando esté disponible
        'samesite' => 'Lax',    // el navegador no envía el cookie en POST cross-site (mitiga CSRF)
    ]);
    session_start();
}
require_once __DIR__ . '/funciones.php';

// ─── Protección CSRF ───
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Campo oculto para incrustar el token en cualquier formulario POST. */
function csrfField(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
}

/** Verifica el token; corta la petición si no coincide (falla en cerrado). */
function verificarCsrf(): void {
    $enviado = $_POST['_csrf'] ?? '';
    if (!is_string($enviado) || $enviado === '' || !hash_equals(csrfToken(), $enviado)) {
        http_response_code(419);
        exit('<div style="font-family:sans-serif;padding:40px">'
            . 'Solicitud no válida o sesión expirada. <a href="javascript:history.back()">Volver</a></div>');
    }
}

// Toda petición POST hacia el panel (que siempre incluye este archivo) exige token CSRF.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verificarCsrf();
}

function usuarioActual(): ?array {
    $u = $_SESSION['usuario'] ?? null;
    // Sesiones viejas u objetos de otra app en la misma sesión → ignorar
    if (!is_array($u) || !isset($u['id'], $u['rol'])) {
        unset($_SESSION['usuario']);
        return null;
    }
    return $u;
}

/** Exige sesión iniciada; opcionalmente restringe por roles. */
function requerirLogin(array $roles = []): array {
    $u = usuarioActual();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    if ($roles && !in_array($u['rol'], $roles, true)) {
        http_response_code(403);
        exit('<div style="font-family:sans-serif;padding:40px">403 · No tienes permiso para esta sección. <a href="index.php">Volver</a></div>');
    }
    return $u;
}

function puedeVer(string $modulo): bool {
    $rol = usuarioActual()['rol'] ?? '';
    $permisos = [
        'admin'       => ['citas','clientes','bloqueos','dias_especiales','promociones','personal','asistencia','prestamos','pagos','inventario','galeria','parametricas'],
        'recepcion'   => ['citas','clientes','bloqueos','dias_especiales','promociones','inventario','galeria'],
        'manicurista' => ['citas','prestamos','pagos'],
    ];
    return in_array($modulo, $permisos[$rol] ?? [], true);
}
