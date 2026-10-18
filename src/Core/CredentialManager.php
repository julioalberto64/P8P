<?php

namespace P8P\Core;

use P8P\Core\Database;
use Exception;

class CredentialManager
{
    private static function getSecretKey(): string
    {
        $secret = $_ENV['APP_SECRET'] ?? getenv('APP_SECRET');
        if (empty($secret)) {
            throw new Exception("APP_SECRET no definida en el archivo .env");
        }
        return hash('sha256', $secret, true);
    }

    /**
     * Encripta un arreglo de datos (ej. ['api_key' => 'sk-...'])
     */
    public static function encrypt(array $data): string
    {
        $key = self::getSecretKey();
        $cipher = "aes-256-gcm";
        $ivlen = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes($ivlen);

        $plaintext = json_encode($data);
        $ciphertext = openssl_encrypt($plaintext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Desencripta la credencial retenida en la base de datos
     */
    public static function decrypt(string $encryptedData): array
    {
        $key = self::getSecretKey();
        $cipher = "aes-256-gcm";
        $data = base64_decode($encryptedData);

        $ivlen = openssl_cipher_iv_length($cipher);
        $iv = substr($data, 0, $ivlen);
        $tag = substr($data, $ivlen, 16);
        $ciphertext = substr($data, $ivlen + 16);

        $plaintext = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plaintext === false) {
            throw new Exception("Fallo al desencriptar credencial. Clave inválida.");
        }

        return json_decode($plaintext, true) ?? [];
    }

    /**
     * Obtiene y desencripta una credencial por su ID
     */
    public static function getCredential(string $id): array
    {
        $config = require __DIR__ . '/../../config/database.php';
        $db = Database::getConnection($config);

        $stmt = $db->prepare("SELECT data FROM credentials WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new Exception("Credencial no encontrada: {$id}");
        }

        return self::decrypt($row['data']);
    }
}
