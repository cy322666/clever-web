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
            $table->string('status_id_came')->nullable(); $table->string('status_id_delete')->nullable();
            $table->text('fields_lead')->nullable(); $table->text('pipelines')->nullable();
            $table->timestamps();
        });
        Schema::create('yclients_clients', function (Blueprint $table): void {
            $table->id();
            foreach (['client_id', 'user_id', 'account_id', 'setting_id', 'company_id'] as $key) { $table->integer($key); }
            $table->integer('contact_id')->nullable(); $table->string('name')->nullable(); $table->timestamps();
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

    public function test_existing_lead_contact_is_checked_before_status_update_on_every_run(): void
    {
        $record = $this->record();
        $record->update(['lead_id' => 42]);
        $linked = false;
        $lead = $this->lead(42, function () use (&$linked): void {
            $this->assertTrue($linked, 'Contact must be linked before changing the lead status.');
        });
        $api = $this->apiWithContact($record, $lead);
        $requests = [];
        $api->expects($this->exactly(4))->method('requestV4')->willReturnCallback(
            function ($method, $path) use (&$linked, &$requests): array {
                $requests[] = $method;
                if ($method === 'POST') {
                    $this->assertSame('/api/v4/leads/42/link', $path);
                    $linked = true;
                    return [];
                }
                return ['id' => 42, '_embedded' => ['contacts' => $linked ? [['id' => 77]] : []]];
            },
        );
        $this->app->bind(AmoClient::class, fn () => $api);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->artisan('yc:send-record', ['record_id' => $record->id, 'account_id' => 1,
                'setting_id' => 1, '--skip-note' => true])->assertSuccessful();
        }

        $this->assertSame(['GET', 'POST', 'GET', 'GET'], $requests);
        $this->assertSame(Record::STATUS_SUCCESS, $record->fresh()->status);
        $this->assertSame(42, (int) $record->fresh()->lead_id);
    }

    public function test_new_lead_id_is_saved_before_link_check_and_retry_reuses_it(): void
    {
        $record = $this->record();
        $record->update(['status' => Record::STATUS_SUCCESS]);
        $lead = $this->lead(0);
        $api = $this->apiWithContact($record, $lead);
        $linked = false;
        $posts = 0;
        $api->expects($this->exactly(5))->method('requestV4')->willReturnCallback(
            function ($method) use ($record, &$linked, &$posts): array {
                $this->assertSame(42, (int) $record->fresh()->lead_id);
                if ($method === 'POST') {
                    if (++$posts === 1) {
                        throw new \RuntimeException('Link forbidden', 403);
                    }
                    $linked = true;
                    return [];
                }
                return ['id' => 42, '_embedded' => ['contacts' => $linked ? [['id' => 77]] : []]];
            },
        );
        $this->app->bind(AmoClient::class, fn () => $api);

        try {
            $this->artisan('yc:send-record', ['record_id' => $record->id, 'account_id' => 1,
                'setting_id' => 1, '--skip-note' => true])->run();
            $this->fail('Unconfirmed link must not be marked successful.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Link forbidden', $exception->getMessage());
        }

        $this->assertSame(Record::STATUS_FAILED, $record->fresh()->status);
        $this->assertStringContainsString('Link forbidden', $record->fresh()->error_message);
        $this->artisan('yc:send-record', ['record_id' => $record->id, 'account_id' => 1,
            'setting_id' => 1, '--skip-note' => true])->assertSuccessful();
        $this->assertSame(Record::STATUS_SUCCESS, $record->fresh()->status);
        $this->assertSame(42, (int) $record->fresh()->lead_id);
    }

    public function test_mapped_record_match_restores_closed_lead_before_free_active_fallback(): void
    {
        $record = $this->record();
        $this->mapRecordId($record);
        $record->update(['attendance' => 1]);
        $saved = 0;
        $closed = $this->lead(42, function () use (&$saved): void { $saved++; },
            $this->leadAttributes(142, 999, '1000'));
        $active = $this->lead(43, fn() => $this->fail('Exact record match must win over a free active lead.'),
            $this->leadAttributes(101, 10, null));
        $api = $this->apiWithContact($record, $closed, [$active, $closed]);
        $api->expects($this->once())->method('requestV4')->with('GET', '/api/v4/leads/42', [], ['with' => 'contacts'])
            ->willReturn(['id' => 42, '_embedded' => ['contacts' => [['id' => 77]]]]);
        $this->app->bind(AmoClient::class, fn() => $api);

        $this->artisan('yc:send-record', ['record_id' => $record->id, 'account_id' => 1,
            'setting_id' => 1, '--skip-note' => true])->assertSuccessful();

        $this->assertSame(1, $saved);
        $this->assertSame(42, (int) $record->fresh()->lead_id);
        $this->assertSame(142, (int) $closed->status_id);
        $this->assertSame(10, (int) $closed->pipeline_id);
        $this->assertSame(Record::STATUS_SUCCESS, $record->fresh()->status);
    }

    public function test_no_exact_match_still_reuses_a_free_active_lead(): void
    {
        $record = $this->record();
        $this->mapRecordId($record);
        $active = $this->lead(42, null, $this->leadAttributes(101, 10, null));
        $closed = $this->lead(43, fn() => $this->fail('Unrelated closed lead must not be reused.'),
            $this->leadAttributes(142, 10, '2000'));
        $api = $this->apiWithContact($record, $active, [$closed, $active]);
        $api->expects($this->once())->method('requestV4')->willReturn(['id' => 42, '_embedded' => ['contacts' => [['id' => 77]]]]);
        $this->app->bind(AmoClient::class, fn() => $api);

        $this->artisan('yc:send-record', ['record_id' => $record->id, 'account_id' => 1,
            'setting_id' => 1, '--skip-note' => true])->assertSuccessful();
        $this->assertSame(42, (int) $record->fresh()->lead_id);
    }

    public function test_active_lead_with_another_record_id_is_not_free_when_local_binding_is_missing(): void
    {
        $record = $this->record();
        $this->mapRecordId($record);
        $new = $this->lead(0);
        $occupied = $this->lead(43, fn() => $this->fail('Another record ID must not be overwritten.'),
            $this->leadAttributes(101, 10, '2000'));
        $api = $this->apiWithContact($record, $new, [$occupied]);
        $api->expects($this->once())->method('requestV4')->willReturn(['id' => 42, '_embedded' => ['contacts' => [['id' => 77]]]]);
        $this->app->bind(AmoClient::class, fn() => $api);

        $this->artisan('yc:send-record', ['record_id' => $record->id, 'account_id' => 1,
            'setting_id' => 1, '--skip-note' => true])->assertSuccessful();
        $this->assertSame(42, (int) $record->fresh()->lead_id);
    }

    public function test_exact_match_conflicting_with_another_local_record_stops_without_creating(): void
    {
        $record = $this->record();
        $this->mapRecordId($record);
        Record::query()->create(['user_id' => 1, 'account_id' => 1, 'setting_id' => 1,
            'company_id' => 10, 'record_id' => 2000, 'lead_id' => 42, 'status' => Record::STATUS_SUCCESS]);
        $closed = $this->lead(42, fn() => $this->fail('Conflicting lead must not be updated.'),
            $this->leadAttributes(142, 10, '1000'));
        $api = $this->apiWithContact($record, $closed, [$closed]);
        $api->expects($this->never())->method('requestV4');
        $this->app->bind(AmoClient::class, fn() => $api);

        try {
            $this->artisan('yc:send-record', ['record_id' => $record->id, 'account_id' => 1,
                'setting_id' => 1, '--skip-note' => true])->run();
            $this->fail('Conflicting ownership must stop processing.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('linked to another record', $exception->getMessage());
        }
        $this->assertNull($record->fresh()->lead_id);
    }

    public function test_delete_webhook_finds_closed_lead_by_mapped_record_id(): void
    {
        $record = $this->record();
        $this->mapRecordId($record);
        $record->update(['attendance' => 3]);
        $closed = $this->lead(42, null, $this->leadAttributes(142, 10, '1000'));
        $api = $this->apiWithContact($record, $closed, [$closed]);
        $api->expects($this->once())->method('requestV4')->willReturn(['id' => 42, '_embedded' => ['contacts' => [['id' => 77]]]]);
        $this->app->bind(AmoClient::class, fn() => $api);

        $this->artisan('yc:send-record', ['record_id' => $record->id, 'account_id' => 1,
            'setting_id' => 1, '--skip-note' => true])->assertSuccessful();
        $this->assertSame(42, (int) $record->fresh()->lead_id);
        $this->assertSame(143, (int) $closed->status_id);
    }

    private function mapRecordId(Record $record): void
    {
        $record->setting->update(['fields_lead' => json_encode([['field_yc' => 'record_id', 'field_amo' => '901']]),
            'pipelines' => [10], 'status_id_came' => '10.142', 'status_id_delete' => '10.143']);
    }

    private function leadAttributes(int $status, int $pipeline, mixed $recordId): array
    {
        return ['status_id' => $status, 'pipeline_id' => $pipeline,
            'custom_fields' => [(object)['id' => 901, 'values' => [(object)['value' => $recordId]]]]];
    }

    private function apiWithContact(Record $record, Lead $lead, array $linkedLeads = []): AmoClient
    {
        \App\Models\Integrations\YClients\Client::query()->forceCreate([
            'user_id' => 1, 'account_id' => 1, 'setting_id' => 1, 'company_id' => 10,
            'client_id' => 123, 'contact_id' => 77, 'name' => 'Test client',
        ]);
        $record->update(['client_id' => 123]);
        $contact = $this->createMock(\Ufee\Amo\Models\Contact::class);
        $contact->method('__get')->willReturnCallback(fn ($name) => match ($name) {
            'id' => 77,
            'leads_id' => array_map(fn($linked) => $linked->id, $linkedLeads),
            'leads' => new \Ufee\Amo\Base\Collections\Collection($linkedLeads),
            default => null,
        });
        $contact->method('save')->willReturn(true);
        $contact->expects((int) $lead->id > 0 ? $this->never() : $this->once())
            ->method('createLead')->willReturn($lead);
        $contacts = new class($contact) {
            public function __construct(public object $contact) {}
            public function find(int $id): object { return $this->contact; }
        };
        $leads = new class($lead) {
            public function __construct(public Lead $lead) {}
            public function find(int $id): Lead { return $this->lead; }
        };
        $api = $this->api();
        $api->service->method('__call')->willReturnCallback(fn ($method) => $method === 'contacts' ? $contacts : $leads);

        return $api;
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

    private function lead(int $id, ?callable $onSave = null, array $attributes = []): Lead
    {
        $data = ['id' => $id] + $attributes;
        $lead = $this->createMock(Lead::class);
        $lead->method('__get')->willReturnCallback(function ($name) use (&$data) { return $data[$name] ?? null; });
        $lead->method('__set')->willReturnCallback(function ($name, $value) use (&$data) { $data[$name] = $value; });
        $lead->method('save')->willReturnCallback(function () use (&$data, $onSave) {
            $onSave && $onSave();
            $data['id'] = 42;
            return true;
        });
        return $lead;
    }
}
