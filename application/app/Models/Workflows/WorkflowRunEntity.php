<?php

namespace App\Models\Workflows;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Leek\FilamentWorkflows\Models\WorkflowRunStep;

class WorkflowRunEntity extends Model
{
    protected $fillable = [
        'user_id',
        'workflow_id',
        'workflow_run_id',
        'workflow_run_step_id',
        'entity_type',
        'entity_id',
        'source',
        'url',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'workflow_id' => 'integer',
            'workflow_run_id' => 'integer',
            'workflow_run_step_id' => 'integer',
            'entity_id' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'workflow_run_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowRunStep::class, 'workflow_run_step_id');
    }
}
