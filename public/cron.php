<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Engine;
use P8P\Core\NodeRegistry;
use P8P\Core\QueueManager;
use P8P\Core\Database;

// Cargar variables del .env para ejecuciones CLI (Cron Job) o HTTP
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
        list($key, $val) = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val, " \t\n\r\0\x0B\"'");
        if (!getenv($key)) {
            putenv("{$key}={$val}");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

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

        try {
            $continuation = null;
            $jobPayload = json_decode($job['payload'], true, 2048, JSON_THROW_ON_ERROR);
            if (isset($jobPayload['continuation'])) {
                // Instantánea confiable de QueueManager; no reinyectar entradas al reanudar.
                $continuation = $jobPayload['continuation']['checkpoint'];
                $workflowPayload = $jobPayload['continuation']['workflow'];
            } else {
                $workflowStructure = [];
                if (!empty($jobPayload['nodes'])) {
                    $workflowStructure = $jobPayload;
                } elseif (!empty($job['workflow_id'])) {
                    $config = require __DIR__ . '/../config/database.php';
                    $db = Database::getConnection($config);
                    $stmt = $db->prepare("SELECT structure FROM workflows WHERE id = :id");
                    $stmt->execute([':id' => $job['workflow_id']]);
                    $row = $stmt->fetch();
                    if ($row) {
                        $workflowStructure = json_decode($row['structure'], true, 512, JSON_THROW_ON_ERROR);
                    }
                }
                if (empty($workflowStructure['nodes'])) {
                    $queueManager->markFailed($jobId, "No se encontró una estructura de nodos válida para el trabajo.", $job['claim_token']);
                    continue;
                }
                $incomingMessage = $jobPayload['user_message'] ?? $jobPayload['message'] ?? '';
                $incomingSession = $jobPayload['session_id'] ?? ('session_' . time());
                $normalizedNodes = [];
                foreach ($workflowStructure['nodes'] as $node) {
                    $realType = $node['data']['type'] ?? $node['type'] ?? 'default';
                    $isStart = $node['data']['isStart'] ?? $node['data']['is_start'] ?? false;
                    $params = $node['data']['parameters'] ?? [];
                    if ($isStart || $realType === 'chat_trigger' || $realType === 'start') {
                        $params['user_message'] = $incomingMessage;
                        $params['session_id'] = $incomingSession;
                    }
                    $normalizedNodes[] = ['id' => $node['id'], 'type' => $realType,
                        'data' => ['is_start' => $isStart, 'parameters' => $params]];
                }
                $workflowPayload = ['nodes' => $normalizedNodes, 'edges' => $workflowStructure['edges'] ?? []];
            }

            // Se ejecuta pasándole únicamente la estructura normalizada
            $result = $continuation === null
                ? $engine->executeWorkflow($workflowPayload)
                : $engine->resumeWorkflow($workflowPayload, $continuation);
            if ($result['status'] === 'suspended') {
                $queueManager->reschedule($jobId, $job['claim_token'], $workflowPayload, $result, $jobPayload);
            } elseif ($result['status'] === 'failed') {
                $queueManager->markFailed($jobId, $result['error'] ?? 'Falló el workflow.', $job['claim_token'], $result);
            } else {
                $queueManager->markCompleted($jobId, $result, $job['claim_token']);
                $processedCount++;
            }
        } catch (\Throwable $e) {
            $queueManager->markFailed($jobId, 'No se pudo procesar el workflow.', $job['claim_token']);
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
