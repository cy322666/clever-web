<?php

namespace App\Providers;

use App\Observers\QueueMonitorObserver;
use Croustibat\FilamentJobsMonitor\Models\QueueMonitor;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        QueueMonitor::observe(QueueMonitorObserver::class);

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

            Cache::add(self::DB_SLOW_QUERY_TOTAL_KEY, 0, now()->addYear());
            Cache::increment(self::DB_SLOW_QUERY_TOTAL_KEY);
            Cache::forever(self::DB_SLOW_QUERY_LAST_MS_KEY, (float)$query->time);
            Cache::forever(self::DB_SLOW_QUERY_LAST_SEEN_KEY, now()->timestamp);

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
