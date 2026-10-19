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
            'icon' => 'MessageSquare', 'description' => 'Detonador de chat flotante JS',
            'parameters' => [
                'session_id' => ['type' => 'string', 'default' => 'default_session'],
                'user_message' => ['type' => 'string', 'default' => '']
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
