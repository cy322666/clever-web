<?php

namespace App\Models\Integrations\CallTranscription;

use App\Filament\Resources\Integrations\CallTranscriptionResource;
use App\Helpers\Traits\SettingRelation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'call_transcription_settings';

    public static string $resource = CallTranscriptionResource::class;

    public static string $description = 'Транскрибируйте звонки в amoCRM, применяйте промпт и сохраняйте результат в поле или примечании с возможностью запуска Salesbot.';

    public static array $instruction = [
        'Создайте одну или несколько настроек ниже и сохраните',
        'Скопируйте ссылку вебхука для нужной настройки',
        'Подключите ссылку в виджете amoCRM',
        'Сделайте тестовый звонок или отправьте тестовый запрос',
        'Проверьте результат в поле/примечании сделки',
    ];

    protected $fillable = [
        'settings',
        'active',
        'user_id',
    ];
}
