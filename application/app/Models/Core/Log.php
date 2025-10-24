<?php

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    use HasFactory;

    protected $fillable = [
        'request',
        'user_id',
        'app_id',
        'app_name',
        'route',
        'app_is_active',
    ];
}
