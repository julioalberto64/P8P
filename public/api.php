<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Engine;
use P8P\Nodes\StartNode;
use P8P\Nodes\HttpRequestNode;
use P8P\Nodes\SetVariableNode; // 1. Importar nodo

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if (empty($input['nodes'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No se recibieron nodos en la solicitud']);
    exit;
}

try {
    $engine = new Engine();

    // 2. Registrar en el motor
    $engine->registerNode(new StartNode());
    $engine->registerNode(new HttpRequestNode());
    $engine->registerNode(new SetVariableNode());

    $result = $engine->executeWorkflow($input);

    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
