<?php

namespace App\Models\amoCRM;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Field extends Model
{
    use HasFactory;

    protected $table = 'amocrm_fields';

    protected $fillable = [
        'field_id',
        'name',
        'type',
        'code',
        'sort',
        'is_api_only',
        'entity_type',
        'enums',
        'user_id',
    ];

    public static function getAllFields(): Builder
    {
        return Field::query()->where('user_id', Auth::id());
    }

    public static function getLeadFields(): Builder
    {
        return Field::query()
            ->where('user_id', Auth::id())
            ->where('entity_type', 'leads');
    }

    public static function getLeadSelectFields()
    {
        return Field::query()
            ->where('user_id', Auth::id())
            ->where('entity_type', 'leads')
            ->pluck('name', 'id');
    }

    public static function getContactFields(): Builder
    {
        return Field::query()
            ->where('user_id', Auth::id())
            ->where('entity_type', 'contacts');
    }

    public static function getContactSelectFields()
    {
        return Field::query()
            ->where('user_id', Auth::id())
            ->where('entity_type', 'contacts')
            ->pluck('name', 'field_id');
    }

    public static function getCompanyFields(): Builder
    {
        return Field::query()
            ->where('user_id', Auth::id())
            ->where('entity_type', 'companies');
    }
}
