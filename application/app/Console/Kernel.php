<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->useCache(config('cache.schedule_store', config('cache.default', 'file')));

        $dbConnection = config('database.default', 'pgsql');

        $schedule->command('app:monitor-heartbeat')
            ->everyMinute();

        $schedule->command('app:monitor-queue-health')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('app:queue-backfill-failed --limit=1000')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('horizon:snapshot')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        $schedule->command('horizon:sync-platform-monitoring')
            ->everyThirtyMinutes()
            ->withoutOverlapping();

        $schedule->command('workflows:fail-stuck-runs')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('app:check-date-expire')
            ->dailyAt('01:00');

        $schedule->command('subscriptions:notify-expiring --days=7')
            ->dailyAt('01:20')
            ->withoutOverlapping();

        $schedule->command('subscriptions:expire')
            ->dailyAt('01:30')
            ->withoutOverlapping();

        $schedule->command('app:clear-month-log --days=14')
            ->dailyAt('02:00');

        $schedule->command('app:api-requests-prune --days=' . (int)env('API_REQUESTS_RETENTION_DAYS', 30))
            ->dailyAt('03:00')
            ->withoutOverlapping();

        if ((bool)env('YCLIENTS_RECORDS_PRUNE_ENABLED', false)) {
            $schedule->command('yc:prune-records --days=' . (int)env('YCLIENTS_RECORDS_RETENTION_DAYS', 5))
                ->dailyAt('03:30')
                ->withoutOverlapping();
        }

        $schedule->command(sprintf(
            'workflows:db-maintenance --days=%d --raw-days=%d --run-days=%d',
            (int)env('WORKFLOWS_DB_MAINTENANCE_DAYS', 45),
            (int)env('WORKFLOWS_RAW_RETENTION_DAYS', 7),
            (int)env('WORKFLOWS_RUN_RETENTION_DAYS', 31),
        ))
            ->dailyAt(env('WORKFLOWS_DB_MAINTENANCE_TIME', '03:45'))
            ->withoutOverlapping();

        $schedule->command('backup:run --db-name=' . $dbConnection . ' --only-db')
            ->dailyAt('00:00');

    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
