<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Database;

try {
    $config = require __DIR__ . '/../config/database.php';
    $db = Database::getConnection($config);

    // Si se pasa un ID específico (?id=xxxx)
    if (!empty($_GET['id'])) {
        $stmt = $db->prepare("SELECT id, name, description, structure, is_active, updated_at FROM workflows WHERE id = :id");
        $stmt->execute([':id' => $_GET['id']]);
        $workflow = $stmt->fetch();

        if (!$workflow) {
            http_response_code(404);
            echo json_encode(['error' => 'Workflow no encontrado']);
            exit;
        }

        $workflow['structure'] = json_decode($workflow['structure'], true);
        echo json_encode(['success' => true, 'workflow' => $workflow]);
        exit;
    }

    // Listar todos los workflows
    $stmt = $db->query("SELECT id, name, description, is_active, created_at, updated_at FROM workflows ORDER BY updated_at DESC");
    $workflows = $stmt->fetchAll();

    echo json_encode(['success' => true, 'workflows' => $workflows]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al consultar MariaDB: ' . $e->getMessage()]);
}
