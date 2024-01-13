<?php

namespace App\Models\Integrations\Distribution;

use App\Models\User;
use App\Services\Distribution\Strategies\BaseStrategy;
use App\Services\Distribution\Strategies\RotationStrategy;
use App\Services\Distribution\Strategies\ScheduleStrategy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            Setting::STRATEGY_SCHEDULE => new ScheduleStrategy(),
            Setting::STRATEGY_ROTATION => new RotationStrategy(),
            Setting::STRATEGY_RANDOM   => new RotationStrategy(),//
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
