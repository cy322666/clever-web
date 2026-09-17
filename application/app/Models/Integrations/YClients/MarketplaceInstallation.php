<?php

namespace App\Models\Integrations\YClients;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceInstallation extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FROZEN = 'frozen';
    public const STATUS_UNINSTALLED = 'uninstalled';

    protected $table = 'yclients_marketplace_installations';

    protected $fillable = [
        'user_id',
        'setting_id',
        'salon_id',
        'application_id',
        'status',
        'connected_at',
        'disconnected_at',
        'last_payload',
        'last_error',
    ];

    protected $casts = [
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'last_payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class, 'setting_id');
    }
}
