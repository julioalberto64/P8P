<?php

namespace P8P\Core;

use P8P\Interfaces\NodeInterface;
use InvalidArgumentException;
use RuntimeException;

class Engine
{
    private array $registeredNodes = [];

    public function registerNode(NodeInterface $node): void
    {
        $schema = $node->getSchema();
        $this->registeredNodes[$schema['type']] = $node;
    }

    public function executeWorkflow(array $workflowData, array $initialContext = []): array
    {
        $nodes = $workflowData['nodes'] ?? [];
        $edges = $workflowData['edges'] ?? [];

        if (empty($nodes)) {
            throw new InvalidArgumentException("El workflow no contiene nodos para ejecutar.");
        }

        $adjacencyList = [];
        foreach ($edges as $edge) {
            $source = $edge['source'];
            $target = $edge['target'];
            $adjacencyList[$source][] = $target;
        }

        $startNode = $this->findStartNode($nodes);
        if (!$startNode) {
            throw new RuntimeException("No se encontró un nodo de inicio en el workflow.");
        }

        $executionHistory = [];
        $context = $initialContext;
        $queue = [$startNode['id']];
        $visited = [];

        $nodesById = array_column($nodes, null, 'id');

        while (!empty($queue)) {
            $currentNodeId = array_shift($queue);

            if (isset($visited[$currentNodeId])) {
                continue;
            }

            $visited[$currentNodeId] = true;
            $nodeData = $nodesById[$currentNodeId] ?? null;

            if (!$nodeData) {
                continue;
            }

            $nodeType = $nodeData['type'] ?? '';

            if (!isset($this->registeredNodes[$nodeType])) {
                throw new RuntimeException("El tipo de nodo '{$nodeType}' no está registrado en el motor.");
            }

            /** @var NodeInterface $nodeInstance */
            $nodeInstance = $this->registeredNodes[$nodeType];

            // 1. Obtener parámetros crudos enviados desde el frontend
            $rawParameters = $nodeData['data']['parameters'] ?? [];

            // 2. Interpolar/evaluar expresiones {{node_id.key}} usando el contexto acumulado
            $evaluatedParameters = ExpressionEvaluator::evaluate($rawParameters, $context);

            $startTime = microtime(true);
            try {
                // 3. Ejecutar el nodo pasándole los parámetros ya evaluados
                $output = $nodeInstance->execute($evaluatedParameters, $context);
                $executionTime = round((microtime(true) - $startTime) * 1000, 2);

                $executionHistory[$currentNodeId] = [
                    'node_id' => $currentNodeId,
                    'type' => $nodeType,
                    'status' => 'success',
                    'execution_time_ms' => $executionTime,
                    'evaluated_parameters' => $evaluatedParameters,
                    'output' => $output
                ];

                // 4. Guardar en el contexto usando el ID del nodo como namespace
                $context[$currentNodeId] = $output;

            } catch (\Throwable $e) {
                $executionTime = round((microtime(true) - $startTime) * 1000, 2);

                $executionHistory[$currentNodeId] = [
                    'node_id' => $currentNodeId,
                    'type' => $nodeType,
                    'status' => 'failed',
                    'execution_time_ms' => $executionTime,
                    'error' => $e->getMessage()
                ];

                break;
            }

            if (isset($adjacencyList[$currentNodeId])) {
                foreach ($adjacencyList[$currentNodeId] as $nextId) {
                    if (!isset($visited[$nextId])) {
                        $queue[] = $nextId;
                    }
                }
            }
        }

        return [
            'status' => 'completed',
            'history' => $executionHistory,
            'final_context' => $context
        ];
    }

    private function findStartNode(array $nodes): ?array
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'start' || ($node['data']['is_start'] ?? false)) {
                return $node;
            }
        }
        return $nodes[0] ?? null;
    }
}
