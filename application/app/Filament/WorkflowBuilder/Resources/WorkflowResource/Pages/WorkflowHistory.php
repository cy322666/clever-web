<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\UsesWorkflowOwnerContext;
use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource;
use App\Models\Workflows\WorkflowRun;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/** Keeps existing history links working without a separate run-viewer page. */
class WorkflowHistory extends ViewRecord
{
    use UsesWorkflowOwnerContext;

    protected static string $resource = WorkflowResource::class;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();
        $this->initializeWorkflowOwnerContext();

        $parameters = ['workflow_id' => $this->record->getKey()];
        if (request()->boolean('errors')) {
            $parameters['errors'] = 1;
        }

        $url = WorkflowRunResource::getUrl('index', $parameters);
        if (($runId = request()->integer('run')) > 0) {
            $run = WorkflowRun::query()->where('user_id', Auth::id())
                ->where('workflow_id', $this->record->getKey())->whereKey($runId)->firstOrFail();
            $url = WorkflowRunResource::debugUrl($run) ?? $url;
        }

        $this->redirect($url);
    }
}
