<?php

namespace App\Models\Integrations\YClients;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class YClientsUser extends Model
{
    protected $table = 'yclients_users';

    protected $fillable = [
        'setting_id',
        'company_id',
        'company_name',
        'yc_user_id',
        'yc_user_name',
    ];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    public function key(): string
    {
        return $this->company_id . ':' . $this->yc_user_id;
    }

    public function label(): string
    {
        return ($this->company_name ?: $this->company_id)
            . ' — '
            . ($this->yc_user_name ?: $this->yc_user_id);
    }
}
