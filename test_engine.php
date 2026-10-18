<?php

require_once __DIR__ . '/vendor/autoload.php';

use P8P\Core\Engine;
use P8P\Nodes\HttpRequestNode;

// 1. Instanciar el motor y registrar nodos
$engine = new Engine();
$engine->registerNode(new HttpRequestNode());

// 2. Simular un Workflow proveniente del Frontend (JSON de React Flow)
$workflow = [
    'nodes' => [
        [
            'id' => 'node_1',
            'type' => 'http_request',
            'data' => [
                'is_start' => true,
                'parameters' => [
                    'url' => 'https://jsonplaceholder.typicode.com/todos/1',
                    'method' => 'GET'
                ]
            ]
        ]
    ],
    'edges' => []
];

// 3. Ejecutar workflow
$result = $engine->executeWorkflow($workflow);

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT);
