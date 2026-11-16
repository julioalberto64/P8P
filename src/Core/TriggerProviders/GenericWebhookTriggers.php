<?php

namespace P8P\Core\TriggerProviders;

use InvalidArgumentException;
use P8P\Interfaces\TriggerProviderInterface;

/** Generic HTTP POST webhook receiver for custom forms, landing pages, and external services. */
class GenericWebhookTriggers implements TriggerProviderInterface
{
    public function definitions(): array
    {
        return [
            'generic_webhook' => [
                'name' => 'Webhook Genérico: HTTP POST',
                'node_type' => 'webhook',
                'event' => 'http.post',
                'transport' => 'webhook',
                'config_fields' => [
                    'allowed_origin' => [
                        'type' => 'string',
                        'label' => 'Origen CORS permitido (opcional, ej. https://p8p.info)',
                        'required' => false,
                    ],
                ],
                'secret_fields' => [
                    'bearer_token' => [
                        'type' => 'string',
                        'label' => 'Token Bearer / X-Webhook-Secret (opcional; vacío = público por URL)',
                        'required' => false,
                    ],
                ],
                'help' => 'Recibe peticiones HTTP POST en formato JSON o form-urlencoded desde cualquier formulario web, landing page o aplicación externa. Si configuras una credencial con bearer_token, exigirá cabecera Authorization: Bearer <token> o X-Webhook-Secret.',
                'docs' => ['https://p8p.info'],
            ],
        ];
    }

    public function verify(string $key, array $config, array $secrets, array $request, int $now): array
    {
        $def = $this->definitions()[$key] ?? null;
        if (!$def || ($request['method'] ?? null) !== 'POST') {
            throw new InvalidArgumentException('Webhook Genérico: solo se aceptan peticiones POST.');
        }

        // Validación opcional si se vinculó una credencial con bearer_token
        $expectedToken = trim((string)($secrets['bearer_token'] ?? $secrets['api_key'] ?? ''));
        if ($expectedToken !== '') {
            $headers = $request['headers'] ?? [];
            $authHeader = trim((string)($headers['authorization'] ?? ''));
            $customHeader = trim((string)($headers['x-webhook-secret'] ?? ''));

            $providedToken = '';
            if (stripos($authHeader, 'Bearer ') === 0) {
                $providedToken = trim(substr($authHeader, 7));
            } elseif ($customHeader !== '') {
                $providedToken = $customHeader;
            }

            if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
                throw new InvalidArgumentException('Webhook Genérico: token de autorización inválido o ausente.');
            }
        }

        $rawBody = (string)($request['raw_body'] ?? '');
        if (strlen($rawBody) > 1048576) { // Límite 1 MB
            throw new InvalidArgumentException('Webhook Genérico: el cuerpo excede el máximo permitido (1 MB).');
        }

        $payload = [];
        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            } elseif (!empty($request['form_fields']) && is_array($request['form_fields'])) {
                $payload = $request['form_fields'];
            } else {
                parse_str($rawBody, $parsedForm);
                $payload = is_array($parsedForm) && !empty($parsedForm)
                    ? $parsedForm
                    : ['raw' => $rawBody];
            }
        } elseif (!empty($request['form_fields']) && is_array($request['form_fields'])) {
            $payload = $request['form_fields'];
        }

        $eventId = hash('sha256', $now . ':' . $rawBody . ':' . json_encode($payload));

        return [
            'status' => 200,
            'content_type' => 'application/json',
            'body' => json_encode(['success' => true, 'event_id' => $eventId]),
            'events' => [
                [
                    'id' => $eventId,
                    'type' => 'http.post',
                    'data' => $payload,
                ],
            ],
        ];
    }

    public function poll(string $key, array $config, array $secrets, array $state, int $now): array
    {
        return ['events' => [], 'state' => $state, 'has_more' => false];
    }
}