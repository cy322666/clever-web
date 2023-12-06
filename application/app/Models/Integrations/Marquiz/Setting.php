<?php

namespace App\Models\Integrations\Marquiz;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $table = 'marquiz_settings';

    protected $fillable = [
        'user_id',
        'settings',
        'active',
    ];
}
