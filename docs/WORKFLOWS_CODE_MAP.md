# Карта кода потоков

Корень Laravel-приложения: `application/`. Начинай с [WorkflowResource.php](../application/app/Filament/WorkflowBuilder/Resources/WorkflowResource.php): метод `getPages()` показывает, какие страницы реально открываются. Сейчас редактирование ведёт в `Pages/EditWorkflow.php`, история в `Pages/WorkflowHistory.php`.

## Редактор и полотно

| Что искать | Где |
| --- | --- |
| Страница редактирования, сохранение и доступ | [EditWorkflow.php](../application/app/Filament/WorkflowBuilder/Resources/WorkflowResource/Pages/EditWorkflow.php) |
| Разметка полотна, карточки нод и кнопки | [workflow-builder.blade.php](../application/resources/views/vendor/filament-workflows/components/workflow-builder.blade.php) и соседние файлы в `components/workflows/` |
| Клиентское поведение: перетаскивание, связи, меню, панель ноды, отладка | [app.js](../application/resources/js/app.js), ищи `workflowNodeCanvas`, `workflowNodeLibrary`, `workflowInspector`, `workflowDebugPanel` |
| Локальные изменения графа до сохранения | [workflow-editor-draft.mjs](../application/resources/js/workflow-editor-draft.mjs) и [workflow-browser-document.mjs](../application/resources/js/workflow-browser-document.mjs) |
| Настройки ноды в правой панели | [HasCompactWorkflowConfigurationPanels.php](../application/app/Filament/WorkflowBuilder/Resources/WorkflowResource/Pages/Concerns/HasCompactWorkflowConfigurationPanels.php) и [workflow-inspector.blade.php](../application/resources/views/filament/workflow-builder/workflow-inspector.blade.php) |
| Внешний вид | [filament-workflows.css](../application/resources/css/filament-workflows.css) и [workflow-inspector.css](../application/resources/css/workflow-inspector.css) |

[BrowserEditWorkflow.php](../application/app/Filament/WorkflowBuilder/Resources/WorkflowResource/Pages/BrowserEditWorkflow.php) и [workflow-browser-editor.mjs](../application/resources/js/workflow-browser-editor.mjs) содержат отдельный браузерный редактор, но текущий маршрут `edit` на него не указывает. Для правки действующего экрана начинай с файлов выше. Код пакета-основы лежит в `application/vendor/leek/filament-workflows/src/`; изменения продукта лучше искать в `application/app/` и `application/resources/`, а не в `vendor/`.

## Ноды, данные и выполнение

| Что искать | Где |
| --- | --- |
| Какие ноды доступны в меню | [AppServiceProvider.php](../application/app/Providers/AppServiceProvider.php), методы `registerWorkflowActions()` и `registerWorkflowTriggers()` |
| Настройки и выполнение конкретного действия | `application/app/Workflows/Actions/`; amoCRM-действия собраны в [WorkflowAmoCrmActionCatalog.php](../application/app/Workflows/Actions/WorkflowAmoCrmActionCatalog.php) |
| Способы запуска | `application/app/Workflows/Triggers/`; события amoCRM в [AmoCrmWebhookTriggerCatalog.php](../application/app/Workflows/Triggers/AmoCrmWebhookTriggerCatalog.php) |
| Связи, порядок нод и проверка схемы | [WorkflowGraph.php](../application/app/Services/Workflows/WorkflowGraph.php) и [WorkflowDefinitionValidator.php](../application/app/Services/Workflows/WorkflowDefinitionValidator.php) |
| Переменные и подсказки выражений | [WorkflowVariableService.php](../application/app/Services/Workflows/WorkflowVariableService.php) и [WorkflowExpressionCatalog.php](../application/app/Services/Workflows/WorkflowExpressionCatalog.php) |
| Реальный запуск, цикл, тест и отладка | [WorkflowExecutor.php](../application/app/Workflows/Engine/WorkflowExecutor.php), [WorkflowLoopRunner.php](../application/app/Services/Workflows/WorkflowLoopRunner.php), [WorkflowTestRunner.php](../application/app/Workflows/Engine/WorkflowTestRunner.php), [WorkflowDebugger.php](../application/app/Workflows/Engine/WorkflowDebugger.php) |
| Входящие вебхуки и запуск по расписанию | [api.php](../application/routes/api.php), [WorkflowWebhookController.php](../application/app/Http/Controllers/Api/WorkflowWebhookController.php), [ProcessScheduledWorkflows.php](../application/app/Console/Commands/Workflows/ProcessScheduledWorkflows.php) |
| Сохранённые потоки и запуски | [Workflow.php](../application/app/Models/Workflows/Workflow.php), [WorkflowRun.php](../application/app/Models/Workflows/WorkflowRun.php); схема таблиц в `application/database/migrations/*workflow*` |

Схема нод и связей хранится в JSON-поле `workflows.definition`. История выполнения хранится отдельно в `workflow_runs` и `workflow_run_steps`. Для отображения результатов без служебных данных смотри `application/app/Services/Workflows/WorkflowOutputView.php`.

## С чего начать правку

- Кнопка, карточка или линия: Blade-шаблон полотна, затем `app.js` и CSS.
- Поле настроек ноды: соответствующий класс в `Workflows/Actions/` или `Workflows/Triggers/`, затем `HasCompactWorkflowConfigurationPanels.php`.
- Ошибка при исполнении: `WorkflowExecutor.php`, затем класс действия и профильный сервис в `Services/Workflows/`.
- История и отладка: `WorkflowHistory.php`, `HasWorkflowDebugger.php`, `WorkflowDebugger.php` и Blade-файлы `workflow-history-page.blade.php`, `workflow-debugger.blade.php`.

Тесты: `application/tests/Unit/Workflows/` для PHP-логики и `application/tests/JavaScript/workflow-*.test.mjs` для редактора. Для поиска по коду из корня проекта: `rg -n 'имя_метода_или_ноды' application/app application/resources application/tests`.
