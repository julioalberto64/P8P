<?php

namespace P8P\Nodes;

use P8P\Interfaces\NodeInterface;
use GuzzleHttp\Client;

class OllamaChatModelNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'ollama_model',
            'name' => 'Ollama Chat Model',
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
