<?php

namespace App\Models\Integrations\AmoData;

use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRun extends Model
{
    use HasFactory;

    protected $table = 'amocrm_sync_runs';

    protected $fillable = [
        'setting_id',
        'user_id',
        'account_id',
        'type',
        'status',
        'started_at',
        'finished_at',
        'leads_synced',
        'tasks_synced',
        'events_created',
        'error',
        'meta',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'meta' => 'array',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
