<?php

namespace Tests\Feature\Core;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QueueAnalyticsRemovalTest extends TestCase
{
    public function test_migration_drops_only_the_monitor_history(): void
    {
        config([
            'database.default' => 'queue_removal_test',
            'database.connections.queue_removal_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);

        (require database_path('migrations/2026_04_04_234246_create_filament-jobs-monitor_table.php'))->up();
        (require database_path('migrations/2023_08_26_142354_create_jobs_table.php'))->up();
        (require database_path('migrations/2019_08_19_000000_create_failed_jobs_table.php'))->up();

        DB::table('queue_monitors')->insert(['job_id' => 'failed-example']);
        DB::table('failed_jobs')->insert([
            'uuid' => 'failed-example', 'connection' => 'redis', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'Example job failure',
        ]);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ]);

        $migration = require database_path('migrations/2026_11_26_153000_drop_queue_monitors_table.php');
        $migration->up();
        $migration->up();
        $this->assertFalse(Schema::hasTable('queue_monitors'));
        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'failed-example']);
        $this->assertDatabaseHas('jobs', ['queue' => 'default', 'attempts' => 0]);

        $migration->down();
        $this->assertDatabaseCount('queue_monitors', 0);
    }

    public function test_monitor_is_not_registered_but_queue_health_checks_remain(): void
    {
        $commands = Artisan::all();
        $schedule = app(Schedule::class)->events();
        $scheduledCommands = implode("\n", array_map(fn ($event) => (string) $event->command, $schedule));

        $this->assertArrayNotHasKey('Croustibat\\FilamentJobsMonitor\\QueueMonitorProvider', app()->getLoadedProviders());
        $this->assertFalse(Route::has('filament.app.resources.queue-monitors.index'));
        $this->assertArrayNotHasKey('app:queue-backfill-failed', $commands);
        $this->assertStringNotContainsString('app:queue-backfill-failed', $scheduledCommands);
        $this->assertStringNotContainsString('QueueMonitor', $scheduledCommands);
        $this->assertStringContainsString('app:monitor-queue-health', $scheduledCommands);
        $this->assertArrayHasKey('horizon:status', $commands);
    }
}
