<?php

namespace App\Filament\WorkflowBuilder\Resources;

use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource\Pages;
use App\Models\Workflows\WorkflowRun;
use App\Models\Workflows\WorkflowRunEntity;
use App\Workflows\Triggers\AmoCrmWebhookTriggerCatalog;
use App\Workflows\Triggers\GenericWebhookTrigger;
use App\Workflows\Triggers\WorkflowCompletedTrigger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as SchemaFacade;
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
        $workflowId = request()->integer('workflow_id');

        $query = parent::getEloquentQuery()
            ->where('user_id', Auth::id())
            ->with(['workflow', 'latestStep', 'triggeredBy'])
            ->withCount('steps');

        if ($workflowId > 0) {
            $query->where('workflow_id', $workflowId);
        }

        if (static::hasEntityIndexTable()) {
            $query->with('entityLinks');
        }

        return $query;
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
                TextColumn::make('started_at')
                    ->label('Дата срабатывания')
                    ->state(fn(WorkflowRun $record): string => static::startedDescription($record))
                    ->sortable()
                    ->inline()
                    ->extraCellAttributes(static::compactCellAttributes()),

                TextColumn::make('initiator')
                    ->label('Инициатор')
                    ->state(fn(WorkflowRun $record): HtmlString => static::initiatorHtml($record))
                    ->html()
                    ->inline()
                    ->extraCellAttributes(static::compactCellAttributes()),

                TextColumn::make('workflow_history_name')
                    ->label('Сценарий')
                    ->state(fn(WorkflowRun $record): HtmlString => static::workflowHtml($record))
                    ->html()
                    ->sortable(query: fn(Builder $query, string $direction): Builder => $query
                        ->join('workflows', 'workflow_runs.workflow_id', '=', 'workflows.id')
                        ->orderBy('workflows.name', $direction)
                        ->select('workflow_runs.*'))
                    ->inline()
                    ->extraCellAttributes(static::compactCellAttributes()),

                TextColumn::make('latest_block')
                    ->label('Блок / шаг')
                    ->state(fn(WorkflowRun $record): HtmlString => static::latestBlockHtml($record))
                    ->html()
                    ->inline()
                    ->extraCellAttributes(static::compactCellAttributes()),

                TextColumn::make('latest_changes')
                    ->label('Последние изменения')
                    ->state(fn(WorkflowRun $record): HtmlString => static::latestChangesHtml($record))
                    ->html()
                    ->inline()
                    ->extraCellAttributes(static::compactCellAttributes()),

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
                            ->action(fn(WorkflowRun $record): mixed => static::restartRun($record))
                    ),
            ])
            ->defaultSort('created_at', 'desc')
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

                Filter::make('entity_id')
                    ->label('ID сущности')
                    ->schema([
                        TextInput::make('value')
                            ->label('ID сущности')
                            ->numeric(),
                    ])
                    ->query(fn(Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? static::applyEntityIdSearch($query, (string)$data['value'])
                        : $query)
                    ->indicateUsing(fn(): array => []),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
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

    public static function triggerDescription(WorkflowRun $run): string
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
            GenericWebhookTrigger::type() => GenericWebhookTrigger::name(),
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

    private static function startedDescription(WorkflowRun $run): string
    {
        $date = $run->started_at ?? $run->created_at;

        if ($date === null) {
            return 'Ожидает запуска';
        }

        return $date->isToday()
            ? 'Сегодня, ' . $date->format('H:i')
            : $date->format('d.m.Y H:i');
    }

    private static function initiatorHtml(WorkflowRun $run): HtmlString
    {
        $triggerEntity = static::firstEntityLink($run, 'trigger');

        if ($triggerEntity !== null) {
            return new HtmlString(static::entityLinkHtml($triggerEntity));
        }

        if ($run->triggerable_id !== null) {
            return new HtmlString(sprintf(
                '<span class="workflow-run-history-muted">Сущность</span> <span class="workflow-run-history-strong">#%d</span>',
                (int)$run->triggerable_id,
            ));
        }

        $triggeredBy = $run->triggeredBy?->name ?? null;

        if ($triggeredBy !== null) {
            return new HtmlString(e($triggeredBy));
        }

        return new HtmlString(e(static::triggerDescription($run)));
    }

    private static function workflowHtml(WorkflowRun $run): HtmlString
    {
        $name = $run->workflow?->name ?: 'Процесс удалён';
        $trigger = static::triggerDescription($run);
        $url = $run->workflow_id
            ? WorkflowResource::getUrl('edit', ['record' => $run->workflow_id])
            : null;

        $title = $url
            ? sprintf(
                '<a class="workflow-run-history-link" href="%s" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation();">%s</a>',
                e($url),
                e($name),
            )
            : e($name);

        return new HtmlString(sprintf(
            '%s<div class="workflow-run-history-subline">%s</div>',
            $title,
            e($trigger),
        ));
    }

    private static function latestBlockHtml(WorkflowRun $run): HtmlString
    {
        $step = $run->latestStep;

        if ($step === null) {
            return new HtmlString('<span class="workflow-run-history-muted">Шагов нет</span>');
        }

        $label = static::actionLabel((string)($step->action_type ?: $step->step_type));
        $status = $step->status?->getLabel() ?: null;
        $isFailed = filled($step->error_message);
        $prefix = $isFailed ? '<span class="workflow-run-history-error">!</span> ' : '';
        $subline = $status
            ? sprintf('<div class="workflow-run-history-subline">%s</div>', e($status))
            : '';

        return new HtmlString($prefix . e($label) . $subline);
    }

    private static function latestChangesHtml(WorkflowRun $run): HtmlString
    {
        if ($run->status === RunStatus::FAILED && filled($run->error_message)) {
            return new HtmlString(sprintf(
                '<span class="workflow-run-history-error">%s</span>',
                e(str($run->error_message)->limit(110)),
            ));
        }

        $entities = static::entityLinks($run)
            ->sortByDesc(fn(WorkflowRunEntity $entity): int => $entity->workflow_run_step_id ? 1 : 0)
            ->unique(fn(WorkflowRunEntity $entity): string => (string)$entity->entity_type . ':' . (string)$entity->entity_id)
            ->take(2)
            ->map(fn(WorkflowRunEntity $entity): string => static::entityLinkHtml($entity))
            ->implode('<br>');

        if ($entities !== '') {
            return new HtmlString($entities);
        }

        return new HtmlString(sprintf(
            '<span class="workflow-run-history-muted">Шаги %d / %d · %s</span>',
            min($run->current_step_index + 1, $run->steps_count),
            $run->steps_count,
            e(static::duration($run)),
        ));
    }

    private static function firstEntityLink(WorkflowRun $run, ?string $source = null): ?WorkflowRunEntity
    {
        return static::entityLinks($run)
            ->when($source !== null, fn($entities) => $entities->where('source', $source))
            ->first();
    }

    private static function entityLinks(WorkflowRun $run)
    {
        if (!static::hasEntityIndexTable()) {
            return collect();
        }

        if ($run->relationLoaded('entityLinks')) {
            return collect($run->entityLinks ?? []);
        }

        return collect();
    }

    private static function entityLinkHtml(WorkflowRunEntity $entity): string
    {
        $label = static::entityLabel((string)$entity->entity_type);
        $text = sprintf('%s #%d', $label, (int)$entity->entity_id);

        if (filled($entity->url)) {
            return sprintf(
                '<a class="workflow-run-history-link" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                e((string)$entity->url),
                e($text),
            );
        }

        return sprintf('<span class="workflow-run-history-strong">%s</span>', e($text));
    }

    private static function entityLabel(string $entity): string
    {
        return [
            'lead' => 'Сделка',
            'contact' => 'Контакт',
            'company' => 'Компания',
            'customer' => 'Покупатель',
        ][$entity] ?? 'Сущность';
    }

    private static function actionLabel(string $action): string
    {
        return static::actionNames()[$action] ?? ($action !== '' ? $action : 'Шаг процесса');
    }

    /**
     * @return array<string, string>
     */
    private static function actionNames(): array
    {
        return [
            'trigger' => 'Триггер',
            'condition' => 'Блок условий',
            'delay' => 'Задержка',
            'amocrm_create_lead' => 'Создать сделку',
            'amocrm_create_contact' => 'Создать контакт',
            'amocrm_create_company' => 'Создать компанию',
            'amocrm_copy_lead' => 'Копировать сделку',
            'amocrm_update_fields' => 'Сменить значение поля',
            'amocrm_update_lead_fields' => 'Изменить сделку',
            'amocrm_update_contact_fields' => 'Изменить контакт',
            'amocrm_update_company_fields' => 'Изменить компанию',
            'amocrm_create_task' => 'Поставить задачу',
            'amocrm_add_note' => 'Добавить примечание',
            'amocrm_change_tags' => 'Сменить теги',
            'amocrm_change_lead_status' => 'Сменить статус сделки',
            'amocrm_find_entity' => 'Найти сущность',
            'amocrm_link_entity' => 'Прикрепить сущность',
            'amocrm_unlink_entity' => 'Открепить сущность',
            'workflow_call' => 'Запустить процесс',
            'send_email' => 'Отправить email',
            'send_telegram' => 'Отправить в Telegram',
        ];
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

    private static function applyEntityIdSearch(Builder $query, string $search): Builder
    {
        $entityId = preg_replace('/\D+/', '', $search);

        if ($entityId === '') {
            return $query->whereKey(0);
        }

        return $query->where(function (Builder $query) use ($entityId): void {
            $query->where('triggerable_id', (int)$entityId);

            if (!static::hasEntityIndexTable()) {
                return;
            }

            $query->orWhereIn('workflow_runs.id', WorkflowRunEntity::query()
                ->select('workflow_run_id')
                ->where('user_id', Auth::id())
                ->where('entity_id', (int)$entityId));
        });
    }

    private static function hasEntityIndexTable(): bool
    {
        static $exists = null;

        if ($exists === null) {
            $exists = SchemaFacade::hasTable('workflow_run_entities');
        }

        return $exists;
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
