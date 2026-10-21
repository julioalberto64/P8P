<?php

namespace P8P\Nodes\Core;

use InvalidArgumentException;
use P8P\Interfaces\NodeInterface;

class SetVariableNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'set_variable',
            'name' => 'Set Variable',
            'category' => 'Core',
            'description' => 'Crea, modifica, renombra o transforma variables temporales del bloque actual.',
            'icon' => 'Sliders',
            'is_start' => false,
            'help_text' => 'Usa {{nombre_variable}} en nodos posteriores. Asignar crea o reemplaza; crear exige ausencia y modificar exige existencia. Renombrar conserva el valor y elimina el nombre anterior. Transformar usa el valor existente. Cada iteración tiene variables independientes; no se almacenan como credenciales ni persisten fuera del workflow.',
            'parameters' => [
                'operation' => [
                    'type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'set',
                    'options' => [
                        ['value' => 'set', 'label' => 'Asignar (crear o reemplazar)'],
                        ['value' => 'create', 'label' => 'Crear'],
                        ['value' => 'update', 'label' => 'Modificar'],
                        ['value' => 'rename', 'label' => 'Renombrar'],
                        ['value' => 'transform', 'label' => 'Transformar']
                    ]
                ],
                'variable_name' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Nombre de la Variable',
                    'placeholder' => 'ej. chat_id o total'
                ],
                'variable_value' => [
                    'type' => 'string',
                    'required' => false,
                    'label' => 'Valor (asignar, crear o modificar)',
                    'placeholder' => 'ej. 123456 o Hola Mundo'
                ],
                'new_name' => [
                    'type' => 'string', 'required' => false, 'label' => 'Nuevo nombre (renombrar)'
                ],
                'transform' => [
                    'type' => 'select', 'required' => false, 'label' => 'Transformación', 'default' => 'trim',
                    'options' => [
                        ['value' => 'trim', 'label' => 'Quitar espacios de extremos'],
                        ['value' => 'uppercase', 'label' => 'Mayúsculas'],
                        ['value' => 'lowercase', 'label' => 'Minúsculas'],
                        ['value' => 'to_number', 'label' => 'Convertir a número'],
                        ['value' => 'to_string', 'label' => 'Convertir escalar a texto'],
                        ['value' => 'json_encode', 'label' => 'Codificar JSON'],
                        ['value' => 'json_decode', 'label' => 'Decodificar JSON']
                    ]
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        $operation = $parameters['operation'] ?? 'set';
        return in_array($operation, ['set', 'create', 'update', 'rename', 'transform'], true)
            && $this->validName($parameters['variable_name'] ?? null)
            && ($operation !== 'rename' || $this->validName($parameters['new_name'] ?? null))
            && ($operation !== 'transform' || in_array($parameters['transform'] ?? 'trim',
                ['trim', 'uppercase', 'lowercase', 'to_number', 'to_string', 'json_encode', 'json_decode'], true));
    }

    public function execute(array $parameters, array $context): array
    {
        if (!$this->validate($parameters)) {
            throw new InvalidArgumentException('Set Variable: operación, nombre o transformación inválida. Los nombres deben empezar con una letra y contener solo letras, números o guion bajo; no pueden ser reservados.');
        }

        $name = $parameters['variable_name'];
        $operation = $parameters['operation'] ?? 'set';
        [$exists, $current] = $this->currentValue($name, $context);
        if ($operation === 'create' && $exists) {
            throw new InvalidArgumentException('Set Variable: la variable que se intenta crear ya existe.');
        }
        if (in_array($operation, ['update', 'rename', 'transform'], true) && !$exists) {
            throw new InvalidArgumentException('Set Variable: la operación requiere una variable existente.');
        }
        $unset = [];
        $value = $parameters['variable_value'] ?? null;
        if ($operation === 'rename') {
            $newName = $parameters['new_name'];
            [$destinationExists] = $this->currentValue($newName, $context);
            if ($destinationExists) {
                throw new InvalidArgumentException('Set Variable: el nombre de destino ya existe.');
            }
            $unset[] = $name;
            $name = $newName;
            $value = $current;
        } elseif ($operation === 'transform') {
            $value = $this->transformValue($current, $parameters['transform'] ?? 'trim');
        }

        return [
            'success' => true,
            'variable' => $name,
            'value' => $value,
            'operation' => $operation,
            '_variable_changes' => ['set' => [$name => $value], 'unset' => $unset]
        ];
    }

    private function validName(mixed $name): bool
    {
        return is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $name) === 1
            && !in_array($name, ['GLOBALS', 'this', 'context', 'parameters'], true);
    }

    private function currentValue(string $name, array $context): array
    {
        $variables = $context['_variables'] ?? [];
        if (is_array($variables) && array_key_exists($name, $variables)) {
            return [true, $variables[$name]];
        }
        $deleted = $context['_deleted_variables'] ?? [];
        if (is_array($deleted) && (array_key_exists($name, $deleted) || in_array($name, $deleted, true))) {
            return [false, null];
        }
        foreach (array_reverse($context) as $output) {
            if (is_array($output) && ($output['variable'] ?? null) === $name && array_key_exists('value', $output)) {
                return [true, $output['value']];
            }
        }
        return [false, null];
    }

    private function transformValue(mixed $value, string $transform): mixed
    {
        if (in_array($transform, ['trim', 'uppercase', 'lowercase'], true)) {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException('Set Variable: esta transformación requiere texto UTF-8.');
            }
            return match ($transform) {
                'trim' => trim($value),
                'uppercase' => mb_strtoupper($value, 'UTF-8'),
                'lowercase' => mb_strtolower($value, 'UTF-8')
            };
        }
        if ($transform === 'to_number') {
            if (is_string($value)) {
                $value = trim($value);
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException('Set Variable: el valor no representa un número.');
                }
                $value += 0;
            }
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) {
                throw new InvalidArgumentException('Set Variable: se requiere un número finito.');
            }
            return $value;
        }
        if ($transform === 'to_string') {
            if ($value === null) {
                return '';
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if (!is_scalar($value) || (is_float($value) && !is_finite($value))) {
                throw new InvalidArgumentException('Set Variable: convertir a texto requiere un valor escalar finito.');
            }
            return (string) $value;
        }
        try {
            if ($transform === 'json_decode') {
                if (!is_string($value)) {
                    throw new InvalidArgumentException('Set Variable: decodificar JSON requiere texto.');
                }
                return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            }
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Set Variable: el valor no se puede convertir a JSON o contiene JSON inválido.');
        }
    }
}
