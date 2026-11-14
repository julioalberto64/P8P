<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Core\ExpressionEvaluator;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class GoogleAnalyticsNode implements NodeInterface
{
    private const OPERATIONS = ['run_report', 'run_realtime_report', 'get_metadata', 'check_compatibility'];

    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return ['type' => 'google_analytics', 'name' => 'Google Analytics 4', 'category' => 'Community', 'evaluates_expressions' => true, 'description' => 'Reportes GA4, tiempo real, metadatos y compatibilidad; solo consulta.', 'icon' => 'ChartColumn', 'is_start' => false,
            'help_text' => 'generic_bearer: access token OAuth o JSON {"access_token":"..."}. Requiere Analytics Data API habilitada, scope analytics.readonly y rol Viewer en la propiedad; restricciones de datos del usuario se mantienen. Token caducable: reemplazo/renovación manual mediante OAuth existente. property_id numérico, sin G-. dimensions/metrics son arrays de nombres. date_ranges: [{"startDate":"7daysAgo","endDate":"yesterday"}]. Filtros FilterExpression oficial: andGroup/orGroup/notExpression/filter. order_bys: [{"dimension":{"dimensionName":"date"},"desc":false}]. Una página por ejecución, limit 1..10000; offset solo reportes normales. Tiempo real usa minute_ranges opcional y no fechas/offset. Compatibilidad solo para reportes normales: úsala para verificar campos; el servidor valida combinaciones concretas. customEvent:... consulta dimensiones/métricas registradas, no envía eventos. Usa métricas vigentes como keyEvents según get_metadata. Incluye cuotas y metadatos/restricciones en salida; sin reintentos ni Measurement Protocol. No es trigger.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial OAuth GA4'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'run_report', 'options' => array_map(static fn ($v) => ['value' => $v, 'label' => $v], self::OPERATIONS)],
                'property_id' => ['type' => 'string', 'required' => true, 'label' => 'ID numérico de propiedad'],
                'metrics' => ['type' => 'textarea', 'label' => 'Métricas JSON', 'placeholder' => '["activeUsers","keyEvents"]'],
                'dimensions' => ['type' => 'textarea', 'label' => 'Dimensiones JSON', 'default' => '[]'],
                'date_ranges' => ['type' => 'textarea', 'label' => 'Rangos de fechas JSON', 'default' => '[{"startDate":"7daysAgo","endDate":"yesterday"}]'],
                'minute_ranges' => ['type' => 'textarea', 'label' => 'Tiempo real: rangos de minutos JSON (opcional)'],
                'dimension_filter' => ['type' => 'textarea', 'label' => 'Filtro de dimensiones JSON (FilterExpression)'],
                'metric_filter' => ['type' => 'textarea', 'label' => 'Filtro de métricas JSON (FilterExpression)'],
                'order_bys' => ['type' => 'textarea', 'label' => 'Orden JSON'],
                'limit' => ['type' => 'number', 'label' => 'Máximo filas (1..10000)', 'default' => 1000],
                'offset' => ['type' => 'number', 'label' => 'Offset (solo normal)', 'default' => 0],
                'keep_empty_rows' => ['type' => 'select', 'label' => 'Conservar filas vacías (normal)', 'default' => 'false', 'options' => [['value' => 'false', 'label' => 'No'], ['value' => 'true', 'label' => 'Sí']]]
            ]];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (InvalidArgumentException $e) { return false; }
    }

    public function execute(array $parameters, array $context): array
    {
        $p = $this->prepare(ExpressionEvaluator::evaluate($parameters, $context));
        $token = $this->token($p['credential_id']);
        $options = ['headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'], 'timeout' => 45, 'connect_timeout' => 10, 'read_timeout' => 45, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true];
        if ($p['body'] !== null) { $options['json'] = $this->apiObjects($p['body']); }
        try {
            $response = ($this->client ?? new Client())->request($p['method'], 'https://analyticsdata.googleapis.com/v1beta/properties/' . $p['property_id'] . $p['suffix'], $options);
            $status = $response->getStatusCode(); $stream = $response->getBody(); $raw = '';
            try { while (!$stream->eof() && strlen($raw) <= 8388608) { $part = $stream->read(min(65536, 8388609 - strlen($raw))); if ($part === '' && !$stream->eof()) { throw new RuntimeException(); } $raw .= $part; } } finally { $stream->close(); }
            if (strlen($raw) > 8388608) { throw new RuntimeException(); }
        } catch (\Throwable $e) { throw new RuntimeException('GA4: fallo de transporte/lectura o respuesta mayor de 8 MiB; sin reintento.'); }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) { 401 => 'token inválido o caducado', 403 => 'permisos/restricciones de propiedad o API deshabilitada', 429 => 'cuota agotada', 400 => 'campos/filtros incompatibles o inválidos', default => 'solicitud rechazada por el servicio' };
            throw new RuntimeException('GA4: ' . $reason . ' (HTTP ' . $status . ').', $status);
        }
        $body = json_decode($raw, true, 64, JSON_BIGINT_AS_STRING);
        if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE || isset($body['error'])) { throw new RuntimeException('GA4: respuesta inválida o error funcional.'); }
        if (in_array($p['operation'], ['run_report', 'run_realtime_report'], true)) {
            $body['rows'] ??= [];
            if (!is_array($body['rows']) || !array_is_list($body['rows']) || count($body['rows']) > $p['limit']) { throw new RuntimeException('GA4: filas de respuesta inválidas.'); }
            $body['pagination'] = ['offset' => $p['offset'], 'limit' => $p['limit'], 'next_offset' => $p['operation'] === 'run_report' && count($body['rows']) === $p['limit'] && (int) ($body['rowCount'] ?? 0) > $p['offset'] + count($body['rows']) ? $p['offset'] + count($body['rows']) : null];
        }
        return ['success' => true, 'status_code' => $status, 'data' => $this->redact($body, $token)];
    }

    private function prepare(array $p): array
    {
        $op = $p['operation'] ?? 'run_report'; $id = $p['property_id'] ?? null;
        if (!in_array($op, self::OPERATIONS, true) || !is_string($p['credential_id'] ?? null) || trim($p['credential_id']) === '' || (!is_string($id) && !is_int($id)) || preg_match('/^[1-9][0-9]{0,19}$/D', (string) $id) !== 1) { throw new InvalidArgumentException('GA4: operación, credencial o ID de propiedad inválido.'); }
        $result = ['credential_id' => $p['credential_id'], 'operation' => $op, 'property_id' => (string) $id, 'method' => 'POST', 'body' => [], 'limit' => 1000, 'offset' => 0];
        if ($op === 'get_metadata') { return array_replace($result, ['method' => 'GET', 'body' => null, 'suffix' => '/metadata']); }
        $dimensions = $this->names($this->json($p['dimensions'] ?? []), $op === 'run_realtime_report' ? 4 : 9, false);
        $metrics = $this->names($this->json($p['metrics'] ?? []), 10, $op !== 'check_compatibility');
        $body = ['dimensions' => array_map(static fn ($name) => ['name' => $name], $dimensions), 'metrics' => array_map(static fn ($name) => ['name' => $name], $metrics)];
        foreach (['dimension_filter' => 'dimensionFilter', 'metric_filter' => 'metricFilter'] as $input => $api) {
            if (isset($p[$input]) && $p[$input] !== '') { $filter = $this->json($p[$input]); $this->filter($filter, $input === 'dimension_filter' ? $dimensions : $metrics, 0); $body[$api] = $filter; }
        }
        if ($op === 'check_compatibility') { return array_replace($result, ['suffix' => ':checkCompatibility', 'body' => $body]); }
        $limit = $this->integer($p['limit'] ?? 1000, 1, 10000); $offset = $this->integer($p['offset'] ?? 0, 0, 1000000000);
        $body['limit'] = (string) $limit; $body['returnPropertyQuota'] = true;
        $orders = isset($p['order_bys']) && $p['order_bys'] !== '' ? $this->json($p['order_bys']) : [];
        if (!array_is_list($orders) || count($orders) > 20) { throw new InvalidArgumentException('GA4: lista order_bys inválida.'); }
        foreach ($orders as $order) {
            if (!is_array($order) || array_diff(array_keys($order), ['dimension', 'metric', 'desc']) || (isset($order['dimension']) === isset($order['metric'])) || (array_key_exists('desc', $order) && !is_bool($order['desc']))) { throw new InvalidArgumentException('GA4: orden inválido.'); }
            if (isset($order['dimension'])) {
                $o = $order['dimension'];
                if (!is_array($o) || array_diff(array_keys($o), ['dimensionName', 'orderType']) || !in_array($o['dimensionName'] ?? null, $dimensions, true) || !in_array($o['orderType'] ?? 'ALPHANUMERIC', ['ALPHANUMERIC', 'CASE_INSENSITIVE_ALPHANUMERIC', 'NUMERIC'], true)) { throw new InvalidArgumentException('GA4: dimensión de orden inválida.'); }
            } else {
                $o = $order['metric'];
                if (!is_array($o) || array_keys($o) !== ['metricName'] || !in_array($o['metricName'], $metrics, true)) { throw new InvalidArgumentException('GA4: métrica de orden inválida.'); }
            }
        }
        if ($orders !== []) { $body['orderBys'] = $orders; }
        if ($op === 'run_report') {
            $ranges = $this->json($p['date_ranges'] ?? [['startDate' => '7daysAgo', 'endDate' => 'yesterday']]);
            if (!array_is_list($ranges) || count($ranges) < 1 || count($ranges) > 4) { throw new InvalidArgumentException('GA4: requiere 1..4 rangos de fechas.'); }
            foreach ($ranges as $range) {
                if (!is_array($range) || array_diff(array_keys($range), ['startDate', 'endDate', 'name'])) { throw new InvalidArgumentException('GA4: rango inválido.'); }
                foreach (['startDate', 'endDate'] as $key) { $this->date($range[$key] ?? null); }
                if (isset($range['name']) && (!is_string($range['name']) || preg_match('/^(?!date_range_|RESERVED_)[A-Za-z][A-Za-z0-9_]{0,99}$/D', $range['name']) !== 1)) { throw new InvalidArgumentException('GA4: nombre de rango inválido.'); }
            }
            $keep = $p['keep_empty_rows'] ?? 'false';
            if (!in_array($keep, [true, false, 'true', 'false'], true)) { throw new InvalidArgumentException('GA4: keep_empty_rows inválido.'); }
            $body += ['dateRanges' => $ranges, 'offset' => (string) $offset, 'keepEmptyRows' => in_array($keep, [true, 'true'], true)];
            if (isset($p['minute_ranges']) && $p['minute_ranges'] !== '') { throw new InvalidArgumentException('GA4: minute_ranges solo en tiempo real.'); }
        } else {
            if ($offset !== 0) { throw new InvalidArgumentException('GA4: tiempo real no admite offset.'); }
            if (isset($p['minute_ranges']) && $p['minute_ranges'] !== '') {
                $ranges = $this->json($p['minute_ranges']);
                if (!array_is_list($ranges) || count($ranges) < 1 || count($ranges) > 2) { throw new InvalidArgumentException('GA4: tiempo real requiere 1..2 rangos.'); }
                foreach ($ranges as &$range) {
                    if (!is_array($range) || array_diff(array_keys($range), ['startMinutesAgo', 'endMinutesAgo', 'name'])) { throw new InvalidArgumentException('GA4: rango de minutos inválido.'); }
                    $range['startMinutesAgo'] = $this->integer($range['startMinutesAgo'] ?? null, 0, 59);
                    $range['endMinutesAgo'] = $this->integer($range['endMinutesAgo'] ?? 0, 0, 59);
                    if ($range['startMinutesAgo'] < $range['endMinutesAgo'] || (isset($range['name']) && (!is_string($range['name']) || preg_match('/^(?!date_range_|RESERVED_)[A-Za-z][A-Za-z0-9_]{0,99}$/D', $range['name']) !== 1))) { throw new InvalidArgumentException('GA4: rango de minutos inválido; ventana disponible depende de la propiedad.'); }
                }
                unset($range); $body['minuteRanges'] = $ranges;
            }
        }
        return array_replace($result, ['suffix' => $op === 'run_report' ? ':runReport' : ':runRealtimeReport', 'body' => $body, 'limit' => $limit, 'offset' => $offset]);
    }

    private function filter(array $expression, array $fields, int $depth): void
    {
        if ($depth > 10 || count($expression) !== 1) { throw new InvalidArgumentException('GA4: FilterExpression inválida o demasiado profunda.'); }
        $key = array_key_first($expression); $value = $expression[$key];
        if ($key === 'notExpression') { if (!is_array($value)) { throw new InvalidArgumentException('GA4: notExpression inválida.'); } $this->filter($value, $fields, $depth + 1); return; }
        if ($key === 'andGroup' || $key === 'orGroup') {
            if (!is_array($value) || array_keys($value) !== ['expressions'] || !is_array($value['expressions']) || !array_is_list($value['expressions']) || count($value['expressions']) < 1 || count($value['expressions']) > 20) { throw new InvalidArgumentException('GA4: grupo de filtros inválido.'); }
            foreach ($value['expressions'] as $child) { if (!is_array($child)) { throw new InvalidArgumentException('GA4: filtro inválido.'); } $this->filter($child, $fields, $depth + 1); } return;
        }
        if ($key !== 'filter' || !is_array($value) || !in_array($value['fieldName'] ?? null, $fields, true)) { throw new InvalidArgumentException('GA4: filtro debe referenciar un campo solicitado.'); }
        $types = array_intersect(array_keys($value), ['stringFilter', 'inListFilter', 'numericFilter', 'betweenFilter', 'emptyFilter']);
        if (count($types) !== 1 || array_diff(array_keys($value), ['fieldName', ...$types])) { throw new InvalidArgumentException('GA4: tipo de filtro inválido.'); }
        $type = reset($types); $f = $value[$type];
        if (!is_array($f)) { throw new InvalidArgumentException('GA4: estructura de filtro inválida.'); }
        if ($type === 'stringFilter') {
            if (array_diff(array_keys($f), ['matchType', 'value', 'caseSensitive']) || !in_array($f['matchType'] ?? null, ['EXACT', 'BEGINS_WITH', 'ENDS_WITH', 'CONTAINS', 'FULL_REGEXP', 'PARTIAL_REGEXP'], true) || !is_string($f['value'] ?? null) || strlen($f['value']) > 4096 || (isset($f['caseSensitive']) && !is_bool($f['caseSensitive']))) { throw new InvalidArgumentException('GA4: stringFilter inválido.'); }
        } elseif ($type === 'inListFilter') {
            if (array_diff(array_keys($f), ['values', 'caseSensitive']) || !is_array($f['values'] ?? null) || !array_is_list($f['values']) || count($f['values']) < 1 || count($f['values']) > 100 || (isset($f['caseSensitive']) && !is_bool($f['caseSensitive']))) { throw new InvalidArgumentException('GA4: inListFilter inválido.'); }
            foreach ($f['values'] as $v) { if (!is_string($v) || strlen($v) > 4096) { throw new InvalidArgumentException('GA4: valor de filtro inválido.'); } }
        } elseif ($type === 'numericFilter') {
            if (array_diff(array_keys($f), ['operation', 'value']) || !in_array($f['operation'] ?? null, ['EQUAL', 'LESS_THAN', 'LESS_THAN_OR_EQUAL', 'GREATER_THAN', 'GREATER_THAN_OR_EQUAL'], true)) { throw new InvalidArgumentException('GA4: numericFilter inválido.'); }
            $this->numeric($f['value'] ?? null);
        } elseif ($type === 'betweenFilter') {
            if (array_diff(array_keys($f), ['fromValue', 'toValue'])) { throw new InvalidArgumentException('GA4: betweenFilter inválido.'); }
            $this->numeric($f['fromValue'] ?? null); $this->numeric($f['toValue'] ?? null);
        } elseif ($f !== []) { throw new InvalidArgumentException('GA4: emptyFilter debe ser objeto vacío.'); }
    }

    private function numeric(mixed $value): void
    {
        if (!is_array($value) || count($value) !== 1 || (array_key_first($value) === 'int64Value' ? (!is_string($value['int64Value']) || preg_match('/^-?[0-9]{1,19}$/D', $value['int64Value']) !== 1) : (array_key_first($value) !== 'doubleValue' || (!is_int($value['doubleValue']) && !is_float($value['doubleValue'])) || !is_finite((float) $value['doubleValue'])))) { throw new InvalidArgumentException('GA4: NumericValue inválido.'); }
    }

    private function names(array $values, int $max, bool $required): array
    {
        if (!array_is_list($values) || count($values) > $max || ($required && $values === [])) { throw new InvalidArgumentException('GA4: cantidad de campos inválida.'); }
        foreach ($values as $value) { if (!is_string($value) || preg_match('/^[A-Za-z][A-Za-z0-9_:]{0,199}$/D', $value) !== 1) { throw new InvalidArgumentException('GA4: nombre de campo inválido.'); } }
        if (count(array_unique($values)) !== count($values)) { throw new InvalidArgumentException('GA4: campos duplicados.'); }
        return $values;
    }

    private function date(mixed $value): void
    {
        if (!is_string($value)) { throw new InvalidArgumentException('GA4: fecha inválida.'); }
        if (preg_match('/^(?:today|yesterday|[0-9]{1,5}daysAgo)$/D', $value)) { return; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) { throw new InvalidArgumentException('GA4: fecha debe ser YYYY-MM-DD, today, yesterday o NdaysAgo.'); }
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if (!is_scalar($value) || is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) { throw new InvalidArgumentException('GA4: número fuera de rango.'); }
        return (int) $value;
    }

    private function json(mixed $value): array
    {
        if (is_string($value) && strlen($value) <= 1048576) { try { $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); } catch (\JsonException $e) { throw new InvalidArgumentException('GA4: JSON inválido.'); } }
        if (!is_array($value)) { throw new InvalidArgumentException('GA4: requiere JSON/array.'); }
        return $value;
    }

    private function apiObjects(mixed $value): mixed
    {
        if (is_array($value)) { foreach ($value as $key => $item) { $value[$key] = $key === 'emptyFilter' && $item === [] ? new \stdClass() : $this->apiObjects($item); } }
        return $value;
    }

    private function token(string $id): string
    {
        try { $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id); } catch (\Throwable $e) { throw new RuntimeException('GA4: error de almacenamiento/descifrado de credencial.'); }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? '') !== 'generic_bearer' || !is_string($token)) { throw new InvalidArgumentException('GA4: requiere credencial generic_bearer.'); }
        if (str_starts_with(ltrim($token), '{')) { $data = $this->json($token); $token = $data['access_token'] ?? null; }
        if (!is_string($token) || strlen($token) < 8 || strlen($token) > 8192 || preg_match('/[\x00-\x20\x7f]/', $token)) { throw new InvalidArgumentException('GA4: access_token inválido.'); }
        return $token;
    }

    private function redact(mixed $value, string $token): mixed
    {
        if (is_array($value)) { foreach ($value as $key => $item) { $value[$key] = $this->redact($item, $token); } return $value; }
        return is_string($value) ? str_replace($token, '[redacted]', $value) : $value;
    }
}
