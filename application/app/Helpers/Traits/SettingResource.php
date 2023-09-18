<?php

namespace App\Helpers\Traits;

use App\Models\App;
use App\Models\User;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @method hasOne(string $class, string $string, string $string1)
 * @method belongsTo(string $class, string $string, string $string1)
 */
trait SettingResource
{
    public static function getRecordTitle(?Model $record = null): string|Htmlable|null
    {
        return static::$recordTitleAttribute;
    }
}
