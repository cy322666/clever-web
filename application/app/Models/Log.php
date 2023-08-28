<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    use HasFactory;

    protected $fillable = [
        'data',
        'url',
        'code',
        'start',
        'end',
        'method',
        'error',
        'details',
        'user_id',
    ];
}
