<?php

namespace P8P\Nodes\Core;

use InvalidArgumentException;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class CodeNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'code',
            'name' => 'Code (deshabilitado)',
            'category' => 'Core',
            'description' => 'Configuración de código PHP deshabilitada hasta disponer de autorización efectiva de workflows.',
            'icon' => 'Code',
            'is_start' => false,
            'help_text' => 'La ejecución de PHP está deshabilitada por decisión de mantener la autenticación fuera del alcance. Guardar una configuración no habilita su ejecución. PHP libre tiene los privilegios del proceso: no es un sandbox ni tiene un timeout garantizado en este entorno. Contrato previsto, todavía no habilitado: $input y $context contendrían solo datos, sin utilidades ni credenciales automáticas, y el código devolvería un array. El código fuente se conserva literal, sin interpolar {{expresiones}}. Para activar esta capacidad es necesario implementar y revisar autorización efectiva; no existe un parámetro para habilitarla.',
            'parameters' => [
                'code' => [
                    'type' => 'textarea',
                    'required' => true,
                    'label' => 'Código PHP (ejecución deshabilitada)',
                    'placeholder' => 'return ["value" => $input];'
                ],
                'input' => [
                    'type' => 'textarea',
                    'required' => false,
                    'label' => 'Datos de entrada previstos (JSON o expresión)',
                    'default' => '{}',
                    'placeholder' => '{{origen.data}}'
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        $code = $parameters['code'] ?? null;

        return is_string($code)
            && trim($code) !== ''
            && strlen($code) <= 16384
            && mb_check_encoding($code, 'UTF-8');
    }

    public function execute(array $parameters, array $context): array
    {
        if (!$this->validate($parameters)) {
            throw new InvalidArgumentException('Code: indica código UTF-8 no vacío de hasta 16384 bytes. La ejecución permanece deshabilitada.');
        }

        // No interpretar el código, las entradas ni opciones: no existe vía de activación.
        throw new RuntimeException('Code está deshabilitado: requiere autorización efectiva para configurar y ejecutar workflows con PHP.');
    }
}
