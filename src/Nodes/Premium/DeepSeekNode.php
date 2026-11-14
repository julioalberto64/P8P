<?php

namespace P8P\Nodes\Premium;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Core\ExpressionEvaluator;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class DeepSeekNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return [
            'evaluates_expressions' => true,
            'type' => 'deepseek_model', 'name' => 'DeepSeek AI Model', 'category' => 'Premium',
            'description' => 'Proveedor DeepSeek Chat Completions para el puerto Model del AI Agent.',
            'icon' => 'Sparkles', 'is_start' => false,
            'help_text' => 'Conecta Model al AI Agent. Credencial generic_bearer: API Key o JSON {"api_key":"..."}. Modelos vigentes deepseek-flash y deepseek-v4-pro, sujetos a permisos de cuenta. Thinking se configura separado del modelo; solo se publica la respuesta final, nunca reasoning_content. Texto UTF-8 hasta 1 MiB (128 KiB en el agente). Sin herramientas porque el agente aún no las ejecuta; sin archivos, streaming ni reintentos automáticos. La clave se renueva manualmente en credenciales.',
            'parameters' => [
                'credential_id' => ['type' => 'credential', 'required' => true, 'label' => 'Credencial de DeepSeek'],
                'model' => ['type' => 'string', 'required' => true, 'label' => 'Modelo habilitado', 'placeholder' => 'deepseek-flash'],
                'max_tokens' => ['type' => 'number', 'required' => false, 'default' => 4096, 'label' => 'Límite local de salida (1–16384 tokens, incluye razonamiento)'],
                'thinking' => ['type' => 'select', 'required' => false, 'default' => 'disabled', 'label' => 'Modo de razonamiento',
                    'options' => [['value' => 'disabled', 'label' => 'Conversación'], ['value' => 'enabled', 'label' => 'Razonamiento']]],
                'reasoning_effort' => ['type' => 'select', 'required' => false, 'default' => 'high', 'label' => 'Esfuerzo (solo Thinking habilitado)',
                    'options' => array_map(static fn($v) => ['value' => $v, 'label' => $v], ['low', 'high', 'max'])]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->configuration($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        $config = $this->configuration(ExpressionEvaluator::evaluate($parameters, $context));
        return ['type' => 'chat_model', 'provider' => 'deepseek', 'credential_id' => $config['credential_id'],
            'model' => $config['model'], 'options' => array_intersect_key($config, array_flip(['max_tokens', 'thinking', 'reasoning_effort']))];
    }

    public function generate(array $configuration, array $messages): array
    {
        $config = $this->configuration($configuration);
        $this->validateMessages($messages);
        $key = $this->apiKey($config['credential_id']);
        $payload = ['model' => $config['model'], 'messages' => $messages, 'stream' => false,
            'max_tokens' => $config['max_tokens'], 'thinking' => ['type' => $config['thinking']]];
        if ($config['thinking'] === 'enabled') {
            $payload['reasoning_effort'] = $config['reasoning_effort'];
        }
        $body = $this->request($payload, $key);
        $choice = $body['choices'][0] ?? null;
        if (isset($body['error']) || ($body['object'] ?? null) !== 'chat.completion'
            || !is_array($body['choices'] ?? null) || count($body['choices']) !== 1 || !is_array($choice)
            || ($choice['finish_reason'] ?? '') !== 'stop' || ($choice['message']['role'] ?? '') !== 'assistant'
            || !is_string($choice['message']['content'] ?? null) || trim($choice['message']['content']) === ''
            || !mb_check_encoding($choice['message']['content'], 'UTF-8') || !empty($choice['message']['tool_calls'])
            || !empty($choice['message']['function_call']) || !empty($choice['message']['refusal'])) {
            throw new RuntimeException('DeepSeek: respuesta fallida, incompleta, rechazada o pendiente de herramientas; sin reintento automático.');
        }
        // Select final text explicitly; never return raw message or reasoning_content.
        $text = str_replace($key, '[redacted]', $choice['message']['content']);
        $usage = [];
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens', 'prompt_cache_hit_tokens', 'prompt_cache_miss_tokens'] as $field) {
            if (is_int($body['usage'][$field] ?? null) && $body['usage'][$field] >= 0) {
                $usage[$field] = $body['usage'][$field];
            }
        }
        if (is_int($body['usage']['completion_tokens_details']['reasoning_tokens'] ?? null)
            && $body['usage']['completion_tokens_details']['reasoning_tokens'] >= 0) {
            $usage['reasoning_tokens'] = $body['usage']['completion_tokens_details']['reasoning_tokens'];
        }
        return ['success' => true, 'status_code' => 200, 'data' => ['text' => $text], 'provider' => 'deepseek',
            'model_used' => $config['model'], 'output_text' => $text, 'usage' => $usage, 'finish_reason' => 'stop'];
    }

    private function configuration(array $parameters): array
    {
        if (($parameters['_sub_connections'] ?? null) === []) {
            unset($parameters['_sub_connections']);
        }
        $options = $parameters['options'] ?? [];
        if (!is_array($options) || array_diff(array_keys($parameters), ['type', 'provider', 'credential_id', 'model', 'max_tokens', 'thinking', 'reasoning_effort', 'options'])
            || array_diff(array_keys($options), ['max_tokens', 'thinking', 'reasoning_effort'])
            || (isset($parameters['provider']) && $parameters['provider'] !== 'deepseek')) {
            throw new InvalidArgumentException('DeepSeek: configuración incompatible; no se admiten secretos directos, herramientas ni opciones arbitrarias.');
        }
        $config = array_merge($parameters, $options);
        $id = $config['credential_id'] ?? null;
        $model = $config['model'] ?? null;
        $tokens = $config['max_tokens'] ?? 4096;
        $thinking = $config['thinking'] ?? 'disabled';
        $effort = $config['reasoning_effort'] ?? 'high';
        // Capabilities are verified for these models. New IDs require reviewing their API contract.
        if (!is_string($id) || trim($id) === '' || strlen($id) > 255 || str_contains($id, '{{')
            || !in_array($model, ['deepseek-flash', 'deepseek-v4-pro'], true)
            || (!is_string($tokens) && !is_int($tokens)) || filter_var($tokens, FILTER_VALIDATE_INT) === false
            || (int)$tokens < 1 || (int)$tokens > 16384 || !in_array($thinking, ['enabled', 'disabled'], true)
            || !in_array($effort, ['low', 'high', 'max'], true)) {
            throw new InvalidArgumentException('DeepSeek: credencial, modelo, tokens o modo de razonamiento inválidos.');
        }
        return ['credential_id' => $id, 'model' => $model, 'max_tokens' => (int)$tokens,
            'thinking' => $thinking, 'reasoning_effort' => $effort];
    }

    private function validateMessages(array $messages): void
    {
        if (!array_is_list($messages) || $messages === [] || count($messages) > 100) {
            throw new InvalidArgumentException('DeepSeek: se requieren entre 1 y 100 mensajes.');
        }
        $size = 0;
        $hasUser = false;
        foreach ($messages as $message) {
            if (!is_array($message) || array_diff(array_keys($message), ['role', 'content'])
                || !in_array($message['role'] ?? null, ['system', 'user', 'assistant'], true)
                || !is_string($message['content'] ?? null) || $message['content'] === ''
                || !mb_check_encoding($message['content'], 'UTF-8')) {
                throw new InvalidArgumentException('DeepSeek: solo mensajes de texto UTF-8 system/user/assistant, sin razonamiento interno.');
            }
            $hasUser = $hasUser || $message['role'] === 'user';
            $size += strlen($message['content']);
        }
        if (!$hasUser || $size > 1048576) {
            throw new InvalidArgumentException('DeepSeek: falta un mensaje user o se supera 1 MiB.');
        }
    }

    private function apiKey(string $id): string
    {
        try {
            $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id);
        } catch (\Throwable $e) {
            throw new RuntimeException('DeepSeek: no se pudo recuperar o descifrar la credencial.');
        }
        $key = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? '') !== 'generic_bearer' || !is_string($key)) {
            throw new InvalidArgumentException('DeepSeek: requiere credencial generic_bearer con api_key.');
        }
        if (str_starts_with(ltrim($key), '{')) {
            $config = json_decode($key, true, 16);
            $key = is_array($config) ? ($config['api_key'] ?? null) : null;
        }
        if (!is_string($key) || strlen($key) < 8 || strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new InvalidArgumentException('DeepSeek: API Key inválida.');
        }
        return $key;
    }

    private function request(array $payload, string $key): array
    {
        try {
            $response = ($this->client ?? new Client())->request('POST', 'https://api.deepseek.com/chat/completions', [
                'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'], 'json' => $payload,
                'timeout' => 90, 'connect_timeout' => 10, 'read_timeout' => 90, 'verify' => true,
                'allow_redirects' => false, 'http_errors' => false, 'stream' => true
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('DeepSeek: fallo de transporte; resultado incierto, sin reintento automático.');
        }
        $stream = $response->getBody();
        if ($response->getStatusCode() !== 200) {
            $stream->close();
            throw new RuntimeException('DeepSeek: solicitud rechazada (HTTP ' . $response->getStatusCode() . '); revisa permisos, cuota o configuración. Sin reintento automático.');
        }
        try {
            $raw = '';
            while (!$stream->eof() && strlen($raw) <= 2097152) {
                $chunk = $stream->read(min(65536, 2097153 - strlen($raw)));
                if ($chunk === '' && !$stream->eof()) {
                    throw new RuntimeException();
                }
                $raw .= $chunk;
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('DeepSeek: lectura incompleta; sin reintento automático.');
        } finally {
            $stream->close();
        }
        $body = strlen($raw) <= 2097152 ? json_decode($raw, true, 64) : null;
        if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('DeepSeek: respuesta JSON inválida o mayor de 2 MiB.');
        }
        return $body;
    }
}
