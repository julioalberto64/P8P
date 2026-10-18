<?php

namespace P8P\Core;

use P8P\Interfaces\NodeInterface;
use InvalidArgumentException;
use RuntimeException;

class Engine
{
    private array $registeredNodes = [];

    /**
     * Registra un nodo en el motor
     */
    public function registerNode(NodeInterface $node): void
    {
        $schema = $node->getSchema();
        $this->registeredNodes[$schema['type']] = $node;
    }

    /**
     * Ejecuta la secuencia del grafo resolviendo el flujo principal y los sub-puertos (model, memory, tools)
     */
    public function executeWorkflow(array $workflowData, array $initialContext = []): array
    {
        $nodes = $workflowData['nodes'] ?? [];
        $edges = $workflowData['edges'] ?? [];

        if (empty($nodes)) {
            throw new InvalidArgumentException("El workflow no contiene nodos para ejecutar.");
        }

        // Separar las conexiones principales de la secuencia de las sub-conexiones de soporte
        $adjacencyList = [];
        $subConnections = [];

        foreach ($edges as $edge) {
            $source = $edge['source'];
            $target = $edge['target'];
            $targetHandle = $edge['targetHandle'] ?? 'main_input';

            if ($targetHandle !== 'main_input' && !empty($targetHandle)) {
                // Conexión dirigida a sub-puertos inferiores (model, memory, tools)
                $subConnections[$target][$targetHandle][] = $source;
            } else {
                // Flujo principal de ejecución
                $adjacencyList[$source][] = $target;
            }
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

            // 1. Obtener parámetros crudos desde el frontend
            $rawParameters = $nodeData['data']['parameters'] ?? [];

            // 2. Interpolar expresiones dinámicas {{node_id.key}} o {{variable}} usando el contexto acumulado
            $evaluatedParameters = ExpressionEvaluator::evaluate($rawParameters, $context);

            // 3. Resolver sub-nodos conectados a los sub-puertos inferiores (model, memory, tools)
            $nodeSubConnections = $subConnections[$currentNodeId] ?? [];
            $subNodeOutputs = [];

            foreach ($nodeSubConnections as $handle => $sourceNodeIds) {
                foreach ($sourceNodeIds as $sourceId) {
                    if (isset($nodesById[$sourceId])) {
                        $sourceType = $nodesById[$sourceId]['type'] ?? '';
                        if (isset($this->registeredNodes[$sourceType])) {
                            $sourceInstance = $this->registeredNodes[$sourceType];
                            $sourceParams = ExpressionEvaluator::evaluate(
                                $nodesById[$sourceId]['data']['parameters'] ?? [],
                                $context
                            );
                            $subNodeOutputs[$handle][] = $sourceInstance->execute($sourceParams, $context);
                        }
                    }
                }
            }

            $evaluatedParameters['_sub_connections'] = $subNodeOutputs;

            // 4. Ejecutar el nodo principal
            $startTime = microtime(true);
            try {
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

                // Guardar resultado en el contexto global usando el ID del nodo
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

                break; // Detener la ejecución si ocurre un error no controlado
            }

            // Encolar los siguientes nodos en el flujo principal
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
