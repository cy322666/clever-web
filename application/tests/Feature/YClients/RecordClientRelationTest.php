<?php

namespace Tests\Feature\YClients;

use App\Models\Integrations\YClients\Client;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\ResponsibleMapping;
use App\Models\Integrations\YClients\Setting;
use App\Models\Core\Account;
use App\Models\User;
use App\Http\Controllers\Api\YClientsController;
use App\Jobs\YClients\RecordSend;
use App\Models\amoCRM\Staff;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecordClientRelationTest extends TestCase
{
    public function test_record_scoped_client_is_filtered_by_tenant_and_company(): void
    {
        $expectedClient = Client::query()->create([
            'client_id' => 100500,
            'company_id' => 10,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
            'name' => 'Expected',
        ]);

        Client::query()->create([
            'client_id' => 100500,
            'company_id' => 10,
            'user_id' => 1,
            'account_id' => 12,
            'setting_id' => 111,
            'name' => 'Wrong account',
        ]);

        Client::query()->create([
            'client_id' => 100500,
            'company_id' => 99,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
            'name' => 'Wrong company',
        ]);

        $record = Record::query()->create([
            'record_id' => 1,
            'client_id' => 100500,
            'company_id' => 10,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
            'status' => Record::STATUS_PENDING,
        ]);

        $record->refresh();

        $resolvedClient = $record->scopedClient();

        $this->assertNotNull($resolvedClient);
        $this->assertSame($expectedClient->id, $resolvedClient->id);
    }

    public function test_record_detects_lead_owned_by_another_yclients_record(): void
    {
        Record::query()->create([
            'record_id' => 1001,
            'client_id' => 100500,
            'company_id' => 10,
            'lead_id' => 555,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
        ]);

        $record = Record::query()->create([
            'record_id' => 1002,
            'client_id' => 100500,
            'company_id' => 10,
            'lead_id' => 555,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
        ]);

        $this->assertTrue($record->isLeadOwnedByAnotherYClientsRecord());
        $this->assertSame(1001, $record->leadOwnerRecord()?->record_id);
    }

    public function test_record_allows_same_yclients_record_duplicates_to_share_lead(): void
    {
        Record::query()->create([
            'record_id' => 1001,
            'client_id' => 100500,
            'company_id' => 10,
            'lead_id' => 555,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
        ]);

        $record = Record::query()->create([
            'record_id' => 1001,
            'client_id' => 100500,
            'company_id' => 10,
            'lead_id' => 555,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
        ]);

        $this->assertFalse($record->isLeadOwnedByAnotherYClientsRecord());
    }

    public function test_failed_export_scope_selects_errors_without_pending_by_default(): void
    {
        $failed = Record::query()->create([
            'record_id' => 1001,
            'client_id' => 100500,
            'company_id' => 10,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
            'status' => Record::STATUS_FAILED,
        ]);

        $failedWithMessage = Record::query()->create([
            'record_id' => 1002,
            'client_id' => 100500,
            'company_id' => 10,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
            'status' => Record::STATUS_PENDING,
            'error_message' => 'amoCRM error',
        ]);

        Record::query()->create([
            'record_id' => 1003,
            'client_id' => 100500,
            'company_id' => 10,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
            'status' => Record::STATUS_PENDING,
        ]);

        Record::query()->create([
            'record_id' => 1004,
            'client_id' => 100500,
            'company_id' => 10,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
            'status' => Record::STATUS_SUCCESS,
        ]);

        $ids = Record::query()->failedExport()->pluck('id')->all();

        $this->assertSame([$failed->id, $failedWithMessage->id], $ids);
    }

    public function test_failed_export_scope_can_include_pending_records(): void
    {
        $failed = Record::query()->create([
            'record_id' => 1001,
            'client_id' => 100500,
            'company_id' => 10,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
            'status' => Record::STATUS_FAILED,
        ]);

        $pending = Record::query()->create([
            'record_id' => 1002,
            'client_id' => 100500,
            'company_id' => 10,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
            'status' => Record::STATUS_PENDING,
        ]);

        Record::query()->create([
            'record_id' => 1003,
            'client_id' => 100500,
            'company_id' => 10,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
            'status' => Record::STATUS_SUCCESS,
        ]);

        $ids = Record::query()->failedExport(true)->pluck('id')->all();

        $this->assertSame([$failed->id, $pending->id], $ids);
    }

    public function test_pending_mapped_fields_update_scope_skips_successfully_updated_records(): void
    {
        $pending = Record::query()->create([
            'record_id' => 1001,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
        ]);
        $failed = Record::query()->create([
            'record_id' => 1002,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
            'mapped_fields_update_error' => 'failed',
        ]);
        Record::query()->create([
            'record_id' => 1003,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
            'mapped_fields_updated_at' => now(),
        ]);

        $this->assertSame(
            [$pending->id, $failed->id],
            Record::query()->pendingMappedFieldsUpdate()->orderBy('id')->pluck('id')->all()
        );
        $this->assertCount(3, Record::query()->pendingMappedFieldsUpdate(true)->get());
    }

    public function test_setting_resolves_active_amo_responsible_by_branch_and_created_user(): void
    {
        Staff::query()->create([
            'user_id' => 1,
            'staff_id' => 9001,
            'name' => 'Менеджер amoCRM',
            'active' => true,
        ]);

        $setting = new Setting([
            'user_id' => 1,
        ]);
        $setting->id = 111;

        ResponsibleMapping::query()->create([
            'setting_id' => 111,
            'amo_user_id' => 9001,
            'yc_user_keys' => ['10:4321', '10:4322'],
            'active' => true,
        ]);

        $matchingRecord = new Record([
            'company_id' => 10,
            'created_user_id' => 4321,
        ]);
        $secondMatchingRecord = new Record([
            'company_id' => 10,
            'created_user_id' => 4322,
        ]);
        $otherBranchRecord = new Record([
            'company_id' => 99,
            'created_user_id' => 4321,
        ]);

        $this->assertSame(9001, $setting->responsibleUserIdForRecord($matchingRecord));
        $this->assertSame(9001, $setting->responsibleUserIdForRecord($secondMatchingRecord));
        $this->assertNull($setting->responsibleUserIdForRecord($otherBranchRecord));
    }

    public function test_setting_uses_default_responsible_when_creator_mapping_is_missing(): void
    {
        Staff::query()->create([
            'user_id' => 1,
            'staff_id' => 9001,
            'name' => 'Ответственный по умолчанию',
            'active' => true,
        ]);

        $setting = new Setting([
            'user_id' => 1,
            'default_responsible_user_id' => 9001,
        ]);
        $setting->id = 111;

        $record = new Record([
            'company_id' => 10,
            'created_user_id' => 4321,
        ]);

        $this->assertSame(9001, $setting->responsibleUserIdForRecord($record));
    }

    public function test_setting_ignores_inactive_default_responsible(): void
    {
        Staff::query()->create([
            'user_id' => 1,
            'staff_id' => 9001,
            'name' => 'Уволенный пользователь',
            'active' => false,
        ]);

        $setting = new Setting([
            'user_id' => 1,
            'default_responsible_user_id' => 9001,
        ]);
        $setting->id = 111;

        $record = new Record([
            'company_id' => 10,
            'created_user_id' => 4321,
        ]);

        $this->assertNull($setting->responsibleUserIdForRecord($record));
    }

    public function test_mapping_reports_yclients_users_reserved_by_other_amo_users(): void
    {
        $first = ResponsibleMapping::query()->create([
            'setting_id' => 111,
            'amo_user_id' => 9001,
            'yc_user_keys' => ['10:4321', '10:4322'],
            'active' => true,
        ]);
        ResponsibleMapping::query()->create([
            'setting_id' => 111,
            'amo_user_id' => 9002,
            'yc_user_keys' => ['10:4323', '20:5001'],
            'active' => true,
        ]);

        $this->assertSame(['10:4323', '20:5001'], $first->reservedUserKeysByOtherMappings());
    }

    public function test_prune_records_command_deletes_only_records_older_than_retention(): void
    {
        $oldRecord = Record::query()->create([
            'record_id' => 1001,
            'client_id' => 100500,
            'company_id' => 10,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
        ]);
        $oldRecord->forceFill([
            'created_at' => now()->subDays(6),
            'updated_at' => now()->subDays(6),
        ])->save();

        $freshRecord = Record::query()->create([
            'record_id' => 1002,
            'client_id' => 100500,
            'company_id' => 10,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
        ]);
        $freshRecord->forceFill([
            'created_at' => now()->subDays(4),
            'updated_at' => now()->subDays(4),
        ])->save();

        $recentlyUpdatedOldRecord = Record::query()->create([
            'record_id' => 1003,
            'client_id' => 100500,
            'company_id' => 10,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
        ]);
        $recentlyUpdatedOldRecord->forceFill([
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDay(),
        ])->save();

        $futureAppointmentOldRecord = Record::query()->create([
            'record_id' => 1004,
            'client_id' => 100500,
            'company_id' => 10,
            'user_id' => 1,
            'account_id' => 11,
            'setting_id' => 111,
            'datetime' => now()->addDay(),
        ]);
        $futureAppointmentOldRecord->forceFill([
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ])->save();

        $this->artisan('yc:prune-records', ['--days' => 5, '--chunk' => 1])
            ->assertSuccessful();

        $this->assertDatabaseMissing('yclients_records', ['id' => $oldRecord->id]);
        $this->assertDatabaseHas('yclients_records', ['id' => $freshRecord->id]);
        $this->assertDatabaseHas('yclients_records', ['id' => $recentlyUpdatedOldRecord->id]);
        $this->assertDatabaseHas('yclients_records', ['id' => $futureAppointmentOldRecord->id]);
    }

    public function test_yclients_delete_webhook_marks_record_deleted_without_erasing_existing_lead_link(): void
    {
        Queue::fake();

        $user = User::withoutEvents(fn() => User::query()->create([
            'uuid' => (string)Str::uuid(),
            'name' => 'YClients user',
            'email' => 'yc@example.test',
            'password' => 'secret',
            'active' => true,
        ]));

        $account = Account::query()->forceCreate([
            'user_id' => $user->id,
            'subdomain' => 'test',
            'active' => true,
            'widget' => 'yclients',
        ]);

        Setting::query()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'active' => true,
            'status_id_delete' => '9955494.143',
        ]);

        $record = Record::query()->create([
            'record_id' => 1792150137,
            'client_id' => 360604959,
            'company_id' => 331981,
            'lead_id' => 33073703,
            'user_id' => $user->id,
            'account_id' => $account->id,
            'setting_id' => 1,
            'staff_name' => 'Дашкова Екатерина Вадимовна',
            'datetime' => now()->addDay(),
            'attendance' => 0,
            'status' => Record::STATUS_SUCCESS,
        ]);

        $request = Request::create('/api/yclients/hook/' . $user->uuid, 'POST', [
            'company_id' => 331981,
            'resource' => 'record',
            'resource_id' => 1792150137,
            'status' => 'delete',
            'data' => [
                'id' => 1792150137,
                'deleted' => true,
            ],
        ]);

        $response = YClientsController::record($user->fresh(), $request);

        $this->assertSame(201, $response->getStatusCode());

        $record->refresh();

        $this->assertSame(3, (int)$record->attendance);
        $this->assertSame(33073703, (int)$record->lead_id);
        $this->assertSame('Дашкова Екатерина Вадимовна', $record->staff_name);
        $this->assertSame(Record::STATUS_PENDING, $record->status);

        Queue::assertPushed(
            RecordSend::class,
            fn(RecordSend $job): bool => $job->record->id === $record->id
                && $job->account->id === $account->id
                && $job->setting->id === 1
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('user_id');
            $table->string('subdomain')->nullable();
            $table->boolean('active')->default(false);
            $table->string('widget')->nullable();
            $table->integer('created_at')->nullable();
        });

        Schema::create('apps', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('resource_name')->nullable();
            $table->unsignedBigInteger('setting_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        Schema::create('yclients_settings', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('active')->default(false);
            $table->string('status_id_delete')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('account_id');
            $table->timestamps();
        });

        Schema::create('yclients_clients', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('client_id');
            $table->integer('company_id')->nullable();
            $table->integer('contact_id')->nullable();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('visits')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('setting_id');
            $table->timestamps();
        });

        Schema::create('yclients_records', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('record_id')->nullable();
            $table->integer('client_id')->nullable();
            $table->integer('company_id')->nullable();
            $table->integer('lead_id')->nullable();
            $table->string('staff_name')->nullable();
            $table->integer('attendance')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('setting_id');
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
            $table->string('lead_fields_replay_status')->nullable();
            $table->timestamp('lead_fields_replayed_at')->nullable();
            $table->text('lead_fields_replay_error')->nullable();
            $table->timestamp('mapped_fields_updated_at')->nullable();
            $table->text('mapped_fields_update_error')->nullable();
            $table->timestamp('datetime')->nullable();
            $table->timestamps();
        });

        Schema::create('amocrm_staffs', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('staff_id');
            $table->string('name')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('yclients_responsible_mappings', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('amo_user_id');
            $table->json('yc_user_keys')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('yclients_records');
        Schema::dropIfExists('yclients_clients');
        Schema::dropIfExists('amocrm_staffs');
        Schema::dropIfExists('yclients_responsible_mappings');

        parent::tearDown();
    }
}
