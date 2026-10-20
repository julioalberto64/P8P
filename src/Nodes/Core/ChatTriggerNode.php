<?php

namespace P8P\Nodes\Core;

use P8P\Interfaces\NodeInterface;

class ChatTriggerNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'chat_trigger',
            'name' => 'Chat Trigger',
            'category' => 'Core',
            'description' => 'Disparador de entrada para conversaciones mediante el Widget JS flotante (p8p-chat.js).',
            'icon' => 'MessageSquare',
            'is_start' => true,
            'help_text' => 'Punto de inicio para flujos conversacionales. Captura el mensaje entrante del usuario ({{user_message}}) y el ID de sesión ({{session_id}}) desde el widget web.',
            'parameters' => [
                'session_id' => [
                    'type' => 'string',
                    'required' => false,
                    'label' => 'ID de Sesión por Defecto',
                    'default' => 'default_session',
                    'placeholder' => 'ej. session_12345'
                ],
                'user_message' => [
                    'type' => 'string',
                    'required' => false,
                    'label' => 'Mensaje Simulado (Testing)',
                    'placeholder' => 'Escribe un mensaje de prueba...'
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
        $message = $parameters['user_message'] ?? $context['user_message'] ?? '';
        $sessionId = $parameters['session_id'] ?? $context['session_id'] ?? 'session_' . time();

        return [
            'success' => true,
            'session_id' => $sessionId,
            'user_message' => $message,
            'output_text' => $message
        ];
    }
}
