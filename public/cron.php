<?php

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Engine;
use P8P\Core\NodeRegistry;
use P8P\Core\QueueManager;
use P8P\Core\Database;

// Inicializar motor y auto-descubrir nodos
$engine = new Engine();
$registry = new NodeRegistry($engine);
$registry->autoDiscover();

$queueManager = new QueueManager();

try {
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

        // Normalización de nodos customNode
        if (!empty($payload['nodes'])) {
            $normalizedNodes = [];
            foreach ($payload['nodes'] as $node) {
                $realType = $node['data']['type'] ?? $node['type'] ?? 'default';

                $normalizedNodes[] = [
                    'id' => $node['id'],
                    'type' => $realType,
                    'data' => [
                        'is_start' => $node['data']['isStart'] ?? $node['data']['is_start'] ?? false,
                        'parameters' => $node['data']['parameters'] ?? []
                    ]
                ];
            }
            $payload['nodes'] = $normalizedNodes;
        }

        try {
            $result = $engine->executeWorkflow($payload);
            $queueManager->markCompleted($jobId, $result);
            $processedCount++;
        } catch (\Throwable $e) {
            $queueManager->markFailed($jobId, $e->getMessage());
        }
    }

    if (php_sapi_name() === 'cli') {
        echo "[" . date('Y-m-d H:i:s') . "] Procesados {$processedCount} trabajos con éxito.\n";
    } else {
        header("Content-Type: application/json");
        echo json_encode(['success' => true, 'processed_jobs' => $processedCount]);
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
