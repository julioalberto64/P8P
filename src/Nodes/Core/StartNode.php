<?php

namespace P8P\Nodes\Core;

use P8P\Interfaces\NodeInterface;

class StartNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'start',
            'name' => 'Start Trigger',
            'category' => 'Core',
            'description' => 'Punto de entrada manual o por defecto para iniciar la ejecución del flujo.',
            'icon' => 'Play',
            'is_start' => true,
            'help_text' => 'Este nodo marca el comienzo de tu flujo de trabajo. No requiere parámetros obligatorios, pero puedes inicializar un Payload manual para pruebas localmente.',
            'parameters' => [
                'test_payload' => [
                    'type' => 'textarea',
                    'required' => false,
                    'label' => 'Payload Inicial de Prueba (JSON)',
                    'placeholder' => "{\n  \"env\": \"test\",\n  \"user\": \"admin\"\n}"
                ]
            ]
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
