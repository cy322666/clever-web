<?php

namespace App\Filament\WorkflowBuilder\Resources;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\RelationManagers\WorkflowRunsRelationManager;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Schemas\WorkflowForm;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Leek\FilamentWorkflows\Models\Workflow;
use Leek\FilamentWorkflows\Resources\WorkflowResource as BaseWorkflowResource;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;

class WorkflowResource extends BaseWorkflowResource
{
    public static function form(Schema $schema): Schema
    {
        return WorkflowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament-workflows::workflows.fields.name.label'))
                    ->searchable()
                    ->sortable()
                    ->description(fn(Workflow $record): ?string => $record->description)
                    ->url(fn(Workflow $record): string => static::getUrl('edit', ['record' => $record])),

                TextColumn::make('workflow_trigger')
                    ->label(__('filament-workflows::workflows.fields.trigger_type.label'))
                    ->state(fn(Workflow $record): string => static::triggerLabel($record))
                    ->icon(fn(Workflow $record): string => static::triggerIcon($record))
                    ->color(fn(Workflow $record): string => static::triggerColor($record))
                    ->badge()
                    ->sortable(false),

                TextColumn::make('runs_count')
                    ->label(__('filament-workflows::workflows.fields.runs_count.label'))
                    ->counts('runs')
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('updated_at')
                    ->label(__('filament-workflows::workflows.fields.updated_at.label'))
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label(__('filament-workflows::workflows.fields.created_at.label'))
                    ->dateTime('d.m.Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('filament-workflows::workflows.filters.active.label'))
                    ->placeholder(__('filament-workflows::workflows.filters.active.all'))
                    ->trueLabel(__('filament-workflows::workflows.filters.active.active_only'))
                    ->falseLabel(__('filament-workflows::workflows.filters.active.inactive_only')),

                SelectFilter::make('workflow_trigger')
                    ->label(__('filament-workflows::workflows.filters.trigger_type.label'))
                    ->options(fn(): array => app(TriggerRegistry::class)->getSelectOptions())
                    ->query(fn(Builder $query, array $data): Builder => $query->when(
                        filled($data['value'] ?? null),
                        fn(Builder $query): Builder => $query->where(
                            'definition->trigger->type',
                            $data['value'],
                        ),
                    )),
            ])
            ->recordActions([
                Action::make('toggle_active')
                    ->label(
                        fn(Workflow $record): string => $record->is_active ? 'Выключить процесс' : 'Включить процесс'
                    )
                    ->icon(fn(Workflow $record): string => $record->is_active ? 'heroicon-o-power' : 'heroicon-o-play')
                    ->color(fn(Workflow $record): string => $record->is_active ? 'warning' : 'success')
                    ->iconButton()
                    ->extraAttributes(fn(Workflow $record): array => [
                        'class' => $record->is_active
                            ? '!h-10 !w-10 rounded-full bg-amber-100 text-amber-700 ring-1 ring-amber-300 shadow-sm transition hover:bg-amber-200 dark:bg-amber-500/15 dark:text-amber-300 dark:ring-amber-500/30'
                            : '!h-10 !w-10 rounded-full bg-emerald-100 text-emerald-700 ring-1 ring-emerald-300 shadow-sm transition hover:bg-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/30',
                    ])
                    ->action(function (Workflow $record): void {
                        $isActive = !$record->is_active;

                        $record->update([
                            'is_active' => $isActive,
                        ]);

                        Notification::make()
                            ->success()
                            ->title($isActive ? 'Процесс включён' : 'Процесс выключен')
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
            $sourceWorkflowId = (int)data_get($record->definition, 'trigger.config.source_workflow_id');

            return static::workflowName($sourceWorkflowId) ?? 'Процесс #' . $sourceWorkflowId;
        }

        $triggerClass = static::triggerClass($record);

        if ($triggerClass !== null) {
            return $triggerClass::name();
        }

        return $record->trigger_type?->getLabel() ?? '—';
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
            return 'info';
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
        return data_get($record->definition, 'trigger.type') === 'workflow-completed'
            && filled(data_get($record->definition, 'trigger.config.source_workflow_id'));
    }

    private static function workflowName(int $workflowId): ?string
    {
        if ($workflowId <= 0) {
            return null;
        }

        $workflowModel = config('filament-workflows.models.workflow', Workflow::class);

        return $workflowModel::query()
            ->whereKey($workflowId)
            ->value('name');
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
