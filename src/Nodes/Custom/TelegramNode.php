<?php

namespace P8P\Nodes\Custom;

use P8P\Interfaces\NodeInterface;

class TelegramNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'telegram_bot',
            'name' => 'Telegram Bot',
            'category' => 'Community',
            'description' => 'Envía mensajes a un chat o canal de Telegram',
            'icon' => 'Send',
            'is_start' => false,
            'parameters' => [
                'bot_token' => ['type' => 'string', 'required' => true, 'label' => 'Bot Token'],
                'chat_id' => ['type' => 'string', 'required' => true, 'label' => 'Chat ID'],
                'message' => ['type' => 'string', 'required' => true, 'label' => 'Mensaje']
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        return true;
    }

    public function execute(array $parameters, array $context): array
    {
        // 1. Extraer del array $parameters o buscar en $context (variables de nodos anteriores)
        $botToken = $parameters['bot_token'] ?? $this->findInContext('bot_token', $context) ?? '';
        $chatId   = $parameters['chat_id']   ?? $this->findInContext('chat_id', $context)   ?? '';
        $message  = $parameters['message']   ?? $this->findInContext('message', $context)   ?? '';

        if (empty($botToken) || empty($chatId) || empty($message)) {
            return [
                'success' => false,
                'error' => 'Faltan parámetros requeridos de Telegram (bot_token, chat_id o message)'
            ];
        }

        $botToken = trim(str_replace(' ', '', $botToken));

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        $data = [
            'chat_id' => trim($chatId),
            'text' => $message,
            'parse_mode' => 'HTML'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($response, true);

        return [
            'success' => $httpCode === 200 && ($json['ok'] ?? false),
            'status_code' => $httpCode,
            'response' => $json
        ];
    }

    /**
     * Busca una variable asignada por un nodo Set Variable en el contexto
     */
    private function findInContext(string $key, array $context): ?string
    {
        // Buscar directamente en la raíz del contexto
        if (isset($context[$key])) {
            return (string)$context[$key];
        }

        // Recorrer el historial de salidas de los nodos anteriores
        foreach ($context as $nodeOutput) {
            if (is_array($nodeOutput) && isset($nodeOutput['variable']) && $nodeOutput['variable'] === $key) {
                return (string)$nodeOutput['value'];
            }
        }

        return null;
    }
}
