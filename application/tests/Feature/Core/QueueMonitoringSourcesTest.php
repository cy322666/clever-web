<?php

namespace Tests\Feature\Core;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class QueueMonitoringSourcesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'queue_monitoring_sources_test',
            'database.connections.queue_monitoring_sources_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'alerts.cache_store' => 'array',
        ]);

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('reserved_at')->nullable();
        });
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('failed_at');
        });

        $this->freezeSecond();
        DB::table('jobs')->insert([
            'queue' => 'legacy-only',
            'available_at' => now()->subDay()->timestamp,
            'reserved_at' => now()->subHour()->timestamp,
        ]);
        DB::table('failed_jobs')->insert([
            ['failed_at' => now()->subMinute()],
            ['failed_at' => now()->subDays(2)],
        ]);
        DB::enableQueryLog();
    }

    public function test_queue_metrics_count_redis_jobs_and_laravel_failures_only(): void
    {
        config(['queue.default' => 'redis', 'queue.connections.redis.connection' => 'queue-metrics-test']);
        $redis = Mockery::mock();
        $redis->shouldReceive('llen')->withAnyArgs()->andReturn(0)->byDefault();
        $redis->shouldReceive('llen')->with('queues:default')->once()->andReturn(2);
        $redis->shouldReceive('zcard')->withAnyArgs()->andReturn(0)->byDefault();
        $redis->shouldReceive('lindex')->with('queues:default', 0)->once()->andReturn(json_encode([
            'pushedAt' => now()->subSeconds(90)->timestamp,
        ]));
        $redis->shouldReceive('zrange')->with('queues:default:delayed', 0, 0, ['withscores' => true])->once()->andReturn([]);
        Redis::shouldReceive('connection')->with('queue-metrics-test')->once()->andReturn($redis);

        $this->get('/metrics')->assertOk()
            ->assertSee("clever_queue_jobs_total 2\n", false)
            ->assertSee("clever_queue_oldest_job_age_seconds 90\n", false)
            ->assertSee("clever_queue_failed_jobs_total 2\n", false)
            ->assertSee("clever_queue_failed_jobs_recent_total 1\n", false)
            ->assertDontSee('legacy-only');
        $this->assertDatabaseJobsWereNotQueried();
    }

    public function test_digest_still_reports_failures_in_period_without_database_queue_counts(): void
    {
        config([
            'alerts.enabled' => true,
            'alerts.channels.telegram.enabled' => true,
            'alerts.channels.telegram.token' => 'test-token',
            'alerts.channels.telegram.chat_id' => 'test-chat',
        ]);

        $this->artisan('platform:telegram-digest', ['--dry-run' => true])
            ->expectsOutputToContain('Ошибок заданий за период: 1')
            ->doesntExpectOutputToContain('Ожидают:')
            ->doesntExpectOutputToContain('выполняются:')
            ->assertSuccessful();
        $this->assertDatabaseJobsWereNotQueried();
    }

    private function assertDatabaseJobsWereNotQueried(): void
    {
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('"jobs"', $query['query']);
            $this->assertNotContains('jobs', $query['bindings']);
        }
    }
}
