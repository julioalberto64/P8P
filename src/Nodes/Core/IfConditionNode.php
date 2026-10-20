<?php

namespace P8P\Nodes\Core;

use P8P\Interfaces\NodeInterface;

class IfConditionNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'if_condition',
            'name' => 'If Condition',
            'category' => 'Core',
            'description' => 'Bifurcación lógica condicional para controlar el flujo de ejecución.',
            'icon' => 'GitFork',
            'is_start' => false,
            'help_text' => 'Compara dos valores usando el operador seleccionado. Puedes evaluar variables dinámicas (ej. {{status}} o {{response_code}}).',
            'parameters' => [
                'value_1' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Valor 1',
                    'placeholder' => 'ej. {{status}}'
                ],
                'operator' => [
                    'type' => 'select',
                    'required' => true,
                    'label' => 'Operador de Comparación',
                    'default' => 'equals',
                    'options' => [
                        ['value' => 'equals', 'label' => 'Es igual a (==)'],
                        ['value' => 'not_equals', 'label' => 'No es igual a (!=)'],
                        ['value' => 'contains', 'label' => 'Contiene'],
                        ['value' => 'greater_than', 'label' => 'Mayor que (>)'],
                        ['value' => 'less_than', 'label' => 'Menor que (<)']
                    ]
                ],
                'value_2' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Valor 2',
                    'placeholder' => 'ej. active o 200'
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
        $v1 = $parameters['value_1'] ?? '';
        $v2 = $parameters['value_2'] ?? '';
        $operator = $parameters['operator'] ?? 'equals';

        $isTrue = false;

        switch ($operator) {
            case 'equals':
                $isTrue = ($v1 == $v2);
                break;
            case 'not_equals':
                $isTrue = ($v1 != $v2);
                break;
            case 'contains':
                $isTrue = (str_contains((string)$v1, (string)$v2));
                break;
            case 'not_contains':
                $isTrue = (!str_contains((string)$v1, (string)$v2));
                break;
            case 'is_empty':
                $isTrue = empty($v1);
                break;
            case 'is_not_empty':
                $isTrue = !empty($v1);
                break;
        }

        return [
            'success' => true,
            'result' => $isTrue,
            'branch' => $isTrue ? 'true' : 'false',
            'evaluated' => [
                'value_1' => $v1,
                'operator' => $operator,
                'value_2' => $v2
            ]
        ];
    }
}
