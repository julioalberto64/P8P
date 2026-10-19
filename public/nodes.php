<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

use P8P\Core\Engine;
use P8P\Core\NodeRegistry;

$engine = new Engine();
$registry = new NodeRegistry($engine);
$registry->autoDiscover();

echo json_encode([
    'success' => true,
    'nodes' => $registry->getDiscoveredSchemas()
]);
