<?php

namespace P8P\Nodes\Core;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use P8P\Interfaces\NodeInterface;

class HttpRequestNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'http_request',
            'name' => 'HTTP Request',
            'category' => 'Core',
            'description' => 'Realiza peticiones HTTP (GET, POST, PUT, DELETE) a servicios externos.',
            'icon' => 'Globe',
            'is_start' => false,
            'help_text' => 'Permite conectar tu flujo con APIs web. Puedes usar sintaxis {{variable}} en la URL, Headers o Body para enviar datos dinámicos.',
            'parameters' => [
                'url' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'URL de Destino',
                    'placeholder' => 'https://api.ejemplo.com/v1/data'
                ],
                'method' => [
                    'type' => 'select',
                    'required' => true,
                    'label' => 'Método HTTP',
                    'default' => 'GET',
                    'options' => [
                        ['value' => 'GET', 'label' => 'GET'],
                        ['value' => 'POST', 'label' => 'POST'],
                        ['value' => 'PUT', 'label' => 'PUT'],
                        ['value' => 'DELETE', 'label' => 'DELETE']
                    ]
                ],
                'headers' => [
                    'type' => 'textarea',
                    'required' => false,
                    'label' => 'Encabezados (JSON)',
                    'placeholder' => "{\n  \"Content-Type\": \"application/json\"\n}"
                ],
                'body' => [
                    'type' => 'textarea',
                    'required' => false,
                    'label' => 'Cuerpo de la Petición (Body)',
                    'placeholder' => "{\n  \"key\": \"{{value}}\"\n}"
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        return !empty($parameters['url']);
    }

    public function execute(array $parameters, array $context): array
    {
        $url = $parameters['url'] ?? '';
        $method = strtoupper($parameters['method'] ?? 'GET');
        $rawHeaders = $parameters['headers'] ?? '';
        $rawBody = $parameters['body'] ?? '';

        $client = new Client(['timeout' => 30]);
        $options = [];

        // 1. Procesar Headers
        $headers = [];
        if (!empty($rawHeaders)) {
            if (is_array($rawHeaders)) {
                $headers = $rawHeaders;
            } elseif (is_string($rawHeaders)) {
                $decoded = json_decode($rawHeaders, true);
                if (is_array($decoded)) {
                    $headers = $decoded;
                }
            }
        }
        if (!empty($headers)) {
            $options['headers'] = $headers;
        }

        // 2. Procesar Body (POST, PUT, PATCH)
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($rawBody)) {
            if (is_array($rawBody)) {
                $options['json'] = $rawBody;
            } elseif (is_string($rawBody)) {
                $decodedBody = json_decode($rawBody, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedBody)) {
                    // Si el cuerpo es un JSON válido, se envía mediante la opción 'json' de Guzzle
                    $options['json'] = $decodedBody;
                } else {
                    $options['body'] = $rawBody;
                }
            }
        }

        try {
            $response = $client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $responseContent = (string)$response->getBody();

            $decodedResponse = json_decode($responseContent, true);
            $data = (json_last_error() === JSON_ERROR_NONE) ? $decodedResponse : $responseContent;

            return [
                'success' => true,
                'status_code' => $statusCode,
                'data' => $data
            ];
        } catch (RequestException $e) {
            $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;
            $errorBody = $e->hasResponse() ? (string)$e->getResponse()->getBody() : $e->getMessage();

            return [
                'success' => false,
                'status_code' => $statusCode,
                'error' => $errorBody
            ];
        }
    }
}
