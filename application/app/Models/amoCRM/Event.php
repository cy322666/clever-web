<?php

namespace App\Models\amoCRM;

use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    use HasFactory;

    protected $table = 'amocrm_events';

    protected $fillable = [
        'user_id',
        'account_id',
        'lead_id',
        'task_id',
        'entity_type',
        'entity_amo_id',
        'event_type',
        'event_key',
        'event_at',
        'from_pipeline_id',
        'to_pipeline_id',
        'from_status_id',
        'to_status_id',
        'from_responsible_user_id',
        'to_responsible_user_id',
        'meta',
    ];

    protected $casts = [
        'event_at' => 'datetime',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }
}
