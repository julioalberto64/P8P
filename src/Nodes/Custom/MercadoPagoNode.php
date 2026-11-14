<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Core\ExpressionEvaluator;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class MercadoPagoNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return ['type'=>'mercado_pago','name'=>'Mercado Pago','category'=>'Community','icon'=>'CreditCard','is_start'=>false,'evaluates_expressions'=>true,
            'description'=>'Preferencias Checkout Pro y consultas autenticadas de pagos y órdenes comerciales.',
            'help_text'=>'Credencial generic_bearer: JSON {"access_token":"TEST-..."}. Checkout Pro Preferences API; disponibilidad, moneda y medios dependen del país/cuenta. Crear preferencia NO cobra ni confirma un pago. No se envía una clave idempotente inventada: este endpoint no documenta X-Idempotency-Key; usa external_reference y consulta antes de repetir una escritura incierta. Sin datos de tarjeta ni reintentos. Configura notificaciones payment en Triggers; firma y estado autenticado se verifican antes de ejecutar el workflow. Tokens caducados se renuevan manualmente.',
            'parameters'=>[
                'credential_id'=>['type'=>'credential','label'=>'Credencial (JSON)','required'=>true],
                'operation'=>['type'=>'select','label'=>'Operación','default'=>'get_payment','required'=>true,'options'=>array_map(static fn($v)=>['value'=>$v,'label'=>$v],['create_preference','get_preference','get_payment','get_merchant_order'])],
                'preference_id'=>['type'=>'string','label'=>'ID de preferencia'], 'payment_id'=>['type'=>'string','label'=>'ID de pago'],
                'merchant_order_id'=>['type'=>'string','label'=>'ID de orden comercial'],
                'preference'=>['type'=>'textarea','label'=>'Preferencia JSON (items, external_reference, back_urls, notification_url)']
            ]];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (InvalidArgumentException $e) { return false; }
    }

    public function execute(array $parameters, array $context): array
    {
        $parameters = ExpressionEvaluator::evaluate($parameters, $context);
        $p = $this->prepare($parameters);
        $auth = $this->credential($p['credential_id']);
        $token = $auth['access_token'] ?? null;
        if (!is_string($token) || preg_match('/^[A-Za-z0-9._-]{8,4096}$/D', $token) !== 1) { throw new InvalidArgumentException('Mercado Pago: access_token inválido.'); }
        $options = ['headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json']];
        if ($p['method'] === 'POST') { $options['json'] = $p['body']; }
        $result = $this->request($p['method'], 'https://api.mercadopago.com'.$p['path'], $options, [$token]);
        if (!is_array($result['data']) || !isset($result['data']['id'])) { throw new RuntimeException('Mercado Pago: respuesta sin identificador de recurso.'); }
        $result['resource'] = $p['resource'];
        return $result;
    }

    private function prepare(array $p): array
    {
        $credential = $this->text($p['credential_id'] ?? null);
        $op = $p['operation'] ?? 'get_payment';
        $r = ['credential_id'=>$credential, 'method'=>'GET'];
        if ($op === 'create_preference') {
            $body = $this->object($p['preference'] ?? null, ['items','external_reference','back_urls','notification_url','auto_return']);
            if (!is_array($body['items'] ?? null) || !array_is_list($body['items']) || count($body['items']) < 1 || count($body['items']) > 100) { throw new InvalidArgumentException('Mercado Pago: items debe contener 1–100 elementos.'); }
            foreach ($body['items'] as &$item) {
                $item = $this->object($item, ['id','title','description','quantity','currency_id','unit_price']);
                $item['title'] = $this->text($item['title'] ?? null, 256);
                $item['quantity'] = $this->integer($item['quantity'] ?? null, 1, 100000);
                $price = $item['unit_price'] ?? null;
                if ((!is_int($price) && !is_float($price)) || !is_finite((float)$price) || $price <= 0 || $price > 1000000000) { throw new InvalidArgumentException('Mercado Pago: unit_price requiere un número JSON positivo finito.'); }
                if (!is_string($item['currency_id'] ?? null) || !preg_match('/^[A-Z]{3}$/D',$item['currency_id'])) { throw new InvalidArgumentException('Mercado Pago: currency_id inválida.'); }
                foreach (['id','description'] as $f) { if (array_key_exists($f,$item)) { $this->text($item[$f],600,true); } }
            }
            unset($item);
            $body['external_reference'] = $this->text($body['external_reference'] ?? null, 256);
            if (isset($body['back_urls'])) {
                $body['back_urls'] = $this->object($body['back_urls'], ['success','failure','pending']);
                foreach ($body['back_urls'] as $url) { $this->httpsUrl($url); }
            }
            if (isset($body['notification_url'])) { $this->httpsUrl($body['notification_url']); }
            if (isset($body['auto_return']) && ($body['auto_return'] !== 'approved' || !isset($body['back_urls']['success']))) { throw new InvalidArgumentException('Mercado Pago: auto_return solo approved con back_url success.'); }
            return array_replace($r, ['method'=>'POST','path'=>'/checkout/preferences','resource'=>'preference','body'=>$body]);
        }
        if ($op === 'get_preference') {
            $id = $this->text($p['preference_id'] ?? null,128);
            if (!preg_match('/^[A-Za-z0-9_-]+$/D',$id)) { throw new InvalidArgumentException('Mercado Pago: preference_id inválido.'); }
            return $r + ['path'=>'/checkout/preferences/'.$id,'resource'=>'preference'];
        }
        if ($op === 'get_payment') { return $r + ['path'=>'/v1/payments/'.$this->id($p['payment_id'] ?? null),'resource'=>'payment']; }
        if ($op === 'get_merchant_order') { return $r + ['path'=>'/merchant_orders/'.$this->id($p['merchant_order_id'] ?? null),'resource'=>'merchant_order']; }
        throw new InvalidArgumentException('Mercado Pago: operación inválida.');
    }

    private function httpsUrl(mixed $value): void
    {
        $url = parse_url($this->text($value,2048));
        if ($url === false || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) { throw new InvalidArgumentException('Mercado Pago: se requiere URL HTTPS sin credenciales.'); }
    }

    /** Official HMAC manifest signs query data.id, request ID and timestamp, not the body. */
    public static function verifyWebhook(string $signature, string $requestId, string $dataId, string $secret, ?int $now = null, int $tolerance = 300): array
    {
        if (strlen($signature)>1024 || !preg_match('/^[A-Za-z0-9._:-]{1,200}$/D',$requestId)
            || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$dataId) || strlen($secret)<8 || strlen($secret)>4096 || $tolerance<1 || $tolerance>3600) {
            throw new InvalidArgumentException('Mercado Pago: componentes de firma inválidos.');
        }
        $parts=[];
        foreach (explode(',',$signature) as $part) {
            $pair=array_map('trim',explode('=',trim($part),2));
            if (count($pair)!==2 || isset($parts[$pair[0]]) || !in_array($pair[0],['ts','v1'],true)) { throw new InvalidArgumentException('Mercado Pago: firma inválida.'); }
            $parts[$pair[0]]=$pair[1];
        }
        if (!preg_match('/^[0-9]{10}(?:[0-9]{3})?$/D',$parts['ts'] ?? '') || !preg_match('/^[a-fA-F0-9]{64}$/D',$parts['v1'] ?? '')) { throw new InvalidArgumentException('Mercado Pago: firma inválida.'); }
        $timestamp = strlen($parts['ts']) === 13 ? intdiv((int)$parts['ts'],1000) : (int)$parts['ts'];
        if (abs(($now ?? time())-$timestamp)>$tolerance) { throw new InvalidArgumentException('Mercado Pago: firma fuera de ventana temporal.'); }
        $dataId=strtolower($dataId);
        $manifest='id:'.$dataId.';request-id:'.$requestId.';ts:'.$parts['ts'].';';
        if (!hash_equals(hash_hmac('sha256',$manifest,$secret),strtolower($parts['v1']))) { throw new InvalidArgumentException('Mercado Pago: firma rechazada.'); }
        return ['resource_id'=>$dataId,'timestamp'=>$timestamp];
    }

    private function object(mixed $value, array $allowed = []): array
    {
        if (is_string($value)) {
            if (strlen($value) > 1048576) { throw new InvalidArgumentException('Mercado Pago: JSON demasiado grande.'); }
            try { $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
            catch (\Throwable $e) { throw new InvalidArgumentException('Mercado Pago: JSON inválido.'); }
        } elseif ($value instanceof \stdClass) { $value = (array)$value; }
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || ($allowed && array_diff(array_keys($value), $allowed))) {
            throw new InvalidArgumentException('Mercado Pago: objeto o campos no admitidos.');
        }
        return $value;
    }

    private function text(mixed $value, int $max = 255, bool $blank = false): string
    {
        if (!is_string($value) || strlen($value) > $max || (!$blank && trim($value) === '') || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Mercado Pago: texto requerido o inválido.');
        }
        return $value;
    }

    private function id(mixed $value): string
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[1-9][0-9]{0,19}$/D', (string)$value) !== 1) {
            throw new InvalidArgumentException('Mercado Pago: identificador numérico inválido.');
        }
        return (string)$value;
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < $min || (int)$value > $max) {
            throw new InvalidArgumentException('Mercado Pago: entero fuera de límites.');
        }
        return (int)$value;
    }

    private function credential(string $id): array
    {
        try { $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id); }
        catch (\Throwable $e) { throw new RuntimeException('Mercado Pago: no se pudo recuperar o descifrar la credencial.'); }
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($credential['data']['api_key'] ?? null)) {
            throw new InvalidArgumentException('Mercado Pago: requiere credencial generic_bearer con configuración JSON en API Key.');
        }
        return $this->object($credential['data']['api_key']);
    }

    private function request(string $method, string $url, array $options, array $secrets): array
    {
        try {
            $response = ($this->client ?? new Client())->request($method, $url, $options + [
                'timeout'=>30, 'connect_timeout'=>10, 'verify'=>true, 'http_errors'=>false, 'allow_redirects'=>false, 'stream'=>true
            ]);
            $status = $response->getStatusCode();
            $stream = $response->getBody(); $raw = '';
            try {
                while (!$stream->eof() && strlen($raw) <= 4194304) {
                    $chunk = $stream->read(min(65536, 4194305-strlen($raw)));
                    if ($chunk === '' && !$stream->eof()) { throw new RuntimeException(); }
                    $raw .= $chunk;
                }
            } finally { $stream->close(); }
        } catch (\Throwable $e) { throw new RuntimeException('Mercado Pago: fallo de transporte/lectura; resultado incierto, sin reintentos automáticos.'); }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) { 401=>'autenticación rechazada',403=>'permisos insuficientes',429=>'cuota agotada; respetar Retry-After antes de repetir',default=>'solicitud rechazada' };
            throw new RuntimeException('Mercado Pago: '.$reason.' (HTTP '.$status.'); sin reintento automático.', $status);
        }
        try {
            $data = $raw === '' && $status === 204 ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (strlen($raw) > 4194304 || (!is_array($data) && !is_bool($data)) || (is_array($data) && (isset($data['error']) || isset($data['errors'])))) { throw new RuntimeException(); }
        } catch (\Throwable $e) { throw new RuntimeException('Mercado Pago: respuesta inválida o superior a 4 MiB.'); }
        $data = $this->sanitize($data, $secrets);
        return ['success'=>true, 'status_code'=>$status, 'data'=>$data];
    }

    private function sanitize(mixed $data, array $secrets): mixed
    {
        if (is_array($data)) {
            foreach ($data as $key=>&$value) {
                if (is_string($key) && preg_match('/password|token|secret|authorization|api_key|card_number|security_code|first_six_digits|last_four_digits/i', $key)) { unset($data[$key]); continue; }
                $value = $this->sanitize($value, $secrets);
            }
            unset($value);
        } elseif (is_string($data)) {
            foreach ($secrets as $secret) { if (is_string($secret) && $secret !== '') { $data = str_replace($secret, '[redacted]', $data); } }
        }
        return $data;
    }
}
