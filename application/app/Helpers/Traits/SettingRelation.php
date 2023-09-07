<?php

namespace App\Helpers\Traits;

use App\Models\App;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @method hasOne(string $class, string $string, string $string1)
 * @method belongsTo(string $class, string $string, string $string1)
 */
trait SettingRelation
{
    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class, 'id','setting_id')
            ->where('user_id', $this->user_id)
            ->where('resource_name', static::$resource);
    }
}
