<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\CredentialManager;

$cm = new CredentialManager();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        echo json_encode(['success' => true, 'credentials' => $cm->getCredentials()]);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim($input['name'] ?? '');
        $type = trim($input['type'] ?? 'gemini_api');
        $data = $input['data'] ?? [];
        $id = $input['id'] ?? null;

        if (empty($name) || empty($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Nombre y datos de credencial son requeridos']);
            exit;
        }

        $credentialId = $cm->saveCredential($name, $type, $data, $id);
        echo json_encode(['success' => true, 'id' => $credentialId, 'message' => 'Credencial guardada y encriptada']);
        exit;
    }

    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de credencial requerido']);
            exit;
        }

        $cm->deleteCredential($id);
        echo json_encode(['success' => true, 'message' => 'Credencial eliminada']);
        exit;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
