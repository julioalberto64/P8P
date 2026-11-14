<?php

namespace P8P\Core\TriggerProviders;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\TriggerRetryException;
use P8P\Interfaces\TriggerProviderInterface;
use P8P\Nodes\Custom\MercadoPagoNode;
use RuntimeException;

/** Adapters only: the existing manager owns encryption, leases, durable dedup and ACK. */
class CommerceDataTriggers implements TriggerProviderInterface
{
    public function __construct(private ?ClientInterface $client = null)
    {
    }

    public function definitions(): array
    {
        $field = static fn (string $label): array => ['type' => 'string', 'label' => $label, 'required' => true];
        $definitions = [
            'mercadopago_payment_updated' => [
                'name' => 'Mercado Pago: estado de pago confirmado', 'node_type' => 'mercado_pago',
                'event' => 'payment.updated', 'transport' => 'webhook',
                'config_fields' => ['seller_id' => $field('Collector/seller ID'), 'mode' => ['type' => 'select', 'required' => true, 'label' => 'Entorno', 'options' => [['value' => 'test', 'label' => 'test'], ['value' => 'live', 'label' => 'live']]]],
                'secret_fields' => ['access_token' => $field('Access token del vendedor'), 'webhook_secret' => $field('Secreto de firma de la aplicación')],
                'help' => 'Registra notificaciones Webhooks de Pagos en la aplicación (no IPN, órdenes v1 ni Wallet Connect). Firma oficial id/request-id/ts con tolerancia local 300s; consulta GET /v1/payments/ID y comprueba collector_id y live_mode. Solo el estado autenticado se encola, nunca el body no firmado. Deduplicación por hash del snapshot seguro del pago: avisos repetidos sin cambio se colapsan. No confirma, captura ni mueve dinero. Usa token del vendedor/entorno; reemplazo manual al caducar. Registro remoto manual, HTTPS. Sin reintentos de escritura.',
                'docs' => ['https://www.mercadopago.com.mx/developers/en/docs/your-integrations/notifications/webhooks', 'https://www.mercadopago.com.mx/developers/en/reference/payments/_payments_id/get']
            ],
            'freshdesk_ticket_updated' => [
                'name' => 'Freshdesk: ticket creado o actualizado (polling)', 'node_type' => 'freshdesk',
                'event' => 'ticket.updated', 'transport' => 'polling', 'config_fields' => [],
                'secret_fields' => ['domain' => $field('empresa.freshdesk.com'), 'api_key' => $field('API key de agente con lectura de tickets')],
                'help' => 'Polling API v2 updated_since, orden ascendente updated_at, 100 tickets/página. Primera ejecución solo guarda baseline; no histórico. Ventanas UTC con solape de 5s, máximo 300 páginas; si se agota el límite se detiene sin avanzar cursor. Dedup durable id/updated_at/metadatos. Cambios intermedios del mismo ticket entre lecturas no son un historial de eventos. La paginación del servicio no es snapshot: movimientos concurrentes pueden requerir reconciliación. No depende de webhooks ni plan de automatización. API key/credencial manual, sin renovación automática.',
                'docs' => ['https://developers.freshdesk.com/api/#list_all_tickets']
            ],
            'magento_order_updated' => [
                'name' => 'Magento: pedido creado o actualizado (polling)', 'node_type' => 'magento',
                'event' => 'order.updated', 'transport' => 'polling',
                'config_fields' => ['store_code' => $field('Store code (default o all)')],
                'secret_fields' => ['base_url' => $field('https://tienda.example (Open Source/PaaS 2.4.x)'), 'access_token' => $field('Bearer autorizado para Sales > Operations > Orders > Actions > View')],
                'help' => 'Magento Open Source / Adobe Commerce PaaS 2.4.x REST, no SaaS. Polling searchCriteria updated_at UTC con ventana cerrada, solape 5s, orden updated_at/entity_id, 100 por página y máximo 300 páginas. Primera ejecución baseline sin histórico. Snapshots de estado, no todos los eventos intermedios; API no ofrece snapshot consistente, reconciliar cambios concurrentes. Sin capturas/facturas/envíos/reembolsos. No requiere extensiones de webhook ni supone Adobe I/O disponible. Bearer debe estar habilitado según instalación; no firma OAuth1 ni renueva tokens.',
                'docs' => ['https://developer.adobe.com/commerce/webapi/rest/use-rest/performing-searches', 'https://developer.adobe.com/commerce/webapi/rest/tutorials/orders/order-create-order/']
            ]
        ];
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            $definitions['supabase_database_' . strtolower($event)] = [
                'name' => 'Supabase Database: ' . $event, 'node_type' => 'supabase', 'event' => $event,
                'transport' => 'webhook',
                'config_fields' => ['schema' => $field('Esquema (public)'), 'table' => $field('Tabla')],
                'secret_fields' => ['webhook_secret' => $field('Secreto aleatorio exclusivo del receptor (32+ caracteres)')],
                'help' => 'Configura Database Webhook HTTP POST para tabla/evento y header Authorization: Bearer SECRETO exclusivo del receptor; no uses project key ni service_role. No se inventa una firma HMAC: el mecanismo oficial permite headers configurados y HTTPS. Comprueba schema/table/type. Dedup por hash del cuerpo: dos cambios con payload idéntico se colapsan; no garantiza identidad única de cada modificación ni frescura temporal. No hay handshake/renovación automática; rotación manual coordinada, se pausa al guardar. Revisa RLS/permisos del origen y exposición de headers en pg_net/definición del trigger. Solo columnas seleccionadas por la aplicación deben contener datos admisibles para workflows.',
                'docs' => ['https://supabase.com/docs/guides/database/webhooks']
            ];
        }
        return $definitions;
    }

    public function verify(string $key, array $config, array $secrets, array $request, int $now): array
    {
        $definition = $this->definitions()[$key] ?? null;
        $raw = $request['raw_body'] ?? null;
        if (($definition['transport'] ?? '') !== 'webhook' || ($request['method'] ?? '') !== 'POST'
            || ($request['setup_allowed'] ?? false) || !is_string($raw) || strlen($raw) > 1048576 || !is_array($request['headers'] ?? null)) {
            throw new InvalidArgumentException('Evento: receptor, método o cuerpo inválido.');
        }
        $headers = $request['headers'];
        if ($definition['node_type'] === 'supabase') {
            $secret = $this->text($secrets['webhook_secret'] ?? null, 4096);
            if (strlen($secret) < 32 || preg_match('/\s/', $secret) || !is_string($headers['authorization'] ?? null)
                || !hash_equals('Bearer ' . $secret, $headers['authorization'])) {
                throw new InvalidArgumentException('Supabase: autenticación del webhook inválida.');
            }
            $body = $this->json($raw);
            foreach (['schema', 'table'] as $field) {
                $expected = $this->identifier($config[$field] ?? null);
                if (($body[$field] ?? null) !== $expected) {
                    throw new InvalidArgumentException('Supabase: tabla/esquema incompatible.');
                }
            }
            if (($body['type'] ?? '') !== $definition['event']) {
                throw new InvalidArgumentException('Supabase: evento no corresponde al receptor.');
            }
            foreach (['record', 'old_record'] as $field) {
                $required = $field === 'record' ? $definition['event'] !== 'DELETE' : $definition['event'] !== 'INSERT';
                if (!array_key_exists($field, $body) || ($required ? !is_array($body[$field]) || array_is_list($body[$field]) : $body[$field] !== null)) {
                    throw new InvalidArgumentException('Supabase: estructura de evento inválida.');
                }
            }
            return $this->ack([['id' => hash('sha256', $raw), 'type' => $definition['event'],
                'data' => $this->clean(array_intersect_key($body, array_flip(['schema', 'table', 'type', 'record', 'old_record'])), [$secret])]]);
        }
        $query = [];
        $rawQuery = $request['raw_query'] ?? '';
        if (!is_string($rawQuery) || strlen($rawQuery) > 8192) {
            throw new InvalidArgumentException('Mercado Pago: query inválida.');
        }
        foreach (explode('&', $rawQuery) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $name = urldecode($name);
            if ($name === 'data.id') {
                if (isset($query[$name])) { throw new InvalidArgumentException('Mercado Pago: ID ambiguo.'); }
                $query[$name] = urldecode($value);
            }
        }
        $id = $this->numericId($query['data.id'] ?? null);
        MercadoPagoNode::verifyWebhook($headers['x-signature'] ?? '', $headers['x-request-id'] ?? '', $id,
            $this->text($secrets['webhook_secret'] ?? null, 4096), $now);
        // Neither event type nor notification ID/body is authenticated by the signature.
        $token = $this->token($secrets['access_token'] ?? null);
        $body = $this->request('https://api.mercadopago.com/v1/payments/' . $id, ['Authorization' => 'Bearer ' . $token], [], $now);
        $seller = $this->numericId($config['seller_id'] ?? null);
        if ((string)($body['id'] ?? '') !== $id || (string)($body['collector_id'] ?? '') !== $seller
            || !in_array($config['mode'] ?? '', ['test', 'live'], true) || ($body['live_mode'] ?? null) !== ($config['mode'] === 'live')) {
            throw new InvalidArgumentException('Mercado Pago: pago, vendedor o entorno incompatible.');
        }
        $data = $this->pick($body, ['id', 'collector_id', 'live_mode', 'status', 'status_detail', 'currency_id', 'transaction_amount', 'date_created', 'date_last_updated', 'external_reference']);
        if (!is_string($data['status'] ?? null) || !is_string($data['date_last_updated'] ?? null)) {
            throw new RuntimeException('Mercado Pago: estado autenticado incompleto.');
        }
        $data = $this->clean($data, [$token, $secrets['webhook_secret']]);
        return $this->ack([['id' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 'type' => $definition['event'], 'data' => $data]]);
    }

    public function poll(string $key, array $config, array $secrets, array $state, int $now): array
    {
        $definition = $this->definitions()[$key] ?? null;
        if (($definition['transport'] ?? '') !== 'polling') { throw new InvalidArgumentException('Evento: polling no admitido.'); }
        $freshdesk = $definition['node_type'] === 'freshdesk';
        if ($freshdesk) {
            $domain = $this->text($secrets['domain'] ?? null, 253);
            if (!preg_match('/^[a-z0-9][a-z0-9-]*\.freshdesk\.com$/D', $domain)) { throw new InvalidArgumentException('Freshdesk: dominio inválido.'); }
            $token = $this->token($secrets['api_key'] ?? null);
            $url = 'https://' . $domain . '/api/v2/tickets';
            $headers = ['Authorization' => 'Basic ' . base64_encode($token . ':X')];
        } else {
            $base = $this->text($secrets['base_url'] ?? null, 2048);
            $parts = parse_url($base);
            if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\x7f]/', $base)) { throw new InvalidArgumentException('Magento: URL HTTPS inválida.'); }
            $store = $this->identifier($config['store_code'] ?? null);
            $token = $this->token($secrets['access_token'] ?? null);
            $url = rtrim($base, '/') . '/rest/' . $store . '/V1/orders';
            $headers = ['Authorization' => 'Bearer ' . $token];
        }
        $binding = hash('sha256', $url . '|' . hash('sha256', $token));
        if (isset($state['binding']) && !hash_equals($binding, $state['binding'])) { throw new InvalidArgumentException('Evento: configuración cambió; reactiva para nueva baseline.'); }
        $state['binding'] = $binding;
        if (!isset($state['cursor'])) {
            return ['events' => [], 'state' => ['binding' => $binding, 'cursor' => $now, 'baseline' => $now], 'has_more' => false];
        }
        $from = $state['from'] ?? max($state['baseline'], $state['cursor'] - 5);
        $to = $state['to'] ?? max($state['cursor'], $now - 5);
        $page = $state['page'] ?? 1;
        if (!is_int($from) || !is_int($to) || $to < $from || !is_int($page) || $page < 1 || $page > 300) { throw new InvalidArgumentException('Evento: cursor inválido o límite excedido.'); }
        if ($freshdesk) {
            $query = ['updated_since' => gmdate('Y-m-d\TH:i:s\Z', $from), 'order_by' => 'updated_at', 'order_type' => 'asc', 'per_page' => 100, 'page' => $page];
        } else {
            $query = ['searchCriteria' => ['filter_groups' => [
                ['filters' => [['field' => 'updated_at', 'condition_type' => 'gteq', 'value' => gmdate('Y-m-d H:i:s', $from)]]],
                ['filters' => [['field' => 'updated_at', 'condition_type' => 'lteq', 'value' => gmdate('Y-m-d H:i:s', $to)]]]],
                'sortOrders' => [['field' => 'updated_at', 'direction' => 'ASC'], ['field' => 'entity_id', 'direction' => 'ASC']], 'pageSize' => 100, 'currentPage' => $page]];
        }
        $body = $this->request($url, $headers, $query, $now);
        $rows = $freshdesk ? $body : ($body['items'] ?? null);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100) { throw new RuntimeException('Evento: lista de recursos inválida.'); }
        $events = [];
        $beyond = false;
        foreach ($rows as $row) {
            if (!is_array($row)) { throw new RuntimeException('Evento: recurso inválido.'); }
            $id = $this->numericId($row[$freshdesk ? 'id' : 'entity_id'] ?? null);
            $date = $this->text($row['updated_at'] ?? null, 64);
            $at = strtotime($freshdesk ? $date : $date . ' UTC');
            if ($at === false) { throw new RuntimeException('Evento: fecha inválida.'); }
            if ($at > $to) { $beyond = true; continue; }
            if ($at < $from || $at <= $state['baseline']) { continue; }
            $data = $this->clean($this->pick($row, $freshdesk
                ? ['id', 'status', 'priority', 'group_id', 'responder_id', 'requester_id', 'created_at', 'updated_at']
                : ['entity_id', 'increment_id', 'store_id', 'state', 'status', 'created_at', 'updated_at', 'order_currency_code', 'grand_total']),
                $freshdesk ? [$token, base64_encode($token . ':X')] : [$token]);
            $events[] = ['id' => $id . ':' . hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 'type' => $definition['event'], 'data' => $data];
        }
        $more = count($rows) === 100 && !$beyond;
        if ($more && $page === 300) { throw new RuntimeException('Evento: límite de 300 páginas; no se avanzó el cursor, reconcilia el intervalo.'); }
        $next = ['binding' => $binding, 'baseline' => $state['baseline'], 'cursor' => $more ? $state['cursor'] : $to];
        if ($more) { $next += ['from' => $from, 'to' => $to, 'page' => $page + 1]; }
        return ['events' => $events, 'state' => $next, 'has_more' => $more];
    }

    private function request(string $url, array $headers, array $query, int $now): array
    {
        try {
            $response = ($this->client ?? new Client())->request('GET', $url, ['headers' => $headers + ['Accept' => 'application/json'], 'query' => $query,
                'timeout' => 25, 'connect_timeout' => 8, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true]);
            $stream = $response->getBody();
            $raw = '';
            try {
                while (!$stream->eof() && strlen($raw) <= 2097152) {
                    $chunk = $stream->read(min(65536, 2097153 - strlen($raw)));
                    if ($chunk === '' && !$stream->eof()) { throw new RuntimeException('Lectura incompleta.'); }
                    $raw .= $chunk;
                }
            } finally { $stream->close(); }
        } catch (\Throwable $e) { throw new TriggerRetryException(60); }
        $status = $response->getStatusCode();
        if ($status === 429 || $status >= 500) {
            $retry = $response->getHeaderLine('Retry-After');
            $retryAt = ctype_digit($retry) ? $now + min(86400, (int)$retry) : strtotime($retry);
            throw new TriggerRetryException($retryAt === false ? 60 : max(60, min(86400, $retryAt - $now)));
        }
        if ($status < 200 || $status >= 300 || strlen($raw) > 2097152) { throw new RuntimeException('Evento: lectura rechazada o respuesta demasiado grande.'); }
        return $this->json($raw);
    }

    private function json(string $raw): array
    {
        try { $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
        catch (\Throwable $e) { throw new InvalidArgumentException('Evento: JSON inválido.'); }
        if (!is_array($body)) { throw new InvalidArgumentException('Evento: JSON estructurado requerido.'); }
        return $body;
    }

    private function text(mixed $value, int $max): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new InvalidArgumentException('Evento: configuración inválida.'); }
        return $value;
    }

    private function token(mixed $value): string
    {
        $value = $this->text($value, 16384);
        if (preg_match('/\s/', $value)) { throw new InvalidArgumentException('Evento: credencial inválida.'); }
        return $value;
    }

    private function identifier(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $value)) { throw new InvalidArgumentException('Evento: identificador inválido.'); }
        return $value;
    }

    private function numericId(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,24}$/D', (string)$value)) { throw new InvalidArgumentException('Evento: ID inválido.'); }
        return (string)$value;
    }

    private function pick(array $body, array $keys): array
    {
        return array_intersect_key($body, array_flip($keys));
    }

    private function clean(mixed $value, array $secrets, int $depth = 0): mixed
    {
        if ($depth > 24) { throw new InvalidArgumentException('Evento: profundidad excedida.'); }
        if (is_string($value)) { return str_replace($secrets, '[redacted]', $value); }
        if (!is_array($value)) { return $value; }
        $out = [];
        foreach ($value as $key => $item) {
            if (preg_match('/password|passwd|secret|token|authorization|api[_-]?key/i', (string)$key)) { continue; }
            $out[$key] = $this->clean($item, $secrets, $depth + 1);
        }
        return $out;
    }

    private function ack(array $events): array
    {
        return ['status' => 200, 'content_type' => 'text/plain', 'body' => '', 'events' => $events];
    }
}
