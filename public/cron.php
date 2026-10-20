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

        // 1. Decodificar el payload que contiene la entrada del usuario (message / user_message, session_id)
        $jobPayload = json_decode($job['payload'], true) ?? [];
        $workflowStructure = [];

        // 2. Cargar la estructura del workflow desde la base de datos
        if (!empty($jobPayload['nodes'])) {
            $workflowStructure = $jobPayload;
        } elseif (!empty($job['workflow_id'])) {
            $config = require __DIR__ . '/../config/database.php';
            $db = Database::getConnection($config);
            $stmt = $db->prepare("SELECT structure FROM workflows WHERE id = :id");
            $stmt->execute([':id' => $job['workflow_id']]);
            $row = $stmt->fetch();

            if ($row) {
                $workflowStructure = json_decode($row['structure'], true) ?? [];
            }
        }

        if (empty($workflowStructure['nodes'])) {
            $queueManager->markFailed($jobId, "No se encontró una estructura de nodos válida para el trabajo.");
            continue;
        }

        // Extraer el mensaje entrante del widget / cURL
        $incomingMessage = $jobPayload['user_message'] ?? $jobPayload['message'] ?? '';
        $incomingSession = $jobPayload['session_id'] ?? ('session_' . time());

        // 3. Normalizar nodos e INYECTAR las variables de entrada en los parámetros del nodo disparador (is_start)
        $normalizedNodes = [];
        foreach ($workflowStructure['nodes'] as $node) {
            $realType = $node['data']['type'] ?? $node['type'] ?? 'default';
            $isStart  = $node['data']['isStart'] ?? $node['data']['is_start'] ?? false;
            $params   = $node['data']['parameters'] ?? [];

            // Si es el nodo de inicio (chat_trigger o start), le inyectamos los datos reales del payload
            if ($isStart || $realType === 'chat_trigger' || $realType === 'start') {
                $params['user_message'] = $incomingMessage;
                $params['session_id']   = $incomingSession;
            }

            $normalizedNodes[] = [
                'id'   => $node['id'],
                'type' => $realType,
                'data' => [
                    'is_start'   => $isStart,
                    'parameters' => $params
                ]
            ];
        }

        $workflowPayload = [
            'nodes' => $normalizedNodes,
            'edges' => $workflowStructure['edges'] ?? []
        ];

        try {
            // Se ejecuta pasándole únicamente la estructura normalizada
            $result = $engine->executeWorkflow($workflowPayload);
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
