<?php

namespace App\Helpers\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait TenantResource
{
    public static function getEloquentQuery(): Builder
    {
        return static::$model::query()->where('user_id', Auth::id());
    }
}
