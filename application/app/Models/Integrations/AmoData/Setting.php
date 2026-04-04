<?php

namespace App\Models\Integrations\AmoData;

use App\Filament\Resources\Integrations\AmoDataResource;
use App\Helpers\Traits\SettingRelation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    public static string $resource = AmoDataResource::class;

    public static string $description = 'Локальный сбор сделок, задач и событий amoCRM для будущей аналитики и ИИ.';

    public static array $instruction = [
        'Модуль периодически забирает сделки и задачи из amoCRM и сохраняет локальные данные-срезы.',
        'Справочники amoCRM не дублируются: используются существующие статусы, сотрудники и поля.',
        'История изменений строится через сравнение локального среза и нового состояния из amoCRM.',
        'На текущем этапе не синхронизируются вебхуки, звонки, переписки, примечания и значения кастомных полей.',
    ];

    protected $table = 'amo_data_settings';

    protected $fillable = [
        'user_id',
        'active',
        'settings',
        'sync_status',
        'initial_synced_at',
        'last_attempt_at',
        'last_successful_sync_at',
        'leads_synced_at',
        'tasks_synced_at',
        'last_leads_count',
        'last_tasks_count',
        'last_events_count',
        'last_error',
    ];

    protected $casts = [
        'active' => 'boolean',
        'settings' => 'array',
        'initial_synced_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'last_successful_sync_at' => 'datetime',
        'leads_synced_at' => 'datetime',
        'tasks_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $setting) {
            $setting->settings = array_merge([
                'sync_interval_minutes' => 30,
                'sync_deals' => true,
                'sync_tasks' => true,
                'store_payloads' => false,
            ], $setting->settings ?? []);
        });
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SyncRun::class, 'setting_id');
    }

    public function syncIntervalMinutes(): int
    {
        return max((int)data_get($this->settings, 'sync_interval_minutes', 30), 15);
    }
}
