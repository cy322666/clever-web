<?php

namespace App\Models\Integrations\Vetmanager;

use App\Filament\Resources\Integrations\Vetmanager\VetmanagerResource;
use App\Helpers\Traits\SettingRelation;
use App\Models\Core\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Setting extends Model
{
    use SettingRelation;

    public const WEBHOOK_EVENTS = [
        'admissionAccepted',
        'admissionInvoicesSumChanged',
    ];

    public static string $resource = VetmanagerResource::class;

    public static array $cost = [
        '1_month' => '2 990 ₽',
        '6_month' => '14 900 ₽',
        '12_month' => '24 900 ₽',
    ];

    protected $table = 'vetmanager_settings';

    protected $fillable = [
        'active',
        'base_url',
        'api_key',
        'timezone',
        'webhook_secret',
        'webhook_id',
        'webhook_synced_at',
        'target_status',
        'responsible_user_id',
        'sync_price',
        'contact_external_id_field_id',
        'lead_external_id_field_id',
        'lead_admission_date_field_id',
        'lead_patient_name_field_id',
        'lead_doctor_name_field_id',
        'lead_description_field_id',
        'user_id',
        'account_id',
    ];

    protected $hidden = [
        'api_key',
        'webhook_secret',
    ];

    protected $casts = [
        'active' => 'boolean',
        'api_key' => 'encrypted',
        'webhook_secret' => 'encrypted',
        'sync_price' => 'boolean',
        'webhook_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $setting): void {
            if (blank($setting->webhook_secret)) {
                $setting->webhook_secret = Str::random(48);
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function webhookUrl(): string
    {
        $user = $this->user()->first();

        return $user
            ? route('vetmanager.hook', ['user' => $user->uuid])
            : '';
    }

    /**
     * @return array{pipeline_id: int, status_id: int}|null
     */
    public function targetStatusIds(): ?array
    {
        if (! is_string($this->target_status) || ! str_contains($this->target_status, '.')) {
            return null;
        }

        [$pipelineId, $statusId] = array_map('intval', explode('.', $this->target_status, 2));

        if ($pipelineId <= 0 || $statusId <= 0) {
            return null;
        }

        return [
            'pipeline_id' => $pipelineId,
            'status_id' => $statusId,
        ];
    }
}
