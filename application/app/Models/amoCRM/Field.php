<?php

namespace App\Models\amoCRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
