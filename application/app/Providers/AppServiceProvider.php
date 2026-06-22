<?php

namespace App\Providers;

use App\Jobs\Workflows\SynchronizeAmoCrmWebhooks;
use App\Models\Workflows\Workflow;
use App\Models\Workflows\WorkflowRun as AppWorkflowRun;
use App\Observers\QueueMonitorObserver;
use App\Services\Core\MonitoringCache;
use App\Services\Workflows\WorkflowRunEntityIndexService;
use App\Services\Workflows\WorkflowVariableService;
use App\Support\View\SafeBladeCompiler;
use App\Workflows\Actions\ControlConditionAction;
use App\Workflows\Actions\MultiChannelNotificationAction;
use App\Workflows\Actions\RunWorkflowAction;
use App\Workflows\Actions\WorkflowAmoCrmActionCatalog;
use App\Workflows\Engine\WorkflowExecutor as AppWorkflowExecutor;
use App\Workflows\Engine\WorkflowTestRunner as AppWorkflowTestRunner;
use App\Workflows\Triggers\AmoCrmWebhookTriggerCatalog;
use App\Workflows\Triggers\GenericWebhookTrigger;
use App\Workflows\Triggers\WorkflowCompletedTrigger;
use Croustibat\FilamentJobsMonitor\Models\QueueMonitor;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\DynamicComponent;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Models\WorkflowRunStep;
use Leek\FilamentWorkflows\Triggers\DateConditionTrigger;
use Leek\FilamentWorkflows\Triggers\ManualTrigger;
use Leek\FilamentWorkflows\Triggers\ScheduleTrigger;
use Leek\FilamentWorkflows\Triggers\TriggerRegistry;
use Studio\Totem\Totem;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    private const DB_SLOW_QUERY_TOTAL_KEY = 'monitoring:db:slow_queries:total';

    private const DB_SLOW_QUERY_LAST_MS_KEY = 'monitoring:db:slow_queries:last_ms';

    private const DB_SLOW_QUERY_LAST_SEEN_KEY = 'monitoring:db:slow_queries:last_seen_unixtime';

    private const DB_SLOW_QUERY_LAST_CONTEXT_KEY = 'monitoring:db:slow_queries:last_context';

    private const DB_SLOW_QUERY_CONTEXT_INDEX_KEY = 'monitoring:db:slow_queries:context_index';

    private const DB_SLOW_QUERY_CONTEXT_COUNTER_PREFIX = 'monitoring:db:slow_queries:context_total:';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerSafeBladeCompiler();

        if (!$this->workflowsAvailable()) {
            return;
        }

        $this->app->singleton(
            \Leek\FilamentWorkflows\Engine\WorkflowExecutor::class,
            fn($app): AppWorkflowExecutor => new AppWorkflowExecutor($app->make(ActionRegistry::class)),
        );

        $this->app->singleton(
            \Leek\FilamentWorkflows\Engine\WorkflowTestRunner::class,
            fn($app): AppWorkflowTestRunner => new AppWorkflowTestRunner($app->make(ActionRegistry::class)),
        );

        $this->app->singleton(
            \Leek\FilamentWorkflows\Services\WorkflowVariableService::class,
            WorkflowVariableService::class,
        );
    }

    private function registerSafeBladeCompiler(): void
    {
        $this->app->forgetInstance('blade.compiler');

        $this->app->singleton('blade.compiler', function ($app) {
            return tap(new SafeBladeCompiler(
                $app['files'],
                $app['config']['view.compiled'],
                $app['config']->get('view.relative_hash', false) ? $app->basePath() : '',
                $app['config']->get('view.cache', true),
                $app['config']->get('view.compiled_extension', 'php'),
                $app['config']->get('view.check_cache_timestamps', true),
            ), function (SafeBladeCompiler $blade): void {
                $blade->component('dynamic-component', DynamicComponent::class);
            });
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        QueueMonitor::observe(QueueMonitorObserver::class);

        FilamentView::registerRenderHook(
            PanelsRenderHook::STYLES_BEFORE,
            fn(): string => $this->optionalViteAsset('resources/css/filament-workflows.css'),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_AFTER,
            fn(): string => $this->optionalViteAsset('resources/js/app.js'),
        );

        FilamentAsset::register([
            Js::make('amochat', resource_path('js/amochat.js')),
        ]);

        //        Totem::auth(function(Request $request) {
        //
        //            return $request->user()->is_root;
        //        });

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        $this->disableTelescopeWhenNotEnabled();
        $this->registerSlowQueryMonitoring();

        if ($this->workflowsAvailable()) {
            $this->registerWorkflowTriggers();
            $this->registerWorkflowActions();
            $this->registerWorkflowWebhookSynchronization();
            $this->registerWorkflowRunEntityIndexing();
        }
    }

    private function workflowsAvailable(): bool
    {
        return class_exists(ActionRegistry::class)
            && class_exists(TriggerRegistry::class)
            && class_exists(\Leek\FilamentWorkflows\Engine\WorkflowExecutor::class)
            && class_exists(\Leek\FilamentWorkflows\Models\Workflow::class);
    }

    private function optionalViteAsset(string $asset): string
    {
        try {
            return (string)app(Vite::class)($asset);
        } catch (Throwable) {
            return '';
        }
    }

    private function registerWorkflowTriggers(): void
    {
        $this->app->booted(function (): void {
            if (!$this->app->bound(TriggerRegistry::class)) {
                return;
            }

            /** @var TriggerRegistry $registry */
            $registry = $this->app->make(TriggerRegistry::class);
            $registry->flush();

            $registry->register(ManualTrigger::class);
            $registry->register(ScheduleTrigger::class);
            $registry->register(DateConditionTrigger::class);
            $registry->register(WorkflowCompletedTrigger::class);
            $registry->register(GenericWebhookTrigger::class);

            foreach (AmoCrmWebhookTriggerCatalog::classes() as $triggerClass) {
                $registry->register($triggerClass);
            }
        });
    }

    private function registerWorkflowActions(): void
    {
        $this->app->booted(function (): void {
            if (!$this->app->bound(ActionRegistry::class)) {
                return;
            }

            /** @var ActionRegistry $registry */
            $registry = $this->app->make(ActionRegistry::class);
            $registry->flush();
            $this->clearWorkflowActionPromotions($registry);

            $registry->register(ControlConditionAction::class);
            $registry->register(RunWorkflowAction::class);
            $registry->register(MultiChannelNotificationAction::class);

            foreach (WorkflowAmoCrmActionCatalog::classes() as $actionClass) {
                $registry->register($actionClass);
            }

            $registry->setSortOrder([
                'control-condition',
                'run_workflow',
                'send_notification',
                'amocrm_create_lead',
                'amocrm_create_contact',
                'amocrm_create_company',
                'amocrm_copy_lead',
                'amocrm_update_lead_fields',
                'amocrm_update_contact_fields',
                'amocrm_update_company_fields',
                'amocrm_create_task',
                'amocrm_add_note',
                'amocrm_change_tags',
                'amocrm_change_lead_status',
                'amocrm_distribution_queue',
                'amocrm_start_salesbot',
                'amocrm_stop_salesbot',
                'amocrm_manage_subscription',
                'amocrm_update_task',
                'amocrm_cancel_delayed_action',
                'amocrm_normalize_contact_data',
                'amocrm_add_products',
                'amocrm_remove_products',
                'amocrm_find_entity',
                'amocrm_link_entity',
                'amocrm_unlink_entity',
            ]);
        });
    }

    private function registerWorkflowWebhookSynchronization(): void
    {
        Workflow::created(function (Workflow $workflow): void {
            $userId = (int)($workflow->{config('filament-workflows.tenancy.column', 'user_id')} ?? 0);

            if ($userId <= 0) {
                return;
            }

            $this->dispatchWorkflowWebhookSynchronization($userId);
        });

        Workflow::updated(function (Workflow $workflow): void {
            if (!$this->workflowWebhookTriggerChanged($workflow)) {
                return;
            }

            $userId = (int)($workflow->{config('filament-workflows.tenancy.column', 'user_id')} ?? 0);

            if ($userId <= 0) {
                return;
            }

            $this->dispatchWorkflowWebhookSynchronization($userId);
        });

        Workflow::deleted(function (Workflow $workflow): void {
            $userId = (int)($workflow->{config('filament-workflows.tenancy.column', 'user_id')} ?? 0);

            if ($userId <= 0) {
                return;
            }

            $this->dispatchWorkflowWebhookSynchronization($userId);
        });
    }

    private function workflowWebhookTriggerChanged(Workflow $workflow): bool
    {
        if ($workflow->wasChanged([
            config('filament-workflows.tenancy.column', 'user_id'),
            'is_active',
            'trigger_type',
            'trigger_model_type',
            'trigger_event',
            'trigger_conditions',
            'trigger_schedule',
        ])) {
            return true;
        }

        if (!$workflow->wasChanged('definition')) {
            return false;
        }

        return $this->workflowDefinitionTrigger($workflow->getRawOriginal('definition'))
            != data_get($workflow->definition, 'trigger');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function workflowDefinitionTrigger(mixed $definition): ?array
    {
        if (is_string($definition)) {
            $definition = json_decode($definition, true);
        }

        if (!is_array($definition)) {
            return null;
        }

        $trigger = data_get($definition, 'trigger');

        return is_array($trigger) ? $trigger : null;
    }

    private function dispatchWorkflowWebhookSynchronization(int $userId): void
    {
        $dispatch = SynchronizeAmoCrmWebhooks::dispatch($userId);

        if (!$this->app->runningInConsole()) {
            $dispatch->afterResponse();
        }
    }

    private function registerWorkflowRunEntityIndexing(): void
    {
        /** @var class-string<AppWorkflowRun> $runModelClass */
        $runModelClass = config('filament-workflows.models.workflow_run', AppWorkflowRun::class);

        /** @var class-string<WorkflowRunStep> $stepModelClass */
        $stepModelClass = config('filament-workflows.models.workflow_run_step', WorkflowRunStep::class);

        $runModelClass::saved(function ($run): void {
            if (!$run->wasChanged('context_data')) {
                return;
            }

            app(WorkflowRunEntityIndexService::class)->indexRun($run);
        });

        $stepModelClass::saved(function ($step): void {
            if (!$step->wasChanged('output_data') && !$step->wasChanged('input_data')) {
                return;
            }

            app(WorkflowRunEntityIndexService::class)->indexStep($step);
        });
    }

    private function clearWorkflowActionPromotions(ActionRegistry $registry): void
    {
        try {
            $property = new \ReflectionProperty($registry, 'promotions');
            $property->setAccessible(true);
            $property->setValue($registry, []);
        } catch (Throwable $e) {
            Log::warning('Failed to clear workflow action promotions.', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function disableTelescopeWhenNotEnabled(): void
    {
        if ((bool)env('TELESCOPE_ENABLED', false)) {
            return;
        }

        if (!class_exists(\Laravel\Telescope\Telescope::class)) {
            return;
        }

        try {
            \Laravel\Telescope\Telescope::stopRecording();
        } catch (Throwable $e) {
            Log::warning('Failed to disable Telescope recording.', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function registerSlowQueryMonitoring(): void
    {
        $thresholdMs = (int)config('database.monitoring.slow_query_threshold_ms', 1000);

        if ($thresholdMs <= 0) {
            return;
        }

        $sampleSql = (bool)config('database.monitoring.slow_query_sample_sql', false);
        $lastTtlSeconds = max(60, (int)config('database.monitoring.slow_query_last_ttl_seconds', 3600));

        DB::listen(function (QueryExecuted $query) use ($thresholdMs, $sampleSql, $lastTtlSeconds): void {
            if ($query->time < $thresholdMs) {
                return;
            }

            $source = $this->resolveSlowQuerySource();
            $connection = $query->connectionName ?: 'default';
            $timeMs = round((float)$query->time, 2);
            $seenAt = now()->timestamp;

            MonitoringCache::add(self::DB_SLOW_QUERY_TOTAL_KEY, 0, 31536000);
            MonitoringCache::increment(self::DB_SLOW_QUERY_TOTAL_KEY);
            MonitoringCache::put(self::DB_SLOW_QUERY_LAST_MS_KEY, $timeMs, $lastTtlSeconds);
            MonitoringCache::put(self::DB_SLOW_QUERY_LAST_SEEN_KEY, $seenAt, $lastTtlSeconds);
            MonitoringCache::put(self::DB_SLOW_QUERY_LAST_CONTEXT_KEY, [
                'connection' => $connection,
                'source' => $source['type'],
                'name' => $source['name'],
                'time_ms' => $timeMs,
                'seen_at' => $seenAt,
            ], $lastTtlSeconds);

            $this->incrementSlowQueryContextCounter($connection, $source['type'], $source['name']);

            $context = [
                'time_ms' => $timeMs,
                'threshold_ms' => $thresholdMs,
                'connection' => $connection,
                'source' => $source['type'],
                'source_name' => $source['name'],
            ];

            if ($sampleSql) {
                $context['sql'] = $query->toRawSql();
            }

            Log::warning('Slow database query detected.', $context);
        });
    }

    /**
     * @return array{type: string, name: string}
     */
    private function resolveSlowQuerySource(): array
    {
        if ($this->app->bound('request')) {
            /** @var Request $request */
            $request = $this->app->make('request');

            if ($request->server->has('REQUEST_METHOD')) {
                $route = $request->route();
                $routeName = is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;
                $routeUri = is_object($route) && method_exists($route, 'uri') ? $route->uri() : null;
                $name = $routeName ?: trim($request->method() . ' ' . ($routeUri ?: $request->path()));

                return [
                    'type' => 'http',
                    'name' => $this->normalizeMetricLabelValue($name),
                ];
            }
        }

        if (!$this->app->runningInConsole()) {
            return ['type' => 'unknown', 'name' => 'unknown'];
        }

        return [
            'type' => 'console',
            'name' => $this->normalizeMetricLabelValue($this->resolveConsoleCommandName()),
        ];
    }

    private function resolveConsoleCommandName(): string
    {
        $argv = $_SERVER['argv'] ?? [];

        if (!is_array($argv) || $argv === []) {
            return 'artisan';
        }

        $parts = array_values(array_filter(
            array_map(static fn(mixed $part): string => trim((string)$part), $argv),
            static fn(string $part): bool => $part !== '' && !str_starts_with($part, '--')
        ));

        $command = $parts[1] ?? $parts[0] ?? 'artisan';

        if ($command === 'horizon' || $command === 'queue:work') {
            return $command;
        }

        return str_starts_with($command, 'artisan') ? 'artisan' : $command;
    }

    private function normalizeMetricLabelValue(string $value): string
    {
        $value = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '{uuid}', $value) ?? $value;
        $value = preg_replace('/\b\d{4,}\b/', '{id}', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = trim($value);

        return mb_substr($value === '' ? 'unknown' : $value, 0, 120);
    }

    private function incrementSlowQueryContextCounter(string $connection, string $source, string $name): void
    {
        $contextKey = sha1($connection . '|' . $source . '|' . $name);
        $index = MonitoringCache::get(self::DB_SLOW_QUERY_CONTEXT_INDEX_KEY, []);

        if (!is_array($index)) {
            $index = [];
        }

        $index[$contextKey] = [
            'connection' => $connection,
            'source' => $source,
            'name' => $name,
        ];

        if (count($index) > 100) {
            $index = array_slice($index, -100, null, true);
        }

        MonitoringCache::forever(self::DB_SLOW_QUERY_CONTEXT_INDEX_KEY, $index);
        MonitoringCache::add(self::DB_SLOW_QUERY_CONTEXT_COUNTER_PREFIX . $contextKey, 0, 31536000);
        MonitoringCache::increment(self::DB_SLOW_QUERY_CONTEXT_COUNTER_PREFIX . $contextKey);
    }
}
