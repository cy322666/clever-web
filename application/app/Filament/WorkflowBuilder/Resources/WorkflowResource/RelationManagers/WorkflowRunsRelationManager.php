<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Leek\FilamentWorkflows\Enums\RunStatus;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use Leek\FilamentWorkflows\Models\WorkflowRun;
use Leek\FilamentWorkflows\Resources\WorkflowResource\RelationManagers\WorkflowRunsRelationManager as BaseWorkflowRunsRelationManager;

class WorkflowRunsRelationManager extends BaseWorkflowRunsRelationManager
{
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ulid')
            ->columns([
                TextColumn::make('status')
                    ->label(__('filament-workflows::workflows.fields.status.label'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('started_at')
                    ->label(__('filament-workflows::workflows.fields.started_at.label'))
                    ->state(fn (WorkflowRun $record): ?string => $record->started_at
                        ?->timezone('Europe/Moscow')
                        ->format('Y-m-d H:i:s'))
                    ->sortable()
                    ->placeholder(__('filament-workflows::workflows.placeholders.not_started')),

                TextColumn::make('duration')
                    ->label(__('filament-workflows::workflows.fields.duration.label'))
                    ->state(function (WorkflowRun $record): ?string {
                        $seconds = $record->getDurationInSeconds();

                        if ($seconds === null) {
                            return null;
                        }

                        if ($seconds < 60) {
                            return __('filament-workflows::workflows.formatters.duration_seconds', ['seconds' => $seconds]);
                        }

                        $minutes = floor($seconds / 60);
                        $remainingSeconds = $seconds % 60;

                        return __('filament-workflows::workflows.formatters.duration_minutes_seconds', [
                            'minutes' => $minutes,
                            'seconds' => $remainingSeconds,
                        ]);
                    })
                    ->placeholder(__('filament-workflows::workflows.placeholders.duration'))
                    ->alignEnd(),

                TextColumn::make('retry_count')
                    ->label(__('filament-workflows::workflows.fields.retry_count.label'))
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),

                TextColumn::make('error_message')
                    ->label(__('filament-workflows::workflows.fields.error_message.label'))
                    ->limit(50)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->color('danger')
                    ->placeholder(__('filament-workflows::workflows.placeholders.error')),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->headerActions([])
            ->recordAction('view_steps')
            ->recordActionsColumnLabel('')
            ->recordActions([
                Action::make('view_steps')
                    ->label('')
                    ->icon(null)
                    ->extraAttributes(['class' => 'hidden'])
                    ->modalHeading(fn (WorkflowRun $record): string => __('filament-workflows::workflows.modals.run_steps.heading', ['ulid' => $record->ulid]))
                    ->modalContent(fn (WorkflowRun $record) => view(
                        'filament-workflows::filament.partials.run-steps-modal',
                        ['run' => $record->load('steps')]
                    ))
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('filament-workflows::workflows.actions.close.label')),

                $this->getRetryAction(),
                $this->getCancelAction(),
            ])
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-play')
            ->emptyStateHeading(__('filament-workflows::workflows.empty_states.no_runs.heading'))
            ->emptyStateDescription(__('filament-workflows::workflows.empty_states.no_runs.description'))
            ->paginated(false)
            ->modifyQueryUsing(fn(Builder $query): Builder => $query
                ->latest('created_at')
                ->limit(10));
    }

    protected function getRetryAction(): Action
    {
        return Action::make('retry')
            ->label(__('filament-workflows::workflows.actions.retry.label'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn (WorkflowRun $record): bool => $record->status === RunStatus::FAILED && $record->canRetry())
            ->action(function (WorkflowRun $record): void {
                $record->update([
                    'status' => RunStatus::PENDING,
                    'error_message' => null,
                    'completed_at' => null,
                ]);

                $record->incrementRetry();

                ExecuteWorkflowJob::dispatch($record->id);
            });
    }
}
