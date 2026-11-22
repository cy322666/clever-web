<?php

namespace App\Providers;

use Croustibat\FilamentJobsMonitor\Models\QueueMonitor;
use App\Services\Core\QueueMonitorLifecycle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class QueueMonitorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Run after package listeners, which treat a returned handle() as success
        // even when the job explicitly called fail().
        $this->app->booted(function (): void {
            Queue::failing(static function (JobFailed $event): void {
                app(QueueMonitorLifecycle::class)->failed($event->job, $event->exception);
            });
            Queue::after(static function (JobProcessed $event): void {
                if ($event->job->hasFailed()) {
                    app(QueueMonitorLifecycle::class)->failed($event->job);
                }
            });
        });
    }

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
