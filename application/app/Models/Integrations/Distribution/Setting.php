<?php

namespace App\Models\Integrations\Distribution;

use App\Filament\Resources\Integrations\DistributionResource;
use App\Helpers\Traits\SettingRelation;
use App\Models\amoCRM\Staff;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'distribution_settings';

    public static string $resource = DistributionResource::class;

    public static string $description = "Распределение сделок по графику или по очереди...";

    public static array $instruction = [
        'Создать одну настройку ниже и Сохранить',
        'Скопировать url из поля Вебхук',
        'Вставить ее на стороне amoCRM в нужно этапе воронки',
        'Выбрать тип распределения и другие настройки',
        'Настроить график если нужно',
        'Сохранить настройки, либо добавить еще одну настройку',
    ];

    public const STRATEGY_SCHEDULE = 'schedule';
    public const STRATEGY_ROTATION = 'rotation';
    public const STRATEGY_RANDOM = 'random';

    public static array $cost = [
        '1_month' => '2 990 ₽',
        '6_month' => '14 900 ₽',
        '12_month' => '24 900 ₽',
    ];

    protected $fillable = [
        'settings',
        'cursors',
        'active',
        'user_id',
    ];
}
