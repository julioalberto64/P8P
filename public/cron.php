<?php

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Engine;
use P8P\Core\QueueManager;
use P8P\Core\Database;
use P8P\Nodes\StartNode;
use P8P\Nodes\SetVariableNode;
use P8P\Nodes\IfConditionNode;
use P8P\Nodes\HttpRequestNode;
use P8P\Nodes\AiAgentNode;
use P8P\Nodes\GeminiChatModelNode;
use P8P\Nodes\OllamaChatModelNode;
use P8P\Nodes\McpToolNode;

// Inicializar motor y registrar nodos
$engine = new Engine();
$engine->registerNode(new StartNode());
$engine->registerNode(new SetVariableNode());
$engine->registerNode(new IfConditionNode());
$engine->registerNode(new HttpRequestNode());
$engine->registerNode(new AiAgentNode());
$engine->registerNode(new GeminiChatModelNode());
$engine->registerNode(new OllamaChatModelNode());
$engine->registerNode(new McpToolNode());

$queueManager = new QueueManager();

try {
    // Procesar hasta 5 trabajos pendientes por lote
    $jobs = $queueManager->getPendingJobs(5);

    if (empty($jobs)) {
        if (php_sapi_name() === 'cli') {
            echo "[" . date('Y-m-d H:i:s') . "] No hay trabajos pendientes en la cola.\n";
        } else {
            header("Content-Type: application/json");
            echo json_encode(['message' => 'No hay trabajos pendientes en la cola']);
        }
        exit;
    }

    $processedCount = 0;

    foreach ($jobs as $job) {
        $jobId = $job['id'];
        $payload = json_decode($job['payload'], true) ?? [];

        // 1. Si el payload contiene un workflow_id pero no los nodos directamente, cargarlo de MariaDB
        if (empty($payload['nodes']) && !empty($job['workflow_id'])) {
            $config = require __DIR__ . '/../config/database.php';
            $db = Database::getConnection($config);
            $stmt = $db->prepare("SELECT structure FROM workflows WHERE id = :id");
            $stmt->execute([':id' => $job['workflow_id']]);
            $row = $stmt->fetch();

            if ($row) {
                $payload = json_decode($row['structure'], true);
            }
        }

        // 2. NORMALIZACIÓN DE NODOS: Mapear 'type' desde 'data.type' si viene como 'customNode'
        if (!empty($payload['nodes'])) {
            $normalizedNodes = [];
            foreach ($payload['nodes'] as $node) {
                $realType = $node['data']['type'] ?? $node['type'] ?? 'default';

                $normalizedNodes[] = [
                    'id' => $node['id'],
                    'type' => $realType, // <-- Tipo real reconocido por Engine.php
                    'data' => [
                        'is_start' => $node['data']['isStart'] ?? $node['data']['is_start'] ?? false,
                        'parameters' => $node['data']['parameters'] ?? []
                    ]
                ];
            }
            $payload['nodes'] = $normalizedNodes;
        }

        // 3. Ejecutar el workflow normalizado
        try {
            $result = $engine->executeWorkflow($payload);
            $queueManager->markCompleted($jobId, $result);
            $processedCount++;
        } catch (\Throwable $e) {
            $queueManager->markFailed($jobId, $e->getMessage());
        }
    }

    $response = [
        'success' => true,
        'processed_jobs' => $processedCount,
        'timestamp' => date('Y-m-d H:i:s')
    ];

    if (php_sapi_name() === 'cli') {
        echo "[" . date('Y-m-d H:i:s') . "] Procesados {$processedCount} trabajos con éxito.\n";
    } else {
        header("Content-Type: application/json");
        echo json_encode($response);
    }

} catch (\Throwable $e) {
    if (php_sapi_name() === 'cli') {
        echo "[" . date('Y-m-d H:i:s') . "] Error en el worker: " . $e->getMessage() . "\n";
    } else {
        http_response_code(500);
        header("Content-Type: application/json");
        echo json_encode(['error' => $e->getMessage()]);
    }
}
