<?php

namespace App\Models\Workflows;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;
use Leek\FilamentWorkflows\Models\WorkflowRunStep;
use Leek\FilamentWorkflows\Models\WorkflowRun as BaseWorkflowRun;

class WorkflowRun extends BaseWorkflowRun
{
    public function entityLinks(): HasMany
    {
        return $this->hasMany(WorkflowRunEntity::class, 'workflow_run_id');
    }

    public function latestStep(): HasOne
    {
        /** @var class-string<WorkflowRunStep> $modelClass */
        $modelClass = config('filament-workflows.models.workflow_run_step', WorkflowRunStep::class);

        return $this->hasOne($modelClass, 'workflow_run_id')->latestOfMany();
    }

    protected static function getCurrentTenantId(): int|string|null
    {
        return Auth::id();
    }
}
