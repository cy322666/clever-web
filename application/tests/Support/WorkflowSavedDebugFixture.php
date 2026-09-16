<?php

namespace Tests\Support;

use App\Models\Workflows\Workflow;

class WorkflowSavedDebugFixture extends WorkflowDebugFixture
{
    public function getRecord(): Workflow { return Workflow::findOrFail(1); }
    public function render(): string { return '<div></div>'; }
}
