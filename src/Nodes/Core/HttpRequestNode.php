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
            'parameters' => [
                'url' => ['type' => 'string', 'required' => true],
                'method' => ['type' => 'string', 'default' => 'GET'],
                'headers' => ['type' => 'string', 'default' => ''],
                'body' => ['type' => 'string', 'default' => '']
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
