<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;
use stdClass;
use Throwable;

class WordPressNode implements NodeInterface
{
    private const STATUSES = ['draft', 'pending', 'private', 'publish'];
    private const FIELDS = 'id,link,status,title,content,excerpt,slug,date,date_gmt,modified,modified_gmt,categories,tags';

    private ?ClientInterface $client;
    private ?CredentialManager $credentials;

    public function __construct(?ClientInterface $client = null, ?CredentialManager $credentials = null)
    {
        // El catálogo instancia este nodo sin acceder a credenciales ni a WordPress.
        $this->client = $client;
        $this->credentials = $credentials;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'wordpress',
            'name' => 'WordPress',
            'category' => 'Community',
            'description' => 'Lista, obtiene, crea o actualiza entradas mediante WordPress REST API.',
            'icon' => 'FileText',
            'is_start' => false,
            'help_text' => 'Guarda un JSON con site_url, username y application_password en API Key de una credencial Bearer Token / API Key. Usa la URL HTTPS del sitio, incluido su subdirectorio, sin /wp-json ni consultas. Requiere Application Passwords y /wp-json/wp/v2/posts accesible. Crear exige título y usa draft si no eliges estado. Actualizar exige ID y al menos un campo: solo se envían claves proporcionadas; no se aplica un estado predeterminado. Para vaciar contenido, extracto o slug envía texto vacío; para quitar categorías/etiquetas usa []. Las listas admiten JSON o {{node_id.data.ids}}. Usa {{variable}} en textos. Listar usa publish por defecto y admite filtrar por estado. Salida individual: data.id, link, status, title y fechas; lista: data.posts y data.pagination. Sin programación de publicaciones, redirecciones ni reintentos automáticos. Los errores detienen el flujo.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial WordPress (JSON en API Key)'],
                'operation' => [
                    'type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'list',
                    'options' => [
                        ['value' => 'list', 'label' => 'Listar entradas'],
                        ['value' => 'get', 'label' => 'Obtener entrada'],
                        ['value' => 'create', 'label' => 'Crear entrada'],
                        ['value' => 'update', 'label' => 'Actualizar entrada']
                    ]
                ],
                'post_id' => ['type' => 'number', 'required' => false, 'label' => 'ID de entrada (obtener/actualizar)'],
                'title' => ['type' => 'string', 'required' => false, 'label' => 'Título (obligatorio al crear)'],
                'content' => ['type' => 'textarea', 'required' => false, 'label' => 'Contenido (crear/actualizar)'],
                'excerpt' => ['type' => 'textarea', 'required' => false, 'label' => 'Extracto (crear/actualizar)'],
                'status' => [
                    'type' => 'select', 'required' => false, 'label' => 'Estado (crear/actualizar/listar)',
                    'options' => [
                        ['value' => '', 'label' => 'Predeterminado: crear borrador / actualizar conservar / listar publicados'],
                        ['value' => 'draft', 'label' => 'Borrador'],
                        ['value' => 'pending', 'label' => 'Pendiente de revisión'],
                        ['value' => 'private', 'label' => 'Privado'],
                        ['value' => 'publish', 'label' => 'Publicado (solicitud explícita)']
                    ]
                ],
                'slug' => ['type' => 'string', 'required' => false, 'label' => 'Slug (opcional)'],
                'categories' => ['type' => 'textarea', 'required' => false, 'label' => 'Categorías: lista JSON de IDs', 'placeholder' => '[2,5]'],
                'tags' => ['type' => 'textarea', 'required' => false, 'label' => 'Etiquetas: lista JSON de IDs', 'placeholder' => '[8,13]'],
                'search' => ['type' => 'string', 'required' => false, 'label' => 'Búsqueda (listar)'],
                'page' => ['type' => 'number', 'required' => false, 'label' => 'Página (listar)', 'default' => 1],
                'per_page' => ['type' => 'number', 'required' => false, 'label' => 'Entradas por página (1–100)', 'default' => 10, 'min' => 1, 'max' => 100]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->prepareRequest($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine ya usa ExpressionEvaluator; validar los resultados sin una segunda interpolación.
        $request = $this->prepareRequest($parameters);
        try {
            $manager = $this->credentials ?? new CredentialManager();
            $credential = $manager->getCredentialDecrypted($request['credential_id']);
        } catch (Throwable $e) {
            throw new RuntimeException('WordPress: no se pudo recuperar o descifrar la credencial en el gestor de p8p.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('WordPress: la credencial seleccionada no existe.');
        }
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($credential['data']['api_key'] ?? null)) {
            throw new InvalidArgumentException('WordPress: selecciona una credencial Bearer Token / API Key con configuración JSON.');
        }
        $config = $this->parseCredential($credential['data']['api_key']);
        $options = [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($config['username'] . ':' . $config['application_password']),
                'Accept' => 'application/json'
            ],
            'query' => $request['query'] + ['_fields' => self::FIELDS],
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify' => true,
            'allow_redirects' => false,
            'http_errors' => false
        ];
        if ($request['method'] === 'POST') {
            $options['json'] = $request['payload'];
        }
        $notice = $request['method'] === 'POST'
            ? ' La escritura puede haberse realizado; comprueba la entrada antes de repetirla.' : '';
        try {
            $client = $this->client ?? new Client();
            $response = $client->request($request['method'], $config['site_url'] . '/wp-json/wp/v2/posts' . $request['path'], $options);
            $status = $response->getStatusCode();
            $rawBody = (string)$response->getBody();
            $body = json_decode($rawBody, true);
            // json_decode(..., true) convierte tanto {} como [] en []; conservar la distinción.
            $isListResponse = str_starts_with(ltrim($rawBody), '[');
        } catch (Throwable $e) {
            throw new RuntimeException('WordPress: fallo de transporte o lectura de la respuesta.' . $notice);
        }

        $bodyError = is_array($body) && (isset($body['code']) || ($body['success'] ?? null) === false);
        if ($status < 200 || $status >= 300 || $bodyError) {
            $code = is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : '';
            $errorStatus = is_array($body) ? ($body['data']['status'] ?? null) : null;
            $effectiveStatus = $bodyError && is_int($errorStatus) && $errorStatus >= 400 && $errorStatus <= 599
                ? $errorStatus : $status;
            // Solo mensajes conocidos: nunca reflejar mensajes, encabezados o cuerpos remotos.
            $reason = match (true) {
                $status >= 300 && $status < 400 => 'redirección rechazada; configura la URL HTTPS canónica del sitio',
                $effectiveStatus === 401 || in_array($code, ['incorrect_password', 'invalid_username', 'invalid_email', 'application_passwords_disabled', 'application_passwords_disabled_for_user'], true)
                    => 'autenticación rechazada; revisa el usuario, la Application Password y su disponibilidad en el sitio',
                $effectiveStatus === 429 || in_array($code, ['rest_rate_limit_exceeded', 'rate_limit_exceeded'], true)
                    => 'límite de solicitudes alcanzado; espera antes de volver a intentar',
                $code === 'rest_no_route' => 'ruta REST no disponible; revisa la URL del sitio y el acceso a /wp-json',
                $code === 'rest_invalid_post_id' || $effectiveStatus === 404 => 'entrada inexistente o recurso REST no disponible',
                $effectiveStatus === 403 => 'permisos insuficientes o acceso REST bloqueado; revisa las capacidades del usuario',
                $effectiveStatus >= 500 => 'error del servidor WordPress',
                in_array($effectiveStatus, [400, 422], true) => 'WordPress rechazó los campos, IDs, estado o paginación de la solicitud',
                default => 'WordPress rechazó la operación'
            };
            throw new RuntimeException("WordPress: {$reason} (HTTP {$status})." . $notice, $effectiveStatus);
        }

        try {
            if ($request['operation'] === 'list') {
                if (!$isListResponse || !is_array($body) || !array_is_list($body) || count($body) > $request['query']['per_page']) {
                    throw new InvalidArgumentException();
                }
                $posts = array_map(fn($post): array => $this->normalizePost($post), $body);
                $data = [
                    'posts' => $posts,
                    'pagination' => [
                        'page' => $request['query']['page'], 'per_page' => $request['query']['per_page'],
                        'count' => count($posts),
                        'total' => $this->paginationHeader($response->getHeaderLine('X-WP-Total')),
                        'total_pages' => $this->paginationHeader($response->getHeaderLine('X-WP-TotalPages'))
                    ]
                ];
            } else {
                $data = $this->normalizePost($body);
                if ($request['path'] !== '' && $data['id'] !== (int)substr($request['path'], 1)) {
                    throw new InvalidArgumentException();
                }
            }
            $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            foreach ([$config['application_password'], base64_encode($config['username'] . ':' . $config['application_password'])] as $secret) {
                if (str_contains($encoded, $secret)) {
                    throw new InvalidArgumentException();
                }
            }
        } catch (Throwable $e) {
            throw new RuntimeException('WordPress: respuesta inválida o incompleta.' . $notice, $status);
        }
        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepareRequest(array $parameters): array
    {
        $credentialId = $this->text($parameters['credential_id'] ?? null);
        if (trim($credentialId) === '') {
            throw new InvalidArgumentException('WordPress: selecciona una credencial.');
        }
        $operation = array_key_exists('operation', $parameters) ? $parameters['operation'] : 'list';
        if (!is_string($operation) || !in_array($operation, ['list', 'get', 'create', 'update'], true)) {
            throw new InvalidArgumentException('WordPress: operación inválida.');
        }
        $path = in_array($operation, ['get', 'update'], true)
            ? '/' . $this->positiveInteger($parameters['post_id'] ?? null) : '';
        $query = [];
        $payload = [];
        if ($operation === 'list') {
            $query['page'] = $this->positiveInteger(array_key_exists('page', $parameters) ? $parameters['page'] : 1);
            $query['per_page'] = $this->positiveInteger(array_key_exists('per_page', $parameters) ? $parameters['per_page'] : 10, 100);
            if (array_key_exists('search', $parameters)) {
                $query['search'] = $this->text($parameters['search']);
            }
        }
        if (in_array($operation, ['list', 'create', 'update'], true) && array_key_exists('status', $parameters)) {
            $status = $this->text($parameters['status']);
            if ($status !== '' && !in_array($status, self::STATUSES, true)) {
                throw new InvalidArgumentException('WordPress: estado inválido; usa draft, pending, private o publish. No se admite programación.');
            }
            if ($status !== '') {
                if ($operation === 'list') {
                    $query['status'] = $status;
                } else {
                    $payload['status'] = $status;
                }
            }
        }
        if (in_array($operation, ['create', 'update'], true)) {
            // array_key_exists distingue un campo omitido de un vaciado explícito.
            foreach (['title', 'content', 'excerpt', 'slug'] as $field) {
                if (array_key_exists($field, $parameters)) {
                    $payload[$field] = $this->text($parameters[$field]);
                }
            }
            if ($operation === 'create' && trim($payload['title'] ?? '') === '') {
                throw new InvalidArgumentException('WordPress: el título debe ser texto no vacío al crear.');
            }
            foreach (['categories', 'tags'] as $field) {
                if (array_key_exists($field, $parameters)) {
                    $payload[$field] = $this->idList($parameters[$field]);
                }
            }
            if ($operation === 'create') {
                $payload['status'] = $payload['status'] ?? 'draft';
            } elseif ($payload === []) {
                throw new InvalidArgumentException('WordPress: proporciona al menos un campo para actualizar.');
            }
        }
        return ['credential_id' => trim($credentialId), 'operation' => $operation,
            'method' => in_array($operation, ['create', 'update'], true) ? 'POST' : 'GET',
            'path' => $path, 'query' => $query, 'payload' => $payload];
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")
            || preg_match('/{{\s*[A-Za-z0-9_.]+\s*}}/', $value) === 1) {
            throw new InvalidArgumentException('WordPress: se esperaba texto UTF-8 válido con expresiones resueltas.');
        }
        return $value;
    }

    private function positiveInteger(mixed $value, int $maximum = PHP_INT_MAX): int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[1-9][0-9]*$/D', (string)$value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]]) === false) {
            throw new InvalidArgumentException('WordPress: ID o paginación inválidos; usa enteros positivos y per_page de 1 a 100.');
        }
        return (int)$value;
    }

    private function idList(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, false, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('WordPress: categorías y etiquetas requieren listas JSON de IDs; usa [] para vaciar.');
            }
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('WordPress: categorías y etiquetas deben ser listas de IDs positivos.');
        }
        return array_values(array_unique(array_map(fn($id): int => $this->positiveInteger($id), $value)));
    }

    private function parseCredential(string $json): array
    {
        try {
            $config = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('WordPress: JSON de credencial inválido.');
        }
        if (!$config instanceof stdClass || array_diff(array_keys(get_object_vars($config)), ['site_url', 'username', 'application_password']) !== []) {
            throw new InvalidArgumentException('WordPress: la credencial debe ser un objeto con site_url, username y application_password.');
        }
        foreach (['site_url', 'username', 'application_password'] as $field) {
            if (!is_string($config->$field ?? null) || trim($config->$field) === '' || !mb_check_encoding($config->$field, 'UTF-8')) {
                throw new InvalidArgumentException('WordPress: faltan datos válidos en la credencial.');
            }
        }
        $url = $config->site_url;
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) || $parts === false || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || preg_match('/[\s\x00-\x1f\x7f\\\\]/', $url) === 1
            || preg_match('~(?:^|/)(?:\.{1,2}|wp-json)(?:/|$)~i', rawurldecode($parts['path'] ?? '')) === 1) {
            throw new InvalidArgumentException('WordPress: site_url debe ser la URL HTTPS del sitio, con subdirectorio si corresponde, sin credenciales, consulta ni /wp-json.');
        }
        if (preg_match('/[:\x00-\x1f\x7f]/', $config->username) === 1) {
            throw new InvalidArgumentException('WordPress: nombre de usuario inválido para autenticación Basic.');
        }
        // WordPress muestra las Application Passwords agrupadas con espacios.
        $password = str_replace(' ', '', $config->application_password);
        if (preg_match('/^[A-Za-z0-9]{24}$/D', $password) !== 1) {
            throw new InvalidArgumentException('WordPress: proporciona una Application Password de 24 caracteres alfanuméricos, no la contraseña habitual.');
        }
        return ['site_url' => rtrim($url, '/'), 'username' => $config->username, 'application_password' => $password];
    }

    private function normalizePost(mixed $post): array
    {
        if (!is_array($post) || !is_string($post['link'] ?? null) || !filter_var($post['link'], FILTER_VALIDATE_URL)
            || !in_array(parse_url($post['link'], PHP_URL_SCHEME), ['http', 'https'], true)
            || parse_url($post['link'], PHP_URL_USER) !== null || parse_url($post['link'], PHP_URL_PASS) !== null
            || !is_string($post['status'] ?? null) || !is_array($post['title'] ?? null) || !is_string($post['title']['rendered'] ?? null)) {
            throw new InvalidArgumentException();
        }
        $data = ['id' => $this->positiveInteger($post['id'] ?? null), 'link' => $post['link'],
            'status' => $post['status'], 'title' => $post['title']['rendered']];
        foreach (['date', 'date_gmt', 'modified', 'modified_gmt', 'slug'] as $field) {
            if (array_key_exists($field, $post)) {
                if (!is_string($post[$field]) && $post[$field] !== null) {
                    throw new InvalidArgumentException();
                }
                $data[$field] = $post[$field];
            }
        }
        foreach (['content', 'excerpt'] as $field) {
            if (isset($post[$field])) {
                if (!is_array($post[$field]) || !is_string($post[$field]['rendered'] ?? null)) {
                    throw new InvalidArgumentException();
                }
                $data[$field] = $post[$field]['rendered'];
            }
        }
        foreach (['categories', 'tags'] as $field) {
            if (array_key_exists($field, $post)) {
                $data[$field] = $this->idList($post[$field]);
            }
        }
        return $data;
    }

    private function paginationHeader(string $value): ?int
    {
        return preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false ? (int)$value : null;
    }
}
