<?php

namespace App\Models\Integrations\Assistant;

use App\Filament\Resources\Integrations\AssistantResource;
use App\Helpers\Traits\SettingRelation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    public static string $resource = AssistantResource::class;
    public static string $description = 'AI ассистент руководителя по данным amoCRM: analytics tool endpoint-ы, настройки интеграции и логи взаимодействий.';
    public static array $instruction = [
        'Подключите amoCRM и выполните синхронизацию справочников',
        'Сохраните настройки Assistant и скопируйте service token',
        'Подключите n8n к endpoint-ам модуля через заголовок X-Assistant-Token',
        'Используйте summary payload-ы как source of truth для LLM-сценариев',
        'Храните conversation state и prompt reconstruction на стороне n8n',
        'При необходимости сохраняйте traces обратно в logs endpoint',
    ];
    protected $table = 'assistant_settings';
    protected $fillable = [
        'settings',
        'active',
        'service_token',
        'user_id',
    ];

    protected $casts = [
        'active' => 'boolean',
        'settings' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Setting $setting) {
            if (!$setting->service_token) {
                $setting->service_token = Str::random(60);
            }
        });
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AssistantLog::class, 'assistant_setting_id');
    }
}
