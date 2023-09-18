<?php

namespace App\Models\Integrations\Dadata;

use App\Filament\Resources\Integrations\DadataResource;
use App\Helpers\Traits\SettingRelation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'data_settings';

    public static string $resource = DadataResource::class;

    public static string $description = "Интеграция нескольких форм/сайтов/настроек. Поиск и склейка дублей контакта. Возможность склеивать сделки...";

    public static array $cost = [
        '1_month'  => 'бесплатно',
        '6_month'  => 'бесплатно',
        '12_month' => 'бесплатно',
    ];

    protected $fillable = [
        'field_country',
        'field_city',
        'field_timezone',
        'field_region',
        'field_provider',
        'active',
        'user_id',
    ];

}
