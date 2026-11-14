<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Core\ExpressionEvaluator;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class SupabaseNode implements NodeInterface
{
    private const OPERATIONS = ['list_rows', 'get_row', 'insert_rows', 'update_rows', 'delete_rows', 'rpc', 'storage_list', 'storage_upload', 'storage_download', 'storage_signed_url', 'storage_public_url', 'admin_list_users', 'admin_get_user', 'admin_create_user', 'admin_update_user', 'admin_delete_user'];

    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return ['type' => 'supabase', 'name' => 'Supabase', 'category' => 'Community', 'evaluates_expressions' => true,
            'description' => 'Filas REST, RPC, Storage y administración explícita de usuarios.', 'icon' => 'Database', 'is_start' => false,
            'help_text' => 'generic_bearer API Key JSON: {"project_url":"https://example.supabase.co","project_key":"sb_publishable_...","key_type":"publishable","user_access_token":"JWT opcional"}. key_type: publishable/anon (RLS), secret/service_role (privilegiada, requiere allow_privileged=true). Claves nuevas solo apikey; Authorization usa sesión JWT o clave JWT legacy según tipo. No desactiva RLS; secret/service_role omiten RLS, solo elección explícita. Una página, limit 1..1000. table/schema/function identificadores simples. filters es array [{"column":"id","operator":"eq","value":1}]; update/delete/get exigen filtros. payload JSON objeto/array; PATCH solo campos proporcionados y null/"" vacían explícitamente. RPC usa argumentos nombrados; permisos EXECUTE de la función y RLS dependen del servidor. Storage requiere políticas por bucket, subida content_base64 máximo 5 MiB sin sobrescritura implícita; enlace público requiere confirmación de bucket público y no cambia ACL. admin_* requiere clave privilegiada y allow_privileged=true; payload admite email,phone,user_metadata,app_metadata,email_confirm,phone_confirm,ban_duration sin passwords ni sesiones. Tokens de sesión, contraseñas y claves se excluyen de salidas. Rotación de claves y renovación de sesión manual. Eventos de BD: configura Database Webhooks INSERT/UPDATE/DELETE con secreto dedicado en Triggers; este nodo no registra recursos remotos. Sin reintentos; una escritura con fallo de transporte puede haber ocurrido.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial Supabase JSON'],
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'list_rows', 'options' => array_map(static fn ($v) => ['value' => $v, 'label' => $v], self::OPERATIONS)],
                'allow_privileged' => ['type' => 'select', 'label' => 'Autorizar uso de clave privilegiada', 'default' => 'false', 'options' => [['value' => 'false', 'label' => 'No (RLS)'], ['value' => 'true', 'label' => 'Sí, secret/service_role explícita']]],
                'schema' => ['type' => 'string', 'label' => 'Esquema REST', 'default' => 'public'],
                'table' => ['type' => 'string', 'label' => 'Tabla'],
                'columns' => ['type' => 'string', 'label' => 'Columnas (coma o *)', 'default' => '*'],
                'filters' => ['type' => 'textarea', 'label' => 'Filtros declarativos JSON', 'default' => '[]'],
                'order' => ['type' => 'textarea', 'label' => 'Orden JSON [{"column":"id","ascending":true}]'],
                'payload' => ['type' => 'textarea', 'label' => 'Filas, cambios o datos de usuario JSON'],
                'function_name' => ['type' => 'string', 'label' => 'Nombre de función RPC'],
                'arguments' => ['type' => 'textarea', 'label' => 'Argumentos RPC JSON nombrados', 'default' => '{}'],
                'limit' => ['type' => 'number', 'label' => 'Tamaño página (1..1000)', 'default' => 100],
                'offset' => ['type' => 'number', 'label' => 'Offset filas/storage', 'default' => 0],
                'page' => ['type' => 'number', 'label' => 'Página usuarios', 'default' => 1],
                'bucket' => ['type' => 'string', 'label' => 'Bucket Storage'],
                'object_path' => ['type' => 'string', 'label' => 'Ruta de objeto Storage (no ruta de servidor)'],
                'prefix' => ['type' => 'string', 'label' => 'Prefijo Storage list (vacío lista raíz)'],
                'content_base64' => ['type' => 'textarea', 'label' => 'Archivo base64 (subida, máximo 5 MiB)'],
                'mime_type' => ['type' => 'string', 'label' => 'MIME de subida', 'default' => 'application/octet-stream'],
                'upsert' => ['type' => 'select', 'label' => 'Sobrescribir objeto explícitamente', 'default' => 'false', 'options' => [['value' => 'false', 'label' => 'No'], ['value' => 'true', 'label' => 'Sí']]],
                'expires_in' => ['type' => 'number', 'label' => 'Enlace firmado: segundos (1..604800)', 'default' => 3600],
                'confirm_public_bucket' => ['type' => 'select', 'label' => 'Confirmo bucket público para URL pública', 'default' => 'false', 'options' => [['value' => 'false', 'label' => 'No'], ['value' => 'true', 'label' => 'Sí']]],
                'user_id' => ['type' => 'string', 'label' => 'UUID de usuario administrativo']
            ]];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (InvalidArgumentException $e) { return false; }
    }

    public function execute(array $parameters, array $context): array
    {
        $p = $this->prepare(ExpressionEvaluator::evaluate($parameters, $context));
        $credential = $this->credential($p['credential_id']);
        $privileged = in_array($credential['key_type'], ['secret', 'service_role'], true);
        if (($privileged && !$p['allow_privileged']) || (str_starts_with($p['operation'], 'admin_') && (!$privileged || !$p['allow_privileged'] || isset($credential['user_access_token'])))) { throw new InvalidArgumentException('Supabase: uso administrativo/privilegiado requiere clave secret/service_role, allow_privileged=true y sin sesión de usuario.'); }
        if ($p['operation'] === 'storage_public_url') {
            // Pure URL derivation does not invent an HTTP status; bucket visibility is externally configured.
            return ['success' => true, 'data' => ['public_url' => $credential['project_url'] . $p['path'], 'visibility_verified' => false]];
        }
        $headers = $p['options']['headers'] ?? [];
        $headers += ['apikey' => $credential['project_key'], 'Accept' => 'application/json'];
        if (isset($credential['user_access_token'])) { $headers['Authorization'] = 'Bearer ' . $credential['user_access_token']; }
        elseif (in_array($credential['key_type'], ['anon', 'service_role'], true)) { $headers['Authorization'] = 'Bearer ' . $credential['project_key']; }
        $options = $p['options']; $options['headers'] = $headers;
        $options += ['timeout' => 45, 'connect_timeout' => 10, 'read_timeout' => 45, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'stream' => true];
        try {
            $response = ($this->client ?? new Client())->request($p['method'], $credential['project_url'] . $p['path'], $options);
            $status = $response->getStatusCode(); $stream = $response->getBody(); $raw = '';
            try { while (!$stream->eof() && strlen($raw) <= 5242880) { $part = $stream->read(min(65536, 5242881 - strlen($raw))); if ($part === '' && !$stream->eof()) { throw new RuntimeException(); } $raw .= $part; } } finally { $stream->close(); }
            if (strlen($raw) > 5242880) { throw new RuntimeException(); }
        } catch (\Throwable $e) { throw new RuntimeException('Supabase: fallo de transporte/lectura o respuesta mayor de 5 MiB; sin reintento. Una escritura puede haber ocurrido.'); }
        if ($status < 200 || $status >= 300) { throw new RuntimeException('Supabase: solicitud rechazada (HTTP ' . $status . '); revisa claves/sesión, RLS, permisos, filtros o límites.', $status); }
        if ($p['operation'] === 'storage_download') {
            $data = ['content_base64' => base64_encode($raw), 'mime_type' => $response->getHeaderLine('Content-Type'), 'size' => strlen($raw), 'filename' => basename($p['object_path'])];
        } else {
            $data = $raw === '' ? [] : json_decode($raw, true, 64, JSON_BIGINT_AS_STRING);
            if ($raw !== '' && (json_last_error() !== JSON_ERROR_NONE || (!is_array($data) && !is_scalar($data) && $data !== null))) { throw new RuntimeException('Supabase: respuesta JSON inválida.'); }
            if (is_array($data) && (isset($data['error']) || isset($data['error_description']) || (isset($data['code']) && isset($data['message'])))) { throw new RuntimeException('Supabase: error funcional del servicio.'); }
            if ($p['operation'] === 'storage_signed_url') {
                $signed = $data['signedURL'] ?? $data['signedUrl'] ?? null;
                if (!is_string($signed) || !str_starts_with($signed, '/object/sign/') || preg_match('/[\x00-\x20\x7f]/', $signed) || str_contains($signed, '://') || str_contains($signed, '..')) { throw new RuntimeException('Supabase: enlace firmado inválido.'); }
                $data = ['signed_url' => $credential['project_url'] . '/storage/v1' . $signed, 'expires_in' => $p['expires_in']];
            } elseif (str_starts_with($p['operation'], 'admin_')) {
                $data = $this->safeUsers($data);
            }
            $data = $this->safe($data, [$credential['project_key'], $credential['user_access_token'] ?? '']);
        }
        $result = ['success' => true, 'status_code' => $status, 'data' => $data];
        if (in_array($p['operation'], ['list_rows', 'storage_list', 'admin_list_users'], true)) {
            $result['pagination'] = ['limit' => $p['limit'], 'offset' => $p['offset'], 'page' => $p['page'], 'content_range' => $response->getHeaderLine('Content-Range'), 'next_offset' => is_array($data) && array_is_list($data) && count($data) === $p['limit'] ? $p['offset'] + $p['limit'] : null];
        }
        return $result;
    }

    private function prepare(array $p): array
    {
        $op = $p['operation'] ?? 'list_rows';
        if (!in_array($op, self::OPERATIONS, true) || !is_string($p['credential_id'] ?? null) || trim($p['credential_id']) === '') { throw new InvalidArgumentException('Supabase: operación o credencial inválida.'); }
        $p += ['allow_privileged' => 'false', 'limit' => 100, 'offset' => 0, 'page' => 1];
        $p['allow_privileged'] = $this->boolean($p['allow_privileged']);
        $p['limit'] = $this->integer($p['limit'], 1, 1000); $p['offset'] = $this->integer($p['offset'], 0, 1000000000); $p['page'] = $this->integer($p['page'], 1, 1000000);
        $result = ['operation' => $op, 'credential_id' => $p['credential_id'], 'allow_privileged' => $p['allow_privileged'], 'limit' => $p['limit'], 'offset' => $p['offset'], 'page' => $p['page'], 'method' => 'GET', 'path' => '', 'options' => []];
        if (str_ends_with($op, '_rows') || $op === 'get_row' || $op === 'rpc') {
            $schema = $this->identifier($p['schema'] ?? 'public');
            $columns = $p['columns'] ?? '*';
            if (!is_string($columns) || strlen($columns) > 4096 || preg_match('/^(?:\*|[A-Za-z_][A-Za-z0-9_]*(?:,[A-Za-z_][A-Za-z0-9_]*)*)$/D', $columns) !== 1) { throw new InvalidArgumentException('Supabase: columns requiere * o nombres simples separados por coma.'); }
            $result['options']['headers'] = ['Accept-Profile' => $schema, 'Content-Profile' => $schema, 'Prefer' => 'return=representation,count=exact'];
            if ($op === 'rpc') {
                $result['path'] = '/rest/v1/rpc/' . $this->identifier($p['function_name'] ?? null); $result['method'] = 'POST';
                $arguments = $this->json($p['arguments'] ?? []);
                if ($arguments !== [] && array_is_list($arguments)) { throw new InvalidArgumentException('Supabase: RPC requiere objeto con argumentos nombrados.'); }
                foreach (array_keys($arguments) as $key) { $this->identifier($key); }
                $this->jsonValues($arguments); $result['options']['json'] = (object) $arguments;
            } else {
                $result['path'] = '/rest/v1/' . $this->identifier($p['table'] ?? null);
                $filters = $this->filters($p['filters'] ?? []);
                if (in_array($op, ['update_rows', 'delete_rows', 'get_row'], true) && $filters === []) { throw new InvalidArgumentException('Supabase: get/update/delete requieren filtros explícitos no vacíos.'); }
                $result['options']['query'] = ['select' => $columns] + $filters;
                if (in_array($op, ['list_rows', 'get_row'], true)) {
                    if ($op === 'get_row') { $result['options']['headers']['Accept'] = 'application/vnd.pgrst.object+json'; }
                    else { $result['options']['query'] += ['limit' => $p['limit'], 'offset' => $p['offset']]; }
                    if (isset($p['order']) && $p['order'] !== '') { $result['options']['query']['order'] = $this->order($this->json($p['order'])); }
                } elseif ($op === 'delete_rows') { $result['method'] = 'DELETE'; }
                else {
                    $payload = $this->json($p['payload'] ?? null);
                    if ($payload === []) { throw new InvalidArgumentException('Supabase: payload vacío.'); }
                    if ($op === 'update_rows') {
                        if (array_is_list($payload)) { throw new InvalidArgumentException('Supabase: actualizar requiere objeto de cambios.'); }
                        $rows = [$payload]; $result['method'] = 'PATCH';
                    } else { $rows = array_is_list($payload) ? $payload : [$payload]; $result['method'] = 'POST'; }
                    if (count($rows) > 1000) { throw new InvalidArgumentException('Supabase: máximo 1000 filas por inserción.'); }
                    foreach ($rows as $row) {
                        if (!is_array($row) || $row === [] || array_is_list($row)) { throw new InvalidArgumentException('Supabase: cada fila requiere objeto no vacío.'); }
                        foreach (array_keys($row) as $key) { $this->identifier($key); }
                        $this->jsonValues($row);
                    }
                    $result['options']['json'] = $payload;
                }
            }
            return $result;
        }
        if (str_starts_with($op, 'storage_')) {
            $bucket = $this->segment($p['bucket'] ?? null);
            if ($op === 'storage_list') {
                $prefix = $p['prefix'] ?? '';
                if (!is_string($prefix) || strlen($prefix) > 1024) { throw new InvalidArgumentException('Supabase: prefijo inválido.'); }
                if ($prefix !== '') { $this->objectPath($prefix); }
                $result['method'] = 'POST'; $result['path'] = '/storage/v1/object/list/' . $bucket;
                $result['options']['json'] = ['prefix' => $prefix, 'limit' => $p['limit'], 'offset' => $p['offset'], 'sortBy' => ['column' => 'name', 'order' => 'asc']];
                return $result;
            }
            $path = $this->objectPath($p['object_path'] ?? null); $result['object_path'] = $p['object_path'];
            $result['path'] = '/storage/v1/object/' . $bucket . '/' . $path;
            if ($op === 'storage_upload') {
                $encoded = $p['content_base64'] ?? null;
                if (!is_string($encoded) || strlen($encoded) > 6990508 || ($bytes = base64_decode($encoded, true)) === false || base64_encode($bytes) !== $encoded || strlen($bytes) > 5242880) { throw new InvalidArgumentException('Supabase: content_base64 inválido o mayor de 5 MiB.'); }
                $mime = $p['mime_type'] ?? 'application/octet-stream';
                if (!is_string($mime) || preg_match('/^[A-Za-z0-9!#$&^_.+-]+\/[A-Za-z0-9!#$&^_.+-]+$/D', $mime) !== 1) { throw new InvalidArgumentException('Supabase: MIME inválido.'); }
                $result['method'] = 'POST'; $result['options'] = ['body' => $bytes, 'headers' => ['Content-Type' => $mime, 'x-upsert' => $this->boolean($p['upsert'] ?? 'false') ? 'true' : 'false']];
            } elseif ($op === 'storage_signed_url') {
                $result['expires_in'] = $this->integer($p['expires_in'] ?? 3600, 1, 604800);
                $result['method'] = 'POST'; $result['path'] = '/storage/v1/object/sign/' . $bucket . '/' . $path;
                $result['options']['json'] = ['expiresIn' => $result['expires_in']];
            } elseif ($op === 'storage_public_url') {
                if (!$this->boolean($p['confirm_public_bucket'] ?? 'false')) { throw new InvalidArgumentException('Supabase: URL pública requiere confirmación explícita de bucket público.'); }
                $result['path'] = '/storage/v1/object/public/' . $bucket . '/' . $path;
            }
            return $result;
        }
        if (!$p['allow_privileged']) { throw new InvalidArgumentException('Supabase: admin requiere allow_privileged=true.'); }
        $result['path'] = '/auth/v1/admin/users';
        if (in_array($op, ['admin_get_user', 'admin_update_user', 'admin_delete_user'], true)) { $result['path'] .= '/' . $this->uuid($p['user_id'] ?? null); }
        if ($op === 'admin_list_users') { $result['options']['query'] = ['page' => $p['page'], 'per_page' => $p['limit']]; }
        elseif ($op === 'admin_delete_user') { $result['method'] = 'DELETE'; }
        elseif ($op === 'admin_create_user' || $op === 'admin_update_user') {
            $payload = $this->json($p['payload'] ?? null);
            if ($payload === [] || array_is_list($payload) || array_diff(array_keys($payload), ['email', 'phone', 'user_metadata', 'app_metadata', 'email_confirm', 'phone_confirm', 'ban_duration'])) { throw new InvalidArgumentException('Supabase: campos admin no admitidos; nunca contraseñas.'); }
            if ($op === 'admin_create_user' && !isset($payload['email']) && !isset($payload['phone'])) { throw new InvalidArgumentException('Supabase: crear usuario requiere email o teléfono sin contraseña.'); }
            if (array_key_exists('email', $payload) && (!is_string($payload['email']) || filter_var($payload['email'], FILTER_VALIDATE_EMAIL) === false || strlen($payload['email']) > 254)) { throw new InvalidArgumentException('Supabase: email inválido.'); }
            if (array_key_exists('phone', $payload) && (!is_string($payload['phone']) || preg_match('/^\+?[1-9][0-9]{5,14}$/D', $payload['phone']) !== 1)) { throw new InvalidArgumentException('Supabase: teléfono inválido.'); }
            foreach (['email_confirm', 'phone_confirm'] as $key) { if (array_key_exists($key, $payload) && !is_bool($payload[$key])) { throw new InvalidArgumentException('Supabase: confirmación admin requiere booleano.'); } }
            foreach (['user_metadata', 'app_metadata'] as $key) { if (isset($payload[$key]) && (!is_array($payload[$key]) || ($payload[$key] !== [] && array_is_list($payload[$key])))) { throw new InvalidArgumentException('Supabase: metadatos requieren objeto.'); } }
            if (isset($payload['ban_duration']) && (!is_string($payload['ban_duration']) || preg_match('/^(?:none|[1-9][0-9]{0,5}[smh])$/D', $payload['ban_duration']) !== 1)) { throw new InvalidArgumentException('Supabase: ban_duration inválido.'); }
            $this->jsonValues($payload); $this->noSecrets($payload);
            foreach (['user_metadata', 'app_metadata'] as $key) { if (array_key_exists($key, $payload) && $payload[$key] === []) { $payload[$key] = new \stdClass(); } }
            $result['method'] = $op === 'admin_create_user' ? 'POST' : 'PUT'; $result['options']['json'] = $payload;
        }
        return $result;
    }

    private function credential(string $id): array
    {
        try { $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id); } catch (\Throwable $e) { throw new RuntimeException('Supabase: error de almacenamiento/descifrado de credencial.'); }
        if (($credential['type'] ?? '') !== 'generic_bearer' || !is_string($credential['data']['api_key'] ?? null)) { throw new InvalidArgumentException('Supabase: requiere generic_bearer con JSON de configuración.'); }
        $data = $this->json($credential['data']['api_key']);
        $url = $data['project_url'] ?? null; $key = $data['project_key'] ?? null; $type = $data['key_type'] ?? null;
        if (!is_string($url) || preg_match('/^https:\/\/[a-z0-9][a-z0-9-]{0,62}\.supabase\.co\/?$/D', $url) !== 1 || !is_string($key) || strlen($key) < 16 || strlen($key) > 8192 || preg_match('/[\x00-\x20\x7f]/', $key) || !in_array($type, ['publishable', 'anon', 'secret', 'service_role'], true)) { throw new InvalidArgumentException('Supabase: project_url alojada, project_key o key_type inválidos.'); }
        if (in_array($type, ['publishable', 'secret'], true)) {
            if (!str_starts_with($key, 'sb_' . $type . '_')) { throw new InvalidArgumentException('Supabase: prefijo de clave no coincide con key_type.'); }
        } else {
            $parts = explode('.', $key); $claims = count($parts) === 3 ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
            // Classification only, not JWT verification: Supabase verifies signatures and permissions.
            if (!is_array($claims) || ($claims['role'] ?? null) !== $type) { throw new InvalidArgumentException('Supabase: clave JWT legacy no coincide con key_type.'); }
        }
        $result = ['project_url' => rtrim($url, '/'), 'project_key' => $key, 'key_type' => $type];
        if (isset($data['user_access_token'])) {
            $token = $data['user_access_token'];
            if (!is_string($token) || strlen($token) > 16384 || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token) !== 1) { throw new InvalidArgumentException('Supabase: sesión JWT inválida.'); }
            $result['user_access_token'] = $token;
        }
        return $result;
    }

    private function filters(mixed $input): array
    {
        $filters = $this->json($input);
        if (!array_is_list($filters) || count($filters) > 30) { throw new InvalidArgumentException('Supabase: filtros requieren lista de máximo 30.'); }
        $query = [];
        foreach ($filters as $filter) {
            if (!is_array($filter) || array_diff(array_keys($filter), ['column', 'operator', 'value']) || !array_key_exists('value', $filter)) { throw new InvalidArgumentException('Supabase: filtro inválido.'); }
            $column = $this->identifier($filter['column'] ?? null); $op = $filter['operator'] ?? null; $value = $filter['value'];
            if (isset($query[$column]) || !in_array($op, ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'like', 'ilike', 'is', 'in'], true)) { throw new InvalidArgumentException('Supabase: operador inválido o columna de filtro duplicada.'); }
            if ($op === 'is') { if (!in_array($value, [null, true, false], true)) { throw new InvalidArgumentException('Supabase: is requiere null o booleano.'); } $literal = $value === null ? 'null' : ($value ? 'true' : 'false'); }
            elseif ($op === 'in') {
                if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 100) { throw new InvalidArgumentException('Supabase: in requiere 1..100 valores.'); }
                $literal = '(' . implode(',', array_map(fn ($v) => $this->filterValue($v, true), $value)) . ')';
            } else { $literal = $this->filterValue($value, false); }
            $query[$column] = $op . '.' . $literal;
        }
        return $query;
    }

    private function filterValue(mixed $value, bool $quoted): string
    {
        if ((!is_scalar($value) && $value !== null) || $value === null || (is_float($value) && !is_finite($value))) { throw new InvalidArgumentException('Supabase: filtro requiere valor escalar; null solo con is.'); }
        $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        if (strlen($text) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $text)) { throw new InvalidArgumentException('Supabase: valor de filtro inválido.'); }
        return $quoted ? '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $text) . '"' : $text;
    }

    private function order(array $orders): string
    {
        if (!array_is_list($orders) || count($orders) < 1 || count($orders) > 10) { throw new InvalidArgumentException('Supabase: orden requiere 1..10 columnas.'); }
        $values = [];
        foreach ($orders as $order) {
            if (!is_array($order) || array_diff(array_keys($order), ['column', 'ascending', 'nulls_first']) || (isset($order['ascending']) && !is_bool($order['ascending'])) || (isset($order['nulls_first']) && !is_bool($order['nulls_first']))) { throw new InvalidArgumentException('Supabase: orden inválido.'); }
            $values[] = $this->identifier($order['column'] ?? null) . (($order['ascending'] ?? true) ? '.asc' : '.desc') . (isset($order['nulls_first']) ? ($order['nulls_first'] ? '.nullsfirst' : '.nullslast') : '');
        }
        return implode(',', $values);
    }

    private function identifier(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $value) !== 1) { throw new InvalidArgumentException('Supabase: identificador simple inválido.'); }
        return $value;
    }

    private function segment(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/D', $value) !== 1 || str_contains($value, '..')) { throw new InvalidArgumentException('Supabase: bucket inválido.'); }
        return rawurlencode($value);
    }

    private function objectPath(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 1024 || preg_match('/[\x00-\x1f\x7f\\\\]/', $value)) { throw new InvalidArgumentException('Supabase: ruta de objeto inválida.'); }
        $parts = explode('/', $value);
        foreach ($parts as $part) { if ($part === '' || $part === '.' || $part === '..') { throw new InvalidArgumentException('Supabase: segmentos de objeto inválidos.'); } }
        return implode('/', array_map('rawurlencode', $parts));
    }

    private function uuid(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[a-fA-F0-9]{8}(?:-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}$/D', $value) !== 1) { throw new InvalidArgumentException('Supabase: UUID inválido.'); }
        return $value;
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if (!is_scalar($value) || is_bool($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $min || (int) $value > $max) { throw new InvalidArgumentException('Supabase: número fuera de rango.'); }
        return (int) $value;
    }

    private function boolean(mixed $value): bool
    {
        if (!in_array($value, [true, false, 'true', 'false'], true)) { throw new InvalidArgumentException('Supabase: se requiere booleano explícito.'); }
        return $value === true || $value === 'true';
    }

    private function json(mixed $value): array
    {
        if (is_string($value) && strlen($value) <= 5242880) { try { $value = json_decode($value, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); } catch (\JsonException $e) { throw new InvalidArgumentException('Supabase: JSON inválido.'); } }
        if (!is_array($value)) { throw new InvalidArgumentException('Supabase: requiere JSON/array.'); }
        return $value;
    }

    private function jsonValues(mixed $value): void
    {
        try { $raw = json_encode($value, JSON_THROW_ON_ERROR); } catch (\JsonException $e) { throw new InvalidArgumentException('Supabase: datos JSON inválidos.'); }
        if (strlen($raw) > 5242880) { throw new InvalidArgumentException('Supabase: datos mayores de 5 MiB.'); }
    }

    private function noSecrets(array $value): void
    {
        foreach ($value as $key => $item) { if (is_string($key) && preg_match('/password|token|secret|api.?key/i', $key)) { throw new InvalidArgumentException('Supabase: no se admiten contraseñas/tokens/claves en datos administrativos.'); } if (is_array($item)) { $this->noSecrets($item); } }
    }

    private function safeUsers(mixed $data): array
    {
        if (!is_array($data)) { throw new RuntimeException('Supabase: respuesta admin inválida.'); }
        if (isset($data['users'])) { if (!is_array($data['users']) || !array_is_list($data['users'])) { throw new RuntimeException('Supabase: lista de usuarios inválida.'); } $data['users'] = array_map(fn ($user) => $this->safeUsers($user), $data['users']); return array_intersect_key($data, array_flip(['users', 'aud', 'next_page', 'last_page', 'total'])); }
        return array_intersect_key($data, array_flip(['id', 'email', 'phone', 'aud', 'role', 'created_at', 'updated_at', 'last_sign_in_at', 'email_confirmed_at', 'phone_confirmed_at', 'banned_until', 'user_metadata', 'app_metadata']));
    }

    private function safe(mixed $value, array $secrets): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) { if (is_string($key) && preg_match('/^(?:password|encrypted_password|access_token|refresh_token|session|sessions|api_key|project_key|service_role_key|secret_key)$/i', $key)) { unset($value[$key]); } else { $value[$key] = $this->safe($item, $secrets); } }
            return $value;
        }
        if (is_string($value)) { foreach ($secrets as $secret) { if ($secret !== '') { $value = str_replace($secret, '[redacted]', $value); } } }
        return $value;
    }
}
