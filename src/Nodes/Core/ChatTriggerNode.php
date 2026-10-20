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
            'help_text' => 'Punto de inicio para flujos conversacionales. Captura automáticamente el mensaje entrante del usuario ({{user_message}}) y el ID de sesión ({{session_id}}).',
            'parameters' => [
                'session_id' => [
                    'type' => 'string',
                    'required' => false,
                    'label' => 'ID de Sesión (Opcional)',
                    'placeholder' => 'Se generará automáticamente'
                ],
                'user_message' => [
                    'type' => 'string',
                    'required' => false,
                    'label' => 'Mensaje Simulado para Pruebas en Editor',
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
        // 1. Prioridad: Buscar si el payload inyectó user_message o session_id en el contexto del job
        $userMessage = $context['user_message']
            ?? $this->findInContext('user_message', $context)
            ?? $parameters['user_message']
            ?? '';

        $sessionId = $context['session_id']
            ?? $this->findInContext('session_id', $context)
            ?? $parameters['session_id']
            ?? 'session_' . time();

        return [
            'success' => true,
            'variable' => 'user_message', // Registra la variable en el contexto global
            'value' => $userMessage,
            'user_message' => $userMessage,
            'session_id' => $sessionId
        ];
    }

    private function findInContext(string $key, array $context): ?string
    {
        if (isset($context[$key])) {
            return (string)$context[$key];
        }

        foreach ($context as $nodeOutput) {
            if (is_array($nodeOutput) && isset($nodeOutput['variable']) && $nodeOutput['variable'] === $key) {
                return (string)$nodeOutput['value'];
            }
            if (is_array($nodeOutput) && isset($nodeOutput[$key])) {
                return (string)$nodeOutput[$key];
            }
        }

        return null;
    }
}
