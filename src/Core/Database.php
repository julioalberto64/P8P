<?php

namespace P8P\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(array $config = []): PDO
    {
        if (self::$instance === null) {
            $host = $config['host'] ?? '127.0.0.1';
            $port = $config['port'] ?? '3306';
            $db   = $config['database'] ?? 'p8p_db';
            $user = $config['username'] ?? 'root';
            $pass = $config['password'] ?? '';
            $charset = 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                throw new PDOException("Error de conexión a MariaDB: " . $e->getMessage(), (int)$e->getCode());
            }
        }

        return self::$instance;
    }
}
