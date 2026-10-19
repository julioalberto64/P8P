<?php

namespace P8P\Nodes\Premium;

use P8P\Interfaces\NodeInterface;

class OllamaChatModelNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'ollama_model',
            'name' => 'Ollama Chat Model',
            'icon' => 'Cpu', 'category' => 'Premium', 'description' => 'Modelo LLM local o remoto',
            'parameters' => [
                'endpoint' => ['type' => 'string', 'default' => 'http://127.0.0.1:11434'],
                'model' => ['type' => 'string', 'default' => 'llama3']
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        return !empty($parameters['endpoint']) && !empty($parameters['model']);
    }

    public function execute(array $parameters, array $context): array
    {
        return [
            'type' => 'chat_model',
            'provider' => 'ollama',
            'endpoint' => $parameters['endpoint'] ?? 'http://127.0.0.1:11434',
            'model' => $parameters['model'] ?? 'llama3'
        ];
    }
}
