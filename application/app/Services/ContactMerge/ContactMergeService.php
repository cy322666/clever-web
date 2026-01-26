<?php

namespace App\Services\ContactMerge;

use App\Models\Integrations\ContactMerge\Record;
use App\Models\Integrations\ContactMerge\Setting;
use App\Services\amoCRM\Client;
use App\Services\amoCRM\Models\Notes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class ContactMergeService
{
    public function __construct(
        private readonly Setting $setting,
        private readonly Client $amoApi,
    )
    {
    }

    public function run(): int
    {
        $contacts = $this->fetchContacts();

        if ($contacts) {

            $groups = $this->groupContacts($contacts);

            $mergedCount = 0;

            foreach ($groups as $groupContacts) {

                if (count($groupContacts) < 2)
                    continue;

                //главный для склейки
                $master = $this->selectMaster($groupContacts);

                //все дубли по текущему правилу без мастера
                $duplicates = array_filter($groupContacts, fn (array $contact) => $contact['id'] !== $master['id']);

                foreach ($duplicates as $duplicate) {

                    //если есть правила для склейки по полям
                    if ($this->setting->merge_rules) {

                        $changes = $this->buildChanges($master, $duplicate);

                        if ($this->setting->auto_merge && $changes['payload'])
                            $status = $this->updateContact($master['id'], $changes['payload']) ? 'merged' : 'error';

//                            if ($status === 'error') {
//                                $message = 'Не удалось обновить контакт.';
//                            }
                    }

                    //если тегаем контакты, но мастер контакт не тегаем
                    if ($this->setting->tag) {}
                        $this->tagContact($duplicate['id'], $this->setting->tag);

                    Record::query()->create([
                        'setting_id' => $this->setting->id,
                        'user_id' => $this->setting->user_id,
                        'master_contact_id' => $master['id'],
                        'duplicate_contact_id' => $duplicate['id'],
                        'match_fields' => $this->buildMatchFields($master),
                        'changes' => $changes['changes'] ?? null,
                        'status' => $status ?? true,
                        'message' => $message ?? null,
                    ]);

                    //TODO примечание
//                    Notes::addOne();

                    $mergedCount++;
                }
            }
        }
        return $mergedCount ?? 0;
    }

    private function fetchContacts(): array
    {
        $contacts = [];
        $page = 1;
        $limit = 250;

        while (true) {
//            try {
                $response = $this->amoApi->service->ajax()->get('/api/v4/contacts', [
                    'limit' => $limit,
                    'page' => $page,
                    'with' => 'custom_fields_values,tags',
                ]);
//            } catch (\Throwable $exception) {
//                Log::warning(__METHOD__.' failed to load contacts', [
//                    'message' => $exception->getMessage(),
//                ]);
//                break;
//            }

            $batch = $response->_embedded->contacts ?? [];

            if ($batch) {
                $contacts = array_merge($contacts, json_decode(json_encode($batch), true));
                $page++;
            } else
                break;
        }

        return $contacts;
    }

    private function groupContacts(array $contacts): array
    {
        $matchFields = $this->setting->match_fields ?? [];

        if (!$matchFields) {
            return [];
        }

        $parent = array_keys($contacts);
        $keyedIndexes = [];
        $eligible = [];

        foreach ($contacts as $index => $contact) {
            $keys = $this->matchKeys($contact, $matchFields);

            if (!$keys) {
                continue;
            }

            $eligible[$index] = true;

            foreach ($keys as $key) {
                $keyedIndexes[$key][] = $index;
            }
        }

        foreach ($keyedIndexes as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $first = $indexes[0];

            foreach (array_slice($indexes, 1) as $index) {
                $this->union($parent, $first, $index);
            }
        }

        $groups = [];

        foreach ($contacts as $index => $contact) {
            if (!isset($eligible[$index])) {
                continue;
            }

            $root = $this->find($parent, $index);
            $groups[$root][] = $contact;
        }

        return $groups;
    }

    private function matchKeys(array $contact, array $matchFields): array
    {
        $keys = [];

        foreach ($matchFields as $fieldKey) {
            $values = $this->extractValues($contact, $fieldKey);

            if (!$values) {
                continue;
            }

            $normalized = $this->normalizeValues($values);

            foreach ($normalized as $value) {
                $keys[] = $fieldKey.':'.$value;
            }
        }

        return array_values(array_unique($keys));
    }

    private function find(array &$parent, int $index): int
    {
        if ($parent[$index] !== $index) {
            $parent[$index] = $this->find($parent, $parent[$index]);
        }

        return $parent[$index];
    }


    private function union(array &$parent, int $a, int $b): void
    {
        $rootA = $this->find($parent, $a);
        $rootB = $this->find($parent, $b);

        if ($rootA !== $rootB) {
            $parent[$rootB] = $rootA;
        }
    }

    //TODO тут разные стратегии проверки?
    private function selectMaster(array $contacts): array
    {
        usort($contacts, fn (array $a, array $b) => $a['id'] <=> $b['id']);

        if ($this->setting->master_strategy === Setting::STRATEGY_NEWEST)
            return end($contacts);

        return reset($contacts);
    }

    private function buildChanges(array $master, array $duplicate): array
    {
        $rules = collect($this->setting->merge_rules ?? [])
            ->keyBy('field_id')
            ->map(fn (array $rule) => $rule['rule'] ?? Setting::RULE_MERGE)
            ->toArray();

        $masterFields = $this->mapCustomFields($master);
        $duplicateFields = $this->mapCustomFields($duplicate);

        $payloadFields = [];
        $changes = [];

        $allFieldIds = array_unique(array_merge(array_keys($masterFields), array_keys($duplicateFields)));

        foreach ($allFieldIds as $fieldId) {
            $rule = $rules[$fieldId] ?? Setting::RULE_MERGE;

            if ($rule === Setting::RULE_SKIP) {
                continue;
            }

            $currentValues = $masterFields[$fieldId] ?? [];
            $incomingValues = $duplicateFields[$fieldId] ?? [];

            $nextValues = match ($rule) {
                Setting::RULE_KEEP_NEW => $incomingValues,
                Setting::RULE_KEEP_OLD => $currentValues,
                default => $this->mergeValues($currentValues, $incomingValues),
            };

            if ($this->valuesChanged($currentValues, $nextValues)) {
                $payloadFields[] = [
                    'field_id' => $fieldId,
                    'values' => $nextValues,
                ];

                $changes[] = [
                    'field_id' => $fieldId,
                    'from' => $currentValues,
                    'to' => $nextValues,
                    'rule' => $rule,
                ];
            }
        }

        $nameRule = $rules['name'] ?? Setting::RULE_MERGE;
        $namePayload = null;
        $nameChanges = null;

        if ($nameRule !== Setting::RULE_SKIP) {
            $masterName = $master['name'] ?? null;
            $duplicateName = $duplicate['name'] ?? null;

            $nextName = match ($nameRule) {
                Setting::RULE_KEEP_NEW => $duplicateName,
                Setting::RULE_KEEP_OLD => $masterName,
                default => $masterName ?: $duplicateName,
            };

            if ($nextName !== $masterName && $nextName !== null) {
                $namePayload = $nextName;
                $nameChanges = [
                    'field_id' => 'name',
                    'from' => $masterName,
                    'to' => $nextName,
                    'rule' => $nameRule,
                ];
            }
        }

        $payload = [];

        if ($payloadFields) {
            $payload['custom_fields_values'] = $payloadFields;
        }

        if ($namePayload) {
            $payload['name'] = $namePayload;
        }

        if ($nameChanges) {
            $changes[] = $nameChanges;
        }

        return [
            'payload' => $payload,
            'changes' => $changes,
        ];
    }

    private function updateContact(int $contactId, array $payload): bool
    {
        $payload['id'] = $contactId;

        try {
            $this->amoApi->service->ajax()->patch('/api/v4/contacts', [$payload]);

        } catch (\Throwable $exception) {

            Log::error(__METHOD__.' failed to update contact', [
                'contact_id' => $contactId,
                'message' => $exception->getMessage(),
            ]);
            return false;
        }

        return true;
    }

    private function tagContact(int $contactId, string $tag): void
    {
        try {
            $this->amoApi->service->ajax()->patch('/api/v4/contacts', [[
                'id' => $contactId,
                'tags' => [
                    ['name' => $tag],
                ],
            ]]);
        } catch (\Throwable $exception) {

            Log::error(__METHOD__.' failed to tag contact', [
                'contact_id' => $contactId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function buildMatchFields(array $master): string
    {
        $data = [];

        foreach ($this->setting->match_fields ?? [] as $fieldKey) {

            $data[$fieldKey] = $this->extractValues($master, $fieldKey);
        }

        foreach ($data as $field => $value) {

            return trim(implode(',', $value), ',');
        }
    }

    //
    private function extractValues(array $contact, string|int $fieldKey): array
    {
        $fields = $this->mapCustomFields($contact);
        $values = $fields[$fieldKey] ?? [];

        return array_filter(array_map(fn ($value) => Arr::get($value, 'value'), $values));
    }

    private function mapCustomFields(array $contact): array
    {
        $mapped = [];

        foreach ($contact['custom_fields_values'] ?? [] as $field) {
            $fieldId = $field['field_id'] ?? null;

            if (!$fieldId) {
                continue;
            }

            $mapped[$fieldId] = $field['values'] ?? [];
        }

        return $mapped;
    }

    private function normalizeValues(array $values): array
    {
        $normalized = array_map(fn ($value) => mb_strtolower(trim((string) $value)), $values);
        $normalized = array_filter($normalized);
        sort($normalized);

        return $normalized;
    }

    private function mergeValues(array $baseValues, array $incomingValues): array
    {
        $merged = $baseValues;

        foreach ($incomingValues as $incoming) {
            $found = false;

            foreach ($merged as $existing) {
                if (($existing['value'] ?? null) === ($incoming['value'] ?? null)
                    && ($existing['enum_id'] ?? null) === ($incoming['enum_id'] ?? null)) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $merged[] = $incoming;
            }
        }

        return $merged;
    }

    private function valuesChanged(array $currentValues, array $nextValues): bool
    {
        return json_encode($currentValues) !== json_encode($nextValues);
    }

}
