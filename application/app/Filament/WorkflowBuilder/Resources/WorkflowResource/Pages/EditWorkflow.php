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

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeAccess();
        $this->initializeWorkflowOwnerContext();
        $this->fillForm();
        $this->previousUrl = url()->previous();
    }

    public function hydrate(): void
    {
        $this->restoreWorkflowOwnerContext();
        parent::hydrate();
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

    protected function getSavedNotificationTitle(): ?string
    {
        return null;
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
