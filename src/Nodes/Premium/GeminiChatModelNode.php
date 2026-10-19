<?php

namespace P8P\Nodes\Premium;

use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;

class GeminiChatModelNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'gemini_model',
            'name' => 'Google Gemini Chat Model',
            'icon' => 'Sparkles',
            'description' => 'Modelo de chat LLM de Google Gemini',
            'parameters' => [
                'credential_id' => ['type' => 'string', 'required' => false],
                'api_key' => ['type' => 'string', 'required' => false],
                'model' => ['type' => 'string', 'default' => 'gemini-3.8-flash'],
                'temperature' => ['type' => 'float', 'default' => 0.7]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        return !empty($parameters['credential_id']) || !empty($parameters['api_key']);
    }

    public function execute(array $parameters, array $context): array
    {
        $apiKey = $parameters['api_key'] ?? '';

        // Si se proporcionó un ID de credencial, descifrar la clave de MariaDB
        if (!empty($parameters['credential_id'])) {
            $cm = new CredentialManager();
            $cred = $cm->getCredentialDecrypted($parameters['credential_id']);
            if ($cred && !empty($cred['data']['api_key'])) {
                $apiKey = $cred['data']['api_key'];
            }
        }

        return [
            'type' => 'chat_model',
            'provider' => 'gemini',
            'api_key' => $apiKey,
            'model' => $parameters['model'] ?? 'gemini-3.8-flash',
            'temperature' => (float)($parameters['temperature'] ?? 0.7)
        ];
    }
}
