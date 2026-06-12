<?php

namespace App\Filament\WorkflowBuilder\Resources;

use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource\Pages;
use App\Models\Workflows\WorkflowRun;
use App\Workflows\Triggers\AmoCrmWebhookTriggerCatalog;
use App\Workflows\Triggers\WorkflowCompletedTrigger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Enums\RunStatus;
use Leek\FilamentWorkflows\Enums\TriggerType;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use Leek\FilamentWorkflows\WorkflowsPlugin;
use Throwable;

class WorkflowRunResource extends Resource
{
    protected static ?string $model = WorkflowRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static ?string $navigationLabel = 'Исполнения процессов';

    protected static ?string $modelLabel = 'исполнение процесса';

    protected static ?string $pluralModelLabel = 'Исполнения процессов';

    protected static ?string $recordTitleAttribute = 'ulid';

    public static function getNavigationGroup(): ?string
    {
        return WorkflowsPlugin::get()->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        $pluginSort = WorkflowsPlugin::get()->getNavigationSort();

        return $pluginSort !== null ? $pluginSort + 1 : null;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return WorkflowsPlugin::get()->hasNavigation();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->whereIn('status', [RunStatus::PENDING, RunStatus::RUNNING, RunStatus::PAUSED])
            ->count();

        return $count > 0 ? (string)$count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', Auth::id())
            ->with('workflow')
            ->withCount('steps');
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('workflow.name')
                    ->label('Процесс')
                    ->searchable()
                    ->sortable()
                    ->limit(48)
                    ->tooltip(fn(?string $state): ?string => $state)
                    ->inline()
                    ->extraCellAttributes(static::compactCellAttributes())
                    ->url(fn(WorkflowRun $record): string => WorkflowResource::getUrl('edit', [
                        'record' => $record->workflow_id,
                    ]))
                    ->openUrlInNewTab(),

                TextColumn::make('trigger_description')
                    ->label('Триггер')
                    ->state(fn(WorkflowRun $record): string => static::triggerDescription($record))
                    ->icon(fn(WorkflowRun $record): ?string => $record->trigger_source?->getIcon())
                    ->color('gray')
                    ->limit(34)
                    ->tooltip(fn(?string $state): ?string => $state)
                    ->inline()
                    ->extraCellAttributes(static::compactCellAttributes()),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->inline()
                    ->extraCellAttributes(static::compactCellAttributes())
                    ->sortable(),

                TextColumn::make('step_progress')
                    ->label('Шаги')
                    ->state(fn(WorkflowRun $record): string => sprintf(
                        '%d / %d',
                        min($record->current_step_index + 1, $record->steps_count),
                        $record->steps_count,
                    ))
                    ->badge()
                    ->inline()
                    ->color(fn(WorkflowRun $record): string => match ($record->status) {
                        RunStatus::COMPLETED => 'success',
                        RunStatus::FAILED => 'danger',
                        RunStatus::RUNNING => 'info',
                        default => 'gray',
                    })
                    ->extraCellAttributes(static::compactCellAttributes())
                    ->alignCenter(),

                TextColumn::make('started_at')
                    ->label('Запущен')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable()
                    ->inline()
                    ->extraCellAttributes(static::compactCellAttributes())
                    ->placeholder('Ожидает запуска'),

                TextColumn::make('duration')
                    ->label('Длительность')
                    ->state(fn(WorkflowRun $record): string => static::duration($record))
                    ->inline()
                    ->extraCellAttributes(static::compactCellAttributes())
                    ->alignEnd(),

                IconColumn::make('result_status')
                    ->label('')
                    ->state(fn(WorkflowRun $record): string => $record->status->value)
                    ->icon(fn(WorkflowRun $record): ?string => $record->status->getIcon())
                    ->color(fn(WorkflowRun $record): ?string => $record->status->getColor())
                    ->tooltip(fn(WorkflowRun $record): string => $record->status === RunStatus::FAILED
                        ? ($record->error_message ?: 'Ошибка выполнения')
                        : ($record->status->getLabel() ?: 'Статус выполнения'))
                    ->extraCellAttributes(static::compactCellAttributes())
                    ->alignCenter(),

                TextColumn::make('ulid')
                    ->label('ID запуска')
                    ->fontFamily('mono')
                    ->copyable()
                    ->searchable()
                    ->inline()
                    ->extraCellAttributes(static::compactCellAttributes())
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('restart')
                    ->label('')
                    ->state(fn(WorkflowRun $record): bool => $record->status->isTerminal())
                    ->icon(fn(bool $state): ?string => $state ? 'heroicon-o-arrow-path' : null)
                    ->color('warning')
                    ->tooltip('Перезапустить сценарий')
                    ->extraCellAttributes(static::compactCellAttributes())
                    ->action(
                        Action::make('restart_run')
                            ->label('Перезапустить сценарий')
                            ->requiresConfirmation()
                            ->modalHeading('Перезапустить сценарий?')
                            ->modalDescription('Будет создан новый запуск с теми же входными данными.')
                            ->modalSubmitActionLabel('Перезапустить')
                            ->action(fn(WorkflowRun $record): mixed => static::restartRun($record))
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('3s')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(RunStatus::class)
                    ->multiple(),

                SelectFilter::make('workflow_id')
                    ->label('Процесс')
                    ->relationship('workflow', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('trigger_source')
                    ->label('Источник запуска')
                    ->options(TriggerType::class)
                    ->multiple(),
            ])
            ->recordAction('view_steps')
            ->recordActions([
                Action::make('view_steps')
                    ->modalHeading(fn(WorkflowRun $record): string => 'Исполнение процесса · ' . $record->ulid)
                    ->modalContent(fn(WorkflowRun $record) => view(
                        'filament-workflows::filament.partials.run-steps-modal',
                        ['run' => $record->load('steps')]
                    ))
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть'),
            ], RecordActionsPosition::AfterContent)
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-play-circle')
            ->emptyStateHeading('Исполнений пока нет')
            ->emptyStateDescription('Здесь появятся запуски всех процессов текущего аккаунта.');
    }

    private static function triggerDescription(WorkflowRun $run): string
    {
        $triggerType = (string)data_get($run->workflow?->definition, 'trigger.type');

        return static::triggerNames()[$triggerType]
            ?? $run->trigger_source?->getLabel()
            ?? 'Запуск';
    }

    /**
     * @return array<string, string>
     */
    private static function triggerNames(): array
    {
        static $names;

        if (is_array($names)) {
            return $names;
        }

        $names = [
            'manual' => 'Ручной запуск',
            'schedule' => 'По расписанию',
            'date-condition' => 'Относительно даты',
            WorkflowCompletedTrigger::type() => WorkflowCompletedTrigger::name(),
        ];

        foreach (AmoCrmWebhookTriggerCatalog::classes() as $triggerClass) {
            $names[$triggerClass::type()] = $triggerClass::name();
        }

        return $names;
    }

    private static function duration(WorkflowRun $run): string
    {
        if ($run->started_at === null) {
            return '—';
        }

        $seconds = $run->completed_at
            ? $run->started_at->diffInSeconds($run->completed_at)
            : $run->started_at->diffInSeconds(now());

        return $seconds < 60
            ? $seconds . ' сек.'
            : floor($seconds / 60) . ' мин. ' . ($seconds % 60) . ' сек.';
    }

    /**
     * @return array<string, string>
     */
    private static function compactCellAttributes(): array
    {
        return [
            'style' => 'padding: 0.625rem 0.75rem; vertical-align: middle;',
        ];
    }

    private static function restartRun(WorkflowRun $record): void
    {
        if (!$record->status->isTerminal()) {
            Notification::make()
                ->warning()
                ->title('Текущий запуск ещё выполняется')
                ->send();

            return;
        }

        try {
            $executor = app(WorkflowExecutor::class);
            $newRun = $executor->start(
                workflow: $record->workflow,
                triggerModel: $record->triggerable,
                triggerSource: $record->trigger_source,
                triggeredBy: Auth::id(),
            );

            $contextData = (array)($record->context_data ?? []);
            $contextData['workflow_id'] = $newRun->workflow_id;
            $contextData['workflow_run_id'] = $newRun->id;
            $contextData['trigger_source'] = $newRun->trigger_source->value;
            $contextData['triggered_by'] = Auth::id();
            $contextData['step_outputs'] = [];

            $newRun->update([
                'context_data' => WorkflowContext::fromArray($contextData, $record->triggerable)->toArray(),
            ]);

            ExecuteWorkflowJob::dispatch($newRun->id);

            Notification::make()
                ->success()
                ->title('Сценарий перезапущен')
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Не удалось перезапустить сценарий')
                ->body($exception->getMessage())
                ->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkflowRuns::route('/'),
        ];
    }
}
