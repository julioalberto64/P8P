<?php

namespace P8P\Nodes\Core;

use DateTimeImmutable;
use InvalidArgumentException;
use P8P\Core\ExpressionEvaluator;
use P8P\Interfaces\NodeInterface;

class DelayNode implements NodeInterface
{
    public const MAX_WAIT_SECONDS = 2592000;

    public function getSchema(): array
    {
        return [
            'type' => 'delay',
            'name' => 'Delay',
            'category' => 'Core',
            'description' => 'Suspende el flujo hasta un instante y lo reanuda mediante la cola.',
            'icon' => 'Clock',
            'is_start' => false,
            'help_text' => 'Máximo 30 días. Intervalo usa cantidades enteras; cero continúa inmediatamente. Hasta una fecha requiere YYYY-MM-DDTHH:MM:SSZ o YYYY-MM-DDTHH:MM:SS±HH:MM, con zona explícita. El motor guarda el contexto y la continuación sin mantener ocupado el worker. La API devuelve status=suspended y job_id mientras espera. La precisión depende de cron, normalmente cerca de un minuto; no garantiza el segundo exacto. Salidas: resume_at (Unix), wait_seconds y scheduled_at (UTC).',
            'parameters' => [
                'mode' => [
                    'type' => 'select', 'label' => 'Modo', 'required' => true, 'default' => 'interval',
                    'options' => [
                        ['value' => 'interval', 'label' => 'Esperar un intervalo'],
                        ['value' => 'until', 'label' => 'Hasta una fecha con zona']
                    ]
                ],
                'amount' => [
                    'type' => 'string', 'label' => 'Cantidad entera (modo intervalo)',
                    'required' => false, 'default' => '0', 'placeholder' => '5 o {{espera}}'
                ],
                'unit' => [
                    'type' => 'select', 'label' => 'Unidad', 'required' => false, 'default' => 'seconds',
                    'options' => [
                        ['value' => 'seconds', 'label' => 'Segundos'],
                        ['value' => 'minutes', 'label' => 'Minutos'],
                        ['value' => 'hours', 'label' => 'Horas']
                    ]
                ],
                'until' => [
                    'type' => 'string', 'label' => 'Fecha con zona (modo hasta)', 'required' => false,
                    'placeholder' => '2026-10-21T10:30:00-06:00 o {{fecha}}'
                ],
                'past_policy' => [
                    'type' => 'select', 'label' => 'Si la fecha ya pasó', 'required' => false, 'default' => 'continue',
                    'options' => [
                        ['value' => 'continue', 'label' => 'Continuar inmediatamente'],
                        ['value' => 'error', 'label' => 'Detener con error']
                    ]
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            // La validez temporal (pasado y horizonte) se comprueba con el reloj en execute().
            $this->configuration($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context, ?int $now = null): array
    {
        $config = $this->configuration(ExpressionEvaluator::evaluate($parameters, $context));
        $now ??= time();
        $resumeAt = $config['mode'] === 'interval' ? $now + $config['seconds'] : $config['timestamp'];
        if (!is_int($resumeAt)) {
            throw new InvalidArgumentException('Delay: instante fuera de rango.');
        }
        if ($resumeAt < $now) {
            if ($config['past_policy'] === 'error') {
                throw new InvalidArgumentException('Delay: la fecha indicada ya pasó.');
            }
            $resumeAt = $now;
        }
        $waitSeconds = $resumeAt - $now;
        if ($waitSeconds > self::MAX_WAIT_SECONDS) {
            throw new InvalidArgumentException('Delay: la espera no puede superar 30 días.');
        }
        return [
            'success' => true,
            'resume_at' => $resumeAt,
            'wait_seconds' => $waitSeconds,
            'scheduled_at' => gmdate('Y-m-d\TH:i:s\Z', $resumeAt)
        ];
    }

    private function configuration(array $parameters): array
    {
        $mode = $parameters['mode'] ?? 'interval';
        $pastPolicy = $parameters['past_policy'] ?? 'continue';
        if (!in_array($mode, ['interval', 'until'], true)
            || !in_array($pastPolicy, ['continue', 'error'], true)) {
            throw new InvalidArgumentException('Delay: modo o política de fechas pasadas inválidos.');
        }
        if ($mode === 'until') {
            return ['mode' => $mode, 'past_policy' => $pastPolicy,
                'timestamp' => $this->dateTimestamp($parameters['until'] ?? null)];
        }
        $unit = $parameters['unit'] ?? 'seconds';
        $multipliers = ['seconds' => 1, 'minutes' => 60, 'hours' => 3600];
        if (!is_string($unit) || !isset($multipliers[$unit])) {
            throw new InvalidArgumentException('Delay: unidad de intervalo inválida.');
        }
        $amount = $parameters['amount'] ?? 0;
        if (is_string($amount) && preg_match('/^[0-9]+$/D', trim($amount)) === 1) {
            $amount = filter_var(trim($amount), FILTER_VALIDATE_INT);
        }
        if (!is_int($amount) || $amount < 0 || $amount > intdiv(self::MAX_WAIT_SECONDS, $multipliers[$unit])) {
            throw new InvalidArgumentException('Delay: indica una cantidad entera no negativa que no supere 30 días.');
        }
        return ['mode' => $mode, 'past_policy' => $pastPolicy, 'seconds' => $amount * $multipliers[$unit]];
    }

    private function dateTimestamp(mixed $value): int
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $value) !== 1) {
            throw new InvalidArgumentException('Delay: indica una fecha ISO8601 con segundos y zona Z o ±HH:MM.');
        }
        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        if (substr($normalized, -5, 2) === '14' && substr($normalized, -2) !== '00') {
            throw new InvalidArgumentException('Delay: el desplazamiento de zona no puede superar 14 horas.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:sP') !== $normalized) {
            throw new InvalidArgumentException('Delay: la fecha o su zona no son válidas.');
        }
        return $date->getTimestamp();
    }
}
