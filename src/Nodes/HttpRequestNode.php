<?php

namespace P8P\Nodes;

use P8P\Interfaces\NodeInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class HttpRequestNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'http_request',
            'name' => 'HTTP Request',
            'icon' => 'globe',
            'description' => 'Realiza peticiones HTTP/REST a APIs externas',
            'parameters' => [
                'url' => ['type' => 'string', 'required' => true],
                'method' => ['type' => 'select', 'options' => ['GET', 'POST', 'PUT', 'DELETE'], 'default' => 'GET'],
                'headers' => ['type' => 'json', 'required' => false],
                'body' => ['type' => 'json', 'required' => false]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        if (empty($parameters['url']) || !filter_var($parameters['url'], FILTER_VALIDATE_URL)) {
            return false;
        }

        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
        $method = strtoupper($parameters['method'] ?? 'GET');

        return in_array($method, $allowedMethods);
    }

    public function execute(array $parameters, array $context): array
    {
        if (!$this->validate($parameters)) {
            throw new \InvalidArgumentException("Parámetros inválidos para HttpRequestNode");
        }

        $client = new Client(['timeout' => 15.0]);
        $method = strtoupper($parameters['method'] ?? 'GET');
        $url = $parameters['url'];

        $options = [];

        if (!empty($parameters['headers'])) {
            $options['headers'] = is_string($parameters['headers'])
                ? json_decode($parameters['headers'], true)
                : $parameters['headers'];
        }

        if (!empty($parameters['body'])) {
            $options['json'] = is_string($parameters['body'])
                ? json_decode($parameters['body'], true)
                : $parameters['body'];
        }

        try {
            $response = $client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $responseBody = json_decode((string) $response->getBody(), true) ?? (string) $response->getBody();

            return [
                'success' => true,
                'status_code' => $statusCode,
                'data' => $responseBody
            ];
        } catch (GuzzleException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
