<?php

namespace App\Models\Integrations\Sqns;

use App\Models\Core\Account;
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
}
