<?php

namespace P8P\Nodes;

use P8P\Interfaces\NodeInterface;

class StartNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'start',
            'name' => 'Start Trigger',
            'icon' => 'play',
            'description' => 'Punto de entrada inicial para la ejecución del flujo',
            'parameters' => []
        ];
    }

    public function validate(array $parameters): bool
    {
        return true;
    }

    public function execute(array $parameters, array $context): array
    {
        return [
            'success' => true,
            'message' => 'Workflow iniciado correctamente',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}
