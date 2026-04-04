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
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('setting_id');
            $table->string('status')->nullable();
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
