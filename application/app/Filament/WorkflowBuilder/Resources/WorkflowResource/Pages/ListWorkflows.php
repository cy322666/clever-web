<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Services\Workflows\WorkflowAmoCrmWebhookService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Leek\FilamentWorkflows\Resources\WorkflowResource\Pages\ListWorkflows as BaseListWorkflows;

class ListWorkflows extends BaseListWorkflows
{
    protected static string $resource = WorkflowResource::class;

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('check_amocrm_webhooks')
                    ->label('Проверить')
                    ->icon('heroicon-o-magnifying-glass')
                    ->action(function (): void {
                        $this->notifyWebhookResult(
                            app(WorkflowAmoCrmWebhookService::class)->statusForUser((int)auth()->id()),
                        );
                    }),

                Action::make('sync_amocrm_webhooks')
                    ->label('Установить или обновить')
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->action(function (): void {
                        $this->notifyWebhookResult(
                            app(WorkflowAmoCrmWebhookService::class)->synchronizeUser((int)auth()->id()),
                        );
                    }),

                Action::make('remove_amocrm_webhooks')
                    ->label('Удалить')
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
                ->label('Вебхуки amoCRM')
                ->icon('heroicon-o-signal')
                ->button()
                ->color('gray'),

            Action::make('connect_amocrm')
                ->label('Подключить amoCRM')
                ->icon('heroicon-o-link')
                ->color('success')
                ->action(function (): void {
                    Notification::make()
                        ->title('Подключение amoCRM будет добавлено позже')
                        ->info()
                        ->send();
                }),

            ...parent::getHeaderActions(),
        ];
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
