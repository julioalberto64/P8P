<?php

namespace P8P\Core;

use P8P\Interfaces\NodeInterface;
use P8P\Nodes\Core\LoopNode;
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
                $subConnections[$target][$targetHandle][] = $source;
            } else {
                // Guardar la información completa del enlace para poder evaluar sourceHandle
                $adjacencyList[$source][] = $edge;
            }
        }

        $startNode = $this->findStartNode($nodes);
        if (!$startNode) {
            throw new RuntimeException("No se encontró un nodo de inicio en el workflow.");
        }

        $nodesById = array_column($nodes, null, 'id');
        if (count($nodesById) !== count($nodes)) {
            throw new InvalidArgumentException('Los IDs de nodos deben ser únicos.');
        }
        $hasLoop = count(array_filter($nodes, fn ($node) => ($node['type'] ?? '') === 'loop')) > 0;
        if ($hasLoop) {
            $this->validateLoopGraph($nodesById, $edges, $adjacencyList);
        }

        $executionHistory = [];
        $context = $initialContext;
        $executions = 0;
        $tick = function () use (&$executions): void {
            if (++$executions > 10000) {
                throw new RuntimeException('Se alcanzó el límite global de 10000 ejecuciones de nodos.');
            }
        };

        // Cada bloque tiene su propio conjunto visited, contexto e historial.
        $runBlock = function (array $queue, array &$blockContext, array &$history, array $loopPath = []) use (
            &$runBlock, $nodesById, $adjacencyList, $subConnections, $tick
        ): void {
            $visited = [];
            while ($queue !== []) {
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
                $startTime = microtime(true);
                $entry = ['node_id' => $currentNodeId, 'type' => $nodeType, 'loop_path' => $loopPath];
                try {
                    $tick();
                    if (!isset($this->registeredNodes[$nodeType])) {
                        throw new RuntimeException("El tipo de nodo '{$nodeType}' no está registrado en el motor.");
                    }
                    $nodeInstance = $this->registeredNodes[$nodeType];
                    $rawParameters = $nodeData['data']['parameters'] ?? [];
                    if ($nodeInstance instanceof LoopNode) {
                        if (count($loopPath) >= 16) {
                            throw new RuntimeException('Loop: se alcanzó la profundidad máxima de 16 niveles.');
                        }
                        $bodyIds = [];
                        foreach ($adjacencyList[$currentNodeId] ?? [] as $edge) {
                            if (($edge['sourceHandle'] ?? '') === 'body') {
                                $bodyIds[] = $edge['target'];
                            }
                        }
                        $loopContext = $blockContext;
                        $pending = $bodyIds;
                        $bodyNodes = [];
                        while ($pending !== []) {
                            $bodyId = array_pop($pending);
                            if (isset($bodyNodes[$bodyId])) {
                                continue;
                            }
                            $bodyNodes[$bodyId] = true;
                            unset($loopContext[$bodyId]);
                            foreach ($adjacencyList[$bodyId] ?? [] as $edge) {
                                $pending[] = $edge['target'];
                            }
                        }
                        // No interpolar aquí: Loop debe reevaluar las condiciones originales.
                        $entry['iterations'] = [];
                        $output = $nodeInstance->execute($rawParameters, $loopContext,
                            function (array $iterationContext, array $state) use (
                                &$runBlock, &$entry, $bodyIds, $currentNodeId, $loopPath
                            ): array {
                                $iterationHistory = [];
                                $path = [...$loopPath, ['node_id' => $currentNodeId, 'iteration' => $state['iteration']]];
                                try {
                                    $runBlock($bodyIds, $iterationContext, $iterationHistory, $path);
                                } finally {
                                    $entry['iterations'][] = ['index' => $state['index'], 'iteration' => $state['iteration'],
                                        'history' => $iterationHistory];
                                }
                                // Exportar solo resultados producidos por este bloque, no el contexto exterior.
                                $outputs = [];
                                foreach ($iterationHistory as $id => $record) {
                                    if ($record['status'] === 'success') {
                                        $outputs[$id] = $record['output'];
                                    }
                                }
                                return $outputs;
                            }, $currentNodeId);
                    } else {
                        $evaluatedParameters = ExpressionEvaluator::evaluate($rawParameters, $blockContext);
                        $subNodeOutputs = [];
                        foreach ($subConnections[$currentNodeId] ?? [] as $handle => $sourceIds) {
                            foreach ($sourceIds as $sourceId) {
                                $sourceType = $nodesById[$sourceId]['type'] ?? '';
                                if (!isset($this->registeredNodes[$sourceType])) {
                                    throw new RuntimeException("Subnodo no registrado: {$sourceId}.");
                                }
                                $tick();
                                $entry['active_subnode'] = $sourceId;
                                $sourceInstance = $this->registeredNodes[$sourceType];
                                $sourceParams = ExpressionEvaluator::evaluate(
                                    $nodesById[$sourceId]['data']['parameters'] ?? [], $blockContext
                                );
                                $subOutput = $sourceInstance->execute($sourceParams, $blockContext);
                                if ($loopPath !== [] && ($subOutput['success'] ?? null) === false) {
                                    throw new RuntimeException("El subnodo {$sourceId} devolvió success=false.");
                                }
                                $subNodeOutputs[$handle][] = $subOutput;
                                unset($entry['active_subnode']);
                            }
                        }
                        $evaluatedParameters['_sub_connections'] = $subNodeOutputs;
                        $entry['evaluated_parameters'] = $evaluatedParameters;
                        $output = $nodeInstance->execute($evaluatedParameters, $blockContext);
                    }
                    if ($loopPath !== [] && ($output['success'] ?? null) === false) {
                        throw new RuntimeException("El nodo {$currentNodeId} devolvió success=false.");
                    }
                    $entry['status'] = 'success';
                    $entry['output'] = $output;
                    $blockContext[$currentNodeId] = $output;
                } catch (\Throwable $e) {
                    $entry['status'] = 'failed';
                    $entry['error'] = $e->getMessage();
                    throw $e;
                } finally {
                    $entry['execution_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
                    $history[$currentNodeId] = $entry;
                }

                foreach ($adjacencyList[$currentNodeId] ?? [] as $edge) {
                    $handle = $edge['sourceHandle'] ?? 'main_output';
                    if ($nodeType === 'loop' && $handle !== 'done') {
                        continue;
                    }
                    if (isset($output['branch']) && $handle !== $output['branch']) {
                        continue;
                    }
                    if (!isset($visited[$edge['target']])) {
                        $queue[] = $edge['target'];
                    }
                }
            }
        };

        $status = 'completed';
        $error = null;
        try {
            $runBlock([$startNode['id']], $context, $executionHistory);
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
        }
        $result = ['status' => $status, 'history' => $executionHistory, 'final_context' => $context];
        if ($error !== null) {
            $result['error'] = $error;
        }
        return $result;
    }

    /**
     * Un bloque estructurado termina en sus hojas. Prohibir ciclos y entradas externas
     * evita ejecutar efectos antes de descubrir un retorno o una continuación ambigua.
     */
    private function validateLoopGraph(array $nodesById, array $edges, array $adjacencyList): void
    {
        foreach ($edges as $edge) {
            if (!isset($nodesById[$edge['source']], $nodesById[$edge['target']])) {
                throw new InvalidArgumentException('Loop: una conexión referencia un nodo inexistente.');
            }
            $sourceLoop = ($nodesById[$edge['source']]['type'] ?? '') === 'loop';
            $targetLoop = ($nodesById[$edge['target']]['type'] ?? '') === 'loop';
            $targetHandle = $edge['targetHandle'] ?? 'main_input';
            if (($sourceLoop || $targetLoop) && $targetHandle !== 'main_input' && $targetHandle !== '') {
                throw new InvalidArgumentException('Loop no admite conexiones por subpuertos.');
            }
            if ($sourceLoop && !in_array($edge['sourceHandle'] ?? '', ['body', 'done'], true)) {
                throw new InvalidArgumentException('Loop: utiliza las salidas body y done.');
            }
        }
        $colors = [];
        $visit = function (string $id) use (&$visit, &$colors, $adjacencyList): void {
            if (($colors[$id] ?? 0) === 1) {
                throw new InvalidArgumentException('Loop: el grafo contiene un ciclo; el cuerpo no debe conectarse de vuelta.');
            }
            if (($colors[$id] ?? 0) === 2) {
                return;
            }
            $colors[$id] = 1;
            foreach ($adjacencyList[$id] ?? [] as $edge) {
                $visit($edge['target']);
            }
            $colors[$id] = 2;
        };
        foreach (array_keys($nodesById) as $id) {
            $visit((string) $id);
        }
        $reachable = function (array $roots) use ($adjacencyList): array {
            $found = [];
            while ($roots !== []) {
                $id = array_pop($roots);
                if (isset($found[$id])) {
                    continue;
                }
                $found[$id] = true;
                foreach ($adjacencyList[$id] ?? [] as $edge) {
                    $roots[] = $edge['target'];
                }
            }
            return $found;
        };
        foreach ($nodesById as $id => $node) {
            if (($node['type'] ?? '') !== 'loop') {
                continue;
            }
            $bodyRoots = [];
            $doneRoots = [];
            foreach ($adjacencyList[$id] ?? [] as $edge) {
                if ($edge['sourceHandle'] === 'body') {
                    $bodyRoots[] = $edge['target'];
                } else {
                    $doneRoots[] = $edge['target'];
                }
            }
            if (count($bodyRoots) !== 1 || count($doneRoots) > 1) {
                throw new InvalidArgumentException('Loop: conecta exactamente un inicio de Cuerpo y como máximo un inicio de Finalizado.');
            }
            $body = $reachable($bodyRoots);
            if (array_intersect_key($body, $reachable($doneRoots)) !== []) {
                throw new InvalidArgumentException('Loop: Cuerpo y Finalizado deben ser bloques separados.');
            }
            foreach ($edges as $edge) {
                if (!empty($edge['targetHandle']) && $edge['targetHandle'] !== 'main_input') {
                    continue;
                }
                if (isset($body[$edge['target']]) && !isset($body[$edge['source']])
                    && !($edge['source'] === $id && ($edge['sourceHandle'] ?? '') === 'body')) {
                    throw new InvalidArgumentException('Loop: el cuerpo no puede recibir conexiones desde fuera de su bloque.');
                }
            }
        }
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
