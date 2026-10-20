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
            'category' => 'Premium',
            'description' => 'Modelo de lenguaje multimodal de Google Gemini con autenticación por credencial o API Key directa.',
            'icon' => 'Sparkles',
            'is_start' => false,
            'help_text' => 'Selecciona una credencial encriptada de Gemini o proporciona una API Key manualmente. Si se seleccionan ambas, la credencial tendrá prioridad.',
            'parameters' => [
                'credential_id' => [
                    'type' => 'credential',
                    'required' => false,
                    'label' => 'Credencial de Gemini'
                ],
                'model' => [
                    'type' => 'select',
                    'required' => true,
                    'label' => 'Modelo',
                    'default' => 'gemini-3.8-flash',
                    'options' => [
                        ['value' => 'gemini-3.8-flash', 'label' => 'Gemini 3.8 Flash (Rápido / Recomendado)'],
                        ['value' => 'gemini-3.5-flash-lite', 'label' => 'Gemini 3.5 Flash Lite (Rápido / Recomendado)'],
                        ['value' => 'gemini-1.5-pro', 'label' => 'Gemini 1.5 Pro (Complejo / Razonamiento)'],
                        ['value' => 'gemini-1.5-flash', 'label' => 'Gemini 1.5 Flash (Balanceado)']
                    ]
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
