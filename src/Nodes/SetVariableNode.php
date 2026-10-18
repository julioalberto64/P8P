<?php

namespace P8P\Nodes;

use P8P\Interfaces\NodeInterface;

class SetVariableNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'set_variable',
            'name' => 'Set Variable',
            'icon' => 'variable',
            'description' => 'Asigna o transforma variables dentro del contexto del flujo',
            'parameters' => [
                'variable_name' => ['type' => 'string', 'required' => true],
                'variable_value' => ['type' => 'string', 'required' => true]
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
