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

    private const FIELD_MAPPING_DEFINITIONS = [
        'contacts' => [
            'client_id' => [
                'attribute' => 'contact_external_id_field_id',
                'label' => 'ID клиента Vetmanager',
                'types' => ['text', 'numeric'],
            ],
        ],
        'leads' => [
            'admission_id' => [
                'attribute' => 'lead_external_id_field_id',
                'label' => 'ID приема Vetmanager',
                'types' => ['text', 'numeric'],
            ],
            'admission_date' => [
                'attribute' => 'lead_admission_date_field_id',
                'label' => 'Дата приема',
                'types' => ['date', 'date_time'],
            ],
            'patient_name' => [
                'attribute' => 'lead_patient_name_field_id',
                'label' => 'Питомец',
                'types' => ['text', 'textarea'],
            ],
            'doctor_name' => [
                'attribute' => 'lead_doctor_name_field_id',
                'label' => 'Врач',
                'types' => ['text', 'textarea'],
            ],
            'description' => [
                'attribute' => 'lead_description_field_id',
                'label' => 'Описание приема',
                'types' => ['text', 'textarea'],
            ],
        ],
    ];

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

    /**
     * @return array<string, string>
     */
    public static function sourceFieldOptions(string $entityType): array
    {
        $options = [];

        foreach (self::FIELD_MAPPING_DEFINITIONS[$entityType] ?? [] as $source => $definition) {
            $options[$source] = $definition['label'];
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function sourceFieldTypes(string $entityType, ?string $source): array
    {
        return self::FIELD_MAPPING_DEFINITIONS[$entityType][$source]['types'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, array{field_vetmanager: string, field_amo: string}>
     */
    public static function fieldMappingRows(array $attributes, string $entityType): array
    {
        $rows = [];

        foreach (self::FIELD_MAPPING_DEFINITIONS[$entityType] ?? [] as $source => $definition) {
            $target = $attributes[$definition['attribute']] ?? null;

            if (blank($target)) {
                continue;
            }

            $rows[] = [
                'field_vetmanager' => $source,
                'field_amo' => (string) $target,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, int|null>
     */
    public static function fieldMappingAttributes(mixed $rows, string $entityType): array
    {
        $definitions = self::FIELD_MAPPING_DEFINITIONS[$entityType] ?? [];
        $attributes = [];

        foreach ($definitions as $definition) {
            $attributes[$definition['attribute']] = null;
        }

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $source = (string) ($row['field_vetmanager'] ?? '');
            $target = filter_var($row['field_amo'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

            if (! isset($definitions[$source]) || $target === false) {
                continue;
            }

            $attributes[$definitions[$source]['attribute']] = (int) $target;
        }

        return $attributes;
    }
}
