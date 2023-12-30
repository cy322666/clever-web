<?php

namespace App\Models\Integrations\Distribution;

use App\Services\Distribution\BaseStrategy;
use App\Services\Distribution\RotationStrategy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $table = 'distribution_transactions';

    protected $fillable = [
        'body',
        'status',
        'lead_id',
        'contact_id',
        'type',
        'distribution_setting_id',
        'user_id',
        'staff_id',
        'staff_name',
        'staff_amocrm_id',
        'schedule',
        'template',
    ];

    public function matchStrategy() : BaseStrategy
    {
        return match ($this->type) {
            Setting::STRATEGY_SCHEDULE => 'График',
            Setting::STRATEGY_ROTATION => new RotationStrategy(),
            Setting::STRATEGY_RANDOM   => 'Равномерно вразброс',
        };
    }
}
