<?php

namespace App\Models\Workflows;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Leek\FilamentWorkflows\Models\WorkflowRun as BaseWorkflowRun;
use Leek\FilamentWorkflows\Models\WorkflowRunStep;

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
        return \App\Services\Workflows\WorkflowAdminAccess::tenantId();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }
}
