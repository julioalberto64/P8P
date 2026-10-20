<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Database;

try {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?? $_POST;

    $workflowId  = $data['workflow_id'] ?? null;
    $sessionId   = $data['session_id'] ?? 'guest_' . time();
    $userMessage = $data['message'] ?? $data['user_message'] ?? '';

    if (empty($workflowId)) {
        echo json_encode(['success' => false, 'error' => 'workflow_id es requerido']);
        exit;
    }

    $config = require __DIR__ . '/../config/database.php';
    $db = Database::getConnection($config);

    // Guardar el mensaje entrante en la cola de trabajos (queue_jobs)
    $jobId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $payload = json_encode([
        'session_id' => $sessionId,
        'user_message' => $userMessage,
        'received_at' => date('Y-m-d H:i:s')
    ]);

    $stmt = $db->prepare("
        INSERT INTO queue_jobs (id, workflow_id, payload, status, created_at) 
        VALUES (:id, :workflow_id, :payload, 'pending', NOW())
    ");

    $stmt->execute([
        ':id' => $jobId,
        ':workflow_id' => $workflowId,
        ':payload' => $payload
    ]);

    echo json_encode([
        'success' => true,
        'job_id' => $jobId,
        'message' => 'Mensaje recibido correctamente en el motor P8P'
    ]);
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
