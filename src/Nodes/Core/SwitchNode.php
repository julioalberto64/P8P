<?php

namespace P8P\Nodes\Core;

use InvalidArgumentException;
use P8P\Core\ExpressionEvaluator;
use P8P\Interfaces\NodeInterface;

class SwitchNode implements NodeInterface
{
    public const MAX_CASES = 8;

    public function getSchema(): array
    {
        return [
            'type' => 'switch',
            'name' => 'Switch',
            'category' => 'Core',
            'description' => 'Selecciona el primer caso coincidente o la salida predeterminada.',
            'icon' => 'GitFork',
            'is_start' => false,
            'help_text' => 'Configura hasta 8 casos como array JSON o {{origen.cases}}. Cada caso contiene value, operator (equals, not_equals, contains, greater_than o less_than) y label opcional. equals/not_equals comparan tipos estrictamente; contains exige textos y las comparaciones de orden exigen números reales, sin convertir textos. Las expresiones en value se resuelven después de leer el JSON y conservan su tipo. Conecta case_1…case_8 y default: solo continúa la primera coincidencia. Un array vacío continúa por default.',
            'parameters' => [
                'value' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Valor a comparar',
                    'placeholder' => '{{origen.status}}'
                ],
                'cases' => [
                    'type' => 'textarea',
                    'required' => true,
                    'label' => 'Casos (array JSON, máximo 8)',
                    'default' => '[]',
                    'placeholder' => '[{"value":"active","operator":"equals","label":"Activo"}]'
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->configuration($parameters, []);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine entrega parámetros originales: interpolar JSON completo perdería tipos.
        [$value, $cases] = $this->configuration($parameters, $context);
        foreach ($cases as $index => $case) {
            $matches = match ($case['operator']) {
                'equals' => $value === $case['value'],
                'not_equals' => $value !== $case['value'],
                'contains' => str_contains($value, $case['value']),
                'greater_than' => $value > $case['value'],
                'less_than' => $value < $case['value']
            };
            if ($matches) {
                return ['success' => true, 'branch' => 'case_' . ($index + 1),
                    'matched_index' => $index, 'value' => $value];
            }
        }

        return ['success' => true, 'branch' => 'default', 'matched_index' => null, 'value' => $value];
    }

    private function configuration(array $parameters, array $context): array
    {
        if (!array_key_exists('value', $parameters) || !array_key_exists('cases', $parameters)) {
            throw new InvalidArgumentException('Switch: value y cases son obligatorios.');
        }
        $value = ExpressionEvaluator::evaluate($parameters['value'], $context);
        $cases = $parameters['cases'];
        // Solo una expresión completa puede resolver la colección antes de decodificarla.
        if (is_string($cases) && preg_match('/^{{\s*[a-zA-Z0-9_\.]+\s*}}$/', trim($cases))) {
            $cases = ExpressionEvaluator::evaluate($cases, $context);
        }
        if (is_string($cases)) {
            try {
                if (!str_starts_with(ltrim($cases), '[')) {
                    throw new \JsonException();
                }
                $cases = json_decode($cases, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidArgumentException('Switch: cases debe ser un array o un array JSON válido.');
            }
        }
        if (!is_array($cases) || !array_is_list($cases) || count($cases) > self::MAX_CASES) {
            throw new InvalidArgumentException('Switch: cases debe ser una lista de entre 0 y 8 casos.');
        }
        $this->validateData($value);
        // Validar todos los casos, incluso los posteriores a una coincidencia.
        foreach ($cases as $index => &$case) {
            if (!is_array($case) || !array_key_exists('value', $case)) {
                throw new InvalidArgumentException('Switch: cada caso debe contener value.');
            }
            $case['value'] = ExpressionEvaluator::evaluate($case['value'], $context);
            $this->validateData($case['value']);
            $case['operator'] = $case['operator'] ?? 'equals';
            if (!in_array($case['operator'], ['equals', 'not_equals', 'contains', 'greater_than', 'less_than'], true)) {
                throw new InvalidArgumentException('Switch: operador inválido en el caso ' . ($index + 1) . '.');
            }
            if (array_key_exists('label', $case) && !is_string($case['label'])) {
                throw new InvalidArgumentException('Switch: label debe ser texto.');
            }
            if ($case['operator'] === 'contains' && (!is_string($value) || !is_string($case['value']))) {
                throw new InvalidArgumentException('Switch: contains requiere dos textos.');
            }
            if (in_array($case['operator'], ['greater_than', 'less_than'], true)
                && (!$this->isNumber($value) || !$this->isNumber($case['value']))) {
                throw new InvalidArgumentException('Switch: las comparaciones de orden requieren números finitos.');
            }
        }
        unset($case);

        return [$value, $cases];
    }

    private function isNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private function validateData(mixed $value): void
    {
        // Solo datos serializables: sin objetos PHP, recursos, infinitos o referencias recursivas.
        $this->validatePlainData($value);
        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Switch: los valores deben ser datos JSON válidos.');
        }
    }

    private function validatePlainData(mixed $value, int $depth = 0): void
    {
        if ($depth > 64 || is_object($value) || is_resource($value)) {
            throw new InvalidArgumentException('Switch: los valores deben ser datos JSON sin objetos PHP y con profundidad máxima de 64.');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->validatePlainData($item, $depth + 1);
            }
        }
    }
}
