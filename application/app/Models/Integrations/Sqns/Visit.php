<?php

namespace App\Models\Integrations\Sqns;

use App\Models\Core\Account;
use App\Services\Sqns\VisitPayload;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Visit extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $table = 'sqns_visits';

    protected $fillable = [
        'user_id',
        'account_id',
        'setting_id',
        'visit_id',
        'client_id',
        'resource_id',
        'organization_id',
        'organization_name',
        'lead_id',
        'datetime',
        'cost',
        'attendance',
        'deleted',
        'online',
        'is_paid',
        'author',
        'services',
        'comment',
        'source_created_at',
        'source_updated_at',
        'status',
        'error_message',
        'body',
    ];

    protected $casts = [
        'datetime' => 'datetime',
        'source_created_at' => 'datetime',
        'source_updated_at' => 'datetime',
        'deleted' => 'boolean',
        'online' => 'boolean',
        'is_paid' => 'boolean',
        'body' => 'array',
    ];

    /**
     * Сохраняет визит и данные клиента из SQNS, обновляя запись по ID визита.
     */
    public static function saveFromSqns(Setting $setting, Account $account, array $payload): self
    {
        $visitId = VisitPayload::id($payload);

        if (! $visitId) {
            throw new \InvalidArgumentException('SQNS visit id is missing.');
        }

        $visit = self::query()->firstOrCreate(
            [
                'setting_id' => $setting->id,
                'visit_id' => $visitId,
            ],
            [
                'user_id' => $setting->user_id,
                'account_id' => $account->id,
                'status' => self::STATUS_PENDING,
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
            self::upsertClient($setting, $account, (int) $clientId, $clientData);
        }

        $organizationId = data_get($payload, 'organization.id');
        $organizationName = data_get($payload, 'organization.name');

        if ($organizationId && (! $setting->organization_id || ! $setting->organization_name)) {
            $setting->forceFill([
                'organization_id' => $setting->organization_id ?: $organizationId,
                'organization_name' => $setting->organization_name ?: $organizationName,
            ])->save();
        }

        $visit->fill(self::withoutEmptyValues([
            'user_id' => $setting->user_id,
            'account_id' => $account->id,
            'client_id' => is_numeric($clientId) ? (int) $clientId : null,
            'resource_id' => data_get($payload, 'resourceId'),
            'organization_id' => $organizationId,
            'organization_name' => $organizationName,
            'datetime' => self::payloadDate(data_get($payload, 'datetime')),
            'cost' => self::payloadCost($payload),
            'attendance' => data_get($payload, 'attendance'),
            'deleted' => self::payloadBoolean(data_get($payload, 'deleted')),
            'online' => self::payloadBoolean(data_get($payload, 'online')),
            'is_paid' => self::payloadBoolean(data_get($payload, 'isPaid')),
            'author' => data_get($payload, 'author'),
            'services' => self::payloadServices($payload),
            'comment' => data_get($payload, 'comment'),
            'source_created_at' => self::payloadDate(data_get($payload, 'create_date')),
            'source_updated_at' => self::payloadDate(data_get($payload, 'update_date')),
        ]));
        $visit->status = self::STATUS_PENDING;
        $visit->error_message = null;
        $visit->body = VisitPayload::merge(
            $storedPayload,
            $payload,
        );
        $visit->save();

        return $visit;
    }

    public function eventLabel(): string
    {
        if ($this->deleted) {
            return 'Запись удалена';
        }

        return match ((int) $this->attendance) {
            -1 => 'Клиент отменил',
            0 => 'Клиент записан',
            1 => 'Клиент пришел',
            2 => 'Клиент подтвердил',
            default => 'Статус не указан',
        };
    }

    public function amoStatus(Setting $setting): object
    {
        $mapping = $this->deleted
            ? $setting->status_id_delete
            : match ((int) $this->attendance) {
                -1 => $setting->status_id_cancel,
                0 => $setting->status_id_wait,
                1 => $setting->status_id_came,
                2 => $setting->status_id_confirm,
                default => null,
            };

        $parts = is_string($mapping) ? explode('.', $mapping, 2) : [];

        return (object) [
            'pipeline_id' => $parts[0] ?? null,
            'status_id' => $parts[1] ?? null,
        ];
    }

    public function scopedClient(): ?Client
    {
        if (! $this->client_id) {
            return null;
        }

        return Client::query()
            ->where('client_id', $this->client_id)
            ->where('setting_id', $this->setting_id)
            ->where('account_id', $this->account_id)
            ->where('user_id', $this->user_id)
            ->first();
    }

    public function leadOwnerVisit(): ?self
    {
        if (! $this->lead_id) {
            return null;
        }

        return static::query()
            ->where('account_id', $this->account_id)
            ->where('lead_id', $this->lead_id)
            ->orderBy('id')
            ->first();
    }

    public function isLeadOwnedByAnotherVisit(): bool
    {
        $owner = $this->leadOwnerVisit();

        return $owner !== null && (string) $owner->visit_id !== (string) $this->visit_id;
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('status', self::STATUS_FAILED)->orWhereNotNull('error_message');
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    private static function upsertClient(Setting $setting, Account $account, int $clientId, array $data): void
    {
        $client = Client::query()->firstOrCreate(
            [
                'setting_id' => $setting->id,
                'client_id' => $clientId,
            ],
            [
                'user_id' => $setting->user_id,
                'account_id' => $account->id,
            ],
        );

        $client->fill(self::withoutEmptyValues([
            'user_id' => $setting->user_id,
            'account_id' => $account->id,
            'name' => self::clientName($data),
            'phone' => data_get($data, 'phone'),
            'additional_phone' => data_get($data, 'additionalPhone'),
            'email' => data_get($data, 'email'),
            'birth_date' => self::payloadDate(data_get($data, 'birthDate'))?->toDateString(),
            'sex' => data_get($data, 'sex'),
            'visits_count' => data_get($data, 'visitsCount'),
            'total_arrival' => self::payloadNumber(data_get($data, 'totalArrival')),
            'tags' => is_array(data_get($data, 'tags')) ? data_get($data, 'tags') : null,
        ]));
        $client->body = VisitPayload::merge(
            is_array($client->body) ? $client->body : [],
            $data,
        );
        $client->save();
    }

    private static function clientName(array $data): ?string
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

    private static function payloadServices(array $payload): ?string
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

    private static function payloadCost(array $payload): ?float
    {
        foreach (['totalCost', 'totalPrice'] as $key) {
            $value = self::payloadNumber(data_get($payload, $key));

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
            $price = self::payloadNumber(data_get($service, 'paySum'))
                ?? self::payloadNumber(data_get($service, 'price'));

            if ($price === null) {
                continue;
            }

            $amount = self::payloadNumber(data_get($service, 'amount')) ?? 1;
            $sum += $price * $amount;
            $found = true;
        }

        return $found ? $sum : null;
    }

    private static function payloadNumber(mixed $value): ?float
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], trim((string) $value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private static function payloadBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function payloadDate(mixed $value): ?Carbon
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

    private static function withoutEmptyValues(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
