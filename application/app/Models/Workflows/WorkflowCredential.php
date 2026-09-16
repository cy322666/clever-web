<?php

namespace App\Models\Workflows;

use Illuminate\Database\Eloquent\Model;

class WorkflowCredential extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['secret'];
    protected $casts = ['secret' => 'encrypted'];
}
