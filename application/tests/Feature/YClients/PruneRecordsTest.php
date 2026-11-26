<?php

namespace Tests\Feature\YClients;

use App\Jobs\YClients\RecordSend;
use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\RecordEvent;
use App\Models\Integrations\YClients\Setting;
use App\Services\YClients\RecordSyncLock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PruneRecordsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.yclients_lock_store' => 'array', 'queue.default' => 'sync', 'alerts.enabled' => false]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 11, 24)->setTime(12, 0));
        Schema::create('yclients_settings', function (Blueprint $table): void {
            $table->id(); $table->boolean('active'); $table->timestamps();
        });
        Schema::create('yclients_records', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('setting_id')->default(1);
            $table->string('status')->nullable(); $table->timestamp('datetime')->nullable(); $table->timestamps();
        });
        (require database_path('migrations/2026_11_11_210000_create_yclients_record_events_table.php'))->up();
        foreach (['yclients_clients', 'yclients_create_receipts'] as $tableName) {
            Schema::create($tableName, fn (Blueprint $table) => $table->id());
            DB::table($tableName)->insert(['id' => 42]);
        }
        Setting::forceCreate(['id' => 1, 'active' => true]);
        Setting::forceCreate(['id' => 2, 'active' => false]);
    }

    public function test_old_terminal_records_and_events_are_removed_but_receipts_and_clients_are_kept(): void
    {
        foreach ([Record::STATUS_SUCCESS, Record::STATUS_FAILED] as $status) {
            $record = $this->record($status);
            $this->event($record, $status);
        }
        $this->artisan('yc:prune-records', ['--chunk' => 1])->assertSuccessful();
        $this->assertDatabaseCount('yclients_records', 0);
        $this->assertDatabaseCount('yclients_record_events', 0);
        $this->assertDatabaseHas('yclients_clients', ['id' => 42]);
        $this->assertDatabaseHas('yclients_create_receipts', ['id' => 42]);
        Http::assertNothingSent();
    }

    public function test_dry_run_does_not_delete_records_or_events(): void
    {
        $this->event($this->record(), Record::STATUS_SUCCESS);
        $this->artisan('yc:prune-records', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseCount('yclients_records', 1);
        $this->assertDatabaseCount('yclients_record_events', 1);
    }

    public function test_pending_and_blocked_events_protect_the_entire_record(): void
    {
        foreach ([Record::STATUS_PENDING, 'processing'] as $status) {
            $record = $this->record(Record::STATUS_FAILED);
            $this->event($record, Record::STATUS_FAILED);
            $this->event($record, $status);
        }
        $this->artisan('yc:prune-records')->assertSuccessful();
        $this->assertDatabaseCount('yclients_records', 2);
        $this->assertDatabaseCount('yclients_record_events', 4);
    }

    public function test_recent_event_protects_an_old_record_even_if_its_timestamp_is_stale(): void
    {
        $event = $this->event($this->record(), Record::STATUS_SUCCESS);
        DB::table('yclients_record_events')->where('id', $event->id)->update(['updated_at' => now()]);
        $this->artisan('yc:prune-records')->assertSuccessful();
        $this->assertDatabaseCount('yclients_records', 1);
        $this->assertDatabaseCount('yclients_record_events', 1);
    }

    public function test_only_inactive_legacy_waits_without_events_can_expire(): void
    {
        $active = $this->record(Record::STATUS_PENDING);
        $activeNull = $this->record(null);
        $inactive = $this->record(Record::STATUS_PENDING, ['setting_id' => 2]);
        $inactiveWithEvent = $this->record(Record::STATUS_PENDING, ['setting_id' => 2]);
        $this->event($inactiveWithEvent, Record::STATUS_PENDING);
        $this->artisan('yc:prune-records')->assertSuccessful();
        foreach ([$active, $activeNull, $inactiveWithEvent] as $record) {
            $this->assertDatabaseHas('yclients_records', ['id' => $record->id]);
        }
        $this->assertDatabaseMissing('yclients_records', ['id' => $inactive->id]);
    }

    public function test_future_visits_recent_updates_and_retention_boundary_are_preserved(): void
    {
        $this->record(Record::STATUS_SUCCESS, ['datetime' => now()->addDay()]);
        $this->record(Record::STATUS_FAILED, ['updated_at' => now()->subDay()]);
        $this->record(Record::STATUS_SUCCESS, ['created_at' => now()->subDays(5)]);
        $this->record(Record::STATUS_FAILED, ['datetime' => now()->subDays(4)]);
        $this->artisan('yc:prune-records')->assertSuccessful();
        $this->assertDatabaseCount('yclients_records', 4);
    }

    public function test_locked_records_are_skipped_without_stalling_the_next_chunk(): void
    {
        $locked = $this->record();
        $other = $this->record();
        $lock = RecordSyncLock::make($locked->id);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('yc:prune-records', ['--chunk' => 1])->assertSuccessful();
            $this->assertDatabaseHas('yclients_records', ['id' => $locked->id]);
            $this->assertDatabaseMissing('yclients_records', ['id' => $other->id]);
        } finally {
            $lock->release();
        }
    }

    public function test_ready_delayed_and_reserved_jobs_are_all_protected(): void
    {
        config(['queue.default' => 'redis']);
        $groups = [];
        foreach (range(1, 3) as $index) {
            $record = $this->record();
            $job = new RecordSend($record, new Account(['id' => 1]), Setting::findOrFail(1));
            $groups[] = [json_encode(['displayName' => RecordSend::class, 'data' => ['command' => serialize($job)]])];
        }
        $redis = \Mockery::mock();
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($redis);
        $redis->shouldReceive('eval')->once()->with(\Mockery::type('string'), 3,
            'queues:yclients_record', 'queues:yclients_record:delayed', 'queues:yclients_record:reserved')->andReturn($groups);
        $this->artisan('yc:prune-records')->assertSuccessful();
        $this->assertDatabaseCount('yclients_records', 3);
    }

    public function test_unreadable_queue_aborts_before_deleting_any_candidates(): void
    {
        config(['queue.default' => 'redis']);
        $this->record();
        $redis = \Mockery::mock();
        Redis::shouldReceive('connection')->once()->andReturn($redis);
        $redis->shouldReceive('eval')->once()->andReturn([['{}'], [], []]);
        try {
            $this->artisan('yc:prune-records')->run();
            $this->fail('Expected unreadable queue to stop pruning.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('unrecognized queued job', $error->getMessage());
        }
        $this->assertDatabaseCount('yclients_records', 1);
    }

    public function test_event_deletion_is_rolled_back_if_record_deletion_fails(): void
    {
        $this->event($this->record(), Record::STATUS_SUCCESS);
        DB::unprepared("CREATE TRIGGER prevent_prune BEFORE DELETE ON yclients_records BEGIN SELECT RAISE(ABORT, 'test rollback'); END");
        try {
            $this->artisan('yc:prune-records')->run();
            $this->fail('Expected deletion failure.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('test rollback', $error->getMessage());
        }
        $this->assertDatabaseCount('yclients_records', 1);
        $this->assertDatabaseCount('yclients_record_events', 1);
    }

    public function test_cleanup_is_scheduled_daily_in_moscow_with_overlap_protection(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'yc:prune-records'));
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('30 3 * * *', $event->expression);
        $this->assertSame('Europe/Moscow', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('--days=5', $event->command);
    }

    private function record(?string $status = Record::STATUS_SUCCESS, array $attributes = []): Record
    {
        return Record::forceCreate(array_merge(['setting_id' => 1, 'status' => $status,
            'created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)], $attributes));
    }

    private function event(Record $record, string $status): RecordEvent
    {
        return RecordEvent::forceCreate(['record_id' => $record->id, 'status' => $status,
            'payload' => [], 'source_snapshot' => [], 'record_snapshot' => [],
            'created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)]);
    }
}
