<?php

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

require_once __DIR__ . '/../vendor/autoload.php';

// Helper para leer variables del archivo .env directamente si getenv() no las detecta
function getEnvVar(string $key, string $default = ''): string {
    $val = getenv($key) ?: ($_ENV[$key] ?? null);
    if ($val !== null && $val !== false) {
        return trim($val);
    }

    $envPath = __DIR__ . '/../.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, '#') === 0) continue; // Ignorar comentarios

            if (strpos($line, '=') !== false) {
                list($envKey, $envVal) = explode('=', $line, 2);
                if (trim($envKey) === $key) {
                    return trim($envVal, " \t\n\r\0\x0B\"'");
                }
            }
        }
    }

    return $default;
}

try {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true) ?? $_POST;

    $username = trim($data['username'] ?? '');
    $password = trim($data['password'] ?? '');

    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'error' => 'Usuario y contraseña son requeridos']);
        exit;
    }

    // Cargar credenciales desde .env
    $envUser   = getEnvVar('ADMIN_USER', 'admin');
    $envPass   = getEnvVar('ADMIN_PASS', '');
    $secretKey = getEnvVar('ADMIN_SECRET_KEY', 'p8p_secret_key');

    // Comprobar coincidencia exacta de credenciales
    if ($username === $envUser && $password === $envPass) {
        // Generar un token único firmado con la clave secreta
        $token = hash_hmac('sha256', $username . '|' . time() . '|' . bin2hex(random_bytes(8)), $secretKey);

        echo json_encode([
            'success' => true,
            'token' => $token,
            'user' => [
                'username' => $username
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Credenciales de administrador inválidas'
        ]);
    }

} catch (\Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error al procesar la autenticación: ' . $e->getMessage()
    ]);
}
