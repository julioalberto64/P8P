<?php

namespace P8P\Nodes\Core;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use P8P\Core\CredentialManager;
use P8P\Core\ExpressionEvaluator;
use P8P\Interfaces\NodeInterface;
use RuntimeException;
use stdClass;
use Throwable;

class PostgreSqlNode implements NodeInterface
{
    public function __construct(private ?Closure $connectionFactory = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return [
            'evaluates_expressions' => true,
            'type' => 'postgresql', 'name' => 'PostgreSQL', 'category' => 'Core', 'icon' => 'Database', 'is_start' => false,
            'description' => 'Consultas parametrizadas y transacciones sobre una conexión PostgreSQL externa independiente.',
            'literal_parameters' => ['query', 'operations'],
            'help_text' => 'Credencial generic_bearer, API Key JSON: {"host":"db.example.com","port":5432,"database":"app","username":"workflow","password":"...","pool_mode":"direct","sslmode":"verify-full","sslrootcert":"/ruta/ca.pem"}. Solo verify-full; CA administrada por servidor, opcional si libpq ya tiene root.crt. pool_mode: direct/session/transaction (este último usa ejecución sin prepared persistentes). Concede CONNECT, USAGE de esquema y solo SELECT/INSERT/UPDATE/DELETE necesarios, secuencias si aplica; sin superusuario, CREATE ni funciones privilegiadas. SQL es código de confianza, no sandbox: solo una sentencia DML, sin DDL/CALL, comentarios ni dollar quoting. Nunca uses {{expresiones}} en SQL: usa ? y bindings separados. INSERT/UPDATE/DELETE declarativos admiten returning, UPDATE/DELETE exigen filters. transaction admite hasta 50 operaciones, todas en una conexión y commit conjunto; cualquier fallo hace rollback. Hasta 1000 filas y 4 MiB de salida. Decimales, bigint, JSON y fechas se devuelven como texto; booleanos y null conservan tipo. Envía decimales/bigint como cadenas; arrays/objetos como JSON, fechas ISO como cadenas sin conversión horaria. Sin triggers ni reintentos automáticos.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial PostgreSQL externa'],
                'operation' => ['type' => 'select', 'required' => true, 'default' => 'select', 'label' => 'Operación',
                    'options' => array_map(static fn($v) => ['value' => $v, 'label' => $v], ['select', 'insert', 'update', 'delete', 'query', 'transaction'])],
                'table' => ['type' => 'string', 'required' => false, 'label' => 'Tabla (schema.tabla o tabla)'],
                'columns' => ['type' => 'textarea', 'required' => false, 'label' => 'Columnas (lista JSON, por defecto ["*"])'],
                'values' => ['type' => 'textarea', 'required' => false, 'label' => 'Valores por columna (objeto JSON)'],
                'filters' => ['type' => 'textarea', 'required' => false, 'label' => 'Filtros AND de igualdad (objeto JSON, null usa igualdad segura para NULL)'],
                'returning' => ['type' => 'textarea', 'required' => false, 'label' => 'RETURNING (lista JSON de columnas)'],
                'limit' => ['type' => 'number', 'required' => false, 'default' => 100, 'label' => 'Máximo de filas (1–1000)'],
                'offset' => ['type' => 'number', 'required' => false, 'default' => 0, 'label' => 'Offset SELECT (0–1000000)'],
                'query' => ['type' => 'textarea', 'required' => false, 'label' => 'SQL literal (sin expresiones, placeholders ?)'],
                'bindings' => ['type' => 'textarea', 'required' => false, 'label' => 'Parámetros SQL (lista JSON; admite expresiones)'],
                'operations' => ['type' => 'textarea', 'required' => false, 'label' => 'Transacción: lista JSON de operaciones (mismos campos, SQL literal)']
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->plan($parameters, []);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        $plan = $this->plan($parameters, $context);
        $config = $this->credential($plan['credential_id']);
        $pdo = null;
        try {
            // Never use Database::getConnection(): this connection is exclusively external.
            $pdo = $this->connect($config);
            if ($pdo->inTransaction() || !$pdo->beginTransaction()) {
                throw new RuntimeException();
            }
            $pdo->exec("SET LOCAL statement_timeout = '30s'");
            $pdo->exec("SET LOCAL lock_timeout = '5s'");
            $results = [];
            $bytes = 0;
            foreach ($plan['queries'] as $query) {
                $statement = $pdo->prepare($query['sql']);
                if ($statement === false) {
                    throw new RuntimeException();
                }
                try {
                    foreach ($query['bindings'] as $index => $binding) {
                        if (!$statement->bindValue($index + 1, $binding[0], $binding[1])) {
                            throw new RuntimeException();
                        }
                    }
                    if (!$statement->execute()) {
                        throw new RuntimeException();
                    }
                    $rows = [];
                    $types = [];
                    for ($i = 0; $i < $statement->columnCount(); $i++) {
                        $meta = $statement->getColumnMeta($i);
                        if (is_array($meta)) {
                            $types[$meta['name']] = $meta['native_type'] ?? '';
                        }
                    }
                    while ($statement->columnCount() > 0 && ($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                        if (count($rows) >= $query['max_rows']) {
                            throw new RuntimeException('Output limit');
                        }
                        foreach ($row as $field => &$value) {
                            if (is_resource($value)) {
                                throw new RuntimeException('Binary output unsupported');
                            }
                            if (($types[$field] ?? '') === 'bool' && $value !== null) {
                                $value = in_array($value, [true, 't', 'true', '1', 1], true);
                            } elseif (is_int($value) || is_float($value)) {
                                $value = (string)$value;
                            }
                        }
                        unset($value);
                        $bytes += strlen(json_encode($row, JSON_THROW_ON_ERROR));
                        if ($bytes > 4194304) {
                            throw new RuntimeException('Output limit');
                        }
                        $rows[] = $row;
                    }
                    $results[] = ['operation' => $query['operation'], 'rows' => $rows, 'count' => count($rows),
                        'affected_rows' => $statement->rowCount()];
                } finally {
                    $statement->closeCursor();
                }
            }
            if (!$pdo->commit()) {
                throw new RuntimeException();
            }
            $data = $plan['transaction'] ? ['results' => $results, 'committed' => true] : $results[0];
            return ['success' => true, 'data' => $data];
        } catch (Throwable $e) {
            if ($pdo !== null) {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (Throwable $ignored) {
                }
            }
            $state = $e instanceof PDOException ? (string)($e->errorInfo[0] ?? $e->getCode()) : '';
            $reason = match (true) {
                str_starts_with($state, '28') => 'autenticación rechazada',
                $state === '42501' => 'permisos insuficientes',
                $state === '57014' => 'se superó el tiempo de ejecución',
                str_starts_with($state, '23') => 'restricción de datos rechazada',
                default => 'fallo de conexión, TLS, consulta o límite de recursos'
            };
            throw new RuntimeException('PostgreSQL: ' . $reason . '. Se solicitó rollback cuando fue posible; sin reintento. Si se perdió la conexión durante commit, verifica el resultado antes de repetir.');
        }
    }

    private function plan(array $parameters, array $context): array
    {
        try {
            if (strlen(json_encode($parameters, JSON_THROW_ON_ERROR)) > 4194304) {
                throw new InvalidArgumentException('PostgreSQL: entrada mayor de 4 MiB.');
            }
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('PostgreSQL: entrada no representable como JSON.');
        }
        $literal = array_intersect_key($parameters, array_flip(['query', 'operations']));
        $parameters = $this->resolveData(array_diff_key($parameters, $literal), $context) + $literal;
        $id = $parameters['credential_id'] ?? null;
        if (!is_string($id) || trim($id) === '' || strlen($id) > 255 || str_contains($id, '{{')) {
            throw new InvalidArgumentException('PostgreSQL: selecciona una credencial válida.');
        }
        $transaction = ($parameters['operation'] ?? 'select') === 'transaction';
        $queries = [];
        if ($transaction) {
            $operations = $this->json($parameters['operations'] ?? null);
            if (!is_array($operations) || !array_is_list($operations) || $operations === [] || count($operations) > 50) {
                throw new InvalidArgumentException('PostgreSQL: la transacción requiere entre 1 y 50 operaciones.');
            }
            foreach ($operations as $operation) {
                if ($operation instanceof stdClass) {
                    $operation = get_object_vars($operation);
                }
                if (!is_array($operation) || array_is_list($operation) || isset($operation['credential_id'])
                    || ($operation['operation'] ?? '') === 'transaction' || array_key_exists('sql', $operation)) {
                    throw new InvalidArgumentException('PostgreSQL: operación de transacción inválida; usa query para SQL literal.');
                }
                $raw = array_intersect_key($operation, ['query' => true]);
                $operation = $this->resolveData(array_diff_key($operation, $raw), $context) + $raw;
                $queries[] = $this->query($operation);
            }
        } else {
            $queries[] = $this->query($parameters);
        }
        return ['credential_id' => $id, 'transaction' => $transaction, 'queries' => $queries];
    }

    private function resolveData(mixed $value, array $context): mixed
    {
        if ($value instanceof stdClass) {
            $resolved = new stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $resolved->$key = $this->resolveData($item, $context);
            }
            return $resolved;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->resolveData($item, $context);
            }
            return $value;
        }
        return ExpressionEvaluator::evaluate($value, $context);
    }

    private function query(array $parameters): array
    {
        $operation = $parameters['operation'] ?? 'select';
        $limit = $this->integer($parameters['limit'] ?? 100, 1, 1000);
        $bindings = [];
        if ($operation === 'query') {
            $sql = $parameters['query'] ?? null;
            $raw = $this->json(($parameters['bindings'] ?? '') === '' ? [] : $parameters['bindings']);
            if (!is_array($raw) || !array_is_list($raw) || count($raw) > 1000) {
                throw new InvalidArgumentException('PostgreSQL: bindings debe ser una lista con hasta 1000 valores.');
            }
            $placeholderCount = $this->literalSql($sql);
            if ($placeholderCount !== count($raw)) {
                throw new InvalidArgumentException('PostgreSQL: cada placeholder ? requiere un binding separado.');
            }
            $bindings = array_map(fn($v) => $this->binding($v), $raw);
            // Raw SQL is trusted workflow code, not an authorization boundary. Limit SELECT at the server.
            if (preg_match('/^\s*SELECT\b/i', $sql)) {
                $sql = 'SELECT * FROM (' . $sql . ') AS p8p_result LIMIT ?';
                $bindings[] = [$limit, PDO::PARAM_INT];
            }
        } elseif (in_array($operation, ['select', 'insert', 'update', 'delete'], true)) {
            $table = $this->identifier($parameters['table'] ?? null, true);
            $values = $this->object($parameters['values'] ?? []);
            $filters = $this->object($parameters['filters'] ?? []);
            if (in_array($operation, ['insert', 'update'], true) && $values === []) {
                throw new InvalidArgumentException('PostgreSQL: INSERT/UPDATE requieren values no vacío.');
            }
            if (in_array($operation, ['update', 'delete'], true) && $filters === []) {
                throw new InvalidArgumentException('PostgreSQL: UPDATE/DELETE requieren filters explícitos no vacíos.');
            }
            if ($operation === 'insert' && $filters !== []) {
                throw new InvalidArgumentException('PostgreSQL: INSERT no admite filters.');
            }
            $where = [];
            foreach ($filters as $column => $value) {
                $where[] = $this->identifier($column) . ' IS NOT DISTINCT FROM ?';
            }
            $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
            if ($operation === 'select') {
                $sql = 'SELECT ' . $this->columns(($parameters['columns'] ?? '') === '' ? ['*'] : $parameters['columns'], true) . ' FROM ' . $table . $whereSql . ' LIMIT ? OFFSET ?';
            } elseif ($operation === 'insert') {
                $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', array_map(fn($c) => $this->identifier($c), array_keys($values)))
                    . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')';
            } elseif ($operation === 'update') {
                $assignments = array_map(fn($c) => $this->identifier($c) . ' = ?', array_keys($values));
                $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $assignments) . $whereSql;
            } else {
                $sql = 'DELETE FROM ' . $table . $whereSql;
            }
            if (in_array($operation, ['insert', 'update'], true)) {
                foreach ($values as $value) {
                    $bindings[] = $this->binding($value);
                }
            }
            foreach ($filters as $value) {
                $bindings[] = $this->binding($value);
            }
            if ($operation === 'select') {
                $bindings[] = [$limit, PDO::PARAM_INT];
                $bindings[] = [$this->integer($parameters['offset'] ?? 0, 0, 1000000), PDO::PARAM_INT];
            } elseif (isset($parameters['returning']) && $parameters['returning'] !== '' && $parameters['returning'] !== []) {
                $sql .= ' RETURNING ' . $this->columns($parameters['returning'], true);
            }
        } else {
            throw new InvalidArgumentException('PostgreSQL: operación no admitida.');
        }
        return ['operation' => $operation, 'sql' => $sql, 'bindings' => $bindings, 'max_rows' => $limit];
    }

    private function literalSql(mixed $sql): int
    {
        if (!is_string($sql) || strlen($sql) > 65536 || !mb_check_encoding($sql, 'UTF-8')
            || str_contains($sql, '{{') || str_contains($sql, "\0") || !preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $sql)) {
            throw new InvalidArgumentException('PostgreSQL: query requiere una sentencia literal SELECT/INSERT/UPDATE/DELETE; expresiones solo en bindings.');
        }
        $words = '';
        $placeholders = 0;
        $quote = null;
        for ($i = 0, $length = strlen($sql); $i < $length; $i++) {
            $c = $sql[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    if (($sql[$i + 1] ?? '') === $quote) {
                        $i++;
                    } else {
                        $quote = null;
                    }
                } elseif ($c === '\\') {
                    throw new InvalidArgumentException('PostgreSQL: usa bindings en lugar de escapes en literales SQL.');
                }
                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c;
                $words .= ' ';
            } elseif ($c === ';' || $c === '$' || ($c === '-' && ($sql[$i + 1] ?? '') === '-')
                || ($c === '/' && ($sql[$i + 1] ?? '') === '*')) {
                throw new InvalidArgumentException('PostgreSQL: no se admiten varias sentencias, comentarios ni dollar quoting.');
            } elseif ($c === '?') {
                $placeholders++;
                $words .= ' ';
            } else {
                $words .= $c;
            }
        }
        if ($quote !== null || preg_match('/\b(CREATE|ALTER|DROP|TRUNCATE|GRANT|REVOKE|COPY|CALL|DO|VACUUM|ANALYZE|RESET|BEGIN|COMMIT|ROLLBACK|SAVEPOINT|PREPARE|EXECUTE|INTO\s+(?:TEMP|TEMPORARY|UNLOGGED|TABLE))\b/i', $words)
            || (preg_match('/^\s*SELECT\b/i', $sql) && preg_match('/\bINTO\b/i', $words))) {
            throw new InvalidArgumentException('PostgreSQL: SQL fuera del contrato DML; no se admite administración ni procedimientos.');
        }
        return $placeholders;
    }

    private function identifier(mixed $value, bool $qualified = false): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('PostgreSQL: identificador inválido.');
        }
        $parts = $qualified ? explode('.', $value) : [$value];
        if (count($parts) > 2) {
            throw new InvalidArgumentException('PostgreSQL: usa tabla o esquema.tabla.');
        }
        foreach ($parts as &$part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $part) !== 1) {
                throw new InvalidArgumentException('PostgreSQL: identificadores ASCII de hasta 63 caracteres, sin SQL.');
            }
            $part = '"' . $part . '"';
        }
        return implode('.', $parts);
    }

    private function columns(mixed $columns, bool $star): string
    {
        $columns = $this->json($columns);
        if (!is_array($columns) || !array_is_list($columns) || $columns === [] || count($columns) > 200) {
            throw new InvalidArgumentException('PostgreSQL: columnas requiere una lista JSON no vacía (máximo 200).');
        }
        if ($star && $columns === ['*']) {
            return '*';
        }
        return implode(', ', array_map(fn($c) => $this->identifier($c), $columns));
    }

    private function json(mixed $value): mixed
    {
        if (is_string($value)) {
            if (strlen($value) > 2097152) {
                throw new InvalidArgumentException('PostgreSQL: JSON mayor de 2 MiB.');
            }
            try {
                return json_decode($value, false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('PostgreSQL: JSON inválido; no se imprimen valores.');
            }
        }
        return $value;
    }

    private function object(mixed $value): array
    {
        if ($value === '') {
            return [];
        }
        $value = $this->json($value);
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > 200) {
            throw new InvalidArgumentException('PostgreSQL: values y filters requieren objetos por columna (máximo 200).');
        }
        foreach ($value as $column => $item) {
            $this->identifier($column);
            $this->binding($item);
        }
        return $value;
    }

    private function binding(mixed $value): array
    {
        if (is_array($value) || $value instanceof stdClass) {
            try {
                $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
            } catch (Throwable $e) {
                throw new InvalidArgumentException('PostgreSQL: valor JSON no representable.');
            }
        }
        if (is_string($value) && (strlen($value) > 1048576 || str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8'))
            || is_float($value) && !is_finite($value) || !is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('PostgreSQL: valor no admitido o mayor de 1 MiB.');
        }
        return match (true) {
            $value === null => [null, PDO::PARAM_NULL],
            is_bool($value) => [$value, PDO::PARAM_BOOL],
            is_int($value) => [$value, PDO::PARAM_INT],
            is_float($value) => [json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), PDO::PARAM_STR],
            default => [$value, PDO::PARAM_STR]
        };
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if ((!is_string($value) && !is_int($value)) || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int)$value < $min || (int)$value > $max) {
            throw new InvalidArgumentException('PostgreSQL: límite u offset fuera de rango.');
        }
        return (int)$value;
    }

    private function credential(string $id): array
    {
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (Throwable $e) {
            throw new RuntimeException('PostgreSQL: no se pudo recuperar o descifrar la credencial almacenada.');
        }
        $raw = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? '') !== 'generic_bearer' || !is_string($raw)) {
            throw new InvalidArgumentException('PostgreSQL: requiere generic_bearer con configuración JSON en API Key.');
        }
        $config = $this->json($raw);
        if (!$config instanceof stdClass) {
            throw new InvalidArgumentException('PostgreSQL: la credencial debe ser un objeto JSON.');
        }
        $config = get_object_vars($config);
        if (array_diff(array_keys($config), ['host', 'port', 'database', 'username', 'password', 'pool_mode', 'sslmode', 'sslrootcert'])) {
            throw new InvalidArgumentException('PostgreSQL: opciones de conexión no admitidas.');
        }
        foreach (['host', 'database', 'username', 'password'] as $field) {
            if (!is_string($config[$field] ?? null) || str_contains($config[$field], "\0") || strlen($config[$field]) > 4096
                || ($field !== 'password' && $config[$field] === '')) {
                throw new InvalidArgumentException('PostgreSQL: configuración de conexión incompleta.');
            }
        }
        if (strlen($config['host']) > 253 || (!filter_var($config['host'], FILTER_VALIDATE_IP)
            && !filter_var($config['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))
            || preg_match('/^[A-Za-z0-9_.-]{1,128}$/D', $config['database']) !== 1) {
            throw new InvalidArgumentException('PostgreSQL: host o base de datos inválidos.');
        }
        $config['port'] = $this->integer($config['port'] ?? 5432, 1, 65535);
        $config['pool_mode'] = $config['pool_mode'] ?? 'direct';
        $config['sslmode'] = $config['sslmode'] ?? 'verify-full';
        if (!in_array($config['pool_mode'], ['direct', 'session', 'transaction'], true) || $config['sslmode'] !== 'verify-full') {
            throw new InvalidArgumentException('PostgreSQL: pool_mode inválido o TLS sin verificación completa.');
        }
        if (array_key_exists('sslrootcert', $config)) {
            $path = $config['sslrootcert'];
            if (!is_string($path) || !str_starts_with($path, '/') || preg_match('/[\x00-\x20\x7f;\'\\\\]/', $path)
                || !is_file($path) || !is_readable($path)) {
                throw new InvalidArgumentException('PostgreSQL: CA debe ser un archivo absoluto legible administrado en el servidor.');
            }
        }
        return $config;
    }

    protected function connect(array $config): PDO
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('PostgreSQL: requiere extensión pdo_pgsql.');
        }
        $dsn = 'pgsql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database']
            . ';sslmode=verify-full;connect_timeout=10;application_name=p8p;options=--client_encoding=UTF8';
        if (isset($config['sslrootcert'])) {
            $dsn .= ';sslrootcert=' . $config['sslrootcert'];
        }
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_PERSISTENT => false, PDO::ATTR_STRINGIFY_FETCHES => true];
        if ($config['pool_mode'] === 'transaction') {
            if (!defined('PDO::PGSQL_ATTR_DISABLE_PREPARES')) {
                throw new RuntimeException('PostgreSQL: controlador sin ejecución compatible con pooler de transacciones.');
            }
            $options[PDO::PGSQL_ATTR_DISABLE_PREPARES] = true;
        }
        return $this->connectionFactory !== null ? ($this->connectionFactory)($dsn, $config['username'], $config['password'], $options)
            : new PDO($dsn, $config['username'], $config['password'], $options);
    }
}
