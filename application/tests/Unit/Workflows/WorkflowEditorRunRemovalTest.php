<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\EditWorkflow;
use App\Services\Workflows\WorkflowManualAmoCrmRunService;
use Tests\TestCase;

class WorkflowEditorRunRemovalTest extends TestCase
{
    public function test_standalone_editor_run_is_removed_including_server_entrypoints(): void
    {
        foreach (['runWorkflowManually', 'pollManualWorkflowRun'] as $method) {
            $this->assertFalse(method_exists(EditWorkflow::class, $method));
        }
        foreach (['manualRunId', 'manualRunStatus', 'manualRunUrl', 'manualRunError', 'manualRunSaveSucceeded'] as $property) {
            $this->assertFalse(property_exists(EditWorkflow::class, $property));
        }
        $this->assertFileDoesNotExist(app_path('Services/Workflows/WorkflowEditorRunService.php'));
        $this->assertFileDoesNotExist(app_path('Filament/WorkflowBuilder/Resources/WorkflowResource/Pages/Concerns/HasWorkflowManualRun.php'));
        $this->assertFileDoesNotExist(resource_path('views/filament/workflow-builder/workflow-manual-run.blade.php'));

        $builder = file_get_contents(resource_path('views/vendor/filament-workflows/components/workflow-builder.blade.php'));
        $this->assertStringNotContainsString('workflow-manual-run', $builder);
        $this->assertStringNotContainsString('runWorkflowManually', $builder);
        $this->assertStringContainsString('openDebug()', $builder);
    }

    public function test_debugger_node_execution_and_external_manual_trigger_remain_available(): void
    {
        foreach (['openWorkflowDebugger', 'startWorkflowDebug', 'runWorkflowCanvasNode', 'runEditingWorkflowNode'] as $method) {
            $this->assertTrue(method_exists(EditWorkflow::class, $method));
        }
        $this->assertTrue(class_exists(WorkflowManualAmoCrmRunService::class));
    }
}
