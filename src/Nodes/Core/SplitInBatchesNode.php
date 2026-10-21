<?php

namespace P8P\Nodes\Core;

use InvalidArgumentException;
use LogicException;
use P8P\Core\ExpressionEvaluator;

class SplitInBatchesNode extends LoopNode
{
    public function getSchema(): array
    {
        return [
            'type' => 'split_in_batches',
            'name' => 'Split In Batches',
            'category' => 'Core',
            'description' => 'Ejecuta un bloque por cada lote de una colección.',
            'icon' => 'Layers',
            'is_start' => false,
            'help_text' => 'Conecta Cuerpo al bloque y Finalizado al siguiente nodo exterior; no conectes el cuerpo de vuelta. Usa {{ID.batch}}, {{ID.batch_index}} (desde 0), {{ID.item_count}} y {{ID.batch_count}} dentro del bloque. Después usa {{ID.batches}} y {{ID.results}}. Cada lote tiene contexto independiente. Las claves originales se conservan. La colección completa permanece en memoria: esto no es streaming. Máximo 1000 lotes, 16 niveles y 10000 ejecuciones por workflow.',
            'parameters' => [
                'collection' => [
                    'type' => 'textarea', 'label' => 'Colección', 'required' => true,
                    'placeholder' => '[{"id":1},{"id":2}] o {{origen.items}}'
                ],
                'batch_size' => [
                    'type' => 'string', 'label' => 'Elementos por lote (1–1000)',
                    'required' => true, 'default' => '10'
                ],
                'max_iterations' => [
                    'type' => 'string', 'label' => 'Límite de lotes (1–1000)',
                    'required' => true, 'default' => '1000'
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->batchConfiguration($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context, ?callable $executeBody = null, string $nodeId = 'loop'): array
    {
        $configuration = $this->batchConfiguration(ExpressionEvaluator::evaluate($parameters, $context));
        if ($executeBody === null) {
            throw new LogicException('Split In Batches requiere el ejecutor de bloques de Engine.');
        }

        $batches = array_chunk($configuration['collection'], $configuration['batch_size'], true);
        $batchCount = count($batches);
        $output = parent::execute([
            'mode' => 'collection',
            'collection' => $batches,
            'max_iterations' => $configuration['max_iterations']
        ], $context, function (array $iterationContext, array $state) use ($executeBody, $nodeId, $batchCount): array {
            $state = array_merge($state, [
                'mode' => 'split_in_batches',
                'batch' => $state['item'],
                'batch_index' => $state['index'],
                'item_count' => count($state['item']),
                'batch_count' => $batchCount
            ]);
            $iterationContext[$nodeId] = $state;
            return $executeBody($iterationContext, $state);
        }, $nodeId);

        $output['mode'] = 'split_in_batches';
        $output['batches'] = $output['iterations'];
        $output['batch_count'] = $batchCount;
        $output['item_count'] = count($configuration['collection']);
        $output['batch_size'] = $configuration['batch_size'];
        foreach ($output['results'] as &$result) {
            $result['batch'] = $result['item'];
            $result['batch_index'] = $result['index'];
            $result['item_count'] = count($result['batch']);
        }
        unset($result);
        return $output;
    }

    private function batchConfiguration(array $parameters): array
    {
        $batchSize = $this->batchInteger($parameters['batch_size'] ?? 10);
        $limit = $this->batchInteger($parameters['max_iterations'] ?? self::MAX_ITERATIONS);
        $collection = $parameters['collection'] ?? null;
        if (is_string($collection)) {
            try {
                $decoded = json_decode($collection, false, 64, JSON_THROW_ON_ERROR);
                $collection = is_array($decoded)
                    ? json_decode($collection, true, 64, JSON_THROW_ON_ERROR) : null;
            } catch (\JsonException $e) {
                throw new InvalidArgumentException('Split In Batches: indica un array o un array JSON válido.');
            }
        }
        if (!is_array($collection)) {
            throw new InvalidArgumentException('Split In Batches: la colección debe ser un array o un array JSON válido.');
        }
        if (count($collection) > $batchSize * $limit) {
            throw new InvalidArgumentException('Split In Batches: la colección supera el límite de lotes.');
        }
        return ['collection' => $collection, 'batch_size' => $batchSize, 'max_iterations' => $limit];
    }

    private function batchInteger(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[0-9]+$/D', trim($value)) === 1) {
            $value = filter_var(trim($value), FILTER_VALIDATE_INT);
        }
        if (!is_int($value) || $value < 1 || $value > self::MAX_ITERATIONS) {
            throw new InvalidArgumentException('Split In Batches: tamaño y límite deben ser enteros entre 1 y 1000.');
        }
        return $value;
    }
}
