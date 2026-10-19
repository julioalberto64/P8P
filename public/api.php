<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Engine;
use P8P\Core\NodeRegistry;

// Inicializar motor
$engine = new Engine();

// Cargar y registrar automáticamente todos los nodos de src/Nodes/
$registry = new NodeRegistry($engine);
$registry->autoDiscover();

// Procesar solicitud POST
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload JSON inválido']);
    exit;
}

try {
    $result = $engine->executeWorkflow($input);
    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
