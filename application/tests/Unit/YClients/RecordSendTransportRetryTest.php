<?php

namespace Tests\Unit\YClients;

use App\Jobs\YClients\RecordSend;
use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\YClients\RecordSyncLock;
use App\Services\YClients\UnconfirmedCreateException;
use Exception;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RecordSendTransportRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        (require database_path('migrations/2026_10_14_120000_create_yclients_create_receipts_table.php'))->up();

        config(['cache.yclients_lock_store' => 'database']);
        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary(); $table->text('value'); $table->integer('expiration');
        });
        Schema::create('accounts', fn (Blueprint $table) => $table->id());
        Schema::create('yclients_settings', fn (Blueprint $table) => $table->id());
        DB::table('accounts')->insert(['id' => 124]);
        DB::table('yclients_settings')->insert(['id' => 14]);

        Schema::dropIfExists('yclients_records');
        Schema::create('yclients_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function test_existing_lead_transport_failure_is_released_without_remaining_failed(): void
    {
        $record = $this->record(42);
        Artisan::shouldReceive('call')
            ->once()
            ->andThrow(new Exception('Invalid API response (non JSON), code: 502'));
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->method('attempts')->willReturn(1);
        $queueJob->expects($this->once())->method('release')->with(30);

        $job = $this->job($record);
        $job->setJob($queueJob);
        $job->handle();

        $record->refresh();
        $this->assertSame(Record::STATUS_PENDING, $record->status);
        $this->assertNull($record->error_message);
    }

    public function test_final_transport_attempt_remains_failed(): void
    {
        $record = $this->record(42);
        Artisan::shouldReceive('call')
            ->once()
            ->andThrow(new Exception('Invalid API response (non JSON), code: 504'));
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->method('attempts')->willReturn(4);
        $queueJob->expects($this->never())->method('release');
        $queueJob->expects($this->once())->method('fail')->with($this->isInstanceOf(Exception::class));

        $job = $this->job($record);
        Cache::store('database')->put('yclients:record-transport:'.$job->attemptKey, 3, 86400);
        $job->setJob($queueJob);
        $job->handle();

        $record->refresh();
        $this->assertSame(Record::STATUS_FAILED, $record->status);
        $this->assertStringContainsString('code: 504', $record->error_message);
    }

    public function test_read_failure_before_lead_creation_is_released_for_retry(): void
    {
        $record = $this->record(null);
        Artisan::shouldReceive('call')
            ->once()
            ->andThrow(new Exception('Invalid API response (non JSON), code: 502'));
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->method('attempts')->willReturn(1);
        $queueJob->expects($this->once())->method('release')->with(30);
        $queueJob->expects($this->never())->method('fail');

        $job = $this->job($record);
        $job->setJob($queueJob);
        $job->handle();

        $this->assertSame(Record::STATUS_PENDING, $record->fresh()->status);
    }

    public function test_retry_skips_note_creation(): void
    {
        $record = $this->record(42);
        Artisan::shouldReceive('call')
            ->once()
            ->withArgs(fn ($command, $args) => $command === 'yc:send-record'
                && $args['record_id'] === $record->id && $args['--skip-note'] === true
                && $args['--defer-status'] === true && is_string($args['--lock-owner']))
            ->andReturn(0);
        Artisan::shouldReceive('call')
            ->once()
            ->withArgs(fn ($command, $args) => $command === 'yc:update-entities'
                && $args['record_id'] === $record->id && $args['--defer-status'] === true
                && RecordSyncLock::make($record->id, $args['--lock-owner'])->isOwnedByCurrentProcess())
            ->andReturn(0);
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->method('attempts')->willReturn(2);

        $job = $this->job($record);
        $job->createNote = false;
        $job->setJob($queueJob);
        $job->handle();
        $this->assertSame(Record::STATUS_SUCCESS, $record->fresh()->status);
    }

    public function test_contention_releases_without_overwriting_success_or_spending_transport_attempt(): void
    {
        $record = $this->record(42);
        $record->update(['status' => Record::STATUS_SUCCESS]);
        $lock = RecordSyncLock::make($record->id);
        $this->assertTrue($lock->get());
        Artisan::shouldReceive('call')->never();
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->expects($this->once())->method('release')->with(30);
        $queueJob->expects($this->never())->method('fail');
        $job = $this->job($record);
        $job->setJob($queueJob);
        try {
            $job->handle();
            $this->assertNull(Cache::store('database')->get('yclients:record-transport:'.$job->attemptKey));
            $this->assertSame(Record::STATUS_SUCCESS, $record->fresh()->status);
            $this->assertNull($record->fresh()->error_message);
            $this->assertTrue($lock->isOwnedByCurrentProcess());
        } finally {
            $lock->release();
        }
    }

    public function test_post_sync_failure_is_retried_even_if_send_record_was_successful(): void
    {
        $record = $this->record(42);
        Artisan::shouldReceive('call')->once()->withArgs(fn ($command) => $command === 'yc:send-record')
            ->andReturnUsing(function () use ($record) {
                $record->update(['status' => Record::STATUS_SUCCESS]);
                return 0;
            });
        Artisan::shouldReceive('call')->once()->withArgs(fn ($command) => $command === 'yc:update-entities')
            ->andThrow(new Exception('Invalid API response (non JSON), code: 0'));
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->expects($this->once())->method('release')->with(30);
        $job = $this->job($record);
        $job->setJob($queueJob);
        $job->handle();
        $this->assertSame(Record::STATUS_PENDING, $record->fresh()->status);
    }

    public function test_unconfirmed_creation_retries_only_reconciliation_and_eventually_fails_visibly(): void
    {
        $record = $this->record(null);
        Artisan::shouldReceive('call')->times(4)->andThrow(new UnconfirmedCreateException('lead'));
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->expects($this->exactly(3))->method('release');
        $queueJob->expects($this->once())->method('fail')->with($this->isInstanceOf(UnconfirmedCreateException::class));
        $job = $this->job($record);
        $originalPayload = serialize($job);
        $job->setJob($queueJob);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $job = unserialize($originalPayload);
            $job->setJob($queueJob);
            $job->handle();
        }
        $this->assertSame(Record::STATUS_FAILED, $record->fresh()->status);
    }

    public function test_success_clears_error_written_after_initial_model_load(): void
    {
        $record = $this->record(42);
        Artisan::shouldReceive('call')->twice()->andReturnUsing(function () use ($record) {
            Record::query()->whereKey($record->id)->update(['error_message' => 'stale lock error']);
            return 0;
        });
        $this->job($record)->handle();
        $this->assertSame(Record::STATUS_SUCCESS, $record->fresh()->status);
        $this->assertNull($record->fresh()->error_message);
    }

    public function test_command_nonzero_is_a_real_queue_failure(): void
    {
        $record = $this->record(42);
        Artisan::shouldReceive('call')->once()->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('Invalid mapping');
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->expects($this->once())->method('fail');
        $job = $this->job($record);
        $job->setJob($queueJob);
        $job->handle();
        $this->assertSame(Record::STATUS_FAILED, $record->fresh()->status);
        $this->assertStringContainsString('Invalid mapping', $record->fresh()->error_message);
    }

    public function test_deploy_schema_gap_waits_without_remote_calls_or_failed_export(): void
    {
        Schema::drop('yclients_create_receipts');
        $record = $this->record(null);
        Artisan::shouldReceive('call')->never();
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->expects($this->once())->method('release')->with(60);
        $queueJob->expects($this->never())->method('fail');
        $job = $this->job($record);
        $job->setJob($queueJob);
        $job->handle();
        $this->assertSame(Record::STATUS_PENDING, $record->fresh()->status);
        $this->assertNull($record->fresh()->error_message);
    }

    public function test_terminal_timeout_can_finalize_its_own_lock_but_not_another_jobs_lock(): void
    {
        $record = $this->record(42);
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->method('uuid')->willReturn('test-timeout-owner');
        $job = $this->job($record);
        $job->setJob($queueJob);
        $otherLock = RecordSyncLock::make($record->id, 'job:other-owner');
        $this->assertTrue($otherLock->get());
        $job->failed(new \RuntimeException('Job has timed out.'));
        $this->assertSame(Record::STATUS_PENDING, $record->fresh()->status);
        $otherLock->release();
        $ownLock = RecordSyncLock::make($record->id, 'job:test-timeout-owner');
        $this->assertTrue($ownLock->get());
        $job->failed(new \RuntimeException('Job has timed out.'));
        $this->assertSame(Record::STATUS_FAILED, $record->fresh()->status);
        $this->assertFalse($ownLock->isOwnedByCurrentProcess());
    }

    private function record(?int $leadId): Record
    {
        return Record::query()->create([
            'user_id' => 141,
            'account_id' => 124,
            'setting_id' => 14,
            'record_id' => 123456,
            'lead_id' => $leadId,
            'status' => Record::STATUS_PENDING,
        ]);
    }

    private function job(Record $record): RecordSend
    {
        $account = (new Account())->forceFill(['id' => 124]);
        $setting = (new Setting())->forceFill(['id' => 14]);

        return new RecordSend($record, $account, $setting, true);
    }
}
