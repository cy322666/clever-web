<?php

namespace Tests\Feature\YClients;

use App\Jobs\YClients\RecordSend;
use App\Models\Core\Account;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\Setting;
use App\Services\amoCRM\Client as AmoClient;
use App\Services\YClients\Leads;
use App\Services\YClients\RecordSyncLock;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Ufee\Amo\Models\Lead;
use Ufee\Amo\Oauthapi;

class RecordSyncRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.yclients_lock_store' => 'database', 'alerts.enabled' => false,
            'backup.notifications.mail.to' => 'test@example.test']);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        Http::preventStrayRequests();
        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary(); $table->string('owner'); $table->integer('expiration');
        });
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary(); $table->text('value'); $table->integer('expiration');
        });
        (require database_path('migrations/2026_10_14_120000_create_yclients_create_receipts_table.php'))->up();
        Schema::create('yclients_records', function (Blueprint $table): void {
            $table->id();
            foreach (['user_id', 'account_id', 'setting_id', 'company_id', 'record_id'] as $key) { $table->integer($key); }
            $table->integer('client_id')->nullable(); $table->integer('lead_id')->nullable();
            $table->integer('attendance')->default(0); $table->string('status');
            $table->text('error_message')->nullable(); $table->timestamps();
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id(); $table->integer('user_id'); $table->timestamps();
        });
        Schema::create('yclients_settings', function (Blueprint $table): void {
            $table->id(); $table->integer('user_id'); $table->integer('account_id');
            $table->string('status_id_wait'); $table->string('user_token'); $table->string('partner_token');
            $table->timestamps();
        });
        Schema::create('yclients_clients', function (Blueprint $table): void {
            $table->id();
            foreach (['client_id', 'user_id', 'account_id', 'setting_id', 'company_id'] as $key) { $table->integer($key); }
        });
        Schema::create('yclients_responsible_mappings', function (Blueprint $table): void {
            $table->id(); $table->integer('setting_id'); $table->boolean('active');
        });
        Schema::create('amocrm_staffs', function (Blueprint $table): void {
            $table->id(); $table->integer('user_id'); $table->integer('staff_id'); $table->boolean('active');
        });
    }

    public function test_lost_note_response_retains_lead_id_and_retry_never_creates_another_lead(): void
    {
        $record = $this->record();
        $lead = $this->lead(0);
        $service = new class($lead) {
            public int $creates = 0;
            public function __construct(public Lead $lead) {}
            public function create(): Lead { $this->creates++; return $this->lead; }
            public function find(int $id): Lead { return $this->lead; }
        };
        $note = $this->createMock(\Ufee\Amo\Models\Note::class);
        $note->expects($this->once())->method('save')->willThrowException(new \Exception('Invalid API response (non JSON), code: 0'));
        $notes = new class($note) {
            public function __construct(public object $note) {}
            public function create(): object { return $this->note; }
        };
        Http::fake(['*' => Http::response(['success' => true, 'data' => [['id' => 10, 'title' => 'Test branch']]])]);
        $api = $this->api();
        $api->service->method('__call')->willReturnCallback(fn ($method) => $method === 'notes' ? $notes : $service);
        $this->app->bind(AmoClient::class, fn () => $api);
        $queue = $this->createMock(Job::class);
        $queue->expects($this->once())->method('release')->with(30);
        $queue->method('fail')->willReturnCallback(fn ($exception) => throw $exception);
        $job = new RecordSend($record, $record->account, $record->setting);
        $originalPayload = serialize($job);
        $job->setJob($queue);

        $job->handle();
        $this->assertSame(42, (int) $record->fresh()->lead_id);
        $this->assertSame(Record::STATUS_PENDING, $record->fresh()->status);
        $this->assertNull($record->fresh()->error_message);
        $job = unserialize($originalPayload);
        $job->setJob($queue);
        $job->handle();
        $this->assertSame(1, $service->creates);
        $this->assertSame(Record::STATUS_SUCCESS, $record->fresh()->status);
        $this->assertNull($record->fresh()->error_message);
        Http::assertSentCount(1); // Read the branch title only on the first note attempt.
    }

    public function test_lead_reconciliation_checks_exact_number_contact_pipeline_time_and_uniqueness(): void
    {
        $record = $this->record();
        $api = $this->api();
        $candidate = ['id' => 42, 'name' => 'Запись #1000', 'pipeline_id' => 10, 'created_at' => 1000,
            '_embedded' => ['contacts' => [['id' => 77]]]];
        $payload = ['_embedded' => ['leads' => [
            $candidate,
            array_replace($candidate, ['id' => 43, 'name' => 'Запись #10001']),
            array_replace($candidate, ['id' => 44, 'created_at' => 900]),
            array_replace($candidate, ['id' => 45, 'pipeline_id' => 99]),
            array_replace($candidate, ['id' => 46, '_embedded' => ['contacts' => [['id' => 88]]]]),
        ]]];
        $api->expects($this->exactly(2))->method('requestV4')->willReturnOnConsecutiveCalls(
            $payload,
            ['_embedded' => ['leads' => [$candidate, array_replace($candidate, ['id' => 99])]]],
        );
        $lead = $this->lead(42);
        $service = new class($lead) {
            public function __construct(public Lead $lead) {}
            public function find(int $id): Lead { return $this->lead; }
        };
        $api->service->method('__call')->willReturn($service);
        $this->assertSame($lead, Leads::recoverCreated($api, $record, 77, (object) ['pipeline_id' => 10], 1000));
        $this->assertNull(Leads::recoverCreated($api, $record, 77, (object) ['pipeline_id' => 10], 1000));
    }

    public function test_manual_reexport_does_not_mark_a_busy_record_failed_or_erase_its_state(): void
    {
        $record = $this->record();
        $record->update(['status' => Record::STATUS_FAILED, 'error_message' => 'original failure']);
        $lock = RecordSyncLock::make($record->id);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('yc:reexport-failed', ['user_id' => 1, '--from' => now()->subDay()->toDateTimeString(),
                '--record-db-id' => $record->id, '--sync' => true])->assertSuccessful();
            $this->assertSame('original failure', $record->fresh()->error_message);
            $this->assertSame(Record::STATUS_FAILED, $record->fresh()->status);
            $this->assertTrue($lock->isOwnedByCurrentProcess());
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    private function record(): Record
    {
        Account::query()->forceCreate(['id' => 1, 'user_id' => 1]);
        Setting::query()->forceCreate(['id' => 1, 'user_id' => 1, 'account_id' => 1,
            'status_id_wait' => '10.101', 'user_token' => 'test', 'partner_token' => 'test']);
        return Record::query()->create(['user_id' => 1, 'account_id' => 1, 'setting_id' => 1,
            'company_id' => 10, 'record_id' => 1000, 'attendance' => 0, 'status' => Record::STATUS_PENDING]);
    }

    private function api(): AmoClient
    {
        $api = $this->createMock(AmoClient::class);
        $api->method('init')->willReturnSelf();
        $api->service = $this->createMock(Oauthapi::class);
        return $api;
    }

    private function lead(int $id): Lead
    {
        $data = ['id' => $id];
        $lead = $this->createMock(Lead::class);
        $lead->method('__get')->willReturnCallback(function ($name) use (&$data) { return $data[$name] ?? null; });
        $lead->method('__set')->willReturnCallback(function ($name, $value) use (&$data) { $data[$name] = $value; });
        $lead->method('save')->willReturnCallback(function () use (&$data) { $data['id'] = 42; return true; });
        return $lead;
    }
}
