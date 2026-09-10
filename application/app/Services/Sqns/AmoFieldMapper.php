<?php

namespace App\Services\Sqns;

use App\Models\amoCRM\Field;
use App\Models\Integrations\Sqns\Client as SqnsClient;
use App\Models\Integrations\Sqns\Setting;
use App\Models\Integrations\Sqns\Visit;
use App\Services\amoCRM\Client as AmoClient;
use Carbon\Carbon;
use DateTimeInterface;

class AmoFieldMapper
{
    private const ENUM_TYPES = ['select', 'multiselect', 'radiobutton', 'category'];

    public function __construct(private readonly AmoClient $amoApi, private readonly Setting $setting) {}

    public function apply(string $entityType, int $entityId, mixed $rows, array $values): void
    {
        $rows = $this->mappingRows($rows);

        if ($rows === []) {
            return;
        }

        $payload = [];
        $customFields = [];

        foreach ($rows as $row) {
            $source = $row['field_sqns'] ?? null;
            $target = $row['field_amo'] ?? null;

            if (! $source || ! $target || ! array_key_exists($source, $values)) {
                continue;
            }

            $value = $values[$source];

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if ($entityType === 'leads' && $target === 'system:price') {
                $price = $this->number($value);

                if ($price !== null) {
                    $payload['price'] = $price;
                }

                continue;
            }

            $field = Field::query()
                ->where('user_id', $this->setting->user_id)
                ->where('entity_type', $entityType)
                ->where('field_id', $target)
                ->where('active', true)
                ->first();

            if (! $field) {
                throw new \RuntimeException('Поле amoCRM из маппинга не найдено: '.$target);
            }

            $fieldPayload = $this->fieldPayload($field, $value);

            if ($fieldPayload) {
                $customFields[] = $fieldPayload;
            }
        }

        if ($customFields !== []) {
            $payload['custom_fields_values'] = $customFields;
        }

        if ($payload !== []) {
            $this->amoApi->requestV4('PATCH', '/api/v4/'.$entityType.'/'.$entityId, $payload);
        }
    }

    public static function values(Visit $visit, ?SqnsClient $client): array
    {
        $datetime = $visit->datetime;
        $visitBody = is_array($visit->body) ? $visit->body : [];
        $clientBody = is_array($client?->body) ? $client->body : [];

        return [
            'visit_id' => $visit->visit_id,
            'visit_datetime' => $datetime,
            'visit_date' => $datetime?->format('d.m.Y'),
            'visit_time' => $datetime?->format('H:i'),
            'attendance' => $visit->eventLabel(),
            'services' => $visit->services,
            'cost' => $visit->cost,
            'total_price' => data_get($visitBody, 'totalPrice'),
            'total_cost' => data_get($visitBody, 'totalCost'),
            'commodities' => self::itemNames(data_get($visitBody, 'commodities')),
            'subscriptions' => self::itemNames(data_get($visitBody, 'subscriptions')),
            'certificates' => self::itemNames(data_get($visitBody, 'certificates')),
            'resource_id' => $visit->resource_id,
            'master_requested' => data_get($visitBody, 'master_requested'),
            'author' => $visit->author,
            'organization' => $visit->organization_name,
            'online' => $visit->online,
            'is_paid' => $visit->is_paid,
            'comment' => $visit->comment,
            'create_date' => $visit->source_created_at,
            'update_date' => $visit->source_updated_at,
            'client_id' => $client?->client_id ?: $visit->client_id,
            'client_name' => $client?->name,
            'client_phone' => $client?->phone,
            'client_additional_phone' => $client?->additional_phone,
            'client_email' => $client?->email,
            'client_birth_date' => $client?->birth_date,
            'client_sex' => match ((int) ($client?->sex ?? 0)) {
                1 => 'М',
                2 => 'Ж',
                default => null,
            },
            'client_tags' => $client?->tags,
            'client_comment' => data_get($clientBody, 'comment'),
            'client_type' => data_get($clientBody, 'type'),
            'client_address' => data_get($clientBody, 'address'),
            'client_visits_count' => $client?->visits_count,
            'client_total_arrival' => $client?->total_arrival
                ?? data_get($clientBody, 'totalArrival'),
        ];
    }

    private static function itemNames(mixed $items): ?string
    {
        if (! is_array($items)) {
            return null;
        }

        $names = collect($items)
            ->map(fn (mixed $item): mixed => data_get($item, 'name') ?? data_get($item, 'title'))
            ->filter(fn (mixed $name): bool => is_scalar($name) && trim((string) $name) !== '')
            ->map(fn (mixed $name): string => trim((string) $name))
            ->implode(', ');

        return $names !== '' ? $names : null;
    }

    private function fieldPayload(Field $field, mixed $value): ?array
    {
        $type = strtolower((string) $field->type);

        if (is_array($value) && $type !== 'multitext' && ! in_array($type, self::ENUM_TYPES, true)) {
            $value = collect($value)
                ->filter(fn (mixed $item): bool => is_scalar($item) && trim((string) $item) !== '')
                ->map(fn (mixed $item): string => trim((string) $item))
                ->implode(', ');
        }

        $values = is_array($value) ? $value : [$value];

        if (in_array($type, self::ENUM_TYPES, true)) {
            $normalized = $this->enumValues($field, $values, $type === 'multiselect');
        } else {
            $normalized = collect($values)
                ->map(fn (mixed $item): mixed => $this->normalizeValue($item, $type))
                ->filter(fn (mixed $item): bool => $item !== null && $item !== '')
                ->map(fn (mixed $item): array => ['value' => $item])
                ->values()
                ->all();
        }

        if ($normalized === []) {
            return null;
        }

        return [
            'field_id' => (int) $field->field_id,
            'values' => $normalized,
        ];
    }

    private function enumValues(Field $field, array $values, bool $multiple): array
    {
        $enums = is_string($field->enums) ? json_decode($field->enums, true) : $field->enums;
        $enums = is_array($enums) ? $enums : [];
        $result = [];

        foreach ($values as $value) {
            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $text = trim((string) $value);
            $enumId = collect($enums)->first(function (mixed $enum, mixed $key) use ($text): bool {
                $enumValue = is_array($enum) ? ($enum['value'] ?? $enum['name'] ?? null) : $enum;

                return mb_strtolower(trim((string) $enumValue)) === mb_strtolower($text)
                    || (is_numeric($key) && (string) $key === $text);
            });

            $id = null;

            if (is_array($enumId)) {
                $id = $enumId['id'] ?? $enumId['enum_id'] ?? null;
            } elseif ($enumId !== null) {
                $key = array_search($enumId, $enums, true);
                $id = is_numeric($key) ? $key : null;
            }

            $result[] = $id ? ['enum_id' => (int) $id] : ['value' => $text];

            if (! $multiple) {
                break;
            }
        }

        return $result;
    }

    private function normalizeValue(mixed $value, string $type): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return in_array($type, ['date', 'date_time', 'birthday'], true)
                ? $value->getTimestamp()
                : $value->format('d.m.Y H:i');
        }

        if (in_array($type, ['date', 'date_time', 'birthday'], true) && is_scalar($value)) {
            try {
                return Carbon::parse((string) $value)->timestamp;
            } catch (\Throwable) {
                return null;
            }
        }

        if ($type === 'checkbox') {
            return (bool) $value;
        }

        if (in_array($type, ['numeric', 'price'], true)) {
            return $this->number($value);
        }

        if (is_bool($value)) {
            return $value ? 'Да' : 'Нет';
        }

        return is_scalar($value) ? trim((string) $value) : null;
    }

    private function mappingRows(mixed $rows): array
    {
        if (is_string($rows)) {
            $rows = json_decode($rows, true);
        }

        return collect(is_array($rows) ? $rows : [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values()
            ->all();
    }

    private function number(mixed $value): int|float|null
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));

        if (! is_numeric($value)) {
            return null;
        }

        return str_contains($value, '.') ? (float) $value : (int) $value;
    }
}
