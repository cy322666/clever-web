<?php

namespace App\Models\Workflows;

use Illuminate\Support\Facades\Auth;
use Leek\FilamentWorkflows\Models\Workflow as BaseWorkflow;

class Workflow extends BaseWorkflow
{
    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return array_replace(parent::casts(), [
            'failure_strategy' => 'string',
        ]);
    }

    protected static function getCurrentTenantId(): int|string|null
    {
        return Auth::id();
    }
}
