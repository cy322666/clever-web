<?php

namespace Tests\Feature\YClients;

use App\Models\Integrations\YClients\Client;
use App\Models\Integrations\YClients\Record;
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('yclients_records');
        Schema::dropIfExists('yclients_clients');

        parent::tearDown();
    }
}
