<?php

namespace P8P\Nodes\Custom;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class GoogleCalendarNode implements NodeInterface
{
    private ?ClientInterface $client;
    private ?CredentialManager $credentials;

    public function __construct(?ClientInterface $client = null, ?CredentialManager $credentials = null)
    {
        // El descubrimiento no debe abrir conexiones ni recuperar secretos.
        $this->client = $client;
        $this->credentials = $credentials;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'google_calendar',
            'name' => 'Google Calendar',
            'category' => 'Community',
            'description' => 'Crea un evento con horario en un calendario de Google.',
            'icon' => 'Calendar',
            'is_start' => false,
            'help_text' => 'Crear evento: guarda un access token OAuth como Bearer Token / API Key, sin el prefijo Bearer. Requiere Calendar API habilitada, permiso de escritura y scope calendar.events.owned (calendarios propios) o calendar.events (compartidos). El token caduca según expires_in y debe reemplazarse manualmente. Usa {{variable}} o {{node_id.campo}} en los campos. Fechas: YYYY-MM-DDTHH:MM:SS con Z/desplazamiento, o sin desplazamiento indicando zona IANA. Las horas ambiguas por cambio de horario requieren desplazamiento explícito. Sin recurrencias, asistentes, recordatorios ni reintentos. Salida: data.id, data.htmlLink, data.start y data.end. Los errores detienen el flujo; comprueba el calendario antes de repetir una operación incierta.',
            'parameters' => [
                'credential_id' => [
                    'type' => 'credential',
                    'required' => true,
                    'label' => 'Credencial OAuth (Bearer Token / API Key)'
                ],
                'calendar_id' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'ID del calendario',
                    'default' => 'primary',
                    'placeholder' => 'primary o ID del calendario'
                ],
                'title' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Título',
                    'placeholder' => 'Reunión o {{node_1.title}}'
                ],
                'start_time' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Fecha/hora de inicio',
                    'placeholder' => '2026-10-19T09:00:00-06:00'
                ],
                'end_time' => [
                    'type' => 'string',
                    'required' => true,
                    'label' => 'Fecha/hora de fin (exclusiva)',
                    'placeholder' => '2026-10-19T10:00:00-06:00'
                ],
                'time_zone' => [
                    'type' => 'string',
                    'required' => false,
                    'label' => 'Zona horaria IANA (obligatoria si no hay desplazamiento)',
                    'placeholder' => 'America/Guatemala'
                ],
                'description' => [
                    'type' => 'textarea',
                    'required' => false,
                    'label' => 'Descripción (opcional)'
                ],
                'location' => [
                    'type' => 'string',
                    'required' => false,
                    'label' => 'Ubicación (opcional)'
                ]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try {
            $this->prepareParameters($parameters);
            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    public function execute(array $parameters, array $context): array
    {
        // Engine ya aplica ExpressionEvaluator; validar los tipos resultantes sin interpolar dos veces.
        $prepared = $this->prepareParameters($parameters);

        try {
            $credentials = $this->credentials ?? new CredentialManager();
            $credential = $credentials->getCredentialDecrypted($prepared['credential_id']);
        } catch (\Throwable $e) {
            throw new RuntimeException('Google Calendar: no se pudo recuperar o descifrar la credencial; revisa el almacenamiento y la configuración del gestor.');
        }
        if ($credential === null) {
            throw new InvalidArgumentException('Google Calendar: la credencial seleccionada no existe.');
        }
        $token = $credential['data']['api_key'] ?? null;
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($token)
            || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', trim($token)) !== 1) {
            throw new InvalidArgumentException('Google Calendar: selecciona una credencial Bearer Token / API Key con un access token OAuth, sin el prefijo Bearer.');
        }

        try {
            $client = $this->client ?? new Client();
            $response = $client->request('POST', 'https://www.googleapis.com/calendar/v3/calendars/'
                . rawurlencode($prepared['calendar_id']) . '/events', [
                    'headers' => ['Authorization' => 'Bearer ' . trim($token), 'Accept' => 'application/json'],
                    'query' => ['sendUpdates' => 'none', 'fields' => 'id,htmlLink,start,end'],
                    'json' => $prepared['payload'],
                    'timeout' => 30,
                    'connect_timeout' => 10,
                    'verify' => true,
                    'allow_redirects' => false,
                    'http_errors' => false
                ]);
            $status = $response->getStatusCode();
            $body = json_decode((string)$response->getBody(), true);
        } catch (\Throwable $e) {
            // No incluir mensajes del cliente, encabezados ni excepciones originales.
            throw new RuntimeException('Google Calendar: fallo de transporte o lectura. El evento puede haberse creado; comprueba el calendario antes de reintentar.');
        }

        if ($status < 200 || $status >= 300) {
            $reasons = [];
            $errors = is_array($body) ? ($body['error']['errors'] ?? []) : [];
            if (is_array($errors)) {
                foreach ($errors as $error) {
                    if (is_array($error) && is_string($error['reason'] ?? null)) {
                        $reasons[] = $error['reason'];
                    }
                }
            }
            $reason = match (true) {
                $status === 401 => 'access token inválido, expirado o revocado; reemplázalo en el gestor de credenciales',
                $status === 429 || ($status === 403 && array_intersect($reasons, [
                    'rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'dailyLimitExceeded'
                ]) !== []) => 'límite de solicitudes o cuota alcanzado; espera y revisa la cuota de Google antes de reintentar',
                $status === 403 && in_array('accessNotConfigured', $reasons, true) => 'habilita Google Calendar API en el proyecto del token',
                $status === 403 => 'permisos insuficientes; revisa los scopes OAuth, el acceso de escritura al calendario y que Calendar API esté habilitada',
                $status === 404 => 'calendario inexistente o inaccesible para esta credencial',
                $status === 400 => 'Google rechazó los datos del evento; revisa el calendario, las fechas y la zona horaria',
                default => 'Google rechazó la solicitud o tuvo un error; comprueba el calendario antes de reintentar'
            };
            throw new RuntimeException("Google Calendar: {$reason} (HTTP {$status}).", $status);
        }

        // Devolver solo campos conocidos, nunca el cuerpo remoto ni datos de autenticación.
        try {
            if (!is_array($body) || isset($body['error'])
                || !is_string($body['id'] ?? null) || preg_match('/^[a-v0-9]{5,1024}$/D', $body['id']) !== 1
                || !is_string($body['htmlLink'] ?? null)) {
                throw new InvalidArgumentException();
            }
            $link = parse_url($body['htmlLink']);
            if ($link === false || ($link['scheme'] ?? '') !== 'https'
                || !in_array($link['host'] ?? '', ['www.google.com', 'calendar.google.com'], true)
                || isset($link['user']) || isset($link['pass'])) {
                throw new InvalidArgumentException();
            }
            $dates = [];
            $instants = [];
            foreach (['start', 'end'] as $field) {
                $date = $body[$field] ?? null;
                if (!is_array($date) || !is_string($date['dateTime'] ?? null)
                    || (isset($date['timeZone']) && !is_string($date['timeZone']))) {
                    throw new InvalidArgumentException();
                }
                $instants[$field] = $this->parseDateTime($date['dateTime'], $date['timeZone'] ?? '');
                $dates[$field] = ['dateTime' => $date['dateTime']];
                if (isset($date['timeZone'])) {
                    $dates[$field]['timeZone'] = $date['timeZone'];
                }
            }
            if ($instants['end'] <= $instants['start']) {
                throw new InvalidArgumentException();
            }
            $data = ['id' => $body['id'], 'htmlLink' => $body['htmlLink']] + $dates;
            if (str_contains(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), trim($token))) {
                throw new InvalidArgumentException();
            }
        } catch (\Throwable $e) {
            throw new RuntimeException('Google Calendar: respuesta de creación inválida o incompleta. El evento puede haberse creado; comprueba el calendario antes de reintentar.', $status);
        }

        return ['success' => true, 'status_code' => $status, 'data' => $data];
    }

    private function prepareParameters(array $parameters): array
    {
        foreach (['credential_id', 'calendar_id', 'title', 'start_time', 'end_time', 'time_zone', 'description', 'location'] as $field) {
            $required = in_array($field, ['credential_id', 'calendar_id', 'title', 'start_time', 'end_time'], true);
            $value = array_key_exists($field, $parameters) ? $parameters[$field] : ($required ? null : '');
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')
                || ($required && trim($value) === '') || str_contains($value, "\0")
                || preg_match('/{{\s*[a-zA-Z0-9_.]+\s*}}/', $value) === 1) {
                throw new InvalidArgumentException("Google Calendar: campo {$field} inválido, vacío o con una expresión sin resolver.");
            }
            $parameters[$field] = $value;
        }
        $calendarId = trim($parameters['calendar_id']);
        if (preg_match('/[\s\x00-\x1f\x7f]/u', $calendarId) === 1) {
            throw new InvalidArgumentException('Google Calendar: el ID del calendario no puede contener espacios ni caracteres de control.');
        }
        $zone = trim($parameters['time_zone']);
        $start = $this->parseDateTime(trim($parameters['start_time']), $zone);
        $end = $this->parseDateTime(trim($parameters['end_time']), $zone);
        if ($end <= $start) {
            throw new InvalidArgumentException('Google Calendar: el fin debe ser posterior al inicio.');
        }
        $payload = [
            'summary' => $parameters['title'],
            'start' => ['dateTime' => $start->format('Y-m-d\TH:i:s.uP')],
            'end' => ['dateTime' => $end->format('Y-m-d\TH:i:s.uP')],
            'reminders' => ['useDefault' => false]
        ];
        if ($zone !== '') {
            $payload['start']['timeZone'] = $zone;
            $payload['end']['timeZone'] = $zone;
        }
        foreach (['description', 'location'] as $field) {
            if ($parameters[$field] !== '') {
                $payload[$field] = $parameters[$field];
            }
        }
        return ['credential_id' => trim($parameters['credential_id']), 'calendar_id' => $calendarId, 'payload' => $payload];
    }

    private function parseDateTime(string $value, string $zone): DateTimeImmutable
    {
        if ($zone !== '' && !in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('Google Calendar: indica una zona horaria IANA válida.');
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:\d{2})?$/D', $value, $parts) !== 1) {
            throw new InvalidArgumentException('Google Calendar: usa fechas YYYY-MM-DDTHH:MM:SS, con hasta seis decimales y Z/desplazamiento o una zona IANA.');
        }
        $offset = $parts[4] ?? '';
        if ($offset === '' && $zone === '') {
            throw new InvalidArgumentException('Google Calendar: las fechas sin desplazamiento requieren una zona horaria IANA.');
        }
        if ($offset !== '' && $offset !== 'Z'
            && ((int)substr($offset, 1, 2) > 23 || (int)substr($offset, 4, 2) > 59)) {
            throw new InvalidArgumentException('Google Calendar: desplazamiento horario inválido.');
        }
        $local = $parts[1] . 'T' . $parts[2] . '.' . str_pad($parts[3] ?? '', 6, '0');
        $timezone = new DateTimeZone($zone === '' ? 'UTC' : $zone);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u' . ($offset !== '' ? 'P' : ''),
            $local . ($offset === 'Z' ? '+00:00' : $offset), $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || substr($parts[1], 0, 4) === '0000' || $date->format('Y-m-d\TH:i:s.u') !== $local) {
            throw new InvalidArgumentException('Google Calendar: fecha u hora inexistente o fuera de rango.');
        }
        if ($zone !== '' && $offset !== '' && $date->getOffset() !== $timezone->getOffset($date)) {
            throw new InvalidArgumentException('Google Calendar: el desplazamiento de la fecha no coincide con la zona horaria.');
        }
        if ($offset === '') {
            // No escoger silenciosamente una de las dos horas de una transición de otoño.
            $wallTime = $date->getTimestamp() + $date->getOffset();
            $candidates = [];
            foreach ($timezone->getTransitions($wallTime - 172800, $wallTime + 172800) ?: [] as $transition) {
                $candidate = $date->setTimestamp($wallTime - $transition['offset'])->setTimezone($timezone);
                if ($candidate->format('Y-m-d\TH:i:s') === $parts[1] . 'T' . $parts[2]) {
                    $candidates[$candidate->getTimestamp()] = true;
                }
            }
            if (count($candidates) > 1) {
                throw new InvalidArgumentException('Google Calendar: hora local ambigua por cambio de horario; indica el desplazamiento explícito.');
            }
        }
        return $date;
    }
}
