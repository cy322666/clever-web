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
        $schedule->command('app:monitor-heartbeat')
            ->everyMinute();

        $schedule->command('app:monitor-queue-health')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('app:queue-backfill-failed --limit=1000')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('app:check-date-expire')
            ->dailyAt('01:00');

        $schedule->command('app:clear-month-log --days=14')
            ->dailyAt('02:00');

        $schedule->command('backup:run --db-name=' . env('DB_CONNECTION') . ' --only-db')
            ->dailyAt('00:00');

        $schedule->command('app:amo-data-sync-periodic')
            ->everyThirtyMinutes();
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
