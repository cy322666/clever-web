<?php

namespace App\Filament\WorkflowBuilder\Resources;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Schemas\WorkflowForm;
use App\Models\Core\Account;
use App\Models\Workflows\Workflow as AppWorkflow;
use App\Models\Workflows\WorkflowRun;
use App\Services\Workflows\WorkflowDependencyMap;
use App\Workflows\Actions\WorkflowAmoCrmActionCatalog;
use App\Workflows\Triggers\WorkflowCompletedTrigger;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Leek\FilamentWorkflows\Models\Workflow;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Resources\WorkflowResource as BaseWorkflowResource;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;
use Throwable;

class WorkflowResource extends BaseWorkflowResource
{
    public const GROUP_FILTER_EMPTY = '__without_group__';

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
                    ->tooltip(fn(Workflow $record): string => $record->is_active ? 'Выключить процесс' : 'Включить процесс')
                    ->onColor('primary')
                    ->offColor('gray')
                    ->onIcon('heroicon-m-check')
                    ->offIcon('heroicon-m-x-mark')
                    ->updateStateUsing(fn(Workflow $record, mixed $state): bool => static::updateWorkflowActivation($record, (bool)$state)),

                TextColumn::make('name')
                    ->label(__('filament-workflows::workflows.fields.name.label'))
                    ->sortable()
                    ->description(fn(Workflow $record): ?string => $record->description)
                    ->action(Action::make('configure_workflow')),

                SelectColumn::make('group_name')
                    ->label(fn(): HtmlString => new HtmlString(
                        view('filament.workflow-builder.table.group-header-filter', [
                            'emptyValue' => static::GROUP_FILTER_EMPTY,
                            'options' => static::workflowGroupFilterOptions(),
                        ])->render()
                    ))
                    ->placeholder('Без группы')
                    ->options(fn(): array => AppWorkflow::groupOptions())
                    ->searchableOptions()
                    ->native(false)
                    ->sortable(),

                TextColumn::make('workflow_trigger')
                    ->label('Событие')
                    ->state(fn(Workflow $record): string => static::triggerLabel($record))
                    ->icon(fn(Workflow $record): string => static::triggerIcon($record))
                    ->color(fn(Workflow $record): string => static::triggerColor($record))
                    ->sortable(false),

                TextColumn::make('runs_count')
                    ->label('Запусков')
                    ->sortable()
                    ->alignCenter()
                    ->formatStateUsing(fn(mixed $state): HtmlString => new HtmlString(
                        '<span class="workflow-runs-count-link">' . e((string)((int)$state)) . '</span>',
                    ))
                    ->html()
                    ->action(
                        Action::make('show_workflow_runs')
                            ->modalHeading('История запусков')
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('Закрыть')
                            ->modalWidth('6xl')
                            ->modalContent(
                                fn(Workflow $record) => view('filament.workflow-builder.workflow-history-modal', [
                                    'workflow' => $record,
                                    'runs' => WorkflowRun::query()
                                        ->where('user_id', Auth::id())
                                        ->where('workflow_id', $record->getKey())
                                        ->with(['workflow', 'latestStep', 'triggeredBy'])
                                        ->withCount('steps')
                                        ->latest('created_at')
                                        ->limit(20)
                                        ->get(),
                                ])
                            ),
                    ),

                TextColumn::make('created_at')
                    ->label(__('filament-workflows::workflows.fields.created_at.label'))
                    ->state(fn(Workflow $record): string => static::createdDescription($record))
                    ->sortable(),
            ])
            ->recordUrl(null)
            ->recordAction('configure_workflow')
            ->defaultSort('updated_at', 'desc')
            ->filters([])
            ->filtersTriggerAction(fn(Action $action): Action => $action->hidden())
            ->paginated(false)
            ->recordActions(
                [
                    Action::make('configure_workflow')
                        ->label('Настроить сценарий')
                        ->modalHeading('')
                        ->modalSubmitAction(false)
                        ->modalCancelAction(false)
                        ->modalWidth('7xl')
                        ->modalContent(fn(Workflow $record) => view('filament.workflow-builder.workflow-editor-modal', [
                            'record' => $record,
                            'title' => $record->name ?: 'Настройка сценария',
                        ]))
                        ->extraAttributes(['class' => 'workflow-list-configure-action']),

                    Action::make('duplicate_workflow')
                        ->label('Дублировать сценарий')
                        ->icon('heroicon-o-document-duplicate')
                        ->color('gray')
                        ->iconButton()
                        ->action(function (Workflow $record): void {
                            try {
                                $copy = static::duplicateWorkflow($record);
                            } catch (Throwable $exception) {
                                report($exception);

                                Notification::make()
                                    ->danger()
                                    ->title('Не удалось скопировать процесс')
                                    ->body('Ошибка записана в лог. Обновите страницу и попробуйте ещё раз.')
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title('Копия процесса создана')
                                ->body($copy->name)
                                ->actions([
                                    Action::make('open_copy')
                                        ->label('Открыть копию')
                                        ->url(static::getUrl('edit', ['record' => $copy])),
                                ])
                                ->send();
                        }),

                    DeleteAction::make()
                        ->label('Удалить сценарий')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->iconButton()
                        ->requiresConfirmation()
                        ->modalHeading('Удалить сценарий?')
                        ->modalDescription('Сценарий и все его исполнения будут удалены безвозвратно.')
                        ->modalSubmitActionLabel('Удалить')
                        ->successNotificationTitle('Сценарий удалён'),
                ],
                position: RecordActionsPosition::AfterColumns,
            )
            ->emptyStateHeading(__('filament-workflows::workflows.empty_states.no_workflows.heading'))
            ->emptyStateDescription(__('filament-workflows::workflows.empty_states.no_workflows.description'))
            ->emptyStateIcon('heroicon-o-arrow-path');
    }

    /**
     * @return array<string, string>
     */
    public static function workflowGroupFilterOptions(): array
    {
        $options = AppWorkflow::groupOptions();

        asort($options);

        return $options;
    }

    public static function applyGroupHeaderFilter(Builder $query, ?string $group): Builder
    {
        if ($group === static::GROUP_FILTER_EMPTY) {
            return $query->where(function (Builder $query): void {
                $query
                    ->whereNull('group_name')
                    ->orWhere('group_name', '');
            });
        }

        if (filled($group)) {
            return $query->where('group_name', $group);
        }

        return $query;
    }

    private static function updateWorkflowActivation(Workflow $record, bool $state): bool
    {
        if ($state) {
            $issues = static::activationIssuesForWorkflow($record);

            if ($issues !== []) {
                static::sendActivationBlockedNotification($issues);
                $record->forceFill(['is_active' => false])->save();

                return false;
            }
        }

        $record->update([
            'is_active' => $state,
        ]);

        Notification::make()
            ->success()
            ->title($state ? 'Процесс включён' : 'Процесс выключен')
            ->send();

        return $state;
    }

    public static function forceInactiveWithoutActions(array $data, bool $notify = false): array
    {
        return static::forceInactiveWhenActivationInvalid($data, null, $notify);
    }

    public static function forceInactiveWhenActivationInvalid(array $data, ?Workflow $record = null, bool $notify = false): array
    {
        if (!($data['is_active'] ?? false)) {
            return $data;
        }

        $issues = static::activationIssuesForDefinition($data['definition'] ?? null, $record, $data);

        if ($issues === []) {
            return $data;
        }

        $data['is_active'] = false;

        if ($notify) {
            static::sendActivationBlockedNotification($issues, 'Процесс сохранён выключенным');
        }

        return $data;
    }

    /**
     * @return array<int, string>
     */
    public static function activationIssuesForWorkflow(Workflow $workflow): array
    {
        return static::activationIssuesForDefinition($workflow->definition, $workflow);
    }

    /**
     * @param array<string, mixed>|null $definition
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private static function activationIssuesForDefinition(mixed $definition, ?Workflow $record = null, array $data = []): array
    {
        $definition = is_array($definition) ? $definition : [];
        $issues = [];

        if (!AppWorkflow::definitionHasConfiguredActions($definition)) {
            $issues[] = 'Добавьте хотя бы одно действие.';
        }

        $triggerType = (string)data_get($definition, 'trigger.type');

        if ($duplicateIssue = static::uniqueAmoTriggerIssue($triggerType, $record, $data)) {
            $issues[] = $duplicateIssue;
        }

        $actionTypes = static::workflowActionTypes((array)data_get($definition, 'actions', []));
        $unsupportedTypes = array_values(array_intersect(
            $actionTypes,
            WorkflowAmoCrmActionCatalog::unsupportedWorkflowTypes(),
        ));

        if ($unsupportedTypes !== []) {
            $issues[] = 'Удалите неподдержанные действия: ' . implode(', ', static::workflowActionLabels($unsupportedTypes)) . '.';
        }

        $unknownTypes = static::unknownActionTypes($actionTypes);

        if ($unknownTypes !== []) {
            $issues[] = 'Удалите неизвестные действия: ' . implode(', ', $unknownTypes) . '.';
        }

        if (static::hasNestedCondition((array)data_get($definition, 'actions', []))) {
            $issues[] = 'Вложенные условия временно отключены. Уберите условие внутри ветки Да/Нет.';
        }

        if ($triggerType === WorkflowCompletedTrigger::type() && $record instanceof Workflow && static::workflowCallParents($record) === []) {
            $issues[] = 'Добавьте в родительский процесс действие «Запустить процесс» и выберите этот процесс.';
        }

        if (static::definitionUsesAmoCrm($definition, $actionTypes) && !static::hasWorkflowAmoAccount($record, $data)) {
            $issues[] = 'Подключите amoCRM для виджета сценариев.';
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function uniqueAmoTriggerIssue(
        string $triggerType,
        ?Workflow $record = null,
        array $data = []
    ): ?string {
        if (!AppWorkflow::requiresUniqueActiveTrigger($triggerType)) {
            return null;
        }

        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');
        $accountId = $record?->account_id ?? ($data['account_id'] ?? null);
        $userId = $record?->{$tenantColumn} ?? ($data[$tenantColumn] ?? auth()->id());

        $duplicate = AppWorkflow::activeDuplicateForUniqueTrigger(
            $triggerType,
            $accountId,
            $userId,
            $record?->getKey(),
        );

        if (!$duplicate instanceof AppWorkflow) {
            return null;
        }

        $duplicateName = filled($duplicate->name) ? $duplicate->name : '#' . $duplicate->getKey();

        return sprintf(
            'Триггер «%s» уже используется активным сценарием «%s». Выключите его или выберите другой amoCRM-триггер.',
            static::triggerTypeName($triggerType),
            $duplicateName,
        );
    }

    private static function triggerTypeName(string $triggerType): string
    {
        $triggerClass = app(TriggerRegistry::class)->get($triggerType);

        if (is_string($triggerClass) && method_exists($triggerClass, 'name')) {
            return (string)$triggerClass::name();
        }

        return $triggerType;
    }

    /**
     * @param array<int, string> $issues
     */
    private static function sendActivationBlockedNotification(array $issues, string $title = 'Сценарий не включён'): void
    {
        Notification::make()
            ->warning()
            ->title($title)
            ->body(implode("\n", $issues))
            ->persistent()
            ->send();
    }

    /**
     * @param array<int, mixed> $actions
     * @return array<int, string>
     */
    private static function workflowActionTypes(array $actions): array
    {
        $types = [];

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $type = (string)($action['type'] ?? '');

            if ($type !== '') {
                $types[] = $type;
            }

            $config = (array)($action['config'] ?? []);

            foreach (['true_actions', 'false_actions'] as $branchKey) {
                $types = array_merge($types, static::workflowActionTypes((array)($config[$branchKey] ?? [])));
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * @param array<int, string> $types
     * @return array<int, string>
     */
    private static function unknownActionTypes(array $types): array
    {
        $registry = app(ActionRegistry::class);

        return array_values(array_filter(
            $types,
            static fn(string $type): bool => $type !== '' && !$registry->has($type),
        ));
    }

    /**
     * @param array<int, string> $types
     * @return array<int, string>
     */
    private static function workflowActionLabels(array $types): array
    {
        $registry = app(ActionRegistry::class);

        return array_map(static function (string $type) use ($registry): string {
            $class = $registry->get($type);

            if (is_string($class) && method_exists($class, 'name')) {
                return (string)$class::name();
            }

            if (is_string($class) && method_exists($class, 'workflowName')) {
                return (string)$class::workflowName();
            }

            return $type;
        }, $types);
    }

    /**
     * @param array<int, mixed> $actions
     */
    private static function hasNestedCondition(array $actions, bool $insideConditionBranch = false): bool
    {
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $isCondition = in_array((string)($action['type'] ?? ''), ['condition', 'control-condition'], true)
                || ($action['componentType'] ?? null) === 'control-condition';

            if ($insideConditionBranch && $isCondition) {
                return true;
            }

            $config = (array)($action['config'] ?? []);

            foreach (['true_actions', 'false_actions'] as $branchKey) {
                if (static::hasNestedCondition((array)($config[$branchKey] ?? []), $insideConditionBranch || $isCondition)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, string> $actionTypes
     */
    private static function definitionUsesAmoCrm(array $definition, array $actionTypes): bool
    {
        $triggerType = (string)data_get($definition, 'trigger.type');

        if (str_starts_with($triggerType, 'amocrm-')) {
            return true;
        }

        foreach ($actionTypes as $type) {
            if (str_starts_with($type, 'amocrm_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function hasWorkflowAmoAccount(?Workflow $record = null, array $data = []): bool
    {
        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');
        $userId = (int)($record?->{$tenantColumn} ?? ($data[$tenantColumn] ?? 0) ?: auth()->id());

        if ($userId <= 0) {
            return false;
        }

        $query = Account::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->whereNotNull('refresh_token');

        if ($userId !== 1) {
            $query->where('widget', 'workflows');
        }

        return $query->exists();
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

    private static function createdDescription(Workflow $record): string
    {
        $date = $record->created_at;

        if ($date === null) {
            return '—';
        }

        return $date->format('Y-m-d H:i:s');
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

    private static function hasConfiguredActions(Workflow $record): bool
    {
        return AppWorkflow::definitionHasConfiguredActions($record->definition);
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
        $name = static::copyName($record->name);

        $copy = $record->replicate(static::workflowCopyExcludedColumns($record));
        $copy->name = $name;
        $copy->is_active = false;
        $copy->created_by = auth()->id();
        $copy->updated_by = auth()->id();

        foreach (static::workflowCopyUniqueValues($record, $name) as $column => $value) {
            $copy->{$column} = $value;
        }

        $copy->save();

        return $copy;
    }

    /**
     * @return array<int, string>
     */
    private static function workflowCopyExcludedColumns(Workflow $record): array
    {
        return array_values(array_unique(array_filter([
            $record->getKeyName(),
            'created_at',
            'updated_at',
            'deleted_at',
            ...static::workflowCopyUniqueColumns($record),
        ])));
    }

    /**
     * @return array<int, string>
     */
    private static function workflowCopyUniqueColumns(Workflow $record): array
    {
        $attributes = $record->getAttributes();

        return array_values(array_filter([
            'uuid',
            'ulid',
            'public_id',
            'workflow_uuid',
            'slug',
            'token',
            'secret',
            'webhook_secret',
        ], fn(string $column): bool => array_key_exists($column, $attributes)));
    }

    /**
     * @return array<string, string>
     */
    private static function workflowCopyUniqueValues(Workflow $record, string $name): array
    {
        $values = [];

        foreach (static::workflowCopyUniqueColumns($record) as $column) {
            $values[$column] = match ($column) {
                'uuid', 'workflow_uuid' => (string)Str::uuid(),
                'ulid', 'public_id' => (string)Str::ulid(),
                'slug' => (Str::slug($name) ?: 'workflow-copy') . '-' . Str::lower(Str::random(6)),
                'token' => Str::random(40),
                'secret', 'webhook_secret' => Str::random(48),
                default => Str::random(32),
            };
        }

        return $values;
    }

    private static function copyName(?string $name): string
    {
        $name = trim((string)$name);

        if ($name === '') {
            return 'Копия процесса';
        }

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
        return [];
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
