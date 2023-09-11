<?php

namespace App\Models\Integrations\ActiveLead;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $table = 'active_lead_settings';

    const CONDITION_PIPELINE = 0;
    const CONDITION_ALL = 1;

    protected $fillable = [
        'user_id',
        'condition',
        'pipeline_id',
        'tag',
    ];
}
