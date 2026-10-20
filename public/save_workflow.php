<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Database;
use P8P\Core\AuthMiddleware;

// Interceptar y proteger el endpoint
AuthMiddleware::check();

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?? $_POST;

if (empty($input['nodes'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No hay estructura de nodos para guardar']);
    exit;
}

$workflowId = !empty($input['id']) ? $input['id'] : sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

$name = $input['name'] ?? 'Mi Workflow P8P';
$description = $input['description'] ?? '';
$structure = json_encode([
    'nodes' => $input['nodes'],
    'edges' => $input['edges'] ?? []
]);

try {
    $config = require __DIR__ . '/../config/database.php';
    $db = Database::getConnection($config);

    $stmt = $db->prepare("
        INSERT INTO workflows (id, name, description, structure, is_active, updated_at)
        VALUES (:id, :name, :description, :structure, 1, NOW())
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            structure = VALUES(structure),
            updated_at = NOW()
    ");

    $stmt->execute([
        ':id' => $workflowId,
        ':name' => $name,
        ':description' => $description,
        ':structure' => $structure
    ]);

    echo json_encode([
        'success' => true,
        'workflow_id' => $workflowId,
        'message' => 'Workflow guardado correctamente en MariaDB'
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error en base de datos: ' . $e->getMessage()]);
}
