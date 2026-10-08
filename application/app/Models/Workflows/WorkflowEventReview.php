<?php

namespace App\Models\Workflows;

use Illuminate\Database\Eloquent\Model;

class WorkflowEventReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['envelope' => 'encrypted:array'];
    }
}
