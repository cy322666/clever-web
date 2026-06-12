<?php

namespace App\Models\Integrations\YClients;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponsibleMapping extends Model
{
    protected $table = 'yclients_responsible_mappings';

    protected $fillable = [
        'setting_id',
        'company_id',
        'company_name',
        'yc_user_id',
        'yc_user_name',
        'amo_user_id',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }
}
