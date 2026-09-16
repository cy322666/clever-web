<?php

namespace App\Models\Workflows;

use Illuminate\Database\Eloquent\Model;

class WorkflowFolder extends Model
{
    protected $fillable = ['user_id', 'name'];
}
