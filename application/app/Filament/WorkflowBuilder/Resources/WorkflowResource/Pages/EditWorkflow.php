<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasCompactWorkflowConfigurationPanels;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasWorkflowPageActions;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\UsesWorkflowOwnerContext;
use App\Workflows\Triggers\WorkflowCompletedTrigger;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Leek\FilamentWorkflows\Resources\WorkflowResource\Pages\EditWorkflow as BaseEditWorkflow;

class EditWorkflow extends BaseEditWorkflow
{
    use HasCompactWorkflowConfigurationPanels;
    use HasWorkflowPageActions;
    use UsesWorkflowOwnerContext;

    protected static string $resource = WorkflowResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.workflow-builder.workflow-editor-page';

    protected ?bool $requestedActivation = null;

    // Signed, locked metadata avoids Livewire restoring the workflow from SQL
    // on every settings request. The editable definition stays in the browser.
    #[\Livewire\Attributes\Locked]
    public array $workflowRecordSnapshot = [];

    #[\Livewire\Attributes\Locked]
    public ?string $workflowRecordVersion = null;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();
        $this->initializeWorkflowOwnerContext();
        $this->fillForm();
        $this->savedWorkflowEditorState = $this->workflowEditorState();
        $this->previousUrl = url()->previous();
    }

    public function hydrate(): void
    {
        if (!isset($this->record) && $this->workflowRecordSnapshot !== []) {
            $this->record = (new \App\Models\Workflows\Workflow)->newFromBuilder($this->workflowRecordSnapshot);
            $this->record->definition = $this->definition;
        }
        $this->restoreWorkflowOwnerContext();
        parent::hydrate();
    }

    public function dehydrate(): void
    {
        // Historical debugging keeps its original model lifecycle and input/output loading.
        if (method_exists($this, 'isWorkflowReplay') && $this->isWorkflowReplay()) return;
        if (!$this->record) return;
        $this->workflowRecordVersion ??= $this->recordVersion($this->record);
        $this->workflowRecordSnapshot = $this->record->getAttributes();
        // Do not ship a second copy of the persisted graph, or an Eloquent model
        // identifier that would be restored from SQL on the following request.
        unset($this->workflowRecordSnapshot['definition']);
        $this->record = null;
    }

    private function recordVersion(\Illuminate\Database\Eloquent\Model $record): string
    {
        return hash('sha256', json_encode([$record->name, $record->group_name, $record->is_active, $record->failure_strategy, $record->definition, (string) $record->updated_at]));
    }

    protected function refreshWorkflowRecordForSave(): void
    {
        $record = \App\Models\Workflows\Workflow::query()->lockForUpdate()->findOrFail($this->getRecord()->getKey());
        abort_unless((int) $record->user_id === (int) auth()->id(), 403);
        if ($this->workflowRecordVersion !== null && !hash_equals($this->workflowRecordVersion, $this->recordVersion($record))) {
            \Filament\Notifications\Notification::make()->warning()->title('Поток изменён в другом окне')
                ->body('Черновик остался здесь и не перезаписал новую версию. Откройте актуальный поток перед сохранением.')->send();
            throw \Illuminate\Validation\ValidationException::withMessages(['definition' => 'Поток изменён в другом окне. Черновик остаётся здесь. Откройте актуальную версию перед сохранением.']);
        }
        $this->record = $record;
        $this->authorizeAccess();
    }

    protected function refreshWorkflowRecordForRuntime(): void
    {
        $record = \App\Models\Workflows\Workflow::query()->findOrFail($this->getRecord()->getKey());
        abort_unless((int) $record->user_id === (int) auth()->id(), 403);
        $this->record = $record;
        $this->authorizeAccess();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function toggleWorkflowActivation(): void
    {
        $this->authorizeAccess();
        abort_unless((int) $this->getRecord()->user_id === (int) auth()->id(), 403);
        $this->requestedActivation = ! $this->getRecord()->is_active;
        try {
            $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
            $this->data['is_active'] = (bool) $this->getRecord()->refresh()->is_active;
        } finally {
            $this->requestedActivation = null;
        }
    }

    public function save(bool $shouldRedirect = true, bool $shouldSendSavedNotification = true): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($shouldRedirect, $shouldSendSavedNotification): void {
            $this->refreshWorkflowRecordForSave();
            if (!$this->applyWorkflowInspectorDraft()) return;
            parent::save($shouldRedirect, $shouldSendSavedNotification);
        });
    }

    protected function persistWorkflowNodeRename(string $nodeId, string $name): void
    {
        // Renaming updates references in the draft, not the saved workflow.
        // The existing inline confirmation/validation behaviour is retained.
        $this->authorizeAccess();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
    }

    protected function afterSave(): void
    {
        $this->workflowRecordVersion = $this->recordVersion($this->getRecord());
        $this->savedWorkflowEditorState = $this->workflowEditorState();
        // Saving acknowledges the current graph; it does not change its markup.
        // A redundant morph rebases flex/tree offsets and dirties the saved layout.
        // Activation still renders its changed toolbar state, as do validation errors.
        if ($this->requestedActivation === null) $this->skipRender();
    }

    public function getTitle(): string
    {
        return (string) ($this->record?->name ?? 'Процесс');
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = parent::mutateFormDataBeforeSave($data);
        // The empty canvas schema intentionally has no identity fields.
        $data['name'] = $this->data['name'] ?? $this->getRecord()->name;
        $data['group_name'] = $this->data['group_name'] ?? null;
        \Illuminate\Support\Facades\Validator::make($data, ['name' => 'required|string|max:255', 'group_name' => 'nullable|string|max:255'])->validate();
        if (filled($data['group_name'])) \App\Services\Workflows\WorkflowFolders::assertFolderExists($data['group_name']);
        // The canvas form has no fields, so Filament strips data.is_active on dehydration.
        $data['is_active'] = $this->requestedActivation ?? (bool) $this->getRecord()->is_active;
        $data = WorkflowResource::forceInactiveWhenActivationInvalid($data, $this->record, notify: true);

        if (data_get($data, 'definition.trigger.type') !== WorkflowCompletedTrigger::type()) {
            return $data;
        }

        data_set($data, 'definition.trigger.config', []);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        // Keep the mounted editor, its selection and viewport after saving.
        return '';
    }
}
