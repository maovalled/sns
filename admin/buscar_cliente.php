<?php
/*
 * Búsqueda de clientas para el panel (agenda interna).
 *
 * A diferencia de `api/cliente.php` —que es público y por eso devuelve los datos
 * ENMASCARADOS—, este endpoint exige sesión de staff y devuelve los datos reales
 * para poder precargar el formulario de nueva cita.
 */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$u = usuarioActual();
if (!$u || !in_array($u['rol'], ['admin', 'recepcion'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'Sesión no válida.']);
    exit;
}

$tel = trim($_GET['telefono'] ?? '');
$q   = trim($_GET['q'] ?? '');

/** Cuenta las citas de una clienta y arma la respuesta. */
function fichaCliente(array $c): array {
    $n = db()->prepare('SELECT COUNT(*) FROM citas WHERE cliente_id = ?');
    $n->execute([$c['id']]);
    return [
        'id'        => (int)$c['id'],
        'nombre'    => $c['nombre'],
        'telefono'  => $c['telefono'],
        'email'     => $c['email'] ?? '',
        'documento' => $c['documento'] ?? '',
        'tipo'      => $c['tipo'] ?? '',
        'validado'  => (bool)$c['validado'],
        'citas'     => (int)$n->fetchColumn(),
    ];
}

// Búsqueda por teléfono (tolera espacios, guiones e indicativo +57)
if ($tel !== '') {
    if ($c = clientePorTelefono($tel)) {
        echo json_encode(['existe' => true, 'cliente' => fichaCliente($c)]);
        exit;
    }
    // Sin coincidencia exacta: si el número va a medias, ofrecer las que empiezan igual.
    $d = preg_replace('/\D+/', '', $tel) ?? '';
    if (strlen($d) >= 3) {
        $st = db()->prepare('SELECT id, nombre, telefono, email FROM clientes WHERE '
                          . sqlTelefonoNormalizado() . ' LIKE ? ORDER BY nombre LIMIT 8');
        $st->execute(["%$d%"]);
        $parciales = $st->fetchAll();
        echo json_encode(['existe' => false, 'parciales' => $parciales]);
        exit;
    }
    echo json_encode(['existe' => false, 'parciales' => []]);
    exit;
}

// Sugerencias por nombre o teléfono parcial (para el datalist)
if (mb_strlen($q) >= 2) {
    $st = db()->prepare('SELECT id, nombre, telefono, email FROM clientes
                         WHERE nombre LIKE ? OR telefono LIKE ?
                         ORDER BY nombre LIMIT 10');
    $st->execute(["%$q%", "%$q%"]);
    echo json_encode(['sugerencias' => $st->fetchAll()]);
    exit;
}

echo json_encode(['existe' => false, 'sugerencias' => []]);
