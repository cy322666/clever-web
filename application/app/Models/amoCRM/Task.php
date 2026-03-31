<?php

namespace App\Models\amoCRM;

use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory;

    protected $table = 'amocrm_tasks';

    protected $fillable = [
        'user_id',
        'account_id',
        'amocrm_staff_id',
        'amo_id',
        'entity_type',
        'entity_id',
        'responsible_user_id',
        'type_id',
        'text',
        'complete_till',
        'is_completed',
        'amo_created_at',
        'amo_updated_at',
        'completed_at',
        'payload',
    ];

    protected $casts = [
        'complete_till' => 'datetime',
        'amo_created_at' => 'datetime',
        'amo_updated_at' => 'datetime',
        'completed_at' => 'datetime',
        'is_completed' => 'boolean',
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'amocrm_staff_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'task_id');
    }
}
