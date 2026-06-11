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
            $account = Auth::user()?->resolveAmoAccountForWidget(null, false);

            if (!$account) {
                Notification::make()
                    ->title('amoCRM аккаунт не найден')
                    ->danger()
                    ->send();

                return;
            }

            $amoApi = new Client($account);
            $amoApi->init();

            if (!$amoApi->auth) {
                Notification::make()
                    ->title('Ошибка авторизации amoCRM')
                    ->body('Подключите amoCRM заново и повторите обновление справочника.')
                    ->danger()
                    ->send();

                return;
            }

            Artisan::call('app:sync', ['account' => $account->id]);

            Notification::make()
                ->title('Справочник обновлен')
                ->body('Поля, воронки, этапы и ответственные amoCRM загружены заново.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            Log::error('Workflow reference refresh failed', [
                'user_id' => Auth::id(),
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Не удалось обновить справочник')
                ->body('Попробуйте еще раз или проверьте подключение amoCRM.')
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
