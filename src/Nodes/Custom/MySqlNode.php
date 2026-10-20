<?php

namespace P8P\Nodes\Custom;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;
use stdClass;
use Throwable;

class MySqlNode implements NodeInterface
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 1000;

    private ?CredentialManager $credentials;
    private ?Closure $connectionFactory;

    public function __construct(?CredentialManager $credentials = null, ?Closure $connectionFactory = null)
    {
        // Descubrimiento sin I/O; la fábrica permite simular PDO sin abrir bases reales.
        $this->credentials = $credentials;
        $this->connectionFactory = $connectionFactory;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'mysql',
            'name' => 'MySQL',
            'category' => 'Community',
            'description' => 'Consulta, inserta, actualiza o elimina registros en una base MySQL externa.',
            'icon' => 'Database',
            'is_start' => false,
            'help_text' => 'Guarda en API Key de una credencial Bearer Token / API Key un JSON con host, port, database, username, password y tls. TLS se activa por defecto: tls={"enabled":true,"ca":"/ruta/ca.pem"}; cert y key son opcionales y deben ir juntos. Para conexión sin TLS indica tls={"enabled":false}. Usa un host TCP, no localhost. Tablas/columnas: letras ASCII, dígitos y _, empezando por letra o _; máximo 64 caracteres, sin prefijos de base ni SQL. Valores/filtros: objetos JSON o {{node_id.data.objeto}}; solo valores escalares o null. Filtros unidos con AND; null compara con NULL. UPDATE/DELETE requieren filtros. INSERT no admite filtros. SELECT: límite 100 por defecto, máximo 1000, sin orden garantizado. Salida: data.rows y data.count, o data.affected_rows y data.insert_id. Los fallos detienen el flujo; no se reintentan escrituras.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial MySQL (JSON en API Key)'],
                'operation' => [
                    'type' => 'select',
                    'required' => true,
                    'label' => 'Operación',
                    'default' => 'SELECT',
                    'options' => array_map(static fn(string $operation): array => ['value' => $operation, 'label' => $operation], ['SELECT', 'INSERT', 'UPDATE', 'DELETE'])
                ],
                'table' => ['type' => 'string', 'required' => true, 'label' => 'Tabla', 'placeholder' => 'clientes'],
                'columns' => [
                    'type' => 'string', 'required' => false, 'label' => 'Columnas SELECT (comas o lista JSON; vacío = todas)',
                    'placeholder' => 'id,nombre o ["id","nombre"]'
                ],
                'values' => [
                    'type' => 'textarea', 'required' => false, 'label' => 'Valores INSERT/UPDATE (objeto JSON)',
                    'placeholder' => '{"nombre":"{{nombre}}","activo":true}'
                ],
                'filters' => [
                    'type' => 'textarea', 'required' => false, 'label' => 'Filtros de igualdad AND (objeto JSON)',
                    'placeholder' => '{"id":123}'
                ],
                'limit' => [
                    'type' => 'number', 'required' => false, 'label' => 'Límite SELECT (1–1000)',
                    'default' => self::DEFAULT_LIMIT, 'min' => 1, 'max' => self::MAX_LIMIT
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->prepareQuery($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine ya resuelve las expresiones mediante ExpressionEvaluator. No interpolar dos veces.
        $query = $this->prepareQuery($parameters);
        try {
            $manager = $this->credentials ?? new CredentialManager();
            $credential = $manager->getCredentialDecrypted($query['credential_id']);
        } catch (Throwable $e) {
            throw new RuntimeException('MySQL: no se pudo recuperar o descifrar la credencial en el gestor de p8p.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('MySQL: la credencial seleccionada no existe.');
        }
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($credential['data']['api_key'] ?? null)) {
            throw new InvalidArgumentException('MySQL: selecciona una credencial Bearer Token / API Key con la configuración JSON.');
        }
        $connection = $this->prepareConnection($credential['data']['api_key']);

        try {
            // Conexión nueva e independiente; nunca usar Database::getConnection().
            $pdo = $this->connectionFactory !== null
                ? ($this->connectionFactory)($connection['dsn'], $connection['username'], $connection['password'], $connection['options'])
                : new PDO($connection['dsn'], $connection['username'], $connection['password'], $connection['options']);
            if (!$pdo instanceof PDO) {
                throw new RuntimeException();
            }
            if ($connection['tls']) {
                // Fallar si el servidor/cliente degradó la conexión a texto plano.
                $tlsStatement = $pdo->prepare("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
                if ($tlsStatement === false || !$tlsStatement->execute()) {
                    throw new RuntimeException();
                }
                $tlsRow = $tlsStatement->fetch(PDO::FETCH_NUM);
                $tlsStatement->closeCursor();
                if (!is_array($tlsRow) || !is_string($tlsRow[1] ?? null) || $tlsRow[1] === '') {
                    throw new RuntimeException();
                }
            }
        } catch (Throwable $e) {
            throw $this->safeError($e, true, $query['operation']);
        }

        try {
            $statement = $pdo->prepare($query['sql']);
            if ($statement === false) {
                throw new RuntimeException();
            }
            foreach ($query['bindings'] as $index => $binding) {
                if (!$statement->bindValue($index + 1, $binding[0], $binding[1])) {
                    throw new RuntimeException();
                }
            }
            if (!$statement->execute()) {
                throw new RuntimeException();
            }
            if ($query['operation'] === 'SELECT') {
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                $data = ['rows' => $rows, 'count' => count($rows)];
            } else {
                $data = ['affected_rows' => $statement->rowCount()];
                if ($query['operation'] === 'INSERT') {
                    $id = $pdo->lastInsertId();
                    $data['insert_id'] = $id === false || $id === '0' || $id === '' ? null : $id;
                }
            }
            // La salida del motor se serializa como JSON; rechazar datos binarios no representables.
            json_encode($data, JSON_THROW_ON_ERROR);
            $statement->closeCursor();
            return ['success' => true, 'data' => $data];
        } catch (Throwable $e) {
            throw $this->safeError($e, false, $query['operation']);
        }
    }

    private function prepareQuery(array $parameters): array
    {
        if (!is_string($parameters['credential_id'] ?? null) || trim($parameters['credential_id']) === ''
            || $this->hasExpression($parameters['credential_id'])) {
            throw new InvalidArgumentException('MySQL: selecciona una credencial válida.');
        }
        $operation = array_key_exists('operation', $parameters) ? $parameters['operation'] : 'SELECT';
        if (!is_string($operation) || !in_array($operation, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], true)) {
            throw new InvalidArgumentException('MySQL: operación inválida; usa SELECT, INSERT, UPDATE o DELETE.');
        }
        $table = $this->quoteIdentifier($parameters['table'] ?? null);
        $values = $this->parseObject(array_key_exists('values', $parameters) ? $parameters['values'] : '', 'valores');
        $filters = $this->parseObject(array_key_exists('filters', $parameters) ? $parameters['filters'] : '', 'filtros');
        if (in_array($operation, ['INSERT', 'UPDATE'], true) && $values === []) {
            throw new InvalidArgumentException('MySQL: INSERT y UPDATE requieren un objeto de valores no vacío.');
        }
        if (in_array($operation, ['UPDATE', 'DELETE'], true) && $filters === []) {
            throw new InvalidArgumentException('MySQL: UPDATE y DELETE requieren filtros no vacíos.');
        }
        if ($operation === 'INSERT' && $filters !== []) {
            throw new InvalidArgumentException('MySQL: INSERT no admite filtros.');
        }
        $bindings = [];
        $whereParts = [];
        foreach ($filters as $column => $value) {
            // Igualdad segura también para null; todos los valores se enlazan.
            $whereParts[] = $this->quoteIdentifier($column) . ' <=> ?';
        }
        $where = $whereParts === [] ? '' : ' WHERE ' . implode(' AND ', $whereParts);

        if ($operation === 'SELECT') {
            $columns = $this->parseColumns(array_key_exists('columns', $parameters) ? $parameters['columns'] : '');
            $limit = array_key_exists('limit', $parameters) ? $parameters['limit'] : self::DEFAULT_LIMIT;
            if ((!is_int($limit) && !is_string($limit)) || preg_match('/^[1-9][0-9]{0,3}$/D', (string)$limit) !== 1
                || (int)$limit > self::MAX_LIMIT) {
                throw new InvalidArgumentException('MySQL: el límite SELECT debe ser un entero de 1 a 1000.');
            }
            $sql = 'SELECT ' . $columns . ' FROM ' . $table . $where . ' LIMIT ?';
        } elseif ($operation === 'INSERT') {
            $columns = array_map(fn($column): string => $this->quoteIdentifier($column), array_keys($values));
            $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')';
        } elseif ($operation === 'UPDATE') {
            $assignments = [];
            foreach ($values as $column => $value) {
                $assignments[] = $this->quoteIdentifier($column) . ' = ?';
            }
            $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $assignments) . $where;
        } else {
            $sql = 'DELETE FROM ' . $table . $where;
        }
        if (in_array($operation, ['INSERT', 'UPDATE'], true)) {
            foreach ($values as $value) {
                $bindings[] = $this->binding($value);
            }
        }
        foreach ($filters as $value) {
            $bindings[] = $this->binding($value);
        }
        if ($operation === 'SELECT') {
            $bindings[] = [(int)$limit, PDO::PARAM_INT];
        }
        return ['credential_id' => trim($parameters['credential_id']), 'operation' => $operation, 'sql' => $sql, 'bindings' => $bindings];
    }

    private function quoteIdentifier(mixed $identifier): string
    {
        if (!is_string($identifier) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('MySQL: identificador inválido; usa hasta 64 letras ASCII, dígitos o _, empezando por letra o _.');
        }
        return '`' . $identifier . '`';
    }

    private function parseObject(mixed $value, string $field): array
    {
        if (is_string($value)) {
            if (trim($value) === '') {
                return [];
            }
            try {
                $value = json_decode($value, false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (Throwable $e) {
                throw new InvalidArgumentException("MySQL: JSON inválido en {$field}.");
            }
            if (!$value instanceof stdClass) {
                throw new InvalidArgumentException("MySQL: {$field} debe ser un objeto JSON, no una lista ni un valor escalar.");
            }
        }
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("MySQL: {$field} debe ser un objeto por columnas.");
        }
        foreach ($value as $column => $item) {
            $this->quoteIdentifier($column);
            if ((!is_scalar($item) && $item !== null) || (is_float($item) && !is_finite($item))
                || (is_string($item) && (!mb_check_encoding($item, 'UTF-8') || $this->hasExpression($item)))) {
                throw new InvalidArgumentException("MySQL: {$field} solo admite valores escalares o null, con expresiones resueltas.");
            }
        }
        return $value;
    }

    private function parseColumns(mixed $columns): string
    {
        if (is_string($columns)) {
            $columns = trim($columns);
            if ($columns === '' || $columns === '*') {
                return '*';
            }
            if (str_starts_with($columns, '[')) {
                try {
                    $columns = json_decode($columns, false, 32, JSON_THROW_ON_ERROR);
                } catch (Throwable $e) {
                    throw new InvalidArgumentException('MySQL: lista JSON de columnas inválida.');
                }
            } else {
                $columns = array_map('trim', explode(',', $columns));
            }
        }
        if (!is_array($columns) || !array_is_list($columns) || $columns === []) {
            throw new InvalidArgumentException('MySQL: indica columnas separadas por comas o una lista JSON no vacía.');
        }
        return implode(', ', array_map(fn($column): string => $this->quoteIdentifier($column), $columns));
    }

    private function binding(mixed $value): array
    {
        return match (true) {
            $value === null => [null, PDO::PARAM_NULL],
            is_bool($value) => [$value, PDO::PARAM_BOOL],
            is_int($value) => [$value, PDO::PARAM_INT],
            is_float($value) => [json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), PDO::PARAM_STR],
            default => [$value, PDO::PARAM_STR]
        };
    }

    private function hasExpression(string $value): bool
    {
        return preg_match('/{{\s*[A-Za-z0-9_.]+\s*}}/', $value) === 1;
    }

    private function prepareConnection(string $json): array
    {
        try {
            $config = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('MySQL: la credencial contiene JSON inválido.');
        }
        if (!$config instanceof stdClass) {
            throw new InvalidArgumentException('MySQL: la configuración de credencial debe ser un objeto JSON.');
        }
        $config = get_object_vars($config);
        if (array_diff(array_keys($config), ['host', 'port', 'database', 'username', 'password', 'tls']) !== []) {
            throw new InvalidArgumentException('MySQL: la credencial contiene opciones no admitidas.');
        }
        foreach (['host', 'database', 'username', 'password'] as $field) {
            if (!is_string($config[$field] ?? null) || str_contains($config[$field], "\0")
                || ($field !== 'password' && trim($config[$field]) === '')) {
                throw new InvalidArgumentException('MySQL: host, database, username y password deben ser cadenas válidas.');
            }
        }
        $host = $config['host'];
        if (strlen($host) > 253 || strtolower(rtrim($host, '.')) === 'localhost'
            || (!filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            throw new InvalidArgumentException('MySQL: host inválido; usa un nombre DNS o una IP de conexión TCP, no localhost.');
        }
        $this->quoteIdentifier($config['database']);
        $port = $config['port'] ?? null;
        if ((!is_int($port) && !is_string($port)) || preg_match('/^[1-9][0-9]{0,4}$/D', (string)$port) !== 1 || (int)$port > 65535) {
            throw new InvalidArgumentException('MySQL: el puerto de la credencial debe ser un entero de 1 a 65535.');
        }
        $tls = array_key_exists('tls', $config) ? $config['tls'] : new stdClass();
        if (!$tls instanceof stdClass || array_diff(array_keys(get_object_vars($tls)), ['enabled', 'ca', 'cert', 'key']) !== []
            || (property_exists($tls, 'enabled') && !is_bool($tls->enabled))) {
            throw new InvalidArgumentException('MySQL: opciones TLS inválidas; se admiten enabled, ca, cert y key.');
        }
        $enabled = $tls->enabled ?? true;
        if (!$enabled && count(get_object_vars($tls)) > 1) {
            throw new InvalidArgumentException('MySQL: no combines TLS desactivado con archivos TLS.');
        }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('MySQL: la extensión pdo_mysql no está disponible.');
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_TIMEOUT => 10,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            PDO::MYSQL_ATTR_LOCAL_INFILE => false
        ];
        if ($enabled) {
            if (!defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                throw new RuntimeException('MySQL: este controlador PDO no permite garantizar la verificación TLS requerida.');
            }
            if (!property_exists($tls, 'ca') || property_exists($tls, 'cert') !== property_exists($tls, 'key')) {
                throw new InvalidArgumentException('MySQL: TLS requiere ca; cert y key deben proporcionarse juntos.');
            }
            foreach (['ca' => PDO::MYSQL_ATTR_SSL_CA, 'cert' => PDO::MYSQL_ATTR_SSL_CERT, 'key' => PDO::MYSQL_ATTR_SSL_KEY] as $field => $option) {
                if (property_exists($tls, $field)) {
                    $path = $tls->$field;
                    if (!is_string($path) || str_contains($path, "\0") || preg_match('/^(?:\/|[A-Za-z]:[\\\\\/])/', $path) !== 1
                        || !@is_file($path) || !@is_readable($path)) {
                        throw new InvalidArgumentException('MySQL: los archivos TLS deben existir, ser legibles y usar rutas absolutas locales.');
                    }
                    $options[$option] = $path;
                }
            }
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        return [
            'dsn' => 'mysql:host=' . $host . ';port=' . (int)$port . ';dbname=' . $config['database'] . ';charset=utf8mb4',
            'username' => $config['username'], 'password' => $config['password'], 'options' => $options, 'tls' => $enabled
        ];
    }

    private function safeError(Throwable $error, bool $connecting, string $operation): RuntimeException
    {
        // Clasificar códigos, nunca reutilizar mensajes, SQL, parámetros ni excepciones originales.
        $code = $error instanceof PDOException ? (int)($error->errorInfo[1] ?? 0) : 0;
        $reason = match (true) {
            in_array($code, [1045, 1698, 2061], true) => 'autenticación rechazada; revisa la cuenta y su método de autenticación',
            in_array($code, [1044, 1142, 1143, 1227], true) => 'permisos insuficientes para la base, tabla u operación',
            $code === 1049 => 'configuración inválida; la base de datos no existe',
            $code === 1146 => 'la tabla no existe',
            $code === 1054 => 'ejecución rechazada; una columna no existe',
            in_array($code, [2026, 3159], true) => 'conexión TLS rechazada; revisa certificados y requisitos del servidor',
            $connecting => 'no se pudo establecer o verificar la conexión externa y su configuración TLS',
            in_array($code, [2002, 2003, 2006, 2013], true) => 'la conexión externa falló o se interrumpió',
            default => 'falló la ejecución de la operación'
        };
        $notice = !$connecting && $operation !== 'SELECT'
            ? ' No se reintentó la escritura; comprueba su resultado en la base antes de repetirla.' : '';
        return new RuntimeException('MySQL: ' . $reason . '.' . $notice);
    }
}
