<?php

namespace App\Models\Integrations\Bizon;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Form extends Model
{
    use HasFactory;

    protected $table = 'bizon_forms';

    protected $fillable = [
        'email',
        'phone',
        'name',
        'status',
        'lead_id',
        'contact_id',
        'user_id',
    ];
}
