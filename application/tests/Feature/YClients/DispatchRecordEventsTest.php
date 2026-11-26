<?php

namespace Tests\Feature\YClients;

use App\Jobs\YClients\RecordSend;
use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\RecordEvent;
use App\Models\Integrations\YClients\Setting;
use App\Services\YClients\RecordEventQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class DispatchRecordEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'queue.default' => 'redis',
            'alerts.enabled' => false,
        ]);
        DB::purge('sqlite');
        Queue::fake();
        $this->freezeSecond();

        Schema::create('accounts', fn (Blueprint $table) => $table->id());
        Schema::create('yclients_settings', fn (Blueprint $table) => $table->id());
        Schema::create('yclients_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('setting_id');
            $table->timestamps();
        });
        (require database_path('migrations/2026_11_11_210000_create_yclients_record_events_table.php'))->up();

        DB::table('accounts')->insert(['id' => 1]);
        DB::table('yclients_settings')->insert(['id' => 1]);
    }

    public function test_only_missing_events_are_recovered_even_when_the_queue_backlog_exceeds_the_dispatch_limit(): void
    {
        [$ready, $delayed, $reserved, $legacy, $missing] = array_map(fn () => $this->event(), range(1, 5));
        $recent = $this->event(['dispatched_at' => now()->subMinutes(14)]);
        $this->mockRedisSnapshot([
            [$this->payload($ready), $this->payload($ready), $this->payload($legacy, legacy: true)],
            [$this->payload($delayed)],
            [$this->payload($reserved)],
        ]);

        $this->artisan('yc:dispatch-record-events', ['--limit' => 1])->assertSuccessful();

        Queue::assertPushed(RecordSend::class, 1);
        Queue::assertPushed(RecordSend::class, fn (RecordSend $job): bool => $job->eventId === $missing->id);
        $this->assertTrue($missing->fresh()->dispatched_at->equalTo(now()));
        foreach ([$ready, $delayed, $reserved, $legacy, $recent] as $untouched) {
            $this->assertTrue($untouched->fresh()->dispatched_at->equalTo($untouched->dispatched_at));
        }
    }

    public function test_dry_run_does_not_dispatch_an_unacknowledged_event(): void
    {
        $event = $this->event(['dispatched_at' => null]);
        $this->mockRedisSnapshot([[], [], []]);

        $this->artisan('yc:dispatch-record-events', ['--dry-run' => true])
            ->expectsOutput('Recoverable YClients events: 1')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull($event->fresh()->dispatched_at);
    }

    public function test_unacknowledged_event_can_be_recovered(): void
    {
        $event = $this->event(['dispatched_at' => null]);
        $this->mockRedisSnapshot([[], [], []]);

        $this->artisan('yc:dispatch-record-events')->assertSuccessful();

        Queue::assertPushed(RecordSend::class, fn (RecordSend $job): bool => $job->eventId === $event->id);
    }

    public function test_no_redis_read_when_no_events_are_due_for_recovery(): void
    {
        $this->event(['available_at' => now()->addMinute()]);
        $this->event(['dispatched_at' => now()->subMinutes(15)]);
        Redis::shouldReceive('connection')->never();

        $this->artisan('yc:dispatch-record-events', ['--dry-run' => true])
            ->expectsOutput('Recoverable YClients events: 0')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_over_limit_snapshot_does_not_fall_back_to_blind_redispatch(): void
    {
        $this->event();
        $this->mockRedisSnapshot([]);

        $this->artisan('yc:dispatch-record-events')
            ->expectsOutput('YClients recovery deferred: queue snapshot unavailable.')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_unreadable_payload_does_not_fall_back_to_blind_redispatch(): void
    {
        $this->event();
        $this->mockRedisSnapshot([['{}'], [], []]);

        $this->artisan('yc:dispatch-record-events')
            ->expectsOutput('YClients recovery deferred: queue snapshot unavailable.')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_recovery_does_not_dispatch_a_different_head_after_concurrent_processing(): void
    {
        $old = $this->event(['status' => Record::STATUS_SUCCESS]);
        $new = $this->event(['record_id' => $old->record_id]);

        $this->assertFalse(RecordEventQueue::dispatchHead($old->record_id, recoverEventId: $old->id));
        $this->assertTrue($new->fresh()->dispatched_at->equalTo($new->dispatched_at));
        Queue::assertNothingPushed();
    }

    private function event(array $attributes = []): RecordEvent
    {
        return RecordEvent::create($attributes + [
            'record_id' => $attributes['record_id'] ?? Record::create(['account_id' => 1, 'setting_id' => 1])->id,
            'payload' => [],
            'source_snapshot' => [],
            'record_snapshot' => [],
            'status' => Record::STATUS_PENDING,
            'available_at' => now()->subMinutes(25),
            'dispatched_at' => now()->subMinutes(20),
        ]);
    }

    private function payload(RecordEvent $event, bool $legacy = false): string
    {
        $job = new RecordSend(
            $event->record,
            Account::findOrFail(1),
            Setting::findOrFail(1),
            eventId: $legacy ? null : $event->id,
        );

        return json_encode(['displayName' => RecordSend::class, 'data' => ['command' => serialize($job)]], JSON_THROW_ON_ERROR);
    }

    private function mockRedisSnapshot(array $snapshot): void
    {
        $connection = Mockery::mock();
        Redis::shouldReceive('connection')->once()->with('default')->andReturn($connection);
        $connection->shouldReceive('eval')->once()->with(Mockery::type('string'), 3,
            'queues:yclients_record', 'queues:yclients_record:delayed', 'queues:yclients_record:reserved', 5000)
            ->andReturn($snapshot);
    }
}
