<?php

namespace App\Filament\WorkflowBuilder\Resources;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Schemas\WorkflowForm;
use App\Models\Core\Account;
use App\Models\Workflows\Workflow as AppWorkflow;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Leek\FilamentWorkflows\Models\Workflow;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
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
                    ->label('Группа')
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
                    ->url(fn(Workflow $record): string => WorkflowRunResource::getUrl('index', [
                        'workflow_id' => $record->getKey(),
                    ]))
                    ->openUrlInNewTab(),

                TextColumn::make('created_at')
                    ->label(__('filament-workflows::workflows.fields.created_at.label'))
                    ->state(fn(Workflow $record): string => static::createdDescription($record))
                    ->sortable(),
            ])
            ->recordUrl(null)
            ->recordAction('configure_workflow')
            ->defaultSort('updated_at', 'desc')
            ->paginated(false)
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('filament-workflows::workflows.filters.active.label'))
                    ->placeholder(__('filament-workflows::workflows.filters.active.all'))
                    ->trueLabel(__('filament-workflows::workflows.filters.active.active_only'))
                    ->falseLabel(__('filament-workflows::workflows.filters.active.inactive_only'))
                    ->indicateUsing(fn(): array => []),

                SelectFilter::make('group_name')
                    ->label('Группа')
                    ->placeholder('Все группы')
                    ->options(fn(): array => static::workflowGroupFilterOptions())
                    ->native(false)
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        $value = (string)($data['value'] ?? '');

                        if ($value === '') {
                            return $query;
                        }

                        if ($value === '__without_group__') {
                            return $query->where(fn(Builder $query): Builder => $query
                                ->whereNull('group_name')
                                ->orWhere('group_name', ''));
                        }

                        return $query->where('group_name', $value);
                    })
                    ->indicateUsing(fn(): array => []),
            ])
            ->deferFilters(false)
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
                            $copy = static::duplicateWorkflow($record);

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
     * @return array<string, array<string, string>>
     */
    public static function triggerFilterOptions(): array
    {
        $groups = [
            'Основные' => [],
            'Внешний запуск' => [],
            'amoCRM · Ответственные' => [],
            'amoCRM · Создание' => [],
            'amoCRM · Изменение' => [],
            'amoCRM · Удаление' => [],
            'amoCRM · Восстановление' => [],
            'amoCRM · Примечания и сообщения' => [],
            'Другое' => [],
        ];

        foreach (app(TriggerRegistry::class)->getSelectOptions() as $value => $label) {
            $label = (string)$label;
            $group = static::triggerFilterGroup((string)$value);
            $groups[$group][(string)$value] = static::triggerFilterLabel($label);
        }

        return array_filter($groups, static fn(array $options): bool => $options !== []);
    }

    private static function triggerFilterGroup(string $value): string
    {
        if (in_array($value, ['manual', 'schedule', 'date-condition'], true)) {
            return 'Основные';
        }

        if (in_array($value, ['workflow-completed', 'generic-webhook'], true)) {
            return 'Внешний запуск';
        }

        if (str_starts_with($value, 'amocrm-responsible-')) {
            return 'amoCRM · Ответственные';
        }

        if (str_starts_with($value, 'amocrm-add-')) {
            return in_array($value, ['amocrm-add-talk', 'amocrm-add-chat-template-review'], true)
                ? 'amoCRM · Примечания и сообщения'
                : 'amoCRM · Создание';
        }

        if (str_starts_with($value, 'amocrm-update-') || $value === 'amocrm-status-lead') {
            return 'amoCRM · Изменение';
        }

        if (str_starts_with($value, 'amocrm-delete-')) {
            return 'amoCRM · Удаление';
        }

        if (str_starts_with($value, 'amocrm-restore-')) {
            return 'amoCRM · Восстановление';
        }

        if (str_starts_with($value, 'amocrm-note-')) {
            return 'amoCRM · Примечания и сообщения';
        }

        return 'Другое';
    }

    private static function triggerFilterLabel(string $label): string
    {
        $label = trim(preg_replace('/^amoCRM:\s*/u', '', $label) ?: $label);

        return mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);
    }

    /**
     * @return array<string, string>
     */
    private static function workflowGroupFilterOptions(): array
    {
        return [
            '__without_group__' => 'Без группы',
            ...AppWorkflow::groupOptions(),
        ];
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
