<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Core\ExpressionEvaluator;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class FreshdeskNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return ['type'=>'freshdesk','name'=>'Freshdesk','category'=>'Community','icon'=>'Headphones','is_start'=>false,'evaluates_expressions'=>true,
            'description'=>'Tickets, asignación, notas privadas, respuestas públicas explícitas y contactos Freshdesk v2.',
            'help_text'=>'Credencial generic_bearer, API Key JSON {"domain":"ejemplo.freshdesk.com","api_key":"..."}. La API usa Basic APIKEY:X y permisos del agente; acceso mínimo a tickets/contactos según operación. Máximo 100 por página, cuotas por plan; sin reintentos automáticos. Notas siempre privadas; respuesta pública requiere operación reply_ticket y confirm_public=true. Crear tickets puede activar correos de automatización de la cuenta. Estados estándar 2–5; estados personalizados deben incluirse explícitamente en allowed_statuses y existir en la cuenta. Campos cf_* deben existir con tipo y opciones válidas en Freshdesk. Eventos vía Triggers polling; no asume firma de webhooks de automatización.',
            'parameters'=>[
                'credential_id'=>['type'=>'credential','required'=>true,'label'=>'Credencial JSON'],
                'operation'=>['type'=>'select','required'=>true,'label'=>'Operación','default'=>'list_tickets','options'=>array_map(static fn($v)=>['value'=>$v,'label'=>$v],['list_tickets','get_ticket','create_ticket','update_ticket','assign_ticket','add_note','reply_ticket','list_contacts','get_contact','create_contact','update_contact','ticket_fields'])],
                'ticket_id'=>['type'=>'string','label'=>'ID ticket'], 'contact_id'=>['type'=>'string','label'=>'ID contacto'],
                'payload'=>['type'=>'textarea','label'=>'Campos JSON (solo campos proporcionados)'],
                'page'=>['type'=>'number','label'=>'Página (1–300)','default'=>1], 'per_page'=>['type'=>'number','label'=>'Por página (1–100)','default'=>30],
                'updated_since'=>['type'=>'string','label'=>'Tickets actualizados desde ISO8601'],
                'allowed_statuses'=>['type'=>'textarea','label'=>'Estados personalizados permitidos JSON','default'=>'[]'],
                'confirm_public'=>['type'=>'select','label'=>'Confirmar envío público','default'=>'false','options'=>[['value'=>'false','label'=>'No'],['value'=>'true','label'=>'Sí, enviar respuesta pública']]]
            ]];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (InvalidArgumentException $e) { return false; }
    }

    public function execute(array $parameters, array $context): array
    {
        $parameters = ExpressionEvaluator::evaluate($parameters, $context);
        $p=$this->prepare($parameters); $auth=$this->credential($p['credential_id']);
        $domain=$auth['domain'] ?? null; $key=$auth['api_key'] ?? null;
        if (!is_string($domain) || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.freshdesk\.com$/D',$domain)
            || !is_string($key) || !preg_match('/^[A-Za-z0-9_-]{8,512}$/D',$key)) { throw new InvalidArgumentException('Freshdesk: dominio canónico o api_key inválidos.'); }
        $basic=base64_encode($key.':X');
        $options=['headers'=>['Authorization'=>'Basic '.$basic,'Accept'=>'application/json']];
        if ($p['query']!==[]) { $options['query']=$p['query']; }
        if ($p['method']!=='GET') { $options['json']=$p['body']; }
        $result=$this->request($p['method'],'https://'.$domain.'/api/v2'.$p['path'],$options,[$key,$basic]);
        if (str_starts_with($p['operation'],'list_')) { $result['page']=$p['query']['page']; $result['per_page']=$p['query']['per_page']; $result['has_more']=is_array($result['data']) && count($result['data'])===$p['query']['per_page']; }
        return $result;
    }

    private function prepare(array $p): array
    {
        $r=['credential_id'=>$this->text($p['credential_id'] ?? null),'method'=>'GET','query'=>[],'operation'=>$p['operation'] ?? 'list_tickets']; $op=$r['operation'];
        if (in_array($op,['list_tickets','list_contacts'],true)) {
            $r['path']=$op==='list_tickets'?'/tickets':'/contacts';
            $r['query']=['page'=>$this->integer($p['page'] ?? 1,1,300),'per_page'=>$this->integer($p['per_page'] ?? 30,1,100)];
            if ($op==='list_tickets') {
                $r['query']+=['order_by'=>'updated_at','order_type'=>'asc'];
                if (isset($p['updated_since']) && $p['updated_since']!=='') {
                    $date=$this->text($p['updated_since'],40);
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D',$date) || strtotime($date)===false) { throw new InvalidArgumentException('Freshdesk: fecha ISO8601 inválida.'); }
                    $r['query']['updated_since']=$date;
                }
            }
            return $r;
        }
        if ($op==='ticket_fields') { return $r+['path'=>'/ticket_fields']; }
        if (in_array($op,['get_ticket','get_contact'],true)) { return $r+['path'=>$op==='get_ticket'?'/tickets/'.$this->id($p['ticket_id'] ?? null):'/contacts/'.$this->id($p['contact_id'] ?? null)]; }
        if (in_array($op,['add_note','reply_ticket'],true)) {
            $body=$this->object($p['payload'] ?? null,['body']); $body['body']=$this->text($body['body'] ?? null,100000);
            if ($op==='reply_ticket' && !in_array($p['confirm_public'] ?? false,[true,'true'],true)) { throw new InvalidArgumentException('Freshdesk: confirmar explícitamente respuesta pública.'); }
            if ($op==='add_note') { $body['private']=true; }
            return array_replace($r,['method'=>'POST','path'=>'/tickets/'.$this->id($p['ticket_id'] ?? null).($op==='add_note'?'/notes':'/reply'),'body'=>$body]);
        }
        $ticket=in_array($op,['create_ticket','update_ticket','assign_ticket'],true);
        if (!$ticket && !in_array($op,['create_contact','update_contact'],true)) { throw new InvalidArgumentException('Freshdesk: operación inválida.'); }
        $allowed=$ticket?['subject','description','email','requester_id','status','priority','responder_id','group_id','type','tags','custom_fields']:['name','email','phone','mobile','unique_external_id','description','job_title','company_id','tags','custom_fields'];
        if ($op==='assign_ticket') { $allowed=['responder_id','group_id']; }
        $body=$this->object($p['payload'] ?? null,$allowed);
        if (!$body) { throw new InvalidArgumentException('Freshdesk: actualización requiere campos explícitos.'); }
        foreach ($body as $key=>$value) {
            if (in_array($key,['requester_id','responder_id','group_id','company_id'],true)) { if ($value!==null) { $this->id($value); } }
            elseif ($key==='priority') { $body[$key]=$this->integer($value,1,4); }
            elseif ($key==='status') {
                $statuses=$p['allowed_statuses'] ?? [];
                if (is_string($statuses)) { try { $statuses=json_decode($statuses,true,8,JSON_THROW_ON_ERROR); } catch (\Throwable $e) { throw new InvalidArgumentException('Freshdesk: estados JSON inválidos.'); } }
                if (!is_array($statuses) || !array_is_list($statuses) || count($statuses)>100) { throw new InvalidArgumentException('Freshdesk: lista de estados inválida.'); }
                foreach ($statuses as $s) { $this->integer($s,6,1000000); }
                $status=$this->integer($value,2,1000000);
                if (!in_array($status,[2,3,4,5,...array_map('intval',$statuses)],true)) { throw new InvalidArgumentException('Freshdesk: estado no permitido.'); }
                $body[$key]=$status;
            } elseif ($key==='custom_fields') {
                $fields=$this->object($value);
                foreach ($fields as $name=>$v) {
                    if (!preg_match('/^cf_[a-z][a-z0-9_]{0,95}$/D',(string)$name) || (!is_scalar($v) && $v!==null && (!is_array($v) || !array_is_list($v)))) { throw new InvalidArgumentException('Freshdesk: campo personalizado inválido.'); }
                    if (is_array($v)) { foreach ($v as $entry) { if (!is_scalar($entry) && $entry!==null) { throw new InvalidArgumentException('Freshdesk: valor personalizado inválido.'); } } }
                }
                $body[$key]=(object)$fields;
            } elseif ($key==='tags') {
                if (!is_array($value) || !array_is_list($value) || count($value)>100) { throw new InvalidArgumentException('Freshdesk: tags inválidas.'); }
                foreach ($value as $tag) { $this->text($tag,100); }
            } else {
                $this->text($value, $key==='description'?100000:1000, true);
                if ($key==='email' && $value!=='' && !filter_var($value,FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Freshdesk: email inválido.'); }
            }
        }
        $create=str_starts_with($op,'create_');
        if ($op==='create_ticket') {
            foreach (['subject','description'] as $key) { $this->text($body[$key] ?? null,100000); }
            if (!isset($body['requester_id']) && empty($body['email'])) { throw new InvalidArgumentException('Freshdesk: requiere email o requester_id.'); }
            $body+=['status'=>2,'priority'=>1];
        }
        if ($op==='create_contact') { $this->text($body['name'] ?? null,255); if (empty($body['email']) && empty($body['phone']) && empty($body['unique_external_id'])) { throw new InvalidArgumentException('Freshdesk: contacto requiere email, phone o unique_external_id.'); } }
        return array_replace($r,['method'=>$create?'POST':'PUT','path'=>($ticket?'/tickets':'/contacts').($create?'':'/'.$this->id($p[$ticket?'ticket_id':'contact_id'] ?? null)),'body'=>$body]);
    }

    private function object(mixed $value, array $allowed = []): array
    {
        if (is_string($value)) {
            if (strlen($value) > 1048576) { throw new InvalidArgumentException('Freshdesk: JSON demasiado grande.'); }
            try { $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
            catch (\Throwable $e) { throw new InvalidArgumentException('Freshdesk: JSON inválido.'); }
        } elseif ($value instanceof \stdClass) { $value = (array)$value; }
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || ($allowed && array_diff(array_keys($value), $allowed))) {
            throw new InvalidArgumentException('Freshdesk: objeto o campos no admitidos.');
        }
        return $value;
    }

    private function text(mixed $value, int $max = 255, bool $blank = false): string
    {
        if (!is_string($value) || strlen($value) > $max || (!$blank && trim($value) === '') || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Freshdesk: texto requerido o inválido.');
        }
        return $value;
    }

    private function id(mixed $value): string
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[1-9][0-9]{0,19}$/D', (string)$value) !== 1) {
            throw new InvalidArgumentException('Freshdesk: identificador numérico inválido.');
        }
        return (string)$value;
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < $min || (int)$value > $max) {
            throw new InvalidArgumentException('Freshdesk: entero fuera de límites.');
        }
        return (int)$value;
    }

    private function credential(string $id): array
    {
        try { $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id); }
        catch (\Throwable $e) { throw new RuntimeException('Freshdesk: no se pudo recuperar o descifrar la credencial.'); }
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($credential['data']['api_key'] ?? null)) {
            throw new InvalidArgumentException('Freshdesk: requiere credencial generic_bearer con configuración JSON en API Key.');
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
        } catch (\Throwable $e) { throw new RuntimeException('Freshdesk: fallo de transporte/lectura; resultado incierto, sin reintentos automáticos.'); }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) { 401=>'autenticación rechazada',403=>'permisos insuficientes',429=>'cuota agotada; respetar Retry-After antes de repetir',default=>'solicitud rechazada' };
            throw new RuntimeException('Freshdesk: '.$reason.' (HTTP '.$status.'); sin reintento automático.', $status);
        }
        try {
            $data = $raw === '' && $status === 204 ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (strlen($raw) > 4194304 || (!is_array($data) && !is_bool($data)) || (is_array($data) && (isset($data['error']) || isset($data['errors'])))) { throw new RuntimeException(); }
        } catch (\Throwable $e) { throw new RuntimeException('Freshdesk: respuesta inválida o superior a 4 MiB.'); }
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
