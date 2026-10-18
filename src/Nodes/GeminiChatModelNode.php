<?php

namespace P8P\Nodes;

use P8P\Interfaces\NodeInterface;

class GeminiChatModelNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'gemini_model',
            'name' => 'Google Gemini Chat Model',
            'parameters' => [
                'api_key' => ['type' => 'string', 'required' => true],
                'model' => ['type' => 'string', 'default' => 'gemini-3.8-flash'],
                'temperature' => ['type' => 'float', 'default' => 0.7]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        return !empty($parameters['api_key']);
    }

    public function execute(array $parameters, array $context): array
    {
        return [
            'type' => 'chat_model',
            'provider' => 'gemini',
            'api_key' => $parameters['api_key'] ?? '',
            'model' => $parameters['model'] ?? 'gemini-3.8-flash',
            'temperature' => (float)($parameters['temperature'] ?? 0.7)
        ];
    }
}
