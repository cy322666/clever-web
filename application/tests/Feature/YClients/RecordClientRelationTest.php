<?php

namespace Tests\Feature\YClients;

use App\Models\Integrations\YClients\Client;
use App\Models\Integrations\YClients\Record;
use App\Models\Integrations\YClients\ResponsibleMapping;
use App\Models\Integrations\YClients\Setting;
use App\Models\amoCRM\Staff;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        $this->artisan('yc:prune-records', ['--days' => 5, '--chunk' => 1])
            ->assertSuccessful();

        $this->assertDatabaseMissing('yclients_records', ['id' => $oldRecord->id]);
        $this->assertDatabaseHas('yclients_records', ['id' => $freshRecord->id]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

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
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('setting_id');
            $table->string('status')->nullable();
            $table->text('error_message')->nullable();
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
