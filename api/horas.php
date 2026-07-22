<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/funciones.php';
header('Content-Type: application/json; charset=utf-8');

$fecha = $_GET['fecha'] ?? '';
$manicurista = (int)($_GET['manicurista'] ?? 0) ?: null;
$dur = (int)($_GET['dur'] ?? 0);        // bloque en minutos (varios servicios seguidos)
$servicio = (int)($_GET['servicio'] ?? 0);

if (!$fecha || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    echo json_encode([]);
    exit;
}

if ($dur > 0) {
    echo json_encode(array_keys(horasDisponiblesDuracion($fecha, min($dur, 600), $manicurista)));
} elseif ($servicio) {
    echo json_encode(array_keys(horasDisponibles($fecha, $servicio, $manicurista)));
} else {
    echo json_encode([]);
}
