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
            'icon' => 'GitFork', 'description' => 'Bifurcación lógica condicional',
            'parameters' => [
                'value_1' => ['type' => 'string', 'default' => ''],
                'operator' => ['type' => 'string', 'default' => 'equals'], // equals, not_equals, contains, not_contains, is_empty, is_not_empty
                'value_2' => ['type' => 'string', 'default' => '']
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
