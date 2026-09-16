<?php

namespace App\Services\Workflows;

use App\Models\Core\Account;
use App\Services\amoCRM\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class WorkflowNodeReferences
{
    public static function plan(string $type, array $config = []): array
    {
        if (!str_starts_with($type, 'amocrm_')) return [];
        if ($type === 'amocrm_read') {
            $resource = explode('.', (string)($config['operation'] ?? ''))[0];
            if ($resource === 'bots') return ['salesbots'];
            if (in_array($resource, ['pipelines','statuses'], true)) return ['pipelines'];
            if ($resource === 'users') return ['users'];
            if ($resource === 'tasks') return ['users', 'task_types'];
            if (in_array($resource, ['leads','contacts','companies','customers'], true)) $config['entity'] = $resource;
        }
        if (str_contains($type, 'salesbot')) return ['salesbots'];
        if (str_contains($type, 'task')) return ['users', 'task_types'];
        if ($type === 'amocrm_change_lead_status') return ['pipelines'];
        if (str_contains($type, 'note') || str_contains($type, 'link_entity')) return [];
        $entity = match (true) {
            str_contains($type, 'contact_leads'), str_contains($type, 'lead') => 'leads',
            str_contains($type, 'contact') => 'contacts',
            str_contains($type, 'company') => 'companies',
            default => match ($config['target_entity'] ?? $config['entity'] ?? '') {
                'lead', 'leads' => 'leads', 'contact', 'contacts' => 'contacts', 'company', 'companies' => 'companies', 'customer', 'customers' => 'customers', default => null,
            },
        };
        return $entity ? array_merge(['fields:'.$entity, 'tags:'.$entity, 'users'], $entity === 'leads' ? ['pipelines'] : []) : [];
    }

    public function refresh(string $type, array $config): array
    {
        $userId = (int) Auth::id();
        abort_unless(WorkflowConnectionAccess::hasActiveConnection($userId), 422, 'Нет активного подключения amoCRM.');
        $account = Auth::user()->resolveAmoAccountForWidget('workflows');
        abort_unless($account instanceof Account && (int)$account->user_id === $userId && $account->active, 403);
        $plan = self::plan($type, $config);
        $client = $plan ? $this->client($account) : null;
        $data = [];
        foreach ($plan as $reference) {
            [$kind, $entity] = array_pad(explode(':', $reference, 2), 2, null);
            $data[$reference] = match ($kind) {
                'fields' => $this->pages($client, '/api/v4/'.$entity.'/custom_fields', 'custom_fields'),
                'tags' => $this->pages($client, '/api/v4/'.$entity.'/tags', 'tags'),
                'users' => $this->pages($client, '/api/v4/users', 'users'),
                'pipelines' => $this->pages($client, '/api/v4/leads/pipelines', 'pipelines'),
                'salesbots' => $this->pages($client, '/api/v4/bots', 'items'),
                'task_types' => $client->requestV4('GET', '/api/v4/account', query: ['with'=>'task_types'])['_embedded']['task_types'] ?? [],
            };
        }
        // Never replace a reference with a partially fetched list.
        DB::transaction(function () use ($data, $userId): void {
            foreach ($data as $reference => $rows) {
                [$kind, $entity] = array_pad(explode(':', $reference, 2), 2, null);
                if ($kind === 'fields') {
                    DB::table('amocrm_fields')->where('user_id',$userId)->where('entity_type',$entity)->update(['active'=>false]);
                    foreach ($rows as $row) DB::table('amocrm_fields')->updateOrInsert(['user_id'=>$userId,'entity_type'=>$entity,'field_id'=>$row['id']], [
                        'name'=>$row['name'] ?? '', 'type'=>$row['type'] ?? '', 'code'=>$row['code'] ?? null, 'sort'=>$row['sort'] ?? 0,
                        'is_api_only'=>$row['is_api_only'] ?? false, 'enums'=>json_encode($row['enums'] ?? null, JSON_UNESCAPED_UNICODE), 'active'=>true,
                    ]);
                }
                if ($kind === 'users') {
                    DB::table('amocrm_staffs')->where('user_id',$userId)->update(['active'=>false]);
                    foreach ($rows as $row) DB::table('amocrm_staffs')->updateOrInsert(['user_id'=>$userId,'staff_id'=>$row['id']], ['name'=>$row['name'] ?? '', 'active'=>data_get($row,'rights.is_active',true)]);
                }
                if ($kind === 'pipelines') {
                    DB::table('amocrm_statuses')->where('user_id',$userId)->update(['active'=>false]);
                    foreach ($rows as $pipeline) foreach ($pipeline['_embedded']['statuses'] ?? [] as $row) {
                        DB::table('amocrm_statuses')->updateOrInsert(['user_id'=>$userId,'pipeline_id'=>$pipeline['id'],'status_id'=>$row['id']], [
                            'pipeline_name'=>$pipeline['name'] ?? '', 'name'=>$row['name'] ?? '', 'sort'=>$row['sort'] ?? 0,
                            'active'=>!($pipeline['is_archive'] ?? false), 'is_archive'=>$pipeline['is_archive'] ?? false,
                        ]);
                    }
                }
            }
        });
        foreach ($data as $reference => $rows) Cache::put(self::key($userId, $reference), $rows, now()->addDay());
        if (isset($data['salesbots'])) app(WorkflowAmoCrmSalesBotService::class)->replaceOptions($account, $data['salesbots']);
        return $plan;
    }

    public static function options(string $reference): array
    {
        if (!Auth::id()) return [];
        return collect(Cache::get(self::key((int) Auth::id(), $reference), []))
            ->sortBy('name')->mapWithKeys(fn (array $row) => [str_starts_with($reference,'tags:') ? $row['name'] : $row['id'] => $row['name']])->all();
    }

    private static function key(int $userId, string $reference): string { return 'workflow:references:'.$userId.':'.$reference; }
    protected function client(Account $account): Client { return new Client($account); }

    private function pages(Client $client, string $path, string $key): array
    {
        $rows = [];
        for ($page = 1; $page <= 20; $page++) {
            $body = $client->requestV4('GET', $path, query: ['page'=>$page,'limit'=>250]);
            if ($body !== [] && !isset($body['_embedded'][$key])) throw new \RuntimeException('В ответе amoCRM отсутствует справочник. Старые данные сохранены.');
            $items = $body['_embedded'][$key] ?? [];
            if (!is_array($items)) throw new \RuntimeException('Некорректный справочник amoCRM.');
            foreach ($items as $row) {
                if (!is_array($row) || !is_numeric($row['id'] ?? null)) throw new \RuntimeException('Некорректная запись справочника amoCRM.');
                if ($key === 'pipelines' && !is_array($row['_embedded']['statuses'] ?? null)) throw new \RuntimeException('В ответе amoCRM отсутствуют этапы воронки.');
                $rows[$row['id']] = $row;
            }
            if (!filled(data_get($body,'_links.next.href')) && (int)($body['_page_count'] ?? 0) <= $page
                && (isset($body['_page_count']) || count($items)<250)) return array_values($rows);
        }
        throw new \RuntimeException('Справочник превышает 5000 записей. Старые данные сохранены.');
    }
}
