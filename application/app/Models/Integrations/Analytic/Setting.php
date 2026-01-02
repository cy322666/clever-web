<?php

namespace App\Models\Integrations\Analytic;

use App\Filament\Resources\AnalyticResource;
use App\Filament\Resources\Integrations\BizonResource;
use App\Helpers\Traits\SettingRelation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory, SettingRelation;

    public static array $cost = [
        '6_month'  => '25.000 р',
        '12_month' => '50.000 р',
    ];

    protected $fillable = [
        'settings',
        'active',
        'user_id',
        'driver',
        'host',
        'database',
        'login',
        'password',
        'port',
    ];

    protected $table = 'analytic_settings';

    public static string $resource = AnalyticResource::class;

    public static string $description = "Готовая аналитика на базе amoCRM...";
}
