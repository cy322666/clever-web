<?php

namespace App\Models\Workflows;

use Illuminate\Support\Facades\Auth;
use Leek\FilamentWorkflows\Models\WorkflowSecret as BaseWorkflowSecret;

class WorkflowSecret extends BaseWorkflowSecret
{
    protected static function getCurrentTenantId(): int|string|null
    {
        return Auth::id();
    }
}
