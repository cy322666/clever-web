<?php

namespace App\Models\Clever;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $table = 'clever_companies';

    protected $fillable = [
        'company_id',
        'name',
    ];
}
