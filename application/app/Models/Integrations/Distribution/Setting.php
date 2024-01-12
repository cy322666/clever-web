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

    public static string $description = "Распределение...";

    public const STRATEGY_SCHEDULE = 'schedule';
    public const STRATEGY_ROTATION = 'rotation';
    public const STRATEGY_RANDOM = 'random';

    public static array $cost = [
        '1_month'  => '1.000 р',
        '6_month'  => '5.000 р',
        '12_month' => '10.000 р',
    ];

    protected $fillable = [
        'settings',
        'active',
        'user_id',
    ];
}
