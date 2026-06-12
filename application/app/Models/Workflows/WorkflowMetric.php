<?php

namespace App\Models\Workflows;

use Illuminate\Support\Facades\Auth;
use Leek\FilamentWorkflows\Models\WorkflowMetric as BaseWorkflowMetric;

class WorkflowMetric extends BaseWorkflowMetric
{
    protected static function booted(): void
    {
        static::creating(function (self $metric): void {
            $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

            if ($metric->getAttribute($tenantColumn) !== null) {
                return;
            }

            $workflowId = $metric->getAttribute('workflow_id');

            if ($workflowId === null) {
                return;
            }

            $workflowClass = config('filament-workflows.models.workflow', Workflow::class);
            $tenantId = $workflowClass::query()
                ->withoutGlobalScope('tenant')
                ->whereKey($workflowId)
                ->value($tenantColumn);

            if ($tenantId !== null) {
                $metric->setAttribute($tenantColumn, $tenantId);
            }
        });
    }

    protected static function getCurrentTenantId(): int|string|null
    {
        return Auth::id();
    }
}
