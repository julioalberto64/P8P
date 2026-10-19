<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Database;
use P8P\Core\Engine;
use P8P\Nodes\Core\AiAgentNode;
use P8P\Nodes\Core\HttpRequestNode;
use P8P\Nodes\Core\IfConditionNode;
use P8P\Nodes\Core\McpToolNode;
use P8P\Nodes\Core\SetVariableNode;
use P8P\Nodes\Core\StartNode;
use P8P\Nodes\Premium\OllamaChatModelNode;

$workflowId = $_GET['id'] ?? null;

if (!$workflowId) {
    http_response_code(400);
    echo json_encode(['error' => 'Se requiere el parámetro ?id=WORKFLOW_ID']);
    exit;
}

try {
    // 1. Cargar Workflow desde MariaDB
    $config = require __DIR__ . '/../config/database.php';
    $db = Database::getConnection($config);

    $stmt = $db->prepare("SELECT structure, is_active FROM workflows WHERE id = :id");
    $stmt->execute([':id' => $workflowId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Workflow no encontrado']);
        exit;
    }

    if (!$row['is_active']) {
        http_response_code(403);
        echo json_encode(['error' => 'El workflow está desactivado']);
        exit;
    }

    $workflowStructure = json_decode($row['structure'], true);
    $payload = json_decode(file_get_contents('php://input'), true) ?? $_REQUEST;

    // 2. Inicializar el motor y registrar nodos
    $engine = new Engine();
    $engine->registerNode(new StartNode());
    $engine->registerNode(new HttpRequestNode());
    $engine->registerNode(new SetVariableNode());
    $engine->registerNode(new AiAgentNode());
    $engine->registerNode(new OllamaChatModelNode());
    $engine->registerNode(new McpToolNode());
    $engine->registerNode(new IfConditionNode());

    // 3. Inyectar datos del webhook como contexto inicial
    $initialContext = [
        'webhook' => [
            'payload' => $payload,
            'received_at' => date('Y-m-d H:i:s')
        ]
    ];

    $result = $engine->executeWorkflow($workflowStructure, $initialContext);

    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error ejecutando Webhook: ' . $e->getMessage()]);
}
