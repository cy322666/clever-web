<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Models\Workflows\WorkflowRun;
use App\Services\Workflows\WorkflowEditorRunService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

trait HasWorkflowManualRun
{
    protected bool $manualRunSaveSucceeded = false;

    #[Locked] public ?int $manualRunId = null;
    #[Locked] public ?string $manualRunStatus = null;
    #[Locked] public ?string $manualRunUrl = null;
    #[Locked] public ?string $manualRunError = null;

    public function runWorkflowManually(?string $startId = null): void
    {
        $this->authorizeAccess();
        abort_if(method_exists($this, 'isWorkflowReplay') && $this->isWorkflowReplay(), 403);
        abort_unless((int) $this->getRecord()->user_id === (int) auth()->id(), 403);
        if ($this->nodePreviewTaskId) {
            $this->manualRunError = 'Дождитесь завершения запуска ноды.';
            $this->skipRender();
            return;
        }
        $this->manualRunError = null;
        $this->resetValidation();
        try {
            $this->manualRunSaveSucceeded = false;
            $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
            if ($this->getErrorBag()->isNotEmpty()) return;
            if (!$this->manualRunSaveSucceeded) {
                throw ValidationException::withMessages(['manualRun' => 'Сначала исправьте ошибки сохранения потока.']);
            }
            $run = app(WorkflowEditorRunService::class)->start((int) $this->getRecord()->id, $startId);
            $this->manualRunId = (int) $run->id;
            $this->manualRunStatus = $run->status->value;
            $this->manualRunUrl = WorkflowResource::getUrl('history', ['record' => $this->getRecord()->id, 'run' => $run->id]);
        } catch (ValidationException $error) {
            $this->manualRunError = collect($error->errors())->flatten()->first();
        } catch (Throwable $error) {
            report($error);
            $this->manualRunError = $error instanceof \Leek\FilamentWorkflows\Exceptions\NonRetryableWorkflowException
                ? $error->getMessage() : 'Не удалось запустить поток. Проверьте историю запусков перед повтором.';
        }
        $this->skipRender();
    }

    public function pollManualWorkflowRun(): void
    {
        if (!$this->manualRunId) { $this->skipRender(); return; }
        $run = WorkflowRun::query()->where('user_id', auth()->id())
            ->where('workflow_id', $this->getRecord()->id)->find($this->manualRunId);
        $this->manualRunStatus = $run?->status->value;
        if (!$run) $this->manualRunId = $this->manualRunUrl = null;
        $this->skipRender();
    }
}
