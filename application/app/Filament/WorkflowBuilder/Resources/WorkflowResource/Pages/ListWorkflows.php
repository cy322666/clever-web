<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Models\Core\Account;
use App\Models\Workflows\WorkflowRun;
use App\Services\Workflows\WorkflowAmoCrmWebhookService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Leek\FilamentWorkflows\Resources\WorkflowResource\Pages\ListWorkflows as BaseListWorkflows;

class ListWorkflows extends BaseListWorkflows
{
    protected static string $resource = WorkflowResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public ?string $workflowGroupFilter = null;

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        $query = parent::getTableQuery();

        if ($query instanceof Builder) {
            return WorkflowResource::applyGroupHeaderFilter($query, $this->workflowGroupFilter);
        }

        return $query;
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
        return [
            Action::make('workflow_runs')
                ->label('История')
                ->icon('heroicon-o-play-circle')
                ->color('gray')
                ->modalHeading('История запусков')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Закрыть')
                ->modalWidth('7xl')
                ->modalContent(fn() => view('filament.workflow-builder.workflow-history-modal', [
                    'workflow' => null,
                    'runs' => $this->workflowHistoryRuns(),
                    'fullHistoryUrl' => null,
                ])),

            Action::make('create_workflow')
                ->label('Создать')
                ->icon('heroicon-o-plus')
                ->color('warning')
                ->modalHeading('Создать сценарий')
                ->modalSubmitAction(false)
                ->modalCancelAction(false)
                ->modalWidth('7xl')
                ->modalContent(fn() => view('filament.workflow-builder.workflow-create-modal', [
                    'title' => 'Создать сценарий',
                ])),

            $this->workflowAmoCrmHeaderAction(),
        ];
    }

    private function workflowAmoCrmHeaderAction(): Action|ActionGroup
    {
        if (!$this->workflowAmoConnectionState()['connected']) {
            return Action::make('workflow_amocrm_connection')
                ->label(fn(): string => $this->workflowAmoConnectionState()['label'])
                ->icon('heroicon-o-link')
                ->color('success')
                ->action(fn(): mixed => $this->redirectToWorkflowAmoOAuth());
        }

        return ActionGroup::make([
            Action::make('check_amocrm_webhooks')
                ->label('Проверить подключение и хуки')
                ->icon('heroicon-o-check-circle')
                ->action(function (): void {
                    $this->notifyWebhookResult(
                        app(WorkflowAmoCrmWebhookService::class)->statusForUser((int)auth()->id()),
                    );
                }),

            Action::make('sync_amocrm_webhooks')
                ->label('Установить или обновить хуки')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->action(function (): void {
                    $this->notifyWebhookResult(
                        app(WorkflowAmoCrmWebhookService::class)->synchronizeUser((int)auth()->id()),
                    );
                }),

            Action::make('remove_amocrm_webhooks')
                ->label('Удалить хуки')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Удалить вебхуки процессов из amoCRM?')
                ->modalDescription(
                    'Процессы перестанут запускаться по событиям amoCRM, пока вебхуки не будут установлены снова.'
                )
                ->action(function (): void {
                    $this->notifyWebhookResult(
                        app(WorkflowAmoCrmWebhookService::class)->removeUser((int)auth()->id()),
                    );
                }),
        ])
            ->label(fn(): string => $this->workflowAmoConnectionState()['label'])
            ->icon('heroicon-o-check-circle')
            ->button()
            ->color('gray');
    }

    private function redirectToWorkflowAmoOAuth(): mixed
    {
        $user = auth()->user();

        if (!$user) {
            Notification::make()
                ->title('Пользователь не найден')
                ->danger()
                ->send();

            return null;
        }

        $widget = Account::normalizeWidget('workflows');
        $clientId = (string)config('services.amocrm.widgets.workflows.client_id', '');

        if ($clientId === '') {
            Notification::make()
                ->title('Не настроен client_id для процессов')
                ->body('Укажите AMO_WORKFLOWS_CLIENT_ID.')
                ->danger()
                ->send();

            return null;
        }

        $state = $user->uuid . '|' . $widget;
        $url = WorkflowResource::getUrl();

        return Redirect::to(
            'https://www.amocrm.ru/oauth/?state=' . urlencode($state)
            . '&client_id=' . urlencode($clientId)
            . '&uri=' . urlencode($url)
        );
    }

    private function workflowHistoryRuns()
    {
        $query = WorkflowRun::query()
            ->where('user_id', auth()->id())
            ->with(['workflow', 'latestStep', 'triggeredBy'])
            ->withCount('steps')
            ->latest('created_at')
            ->limit(50);

        if (SchemaFacade::hasTable('workflow_run_entities')) {
            $query->with('entityLinks');
        }

        return $query->get();
    }

    /**
     * @return array{connected: bool, label: string}
     */
    private function workflowAmoConnectionState(): array
    {
        $account = $this->workflowAmoAccount();
        $connected = $this->workflowAmoAccountIsConnected($account);

        if (!$connected) {
            return [
                'connected' => false,
                'label' => 'Подключить amoCRM',
            ];
        }

        $subdomain = trim((string)$account?->subdomain);

        return [
            'connected' => true,
            'label' => $subdomain !== '' ? 'amoCRM: ' . $subdomain : 'amoCRM',
        ];
    }

    private function workflowAmoAccount(): ?Account
    {
        $userIds = $this->workflowAmoAccountOwnerIds();

        if ($userIds === []) {
            return null;
        }

        return $this->connectedWorkflowAmoAccountQuery()
            ->whereIn('user_id', $userIds)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<int, int>
     */
    private function workflowAmoAccountOwnerIds(): array
    {
        $userIds = [];
        $authUserId = auth()->id();

        if ($authUserId) {
            $userIds[] = (int)$authUserId;
        }

        $workflowModel = WorkflowResource::getModel();
        $workflowUserIds = $workflowModel::query()
            ->whereNotNull('user_id')
            ->latest('id')
            ->limit(10)
            ->pluck('user_id')
            ->filter()
            ->map(fn(mixed $userId): int => (int)$userId)
            ->all();

        return array_values(array_unique([
            ...$userIds,
            ...$workflowUserIds,
        ]));
    }

    private function connectedWorkflowAmoAccountQuery()
    {
        return Account::query()
            ->where('widget', Account::normalizeWidget('workflows'))
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
            });
    }

    private function workflowAmoAccountIsConnected(?Account $account = null): bool
    {
        $account ??= $this->workflowAmoAccount();

        return $account instanceof Account
            && (bool)$account->active
            && filled($account->subdomain)
            && (filled($account->refresh_token) || filled($account->access_token));
    }

    /**
     * @param array<string, mixed> $result
     */
    private function notifyWebhookResult(array $result): void
    {
        $required = count((array)($result['required_events'] ?? []));
        $installed = count((array)($result['installed_events'] ?? []));
        $stale = count((array)($result['stale_hooks'] ?? []));
        $details = sprintf('Требуется событий: %d. Установлено: %d.', $required, $installed);

        if ($stale > 0) {
            $details .= sprintf(' Устаревших вебхуков: %d.', $stale);
        }

        $notification = Notification::make()
            ->title((string)($result['message'] ?? 'Операция с вебхуками завершена.'))
            ->body($details);

        match ((string)($result['state'] ?? 'error')) {
            'installed', 'not_required', 'missing' => $notification->success(),
            'outdated' => $notification->warning(),
            default => $notification->danger(),
        };

        $notification->send();
    }
}
