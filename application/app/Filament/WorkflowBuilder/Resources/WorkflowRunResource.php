<?php

namespace App\Filament\WorkflowBuilder\Resources;

use App\Filament\WorkflowBuilder\Resources\WorkflowRunResource\Pages;
use App\Models\Core\Account;
use App\Models\Workflows\WorkflowRun;
use App\Models\Workflows\WorkflowRunEntity;
use App\Workflows\Triggers\AmoCrmWebhookTriggerCatalog;
use App\Workflows\Triggers\GenericWebhookTrigger;
use App\Workflows\Triggers\WorkflowCompletedTrigger;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\HtmlString;
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

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        $workflowId = request()->integer('workflow_id');

        $query = parent::getEloquentQuery()
            ->when(! (bool) Auth::user()?->is_root, fn (Builder $query): Builder => $query->where('user_id', Auth::id()))
            ->with('workflow')
            ->when((bool) Auth::user()?->is_root, fn (Builder $query): Builder => $query->with('owner.accounts'));

        if ($workflowId > 0) {
            $query->where('workflow_id', $workflowId);
        }

        return $query;
    }

    public static function debugUrl(WorkflowRun $run): ?string
    {
        if (! $run->workflow_id || ! is_array(data_get($run->context_data, 'variables._definition_snapshot.actions'))) {
            return null;
        }

        return WorkflowResource::getUrl('replay', ['run' => $run->getKey()]);
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
                TextColumn::make('workflow_name')
                    ->label('Сценарий')
                    ->state(fn (WorkflowRun $record): string => (string) ($record->workflow?->name ?: 'Сценарий удалён'))
                    ->description(fn (WorkflowRun $record): ?string => (bool) Auth::user()?->is_root
                        ? ($record->owner?->accounts?->first()?->subdomain ?: $record->owner?->email)
                        : null)
                    ->grow()
                    ->extraCellAttributes(static::compactCellAttributes()),

                TextColumn::make('status')
                    ->label('Статус')
                    ->state(fn (WorkflowRun $record): string => $record->status?->getLabel() ?: 'Неизвестен')
                    ->badge()
                    ->icon(fn (WorkflowRun $record): ?string => $record->status?->getIcon())
                    ->color(fn (WorkflowRun $record): string => $record->status?->getColor() ?: 'gray')
                    ->extraCellAttributes(static::compactCellAttributes()),

                TextColumn::make('started_at')
                    ->label('Начало')
                    ->state(fn (WorkflowRun $record): string => static::startedDescription($record))
                    ->sortable()
                    ->inline()
                    ->extraCellAttributes(static::compactCellAttributes()),

                TextColumn::make('run_duration')
                    ->label('Длительность')
                    ->state(fn (WorkflowRun $record): string => static::runDuration($record))
                    ->inline()
                    ->extraCellAttributes(static::compactCellAttributes()),

                TextColumn::make('id')
                    ->label('ID запуска')
                    ->state(fn (WorkflowRun $record): string => '#'.$record->getKey())
                    ->sortable()
                    ->extraCellAttributes(static::compactCellAttributes())
                    ->alignEnd(),
            ])
            ->recordUrl(fn (WorkflowRun $record): ?string => static::debugUrl($record))
            ->recordAction(null)
            ->recordActions([])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50)
            ->poll('10s')
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        RunStatus::COMPLETED->value => 'Завершен',
                        RunStatus::FAILED->value => 'Ошибка',
                        RunStatus::CANCELLED->value => 'Отменен',
                        RunStatus::RUNNING->value => 'Выполняется',
                        RunStatus::PENDING->value => 'Ожидает',
                        RunStatus::PAUSED->value => 'Приостановлен',
                    ])
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
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? static::applyEntityIdSearch($query, (string) $data['value'])
                        : $query)
                    ->indicateUsing(fn (): array => []),
            ], layout: FiltersLayout::Dropdown)
            ->filtersFormColumns(2)
            ->persistFiltersInSession()
            ->toolbarActions([])
            ->emptyStateIcon('heroicon-o-play-circle')
            ->emptyStateHeading('Запусков пока нет')
            ->emptyStateDescription('Здесь появятся выполнения сценариев текущего аккаунта.');
    }

    public static function runDuration(WorkflowRun $run): string
    {
        if ($run->started_at === null || $run->completed_at === null) {
            return $run->status === RunStatus::RUNNING ? 'Выполняется' : '—';
        }

        $seconds = (int) abs($run->started_at->diffInSeconds($run->completed_at));

        if ($seconds === 0) {
            return 'менее 1 с';
        }

        if ($seconds < 60) {
            return $seconds.' с';
        }

        return intdiv($seconds, 60).' мин. '.($seconds % 60).' с';
    }

    public static function triggerDescription(WorkflowRun $run): string
    {
        $triggerType = (string) data_get($run->workflow?->definition, 'trigger.type');

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
            ? $seconds.' сек.'
            : floor($seconds / 60).' мин. '.($seconds % 60).' сек.';
    }

    public static function startedDescription(WorkflowRun $run): string
    {
        $date = $run->started_at ?? $run->created_at;

        if ($date === null) {
            return 'Ожидает запуска';
        }

        return $date
            ->timezone('Europe/Moscow')
            ->format('Y-m-d H:i:s');
    }

    public static function initiatorHtml(WorkflowRun $run): HtmlString
    {
        $triggerEntity = static::firstEntityLink($run, 'trigger');

        if ($triggerEntity !== null) {
            return new HtmlString(static::entityLinkHtml($triggerEntity));
        }

        if ($run->triggerable_id !== null) {
            return new HtmlString(sprintf(
                '<span class="workflow-run-history-muted">Сущность</span> <span class="workflow-run-history-strong">#%d</span>',
                (int) $run->triggerable_id,
            ));
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

    public static function latestBlockHtml(WorkflowRun $run): HtmlString
    {
        $step = $run->latestStep;

        if ($step === null) {
            return new HtmlString('<span class="workflow-run-history-muted">Шагов нет</span>');
        }

        $label = static::actionLabel((string) ($step->action_type ?: $step->step_type));
        $status = $step->status?->getLabel() ?: null;
        $isFailed = filled($step->error_message);
        $prefix = $isFailed ? '<span class="workflow-run-history-error">!</span> ' : '';
        $subline = $status
            ? sprintf('<div class="workflow-run-history-subline">%s</div>', e($status))
            : '';

        return new HtmlString($prefix.e($label).$subline);
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
            ->sortByDesc(fn (WorkflowRunEntity $entity): int => $entity->workflow_run_step_id ? 1 : 0)
            ->unique(fn (WorkflowRunEntity $entity): string => (string) $entity->entity_type.':'.(string) $entity->entity_id)
            ->take(2)
            ->map(fn (WorkflowRunEntity $entity): string => static::entityLinkHtml($entity))
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
            ->when($source !== null, fn ($entities) => $entities->where('source', $source))
            ->first();
    }

    private static function entityLinks(WorkflowRun $run)
    {
        if (! static::hasEntityIndexTable()) {
            return collect();
        }

        if ($run->relationLoaded('entityLinks')) {
            return collect($run->entityLinks ?? []);
        }

        return collect();
    }

    private static function entityLinkHtml(WorkflowRunEntity $entity): string
    {
        $label = static::entityLabel((string) $entity->entity_type);
        $text = sprintf('%s #%d', $label, (int) $entity->entity_id);
        $url = filled($entity->url)
            ? (string) $entity->url
            : static::amoEntityUrl((string) $entity->entity_type, (int) $entity->entity_id, (int) $entity->user_id);

        if ($url !== null) {
            return sprintf(
                '<a class="workflow-run-history-link" href="%s" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation();">%s</a>',
                e($url),
                e($text),
            );
        }

        return sprintf('<span class="workflow-run-history-strong">%s</span>', e($text));
    }

    private static function amoEntityUrl(string $entity, int $entityId, int $userId): ?string
    {
        if ($entityId <= 0 || $userId <= 0) {
            return null;
        }

        $path = [
            'lead' => 'leads/detail',
            'contact' => 'contacts/detail',
            'company' => 'companies/detail',
            'customer' => 'customers/detail',
        ][$entity] ?? null;

        if ($path === null) {
            return null;
        }

        $account = static::amoAccountForWorkflowUser($userId);
        $subdomain = trim((string) ($account?->subdomain ?? ''));

        if ($subdomain === '') {
            return null;
        }

        $endpoint = trim((string) ($account?->endpoint ?? ''));
        if ($endpoint !== '') {
            return rtrim($endpoint, '/').'/'.$path.'/'.$entityId;
        }

        $zone = trim((string) ($account?->zone ?: 'ru'));
        $domain = str_contains($zone, '.')
            ? $zone
            : match ($zone) {
                'com' => 'amocrm.com',
                'ru' => 'amocrm.ru',
                default => 'amocrm.'.$zone,
            };

        return sprintf('https://%s.%s/%s/%d', $subdomain, $domain, $path, $entityId);
    }

    private static function amoAccountForWorkflowUser(int $userId): ?Account
    {
        static $accounts = [];

        if (array_key_exists($userId, $accounts)) {
            return $accounts[$userId];
        }

        return $accounts[$userId] = Account::query()
            ->where('user_id', $userId)
            ->whereNotNull('subdomain')
            ->where('subdomain', '<>', '')
            ->get()
            ->sortBy(function (Account $account): int {
                $widget = Account::normalizeWidget($account->widget);
                $active = (bool) $account->active;

                return match (true) {
                    $widget === 'workflows' && $active => 0,
                    $widget === 'workflows' => 1,
                    $active => 2,
                    $widget === Account::DEFAULT_WIDGET => 3,
                    default => 4,
                };
            })
            ->first();
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
            'amocrm_get_contact' => 'Получить контакт',
            'amocrm_update_fields' => 'Сменить значение поля',
            'amocrm_update_lead_fields' => 'Изменить сделку',
            'amocrm_update_contact_fields' => 'Обновить контакт',
            'amocrm_update_company_fields' => 'Изменить компанию',
            'amocrm_create_task' => 'Поставить задачу',
            'amocrm_add_note' => 'Добавить примечание',
            'amocrm_change_tags' => 'Сменить теги',
            'amocrm_change_lead_status' => 'Сменить статус сделки',
            'amocrm_distribution_queue' => 'Распределить сделку',
            'amocrm_find_entity' => 'Найти сущность',
            'amocrm_link_entity' => 'Связать сущности',
            'amocrm_unlink_entity' => 'Открепить сущность',
            'workflow_call' => 'Запустить процесс',
            'run_workflow' => 'Запустить процесс',
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
            $query->where('triggerable_id', (int) $entityId);

            if (! static::hasEntityIndexTable()) {
                return;
            }

            $query->orWhereIn('workflow_runs.id', WorkflowRunEntity::query()
                ->select('workflow_run_id')
                ->when(! (bool) Auth::user()?->is_root, fn (Builder $query): Builder => $query->where('user_id', Auth::id()))
                ->where('entity_id', (int) $entityId));
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

    public static function restartRun(WorkflowRun $record): void
    {
        if (! $record->status->isTerminal()) {
            Notification::make()
                ->warning()
                ->title('Текущий запуск ещё выполняется')
                ->send();

            return;
        }

        try {
            $executor = app(WorkflowExecutor::class);
            $triggeredBy = (bool) Auth::user()?->is_root ? (int) $record->user_id : (int) Auth::id();
            $newRun = $executor->start(
                workflow: $record->workflow,
                triggerModel: $record->triggerable,
                triggerSource: $record->trigger_source,
                triggeredBy: $triggeredBy,
            );

            $contextData = (array) ($record->context_data ?? []);
            $contextData['workflow_id'] = $newRun->workflow_id;
            $contextData['workflow_run_id'] = $newRun->id;
            $contextData['trigger_source'] = $newRun->trigger_source->value;
            $contextData['triggered_by'] = $triggeredBy;
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
