<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

// Encabezados CORS para integración en cualquier sitio web externo
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Database;

try {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?? $_POST;

// Sanitizar y limitar a 255 caracteres
    $workflowId = isset($data['workflow_id']) ? substr(trim($data['workflow_id']), 0, 255) : null;    $sessionId   = $data['session_id'] ?? 'guest_' . time();
    $userMessage = $data['message'] ?? $data['user_message'] ?? '';

    if (empty($workflowId)) {
        echo json_encode(['success' => false, 'error' => 'El parámetro workflow_id es requerido']);
        exit;
    }

    if (empty($userMessage)) {
        echo json_encode(['success' => false, 'error' => 'El mensaje del usuario no puede estar vacío']);
        exit;
    }

    $config = require __DIR__ . '/../config/database.php';
    $db = Database::getConnection($config);

    // Generar ID UUID v4 único para el trabajo
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

    // Insertar el trabajo en estado "pending" en MariaDB
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
        'status' => 'pending',
        'message' => 'Mensaje enviado a la cola de procesamiento de P8P'
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error al procesar el mensaje: ' . $e->getMessage()
    ]);
}
