<?php

namespace App\Models\Core;

use Illuminate\Database\Eloquent\Model;

class ApiRequest extends Model
{
    public $timestamps = false;

    protected $table = 'api_requests';

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
        'query_params' => 'array',
        'payload' => 'array',
    ];
}
