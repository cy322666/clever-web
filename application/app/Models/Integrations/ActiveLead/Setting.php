<?php

namespace App\Models\Integrations\ActiveLead;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $table = 'active_lead_settings';

    protected $fillable = [
        'user_id',
        'check_pipeline',
        'tag_pipeline',
        'tag_all',
    ];
}
