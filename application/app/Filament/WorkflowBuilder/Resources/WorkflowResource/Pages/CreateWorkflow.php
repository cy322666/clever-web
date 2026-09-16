<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasCompactWorkflowConfigurationPanels;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasWorkflowPageActions;
use App\Workflows\FailureStrategies;
use App\Workflows\Triggers\WorkflowCompletedTrigger;
use App\Services\Workflows\WorkflowFolders;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Leek\FilamentWorkflows\Resources\WorkflowResource\Pages\CreateWorkflow as BaseCreateWorkflow;
use Livewire\Attributes\Url;

class CreateWorkflow extends BaseCreateWorkflow
{
    use HasCompactWorkflowConfigurationPanels;
    use HasWorkflowPageActions;

    protected static string $resource = WorkflowResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.workflow-builder.workflow-create-page';

    #[Url]
    public ?string $folder = null;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTitle(): string
    {
        return 'Новый процесс';
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return null;
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = parent::mutateFormDataBeforeCreate($data);
        $data['name'] = filled($data['name'] ?? null) ? $data['name'] : 'Новый процесс';
        $data['is_active'] = (bool)($data['is_active'] ?? false);
        $data['failure_strategy'] = $data['failure_strategy'] ?? FailureStrategies::STOP;

        if (filled($this->folder)) {
            WorkflowFolders::assertFolderExists($this->folder);
            $data['group_name'] = $this->folder;
        }

        if (data_get($data, 'definition.trigger.type') === WorkflowCompletedTrigger::type()) {
            data_set($data, 'definition.trigger.config', []);
            $data['is_active'] = false;
        }

        return WorkflowResource::forceInactiveWithoutActions($data, notify: true);
    }

    protected function getRedirectUrl(): string
    {
        if ($this->record !== null) {
            return WorkflowResource::getUrl('edit', ['record' => $this->record]);
        }

        return parent::getRedirectUrl();
    }
}
