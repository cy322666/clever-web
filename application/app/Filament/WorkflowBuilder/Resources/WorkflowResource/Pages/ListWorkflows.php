<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Models\Core\Account;
use App\Services\Integrations\AmoCrmDisconnectService;
use App\Services\Workflows\WorkflowAmoCrmInstallationContext;
use App\Services\Workflows\WorkflowAmoCrmWebhookService;
use App\Services\Workflows\WorkflowConnectionAccess;
use App\Services\Workflows\WorkflowFolders;
use App\Services\Workflows\WorkflowSidebar;
use App\Support\Crm\CrmProvider;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Leek\FilamentWorkflows\Resources\WorkflowResource\Pages\ListWorkflows as BaseListWorkflows;
use Livewire\Attributes\Url;

class ListWorkflows extends BaseListWorkflows
{
    use \App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns\HasWorkflowImport;

    protected static string $resource = WorkflowResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected string $view = 'filament.workflow-builder.workflow-list-page';

    #[Url(as: 'folder')]
    public ?string $workflowGroupFilter = null;

    #[Url(as: 'launch')]
    public ?string $workflowLaunchFilter = null;

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
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
        abort_unless(array_key_exists($key, $this->launchTypeGroups()), 404);

        $this->workflowLaunchFilter = $key;
        $this->workflowGroupFilter = null;
        $this->resetPage();
    }

    public function folderOverview(): array
    {
        return WorkflowSidebar::folderOverview();
    }

    /**
     * @return array<int, array{key: string, label: string, icon: string, count: int}>
     */
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
                $this->selectFolder($this->runFolderMutation(fn () => WorkflowFolders::create($data['name'])));
                Notification::make()->title('Папка создана')->success()->send();
            });
    }

    public function renameFolderAction(): Action
    {
        return Action::make('renameFolder')
            ->label('Переименовать папку')->icon('heroicon-o-pencil')->color('gray')->iconButton()
            ->visible(fn (): bool => filled($this->workflowGroupFilter) && $this->workflowGroupFilter !== WorkflowFolders::WITHOUT_FOLDER)
            ->modalHeading('Переименовать папку')->modalWidth('sm')->modalSubmitActionLabel('Сохранить')
            ->fillForm(fn (): array => ['name' => $this->workflowGroupFilter])
            ->schema([TextInput::make('name')->label('Название')->required()->maxLength(100)->autofocus()])
            ->action(function (array $data): void {
                $this->selectFolder($this->runFolderMutation(fn () => WorkflowFolders::rename((string) $this->workflowGroupFilter, $data['name'])));
            });
    }

    public function deleteFolderAction(): Action
    {
        return Action::make('deleteFolder')
            ->label('Удалить папку')->icon('heroicon-o-trash')->color('gray')->iconButton()
            ->visible(fn (): bool => filled($this->workflowGroupFilter) && $this->workflowGroupFilter !== WorkflowFolders::WITHOUT_FOLDER)
            ->requiresConfirmation(false)->modal(false)
            ->action(function (): void {
                $this->runFolderMutation(fn () => WorkflowFolders::remove((string) $this->workflowGroupFilter));
                $this->selectFolder(null);
                Notification::make()->title('Папка удалена. Сценарии сохранены.')->success()->send();
            });
    }

    private function runFolderMutation(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->flatten()->all();
            $statePath = $this->getMountedActionSchema()?->getStatePath();

            if ($statePath) {
                throw ValidationException::withMessages([$statePath.'.name' => $messages]);
            }

            Notification::make()->title('Не удалось изменить папку')->body(implode(' ', $messages))->warning()->send();
            throw new Halt;
        }
    }

    public function getHeading(): string|Htmlable|null
    {
        return 'Сценарии';
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        $query = parent::getTableQuery();

        if ($query instanceof Builder) {
            $query = WorkflowResource::applyGroupHeaderFilter($query, $this->workflowGroupFilter);

            if ($this->workflowLaunchFilter !== null) {
                $types = $this->launchTypeGroups()[$this->workflowLaunchFilter]['types'] ?? [];

                $query->where(function (Builder $query) use ($types): void {
                    foreach ($types as $type) {
                        $query->orWhere(fn (Builder $query): Builder => $query->withStartType($type));
                    }
                });
            }

            return $query;
        }

        return $query;
    }

    public function selectedLaunchTypeLabel(): ?string
    {
        return $this->workflowLaunchFilter !== null
            ? ($this->launchTypeGroups()[$this->workflowLaunchFilter]['label'] ?? null)
            : null;
    }

    /**
     * @return array<string, array{label: string, icon: string, types: array<int, string>}>
     */
    private function launchTypeGroups(): array
    {
        return WorkflowSidebar::launchTypeGroups();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        if ((bool) auth()->user()?->is_root) {
            return [
                Action::make('workflow_admin')
                    ->label('К обзору')
                    ->icon('heroicon-o-arrow-left')
                    ->color('gray')
                    ->url(\App\Filament\App\Pages\WorkflowAdmin::getUrl()),
            ];
        }

        return [
            $this->importWorkflowAction(),
            Action::make('create_workflow')
                ->label('Новый сценарий')
                ->icon('heroicon-o-plus')->iconButton()->tooltip('Новый сценарий')
                ->color('gray')
                ->url(fn (): string => WorkflowResource::getUrl('create', [
                    'folder' => $this->workflowGroupFilter !== WorkflowFolders::WITHOUT_FOLDER ? $this->workflowGroupFilter : null,
                ])),

            $this->workflowAmoCrmHeaderAction(),
        ];
    }

    private function workflowAmoCrmHeaderAction(): Action|ActionGroup
    {
        if (! $this->workflowAmoConnectionState()['connected']) {
            return Action::make('workflow_amocrm_connection')
                ->label(fn (): string => $this->workflowAmoConnectionState()['label'])
                ->icon('heroicon-o-link')
                ->color('gray')
                ->action(fn (): mixed => $this->redirectToWorkflowAmoOAuth());
        }

        return ActionGroup::make([
            Action::make('check_amocrm_integration')
                ->label('Проверить интеграцию')
                ->icon('heroicon-o-check-circle')
                ->action(function (): void {
                    $this->notifyIntegrationCheck(
                        app(WorkflowAmoCrmWebhookService::class)->statusForUser((int) auth()->id(), verifyConnection: true),
                    );
                }),

            Action::make('disconnect_amocrm')
                ->label('Отключить amoCRM')
                ->icon('heroicon-o-link-slash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Отключить amoCRM от «Потоков»?')
                ->modalDescription('Ключи доступа «Потоков» будут удалены. Аккаунт amoCRM останется закреплён за этим аккаунтом Clever.')
                ->modalSubmitActionLabel('Отключить amoCRM')
                ->action(function (): void {
                    $hooksRemoved = app(AmoCrmDisconnectService::class)->disconnectWorkflowsForUser((int) auth()->id());
                    $notification = Notification::make()->title('amoCRM отключена от «Потоков»');
                    if ($hooksRemoved === false) {
                        $notification->warning()->body('Ключи доступа удалены. Не удалось удалить вебхуки в amoCRM.');
                    } else {
                        $notification->success();
                    }
                    $notification->send();

                    $this->redirect(WorkflowResource::getUrl('index', [
                        'folder' => $this->workflowGroupFilter,
                        'launch' => $this->workflowLaunchFilter,
                    ]));
                }),
        ])
            ->label(fn (): string => $this->workflowAmoConnectionState()['label'])
            ->icon('heroicon-o-check-circle')
            ->button()
            ->color('gray');
    }

    private function redirectToWorkflowAmoOAuth(): mixed
    {
        $user = auth()->user();

        if (! $user) {
            Notification::make()
                ->title('Пользователь не найден')
                ->danger()
                ->send();

            return null;
        }

        $clientId = (string) config('services.amocrm.widgets.workflows.client_id', '');

        if ($clientId === '') {
            Notification::make()
                ->title('Не настроен client_id для процессов')
                ->body('Укажите AMO_WORKFLOWS_CLIENT_ID.')
                ->danger()
                ->send();

            return null;
        }

        $state = app(WorkflowAmoCrmInstallationContext::class)->issue($user);
        $url = WorkflowResource::getUrl();

        return Redirect::to(
            CrmProvider::authorizationUrl($user->crm_provider).'?state='.urlencode($state)
            .'&client_id='.urlencode($clientId)
            .'&uri='.urlencode($url)
        );
    }

    /**
     * @return array{connected: bool, label: string}
     */
    private function workflowAmoConnectionState(): array
    {
        $account = $this->workflowAmoAccount();

        if (! $account) {
            return [
                'connected' => false,
                'label' => 'Подключить amoCRM',
            ];
        }

        $subdomain = trim((string) $account->subdomain);

        return [
            'connected' => true,
            'label' => $subdomain !== '' ? 'amoCRM: '.$subdomain : 'amoCRM',
        ];
    }

    private function workflowAmoAccount(): ?Account
    {
        $userId = auth()->id();
        if (!$userId) {
            return null;
        }

        return WorkflowConnectionAccess::accounts()
            ->where('user_id', $userId)
            ->where('active', true)
            ->whereNotNull('subdomain')
            ->where('subdomain', '<>', '')
            ->where(function ($query): void {
                $query->where(function ($query): void {
                    $query->whereNotNull('access_token')
                        ->where('access_token', '<>', '');
                })->orWhere(function ($query): void {
                    $query->whereNotNull('refresh_token')
                        ->where('refresh_token', '<>', '');
                });
            })
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function notifyIntegrationCheck(array $result): void
    {
        $required = count((array) ($result['required_events'] ?? []));
        $installed = count((array) ($result['installed_events'] ?? []));
        $stale = count((array) ($result['stale_hooks'] ?? []));
        $details = sprintf('Требуется событий: %d. Установлено: %d.', $required, $installed);

        if ($stale > 0) {
            $details .= sprintf(' Устаревших вебхуков: %d.', $stale);
        }

        $verified = $result['connection_verified'] ?? false;
        $notification = Notification::make()
            ->title($verified ? 'Подключение amoCRM подтверждено' : 'Не удалось подтвердить подключение amoCRM')
            ->body(trim($result['message'].' '.($result['ok'] ? $details : '')));

        match ((string) ($result['state'] ?? 'error')) {
            'installed', 'not_required' => $notification->success(),
            'missing', 'outdated', 'configuration_required' => $notification->warning(),
            default => $notification->danger(),
        };

        $notification->send();
    }
}
