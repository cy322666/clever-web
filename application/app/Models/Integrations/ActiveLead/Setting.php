<?php

namespace App\Models\Integrations\ActiveLead;

use App\Filament\Resources\Integrations\ActiveLeadResource;
use App\Helpers\Traits\SettingRelation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    protected $table = 'active_lead_settings';

    public static string $resource = ActiveLeadResource::class;

    public static string $description = "Проверка открытых сделок на контакте созданной сделки. Возможность задавать условия проверки...";

    const CONDITION_PIPELINE = 0;
    const CONDITION_ALL = 1;

    public static array $cost = [
        '1_month'  => 'бесплатно',
        '6_month'  => 'бесплатно',
        '12_month' => 'бесплатно',
    ];

    protected $fillable = [
        'user_id',
        'condition',
        'pipeline_id',
        'tag',
    ];
}
