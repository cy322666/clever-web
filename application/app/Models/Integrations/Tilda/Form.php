<?php

namespace App\Models\Integrations\Tilda;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Form extends Model
{
    use HasFactory;

    protected $table = 'tilda_forms';

    protected $fillable = [
        'body',
        'status',
        'lead_id',
        'contact_id',
        'user_id',
    ];
}
