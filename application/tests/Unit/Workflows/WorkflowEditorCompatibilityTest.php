<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\CreateWorkflow;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\EditWorkflow;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\ListWorkflows;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\ReplayWorkflow;
use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource\Pages\ListWorkflowRuns;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Foundation\Vite;
use Tests\TestCase;

class WorkflowEditorCompatibilityTest extends TestCase
{
    public function test_editor_keeps_existing_settings_and_controls_until_browser_parity_is_verified(): void
    {
        $pages = WorkflowResource::getPages();

        $this->assertSame(CreateWorkflow::class, $pages['create']->getPage());
        $this->assertSame(EditWorkflow::class, $pages['edit']->getPage());
    }

    public function test_editor_script_loads_only_on_pages_with_a_workflow_canvas(): void
    {
        $hotFile = tempnam(sys_get_temp_dir(), 'workflow-vite-');
        try {
            file_put_contents($hotFile, 'http://localhost:5173');
            app(Vite::class)->useHotFile($hotFile);
            foreach ([new CreateWorkflow, new EditWorkflow, new ReplayWorkflow] as $page) {
                $scripts = FilamentView::renderHook(PanelsRenderHook::SCRIPTS_AFTER, $page->getRenderHookScopes())->toHtml();
                $this->assertStringContainsString('/resources/js/workflows.js', $scripts);
            }
            foreach ([[], (new ListWorkflows)->getRenderHookScopes(), (new ListWorkflowRuns)->getRenderHookScopes()] as $scopes) {
                $scripts = FilamentView::renderHook(PanelsRenderHook::SCRIPTS_AFTER, $scopes)->toHtml();
                $this->assertStringContainsString('/resources/js/app.js', $scripts);
                $this->assertStringNotContainsString('/resources/js/workflows.js', $scripts);
            }
        } finally {
            unlink($hotFile);
        }
    }
}
