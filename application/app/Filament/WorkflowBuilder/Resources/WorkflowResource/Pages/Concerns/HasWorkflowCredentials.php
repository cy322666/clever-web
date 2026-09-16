<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Services\Workflows\WorkflowCredentials;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

trait HasWorkflowCredentials
{
    public function workflowCredentialsAction(): Action
    {
        return Action::make('workflowCredentials')
            ->label('Подключения')->modalHeading('Подключения')
            ->modalDescription('Ваши подключения для нод сервисов. Доступны во всех ваших потоках.')
            ->modalWidth('lg')->modalSubmitActionLabel('Сохранить подключение')
            ->schema([
                Select::make('provider')->label('Сервис')->options(WorkflowCredentials::providers())
                    ->placeholder('Выберите сервис')->required()->native()->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('credential_id', null);
                        $set('credentials', []);
                    }),
                Select::make('credential_id')->label('Мои подключения')
                    ->options(fn (Get $get) => WorkflowCredentials::options((string) $get('provider')))
                    ->visible(fn (Get $get) => WorkflowCredentials::options((string) $get('provider')) !== [])
                    ->placeholder('Новое подключение')->native()->live()
                    ->afterStateUpdated(fn (Set $set) => $set('credentials', [])),
                Group::make(fn (Get $get) => WorkflowCredentials::schema($get('provider'), filled($get('credential_id'))))
                    ->statePath('credentials'),
            ])
            ->action(fn (array $data, Schema $schema) => WorkflowCredentials::saveFromForm($data, $schema))
            ->successNotificationTitle('Подключение сохранено');
    }
}
