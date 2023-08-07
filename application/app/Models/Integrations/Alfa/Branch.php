<?php

namespace App\Models\Integrations\Alfa;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $table = 'alfacrm_branches';

    protected $fillable = [
        'account_id',
        'branch_id',
        'name',
        'is_active',
        'weight',
        'subject_ids',
    ];
}
