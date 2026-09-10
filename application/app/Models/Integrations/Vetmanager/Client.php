<?php

namespace App\Models\Integrations\Vetmanager;

use App\Models\Core\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Client extends Model
{
    protected $table = 'vetmanager_clients';

    protected $fillable = [
        'external_id',
        'contact_id',
        'name',
        'phone',
        'email',
        'payload',
        'synced_at',
        'user_id',
        'account_id',
        'setting_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'synced_at' => 'datetime',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
