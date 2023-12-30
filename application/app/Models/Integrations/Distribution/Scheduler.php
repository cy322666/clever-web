<?php

namespace App\Models\Integrations\Distribution;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Scheduler extends Model
{
    use HasFactory;

    protected $table = 'distribution_schedulers';

    protected $fillable = [
        'settings',
        'user_id',
        'staff_id',
    ];
}
