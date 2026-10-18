<?php

namespace P8P\Core;

use P8P\Interfaces\NodeInterface;
use InvalidArgumentException;
use RuntimeException;

class Engine
{
    /**
     * Contenedor de instancias de nodos registrados [node_type => NodeInterface]
     */
    private array $registeredNodes = [];

    /**
     * Registra un nuevo tipo de nodo en el motor
     */
    public function registerNode(NodeInterface $node): void
    {
        $schema = $node->getSchema();
        $this->registeredNodes[$schema['type']] = $node;
    }

    /**
     * Ejecuta la estructura completa de un workflow
     *
     * @param array $workflowData Estructura del flujo (nodos y conexiones desde el frontend)
     * @param array $initialContext Datos iniciales (ej. payload de un webhook o evento)
     * @return array Historial detallado de la ejecución por nodo
     */
    public function executeWorkflow(array $workflowData, array $initialContext = []): array
    {
        $nodes = $workflowData['nodes'] ?? [];
        $edges = $workflowData['edges'] ?? [];

        if (empty($nodes)) {
            throw new InvalidArgumentException("El workflow no contiene nodos para ejecutar.");
        }

        // 1. Mapear conexiones (grafo dirigido)
        $adjacencyList = [];
        foreach ($edges as $edge) {
            $source = $edge['source'];
            $target = $edge['target'];
            $adjacencyList[$source][] = $target;
        }

        // 2. Identificar nodo inicial (Start / Webhook)
        $startNode = $this->findStartNode($nodes);
        if (!$startNode) {
            throw new RuntimeException("No se encontró un nodo de inicio en el workflow.");
        }

        // 3. Ejecución secuencial siguiendo las conexiones
        $executionHistory = [];
        $context = $initialContext;
        $queue = [$startNode['id']];
        $visited = [];

        $nodesById = array_column($nodes, null, 'id');

        while (!empty($queue)) {
            $currentNodeId = array_shift($queue);

            if (isset($visited[$currentNodeId])) {
                continue; // Evitar ciclos infinitos
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
            $parameters = $nodeData['data']['parameters'] ?? [];

            // Ejecutar el nodo actual pasándole los parámetros y el contexto acumulado
            $startTime = microtime(true);
            try {
                $output = $nodeInstance->execute($parameters, $context);
                $executionTime = round((microtime(true) - $startTime) * 1000, 2);

                $executionHistory[$currentNodeId] = [
                    'node_id' => $currentNodeId,
                    'type' => $nodeType,
                    'status' => 'success',
                    'execution_time_ms' => $executionTime,
                    'output' => $output
                ];

                // Actualizar contexto global con los datos retornados por este nodo
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

                // Detener la rama de ejecución si ocurre un error sin capturar
                break;
            }

            // Encolar los nodos siguientes conectados a la salida de este
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

    /**
     * Busca el nodo de inicio dentro de la lista
     */
    private function findStartNode(array $nodes): ?array
    {
        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'start' || ($node['data']['is_start'] ?? false)) {
                return $node;
            }
        }
        // Si no está marcado explícitamente, tomamos el primer nodo definido
        return $nodes[0] ?? null;
    }
}
