<?php

namespace P8P\Nodes\Core;

use P8P\Interfaces\NodeInterface;

class McpToolNode implements NodeInterface
{
    public function getSchema(): array
    {
        return [
            'type' => 'mcp_tool',
            'name' => 'MCP Server Tool',
            'category' => 'Core',
            'description' => 'Ejecuta herramientas y recursos expuestos por servidores remotos de Model Context Protocol (MCP).',
            'icon' => 'Wrench',
            'is_start' => false,
            'help_text' => 'Ingresa la URL del servidor MCP y el nombre exacto de la herramienta registrada. Los argumentos se envían en formato JSON.',
            'parameters' => [
                'server_url' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'URL del Servidor MCP',
                    'placeholder' => 'http://127.0.0.1:8001/mcp'
                ],
                'tool_name' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Nombre de la Herramienta',
                    'placeholder' => 'ej. fetch_user_data'
                ],
                'arguments' => [
                    'type' => 'textarea',
                    'required' => false,
                    'label' => 'Argumentos de Entrada (JSON)',
                    'placeholder' => "{\n  \"user_id\": \"{{user_id}}\"\n}"
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        return !empty($parameters['server_url']);
    }

    public function execute(array $parameters, array $context): array
    {
        return [
            'type' => 'mcp_tool',
            'server_url' => $parameters['server_url'] ?? '',
            'tool_name' => $parameters['tool_name'] ?? 'default_tool'
        ];
    }
}
