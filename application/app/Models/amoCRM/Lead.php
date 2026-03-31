<?php

namespace App\Models\amoCRM;

use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasFactory;

    protected $table = 'amocrm_leads';

    protected $fillable = [
        'user_id',
        'account_id',
        'amocrm_status_id',
        'amocrm_staff_id',
        'amo_id',
        'name',
        'pipeline_id',
        'status_id',
        'responsible_user_id',
        'price',
        'amo_created_at',
        'amo_updated_at',
        'closed_at',
        'is_closed',
        'is_won',
        'is_lost',
        'payload',
    ];

    protected $casts = [
        'amo_created_at' => 'datetime',
        'amo_updated_at' => 'datetime',
        'closed_at' => 'datetime',
        'is_closed' => 'boolean',
        'is_won' => 'boolean',
        'is_lost' => 'boolean',
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

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'amocrm_status_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'amocrm_staff_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'lead_id');
    }
}
