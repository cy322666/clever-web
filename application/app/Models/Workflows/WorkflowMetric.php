<?php

namespace App\Models\Workflows;

use Illuminate\Support\Facades\Auth;
use Leek\FilamentWorkflows\Models\WorkflowMetric as BaseWorkflowMetric;

class WorkflowMetric extends BaseWorkflowMetric
{
    protected static function getCurrentTenantId(): int|string|null
    {
        return Auth::id();
    }
}
