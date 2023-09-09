<?php

namespace App\Models\Integrations\ActiveLead;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    protected $table = 'active_leads';

    protected $fillable = [
        'pipeline_id',
        'contact_id',
        'lead_id',
        'is_active',
        'status',
        'count_leads',
        'user_id',
    ];
}
