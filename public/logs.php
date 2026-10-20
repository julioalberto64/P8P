<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Database;

try {
    // 1. Cargar la configuración estandarizada de la base de datos
    $config = require __DIR__ . '/../config/database.php';

    // 2. Obtener la conexión existente mediante la clase Database
    $db = Database::getConnection($config);

    // 3. Consultar las últimas 50 ejecuciones usando solo las columnas existentes
    $stmt = $db->prepare("
        SELECT id, workflow_id, status, error_message, payload, result, created_at, updated_at 
        FROM queue_jobs 
        ORDER BY created_at DESC 
        LIMIT 50
    ");
    $stmt->execute();

    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Formatear resultados leyendo el tiempo de ejecución desde el JSON o timestamps
    $formattedLogs = array_map(function($log) {
        $resultData = !empty($log['result']) ? json_decode($log['result'], true) : null;
        $payloadData = !empty($log['payload']) ? json_decode($log['payload'], true) : null;

        // Extraer el tiempo de ejecución del JSON result, o calcular la diferencia en ms entre updated_at y created_at
        $executionTimeMs = 0;
        if (isset($resultData['execution_time_ms'])) {
            $executionTimeMs = (float)$resultData['execution_time_ms'];
        } elseif (!empty($log['created_at']) && !empty($log['updated_at'])) {
            $start = strtotime($log['created_at']);
            $end = strtotime($log['updated_at']);
            $executionTimeMs = max(0, ($end - $start) * 1000);
        }

        return [
            'id' => $log['id'],
            'workflow_id' => $log['workflow_id'],
            'status' => $log['status'],
            'error_message' => $log['error_message'],
            'execution_time_ms' => $executionTimeMs,
            'payload' => $payloadData,
            'result' => $resultData,
            'created_at' => $log['created_at']
        ];
    }, $logs ?: []);

    echo json_encode([
        'success' => true,
        'logs' => $formattedLogs
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error al consultar logs: ' . $e->getMessage()
    ]);
}
