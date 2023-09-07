<?php

namespace App\Models\Integrations\Tilda;

use App\Models\App;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Setting extends Model
{
    use HasFactory;

    protected $table = 'tilda_settings';

    protected $fillable = [
        'bodies',
        'settings',
        'active',
        'user_id',
        'name',
        'email',
        'phone',
    ];

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'id','setting_id')
            ->where('user_id', $this->user_id);
    }
}
