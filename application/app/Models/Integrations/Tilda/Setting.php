<?php

namespace App\Models\Integrations\Tilda;

use App\Filament\Resources\Integrations\TildaResource;
use App\Helpers\Traits\SettingRelation;
use App\Models\App;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'tilda_settings';

    public static string $resource = TildaResource::class;

    public static string $description = "Интеграция нескольких форм/сайтов/настроек. Поиск и склейка дублей контакта. Возможность склеивать сделки...";

    public static array $cost = [
        '1_month'  => '1.000 р',
        '6_month'  => '10.000 р',
        '12_month' => '20.000 р',
    ];

    protected $fillable = [
        'bodies',
        'settings',
        'active',
        'user_id',
        'name',
        'email',
        'phone',
        'utms',
    ];

}
