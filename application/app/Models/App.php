<?php

namespace App\Models;

use App\Models\Core\Account;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class App extends Model
{
    use HasFactory;

    const STATE_CREATED  = 0;
    const STATE_INACTIVE = 1;
    const STATE_ACTIVE   = 2;

    const STATE_CREATED_WORD  = 'Не настроено';
    const STATE_INACTIVE_WORD = 'Не активно';
    const STATE_ACTIVE_WORD   = 'Активно';

    protected $fillable = [
        'resource_name',
        'setting_id',
        'user_id',
        'name',
        'expires_tariff_at',
        'status',
    ];

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function setting(): HasOne
    {
//        $modelName = $this->resource_name::$model;
//
//        return $this->hasOne($modelName::class, 'id', 'setting_id');
    }
}
