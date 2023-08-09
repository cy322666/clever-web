<?php

namespace App\Models\Integrations\Alfa;

use App\Models\amoCRM\Staff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class LeadStatus extends Model
{
    protected $table = 'alfacrm_lead_statuses';

    protected $fillable = [
        'user_id',
        'is_enabled',
        'status_id',
        'name',
    ];

    public static function getWithUser(): Builder
    {
        return LeadStatus::query()->where('user_id', Auth::id());
    }
}
