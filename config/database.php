<?php

use P8P\Core\EnvLoader;

EnvLoader::load(__DIR__ . '/../.env', false);

return (static function (): array {
    $env = static function (array $keys, string $default = ''): string {
        foreach ($keys as $key) {
            $value = $_ENV[$key] ?? getenv($key);
            if ($value !== false) {
                return (string) $value;
            }
        }

        return $default;
    };

    $config = [
        'host' => $env(['DB_HOST'], '127.0.0.1'),
        'port' => $env(['DB_PORT'], '3306'),
        'database' => $env(['DB_DATABASE', 'DB_NAME']),
        'username' => $env(['DB_USERNAME', 'DB_USER']),
        'password' => $env(['DB_PASSWORD', 'DB_PASS']),
        'charset' => 'utf8mb4',
    ];

    if (trim($config['database']) === '' || trim($config['username']) === '') {
        throw new RuntimeException('Configura DB_DATABASE y DB_USERNAME (o DB_NAME y DB_USER) en el entorno o en .env.');
    }

    return $config;
})();
