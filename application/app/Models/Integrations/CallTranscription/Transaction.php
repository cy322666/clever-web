<?php

namespace App\Models\Integrations\CallTranscription;

use App\Models\Integrations\Distribution\Setting;
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

    protected $table = 'call_transactions';

    protected $fillable = [
        'lead_id',
        'contact_id',
        'duration',
        'note_type',
        'setting_id',
        'user_id',
        'form_setting_id',
        'account_id',
        'call_status',
        'url',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
