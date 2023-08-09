<?php

namespace App\Models\Integrations\Alfa;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeadSource extends Model
{
    use HasFactory;

    protected $table = 'alfacrm_lead_sources';

    protected $fillable = [
        'user_id',
        'code',
        'name',
        'is_enabled',
        'source_id',
    ];
}
