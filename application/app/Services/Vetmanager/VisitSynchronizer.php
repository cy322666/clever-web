<?php

namespace App\Services\Vetmanager;

use App\Models\amoCRM\Field;
use App\Models\Core\Account;
use App\Models\Integrations\Vetmanager\Client as ClientMapping;
use App\Models\Integrations\Vetmanager\Setting;
use App\Models\Integrations\Vetmanager\Visit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class VisitSynchronizer
{
    public function __construct(private readonly AmoCrmGateway $amo) {}

    public function synchronize(Visit $visit): void
    {
        $visit->loadMissing(['setting', 'account']);
        $setting = $visit->setting;
        $account = $visit->account;

        if (! $setting instanceof Setting || ! $setting->active) {
            throw new RuntimeException('Интеграция Vetmanager выключена.');
        }

        if (! $account instanceof Account || ! $account->active) {
            throw new RuntimeException('Аккаунт amoCRM не подключен.');
        }

        if (! $setting->targetStatusIds()) {
            throw new RuntimeException('Не выбран этап для сделок amoCRM.');
        }

        $admission = (new VetmanagerApiClient($setting))->admission((string) $visit->external_id);
        $eventData = data_get($visit->event_payload, 'data', []);

        if (is_array($eventData)) {
            $admission = array_replace($eventData, $admission);
        }

        $data = new AdmissionData($admission, (string) ($setting->timezone ?: 'Europe/Moscow'));

        if ($data->id() !== (string) $visit->external_id) {
            throw new RuntimeException('ID приема в ответе Vetmanager не совпадает с событием.');
        }

        if ($data->clientId() === '') {
            throw new RuntimeException('В приеме Vetmanager отсутствует ID клиента.');
        }

        $contactId = Cache::lock(
            sprintf('vetmanager:setting:%d:client:%s', $setting->id, $data->clientId()),
            60,
        )->block(15, function () use ($visit, $setting, $account, $data): int {
            $mapping = ClientMapping::query()->firstOrNew([
                'setting_id' => $setting->id,
                'external_id' => $data->clientId(),
            ]);

            $mapping->fill([
                'user_id' => $setting->user_id,
                'account_id' => $account->id,
                'name' => $data->clientName(),
                'phone' => $data->phones()[0] ?? null,
                'email' => $data->email(),
                'payload' => [
                    'id' => $data->clientId(),
                    'name' => $data->clientName(),
                    'phones' => $data->phones(),
                    'email' => $data->email(),
                ],
            ]);
            $mapping->save();

            $contactId = $this->resolveContact($visit, $mapping, $setting, $account, $data);

            $mapping->forceFill([
                'contact_id' => $contactId,
                'synced_at' => now(),
            ])->save();

            return $contactId;
        });

        $visit->forceFill(['contact_id' => $contactId])->save();
        $leadId = $this->resolveLead($visit, $setting, $account, $data, $contactId);

        $visit->forceFill([
            'vetmanager_status' => $data->status(),
            'client_id' => $data->clientId(),
            'patient_id' => $data->patientId(),
            'clinic_id' => $data->clinicId(),
            'doctor_id' => $data->doctorId(),
            'client_name' => $data->clientName(),
            'patient_name' => $data->patientName(),
            'doctor_name' => $data->doctorName(),
            'admission_date' => $data->admissionDate(),
            'amount' => $data->amount(),
            'contact_id' => $contactId,
            'lead_id' => $leadId,
            'admission_payload' => $this->admissionSnapshot($data),
        ])->save();
    }

    private function resolveContact(
        Visit $visit,
        ClientMapping $mapping,
        Setting $setting,
        Account $account,
        AdmissionData $data,
    ): int {
        $candidateIds = collect([
            $mapping->contact_id,
            $visit->contact_id,
            Visit::query()
                ->where('setting_id', $setting->id)
                ->where('client_id', $data->clientId())
                ->whereNotNull('contact_id')
                ->latest('id')
                ->value('contact_id'),
        ])->filter()->map(fn (mixed $id): int => (int) $id)->unique();

        foreach ($candidateIds as $candidateId) {
            $contact = $this->entityById($account, 'contacts', $candidateId);

            if ($contact && $this->contactCanBelongToClient($contact, $setting, $data)) {
                $this->updateContact($account, $contact, $setting, $data);

                return $candidateId;
            }
        }

        $contact = $this->findContact($account, $setting, $data);

        if ($contact) {
            $this->updateContact($account, $contact, $setting, $data);

            return (int) $contact['id'];
        }

        return $this->createContact($account, $setting, $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findContact(Account $account, Setting $setting, AdmissionData $data): ?array
    {
        $queries = collect([
            $setting->contact_external_id_field_id ? $data->clientId() : null,
            ...$data->phones(),
            $data->email(),
        ])->filter()->unique()->values();
        $candidates = [];

        foreach ($queries as $query) {
            $response = $this->amo->request($account, 'GET', '/api/v4/contacts', query: [
                'query' => (string) $query,
                'limit' => 50,
            ]);

            foreach ((array) data_get($response, '_embedded.contacts', []) as $contact) {
                if (! is_array($contact) || empty($contact['id']) || ! $this->contactCanBelongToClient($contact, $setting, $data)) {
                    continue;
                }

                $score = $this->contactMatchScore($contact, $setting, $data);

                if ($score > 0) {
                    $id = (int) $contact['id'];
                    $candidates[$id] = [
                        'score' => max($score, (int) ($candidates[$id]['score'] ?? 0)),
                        'contact' => $contact,
                    ];
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        uasort($candidates, fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        $topScore = (int) reset($candidates)['score'];
        $best = array_filter($candidates, fn (array $candidate): bool => (int) $candidate['score'] === $topScore);

        if (count($best) !== 1) {
            throw new RuntimeException('В amoCRM найдено несколько одинаково подходящих контактов. Синхронизация остановлена.');
        }

        return (array) reset($best)['contact'];
    }

    private function createContact(Account $account, Setting $setting, AdmissionData $data): int
    {
        $payload = [
            'name' => $data->clientName(),
            'custom_fields_values' => $this->contactFieldsForCreate($setting, $data),
        ];

        if ($setting->responsible_user_id) {
            $payload['responsible_user_id'] = (int) $setting->responsible_user_id;
        }

        if ($payload['custom_fields_values'] === []) {
            unset($payload['custom_fields_values']);
        }

        $response = $this->amo->request($account, 'POST', '/api/v4/contacts', [$payload]);

        return $this->embeddedEntityId($response, 'contacts');
    }

    /**
     * @param  array<string, mixed>  $contact
     */
    private function updateContact(Account $account, array $contact, Setting $setting, AdmissionData $data): void
    {
        $fields = [];
        $existingPhones = $this->fieldValuesByCode($contact, 'PHONE');
        $existingPhoneKeys = collect($existingPhones)
            ->map(fn (array $value): ?string => AdmissionData::comparablePhone((string) ($value['value'] ?? '')))
            ->filter()
            ->all();

        foreach ($data->phones() as $phone) {
            $key = AdmissionData::comparablePhone($phone);

            if ($key && ! in_array($key, $existingPhoneKeys, true)) {
                $existingPhones[] = ['value' => $phone, 'enum_code' => 'WORK'];
                $existingPhoneKeys[] = $key;
            }
        }

        if ($existingPhones !== $this->fieldValuesByCode($contact, 'PHONE') && $existingPhones !== []) {
            $fields[] = ['field_code' => 'PHONE', 'values' => array_values($existingPhones)];
        }

        $existingEmails = $this->fieldValuesByCode($contact, 'EMAIL');
        $emailKeys = collect($existingEmails)
            ->pluck('value')
            ->map(fn (mixed $value): string => mb_strtolower(trim((string) $value)))
            ->filter()
            ->all();

        if ($data->email() && ! in_array($data->email(), $emailKeys, true)) {
            $existingEmails[] = ['value' => $data->email(), 'enum_code' => 'WORK'];
            $fields[] = ['field_code' => 'EMAIL', 'values' => array_values($existingEmails)];
        }

        if ($setting->contact_external_id_field_id) {
            $externalId = $this->fieldValueById($contact, (int) $setting->contact_external_id_field_id);

            if (blank($externalId)) {
                $fields[] = $this->customField(
                    $setting,
                    'contacts',
                    (int) $setting->contact_external_id_field_id,
                    $data->clientId(),
                );
            }
        }

        if ($fields !== []) {
            $this->amo->request(
                $account,
                'PATCH',
                '/api/v4/contacts/'.(int) $contact['id'],
                ['custom_fields_values' => array_values($fields)],
            );
        }
    }

    private function resolveLead(
        Visit $visit,
        Setting $setting,
        Account $account,
        AdmissionData $data,
        int $contactId,
    ): int {
        $lead = $visit->lead_id
            ? $this->entityById($account, 'leads', (int) $visit->lead_id, ['with' => 'contacts'])
            : null;

        if (! $lead || ! $this->leadCanBelongToVisit($lead, $setting, $data)) {
            $lead = $this->findLead($account, $setting, $data);
        }

        if (! $lead) {
            $payload = $this->leadPayload($setting, $data, true);
            $payload['_embedded']['contacts'] = [['id' => $contactId]];
            $response = $this->amo->request($account, 'POST', '/api/v4/leads', [$payload]);
            $leadId = $this->embeddedEntityId($response, 'leads');

            $visit->forceFill(['lead_id' => $leadId])->save();

            return $leadId;
        }

        $leadId = (int) $lead['id'];
        $payload = $this->leadPayload($setting, $data, false);
        $this->amo->request($account, 'PATCH', '/api/v4/leads/'.$leadId, $payload);

        $linkedContactIds = collect((array) data_get($lead, '_embedded.contacts', []))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if (! in_array($contactId, $linkedContactIds, true)) {
            $this->amo->request($account, 'POST', '/api/v4/leads/'.$leadId.'/link', [[
                'to_entity_id' => $contactId,
                'to_entity_type' => 'contacts',
            ]]);
        }

        return $leadId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLead(Account $account, Setting $setting, AdmissionData $data): ?array
    {
        $marker = '[VM#'.$data->id().']';
        $queries = collect([
            $marker,
            $setting->lead_external_id_field_id ? $data->id() : null,
        ])->filter()->unique();
        $candidates = [];

        foreach ($queries as $query) {
            $response = $this->amo->request($account, 'GET', '/api/v4/leads', query: [
                'query' => (string) $query,
                'limit' => 50,
                'with' => 'contacts',
            ]);

            foreach ((array) data_get($response, '_embedded.leads', []) as $lead) {
                if (! is_array($lead) || empty($lead['id']) || ! $this->leadCanBelongToVisit($lead, $setting, $data)) {
                    continue;
                }

                $score = str_starts_with((string) ($lead['name'] ?? ''), $marker) ? 4 : 0;

                if (
                    $setting->lead_external_id_field_id
                    && (string) $this->fieldValueById($lead, (int) $setting->lead_external_id_field_id) === $data->id()
                ) {
                    $score += 8;
                }

                if ($score > 0) {
                    $candidates[(int) $lead['id']] = ['score' => $score, 'lead' => $lead];
                }
            }
        }

        if ($candidates === []) {
            return null;
        }

        uasort($candidates, fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        $topScore = (int) reset($candidates)['score'];
        $best = array_filter($candidates, fn (array $candidate): bool => (int) $candidate['score'] === $topScore);

        if (count($best) !== 1) {
            throw new RuntimeException('В amoCRM найдено несколько сделок для одного приема Vetmanager.');
        }

        return (array) reset($best)['lead'];
    }

    /**
     * @return array<string, mixed>
     */
    private function leadPayload(Setting $setting, AdmissionData $data, bool $forCreate): array
    {
        $fields = [];

        $this->pushCustomField($fields, $setting, 'leads', $setting->lead_external_id_field_id, $data->id());
        $this->pushCustomField($fields, $setting, 'leads', $setting->lead_admission_date_field_id, $data->admissionDate());
        $this->pushCustomField($fields, $setting, 'leads', $setting->lead_patient_name_field_id, $data->patientName());
        $this->pushCustomField($fields, $setting, 'leads', $setting->lead_doctor_name_field_id, $data->doctorName());
        $this->pushCustomField($fields, $setting, 'leads', $setting->lead_description_field_id, $data->description());

        $payload = [
            'name' => $data->leadName(),
        ];

        if ($forCreate) {
            $target = $setting->targetStatusIds();
            $payload['pipeline_id'] = $target['pipeline_id'];
            $payload['status_id'] = $target['status_id'];
        }

        if ($forCreate && $setting->responsible_user_id) {
            $payload['responsible_user_id'] = (int) $setting->responsible_user_id;
        }

        if ($setting->sync_price) {
            $payload['price'] = (int) round($data->amount());
        }

        if ($fields !== []) {
            $payload['custom_fields_values'] = array_values($fields);
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contactFieldsForCreate(Setting $setting, AdmissionData $data): array
    {
        $fields = [];

        if ($data->phones() !== []) {
            $fields[] = [
                'field_code' => 'PHONE',
                'values' => array_map(
                    fn (string $phone): array => ['value' => $phone, 'enum_code' => 'WORK'],
                    $data->phones(),
                ),
            ];
        }

        if ($data->email()) {
            $fields[] = [
                'field_code' => 'EMAIL',
                'values' => [['value' => $data->email(), 'enum_code' => 'WORK']],
            ];
        }

        if ($setting->contact_external_id_field_id) {
            $fields[] = $this->customField(
                $setting,
                'contacts',
                (int) $setting->contact_external_id_field_id,
                $data->clientId(),
            );
        }

        return $fields;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function pushCustomField(
        array &$fields,
        Setting $setting,
        string $entity,
        mixed $fieldId,
        mixed $value,
    ): void {
        if (blank($fieldId) || $value === null || $value === '') {
            return;
        }

        $fields[(int) $fieldId] = $this->customField($setting, $entity, (int) $fieldId, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function customField(Setting $setting, string $entity, int $fieldId, mixed $value): array
    {
        $type = Field::query()
            ->where('user_id', $setting->user_id)
            ->where('entity_type', $entity)
            ->where('field_id', $fieldId)
            ->value('type');

        if ($value instanceof CarbonInterface) {
            $value = $value->timestamp;
        } elseif (in_array($type, ['numeric', 'price'], true)) {
            $value = is_numeric($value) ? (float) $value : 0;
        } elseif ($type === 'checkbox') {
            $value = (bool) $value;
        } else {
            $value = (string) $value;
        }

        return [
            'field_id' => $fieldId,
            'values' => [['value' => $value]],
        ];
    }

    /**
     * @param  array<string, mixed>  $contact
     */
    private function contactMatchScore(array $contact, Setting $setting, AdmissionData $data): int
    {
        $score = 0;

        if (
            $setting->contact_external_id_field_id
            && (string) $this->fieldValueById($contact, (int) $setting->contact_external_id_field_id) === $data->clientId()
        ) {
            $score += 8;
        }

        $contactPhones = collect($this->fieldValuesByCode($contact, 'PHONE'))
            ->pluck('value')
            ->map(fn (mixed $phone): ?string => AdmissionData::comparablePhone((string) $phone))
            ->filter();
        $sourcePhones = collect($data->phones())
            ->map(fn (string $phone): ?string => AdmissionData::comparablePhone($phone))
            ->filter();

        if ($contactPhones->intersect($sourcePhones)->isNotEmpty()) {
            $score += 4;
        }

        $contactEmails = collect($this->fieldValuesByCode($contact, 'EMAIL'))
            ->pluck('value')
            ->map(fn (mixed $email): string => mb_strtolower(trim((string) $email)));

        if ($data->email() && $contactEmails->contains($data->email())) {
            $score += 2;
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $contact
     */
    private function contactCanBelongToClient(array $contact, Setting $setting, AdmissionData $data): bool
    {
        if (! $setting->contact_external_id_field_id) {
            return true;
        }

        $value = $this->fieldValueById($contact, (int) $setting->contact_external_id_field_id);

        return blank($value) || (string) $value === $data->clientId();
    }

    /**
     * @param  array<string, mixed>  $lead
     */
    private function leadCanBelongToVisit(array $lead, Setting $setting, AdmissionData $data): bool
    {
        if (! $setting->lead_external_id_field_id) {
            return true;
        }

        $value = $this->fieldValueById($lead, (int) $setting->lead_external_id_field_id);

        return blank($value) || (string) $value === $data->id();
    }

    /**
     * @param  array<string, mixed>  $entity
     * @return array<int, array<string, mixed>>
     */
    private function fieldValuesByCode(array $entity, string $code): array
    {
        foreach ((array) ($entity['custom_fields_values'] ?? []) as $field) {
            if (is_array($field) && (string) ($field['field_code'] ?? '') === $code) {
                return array_values(array_filter((array) ($field['values'] ?? []), 'is_array'));
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $entity
     */
    private function fieldValueById(array $entity, int $fieldId): mixed
    {
        foreach ((array) ($entity['custom_fields_values'] ?? []) as $field) {
            if (is_array($field) && (int) ($field['field_id'] ?? 0) === $fieldId) {
                return data_get($field, 'values.0.value');
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    private function entityById(Account $account, string $entity, int $id, array $query = []): ?array
    {
        try {
            return $this->amo->request($account, 'GET', '/api/v4/'.$entity.'/'.$id, query: $query);
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), 'returned 404')) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function embeddedEntityId(array $response, string $entity): int
    {
        $id = (int) data_get($response, '_embedded.'.$entity.'.0.id', 0);

        if ($id <= 0) {
            throw new RuntimeException('amoCRM не вернула ID созданной сущности: '.$entity.'.');
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function admissionSnapshot(AdmissionData $data): array
    {
        return [
            'id' => $data->id(),
            'admission_date' => $data->admissionDate()?->toIso8601String(),
            'description' => $data->description(),
            'status' => $data->status(),
            'client_id' => $data->clientId(),
            'patient_id' => $data->patientId(),
            'clinic_id' => $data->clinicId(),
            'doctor_id' => $data->doctorId(),
            'client_name' => $data->clientName(),
            'patient_name' => $data->patientName(),
            'doctor_name' => $data->doctorName(),
            'phones' => $data->phones(),
            'email' => $data->email(),
            'invoices_sum' => $data->amount(),
        ];
    }
}
