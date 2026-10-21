<?php

namespace P8P\Nodes\Core;

use InvalidArgumentException;
use P8P\Core\ExpressionEvaluator;
use P8P\Interfaces\NodeInterface;
use stdClass;

class MergeNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'merge',
            'name' => 'Merge',
            'category' => 'Core',
            'description' => 'Reúne las ramas conectadas antes de continuar una sola vez.',
            'icon' => 'GitMerge',
            'is_start' => false,
            'help_text' => 'Conecta cada rama a la entrada principal. Se identifica por el ID de su nodo de origen. Espera a resolver todas las ramas del bloque actual; las descartadas se omiten o producen un error. El orden de las conexiones determina el orden de concatenación y de conflictos. No combina recursivamente objetos.',
            'parameters' => [
                'mode' => [
                    'type' => 'select', 'required' => true, 'label' => 'Modo', 'default' => 'concat',
                    'options' => [
                        ['value' => 'concat', 'label' => 'Concatenar colecciones'],
                        ['value' => 'objects', 'label' => 'Combinar objetos']
                    ]
                ],
                'input_field' => [
                    'type' => 'string', 'required' => false, 'label' => 'Campo de cada salida',
                    'default' => 'data', 'placeholder' => 'data.items; vacío utiliza la salida completa'
                ],
                'conflict_policy' => [
                    'type' => 'select', 'required' => true, 'label' => 'Claves repetidas en objetos', 'default' => 'last',
                    'options' => [
                        ['value' => 'first', 'label' => 'Conservar la primera'],
                        ['value' => 'last', 'label' => 'Conservar la última'],
                        ['value' => 'error', 'label' => 'Detener con error']
                    ]
                ],
                'missing_policy' => [
                    'type' => 'select', 'required' => true, 'label' => 'Ramas descartadas', 'default' => 'empty',
                    'options' => [
                        ['value' => 'empty', 'label' => 'Omitir como entrada vacía'],
                        ['value' => 'error', 'label' => 'Detener con error']
                    ]
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        $field = $parameters['input_field'] ?? 'data';
        return in_array($parameters['mode'] ?? 'concat', ['concat', 'objects'], true)
            && in_array($parameters['conflict_policy'] ?? 'last', ['first', 'last', 'error'], true)
            && in_array($parameters['missing_policy'] ?? 'empty', ['empty', 'error'], true)
            && is_string($field)
            && ($field === '' || preg_match('/^[^.\s]+(?:\.[^.\s]+)*$/uD', $field) === 1);
    }

    public function execute(array $parameters, array $context): array
    {
        // Evaluar solo configuración: las salidas de otras ramas son datos, no plantillas.
        $config = [];
        foreach (['mode', 'input_field', 'conflict_policy', 'missing_policy'] as $key) {
            if (array_key_exists($key, $parameters)) {
                $config[$key] = ExpressionEvaluator::evaluate($parameters[$key], $context);
            }
        }
        if (!$this->validate($config)) {
            throw new InvalidArgumentException('Merge: modo, ruta de campo o política inválida.');
        }
        $inputs = $parameters['_merge_inputs'] ?? null;
        $skipped = $parameters['_merge_skipped'] ?? null;
        if (!is_array($inputs) || $inputs === [] || !is_array($skipped) || !array_is_list($skipped)) {
            throw new InvalidArgumentException('Merge: requiere entradas de ramas resueltas por el motor.');
        }
        foreach ($inputs as $source => $output) {
            if ((!is_string($source) && !is_int($source)) || (string) $source === '' || !is_array($output)) {
                throw new InvalidArgumentException('Merge: entrada de rama inválida.');
            }
        }
        $seenSkipped = [];
        foreach ($skipped as $source) {
            if ((!is_string($source) && !is_int($source)) || (string) $source === ''
                || array_key_exists($source, $inputs) || isset($seenSkipped[$source])) {
                throw new InvalidArgumentException('Merge: identificación de rama descartada inválida.');
            }
            $seenSkipped[$source] = true;
        }
        if (($config['missing_policy'] ?? 'empty') === 'error' && $skipped !== []) {
            throw new InvalidArgumentException('Merge: una o más ramas requeridas fueron descartadas.');
        }

        $mode = $config['mode'] ?? 'concat';
        $field = $config['input_field'] ?? 'data';
        $policy = $config['conflict_policy'] ?? 'last';
        $data = [];
        foreach ($inputs as $output) {
            $value = $this->extractField($output, $field);
            if (is_string($value)) {
                try {
                    $value = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new InvalidArgumentException('Merge: una entrada contiene JSON inválido.');
                }
            }
            if ($mode === 'concat') {
                if (!is_array($value) || !array_is_list($value)) {
                    throw new InvalidArgumentException('Merge: concatenar requiere listas o JSON de tipo array.');
                }
                foreach ($value as $item) {
                    $data[] = $item;
                }
                continue;
            }
            if ($value instanceof stdClass) {
                $value = (array) $value;
            } elseif (!is_array($value) || ($value !== [] && array_is_list($value))) {
                throw new InvalidArgumentException('Merge: combinar objetos requiere objetos o arrays asociativos.');
            }
            foreach ($value as $key => $item) {
                if (array_key_exists($key, $data)) {
                    if ($policy === 'error') {
                        throw new InvalidArgumentException('Merge: conflicto de claves entre objetos.');
                    }
                    if ($policy === 'first') {
                        continue;
                    }
                }
                $data[$key] = $item;
            }
        }

        return [
            'success' => true,
            'data' => $mode === 'objects' && ($data === [] || array_is_list($data)) ? (object) $data : $data,
            'branches' => array_map('strval', array_keys($inputs)),
            'skipped' => array_map('strval', $skipped)
        ];
    }

    private function extractField(array $output, string $field): mixed
    {
        $value = $output;
        if ($field !== '') {
            foreach (explode('.', $field) as $key) {
                if (is_array($value) && array_key_exists($key, $value)) {
                    $value = $value[$key];
                } elseif ($value instanceof stdClass && property_exists($value, $key)) {
                    $value = $value->{$key};
                } else {
                    throw new InvalidArgumentException('Merge: el campo solicitado no existe en una rama activa.');
                }
            }
        }
        return $value;
    }
}
