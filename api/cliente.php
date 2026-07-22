<?php
// Consulta liviana para reconocer una clienta por teléfono en el agendamiento.
// Devuelve los datos ENMASCARADOS (privacidad) y limita la tasa por IP (anti-barrido).
declare(strict_types=1);
require_once __DIR__ . '/../includes/funciones.php';
header('Content-Type: application/json; charset=utf-8');

if (!rateLimit('cliente', 20)) {
    http_response_code(429);
    echo json_encode(['error' => 'Demasiadas consultas, intenta en un momento.']);
    exit;
}

$tel = trim($_GET['telefono'] ?? '');
if ($tel === '') {
    echo json_encode(['existe' => false]);
    exit;
}

// Reconoce el número aunque esté guardado con espacios, guiones o indicativo (+57).
$c = clientePorTelefono($tel);

if (!$c) {
    echo json_encode(['existe' => false]);
    exit;
}

echo json_encode([
    'existe'           => true,
    'nombreMascara'    => enmascararNombre($c['nombre']),
    'tieneEmail'       => !empty($c['email']),
    'emailMascara'     => enmascararEmail($c['email'] ?? ''),
    'tieneDocumento'   => !empty($c['documento']),
    'documentoMascara' => enmascararDocumento($c['documento'] ?? ''),
]);
