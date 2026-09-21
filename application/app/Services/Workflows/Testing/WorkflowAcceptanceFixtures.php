<?php

declare(strict_types=1);

namespace App\Services\Workflows\Testing;

use Closure;
use RuntimeException;
use Throwable;

/** One-time, checkpointed data preparation; these API calls are not workflow node tests. */
final class WorkflowAcceptanceFixtures
{
    private const PREFIX = 'Clever QA recurring fixture ';
    private const KEYS = ['company','customer','catalog','element','catalog_field','customer_field','segment','segment_field',
        'transaction','note_lead','note_contact','note_company','note_customer'];
    private const CREATE_CAPS = ['company'=>10,'customer'=>10,'catalog'=>10,'segment'=>100,'segment_field'=>30];

    private Closure $request;
    private Closure $checkpoint;
    private Closure $record;
    private array $data;

    /** Request(method, path, body, query) returns decoded JSON. Checkpoint failures are fatal before any new POST. */
    public function __construct(callable $request, callable $checkpoint, callable $record, array $state = [])
    {
        $this->request=Closure::fromCallable($request);
        $this->checkpoint=Closure::fromCallable($checkpoint);
        $this->record=Closure::fromCallable($record);
        $this->data=$state ?: ['schema_version'=>1,'resources'=>[],'pending'=>null,'created_company_ids'=>[]];
        if (($this->data['schema_version']??null)!==1 || !is_array($this->data['resources']??null)
            || (!is_null($this->data['pending']??null) && !is_array($this->data['pending']))) {
            throw new RuntimeException('Некорректный checkpoint тестовых данных; требуется восстановление.');
        }
    }

    public function state(): array { return $this->data; }

    public function prepare(array $seed): array
    {
        if (!empty($this->data['pending'])) throw new RuntimeException('Исход создания тестовых данных не подтверждён; повторный POST запрещён до восстановления.');
        $leadId=self::positiveId($seed['lead_id']??null);
        $contactId=(int)($seed['contact_id']??0);
        if (!$leadId || $contactId<0 || ($seed['customers_mode']??null)!=='segments') {
            throw new RuntimeException('Для подготовки данных нужны QA-сделка и подтверждённый режим покупателей «segments».');
        }
        $companyIds=$seed['original_company_ids']??null;
        if (!is_array($companyIds) || !array_is_list($companyIds)
            || array_filter($companyIds,static fn($id)=>!self::positiveId($id)) || count(array_unique($companyIds))!==count($companyIds)
            || count($companyIds)>10) throw new RuntimeException('Не подтверждён безопасный исходный список компаний (не более 10).');
        $parents=['lead_id'=>$leadId,'contact_id'=>$contactId,'customers_mode'=>'segments'];
        if (isset($this->data['parents']) && $this->data['parents']!==$parents) {
            throw new RuntimeException('Изменились родительские сущности тестовых данных; автоматическая перепривязка запрещена.');
        }
        $this->data['parents']=$parents;
        $this->data['created_company_ids']=[];
        $this->persist();

        // Readback pins every parent before it can receive a child or a note.
        $lead=$this->readModel('/api/v4/leads/'.$leadId,$leadId);
        if (!str_starts_with((string)($lead['name']??''),'Clever QA recurring ')
            || !in_array((int)($lead['status_id']??0),[142,143],true)) {
            throw new RuntimeException('Родитель примечания не является закрытой QA-сделкой.');
        }
        if ($contactId) $this->readModel('/api/v4/contacts/'.$contactId,$contactId);
        $companies=$this->collection('/api/v4/companies');
        $currentIds=array_map(static fn(array $row)=>(int)$row['id'],$companies);
        $originalIds=array_map('intval',$companyIds); sort($currentIds); sort($originalIds);
        if ($currentIds!==$originalIds) throw new RuntimeException('Список компаний изменился после предварительной проверки; создание остановлено.');
        $customers=$this->collection('/api/v4/customers');
        if (count($companies)>10 || count($customers)>10) throw new RuntimeException('Тестовый аккаунт превышает лимит 10 компаний или покупателей.');
        $this->data['inventory']=['company_count'=>count($companies),'customer_count'=>count($customers)];
        $this->persist();

        $this->ensure('company','/api/v4/companies',[['name'=>self::name('company')]],['name'=>self::name('company')],$companies);
        $this->ensure('customer','/api/v4/customers',[['name'=>self::name('customer')]],['name'=>self::name('customer')],$customers);
        $this->ensure('catalog','/api/v4/catalogs',[['name'=>self::name('catalog'),'type'=>'regular']],['name'=>self::name('catalog'),'type'=>'regular']);
        $catalogId=$this->id('catalog');
        $this->ensure('catalog_field','/api/v4/catalogs/'.$catalogId.'/custom_fields',
            [['name'=>self::name('catalog_field'),'type'=>'text']],['name'=>self::name('catalog_field'),'type'=>'text']);
        $this->ensure('element','/api/v4/catalogs/'.$catalogId.'/elements',
            [['name'=>self::name('element')]],['name'=>self::name('element'),'catalog_id'=>$catalogId]);
        $this->ensure('customer_field','/api/v4/customers/custom_fields',
            [['name'=>self::name('customer_field'),'type'=>'text']],['name'=>self::name('customer_field'),'type'=>'text'],null,true);
        $this->ensure('segment','/api/v4/customers/segments',['name'=>self::name('segment')],['name'=>self::name('segment')]);
        $this->ensure('segment_field','/api/v4/customers/segments/custom_fields',
            [['name'=>self::name('segment_field'),'type'=>'text']],['name'=>self::name('segment_field'),'type'=>'text'],null,true);
        $customerId=$this->id('customer');
        $this->ensure('transaction','/api/v4/customers/'.$customerId.'/transactions',
            [['price'=>1,'comment'=>self::name('transaction')]],['price'=>1,'comment'=>self::name('transaction'),'customer_id'=>$customerId]);
        foreach (['lead'=>$leadId,'contact'=>$contactId,'company'=>$this->id('company'),'customer'=>$customerId] as $entity=>$id) {
            if (!$id) continue;
            $plural=['lead'=>'leads','contact'=>'contacts','company'=>'companies','customer'=>'customers'][$entity];
            $key='note_'.$entity;
            $this->ensure($key,'/api/v4/'.$plural.'/'.$id.'/notes',[
                ['note_type'=>'common','params'=>['text'=>self::name($key)],'is_need_to_trigger_digital_pipeline'=>false],
            ],['note_type'=>'common','params'=>['text'=>self::name($key)],'entity_id'=>$id],null,true);
        }

        $lookups=[];
        foreach ($this->data['resources'] as $resource) $lookups[$resource['collection']]=$resource['id'];
        $lookups['/api/v4/customers/transactions']=$this->id('transaction');
        return ['ids'=>['companies'=>$this->id('company'),'customers'=>$customerId,'catalog_id'=>$catalogId],
            'lookups'=>$lookups,'created_company_ids'=>$this->data['created_company_ids']];
    }

    /** Exact durable intent plus independent endpoint/body/parent checks, never a broad POST allowlist. */
    public static function assertMutation(string $method,string $path,array $body,array $state): void
    {
        $pending=$state['pending']??null;
        if ($method!=='POST' || !is_array($pending) || ($pending['method']??null)!==$method
            || ($pending['path']??null)!==$path || ($pending['body']??null)!==$body || !empty($pending['response_id'])) {
            throw new RuntimeException('Создание тестовых данных не соответствует сохранённому намерению.');
        }
        $key=$pending['key']??'';
        if (!in_array($key,self::KEYS,true) || isset($state['resources'][$key])) throw new RuntimeException('Повторное создание QA-объекта запрещено.');
        [$expectedPath,$expectedBody]=self::creationSpec($key,$state);
        if ($path!==$expectedPath || $body!==$expectedBody) throw new RuntimeException('Путь, родитель или тело запроса QA-объекта не разрешены.');
        if (isset(self::CREATE_CAPS[$key])) {
            $count=$state['inventory'][$key.'_count']??null;
            $cap=self::CREATE_CAPS[$key];
            if (!is_int($count) || $count<0 || $count>=$cap) throw new RuntimeException('Нельзя создать QA-объект '.$key.': лимит '.$cap.' не подтверждён.');
        }
    }

    private function ensure(string $key,string $collection,array $body,array $expected,?array $knownRows=null,bool $reuseExisting=false): void
    {
        [$allowedPath,$allowedBody]=self::creationSpec($key,$this->data);
        if ($collection!==$allowedPath || $body!==$allowedBody) throw new RuntimeException('Внутренняя ошибка плана тестовых данных.');
        if (isset($this->data['resources'][$key])) {
            $resource=$this->data['resources'][$key];
            if (($resource['collection']??null)!==$collection || !self::positiveId($resource['id']??null)
                || !is_bool($resource['owned']??null) || !is_array($resource['expected']??null)) {
                throw new RuntimeException('Изменился путь или родитель сохранённого QA-объекта '.$key.'.');
            }
            // Owned objects must retain their QA identity; borrowed children are read-only.
            if ($resource['owned'] && $resource['expected']!==$expected) throw new RuntimeException('Изменилось описание сохранённого QA-объекта '.$key.'.');
            $this->verify($resource);
            $this->emit($key,'reused',$resource['id']);
            return;
        }
        $rows=$knownRows??$this->collection($collection);
        $matches=array_values(array_filter($rows,fn(array $row):bool=>$this->identityMatches($key,$row)));
        if (count($matches)>1) throw new RuntimeException('Найдено несколько QA-объектов с маркером '.$key.'; автоматический выбор запрещён.');
        if ($matches!==[]) {
            $this->remember($key,$collection,(int)$matches[0]['id'],$expected,true);
            $this->verify($this->data['resources'][$key]);
            $this->persist();
            $this->emit($key,'adopted',(int)$matches[0]['id']);
            return;
        }
        if ($reuseExisting) foreach ($rows as $row) {
            if (str_starts_with($key,'note_')) {
                if (($row['note_type']??'')!=='common' || !is_string($row['params']['text']??null)) continue;
                $borrowed=['note_type'=>'common','params'=>['text'=>$row['params']['text']],'entity_id'=>$expected['entity_id']];
            } else {
                if (!is_string($row['name']??null) || !is_string($row['type']??null)) continue;
                $borrowed=['name'=>$row['name'],'type'=>$row['type']];
            }
            $this->remember($key,$collection,(int)$row['id'],$borrowed,false);
            $this->verify($this->data['resources'][$key]);
            $this->persist();
            $this->emit($key,'borrowed',(int)$row['id']);
            return;
        }
        if (isset(self::CREATE_CAPS[$key])) $this->data['inventory'][$key.'_count']=count($rows);
        $intent=['key'=>$key,'method'=>'POST','path'=>$collection,'body'=>$body];
        // Reject an impossible plan/cap before recording an ambiguous mutation intent.
        self::assertMutation('POST',$collection,$body,array_replace($this->data,['pending'=>$intent]));
        $this->data['pending']=$intent;
        $this->persist(); // Must succeed before POST; timeout/error leaves the intent unresolved.
        $this->emit($key,'creating',null);
        $response=($this->request)('POST',$collection,$body,[]);
        $id=$key==='segment' ? self::positiveId($response['id']??null)
            : self::positiveId($response['_embedded'][basename($collection)][0]['id']??null);
        // Official transaction POST examples use an inconsistent embedded key. Do not
        // mistake a customer ID for a transaction: reconcile against the scoped list.
        if ($key==='transaction' && !$id && is_array($response) && $response!==[]) {
            $found=array_values(array_filter($this->collection($collection),fn(array $row):bool=>$this->matches($row,$expected)));
            if (count($found)===1) $id=(int)$found[0]['id'];
        }
        if (!$id) throw new RuntimeException('Создание '.$key.' не вернуло подтверждённый ID; повтор запрещён до восстановления.');
        $this->data['pending']['response_id']=$id;
        $this->remember($key,$collection,$id,$expected,true);
        if ($key==='company') $this->data['created_company_ids'][]=$id;
        $this->persist(); // Make the response ID recoverable before a fallible readback.
        $this->verify($this->data['resources'][$key]);
        $this->data['pending']=null;
        $this->persist();
        $this->emit($key,'created',$id);
    }

    private static function creationSpec(string $key,array $state): array
    {
        $ownedId=static function(string $parent) use($state):int {
            $resource=$state['resources'][$parent]??[];
            $id=self::positiveId($resource['id']??null);
            $collection=['company'=>'/api/v4/companies','customer'=>'/api/v4/customers','catalog'=>'/api/v4/catalogs'][$parent]??null;
            if (!$id || ($resource['owned']??null)!==true || ($resource['collection']??null)!==$collection
                || ($resource['expected']['name']??null)!==self::name($parent)) {
                throw new RuntimeException('Не подтверждён QA-родитель '.$parent.'.');
            }
            return $id;
        };
        $name=self::name($key);
        if ($key==='company') return ['/api/v4/companies',[['name'=>$name]]];
        if ($key==='customer') {
            if (($state['parents']['customers_mode']??null)!=='segments') throw new RuntimeException('Создание покупателя разрешено только в подтверждённом режиме segments.');
            return ['/api/v4/customers',[['name'=>$name]]];
        }
        if ($key==='catalog') return ['/api/v4/catalogs',[['name'=>$name,'type'=>'regular']]];
        if ($key==='element') return ['/api/v4/catalogs/'.$ownedId('catalog').'/elements',[['name'=>$name]]];
        if ($key==='catalog_field') return ['/api/v4/catalogs/'.$ownedId('catalog').'/custom_fields',[['name'=>$name,'type'=>'text']]];
        if ($key==='customer_field') return ['/api/v4/customers/custom_fields',[['name'=>$name,'type'=>'text']]];
        if ($key==='segment') return ['/api/v4/customers/segments',['name'=>$name]];
        if ($key==='segment_field') return ['/api/v4/customers/segments/custom_fields',[['name'=>$name,'type'=>'text']]];
        if ($key==='transaction') return ['/api/v4/customers/'.$ownedId('customer').'/transactions',[['price'=>1,'comment'=>$name]]];
        if (str_starts_with($key,'note_')) {
            $entity=substr($key,5);
            $plural=['lead'=>'leads','contact'=>'contacts','company'=>'companies','customer'=>'customers'][$entity]??null;
            $id=in_array($entity,['company','customer'],true) ? $ownedId($entity) : self::positiveId($state['parents'][$entity.'_id']??null);
            if (!$plural || !$id) throw new RuntimeException('Не подтверждён родитель QA-примечания.');
            return ['/api/v4/'.$plural.'/'.$id.'/notes',[
                ['note_type'=>'common','params'=>['text'=>$name],'is_need_to_trigger_digital_pipeline'=>false],
            ]];
        }
        throw new RuntimeException('Неизвестный тип тестовых данных.');
    }

    private function collection(string $path): array
    {
        $all=[]; $seen=[];
        for ($page=1;$page<=20;$page++) {
            try { $response=($this->request)('GET',$path,[],['limit'=>250,'page'=>$page]); }
            catch (Throwable $error) {
                // Only this documented empty-data error permits initial transaction
                // creation. Auth failures and arbitrary 404s must remain failures.
                if (preg_match('~^/api/v4/customers/(?:[1-9][0-9]*/)?transactions$~D',$path)
                    && preg_match('~^amoCRM API v4 error: GET '.preg_quote($path,'~').' returned 404: (.+)$~sD',$error->getMessage(),$match)) {
                    $body=json_decode($match[1],true);
                    if (is_array($body) && ($body['detail']??null)==='Transactions not found'
                        && (!isset($body['status']) || $body['status']===404) && $all===[]) return [];
                }
                throw $error;
            }
            // amoCRM uses HTTP 204 for empty entity/child inventories; Client returns [].
            $rows=$response===[] ? [] : ($response['_embedded'][basename($path)]??null);
            if (!is_array($rows) || !array_is_list($rows)) throw new RuntimeException('Некорректный список для подготовки QA-данных.');
            foreach ($rows as $row) {
                if (!is_array($row) || !self::positiveId($row['id']??null)) throw new RuntimeException('В списке QA-данных отсутствует корректный ID.');
                if (isset($seen[(string)$row['id']])) throw new RuntimeException('amoCRM повторяет объекты при чтении QA-данных.');
                $seen[(string)$row['id']]=true;
                $all[]=$row;
            }
            if (empty($response['_links']['next'])) return $all;
        }
        throw new RuntimeException('Список QA-данных превысил безопасный лимит 20 страниц.');
    }

    private function readModel(string $path,int $id): array
    {
        $response=($this->request)('GET',$path,[],[]);
        if (!is_array($response) || self::positiveId($response['id']??null)!==$id || !empty($response['is_deleted'])) {
            throw new RuntimeException('Не удалось подтвердить сохранённый QA-объект по ID.');
        }
        return $response;
    }

    private function verify(array $resource): void
    {
        $model=$this->readModel($resource['collection'].'/'.$resource['id'],(int)$resource['id']);
        if (!$this->matches($model,$resource['expected'])) throw new RuntimeException('Сохранённый QA-объект изменился или принадлежит другому родителю.');
    }

    private function matches(array $actual,array $expected): bool
    {
        foreach ($expected as $key=>$value) {
            if (!array_key_exists($key,$actual)) return false;
            if (is_array($value)) { if (!is_array($actual[$key]) || !$this->matches($actual[$key],$value)) return false; }
            elseif (is_int($value)) { if (!is_numeric($actual[$key]) || (float)$actual[$key]!== (float)$value) return false; }
            elseif ($actual[$key]!==$value) return false;
        }
        return true;
    }

    private function identityMatches(string $key,array $row): bool
    {
        if ($key==='transaction') return ($row['comment']??null)===self::name($key);
        if (str_starts_with($key,'note_')) return ($row['params']['text']??null)===self::name($key);
        return ($row['name']??null)===self::name($key);
    }

    private function remember(string $key,string $collection,int $id,array $expected,bool $owned): void
    {
        $this->data['resources'][$key]=compact('id','collection','owned','expected');
    }

    private function id(string $key): int { return (int)($this->data['resources'][$key]['id']??0); }
    private static function name(string $key): string { return self::PREFIX.$key; }
    private static function positiveId(mixed $value): int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int)$value>0 ? (int)$value : 0;
    }
    private function persist(): void { ($this->checkpoint)($this->data); }
    private function emit(string $key,string $status,?int $id): void
    {
        ($this->record)(['fixture'=>$key,'status'=>$status,'id'=>$id,'provenance'=>'fixture_setup']);
    }
}
