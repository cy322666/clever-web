<?php

namespace Tests\Feature\Sqns;

use App\Models\Core\Account;
use App\Models\Integrations\Sqns\Client;
use App\Models\Integrations\Sqns\Setting;
use App\Models\Integrations\Sqns\Visit;
use App\Services\Sqns\VisitIngestor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VisitIngestorTest extends TestCase
{
    public function test_repeated_partial_visit_updates_are_idempotent_and_preserve_payload_data(): void
    {
        $setting = new Setting;
        $setting->forceFill(['id' => 10, 'user_id' => 1]);
        $account = new Account;
        $account->forceFill(['id' => 20, 'user_id' => 1]);
        $ingestor = new VisitIngestor;

        $first = $ingestor->ingest($setting, $account, [
            'id' => 501,
            'clientId' => 601,
            'clientData' => [
                'id' => 601,
                'name' => 'Иван Иванов',
                'phone' => '+79000000000',
                'birthDate' => '1990-01-31',
            ],
            'datetime' => '2026-09-10 12:00:00',
            'update_date' => '2026-09-10 10:00:00',
            'attendance' => 0,
            'services' => [
                ['id' => 1, 'name' => 'Первая'],
                ['id' => 2, 'name' => 'Вторая'],
            ],
            'totalCost' => '3500',
        ]);

        $second = $ingestor->ingest($setting, $account, [
            'id' => 501,
            'clientId' => 601,
            'clientData' => [
                'id' => 601,
                'name' => 'Иван Петров',
            ],
            'datetime' => '2026-09-11 13:00:00',
            'update_date' => '2026-09-10 12:00:00',
            'attendance' => 2,
            'services' => [
                ['id' => 3, 'name' => 'Новая'],
            ],
            'totalCost' => '4000',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Visit::query()->count());
        $this->assertSame(1, Client::query()->count());
        $this->assertSame('Новая', $second->services);
        $this->assertSame(4000.0, (float) $second->cost);
        $this->assertSame(2, (int) $second->attendance);
        $this->assertSame('+79000000000', data_get($second->body, 'clientData.phone'));

        $client = Client::query()->firstOrFail();
        $this->assertSame('Иван Петров', $client->name);
        $this->assertSame('+79000000000', $client->phone);
        $this->assertSame('1990-01-31', $client->birth_date->toDateString());

        $stale = $ingestor->ingest($setting, $account, [
            'id' => 501,
            'clientId' => 601,
            'clientData' => [
                'id' => 601,
                'name' => 'Старое имя',
            ],
            'datetime' => '2026-09-10 12:00:00',
            'update_date' => '2026-09-10 11:00:00',
            'attendance' => 0,
        ]);

        $this->assertSame(2, (int) $stale->attendance);
        $this->assertSame('Иван Петров', Client::query()->firstOrFail()->name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('sqns_clients', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('additional_phone')->nullable();
            $table->string('email')->nullable();
            $table->date('birth_date')->nullable();
            $table->unsignedTinyInteger('sex')->nullable();
            $table->unsignedInteger('visits_count')->nullable();
            $table->decimal('total_arrival', 14, 2)->nullable();
            $table->json('tags')->nullable();
            $table->json('body')->nullable();
            $table->unique(['setting_id', 'client_id']);
        });

        Schema::create('sqns_visits', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('setting_id');
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('organization_name')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->dateTime('datetime')->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->smallInteger('attendance')->nullable();
            $table->boolean('deleted')->default(false);
            $table->boolean('online')->nullable();
            $table->boolean('is_paid')->nullable();
            $table->string('author')->nullable();
            $table->text('services')->nullable();
            $table->text('comment')->nullable();
            $table->dateTime('source_created_at')->nullable();
            $table->dateTime('source_updated_at')->nullable();
            $table->string('status', 20)->nullable();
            $table->text('error_message')->nullable();
            $table->json('body')->nullable();
            $table->unique(['setting_id', 'visit_id']);
        });
    }
}
