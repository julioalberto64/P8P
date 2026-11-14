<?php

namespace P8P\Nodes\Custom;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use P8P\Core\CredentialManager;
use P8P\Core\ExpressionEvaluator;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

class MagentoNode implements NodeInterface
{
    public function __construct(private ?ClientInterface $client = null, private ?CredentialManager $credentials = null)
    {
    }

    public function getSchema(): array
    {
        return ['type'=>'magento','name'=>'Magento / Adobe Commerce','category'=>'Community','icon'=>'ShoppingBag','is_start'=>false,'evaluates_expressions'=>true,
            'description'=>'Catálogo, clientes, inventario tradicional/MSI y consultas/transiciones explícitas de pedidos.',
            'help_text'=>'Magento Open Source/Adobe Commerce 2.4 PaaS/on-prem REST. No compatible automáticamente con Adobe Commerce SaaS IMS. Credencial generic_bearer JSON {"base_url":"https://shop.example","access_token":"...","store_code":"default","inventory_mode":"msi"}. Token de integración Bearer habilitado en instalación o token administrativo temporal; permisos mínimos catálogo/clientes/inventario/ventas según acción; renovar manualmente. Productos: actualiza únicamente campos enviados; MSI requiere módulo Inventory y fuentes existentes. Stock tradicional requiere inventory_mode=legacy y stock_item_id. Pedidos: hold/unhold son transiciones explícitas sujetas al estado validado por Magento; comentarios internos no cambian estado, no notifican. No factura, captura, envía ni reembolsa. Consulta capacidades/Swagger local y versión; no asume webhooks universales. Triggers usa polling de pedidos updated_at.',
            'parameters'=>[
                'credential_id'=>['type'=>'credential','required'=>true,'label'=>'Credencial JSON'],
                'operation'=>['type'=>'select','required'=>true,'default'=>'list_products','label'=>'Operación','options'=>array_map(static fn($v)=>['value'=>$v,'label'=>$v],['list_products','get_product','create_product','update_product','get_legacy_stock','update_legacy_stock','list_source_items','set_source_items','list_customers','get_customer','create_customer','update_customer','list_orders','get_order','hold_order','unhold_order','add_order_comment'])],
                'sku'=>['type'=>'string','label'=>'SKU (producto/inventario)'], 'customer_id'=>['type'=>'string','label'=>'ID cliente'],
                'order_id'=>['type'=>'string','label'=>'ID pedido'], 'stock_item_id'=>['type'=>'string','label'=>'ID stock item (tradicional)'],
                'payload'=>['type'=>'textarea','label'=>'Objeto JSON de campos o lista sourceItems'],
                'filters'=>['type'=>'textarea','label'=>'Filtros AND JSON [{"field":"sku","value":"ABC","condition_type":"eq"}]','default'=>'[]'],
                'page'=>['type'=>'number','label'=>'Página (1–10000)','default'=>1], 'page_size'=>['type'=>'number','label'=>'Tamaño (1–100)','default'=>20]
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
        $url=$auth['base_url'] ?? null; $parts=is_string($url)?parse_url($url):false;
        $token=$auth['access_token'] ?? null; $store=$auth['store_code'] ?? 'default';
        if ($parts===false || ($parts['scheme'] ?? '')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || strlen($url)>2048
            || !is_string($token) || preg_match('/^[A-Za-z0-9._~+\/-]{8,8192}=*$/D',$token)!==1
            || !is_string($store) || preg_match('/^[a-zA-Z0-9_]{1,64}$/D',$store)!==1) { throw new InvalidArgumentException('Magento: base_url HTTPS, access_token o store_code inválidos.'); }
        $mode=$auth['inventory_mode'] ?? null;
        if (($p['inventory'] ?? null)!==null && $mode!==$p['inventory']) { throw new InvalidArgumentException('Magento: confirma inventory_mode msi o legacy en la credencial según capacidades de la instalación.'); }
        $options=['headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json']];
        if ($p['query']!==[]) { $options['query']=$p['query']; }
        if (($parameters['operation'] ?? '') === 'update_customer') {
            // CustomerRepository validates a complete customer; preserve omitted fields from an authenticated snapshot.
            $current=$this->request('GET',rtrim($url,'/').'/rest/'.rawurlencode($store).'/V1'.$p['path'],$options,[$token]);
            if (!is_array($current['data']) || (string)($current['data']['id'] ?? '') !== (string)$p['body']['customer']['id']) { throw new RuntimeException('Magento: cliente actual inválido; no se actualizó.'); }
            $existing=array_intersect_key($current['data'],array_flip(['id','email','firstname','lastname','middlename','prefix','suffix','website_id','store_id','group_id','custom_attributes','disable_auto_group_change','taxvat','dob','gender']));
            $p['body']['customer']=array_replace($existing,$p['body']['customer']);
        }
        if ($p['method']!=='GET') { $options['json']=$p['body']; }
        $result=$this->request($p['method'],rtrim($url,'/').'/rest/'.rawurlencode($store).'/V1'.$p['path'],$options,[$token]);
        if ($result['data'] === false) { throw new RuntimeException('Magento: operación rechazada por el servicio.'); }
        if (is_array($result['data']) && isset($result['data']['items'])) { $result['page']=$p['page']; $result['page_size']=$p['page_size']; $result['has_more']=count($result['data']['items'])===$p['page_size']; }
        return $result;
    }

    private function prepare(array $p): array
    {
        $r=['credential_id'=>$this->text($p['credential_id'] ?? null),'method'=>'GET','query'=>[]]; $op=$p['operation'] ?? 'list_products';
        if (in_array($op,['list_products','list_customers','list_orders','list_source_items'],true)) {
            $path=match($op) {'list_products'=>'/products','list_customers'=>'/customers/search','list_orders'=>'/orders',default=>'/inventory/source-items'};
            $page=$this->integer($p['page'] ?? 1,1,10000); $size=$this->integer($p['page_size'] ?? 20,1,100);
            $query=['searchCriteria'=>['currentPage'=>$page,'pageSize'=>$size,'sortOrders'=>[['field'=>$op==='list_products'?'entity_id':($op==='list_source_items'?'source_code':'entity_id'),'direction'=>'ASC']]]];
            $filters=$p['filters'] ?? [];
            if (is_string($filters)) { try { $filters=json_decode($filters,true,16,JSON_THROW_ON_ERROR); } catch (\Throwable $e) { throw new InvalidArgumentException('Magento: filtros JSON inválidos.'); } }
            if (!is_array($filters) || !array_is_list($filters) || count($filters)>30) { throw new InvalidArgumentException('Magento: filtros debe ser lista de hasta 30 campos.'); }
            foreach ($filters as $i=>$filter) {
                $filter=$this->object($filter,['field','value','condition_type']); $field=$filter['field'] ?? null;
                $condition=$filter['condition_type'] ?? 'eq';
                if (!is_string($field) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$field) || !in_array($condition,['eq','neq','like','nlike','in','nin','gt','gteq','lt','lteq','null','notnull'],true) || !array_key_exists('value',$filter) || (!is_scalar($filter['value']) && $filter['value']!==null)) { throw new InvalidArgumentException('Magento: filtro inválido.'); }
                $query['searchCriteria']['filter_groups'][$i]['filters'][]=['field'=>$field,'value'=>$filter['value'],'condition_type'=>$condition];
            }
            return array_replace($r,['path'=>$path,'query'=>$query,'page'=>$page,'page_size'=>$size,'inventory'=>$op==='list_source_items'?'msi':null]);
        }
        if (in_array($op,['get_product','get_legacy_stock'],true)) {
            $sku=$this->sku($p['sku'] ?? null);
            return $r+['path'=>($op==='get_product'?'/products/':'/stockItems/').rawurlencode($sku),'inventory'=>$op==='get_legacy_stock'?'legacy':null];
        }
        if (in_array($op,['get_customer','get_order'],true)) { return $r+['path'=>($op==='get_customer'?'/customers/':'/orders/').$this->id($p[$op==='get_customer'?'customer_id':'order_id'] ?? null)]; }
        if (in_array($op,['hold_order','unhold_order'],true)) { return array_replace($r,['method'=>'POST','path'=>'/orders/'.$this->id($p['order_id'] ?? null).($op==='hold_order'?'/hold':'/unhold'),'body'=>new \stdClass()]); }
        if ($op==='add_order_comment') {
            $b=$this->object($p['payload'] ?? null,['comment']); $comment=$this->text($b['comment'] ?? null,10000);
            return array_replace($r,['method'=>'POST','path'=>'/orders/'.$this->id($p['order_id'] ?? null).'/comments','body'=>['statusHistory'=>['comment'=>$comment,'is_customer_notified'=>0,'is_visible_on_front'=>0]]]);
        }
        if ($op==='update_legacy_stock') {
            $b=$this->object($p['payload'] ?? null,['qty','is_in_stock']);
            if (!$b) { throw new InvalidArgumentException('Magento: stock requiere campos explícitos.'); }
            if (array_key_exists('qty',$b)) { $this->quantity($b['qty']); }
            if (array_key_exists('is_in_stock',$b) && !is_bool($b['is_in_stock'])) { throw new InvalidArgumentException('Magento: is_in_stock requiere booleano.'); }
            return array_replace($r,['method'=>'PUT','path'=>'/products/'.rawurlencode($this->sku($p['sku'] ?? null)).'/stockItems/'.$this->id($p['stock_item_id'] ?? null),'body'=>['stockItem'=>$b],'inventory'=>'legacy']);
        }
        if ($op==='set_source_items') {
            $b=$p['payload'] ?? null;
            if (is_string($b)) { try { $b=json_decode($b,true,16,JSON_THROW_ON_ERROR); } catch (\Throwable $e) { throw new InvalidArgumentException('Magento: sourceItems JSON inválido.'); } }
            if (!is_array($b) || !array_is_list($b) || count($b)<1 || count($b)>100) { throw new InvalidArgumentException('Magento: sourceItems requiere lista de 1–100 elementos.'); }
            foreach ($b as &$item) {
                $item=$this->object($item,['sku','source_code','quantity','status']); $this->sku($item['sku'] ?? null);
                if (!is_string($item['source_code'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,255}$/D',$item['source_code'])) { throw new InvalidArgumentException('Magento: source_code inválido.'); }
                $this->quantity($item['quantity'] ?? null); $item['status']=$this->integer($item['status'] ?? null,0,1);
            }
            unset($item);
            return array_replace($r,['method'=>'POST','path'=>'/inventory/source-items','body'=>['sourceItems'=>$b],'inventory'=>'msi']);
        }
        $product=in_array($op,['create_product','update_product'],true);
        if (!$product && !in_array($op,['create_customer','update_customer'],true)) { throw new InvalidArgumentException('Magento: operación inválida.'); }
        $allowed=$product?['sku','name','attribute_set_id','price','status','visibility','type_id','weight','custom_attributes']:['email','firstname','lastname','middlename','prefix','suffix','website_id','store_id','group_id','custom_attributes'];
        $b=$this->object($p['payload'] ?? null,$allowed);
        if (!$b) { throw new InvalidArgumentException('Magento: requiere campos explícitos.'); }
        foreach ($b as $key=>$value) {
            if ($key==='custom_attributes') {
                if (!is_array($value) || !array_is_list($value) || count($value)>100) { throw new InvalidArgumentException('Magento: custom_attributes requiere lista.'); }
                foreach ($value as $a) {
                    $a=$this->object($a,['attribute_code','value']);
                    if (!is_string($a['attribute_code'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$a['attribute_code']) || preg_match('/password|token|secret/i',$a['attribute_code']) || !array_key_exists('value',$a) || (!is_scalar($a['value']) && $a['value']!==null && (!is_array($a['value']) || !array_is_list($a['value'])))) { throw new InvalidArgumentException('Magento: atributo inválido.'); }
                    if (is_array($a['value'])) { foreach ($a['value'] as $v) { if (!is_scalar($v) && $v!==null) { throw new InvalidArgumentException('Magento: valor de atributo inválido.'); } } }
                }
            } elseif (in_array($key,['price','weight'],true)) { $this->quantity($value); }
            elseif (in_array($key,['attribute_set_id','website_id','group_id'],true)) { $this->integer($value,1,2147483647); }
            elseif ($key==='store_id') { $this->integer($value,0,2147483647); }
            elseif ($key==='status') { $this->integer($value,1,2); }
            elseif ($key==='visibility') { $this->integer($value,1,4); }
            elseif ($key==='sku') { $this->sku($value); }
            else { $this->text($value,1000,true); }
        }
        if (isset($b['email']) && !filter_var($b['email'],FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('Magento: email inválido.'); }
        $create=str_starts_with($op,'create_');
        if ($op==='create_product') {
            $this->sku($b['sku'] ?? null); $this->text($b['name'] ?? null,255); $this->integer($b['attribute_set_id'] ?? null,1,2147483647);
            if (($b['type_id'] ?? null)!=='simple') { throw new InvalidArgumentException('Magento: esta versión crea productos simple; extensiones/configurables requieren contratos propios.'); }
            $this->quantity($b['price'] ?? null);
        }
        if ($op==='update_product') { $sku=$this->sku($p['sku'] ?? null); if (isset($b['sku']) && $b['sku']!==$sku) { throw new InvalidArgumentException('Magento: no se renombra SKU.'); } $b['sku']=$sku; }
        if ($op==='create_customer') { foreach (['email','firstname','lastname'] as $k) { $this->text($b[$k] ?? null,255); } $this->integer($b['website_id'] ?? null,1,2147483647); }
        if ($op==='update_customer') { $b['id']=$this->id($p['customer_id'] ?? null); }
        return array_replace($r,['method'=>$create?'POST':'PUT','path'=>($product?'/products':'/customers').($create?'':'/'.($product?rawurlencode($b['sku']):$b['id'])),'body'=>[$product?'product':'customer'=>$b]]);
    }

    private function sku(mixed $value): string
    {
        $sku=$this->text($value,64);
        if (preg_match('/[\x00-\x20\x7f\/\\\\?#]/',$sku)) { throw new InvalidArgumentException('Magento: SKU inválido.'); }
        return $sku;
    }

    private function quantity(mixed $value): void
    {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value<0 || $value>1000000000) { throw new InvalidArgumentException('Magento: cantidad/precio requiere número JSON finito no negativo.'); }
    }

    private function object(mixed $value, array $allowed = []): array
    {
        if (is_string($value)) {
            if (strlen($value) > 1048576) { throw new InvalidArgumentException('Magento: JSON demasiado grande.'); }
            try { $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR); }
            catch (\Throwable $e) { throw new InvalidArgumentException('Magento: JSON inválido.'); }
        } elseif ($value instanceof \stdClass) { $value = (array)$value; }
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || ($allowed && array_diff(array_keys($value), $allowed))) {
            throw new InvalidArgumentException('Magento: objeto o campos no admitidos.');
        }
        return $value;
    }

    private function text(mixed $value, int $max = 255, bool $blank = false): string
    {
        if (!is_string($value) || strlen($value) > $max || (!$blank && trim($value) === '') || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Magento: texto requerido o inválido.');
        }
        return $value;
    }

    private function id(mixed $value): string
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[1-9][0-9]{0,19}$/D', (string)$value) !== 1) {
            throw new InvalidArgumentException('Magento: identificador numérico inválido.');
        }
        return (string)$value;
    }

    private function integer(mixed $value, int $min, int $max): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < $min || (int)$value > $max) {
            throw new InvalidArgumentException('Magento: entero fuera de límites.');
        }
        return (int)$value;
    }

    private function credential(string $id): array
    {
        try { $credential = ($this->credentials ?? new CredentialManager())->getCredentialDecrypted($id); }
        catch (\Throwable $e) { throw new RuntimeException('Magento: no se pudo recuperar o descifrar la credencial.'); }
        if (($credential['type'] ?? null) !== 'generic_bearer' || !is_string($credential['data']['api_key'] ?? null)) {
            throw new InvalidArgumentException('Magento: requiere credencial generic_bearer con configuración JSON en API Key.');
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
        } catch (\Throwable $e) { throw new RuntimeException('Magento: fallo de transporte/lectura; resultado incierto, sin reintentos automáticos.'); }
        if ($status < 200 || $status >= 300) {
            $reason = match ($status) { 401=>'autenticación rechazada',403=>'permisos insuficientes',429=>'cuota agotada; respetar Retry-After antes de repetir',default=>'solicitud rechazada' };
            throw new RuntimeException('Magento: '.$reason.' (HTTP '.$status.'); sin reintento automático.', $status);
        }
        try {
            $data = $raw === '' && $status === 204 ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (strlen($raw) > 4194304 || (!is_array($data) && !is_bool($data)) || (is_array($data) && (isset($data['error']) || isset($data['errors'])))) { throw new RuntimeException(); }
        } catch (\Throwable $e) { throw new RuntimeException('Magento: respuesta inválida o superior a 4 MiB.'); }
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
