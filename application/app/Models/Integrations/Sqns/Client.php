<?php

namespace App\Models\Integrations\Sqns;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $table = 'sqns_clients';

    protected $fillable = [
        'user_id',
        'account_id',
        'setting_id',
        'client_id',
        'contact_id',
        'name',
        'phone',
        'additional_phone',
        'email',
        'birth_date',
        'sex',
        'visits_count',
        'total_arrival',
        'tags',
        'body',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'tags' => 'array',
        'body' => 'array',
    ];
}
