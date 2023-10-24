<?php

namespace App\Models\Integrations\GetCourse;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Form extends Model
{
    use HasFactory;

    const STATUS_WAIT = 0;
    const STATUS_OK   = 1;
    const STATUS_FAIL = 2;

    protected $table = 'getcourse_forms';

    protected $fillable = [
        'email',
        'phone',
        'name',
        'status',
        'user_id',
        'lead_id',
        'contact_id',
        'utm_medium',
        'utm_content',
        'utm_source',
        'utm_term',
        'utm_campaign',
        'user_id',
        'form',
    ];
}
