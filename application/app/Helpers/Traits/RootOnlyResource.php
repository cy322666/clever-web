<?php

namespace App\Helpers\Traits;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

trait RootOnlyResource
{
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        if (!auth()->check() || !(bool) auth()->user()?->is_root) {
            return Response::deny();
        }

        return parent::getAuthorizationResponse($action, $record);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return auth()->check() && (bool) auth()->user()?->is_root
            ? $query
            : $query->whereRaw('1 = 0');
    }
}
