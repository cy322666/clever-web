<?php

namespace App\Filament\WorkflowBuilder\Resources;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Schemas\WorkflowForm;
use App\Models\Workflows\Workflow as AppWorkflow;
use App\Services\Workflows\WorkflowDependencyMap;
use App\Services\Workflows\WorkflowFolders;
use App\Services\Workflows\WorkflowSubscriptionAccess;
use App\Workflows\Actions\WorkflowAmoCrmActionCatalog;
use App\Workflows\Triggers\WorkflowCompletedTrigger;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Enums\RunStatus;
use Leek\FilamentWorkflows\Models\Workflow;
use Leek\FilamentWorkflows\Resources\WorkflowResource as BaseWorkflowResource;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;
use Throwable;

class WorkflowResource extends BaseWorkflowResource
{
    public const GROUP_FILTER_EMPTY = WorkflowFolders::WITHOUT_FOLDER;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['latestRun', 'owner.accounts'])
            ->withCount([
                'runs',
                'runs as queued_runs_count' => fn (Builder $query): Builder => $query
                    ->where('status', RunStatus::PENDING->value),
            ]);
    }

    public static function form(Schema $schema): Schema
    {
        return WorkflowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('owner.email')
                    ->label('Аккаунт')
                    ->description(fn (Workflow $record): ?string => $record->owner?->accounts?->first()?->subdomain)
                    ->searchable()
                    ->visible(fn (): bool => (bool) auth()->user()?->is_root),

                TextColumn::make('name')
                    ->label('Сценарий')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->icon(fn (Workflow $record): string => static::triggerIcon($record))
                    ->iconColor(fn (Workflow $record): string|array => static::triggerIcon($record) === 'amocrm-digital-pipeline'
                        ? \Filament\Support\Colors\Color::hex('#339dc7') : 'gray')
                    ->description(fn (Workflow $record): string => static::triggerLabel($record).' · Изменён '.($record->updated_at?->diffForHumans() ?? 'только что'))
                    ->url(fn (Workflow $record): string => static::getUrl('edit', ['record' => $record])),

                TextColumn::make('latestRun.status')
                    ->label('Последний запуск')
                    ->alignStart()
                    ->badge()
                    ->placeholder('Не запускался')
                    ->formatStateUsing(fn ($state): string => $state instanceof \Leek\FilamentWorkflows\Enums\RunStatus ? $state->getLabel() : (string) $state)
                    ->color(fn ($state): string => match ($state instanceof \BackedEnum ? $state->value : $state) {
                        'completed' => 'success', 'failed' => 'danger', 'running' => 'info',
                        'pending', 'paused' => 'warning', default => 'gray',
                    })
                    ->description(function (Workflow $record): ?string {
                        $run = $record->latestRun;
                        if (! $run) {
                            return null;
                        }
                        $date = ($run->started_at ?? $run->created_at)?->timezone('Europe/Moscow')->format('d.m H:i');
                        $duration = $run->started_at && $run->completed_at
                            ? ' · '.round(abs($run->completed_at->diffInMilliseconds($run->started_at)) / 1000, 1).' с' : '';

                        return $date.$duration;
                    })
                    ->tooltip(fn (Workflow $record): ?string => $record->latestRun ? 'Открыть этот запуск в истории' : null)
                    ->url(fn (Workflow $record): ?string => $record->latestRun
                        ? static::getUrl('history', ['record' => $record, 'run' => $record->latestRun->getKey()]) : null),

                TextColumn::make('queued_runs_count')
                    ->label('В очереди')
                    ->alignCenter()
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (mixed $state): string => (string) (int) $state)
                    ->color(fn (mixed $state): string => (int) $state > 0 ? 'warning' : 'gray')
                    ->tooltip(fn (mixed $state): string => (int) $state > 0
                        ? 'Ожидают обработки: '.(int) $state
                        : 'Нет ожидающих выполнений'),

                ToggleColumn::make('is_active')
                    ->label('Активен')
                    ->alignCenter()
                    ->tooltip(fn (Workflow $record): string => $record->is_active ? 'Выключить процесс' : 'Включить процесс')
                    ->onColor('success')
                    ->offColor('gray')
                    ->updateStateUsing(fn (Workflow $record, mixed $state): bool => static::updateWorkflowActivation($record, (bool) $state))
                    ->visible(fn (): bool => ! (bool) auth()->user()?->is_root),
            ])
            ->recordUrl(fn (Workflow $record): string => static::getUrl('edit', ['record' => $record]))
            ->defaultSort('updated_at', 'desc')
            ->filters([])
            ->filtersTriggerAction(fn (Action $action): Action => $action->hidden())
            ->poll('5s')
            ->paginated(fn (): bool|array => (bool) auth()->user()?->is_root ? [25, 50, 100] : false)
            ->defaultPaginationPageOption(fn (): ?int => (bool) auth()->user()?->is_root ? 50 : null)
            ->searchPlaceholder('Поиск сценариев…')
            ->recordActions(
                [ActionGroup::make([
                    Action::make('configure_workflow')
                        ->label('Открыть редактор')
                        ->icon('heroicon-o-pencil-square')
                        ->url(fn (Workflow $record): string => static::getUrl('edit', ['record' => $record]))
                        ->extraAttributes(['class' => 'workflow-list-configure-action']),

                    Action::make('rename_workflow')
                        ->label('Переименовать')
                        ->icon('heroicon-o-pencil')
                        ->modalWidth('sm')
                        ->fillForm(fn (Workflow $record): array => ['name' => $record->name])
                        ->schema([TextInput::make('name')->label('Название')->required()->maxLength(255)])
                        ->action(fn (Workflow $record, array $data) => $record->forceFill(['name' => trim($data['name'])])->saveQuietly())
                        ->visible(fn (): bool => ! (bool) auth()->user()?->is_root),

                    Action::make('move_workflow')
                        ->label('Переместить в папку')
                        ->icon('heroicon-o-folder-arrow-down')
                        ->modalHeading('Переместить сценарий')
                        ->modalWidth('sm')
                        ->modalSubmitActionLabel('Переместить')
                        ->fillForm(fn (Workflow $record): array => ['group_name' => $record->group_name])
                        ->schema([Select::make('group_name')->label('Папка')->placeholder('Без папки')
                            ->options(fn (): array => WorkflowFolders::options())->searchable()])
                        ->action(function (Workflow $record, array $data): void {
                            WorkflowFolders::move($record, $data['group_name'] ?? null);
                            Notification::make()->title('Сценарий перемещён')->success()->send();
                        })
                        ->visible(fn (): bool => ! (bool) auth()->user()?->is_root),

                    Action::make('workflow_history')
                        ->label('История запусков')->icon('heroicon-o-clock')
                        ->url(fn (Workflow $record): string => static::getUrl('history', ['record' => $record])),

                    Action::make('duplicate_workflow')
                        ->label('Дублировать сценарий')
                        ->icon('heroicon-o-document-duplicate')
                        ->color('gray')
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
                        })
                        ->visible(fn (): bool => ! (bool) auth()->user()?->is_root),

                    DeleteAction::make()
                        ->label('Удалить сценарий')
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->requiresConfirmation(false)
                        ->modal(false)
                        ->successNotificationTitle('Сценарий удалён')
                        ->visible(fn (): bool => ! (bool) auth()->user()?->is_root),
                ])->label('Действия со сценарием')->icon('heroicon-o-ellipsis-horizontal')->color('gray')],
                position: RecordActionsPosition::AfterColumns,
            )
            ->emptyStateHeading('Сценариев пока нет')
            ->emptyStateDescription('Создайте сценарий или перенесите его сюда из другой папки.')
            ->emptyStateIcon('heroicon-o-folder');
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
        if (! ($data['is_active'] ?? false)) {
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
     * @param  array<string, mixed>|null  $definition
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private static function activationIssuesForDefinition(mixed $definition, ?Workflow $record = null, array $data = []): array
    {
        $definition = is_array($definition) ? $definition : [];
        $issues = \App\Services\Workflows\WorkflowDefinitionValidator::issues($definition);

        if (! AppWorkflow::definitionHasConfiguredActions($definition)) {
            $issues[] = 'Добавьте хотя бы одно действие.';
        }

        $triggerType = (string) data_get($definition, 'trigger.type');

        foreach (\App\Services\Workflows\WorkflowStartNodes::all($definition) as $start) {
            if ($duplicateIssue = static::uniqueAmoTriggerIssue((string) ($start['type'] ?? ''), $record, $data)) {
                $issues[] = $duplicateIssue;
            }
        }

        $actionTypes = static::workflowActionTypes((array) data_get($definition, 'actions', []));
        $unsupportedTypes = array_values(array_intersect(
            $actionTypes,
            WorkflowAmoCrmActionCatalog::unsupportedWorkflowTypes(),
        ));

        if ($unsupportedTypes !== []) {
            $issues[] = 'Удалите неподдержанные действия: '.implode(', ', static::workflowActionLabels($unsupportedTypes)).'.';
        }

        $unknownTypes = static::unknownActionTypes($actionTypes);

        if ($unknownTypes !== []) {
            $issues[] = 'Удалите неизвестные действия: '.implode(', ', $unknownTypes).'.';
        }

        if (static::hasNestedCondition((array) data_get($definition, 'actions', []))) {
            $issues[] = 'Вложенные условия временно отключены. Уберите условие внутри ветки Да/Нет.';
        }

        if ($triggerType === WorkflowCompletedTrigger::type() && $record instanceof Workflow && static::workflowCallParents($record) === []) {
            $issues[] = 'Добавьте в родительский процесс действие «Запустить процесс» и выберите этот процесс.';
        }

        if (! static::hasWorkflowAmoAccount($record, $data)) {
            $issues[] = 'Для включения сценария нужно активное подключение amoCRM к аккаунту платформы.';
        }

        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');
        $userId = (int) ($record?->{$tenantColumn} ?? ($data[$tenantColumn] ?? auth()->id()));
        if ($subscriptionIssue = app(WorkflowSubscriptionAccess::class)->activationIssue($userId)) {
            $issues[] = $subscriptionIssue;
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function uniqueAmoTriggerIssue(
        string $triggerType,
        ?Workflow $record = null,
        array $data = []
    ): ?string {
        if (! AppWorkflow::requiresUniqueActiveTrigger($triggerType)) {
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

        if (! $duplicate instanceof AppWorkflow) {
            return null;
        }

        $duplicateName = filled($duplicate->name) ? $duplicate->name : '#'.$duplicate->getKey();

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
            return (string) $triggerClass::name();
        }

        return $triggerType;
    }

    /**
     * @param  array<int, string>  $issues
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
     * @param  array<int, mixed>  $actions
     * @return array<int, string>
     */
    private static function workflowActionTypes(array $actions): array
    {
        $types = [];

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $type = (string) ($action['type'] ?? '');

            if ($type !== '') {
                $types[] = $type;
            }

            $config = (array) ($action['config'] ?? []);

            foreach (['true_actions', 'false_actions'] as $branchKey) {
                $types = array_merge($types, static::workflowActionTypes((array) ($config[$branchKey] ?? [])));
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * @param  array<int, string>  $types
     * @return array<int, string>
     */
    private static function unknownActionTypes(array $types): array
    {
        $registry = app(ActionRegistry::class);

        return array_values(array_filter(
            $types,
            static fn (string $type): bool => $type !== '' && ! $registry->has($type),
        ));
    }

    /**
     * @param  array<int, string>  $types
     * @return array<int, string>
     */
    private static function workflowActionLabels(array $types): array
    {
        $registry = app(ActionRegistry::class);

        return array_map(static function (string $type) use ($registry): string {
            $class = $registry->get($type);

            if (is_string($class) && method_exists($class, 'name')) {
                return (string) $class::name();
            }

            if (is_string($class) && method_exists($class, 'workflowName')) {
                return (string) $class::workflowName();
            }

            return $type;
        }, $types);
    }

    /**
     * @param  array<int, mixed>  $actions
     */
    private static function hasNestedCondition(array $actions, bool $insideConditionBranch = false): bool
    {
        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $isCondition = in_array((string) ($action['type'] ?? ''), ['condition', 'control-condition'], true)
                || ($action['componentType'] ?? null) === 'control-condition';

            if ($insideConditionBranch && $isCondition) {
                return true;
            }

            $config = (array) ($action['config'] ?? []);

            foreach (['true_actions', 'false_actions'] as $branchKey) {
                if (static::hasNestedCondition((array) ($config[$branchKey] ?? []), $insideConditionBranch || $isCondition)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<int, string>  $actionTypes
     */
    private static function definitionUsesAmoCrm(array $definition, array $actionTypes): bool
    {
        foreach (\App\Services\Workflows\WorkflowStartNodes::all($definition) as $start) {
            if (str_starts_with((string) ($start['type'] ?? ''), 'amocrm-')) {
                return true;
            }
        }

        foreach ($actionTypes as $type) {
            if (str_starts_with($type, 'amocrm_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function hasWorkflowAmoAccount(?Workflow $record = null, array $data = []): bool
    {
        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');
        $userId = (int) ($record?->{$tenantColumn} ?? ($data[$tenantColumn] ?? 0) ?: auth()->id());

        if ($userId <= 0) {
            return false;
        }

        return \App\Services\Workflows\WorkflowConnectionAccess::hasActiveConnection($userId);
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
                : $parents[0].' +'.(count($parents) - 1);
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
        $triggerType = (string) data_get($record->definition, 'trigger.type');

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
        if (! static::isWorkflowCallTrigger($record)) {
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
        ], fn (string $column): bool => array_key_exists($column, $attributes)));
    }

    /**
     * @return array<string, string>
     */
    private static function workflowCopyUniqueValues(Workflow $record, string $name): array
    {
        $values = [];

        foreach (static::workflowCopyUniqueColumns($record) as $column) {
            $values[$column] = match ($column) {
                'uuid', 'workflow_uuid' => (string) Str::uuid(),
                'ulid', 'public_id' => (string) Str::ulid(),
                'slug' => (Str::slug($name) ?: 'workflow-copy').'-'.Str::lower(Str::random(6)),
                'token' => Str::random(40),
                'secret', 'webhook_secret' => Str::random(48),
                default => Str::random(32),
            };
        }

        return $values;
    }

    private static function copyName(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return 'Копия процесса';
        }

        return str($name)->startsWith('Копия: ')
            ? $name.' (копия)'
            : 'Копия: '.$name;
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
            'replay' => Pages\ReplayWorkflow::route('/history/{run}/editor'),
            'history' => Pages\WorkflowHistory::route('/{record}/history'),
            'edit' => Pages\EditWorkflow::route('/{record}/edit'),
        ];
    }
}
