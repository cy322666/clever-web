<?php

namespace App\Models\Integrations\Alfa;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Orchid\Screen\AsSource;

class LeadStatus extends Model
{
    protected $table = 'alfacrm_lead_statuses';

    protected $fillable = [
        'account_id',
        'is_enabled',
        'status_id',
        'name',
    ];
}
