<?php

namespace App\Models\Integrations\Marquiz;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Form extends Model
{
    use HasFactory;

    protected $table = 'marquiz_forms';

    protected $fillable = [
        'lead_id',
        'user_id',
        'contact_id',
        'body',
        'quiz',
        'name',
        'status',
    ];
}
