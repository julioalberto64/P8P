<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class SlackNode implements NodeInterface
{
    private ?ClientInterface $client;
    private ?CredentialManager $credentials;

    public function __construct(?ClientInterface $client = null, ?CredentialManager $credentials = null)
    {
        // El catálogo instancia nodos sin conectarse a la BD ni a Slack.
        $this->client = $client;
        $this->credentials = $credentials;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'slack',
            'name' => 'Slack',
            'category' => 'Community',
            'description' => 'Envía un mensaje a un canal de Slack o responde a un hilo.',
            'icon' => 'Hash',
            'is_start' => false,
            'help_text' => 'Guarda el token de bot de Slack como credencial Bearer Token / API Key y selecciónala aquí. Requiere chat:write y acceso al canal. Usa {{node_id.campo}} en canal, texto o hilo. Para responder, indica el ts del mensaje padre; déjalo vacío para un mensaje nuevo.',
            'parameters' => [
                'credential_id' => [
                    'type' => 'credential',
                    'required' => true,
                    'label' => 'Credencial de Slack (bot)'
                ],
                'channel' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'ID del canal',
                    'placeholder' => 'C0123456789 o {{node_1.channel}}'
                ],
                'text' => [
                    'type' => 'textarea',
                    'required' => true,
                    'label' => 'Texto del mensaje',
                    'placeholder' => 'Mensaje o {{node_1.text}}'
                ],
                'thread_ts' => [
                    'type' => 'string',
                    'required' => false,
                    'label' => 'ID del hilo (ts del mensaje padre, opcional)',
                    'placeholder' => '1503435956.000247 o {{node_1.data.ts}}'
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        foreach (['credential_id', 'channel', 'text'] as $field) {
            if (!is_string($parameters[$field] ?? null) || trim($parameters[$field]) === '') {
                return false;
            }
        }

        $text = $parameters['text'];
        $thread = $parameters['thread_ts'] ?? '';

        return preg_match('/^[CG][A-Z0-9]+$/D', trim($parameters['channel'])) === 1
            && mb_check_encoding($text, 'UTF-8')
            && mb_strlen($text, 'UTF-8') <= 40000
            && is_string($thread)
            && (trim($thread) === '' || preg_match('/^\d+\.\d{6}$/D', trim($thread)) === 1);
    }

    public function execute(array $parameters, array $context): array
    {
        if (!$this->validate($parameters)) {
            throw new InvalidArgumentException('Slack: selecciona una credencial, un ID de canal válido (C/G), texto UTF-8 de 1 a 40000 caracteres y un thread_ts válido si corresponde.');
        }

        try {
            $credentials = $this->credentials ?? new CredentialManager();
            $credential = $credentials->getCredentialDecrypted(trim($parameters['credential_id']));
        } catch (\Throwable $e) {
            // No propagar errores de BD/cifrado ni adjuntar excepciones con datos sensibles.
            throw new RuntimeException('Slack: no se pudo recuperar la credencial.');
        }

        $token = $credential['data']['api_key'] ?? null;
        if (!in_array($credential['type'] ?? null, ['generic_bearer', 'slack_bot'], true) || !is_string($token)
            || preg_match('/^xoxb-[A-Za-z0-9-]+$/D', trim($token)) !== 1) {
            throw new InvalidArgumentException('Slack: selecciona una credencial Bearer Token / API Key con un token de bot válido.');
        }

        $payload = [
            'channel' => trim($parameters['channel']),
            'text' => $parameters['text']
        ];
        $thread = trim($parameters['thread_ts'] ?? '');
        if ($thread !== '') {
            $payload['thread_ts'] = $thread;
        }

        try {
            $client = $this->client ?? new Client();
            $response = $client->request('POST', 'https://slack.com/api/chat.postMessage', [
                'headers' => ['Authorization' => 'Bearer ' . trim($token)],
                'json' => $payload,
                'timeout' => 30,
                'connect_timeout' => 10,
                'verify' => true,
                'allow_redirects' => false,
                'http_errors' => false
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Slack: fallo de conexión. El envío puede haber ocurrido; comprueba el canal antes de reintentar.');
        }

        $status = $response->getStatusCode();
        $body = json_decode((string)$response->getBody(), true);
        $error = is_array($body) ? ($body['error'] ?? null) : null;

        if ($status === 429 || in_array($error, ['ratelimited', 'rate_limited'], true)) {
            $retryAfter = trim($response->getHeaderLine('Retry-After'));
            $wait = ctype_digit($retryAfter) ? " Espera {$retryAfter} segundos antes de reintentar." : '';
            throw new RuntimeException('Slack: límite de solicitudes alcanzado (ratelimited).' . $wait);
        }

        // Solo publicar mensajes conocidos: ni cuerpos remotos ni mensajes de Guzzle.
        $errors = [
            'invalid_auth' => 'token inválido o acceso por IP no permitido',
            'not_authed' => 'falta autenticación',
            'token_revoked' => 'token revocado',
            'token_expired' => 'token expirado',
            'account_inactive' => 'cuenta o workspace inactivo',
            'missing_scope' => 'falta el permiso chat:write; revisa los scopes y reinstala la app',
            'no_permission' => 'el bot no tiene permiso para publicar',
            'not_in_channel' => 'invita al bot al canal o revisa chat:write.public para canales públicos',
            'channel_not_found' => 'canal inexistente o inaccesible para el bot',
            'is_archived' => 'el canal está archivado',
            'restricted_action' => 'el workspace restringe esta operación',
            'restricted_action_read_only_channel' => 'el canal es de solo lectura',
            'thread_not_found' => 'el mensaje padre del hilo no existe',
            'invalid_thread_ts' => 'identificador de hilo inválido',
            'cannot_reply_to_message' => 'el mensaje no admite respuestas',
            'msg_too_long' => 'el texto supera el límite de Slack',
            'no_text' => 'falta el texto del mensaje'
        ];
        if (is_string($error) && isset($errors[$error])) {
            throw new RuntimeException("Slack: {$errors[$error]} ({$error}).");
        }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) {
                401 => 'autenticación rechazada',
                403 => 'permisos insuficientes',
                404 => 'recurso no encontrado',
                default => 'falló la solicitud; comprueba el canal antes de reintentar'
            };
            throw new RuntimeException("Slack: {$reason} (HTTP {$status}).");
        }
        if (!is_array($body) || ($body['ok'] ?? null) !== true) {
            throw new RuntimeException('Slack: respuesta inválida o envío rechazado. Comprueba el canal antes de reintentar.');
        }

        // Lista permitida de salida: no devolver message, headers ni la respuesta sin filtrar.
        $channel = $body['channel'] ?? null;
        $ts = $body['ts'] ?? null;
        if (!is_string($channel) || preg_match('/^[CG][A-Z0-9]+$/D', $channel) !== 1
            || !is_string($ts) || preg_match('/^\d+\.\d{6}$/D', $ts) !== 1) {
            throw new RuntimeException('Slack: respuesta sin canal o identificador de mensaje válido. Comprueba el canal antes de reintentar.');
        }

        return [
            'success' => true,
            'status_code' => $status,
            'data' => ['channel' => $channel, 'ts' => $ts]
        ];
    }
}
