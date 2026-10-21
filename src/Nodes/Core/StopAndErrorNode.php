<?php

namespace P8P\Nodes\Core;

use InvalidArgumentException;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class StopAndErrorNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'stop_and_error',
            'name' => 'Stop And Error',
            'category' => 'Core',
            'description' => 'Detiene inmediatamente el workflow con un mensaje de error personalizado.',
            'icon' => 'OctagonAlert',
            'is_start' => false,
            'help_text' => 'Detiene todo el workflow, incluidas las rutas pendientes y la continuación de un Loop o lote. El historial registra el mensaje y la iteración correspondiente. Admite {{origen.campo}}; usa un texto de 1 a 2000 caracteres, sin secretos porque se conserva en el historial.',
            'parameters' => [
                'message' => [
                    'type' => 'textarea',
                    'required' => true,
                    'label' => 'Mensaje de error',
                    'placeholder' => 'No se puede continuar: {{origen.motivo}}'
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        $message = $parameters['message'] ?? null;

        return is_string($message)
            && mb_check_encoding($message, 'UTF-8')
            && trim($message) !== ''
            && mb_strlen($message, 'UTF-8') <= 2000
            && !str_contains($message, '{{')
            && !str_contains($message, '}}');
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine resuelve las expresiones una sola vez antes de ejecutar el nodo.
        if (!$this->validate($parameters)) {
            throw new InvalidArgumentException('Stop And Error: indica un mensaje UTF-8 de 1 a 2000 caracteres, sin expresiones pendientes.');
        }

        throw new RuntimeException($parameters['message']);
    }
}
