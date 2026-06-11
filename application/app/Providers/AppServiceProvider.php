<?php

namespace App\Providers;

use App\Observers\QueueMonitorObserver;
use App\Services\Core\MonitoringCache;
use App\Services\Workflows\WorkflowAmoCrmWebhookService;
use App\Services\Workflows\WorkflowVariableService;
use App\Models\Workflows\Workflow;
use App\Workflows\Actions\ControlConditionAction;
use App\Workflows\Actions\WorkflowAmoCrmActionCatalog;
use App\Workflows\Actions\MultiChannelNotificationAction;
use App\Workflows\Actions\RunWorkflowAction;
use App\Workflows\Engine\WorkflowExecutor as AppWorkflowExecutor;
use App\Workflows\Engine\WorkflowTestRunner as AppWorkflowTestRunner;
use App\Workflows\Triggers\AmoCrmWebhookTriggerCatalog;
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
use Leek\FilamentWorkflows\Actions\ActionRegistry;
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

    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        Workflow::saved(function (Workflow $workflow): void {
            $userId = (int)($workflow->{config('filament-workflows.tenancy.column', 'user_id')} ?? 0);

            if ($userId <= 0) {
                return;
            }

            app(WorkflowAmoCrmWebhookService::class)->synchronizeUser($userId);
        });

        Workflow::deleted(function (Workflow $workflow): void {
            $userId = (int)($workflow->{config('filament-workflows.tenancy.column', 'user_id')} ?? 0);

            if ($userId <= 0) {
                return;
            }

            app(WorkflowAmoCrmWebhookService::class)->synchronizeUser($userId);
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

        DB::listen(function (QueryExecuted $query) use ($thresholdMs, $sampleSql): void {
            if ($query->time < $thresholdMs) {
                return;
            }

            MonitoringCache::add(self::DB_SLOW_QUERY_TOTAL_KEY, 0, 31536000);
            MonitoringCache::increment(self::DB_SLOW_QUERY_TOTAL_KEY);
            MonitoringCache::forever(self::DB_SLOW_QUERY_LAST_MS_KEY, (float)$query->time);
            MonitoringCache::forever(self::DB_SLOW_QUERY_LAST_SEEN_KEY, now()->timestamp);

            $context = [
                'time_ms' => round((float)$query->time, 2),
                'connection' => $query->connectionName,
            ];

            if ($sampleSql) {
                $context['sql'] = $query->toRawSql();
            }

            Log::warning('Slow database query detected.', $context);
        });
    }
}
