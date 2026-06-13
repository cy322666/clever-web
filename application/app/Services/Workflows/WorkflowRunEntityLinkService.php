<?php

namespace App\Services\Workflows;

use App\Models\Core\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class WorkflowRunEntityLinkService
{
    /** @var array<string, Account|null> */
    private array $accountCache = [];

    /**
     * @return array<int, array{type: string, label: string, id: int, url: string}>
     */
    public function forStep(Model $run, Model $step): array
    {
        $output = (array)($step->output_data ?? []);
        $input = (array)($step->input_data ?? []);
        $triggerData = (array)data_get($run->context_data, 'trigger_data', []);
        $account = $this->accountData($run, $output, $triggerData);
        $entities = [];

        $this->append($entities, $output['parent_entity'] ?? null, $output['parent_id'] ?? null, $account);

        foreach (['affected_entities', 'created_entities', 'linked_entities', 'entities'] as $key) {
            foreach ((array)($output[$key] ?? []) as $entity) {
                if (!is_array($entity)) {
                    continue;
                }

                $this->append(
                    $entities,
                    $entity['entity_type'] ?? $entity['type'] ?? null,
                    $entity['entity_id'] ?? $entity['id'] ?? null,
                    $account,
                    $entity['url'] ?? null,
                );
            }
        }

        $this->append($entities, $output['entity_type'] ?? $output['entity'] ?? null, $output['entity_id'] ?? $output['id'] ?? null, $account);
        $this->append($entities, $output['linked_entity'] ?? null, $output['linked_entity_id'] ?? null, $account);

        if ($entities === []) {
            $targetEntity = (string)($input['target_entity'] ?? $input['entity'] ?? $triggerData['entity'] ?? '');
            $targetId = $this->numericId($input['target_entity_id'] ?? $input['entity_id'] ?? null)
                ?: $this->triggerEntityId($triggerData, $targetEntity);

            $this->append($entities, $targetEntity, $targetId, $account);
        }

        return array_values($entities);
    }

    /**
     * @param array<string, array{type: string, label: string, id: int, url: string}> $entities
     * @param array{subdomain: ?string, zone: string} $account
     */
    private function append(array &$entities, mixed $type, mixed $id, array $account, mixed $url = null): void
    {
        $type = $this->normalizeType((string)$type);
        $id = $this->numericId($id);

        if ($type === null || $id === null) {
            return;
        }

        $url = is_string($url) && str_starts_with($url, 'http')
            ? $url
            : $this->entityUrl($type, $id, $account);

        if ($url === null) {
            return;
        }

        $entities[$type . ':' . $id] = [
            'type' => $type,
            'label' => $this->entityLabel($type),
            'id' => $id,
            'url' => $url,
        ];
    }

    /**
     * @param array<string, mixed> $output
     * @param array<string, mixed> $triggerData
     * @return array{subdomain: ?string, zone: string}
     */
    private function accountData(Model $run, array $output, array $triggerData): array
    {
        $subdomain = $this->cleanSubdomain(data_get($triggerData, 'account.subdomain'));
        $zone = (string)(data_get($triggerData, 'account.zone') ?: '');
        $accountId = $this->numericId($output['account_id'] ?? data_get($triggerData, 'account.id'));

        if ($accountId !== null) {
            $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');
            $tenantId = $run->getAttribute($tenantColumn) ?? data_get($run, 'workflow.' . $tenantColumn);
            $cacheKey = ($tenantId ?? 'none') . ':' . $accountId;
            $account = $this->accountCache[$cacheKey] ??= Account::query()
                ->when($tenantId !== null, fn($query) => $query->where('user_id', $tenantId))
                ->find($accountId);

            $subdomain ??= $this->cleanSubdomain($account?->subdomain);
            $zone = $zone !== '' ? $zone : (string)($account?->zone ?: '');
        }

        return [
            'subdomain' => $subdomain,
            'zone' => $zone !== '' ? $zone : 'ru',
        ];
    }

    /**
     * @param array<string, mixed> $triggerData
     */
    private function triggerEntityId(array $triggerData, string $entity): ?int
    {
        if ($entity === '') {
            return null;
        }

        $action = (string)($triggerData['action'] ?? '');

        return $this->numericId(data_get($triggerData, $entity . '.id'))
            ?: $this->numericId(data_get($triggerData, $entity . '.' . $action . '.element_id'))
            ?: $this->numericId(data_get($triggerData, 'item.id'))
            ?: $this->numericId(data_get($triggerData, 'item.' . $action . '.element_id'))
            ?: $this->firstElementId((array)($triggerData[$entity] ?? []));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function firstElementId(array $data): ?int
    {
        foreach (Arr::dot($data) as $key => $value) {
            if (str_ends_with((string)$key, 'element_id') && ($id = $this->numericId($value)) !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param array{subdomain: ?string, zone: string} $account
     */
    private function entityUrl(string $entity, int $id, array $account): ?string
    {
        if ($account['subdomain'] === null) {
            return null;
        }

        $path = [
            'lead' => 'leads/detail',
            'contact' => 'contacts/detail',
            'company' => 'companies/detail',
            'customer' => 'customers/detail',
        ][$entity] ?? null;

        return $path === null
            ? null
            : sprintf('https://%s.amocrm.%s/%s/%d', $account['subdomain'], $account['zone'], $path, $id);
    }

    private function normalizeType(string $type): ?string
    {
        return [
            'lead' => 'lead',
            'leads' => 'lead',
            'contact' => 'contact',
            'contacts' => 'contact',
            'company' => 'company',
            'companies' => 'company',
            'customer' => 'customer',
            'customers' => 'customer',
        ][$type] ?? null;
    }

    private function entityLabel(string $entity): string
    {
        return [
            'lead' => 'Сделка',
            'contact' => 'Контакт',
            'company' => 'Компания',
            'customer' => 'Покупатель',
        ][$entity] ?? $entity;
    }

    private function numericId(mixed $value): ?int
    {
        return is_numeric($value) && (int)$value > 0 ? (int)$value : null;
    }

    private function cleanSubdomain(mixed $value): ?string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('#^https?://#', '', $value) ?: $value;
        $value = explode('/', $value, 2)[0] ?? $value;

        return explode('.amocrm.', $value, 2)[0] ?: null;
    }
}
