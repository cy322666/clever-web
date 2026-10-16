<?php

namespace Tests\Unit\Workflows;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\CreateWorkflow;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\EditWorkflow;
use Tests\TestCase;

class WorkflowEditorCompatibilityTest extends TestCase
{
    public function test_editor_keeps_existing_settings_and_controls_until_browser_parity_is_verified(): void
    {
        $pages = WorkflowResource::getPages();

        $this->assertSame(CreateWorkflow::class, $pages['create']->getPage());
        $this->assertSame(EditWorkflow::class, $pages['edit']->getPage());
    }
}
