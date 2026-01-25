<?php

namespace App\Services\ContactMerge;

use App\Models\Integrations\ContactMerge\Record;
use App\Models\Integrations\ContactMerge\Setting;
use App\Services\amoCRM\Client;
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

        if (!$contacts) {
            return 0;
        }

        $groups = $this->groupContacts($contacts);
        $mergedCount = 0;

        foreach ($groups as $groupContacts) {
            if (count($groupContacts) < 2) {
                continue;
            }

            $master = $this->selectMaster($groupContacts);
            $duplicates = array_filter($groupContacts, fn (array $contact) => $contact['id'] !== $master['id']);

            foreach ($duplicates as $duplicate) {
                $changes = $this->buildChanges($master, $duplicate);
                $status = 'tagged';
                $message = null;

                if ($this->setting->auto_merge && $changes['payload']) {
                    $status = $this->updateContact($master['id'], $changes['payload']) ? 'merged' : 'error';

                    if ($status === 'error') {
                        $message = 'Не удалось обновить контакт.';
                    }
                }

                if ($this->setting->tag) {
                    $this->tagContact($duplicate['id'], $this->setting->tag);
                }

                Record::query()->create([
                    'setting_id' => $this->setting->id,
                    'user_id' => $this->setting->user_id,
                    'master_contact_id' => $master['id'],
                    'duplicate_contact_id' => $duplicate['id'],
                    'match_fields' => $this->buildMatchFields($master),
                    'changes' => $changes['changes'],
                    'status' => $status,
                    'message' => $message,
                ]);

                $mergedCount++;
            }
        }

        return $mergedCount;
    }

    private function fetchContacts(): array
    {
        $contacts = [];
        $page = 1;
        $limit = 250;

        while (true) {
            try {
                $response = $this->amoApi->service->ajax()->get('/api/v4/contacts', [
                    'limit' => $limit,
                    'page' => $page,
                    'with' => 'custom_fields_values,tags',
                ]);
            } catch (\Throwable $exception) {
                Log::warning(__METHOD__.' failed to load contacts', [
                    'message' => $exception->getMessage(),
                ]);
                break;
            }

            $batch = $response->_embedded->contacts ?? [];

            if (!$batch) {
                break;
            }

            $contacts = array_merge($contacts, json_decode(json_encode($batch), true));
            $page++;
        }

        return $contacts;
    }

    private function groupContacts(array $contacts): array
    {
        $groups = [];

        foreach ($contacts as $contact) {
            $key = $this->matchKey($contact);

            if (!$key) {
                continue;
            }

            $groups[$key][] = $contact;
        }

        return $groups;
    }

    private function matchKey(array $contact): ?string
    {
        $matchFields = $this->setting->match_fields ?? [];

        if (!$matchFields) {
            return null;
        }

        $parts = [];

        foreach ($matchFields as $fieldKey) {
            $values = $this->extractValues($contact, $fieldKey);

            if (!$values) {
                return null;
            }

            $normalized = $this->normalizeValues($values);
            $parts[] = implode(',', $normalized);
        }

        return implode('|', $parts);
    }

    private function selectMaster(array $contacts): array
    {
        usort($contacts, fn (array $a, array $b) => $a['id'] <=> $b['id']);

        if ($this->setting->master_strategy === Setting::STRATEGY_NEWEST) {
            return end($contacts);
        }

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
            Log::warning(__METHOD__.' failed to update contact', [
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
            Log::warning(__METHOD__.' failed to tag contact', [
                'contact_id' => $contactId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function buildMatchFields(array $contact): array
    {
        $data = [];

        foreach ($this->setting->match_fields ?? [] as $fieldKey) {
            $data[$fieldKey] = $this->normalizeValues($this->extractValues($contact, $fieldKey));
        }

        return $data;
    }

    private function extractValues(array $contact, string|int $fieldKey): array
    {
        if ($fieldKey === 'name') {
            return array_filter([$contact['name'] ?? null]);
        }

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
