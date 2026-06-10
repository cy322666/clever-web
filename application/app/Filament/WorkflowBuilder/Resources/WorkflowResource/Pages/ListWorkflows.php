<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Leek\FilamentWorkflows\Resources\WorkflowResource\Pages\ListWorkflows as BaseListWorkflows;

class ListWorkflows extends BaseListWorkflows
{
    protected static string $resource = WorkflowResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
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
}
