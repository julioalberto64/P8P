<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Core\ExpressionEvaluator;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class CloudflareNode implements NodeInterface
{
    private const OPERATIONS = ['list_zones', 'get_zone', 'list_dns', 'get_dns', 'create_dns', 'update_dns', 'delete_dns', 'purge_files', 'purge_tags', 'purge_hosts', 'purge_prefixes', 'purge_everything', 'analytics', 'list_rulesets', 'get_ruleset'];

    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        $parameters = [
            'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Cloudflare'],
            'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'list_zones', 'options' => array_map(static fn ($v) => ['value' => $v, 'label' => $v], self::OPERATIONS)],
            'zone_id' => ['type' => 'string', 'label' => 'Zone ID'],
            'record_id' => ['type' => 'string', 'label' => 'DNS record ID'],
            'ruleset_id' => ['type' => 'string', 'label' => 'Ruleset ID'],
            'record' => ['type' => 'textarea', 'label' => 'Registro JSON: type,name,content,ttl,proxied,priority,comment,tags'],
            'resources' => ['type' => 'textarea', 'label' => 'Array JSON de URLs, tags, hosts o prefijos para purgar'],
            'confirm_purge_all' => ['type' => 'select', 'label' => 'Confirmar purga completa', 'default' => 'false', 'options' => [['value' => 'false', 'label' => 'No'], ['value' => 'true', 'label' => 'Sí, purgar toda la zona']]],
            'page' => ['type' => 'number', 'label' => 'Página', 'default' => 1],
            'per_page' => ['type' => 'number', 'label' => 'Resultados por página (1..100)', 'default' => 50],
            'name' => ['type' => 'string', 'label' => 'Filtro nombre exacto de zona/registro'],
            'start_time' => ['type' => 'string', 'label' => 'Analytics desde UTC ISO8601'],
            'end_time' => ['type' => 'string', 'label' => 'Analytics hasta UTC ISO8601'],
            'limit' => ['type' => 'number', 'label' => 'Analytics grupos (1..1000)', 'default' => 100]
        ];
        return ['type' => 'cloudflare', 'name' => 'Cloudflare', 'category' => 'Community', 'evaluates_expressions' => true, 'description' => 'Zonas, DNS, purga explícita, analíticas GraphQL y consulta de reglas.', 'icon' => 'Cloud', 'is_start' => false,
            'help_text' => 'generic_bearer: API Key con token o JSON {"api_token":"..."}. Permisos mínimos por acción: Zone Read, DNS Read/Write, Cache Purge, Analytics Read, Zone WAF/Rulesets Read y recursos de zona autorizados. No cambia protecciones. DNS A/AAAA/CNAME/TXT/MX/NS; PATCH actualiza solo claves presentes, comment="" y tags=[] vacían explícitamente. Purga completa requiere operación purge_everything y confirmación true. Analytics usa httpRequestsAdaptiveGroups (count y datetimeMinute), sujeto a plan, sampling, retención y disponibilidad del dataset; ventana máxima 24h, sin promesa de cobertura universal. Una página por ejecución, sin reintentos. Fallos HTTP, success=false o errors detienen el flujo. Configura expiración y rotación manual del token en Cloudflare. Es nodo de acciones, no un trigger.', 'parameters' => $parameters];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (InvalidArgumentException $e) { return false; }
    }

    public function execute(array $parameters, array $context): array
    {
        $p = $this->prepare(ExpressionEvaluator::evaluate($parameters, $context));
        $token = $this->token($p['credential_id']);
        $options = $p['options'] + ['timeout' => 30, 'connect_timeout' => 10, 'read_timeout' => 30, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true];
        $options['headers'] = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
        try {
            $response = ($this->client ?? new Client())->request($p['method'], 'https://api.cloudflare.com' . $p['path'], $options);
            $status = $response->getStatusCode();
            $raw = $this->read($response->getBody());
        } catch (\Throwable $e) { throw new RuntimeException('Cloudflare: fallo de transporte/lectura; sin reintento. Una mutación puede haber ocurrido.'); }
        $body = json_decode($raw, true, 64, JSON_BIGINT_AS_STRING);
        if ($status < 200 || $status >= 300) { throw new RuntimeException('Cloudflare: solicitud rechazada (HTTP ' . $status . '); revisa autenticación, scopes, recursos autorizados, plan o cuota.', $status); }
        if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE || !empty($body['errors']) || ($p['operation'] !== 'analytics' && ($body['success'] ?? null) !== true)) { throw new RuntimeException('Cloudflare: error funcional o respuesta inválida; sin reintento.'); }
        if ($p['operation'] === 'analytics') {
            if (!is_array($body['data']['viewer']['zones'] ?? null)) { throw new RuntimeException('Cloudflare: dataset de analíticas no disponible para la cuenta/zona.'); }
            $data = $body['data'];
        } else {
            if (!array_key_exists('result', $body)) { throw new RuntimeException('Cloudflare: respuesta sin result.'); }
            $data = ['result' => $body['result'], 'pagination' => $body['result_info'] ?? null];
        }
        return ['success' => true, 'status_code' => $status, 'data' => $this->redact($data, $token)];
    }

    private function prepare(array $p): array
    {
        $op = $p['operation'] ?? 'list_zones';
        if (!in_array($op, self::OPERATIONS, true) || !is_string($p['credential_id'] ?? null) || trim($p['credential_id']) === '') { throw new InvalidArgumentException('Cloudflare: operación o credencial inválida.'); }
        $page = $this->integer($p['page'] ?? 1, 1, 100000);
        $size = $this->integer($p['per_page'] ?? 50, 1, 100);
        $result = ['operation' => $op, 'credential_id' => $p['credential_id'], 'method' => 'GET', 'path' => '/client/v4/zones', 'options' => []];
        if ($op === 'list_zones') {
            $result['options']['query'] = ['page' => $page, 'per_page' => $size];
            if (isset($p['name']) && $p['name'] !== '') { $result['options']['query']['name'] = $this->domain($p['name']); }
            return $result;
        }
        $zone = $this->id($p['zone_id'] ?? null);
        $result['path'] .= '/' . $zone;
        if ($op === 'get_zone') { return $result; }
        if (str_contains($op, '_dns')) {
            $result['path'] .= '/dns_records';
            if (in_array($op, ['get_dns', 'update_dns', 'delete_dns'], true)) { $result['path'] .= '/' . $this->id($p['record_id'] ?? null); }
            if ($op === 'list_dns') {
                $result['options']['query'] = ['page' => $page, 'per_page' => $size];
                if (isset($p['name']) && $p['name'] !== '') { $result['options']['query']['name'] = $this->domain($p['name']); }
            } elseif ($op === 'delete_dns') { $result['method'] = 'DELETE'; }
            elseif ($op === 'create_dns' || $op === 'update_dns') {
                $result['method'] = $op === 'create_dns' ? 'POST' : 'PATCH';
                $record = $this->json($p['record'] ?? null);
                if ($record === [] || array_is_list($record) || array_diff(array_keys($record), ['type', 'name', 'content', 'ttl', 'proxied', 'priority', 'comment', 'tags'])) { throw new InvalidArgumentException('Cloudflare: campos de registro no admitidos.'); }
                foreach (['type', 'name', 'content'] as $key) { if ($op === 'create_dns' && !array_key_exists($key, $record)) { throw new InvalidArgumentException('Cloudflare: crear DNS requiere type,name,content.'); } }
                if (array_key_exists('type', $record) && !in_array($record['type'], ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'NS'], true)) { throw new InvalidArgumentException('Cloudflare: tipo DNS no admitido en esta versión.'); }
                if (array_key_exists('name', $record)) { $this->domain($record['name'], true); }
                if (array_key_exists('content', $record) && (!is_string($record['content']) || $record['content'] === '' || strlen($record['content']) > 8192 || preg_match('/[\x00\r\n]/', $record['content']))) { throw new InvalidArgumentException('Cloudflare: contenido DNS inválido.'); }
                if (isset($record['type'], $record['content']) && in_array($record['type'], ['A', 'AAAA'], true) && filter_var($record['content'], FILTER_VALIDATE_IP, $record['type'] === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6) === false) { throw new InvalidArgumentException('Cloudflare: IP DNS inválida.'); }
                if (array_key_exists('ttl', $record)) { $record['ttl'] = $this->integer($record['ttl'], 1, 86400); if ($record['ttl'] !== 1 && $record['ttl'] < 60) { throw new InvalidArgumentException('Cloudflare: TTL 1 (auto) o 60..86400, sujeto al plan.'); } }
                if (array_key_exists('proxied', $record) && !is_bool($record['proxied'])) { throw new InvalidArgumentException('Cloudflare: proxied requiere booleano.'); }
                if (array_key_exists('priority', $record)) { $record['priority'] = $this->integer($record['priority'], 0, 65535); }
                if (array_key_exists('comment', $record) && (!is_string($record['comment']) || strlen($record['comment']) > 500)) { throw new InvalidArgumentException('Cloudflare: comentario inválido.'); }
                if (array_key_exists('tags', $record)) { $this->strings($record['tags'], 200, 100); }
                $result['options']['json'] = $record;
            }
            return $result;
        }
        if (str_starts_with($op, 'purge_')) {
            $result['method'] = 'POST'; $result['path'] .= '/purge_cache';
            if ($op === 'purge_everything') {
                if (!in_array($p['confirm_purge_all'] ?? null, [true, 'true'], true)) { throw new InvalidArgumentException('Cloudflare: purga completa requiere confirmación explícita.'); }
                $result['options']['json'] = ['purge_everything' => true];
            } else {
                $key = substr($op, 6); $values = $this->json($p['resources'] ?? null);
                $this->strings($values, 30, 2048);
                if ($values === []) { throw new InvalidArgumentException('Cloudflare: recursos de purga vacíos.'); }
                foreach ($values as $value) {
                    if ($key === 'files') {
                        $url = parse_url($value);
                        if (!is_array($url) || !in_array($url['scheme'] ?? '', ['https', 'http'], true) || !isset($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) { throw new InvalidArgumentException('Cloudflare: URL de purga inválida.'); }
                    } elseif ($key === 'hosts') { $this->domain($value); }
                }
                $result['options']['json'] = [$key => $values];
            }
            return $result;
        }
        if ($op === 'analytics') {
            $start = $this->timestamp($p['start_time'] ?? null); $end = $this->timestamp($p['end_time'] ?? null);
            if ($end <= $start || $end - $start > 86400) { throw new InvalidArgumentException('Cloudflare: ventana analytics debe ser positiva y máximo 24h.'); }
            $limit = $this->integer($p['limit'] ?? 100, 1, 1000);
            $result['method'] = 'POST'; $result['path'] = '/client/v4/graphql';
            $result['options']['json'] = ['query' => 'query($zoneTag: string!, $start: Time!, $end: Time!, $limit: Int!) { viewer { zones(filter: {zoneTag: $zoneTag}) { httpRequestsAdaptiveGroups(limit: $limit, filter: {datetime_geq: $start, datetime_lt: $end}, orderBy: [datetimeMinute_ASC]) { count dimensions { datetimeMinute } } } } }', 'variables' => ['zoneTag' => $zone, 'start' => gmdate('Y-m-d\TH:i:s\Z', $start), 'end' => gmdate('Y-m-d\TH:i:s\Z', $end), 'limit' => $limit]];
            return $result;
        }
        $result['path'] .= '/rulesets';
        if ($op === 'get_ruleset') { $result['path'] .= '/' . $this->id($p['ruleset_id'] ?? null); }
        return $result;
    }

    private function token(string $id): string
    {
        try { $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id); } catch (\Throwable $e) { throw new RuntimeException('Cloudflare: error de almacenamiento/descifrado de credencial.'); }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? '') !== 'generic_bearer' || !is_string($token)) { throw new InvalidArgumentException('Cloudflare: requiere generic_bearer con api_token.'); }
        if (str_starts_with(ltrim($token), '{')) { $data = $this->json($token); $token = $data['api_token'] ?? null; }
        if (!is_string($token) || strlen($token) < 8 || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7f]/', $token)) { throw new InvalidArgumentException('Cloudflare: api_token inválido.'); }
        return $token;
    }

    private function id(mixed $id): string
    {
        if (!is_string($id) || preg_match('/^[a-fA-F0-9]{32}$/D', $id) !== 1) { throw new InvalidArgumentException('Cloudflare: ID debe contener 32 dígitos hexadecimales.'); }
        return $id;
    }

    private function domain(mixed $value, bool $wildcard = false): string
    {
        if (!is_string($value) || strlen($value) > 253 || preg_match($wildcard ? '/^(?:\*\.)?(?:[A-Za-z0-9_](?:[A-Za-z0-9_-]*[A-Za-z0-9_])?\.)*[A-Za-z0-9_-]+\.?$/D' : '/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)*[A-Za-z0-9-]+\.?$/D', $value) !== 1) { throw new InvalidArgumentException('Cloudflare: dominio inválido; usa ASCII/punycode.'); }
        return $value;
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if (!is_scalar($value) || is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) { throw new InvalidArgumentException('Cloudflare: número fuera de rango.'); }
        return (int) $value;
    }

    private function json(mixed $value): array
    {
        if (is_string($value) && strlen($value) <= 1048576) { try { $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR); } catch (\JsonException $e) { throw new InvalidArgumentException('Cloudflare: JSON inválido.'); } }
        if (!is_array($value)) { throw new InvalidArgumentException('Cloudflare: se requiere JSON/array.'); }
        return $value;
    }

    private function strings(mixed $values, int $max, int $length): void
    {
        if (!is_array($values) || !array_is_list($values) || count($values) > $max) { throw new InvalidArgumentException('Cloudflare: lista inválida o demasiado larga.'); }
        foreach ($values as $value) { if (!is_string($value) || $value === '' || strlen($value) > $length || preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new InvalidArgumentException('Cloudflare: valor de lista inválido.'); } }
    }

    private function timestamp(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1 || ($date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'))) === false || $date->format('Y-m-d\TH:i:s\Z') !== $value) { throw new InvalidArgumentException('Cloudflare: fecha UTC ISO8601 inválida.'); }
        return $date->getTimestamp();
    }

    private function read($stream): string
    {
        $raw = '';
        try { while (!$stream->eof() && strlen($raw) <= 5242880) { $part = $stream->read(min(65536, 5242881 - strlen($raw))); if ($part === '' && !$stream->eof()) { throw new RuntimeException(); } $raw .= $part; } } finally { $stream->close(); }
        if (strlen($raw) > 5242880) { throw new RuntimeException(); }
        return $raw;
    }

    private function redact(mixed $value, string $token): mixed
    {
        if (is_array($value)) { foreach ($value as $key => $item) { $value[$key] = $this->redact($item, $token); } return $value; }
        return is_string($value) ? str_replace($token, '[redacted]', $value) : $value;
    }
}
