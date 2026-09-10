<?php

namespace App\Services\Sqns;

use App\Models\Core\Account;
use App\Models\Integrations\Sqns\Client as SqnsClient;
use App\Models\Integrations\Sqns\Setting;
use App\Models\Integrations\Sqns\Visit;
use Carbon\Carbon;

class VisitIngestor
{
    public function ingest(Setting $setting, Account $account, array $payload): Visit
    {
        $visitId = VisitPayload::id($payload);

        if (! $visitId) {
            throw new \InvalidArgumentException('SQNS visit id is missing.');
        }

        $visit = Visit::query()->firstOrCreate(
            [
                'setting_id' => $setting->id,
                'visit_id' => $visitId,
            ],
            [
                'user_id' => $setting->user_id,
                'account_id' => $account->id,
                'status' => Visit::STATUS_PENDING,
            ],
        );
        $storedPayload = is_array($visit->body) ? $visit->body : [];

        if (! $visit->wasRecentlyCreated && VisitPayload::isOlder($payload, $storedPayload)) {
            return $visit;
        }

        $clientData = data_get($payload, 'clientData', []);
        $clientData = is_array($clientData) ? $clientData : [];
        $clientId = data_get($payload, 'clientId') ?? data_get($clientData, 'id');

        if (is_numeric($clientId) && (int) $clientId > 0) {
            $this->upsertClient($setting, $account, (int) $clientId, $clientData);
        }

        $organizationId = data_get($payload, 'organization.id');
        $organizationName = data_get($payload, 'organization.name');

        if ($organizationId && (! $setting->organization_id || ! $setting->organization_name)) {
            $setting->forceFill([
                'organization_id' => $setting->organization_id ?: $organizationId,
                'organization_name' => $setting->organization_name ?: $organizationName,
            ])->save();
        }

        $visit->fill($this->withoutEmptyValues([
            'user_id' => $setting->user_id,
            'account_id' => $account->id,
            'client_id' => is_numeric($clientId) ? (int) $clientId : null,
            'resource_id' => data_get($payload, 'resourceId'),
            'organization_id' => $organizationId,
            'organization_name' => $organizationName,
            'datetime' => $this->date(data_get($payload, 'datetime')),
            'cost' => $this->cost($payload),
            'attendance' => data_get($payload, 'attendance'),
            'deleted' => $this->boolean(data_get($payload, 'deleted')),
            'online' => $this->boolean(data_get($payload, 'online')),
            'is_paid' => $this->boolean(data_get($payload, 'isPaid')),
            'author' => data_get($payload, 'author'),
            'services' => $this->services($payload),
            'comment' => data_get($payload, 'comment'),
            'source_created_at' => $this->date(data_get($payload, 'create_date')),
            'source_updated_at' => $this->date(data_get($payload, 'update_date')),
        ]));
        $visit->status = Visit::STATUS_PENDING;
        $visit->error_message = null;
        $visit->body = VisitPayload::merge(
            $storedPayload,
            $payload,
        );
        $visit->save();

        return $visit;
    }

    private function upsertClient(Setting $setting, Account $account, int $clientId, array $data): SqnsClient
    {
        $client = SqnsClient::query()->firstOrCreate(
            [
                'setting_id' => $setting->id,
                'client_id' => $clientId,
            ],
            [
                'user_id' => $setting->user_id,
                'account_id' => $account->id,
            ],
        );

        $client->fill($this->withoutEmptyValues([
            'user_id' => $setting->user_id,
            'account_id' => $account->id,
            'name' => $this->clientName($data),
            'phone' => data_get($data, 'phone'),
            'additional_phone' => data_get($data, 'additionalPhone'),
            'email' => data_get($data, 'email'),
            'birth_date' => $this->date(data_get($data, 'birthDate'))?->toDateString(),
            'sex' => data_get($data, 'sex'),
            'visits_count' => data_get($data, 'visitsCount'),
            'total_arrival' => $this->number(data_get($data, 'totalArrival')),
            'tags' => is_array(data_get($data, 'tags')) ? data_get($data, 'tags') : null,
        ]));
        $client->body = VisitPayload::merge(
            is_array($client->body) ? $client->body : [],
            $data,
        );
        $client->save();

        return $client;
    }

    private function clientName(array $data): ?string
    {
        $name = data_get($data, 'name');

        if (filled($name)) {
            return trim((string) $name);
        }

        $name = collect([
            data_get($data, 'lastname'),
            data_get($data, 'firstname'),
            data_get($data, 'patronymic'),
        ])->filter(fn (mixed $part): bool => filled($part))->implode(' ');

        return $name !== '' ? $name : null;
    }

    private function services(array $payload): ?string
    {
        $services = data_get($payload, 'services', []);

        if (! is_array($services)) {
            return null;
        }

        $value = collect($services)
            ->map(fn (mixed $service): mixed => data_get($service, 'name') ?? data_get($service, 'title'))
            ->filter()
            ->implode(', ');

        return $value !== '' ? $value : null;
    }

    private function cost(array $payload): ?float
    {
        foreach (['totalCost', 'totalPrice'] as $key) {
            $value = $this->number(data_get($payload, $key));

            if ($value !== null) {
                return $value;
            }
        }

        $services = data_get($payload, 'services', []);

        if (! is_array($services)) {
            return null;
        }

        $sum = 0.0;
        $found = false;

        foreach ($services as $service) {
            $price = $this->number(data_get($service, 'paySum'))
                ?? $this->number(data_get($service, 'price'));

            if ($price === null) {
                continue;
            }

            $amount = $this->number(data_get($service, 'amount')) ?? 1;
            $sum += $price * $amount;
            $found = true;
        }

        return $found ? $sum : null;
    }

    private function number(mixed $value): ?float
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], trim((string) $value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function boolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function withoutEmptyValues(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
