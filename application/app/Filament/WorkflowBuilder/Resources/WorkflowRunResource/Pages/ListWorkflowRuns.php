<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowRunResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource;
use App\Models\Workflows\WorkflowRun;
use App\Services\Workflows\WorkflowFolders;
use App\Services\Workflows\WorkflowSidebar;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Leek\FilamentWorkflows\Enums\RunStatus;
use Livewire\Attributes\Url;

class ListWorkflowRuns extends ListRecords
{
    #[\Livewire\Attributes\Url(as: 'run')]
    public ?int $historyRunId = null;

    #[\Livewire\Attributes\Url(as: 'errors')]
    public bool $historyErrorsOnly = false;

    #[Url(as: 'folder')]
    public ?string $workflowGroupFilter = null;

    #[Url(as: 'launch')]
    public ?string $workflowLaunchFilter = null;

    protected static string $resource = WorkflowRunResource::class;

    protected static ?string $title = 'История';

    protected ?string $subheading = null;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.workflow-builder.workflow-account-history-page';

    public function getHeading(): string|Htmlable|null
    {
        return 'История';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getTableQuery(): Builder
    {
        return WorkflowRunResource::getEloquentQuery()
            ->when($this->historyErrorsOnly, fn (Builder $query): Builder => $query->where('status', RunStatus::FAILED))
            ->when($this->workflowGroupFilter !== null || $this->workflowLaunchFilter !== null, function (Builder $query): Builder {
                return $query->whereHas('workflow', function (Builder $workflowQuery): void {
                    WorkflowResource::applyGroupHeaderFilter($workflowQuery, $this->workflowGroupFilter);

                    if ($this->workflowLaunchFilter !== null) {
                        $types = WorkflowSidebar::launchTypeGroups()[$this->workflowLaunchFilter]['types'] ?? [];
                        if ($types === []) {
                            $workflowQuery->whereRaw('1 = 0');

                            return;
                        }

                        $workflowQuery->where(function (Builder $workflowQuery) use ($types): void {
                            foreach ($types as $type) {
                                $workflowQuery->orWhere(fn (Builder $workflowQuery): Builder => $workflowQuery->withStartType($type));
                            }
                        });
                    }
                });
            });
    }

    public function selectFolder(?string $name): void
    {
        if ($name !== null && $name !== WorkflowFolders::WITHOUT_FOLDER) {
            WorkflowFolders::assertFolderExists($name);
        }

        $this->workflowGroupFilter = $name;
        $this->workflowLaunchFilter = null;
        $this->resetPage();
    }

    public function selectLaunchType(string $key): void
    {
        abort_unless(array_key_exists($key, WorkflowSidebar::launchTypeGroups()), 404);

        $this->workflowLaunchFilter = $key;
        $this->workflowGroupFilter = null;
        $this->resetPage();
    }

    public function folderOverview(): array
    {
        return WorkflowSidebar::folderOverview();
    }

    public function launchTypeOverview(): array
    {
        return WorkflowSidebar::launchTypeOverview();
    }

    public function createFolderAction(): Action
    {
        return Action::make('createFolder')
            ->label('Создать папку')->icon('heroicon-o-folder-plus')->color('gray')->iconButton()
            ->modalHeading('Новая папка')->modalWidth('sm')->modalSubmitActionLabel('Создать')
            ->schema([TextInput::make('name')->label('Название')->required()->maxLength(100)->autofocus()])
            ->action(function (array $data): void {
                try {
                    $this->selectFolder(WorkflowFolders::create($data['name']));
                } catch (ValidationException $exception) {
                    $messages = collect($exception->errors())->flatten()->all();
                    $statePath = $this->getMountedActionSchema()?->getStatePath();

                    if ($statePath) {
                        throw ValidationException::withMessages([$statePath.'.name' => $messages]);
                    }

                    Notification::make()->title('Не удалось создать папку')->body(implode(' ', $messages))->warning()->send();
                    throw new Halt;
                }

                Notification::make()->title('Папка создана')->success()->send();
            });
    }

    public function getSelectedRun(): ?WorkflowRun
    {
        $selectedId = $this->historyRunId ?? request()->integer('run');
        if ($selectedId <= 0) {
            return null;
        }

        return WorkflowRunResource::getEloquentQuery()
            ->with('steps')
            ->withCount('steps')
            ->whereKey($selectedId)
            ->firstOrFail();
    }

    public function getHistoryIndexUrl(): string
    {
        return WorkflowRunResource::getUrl('index', $this->historyUrlParameters());
    }

    public function getHistoryRunUrl(WorkflowRun $run): string
    {
        return WorkflowRunResource::getUrl('index', ['run' => $run->getKey()] + $this->historyUrlParameters());
    }

    private function historyUrlParameters(): array
    {
        $parameters = [];
        if ($this->historyErrorsOnly) {
            $parameters['errors'] = 1;
        }
        if ($this->workflowGroupFilter !== null) {
            $parameters['folder'] = $this->workflowGroupFilter;
        }
        if ($this->workflowLaunchFilter !== null) {
            $parameters['launch'] = $this->workflowLaunchFilter;
        }
        $workflowId = request()->integer('workflow_id');

        if ($workflowId > 0) {
            $parameters['workflow_id'] = $workflowId;
        }

        return $parameters;
    }

    public function restartSelectedRun(): void
    {
        $run = $this->getSelectedRun();

        if ($run !== null) {
            WorkflowRunResource::restartRun($run);
        }
    }
}
