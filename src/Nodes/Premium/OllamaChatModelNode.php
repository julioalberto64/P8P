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
            'category' => 'Premium',
            'description' => 'Conecta con modelos de lenguaje locales o remotos ejecutados en Ollama.',
            'icon' => 'Cpu',
            'is_start' => false,
            'help_text' => 'Asegúrate de que la instancia de Ollama esté activa. Puedes especificar el endpoint base (ej. http://127.0.0.1:11434) y el modelo descargado (ej. llama3, mistral o phi3).',
            'parameters' => [
                'endpoint' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Endpoint de Ollama',
                    'default' => 'http://127.0.0.1:11434',
                    'placeholder' => 'http://127.0.0.1:11434'
                ],
                'model' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Nombre del Modelo',
                    'default' => 'llama3',
                    'placeholder' => 'ej. llama3, mistral, codellama'
                ],
                'temperature' => [
                    'type' => 'number',
                    'required' => false,
                    'label' => 'Temperatura (0.0 a 1.0)',
                    'default' => 0.7,
                    'placeholder' => '0.7'
                ]
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
