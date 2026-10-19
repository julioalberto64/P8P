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
            'icon' => 'Wrench', 'description' => 'Set MCP server tool parameters for the flow',
            'parameters' => [
                'server_url' => ['type' => 'string', 'required' => true],
                'tool_name' => ['type' => 'string', 'required' => true]
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
