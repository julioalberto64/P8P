<?php

namespace P8P\Nodes\Core;

use InvalidArgumentException;
use LogicException;
use P8P\Core\ExpressionEvaluator;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class LoopNode implements NodeInterface
{
    public const MAX_ITERATIONS = 1000;

    public function getSchema(): array
    {
        return [
            'type' => 'loop',
            'name' => 'Loop',
            'category' => 'Core',
            'description' => 'Ejecuta un bloque por elemento, una cantidad de veces o mientras se cumpla una condición.',
            'icon' => 'Repeat',
            'is_start' => false,
            'help_text' => 'Conecta Cuerpo al bloque y Finalizado al siguiente nodo exterior. No conectes el cuerpo de vuelta a Loop ni a Finalizado. Dentro del bloque usa {{ID_LOOP.item}}, {{ID_LOOP.index}} (desde 0) y {{ID_LOOP.iteration}} (desde 1). Después usa {{ID_LOOP.iterations}} y {{ID_LOOP.results}}. Cada iteración tiene contexto independiente. Mientras comprueba la condición antes de entrar y después de cada bloque; puede leer la salida del bloque anterior para decidir si continúa. Máximo 1000 iteraciones, 16 niveles y 10000 ejecuciones por workflow.',
            'parameters' => [
                'mode' => [
                    'type' => 'select', 'label' => 'Modo', 'required' => true, 'default' => 'collection',
                    'options' => [
                        ['value' => 'collection', 'label' => 'Recorrer colección'],
                        ['value' => 'count', 'label' => 'Repetir N veces'],
                        ['value' => 'while', 'label' => 'Mientras se cumpla la condición']
                    ]
                ],
                'collection' => [
                    'type' => 'textarea', 'label' => 'Colección (modo colección)', 'required' => false,
                    'placeholder' => '[{"id":1},{"id":2}] o {{origen.items}}'
                ],
                'count' => [
                    'type' => 'string', 'label' => 'Repeticiones (modo cantidad)', 'required' => false,
                    'default' => '0', 'placeholder' => '3 o {{cantidad}}'
                ],
                'value_1' => [
                    'type' => 'string', 'label' => 'Valor 1 (modo mientras)', 'required' => false,
                    'placeholder' => '{{ID_LOOP.iteration}}'
                ],
                'operator' => [
                    'type' => 'select', 'label' => 'Comparación (modo mientras)', 'required' => false,
                    'default' => 'equals',
                    'options' => [
                        ['value' => 'equals', 'label' => 'Es igual a'],
                        ['value' => 'not_equals', 'label' => 'No es igual a'],
                        ['value' => 'less_than', 'label' => 'Menor que'],
                        ['value' => 'greater_than', 'label' => 'Mayor que'],
                        ['value' => 'less_or_equal', 'label' => 'Menor o igual que'],
                        ['value' => 'greater_or_equal', 'label' => 'Mayor o igual que'],
                        ['value' => 'contains', 'label' => 'Contiene']
                    ]
                ],
                'value_2' => [
                    'type' => 'string', 'label' => 'Valor 2 (modo mientras)', 'required' => false,
                    'placeholder' => '3'
                ],
                'max_iterations' => [
                    'type' => 'string', 'label' => 'Límite de iteraciones (1–1000)',
                    'required' => true, 'default' => '1000'
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->configuration($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * El motor proporciona el ejecutor del cuerpo; nunca se acepta desde el payload.
     * Las condiciones originales se conservan para reevaluarlas tras cada bloque.
     */
    public function execute(array $parameters, array $context, ?callable $executeBody = null, string $nodeId = 'loop'): array
    {
        $configuration = $parameters;
        foreach (['mode', 'collection', 'count', 'max_iterations'] as $key) {
            if (array_key_exists($key, $parameters)) {
                $configuration[$key] = ExpressionEvaluator::evaluate($parameters[$key], $context);
            }
        }
        $config = $this->configuration($configuration);
        if ($executeBody === null) {
            throw new LogicException('Loop requiere el ejecutor de bloques de Engine.');
        }

        $results = [];
        $state = ['success' => true, 'mode' => $config['mode'], 'item' => null, 'key' => null,
            'index' => 0, 'iteration' => 0];
        $conditionContext = $context;
        $conditionContext[$nodeId] = $state;
        $run = function (mixed $item, int|string|null $key, int $index) use (
            &$results, &$state, &$conditionContext, $context, $nodeId, $executeBody
        ): void {
            $state = array_merge($state, ['item' => $item, 'key' => $key, 'index' => $index, 'iteration' => $index + 1]);
            $iterationContext = $context;
            $iterationContext[$nodeId] = $state;
            $outputs = $executeBody($iterationContext, $state);
            $results[] = ['index' => $index, 'iteration' => $index + 1, 'item' => $item, 'key' => $key, 'outputs' => $outputs];
            // Solo la condición puede leer el bloque anterior. El siguiente cuerpo parte de $context.
            $conditionContext = array_replace($iterationContext, $outputs);
        };

        if ($config['mode'] === 'collection') {
            $index = 0;
            foreach ($config['collection'] as $key => $item) {
                $run($item, $key, $index++);
            }
        } elseif ($config['mode'] === 'count') {
            for ($index = 0; $index < $config['count']; $index++) {
                $run($index, $index, $index);
            }
        } else {
            $index = 0;
            while ($this->condition($parameters, $conditionContext)) {
                if ($index >= $config['max_iterations']) {
                    throw new RuntimeException('Loop: se alcanzó el límite de iteraciones y la condición sigue siendo verdadera.');
                }
                $run($index, $index, $index);
                $index++;
            }
        }

        return ['success' => true, 'mode' => $config['mode'], 'iterations' => count($results), 'results' => $results];
    }

    private function configuration(array $parameters): array
    {
        $mode = $parameters['mode'] ?? 'collection';
        if (!in_array($mode, ['collection', 'count', 'while'], true)) {
            throw new InvalidArgumentException('Loop: modo inválido.');
        }
        $limit = $this->integer($parameters['max_iterations'] ?? self::MAX_ITERATIONS, 1, self::MAX_ITERATIONS);
        $config = ['mode' => $mode, 'max_iterations' => $limit];
        if ($mode === 'collection') {
            $collection = $parameters['collection'] ?? null;
            if (is_string($collection)) {
                // JSON debe representar un array, no un objeto convertido silenciosamente a array.
                try {
                    $collection = json_decode($collection, false, 64, JSON_THROW_ON_ERROR);
                    if (is_array($collection)) {
                        $collection = json_decode($parameters['collection'], true, 64, JSON_THROW_ON_ERROR);
                    }
                } catch (\JsonException $e) {
                    throw new InvalidArgumentException('Loop: la colección debe ser un array o un array JSON válido.');
                }
            }
            if (!is_array($collection) || count($collection) > $limit) {
                throw new InvalidArgumentException('Loop: colección inválida o superior al límite de iteraciones.');
            }
            $config['collection'] = $collection;
        } elseif ($mode === 'count') {
            $config['count'] = $this->integer($parameters['count'] ?? null, 0, $limit);
        } else {
            $operator = $parameters['operator'] ?? 'equals';
            if (!in_array($operator, ['equals', 'not_equals', 'less_than', 'greater_than', 'less_or_equal', 'greater_or_equal', 'contains'], true)
                || !array_key_exists('value_1', $parameters) || !array_key_exists('value_2', $parameters)) {
                throw new InvalidArgumentException('Loop: indica dos valores y un operador de condición válido.');
            }
            foreach (['value_1', 'value_2'] as $key) {
                if (!is_scalar($parameters[$key]) || (is_string($parameters[$key]) && trim($parameters[$key]) === '')) {
                    throw new InvalidArgumentException('Loop: los valores de condición deben ser escalares no vacíos.');
                }
            }
        }
        return $config;
    }

    private function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (is_string($value) && preg_match('/^\d+$/D', trim($value)) === 1) {
            $value = filter_var(trim($value), FILTER_VALIDATE_INT);
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("Loop: se requiere un entero entre {$minimum} y {$maximum}.");
        }
        return $value;
    }

    private function condition(array $parameters, array $context): bool
    {
        $values = [];
        foreach (['value_1', 'value_2'] as $key) {
            $value = ExpressionEvaluator::evaluate($parameters[$key], $context);
            if (!is_scalar($value) || (is_string($value) && (trim($value) === '' || str_contains($value, '{{')))
                || (is_float($value) && !is_finite($value))) {
                throw new InvalidArgumentException('Loop: condición vacía, no escalar o con una expresión sin resolver.');
            }
            $values[] = $value;
        }
        [$left, $right] = $values;
        $operator = $parameters['operator'] ?? 'equals';
        if ($operator === 'contains') {
            if (!is_string($left) || !is_string($right)) {
                throw new InvalidArgumentException('Loop: contiene requiere dos textos.');
            }
            return str_contains($left, $right);
        }
        // Igualdad tipada, con conversión explícita de booleanos y números escritos en el inspector.
        foreach ($values as &$value) {
            if (is_string($value)) {
                $text = trim($value);
                if ($text === 'true' || $text === 'false') {
                    $value = $text === 'true';
                } elseif (is_numeric($text)) {
                    $value = $text + 0;
                    if (!is_finite((float) $value)) {
                        throw new InvalidArgumentException('Loop: número de condición fuera de rango.');
                    }
                }
            }
        }
        unset($value);
        [$left, $right] = $values;
        if ($operator === 'equals' || $operator === 'not_equals') {
            $equal = (is_int($left) || is_float($left)) && (is_int($right) || is_float($right))
                ? $left == $right : $left === $right;
            return $operator === 'equals' ? $equal : !$equal;
        }
        if ((!is_int($left) && !is_float($left)) || (!is_int($right) && !is_float($right))) {
            throw new InvalidArgumentException('Loop: la comparación de orden requiere dos números.');
        }
        return match ($operator) {
            'less_than' => $left < $right,
            'greater_than' => $left > $right,
            'less_or_equal' => $left <= $right,
            'greater_or_equal' => $left >= $right
        };
    }
}
