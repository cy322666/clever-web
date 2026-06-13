<?php

namespace App\Filament\WorkflowBuilder\Resources;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\RelationManagers\WorkflowRunsRelationManager;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Schemas\WorkflowForm;
use App\Services\Workflows\WorkflowDependencyMap;
use App\Workflows\Triggers\WorkflowCompletedTrigger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Leek\FilamentWorkflows\Models\Workflow;
use Leek\FilamentWorkflows\Resources\WorkflowResource as BaseWorkflowResource;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;

class WorkflowResource extends BaseWorkflowResource
{
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('latestRun')
            ->withCount('runs');
    }

    public static function form(Schema $schema): Schema
    {
        return WorkflowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ToggleColumn::make('is_active')
                    ->label('Вкл')
                    ->alignCenter()
                    ->updateStateUsing(function (Workflow $record, bool $state): bool {
                        if ($state && !static::canActivate($record)) {
                            Notification::make()
                                ->danger()
                                ->title('Процесс не подключён к родителю')
                                ->body('Добавьте в родительский процесс действие «Запустить процесс» и выберите этот процесс.')
                                ->send();

                            return (bool)$record->is_active;
                        }

                        $record->update([
                            'is_active' => $state,
                        ]);

                        Notification::make()
                            ->success()
                            ->title($state ? 'Процесс включён' : 'Процесс выключен')
                            ->send();

                        return $state;
                    }),

                TextColumn::make('name')
                    ->label(__('filament-workflows::workflows.fields.name.label'))
                    ->sortable()
                    ->extraCellAttributes(fn(Workflow $record): array => [
                        'class' => $record->is_active
                            ? 'workflow-list-name-cell'
                            : 'workflow-list-name-cell workflow-list-name-cell--inactive',
                    ])
                    ->description(fn(Workflow $record): ?string => $record->description)
                    ->url(fn(Workflow $record): string => static::getUrl('edit', ['record' => $record]))
                    ->openUrlInNewTab(),

                TextColumn::make('group_name')
                    ->label('Группа')
                    ->placeholder('Без группы')
                    ->icon('heroicon-o-folder')
                    ->sortable(),

                TextColumn::make('workflow_trigger')
                    ->label('Событие')
                    ->state(fn(Workflow $record): string => static::triggerLabel($record))
                    ->description(fn(Workflow $record): string => static::latestRunDescription($record))
                    ->icon(fn(Workflow $record): string => static::triggerIcon($record))
                    ->color(fn(Workflow $record): string => static::triggerColor($record))
                    ->sortable(false),

                TextColumn::make('runs_count')
                    ->label('Запусков')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->label(__('filament-workflows::workflows.fields.created_at.label'))
                    ->state(fn(Workflow $record): string => static::createdDescription($record))
                    ->sortable(),
            ])
            ->recordUrl(fn(Workflow $record): string => static::getUrl('edit', ['record' => $record]))
            ->openRecordUrlInNewTab()
            ->defaultSort('updated_at', 'desc')
            ->paginated(false)
            ->groups([
                Group::make('group_name')
                    ->label('Группа')
                    ->titlePrefixedWithLabel(false)
                    ->getKeyFromRecordUsing(fn(Workflow $record): string => $record->group_name ?: '__without_group__')
                    ->getTitleFromRecordUsing(fn(Workflow $record): string => $record->group_name ?: 'Без группы')
                    ->scopeQueryByKeyUsing(function (Builder $query, ?string $key): Builder {
                        if ($key === '__without_group__') {
                            return $query->where(fn(Builder $query): Builder => $query
                                ->whereNull('group_name')
                                ->orWhere('group_name', ''));
                        }

                        return $query->where('group_name', $key);
                    })
                    ->collapsible(),
            ])
            ->groupingSettingsHidden()
            ->groupingDirectionSettingHidden()
            ->recordClasses(fn(Workflow $record): string => match (true) {
                static::isWorkflowCallTrigger($record) && !static::canActivate($record) => 'workflow-list-row workflow-list-row--warning',
                $record->is_active => 'workflow-list-row workflow-list-row--active',
                default => 'workflow-list-row workflow-list-row--inactive',
            })
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('filament-workflows::workflows.filters.active.label'))
                    ->placeholder(__('filament-workflows::workflows.filters.active.all'))
                    ->trueLabel(__('filament-workflows::workflows.filters.active.active_only'))
                    ->falseLabel(__('filament-workflows::workflows.filters.active.inactive_only'))
                    ->indicateUsing(fn(): array => []),

                SelectFilter::make('workflow_trigger')
                    ->label(__('filament-workflows::workflows.filters.trigger_type.label'))
                    ->options(fn(): array => app(TriggerRegistry::class)->getSelectOptions())
                    ->query(fn(Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn(Builder $query): Builder => $query->where(
                            'definition->trigger->type',
                            $data['value'],
                        ),
                    ))
                    ->indicateUsing(fn(): array => []),
            ], layout: FiltersLayout::Hidden)
            ->deferFilters(false)
            ->recordActions([
                Action::make('duplicate_workflow')
                    ->label('Копировать сценарий')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->iconButton()
                    ->action(function (Workflow $record): void {
                        $copy = static::duplicateWorkflow($record);

                        Notification::make()
                            ->success()
                            ->title('Копия процесса создана')
                            ->body($copy->name)
                            ->actions([
                                Action::make('open_copy')
                                    ->label('Открыть копию')
                                    ->url(static::getUrl('edit', ['record' => $copy]))
                                    ->openUrlInNewTab(),
                            ])
                            ->send();
                    }),
            ])
            ->emptyStateHeading(__('filament-workflows::workflows.empty_states.no_workflows.heading'))
            ->emptyStateDescription(__('filament-workflows::workflows.empty_states.no_workflows.description'))
            ->emptyStateIcon('heroicon-o-arrow-path');
    }

    private static function triggerLabel(Workflow $record): string
    {
        if (static::isWorkflowCallTrigger($record)) {
            $parents = static::workflowCallParents($record);

            if ($parents === []) {
                return 'Не подключён к родителю';
            }

            return count($parents) === 1
                ? $parents[0]
                : $parents[0] . ' +' . (count($parents) - 1);
        }

        $triggerClass = static::triggerClass($record);

        if ($triggerClass !== null) {
            return $triggerClass::name();
        }

        return $record->trigger_type?->getLabel() ?? '—';
    }

    private static function latestRunDescription(Workflow $record): string
    {
        $latestRun = $record->latestRun;

        if ($latestRun === null) {
            return 'Не запускался';
        }

        $date = $latestRun->created_at;

        if ($date === null) {
            return 'Запуск без даты';
        }

        return $date->isToday()
            ? 'Сегодня, ' . $date->format('H:i')
            : $date->format('d.m.Y H:i');
    }

    private static function createdDescription(Workflow $record): string
    {
        $date = $record->created_at;

        if ($date === null) {
            return '—';
        }

        return $date->format('d.m.Y H:i');
    }

    private static function triggerIcon(Workflow $record): string
    {
        if (static::isWorkflowCallTrigger($record)) {
            return 'heroicon-o-arrow-right-circle';
        }

        $triggerClass = static::triggerClass($record);

        return $triggerClass !== null
            ? $triggerClass::icon()
            : ($record->trigger_type?->getIcon() ?? 'heroicon-o-bolt');
    }

    private static function triggerColor(Workflow $record): string
    {
        if (static::isWorkflowCallTrigger($record)) {
            return static::canActivate($record) ? 'info' : 'danger';
        }

        $triggerClass = static::triggerClass($record);

        return $triggerClass !== null ? $triggerClass::color() : 'warning';
    }

    /**
     * @return class-string|null
     */
    private static function triggerClass(Workflow $record): ?string
    {
        $triggerType = (string)data_get($record->definition, 'trigger.type');

        if ($triggerType === '') {
            return null;
        }

        return app(TriggerRegistry::class)->get($triggerType);
    }

    private static function isWorkflowCallTrigger(Workflow $record): bool
    {
        return data_get($record->definition, 'trigger.type') === WorkflowCompletedTrigger::type();
    }

    private static function canActivate(Workflow $record): bool
    {
        if (!static::isWorkflowCallTrigger($record)) {
            return true;
        }

        return static::workflowCallParents($record) !== [];
    }

    public static function duplicateWorkflow(Workflow $record): Workflow
    {
        $copy = $record->replicate();
        $copy->name = static::copyName($record->name);
        $copy->is_active = false;
        $copy->created_by = auth()->id();
        $copy->updated_by = auth()->id();
        $copy->save();

        return $copy;
    }

    private static function copyName(string $name): string
    {
        $name = trim($name);

        return str($name)->startsWith('Копия: ')
            ? $name . ' (копия)'
            : 'Копия: ' . $name;
    }

    /**
     * @return array<int, string>
     */
    private static function workflowCallParents(Workflow $record): array
    {
        static $parentsByWorkflow = [];

        return $parentsByWorkflow[$record->getKey()]
            ??= app(WorkflowDependencyMap::class)->incomingLabels($record);
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            WorkflowRunsRelationManager::class,
        ];
    }

    /**
     * @return array<class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkflows::route('/'),
            'create' => Pages\CreateWorkflow::route('/create'),
            'edit' => Pages\EditWorkflow::route('/{record}/edit'),
        ];
    }
}
