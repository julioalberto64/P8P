<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

// Encabezados CORS completos (permite Authorization para el Token Bearer)
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

// Manejo de Preflight OPTIONS (Responde de inmediato antes de validar el middleware)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Engine;
use P8P\Core\NodeRegistry;
use P8P\Core\AuthMiddleware;

// Interceptar y proteger el endpoint con el Token Bearer del Admin
AuthMiddleware::check();

try {
    $engine = new Engine();
    $registry = new NodeRegistry($engine);
    $registry->autoDiscover();

    echo json_encode([
        'success' => true,
        'nodes' => $registry->getDiscoveredSchemas()
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error al descubrir nodos: ' . $e->getMessage()
    ]);
}
