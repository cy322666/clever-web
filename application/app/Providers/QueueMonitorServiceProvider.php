<?php

namespace App\Providers;

use Croustibat\FilamentJobsMonitor\Models\QueueMonitor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class QueueMonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('model:prune', [
                '--model' => [QueueMonitor::class],
            ])
                ->dailyAt('02:30')
                ->withoutOverlapping();
        });
    }
}
