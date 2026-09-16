<?php

namespace App\Services\Workflows;

use App\Models\Core\Account;
use App\Services\amoCRM\Client;
use Leek\FilamentWorkflows\Exceptions\NonRetryableWorkflowException;

/** Load the card in the worker, once per run; never trust browser-supplied entity data. */
class WorkflowButtonEntitySnapshot
{
    public function hydrate(array $data, int $userId): array
    {
        if (($data['source'] ?? '') !== 'amocrm-button' || ($data['entity_snapshot']['version'] ?? null) === 1) return $data;
        $leadId = filter_var($data['lead']['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $account = Account::query()->whereKey($data['account']['id'] ?? 0)->where('user_id',$userId)->where('active',true)->first();
        if (!$leadId || !$account || !filled($account->refresh_token)) throw new NonRetryableWorkflowException('Не удалось загрузить карточку: нет сделки или активного подключения amoCRM.');
        $client = $this->client($account);
        $lead = $client->requestV4('GET','/api/v4/leads/'.$leadId,query:['with'=>'contacts,catalog_elements,loss_reason,source,is_price_modified_by_robot']);
        if ((int)($lead['id'] ?? 0) !== $leadId) throw new NonRetryableWorkflowException('Сделка не найдена или недоступна в amoCRM.');
        $contacts = $this->related($client, 'contacts', $lead['_embedded']['contacts'] ?? []);
        $companies = $this->related($client, 'companies', $lead['_embedded']['companies'] ?? []);
        $mainId = collect($lead['_embedded']['contacts'] ?? [])->firstWhere('is_main',true)['id'] ?? null;
        $data['lead'] = $data['item'] = $lead;
        $data['contacts'] = $contacts['items'];
        $data['contact'] = collect($contacts['items'])->firstWhere('id',$mainId) ?? ($contacts['items'][0] ?? null);
        $data['companies'] = $companies['items'];
        $data['company'] = $companies['items'][0] ?? null;
        $data['payload'] = array_merge($data['payload'] ?? [], ['lead_id'=>$leadId,'lead_name'=>$lead['name'] ?? '', 'pipeline_id'=>$lead['pipeline_id'] ?? null,'status_id'=>$lead['status_id'] ?? null]);
        $data['widget'] = array_merge($data['widget'] ?? [], ['pipeline_id'=>$lead['pipeline_id'] ?? null,'status_id'=>$lead['status_id'] ?? null]);
        $data['entity_snapshot'] = ['version'=>1,'loaded_at'=>now()->toIso8601String(), 'contacts'=>$contacts['coverage'],'companies'=>$companies['coverage']];
        return $data;
    }

    private function related(Client $client, string $entity, array $links): array
    {
        $allIds = array_values(array_unique(array_filter(array_map(fn ($link)=>(int)($link['id'] ?? 0),$links),fn($id)=>$id>0)));
        $ids = array_slice($allIds,0,250);
        $items = [];
        if ($ids !== []) {
            $body = $client->requestV4('GET','/api/v4/'.$entity,query:['filter'=>['id'=>$ids],'limit'=>250,'page'=>1]);
            if ($body !== [] && !is_array($body['_embedded'][$entity] ?? null)) throw new \RuntimeException('amoCRM вернула некорректный список '.$entity.'.');
            $items = array_values(array_filter($body['_embedded'][$entity] ?? [],fn($row)=>is_array($row) && in_array((int)($row['id'] ?? 0),$ids,true)));
        }
        return ['items'=>$items,'coverage'=>['linked'=>count($allIds),'loaded'=>count($items),'complete'=>count($allIds)===count($items)]];
    }

    protected function client(Account $account): Client { return new Client($account); }
}
