<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\QueueManager;

// Si la consulta es un GET ?job_id=xxx, retornamos el estado actual de la ejecución
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['job_id'])) {
    try {
        $queue = new QueueManager();
        $job = $queue->getJobStatus($_GET['job_id']);

        if (!$job) {
            http_response_code(404);
            echo json_encode(['error' => 'Trabajo no encontrado']);
            exit;
        }

        echo json_encode(['success' => true, 'job' => $job]);
        exit;
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Para POST, encolamos el trabajo
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$workflowId = $input['workflow_id'] ?? $_GET['id'] ?? 'adhoc_workflow';

if (empty($input['nodes']) && empty($input['workflow_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Se requieren nodos o un workflow_id para encolar']);
    exit;
}

try {
    $queue = new QueueManager();
    $jobId = $queue->enqueue($workflowId, $input);

    http_response_code(202); // 202 Accepted
    echo json_encode([
        'success' => true,
        'message' => 'Ejecución encolada correctamente en segundo plano',
        'job_id' => $jobId,
        'status_url' => "http://{$_SERVER['HTTP_HOST']}/async_run.php?job_id={$jobId}"
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al encolar trabajo: ' . $e->getMessage()]);
}
