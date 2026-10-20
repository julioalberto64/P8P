<?php

ini_set('display_errors', '0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Database;

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
foreach (['job_id', 'workflow_id', 'session_id'] as $key) {
    if (!is_array($input) || !is_string($input[$key] ?? null)
        || trim($input[$key]) === '' || strlen($input[$key]) > 255) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Trabajo, workflow y sesión válidos son obligatorios.']);
        exit;
    }
}

try {
    $config = require __DIR__ . '/../config/database.php';
    $db = Database::getConnection($config);
    $stmt = $db->prepare('SELECT status, payload, result FROM queue_jobs WHERE id = :id AND workflow_id = :workflow_id');
    $stmt->execute([':id' => $input['job_id'], ':workflow_id' => $input['workflow_id']]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    $payload = $job ? json_decode($job['payload'], true) : null;

    if (!$job || !is_array($payload) || !is_string($payload['session_id'] ?? null)
        || !hash_equals($payload['session_id'], $input['session_id'])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Trabajo no encontrado.']);
        exit;
    }

    if (in_array($job['status'], ['pending', 'processing'], true)) {
        echo json_encode(['success' => true, 'status' => $job['status']]);
        exit;
    }

    $result = json_decode($job['result'] ?? '', true);
    $history = is_array($result) ? ($result['history'] ?? null) : null;
    if ($job['status'] !== 'completed' || !is_array($history)) {
        echo json_encode(['success' => false, 'status' => 'failed', 'error' => 'No se pudo completar la conversación.']);
        exit;
    }

    $response = null;
    foreach ($history as $execution) {
        if (!is_array($execution) || ($execution['status'] ?? null) === 'failed') {
            echo json_encode(['success' => false, 'status' => 'failed', 'error' => 'No se pudo completar la conversación.']);
            exit;
        }
        $output = $execution['output'] ?? null;
        if (($execution['type'] ?? null) === 'respond_to_chat'
            && ($execution['status'] ?? null) === 'success' && is_array($output)
            && ($output['success'] ?? null) === true
            && ($output['session_id'] ?? null) === $input['session_id']
            && is_string($output['response'] ?? null)) {
            $response = $output['response'];
        }
    }

    // No entregar contextos, parámetros, credenciales ni errores internos al chat público.
    echo json_encode(['success' => true, 'status' => 'completed', 'response' => $response]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo consultar la respuesta.']);
}
