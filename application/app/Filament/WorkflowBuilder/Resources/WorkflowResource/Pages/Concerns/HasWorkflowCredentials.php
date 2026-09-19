<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Services\Workflows\WorkflowCredentials;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

trait HasWorkflowCredentials
{
    public function workflowCredentialsAction(): Action
    {
        return Action::make('workflowCredentials')
            ->label('Подключения')->modalHeading('Подключения')
            ->modalDescription('Ваши подключения для нод сервисов. Доступны во всех ваших потоках.')
            ->modalWidth('lg')->modalSubmitAction(false)->modalCancelActionLabel('Закрыть')
            ->schema([
                Select::make('provider')->label('Сервис')->options(WorkflowCredentials::providers())
                    ->default(array_key_first(WorkflowCredentials::providers()))
                    ->placeholder('Выберите сервис')->required()->native()->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('credential_id', null);
                        $set('credentials', []);
                        $set('mode', 'list');
                    }),
                Hidden::make('mode')->default('list'),
                Radio::make('credential_id')->label('Сохранённые подключения')
                    ->options(fn (Get $get) => WorkflowCredentials::options((string) $get('provider')))
                    ->visible(fn (Get $get) => $get('mode') === 'list'
                        && WorkflowCredentials::options((string) $get('provider')) !== [])
                    ->live(),
                EmptyState::make('Подключений пока нет')
                    ->description('Добавьте подключение, чтобы выбирать его в нодах этого сервиса.')
                    ->icon('heroicon-o-link')
                    ->compact()
                    ->visible(fn (Get $get) => $get('mode') === 'list'
                        && filled($get('provider'))
                        && WorkflowCredentials::options((string) $get('provider')) === []),
                Actions::make([
                    Action::make('createWorkflowCredential')
                        ->label('Добавить подключение')
                        ->icon('heroicon-o-plus')
                        ->action(function ($set): void {
                            $set('credential_id', null);
                            $set('credentials', []);
                            $set('mode', 'create');
                        }),
                    Action::make('editWorkflowCredential')
                        ->label('Изменить')
                        ->icon('heroicon-o-pencil-square')
                        ->color('gray')
                        ->disabled(fn (Get $get): bool => blank($get('credential_id')))
                        ->action(function ($set): void {
                            $set('credentials', []);
                            $set('mode', 'edit');
                        }),
                    Action::make('deleteWorkflowCredential')
                        ->label('Удалить')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->disabled(fn (Get $get): bool => blank($get('credential_id')))
                        ->requiresConfirmation()
                        ->modalHeading('Удалить подключение?')
                        ->modalDescription('Ноды, в которых выбрано это подключение, перестанут выполняться до выбора другого.')
                        ->action(function ($get, $set): void {
                            WorkflowCredentials::delete((string) $get('provider'), (int) $get('credential_id'));
                            $set('credential_id', null);
                            $set('credentials', []);
                            $set('mode', 'list');
                            Notification::make()->success()->title('Подключение удалено')->send();
                        }),
                ])->visible(fn (Get $get): bool => $get('mode') === 'list' && filled($get('provider'))),
                Section::make(fn (Get $get): string => $get('mode') === 'edit'
                    ? 'Изменить подключение'
                    : 'Новое подключение')
                    ->schema([
                        Group::make(fn (Get $get) => WorkflowCredentials::schema(
                            $get('provider'),
                            $get('mode') === 'edit',
                        ))->statePath('credentials'),
                        Actions::make([
                            Action::make('saveWorkflowCredential')
                                ->label('Сохранить')
                                ->icon('heroicon-o-check')
                                ->action(function ($get, $set, $component): void {
                                    $mode = (string) $get('mode');
                                    $id = WorkflowCredentials::saveFromForm([
                                        'provider' => (string) $get('provider'),
                                        'credential_id' => $mode === 'edit' ? $get('credential_id') : null,
                                        'credentials' => (array) $get('credentials'),
                                    ], $component->getContainer());
                                    $set('credential_id', $id);
                                    $set('credentials', []);
                                    $set('mode', 'list');
                                    Notification::make()->success()->title('Подключение сохранено')->send();
                                }),
                            Action::make('cancelWorkflowCredential')
                                ->label('Отмена')
                                ->color('gray')
                                ->action(function ($set): void {
                                    $set('credentials', []);
                                    $set('mode', 'list');
                                }),
                        ]),
                    ])
                    ->visible(fn (Get $get): bool => in_array($get('mode'), ['create', 'edit'], true)),
            ])
            ->action(fn (): null => null);
    }
}
