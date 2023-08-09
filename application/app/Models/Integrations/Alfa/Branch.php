<?php

namespace App\Models\Integrations\Alfa;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Branch extends Model
{
    use HasFactory;

    protected $table = 'alfacrm_branches';

    protected $fillable = [
        'user_id',
        'branch_id',
        'name',
        'is_active',
        'weight',
        'subject_ids',
    ];

    public static function getWithUser(): Builder
    {
        return Branch::query()->where('user_id', Auth::id());
    }
}
