<?php

namespace P8P\Nodes\Core;

use P8P\Interfaces\NodeInterface;

class SetVariableNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'set_variable',
            'name' => 'Set Variable',
            'category' => 'Core',
            'description' => 'Declara o transforma variables globales dentro de la ejecución del flujo.',
            'icon' => 'Sliders',
            'is_start' => false,
            'help_text' => 'Asigna un nombre de variable y su valor. Esta variable estará disponible en los nodos subsecuentes con la sintaxis {{nombre_variable}}.',
            'parameters' => [
                'variable_name' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Nombre de la Variable',
                    'placeholder' => 'ej. chat_id o mi_token'
                ],
                'variable_value' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Valor',
                    'placeholder' => 'ej. 123456 o Hola Mundo'
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        return !empty($parameters['variable_name']);
    }

    public function execute(array $parameters, array $context): array
    {
        if (!$this->validate($parameters)) {
            throw new \InvalidArgumentException("El nombre de la variable es obligatorio en SetVariableNode");
        }

        $varName = $parameters['variable_name'];
        $varValue = $parameters['variable_value'] ?? null;

        return [
            'success' => true,
            'variable' => $varName,
            'value' => $varValue
        ];
    }
}
