<?php

namespace Tests\Feature\Core;

use App\Services\Core\MonitoringCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QueueHealthCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'queue_health_test',
            'database.connections.queue_health_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'queue.failed.database' => 'queue_health_test',
            'queue.failed.table' => 'failed_jobs',
            'filament-jobs-monitor.connection' => 'queue_health_test',
            'alerts.enabled' => true,
            'alerts.cache_store' => 'array',
            'alerts.integration_errors.enabled' => true,
            'alerts.channels.telegram.enabled' => false,
            'alerts.channels.mail.enabled' => true,
            'alerts.channels.mail.to' => ['admin@example.test'],
            'alerts.queue.auto_heal.enabled' => false,
            'widget_lifecycle.telegram.token' => 'test-token',
            'widget_lifecycle.telegram.chat_id' => 'test-chat',
            'widget_lifecycle.telegram.message_thread_id' => null,
        ]);
        DB::purge('queue_health_test');
        Cache::store('array')->flush();
        Queue::fake();
        Mail::spy();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid');
            $table->string('queue');
            $table->text('exception');
            $table->timestamp('failed_at');
        });
    }

    #[DataProvider('cursors')]
    public function test_failure_polling_updates_counts_without_duplicate_alerts(?int $cursor, int $expected): void
    {
        $this->insertFailure(10);
        $this->insertFailure(12);
        if ($cursor !== null) {
            MonitoringCache::forever('monitoring:queue:last_failed_job_id', $cursor);
        }

        $this->artisan('app:monitor-queue-health', ['--sample' => 3])->assertSuccessful();

        $this->assertSame($expected, MonitoringCache::get('monitoring:queue:health:last_new_failed'));
        $this->assertSame(max(12, $cursor ?? 0), MonitoringCache::get('monitoring:queue:last_failed_job_id'));
        $this->assertNotNull(MonitoringCache::get('monitoring:queue:health:last_run'));
        $this->assertSame(2, DB::table('failed_jobs')->count());
        $this->assertNoAlerts();

        $this->artisan('app:monitor-queue-health')->assertSuccessful();
        $this->assertSame(0, MonitoringCache::get('monitoring:queue:health:last_new_failed'));
        $this->assertNoAlerts();
    }

    public static function cursors(): array
    {
        return [
            'first run' => [null, 2],
            'cache reset' => [0, 2],
            'new failure' => [10, 1],
            'already counted' => [12, 0],
            'old rows pruned' => [20, 0],
        ];
    }

    public function test_empty_or_missing_failed_jobs_table_does_not_alert(): void
    {
        $this->artisan('app:monitor-queue-health')->assertSuccessful();
        $this->assertSame(0, MonitoringCache::get('monitoring:queue:health:last_new_failed'));

        Schema::drop('failed_jobs');
        $this->artisan('app:monitor-queue-health')->assertSuccessful();
        $this->assertSame(0, MonitoringCache::get('monitoring:queue:health:last_new_failed'));
        $this->assertNoAlerts();
    }

    public function test_stuck_queue_alerts_are_preserved(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->integer('reserved_at')->nullable();
        });
        DB::table('jobs')->insert(['reserved_at' => now()->subHour()->timestamp]);

        $this->artisan('app:monitor-queue-health', ['--stuck-after' => 900])->assertSuccessful();

        $this->assertSame(1, MonitoringCache::get('monitoring:queue:health:last_stuck'));
        Mail::shouldHaveReceived('raw')->once()->withArgs(
            fn (string $text, callable $callback): bool => str_contains($text, 'Очередь: зависшие jobs')
        );
        $this->assertSame(1, DB::table('jobs')->whereNotNull('reserved_at')->count());
    }

    private function insertFailure(int $id): void
    {
        DB::table('failed_jobs')->insert([
            'id' => $id,
            'uuid' => 'test-failure-'.$id,
            'queue' => 'sqns_visit',
            'exception' => 'Test failure',
            'failed_at' => now(),
        ]);
    }

    private function assertNoAlerts(): void
    {
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        Mail::shouldNotHaveReceived('raw');
    }
}
