<?php

namespace App\Models\Workflows;

use Illuminate\Support\Facades\Auth;
use Leek\FilamentWorkflows\Models\WorkflowRun as BaseWorkflowRun;

class WorkflowRun extends BaseWorkflowRun
{
    protected static function getCurrentTenantId(): int|string|null
    {
        return Auth::id();
    }
}
