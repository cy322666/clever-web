<?php

namespace Tests\Feature\Core;

use Croustibat\FilamentJobsMonitor\Models\QueueMonitor;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class QueueMonitorPruningScheduleTest extends TestCase
{
    public function test_queue_monitor_pruning_is_scheduled_daily(): void
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())->first(function ($event): bool {
            return str_contains((string) $event->command, 'model:prune')
                && str_contains((string) $event->command, QueueMonitor::class);
        });

        $this->assertNotNull($event);
        $this->assertNotInstanceOf(CallbackEvent::class, $event);
        $this->assertSame('30 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
