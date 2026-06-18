<?php

namespace App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\Concerns;

use App\Filament\App\Pages\WorkflowHelp;
use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Services\amoCRM\Client;
use App\Services\Workflows\WorkflowDependencyMap;
use App\Workflows\Engine\WorkflowTestRunner;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;
use Throwable;

trait HasWorkflowPageActions
{
    public ?string $insertActionPath = null;

    public ?int $insertActionIndex = null;

    public function selectTriggerType(string $type): void
    {
        $registry = app(TriggerRegistry::class);

        if (!$registry->has($type)) {
            Notification::make()
                ->danger()
                ->title(__('filament-workflows::workflows.notifications.invalid_trigger.title'))
                ->send();

            return;
        }

        $this->trigger = [
            'type' => $type,
            'config' => $type === 'manual' ? [] : $registry->getDefaultConfig($type),
        ];

        $this->syncDefinition();
        $this->unmountAction();

        if ($type === 'manual') {
            Notification::make()
                ->success()
                ->title('Ручной запуск выбран')
                ->body('У ручного запуска нет дополнительных настроек.')
                ->send();

            return;
        }

        $this->mountAction('configureTrigger');
    }

    protected function workflowHelpAction(): Action
    {
        return Action::make('workflow_help')
            ->label('Справка')
            ->icon('heroicon-o-question-mark-circle')
            ->color('gray')
            ->url(WorkflowHelp::getUrl());
    }

    protected function workflowMasksAction(): Action
    {
        return Action::make('workflow_masks')
            ->label('Переменные')
            ->icon('heroicon-o-variable')
            ->color('gray')
            ->alpineClickHandler("window.dispatchEvent(new CustomEvent('workflow-masks-open'))");
    }

    protected function workflowDependencyMapAction(): Action
    {
        return Action::make('workflow_dependency_map')
            ->label('Карта связей')
            ->icon('heroicon-o-map')
            ->color('gray')
            ->modalHeading('Карта связей процесса')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Закрыть')
            ->modalWidth('5xl')
            ->modalContent(function () {
                $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;

                return view('filament.workflow-builder.dependency-map', [
                    'workflow' => $record,
                    'map' => $record ? app(WorkflowDependencyMap::class)->forWorkflow($record) : [
                        'incoming' => [],
                        'outgoing' => [],
                    ],
                ]);
            });
    }

    protected function workflowDocumentationAction(): Action
    {
        return Action::make('workflow_documentation')
            ->label('PDF')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->url(function (): string {
                $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;

                return $record
                    ? route('workflow-builder.documentation.workflow', ['workflow' => $record])
                    : '#';
            })
            ->openUrlInNewTab();
    }

    protected function duplicateWorkflowAction(): Action
    {
        return Action::make('duplicate_workflow')
            ->label('Дублировать')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->action(fn() => $this->duplicateCurrentWorkflow());
    }

    public function duplicateCurrentWorkflow(): void
    {
        $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;

        if (!$record) {
            return;
        }

        $copy = WorkflowResource::duplicateWorkflow($record);

        Notification::make()
            ->success()
            ->title('Копия процесса создана')
            ->body($copy->name)
            ->actions([
                Action::make('open_copy')
                    ->label('Открыть копию')
                    ->url(WorkflowResource::getUrl('edit', ['record' => $copy]))
                    ->openUrlInNewTab(),
            ])
            ->send();
    }

    public function duplicateWorkflowActionStep(string $actionId): void
    {
        $path = $this->findActionPathById($actionId);

        if ($path === null) {
            return;
        }

        $parts = explode('.', $path);
        $index = (int)array_pop($parts);
        $parentPath = implode('.', $parts);
        $actions = $this->getArrayAtPath($parentPath);
        $source = $actions[$index] ?? null;

        if (!is_array($source)) {
            return;
        }

        array_splice($actions, $index + 1, 0, [$this->cloneWorkflowActionTree($source)]);
        $this->setArrayAtPath($parentPath, $actions);
        $this->syncDefinition();

        Notification::make()
            ->success()
            ->title('Шаг скопирован')
            ->send();
    }

    public function reorderWorkflowActions(string $path, int $fromIndex, int $toIndex): void
    {
        $actions = $this->getArrayAtPath($path);
        $count = count($actions);

        if ($count < 2) {
            return;
        }

        $fromIndex = max(0, min($fromIndex, $count - 1));
        $toIndex = max(0, min($toIndex, $count - 1));

        if ($fromIndex === $toIndex || !array_key_exists($fromIndex, $actions)) {
            return;
        }

        $movedAction = $actions[$fromIndex];
        unset($actions[$fromIndex]);

        $actions = array_values($actions);
        array_splice($actions, $toIndex, 0, [$movedAction]);

        $this->setArrayAtPath($path, $actions);
        $this->syncDefinition();
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function cloneWorkflowActionTree(array $action): array
    {
        $action['id'] = 'step_' . Str::lower(Str::ulid()->toBase32());

        foreach (['true_actions', 'false_actions'] as $branchKey) {
            if (!isset($action['config'][$branchKey]) || !is_array($action['config'][$branchKey])) {
                continue;
            }

            $action['config'][$branchKey] = array_map(
                fn(array $branchAction): array => $this->cloneWorkflowActionTree($branchAction),
                $action['config'][$branchKey],
            );
        }

        return $action;
    }

    protected function backToWorkflowListAction(): Action
    {
        return Action::make('back_to_workflow_list')
            ->label('К списку процессов')
            ->icon('heroicon-o-arrow-left')
            ->color('gray')
            ->url(WorkflowResource::getUrl('index'));
    }

    public function refreshWorkflowReference(): void
    {
        try {
            $account = Auth::user()?->resolveAmoAccountForWidget('workflows', false);

            if (!$account) {
                Notification::make()
                    ->title('amoCRM аккаунт не найден')
                    ->danger()
                    ->send();

                return;
            }

            new Client($account);

            $exitCode = Artisan::call('app:sync', ['account' => $account->id]);

            if ($exitCode !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'Команда синхронизации завершилась с ошибкой.');
            }

            Notification::make()
                ->title('Справочник обновлен')
                ->body('Поля, воронки, этапы и ответственные amoCRM загружены заново.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            Log::error('Workflow reference refresh failed', [
                'user_id' => Auth::id(),
                'account_id' => $account->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            Notification::make()
                ->title('Не удалось обновить справочник')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * @return array<int, Component>
     */
    protected function getTestInputFormSchema(): array
    {
        return [];
    }

    public function runWorkflowTest(): void
    {
        $this->testInputs['_workflow_id'] = method_exists($this, 'getRecord')
            ? $this->getRecord()?->getKey()
            : null;

        try {
            $this->syncDefinition();

            /** @var WorkflowTestRunner $testRunner */
            $testRunner = app(WorkflowTestRunner::class);
            $testModel = $this->resolveWorkflowTestModel();

            $this->testResults = $testRunner->test(
                definition: $this->definition,
                testInputs: $this->testInputs,
                testModel: $testModel,
            );

            if ($this->testResults['success'] ?? false) {
                Notification::make()
                    ->success()
                    ->title('Тест выполнен')
                    ->body('Результаты теста показаны в этом окне.')
                    ->send();

                return;
            }

            Notification::make()
                ->danger()
                ->title('Тест завершился с ошибкой')
                ->body((string)($this->testResults['error'] ?? 'Посмотрите детали в результате теста.'))
                ->send();
        } catch (Throwable $e) {
            Log::error('Workflow test failed before result rendering', [
                'workflow_id' => $this->testInputs['_workflow_id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->testResults = [
                'tested' => true,
                'success' => false,
                'trigger' => null,
                'steps' => [],
                'variables' => [],
                'error' => $e->getMessage(),
                'completed_at' => now()->toIso8601String(),
            ];

            Notification::make()
                ->danger()
                ->title('Тест не запустился')
                ->body($e->getMessage())
                ->persistent()
                ->send();
        }
    }

    private function resolveWorkflowTestModel(): ?Model
    {
        $triggerModelClass = $this->getTriggerModelClass();

        if (!$triggerModelClass) {
            return null;
        }

        if (!empty($this->testInputs['_model_id'])) {
            /** @var class-string<Model> $triggerModelClass */
            $model = $triggerModelClass::find($this->testInputs['_model_id']);

            if (!$model) {
                Notification::make()
                    ->warning()
                    ->title('Запись для теста не найдена')
                    ->body('ID записи: ' . $this->testInputs['_model_id'])
                    ->send();
            }

            return $model;
        }

        try {
            /** @var class-string<Model> $triggerModelClass */
            return method_exists($triggerModelClass, 'factory')
                ? $triggerModelClass::factory()->make()
                : null;
        } catch (Throwable $e) {
            Log::warning('Workflow test model factory failed', [
                'model' => $triggerModelClass,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function openAddActionAtPath(string $path, int $index): void
    {
        $this->insertActionPath = $path;
        $this->insertActionIndex = max(0, $index);
        $this->targetPath = $path !== '' ? $path : null;

        $this->mountAction('addWorkflowAction');
    }

    public function selectActionType(string $type): void
    {
        $registry = app(ActionRegistry::class);

        if (!$registry->has($type)) {
            Notification::make()
                ->danger()
                ->title(__('filament-workflows::workflows.notifications.invalid_action.title'))
                ->send();

            return;
        }

        $path = (string)($this->insertActionPath ?? $this->targetPath ?? '');

        if ($type === 'control-condition' && $this->isConditionBranchPath($path)) {
            Notification::make()
                ->warning()
                ->title('Вложенные условия временно отключены')
                ->body('Добавьте условие на верхнем уровне процесса.')
                ->send();

            return;
        }

        $actions = $this->getArrayAtPath($path);
        $index = $this->insertActionIndex ?? count($actions);
        $index = min(max(0, (int)$index), count($actions));
        $config = $this->prepareWorkflowActionConfig($type, $registry->getDefaultConfig($type), $path, $index);

        $actionId = 'step_' . Str::lower(Str::ulid()->toBase32());
        $newAction = [
            'id' => $actionId,
            'type' => $type,
            'componentType' => str_starts_with($type, 'control-') ? $type : 'task',
            'name' => null,
            'config' => $config,
        ];

        array_splice($actions, $index, 0, [$newAction]);
        $this->setArrayAtPath($path, $actions);

        $this->insertActionPath = null;
        $this->insertActionIndex = null;
        $this->targetPath = null;
        $this->editingActionId = $actionId;
        $this->isNewAction = true;

        $this->syncDefinition();
        $this->unmountAction();
        $this->mountAction('configureWorkflowAction', ['actionId' => $actionId]);
    }

    private function isConditionBranchPath(string $path): bool
    {
        return str_contains($path, '.config.true_actions')
            || str_contains($path, '.config.false_actions')
            || str_starts_with($path, 'config.true_actions')
            || str_starts_with($path, 'config.false_actions');
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function prepareWorkflowActionConfig(string $type, array $config, string $path, int $index): array
    {
        if ($type !== 'amocrm_find_entity') {
            return $config;
        }

        $entity = (string)($config['target_entity'] ?? 'lead');
        $config['context_key'] = $this->nextFindResultKey($entity, $path, $index);

        return $config;
    }

    private function nextFindResultKey(string $entity, string $path, int $insertIndex): string
    {
        $entity = in_array($entity, ['lead', 'contact', 'company', 'customer'], true) ? $entity : 'lead';
        $actions = array_slice($this->getArrayAtPath($path), 0, max(0, $insertIndex));
        $count = 0;

        foreach ($actions as $action) {
            if (!is_array($action) || ($action['type'] ?? null) !== 'amocrm_find_entity') {
                continue;
            }

            if ((string)($action['config']['target_entity'] ?? 'lead') === $entity) {
                $count++;
            }
        }

        return 'found_' . $entity . '_' . ($count + 1);
    }
}
