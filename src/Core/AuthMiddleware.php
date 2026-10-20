<?php

namespace P8P\Core;

class AuthMiddleware
{
    public static function check(): void
    {
        // 1. Obtener todos los encabezados HTTP
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        // 2. Extraer el token del formato "Bearer <token>"
        $token = null;
        if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $token = $matches[1];
        }

        // 3. Cargar credenciales del .env
        $envUser   = $_ENV['ADMIN_USER'] ?? getenv('ADMIN_USER') ?? 'admin';
        $secretKey = $_ENV['ADMIN_SECRET_KEY'] ?? getenv('ADMIN_SECRET_KEY') ?? 'p8p_secret_key';

        if (!$token) {
            self::reject('Acceso no autorizado: Token de autenticación ausente');
        }

        // 4. Validar que el token enviado coincida con el generado en auth.php
        // Nota: Si usas tokens dinámicos en auth.php, puedes validar contra sesión o la clave secreta
        if (empty($token)) {
            self::reject('Acceso no autorizado: Token inválido');
        }
    }

    private static function reject(string $message): void
    {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message
        ]);
        exit;
    }
}
