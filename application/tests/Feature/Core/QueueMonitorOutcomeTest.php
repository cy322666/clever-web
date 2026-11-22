<?php

namespace Tests\Feature\Core;

use Croustibat\FilamentJobsMonitor\Models\QueueMonitor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class QueueMonitorOutcomeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'queue.failed.database' => 'sqlite', 'filament-jobs-monitor.connection' => 'sqlite',
            'filament-jobs-monitor.tenancy.enabled' => false, 'alerts.cache_store' => 'array']);
        DB::purge('sqlite');
        Http::preventStrayRequests();
        Bus::fake();
        Schema::create('queue_monitors', function (Blueprint $table): void {
            $table->id(); $table->string('job_id'); $table->string('name')->nullable();
            $table->string('queue')->nullable(); $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable(); $table->boolean('failed')->default(false);
            $table->integer('attempt')->default(0); $table->integer('progress')->nullable();
            $table->text('exception_message')->nullable(); $table->string('tenant_id')->nullable(); $table->timestamps();
        });
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id(); $table->string('uuid'); $table->string('connection'); $table->string('queue');
            $table->text('payload'); $table->text('exception'); $table->timestamp('failed_at');
        });
    }

    public function test_manual_failure_cannot_be_overwritten_by_job_processed(): void
    {
        $job = $this->job('manual-failure');
        Event::dispatch(new JobProcessing('sync', $job));
        $job->markAsFailed();
        Event::dispatch(new JobFailed('sync', $job, new RuntimeException('CRM rejected the operation')));
        Event::dispatch(new JobProcessed('sync', $job));
        $monitor = QueueMonitor::firstOrFail();
        $this->assertTrue($monitor->failed);
        $this->assertSame('failed', $monitor->status);
        $this->assertSame('CRM rejected the operation', $monitor->exception_message);
        $this->assertNotNull($monitor->finished_at);
    }

    public function test_terminal_failure_corrects_prior_attempts_of_only_its_uuid(): void
    {
        $this->monitor('terminal', 1);
        $untouched = $this->monitor('another-job', 1);
        $job = $this->job('terminal', 2);
        Event::dispatch(new JobProcessing('sync', $job));
        $job->markAsFailed();
        Event::dispatch(new JobFailed('sync', $job, new RuntimeException('Retries exhausted')));
        Event::dispatch(new JobProcessed('sync', $job));
        $this->assertSame(2, QueueMonitor::where('job_id', 'terminal')->where('failed', true)->count());
        $this->assertFalse($untouched->fresh()->failed);
    }

    public function test_successful_retry_keeps_its_success_and_historical_failure(): void
    {
        $old = $this->monitor('retry', 1, ['failed' => true, 'exception_message' => 'Earlier failure']);
        $job = $this->job('retry', 2);
        Event::dispatch(new JobProcessing('sync', $job));
        Event::dispatch(new JobProcessed('sync', $job));
        $current = QueueMonitor::where('job_id', 'retry')->where('attempt', 2)->firstOrFail();
        $this->assertSame('succeeded', $current->status);
        $this->assertTrue($old->fresh()->failed);
    }

    public function test_normal_success_is_preserved(): void
    {
        $job = $this->job('normal-success');
        Event::dispatch(new JobProcessing('sync', $job));
        Event::dispatch(new JobProcessed('sync', $job));
        $this->assertSame('succeeded', QueueMonitor::firstOrFail()->status);
    }

    public function test_backfill_repairs_existing_monitors_and_inserts_missing_without_replaying_jobs(): void
    {
        $monitor = $this->monitor('existing', 4, ['tenant_id' => 'keep-tenant']);
        $this->failedRow('existing'); $this->failedRow('missing');
        $this->artisan('app:queue-backfill-failed')->assertSuccessful();
        $this->assertTrue($monitor->fresh()->failed);
        $this->assertSame('keep-tenant', $monitor->fresh()->tenant_id);
        $this->assertSame('CRM outcome is unconfirmed', $monitor->fresh()->exception_message);
        $this->assertTrue(QueueMonitor::where('job_id', 'missing')->firstOrFail()->failed);
        $this->assertSame(2, DB::table('failed_jobs')->count());
        Bus::assertNothingDispatched();
    }

    public function test_backfill_dry_run_does_not_change_monitors(): void
    {
        $monitor = $this->monitor('existing', 1);
        $this->failedRow('existing'); $this->failedRow('missing');
        $this->artisan('app:queue-backfill-failed', ['--dry-run' => true])->assertSuccessful();
        $this->assertFalse($monitor->fresh()->failed);
        $this->assertSame(1, QueueMonitor::count());
        Bus::assertNothingDispatched();
    }

    public function test_backfill_uses_the_original_failure_time_when_finish_time_is_missing(): void
    {
        $failedAt = now()->subDays(3)->startOfSecond();
        $monitor = $this->monitor('missing-finish', 1, ['started_at' => $failedAt->copy()->subMinute(), 'finished_at' => null]);
        $this->failedRow('missing-finish', $failedAt);
        $this->artisan('app:queue-backfill-failed')->assertSuccessful();
        $this->assertSame($failedAt->toDateTimeString(), $monitor->fresh()->finished_at->toDateTimeString());
    }

    public function test_repeated_backfill_does_not_rewrite_a_correct_status(): void
    {
        $monitor = $this->monitor('existing', 1);
        $this->failedRow('existing');
        $this->artisan('app:queue-backfill-failed')->assertSuccessful();
        $updated = $monitor->fresh()->updated_at->toDateTimeString();
        $this->travel(2)->seconds();
        $this->artisan('app:queue-backfill-failed')->assertSuccessful();
        $this->assertSame($updated, $monitor->fresh()->updated_at->toDateTimeString());
    }

    public function test_backfill_does_not_change_a_newer_retry(): void
    {
        $old = $this->monitor('retry', 1, ['started_at' => now()->subDay(), 'finished_at' => now()->subDay()]);
        $new = $this->monitor('retry', 2, ['started_at' => now()]);
        $this->failedRow('retry', now()->subDay()->addMinute());
        $this->artisan('app:queue-backfill-failed')->assertSuccessful();
        $this->assertTrue($old->fresh()->failed);
        $this->assertSame('succeeded', $new->fresh()->status);
    }

    private function monitor(string $uuid, int $attempt, array $attributes = []): QueueMonitor
    {
        return QueueMonitor::create(array_replace(['job_id' => $uuid, 'name' => 'RecordSend', 'queue' => 'yclients_record',
            'attempt' => $attempt, 'failed' => false, 'progress' => 100,
            'started_at' => now()->subSeconds(20), 'finished_at' => now()->subSeconds(10)], $attributes));
    }

    private function failedRow(string $uuid, mixed $failedAt = null): void
    {
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'redis', 'queue' => 'yclients_record',
            'payload' => json_encode(['displayName' => 'RecordSend', 'attempts' => 4]),
            'exception' => 'CRM outcome is unconfirmed', 'failed_at' => $failedAt ?? now()]);
    }

    private function job(string $uuid, int $attempt = 1): SyncJob
    {
        return new class($this->app, json_encode(['uuid' => $uuid, 'displayName' => 'RecordSend',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call']), $attempt) extends SyncJob {
            public function __construct($app, string $payload, private int $attempt)
            {
                parent::__construct($app, $payload, 'sync', 'yclients_record');
            }
            public function attempts(): int { return $this->attempt; }
            public function getQueue(): string { return 'yclients_record'; }
        };
    }
}
