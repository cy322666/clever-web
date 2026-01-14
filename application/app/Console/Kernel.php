<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('telescope:prune')
            ->dailyAt('00:00');

//        $schedule->command('app:failed-jobs')->everyMinute();
        $schedule->command('app:check-date-expire')
            ->dailyAt('01:00');

        $schedule->command('backup:run --db-name='.env('DB_CONNECTION').' --only-db')
            ->dailyAt('00:00');

        // $schedule->command('inspire')->hourly();
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
