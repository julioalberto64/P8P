<?php

namespace P8P\Nodes\Custom;

use InvalidArgumentException;
use P8P\Interfaces\NodeInterface;

class RespondToChatNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'respond_to_chat',
            'name' => 'Respond to Chat',
            'category' => 'Community',
            'description' => 'Define el mensaje de respuesta de una conversación iniciada por Chat Trigger.',
            'icon' => 'MessageSquare',
            'is_start' => false,
            'help_text' => 'Conecta este nodo después de Chat Trigger o del nodo que genera la respuesta. Usa {{node_id.output_text}} para una respuesta de IA. La sesión se toma del Chat Trigger si no se indica. La entrega al widget requiere que p8p-chat.js consulte el resultado del trabajo.',
            'parameters' => [
                'text' => [
                    'type' => 'textarea',
                    'required' => true,
                    'label' => 'Mensaje de respuesta',
                    'placeholder' => 'Hola, ¿en qué puedo ayudarte? o {{node_id.output_text}}'
                ],
                'session_id' => [
                    'type' => 'string',
                    'required' => false,
                    'label' => 'ID de sesión (opcional)',
                    'placeholder' => 'Se obtiene del Chat Trigger o {{node_id.session_id}}'
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        $text = $parameters['text'] ?? null;
        $session = $parameters['session_id'] ?? '';

        return is_string($text)
            && trim($text) !== ''
            && mb_check_encoding($text, 'UTF-8')
            && mb_strlen($text, 'UTF-8') <= 40000
            && is_string($session)
            && mb_check_encoding($session, 'UTF-8')
            && strlen($session) <= 255;
    }

    public function execute(array $parameters, array $context): array
    {
        if (!$this->validate($parameters)) {
            throw new InvalidArgumentException('Respond to Chat: indica un texto UTF-8 de 1 a 40000 caracteres y una sesión de hasta 255 bytes si corresponde.');
        }

        $session = trim($parameters['session_id'] ?? '');
        if ($session === '') {
            // El motor entrega salidas anteriores por ID. No buscar en resultados arbitrarios.
            foreach ($context as $output) {
                if (is_array($output) && ($output['variable'] ?? null) === 'user_message'
                    && isset($output['user_message']) && is_string($output['session_id'] ?? null)) {
                    $session = trim($output['session_id']);
                    break;
                }
            }
        }

        if ($session === '' || strlen($session) > 255 || !mb_check_encoding($session, 'UTF-8')) {
            throw new InvalidArgumentException('Respond to Chat: no se encontró una sesión válida. Conecta un Chat Trigger o indica session_id.');
        }

        return [
            'success' => true,
            'response' => $parameters['text'],
            'session_id' => $session
        ];
    }
}
