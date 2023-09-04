<?php

namespace App\Models\Integrations\Tilda;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $table = 'tilda_settings';

    protected $fillable = [
        'bodies',
        'settings',
        'active',
        'user_id',
        'name',
        'email',
        'phone',
    ];
}
