<?php

namespace Tests\Feature\Core;

use App\Services\Core\ApiRequestRetention;
use App\Services\Core\ExpiredCachePruner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlatformHistoryRetentionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        Http::preventStrayRequests();
        Schema::create('api_requests', function (Blueprint $table): void {
            $table->id(); $table->integer('status_code'); $table->string('route_name')->nullable();
            $table->json('payload'); $table->timestamp('created_at');
        });
        Schema::create('yclients_records', function (Blueprint $table): void {
            $table->id(); $table->integer('record_id'); $table->string('status')->nullable();
            $table->text('error_message')->nullable(); $table->text('lead_fields_replay_error')->nullable();
            $table->text('mapped_fields_update_error')->nullable();
            $table->string('lead_fields_replay_status')->nullable();
        });
        Schema::create('yclients_record_events', function (Blueprint $table): void {
            $table->id(); $table->integer('record_id'); $table->string('status')->nullable();
        });
        foreach (['cache', 'monitoring_cache', 'cache_locks'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->string('key')->primary(); $table->integer('expiration');
            });
        }
    }

    public function test_api_cleanup_keeps_recent_requests_errors_and_unresolved_export_inputs(): void
    {
        $this->freezeSecond();
        config(['retention.api_requests_days' => 3, 'retention.api_errors_days' => 10]);
        DB::table('yclients_records')->insert([
            ['id' => 1, 'record_id' => 55, 'status' => 'failed'],
            ['id' => 2, 'record_id' => 66, 'status' => '1'],
            ['id' => 3, 'record_id' => 77, 'status' => '1'],
        ]);
        DB::table('yclients_record_events')->insert(['record_id' => 2, 'status' => '0']);
        $old = $this->request(10, 200);
        $fresh = $this->request(1, 200);
        $error = $this->request(9, 500);
        $expiredError = $this->request(11, 500);
        $failed = $this->request(9, 201, ['resource_id' => '55']);
        $nested = $this->request(9, 201, ['data' => ['id' => 55]]);
        $blocked = $this->request(10, 201, ['resource_id' => 66]);
        $completed = $this->request(9, 201, ['resource_id' => 77]);
        $expiredFailed = $this->request(11, 201, ['resource_id' => '55']);
        $expiredNested = $this->request(11, 201, ['data' => ['id' => 55]]);
        $expiredBlocked = $this->request(11, 201, ['resource_id' => 66]);
        $retention = app(ApiRequestRetention::class);
        $this->assertSame(6, $retention->count(3, 10));
        $this->artisan('app:api-requests-prune', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(11, DB::table('api_requests')->count());
        $this->artisan('app:api-requests-prune', ['--chunk' => 1])->assertSuccessful();
        $this->assertSame([$fresh, $error, $failed, $nested, $blocked], DB::table('api_requests')->orderBy('id')->pluck('id')->all());
        foreach ([$old, $expiredError, $completed, $expiredFailed, $expiredNested, $expiredBlocked] as $id) {
            $this->assertDatabaseMissing('api_requests', ['id' => $id]);
        }
    }

    public function test_cache_cleanup_keeps_live_values_and_never_touches_locks(): void
    {
        foreach (['cache', 'monitoring_cache', 'cache_locks'] as $table) {
            DB::table($table)->insert([
                ['key' => 'expired', 'expiration' => now()->subDay()->timestamp],
                ['key' => 'live', 'expiration' => now()->addDay()->timestamp],
            ]);
        }
        $pruner = app(ExpiredCachePruner::class);
        $this->assertSame(2, $pruner->prune(true));
        $this->assertSame(2, $pruner->prune(false, 1));
        $this->assertSame(2, DB::table('cache_locks')->count());
        $this->assertSame(['live'], DB::table('cache')->pluck('key')->all());
        $this->assertSame(['live'], DB::table('monitoring_cache')->pluck('key')->all());
    }

    public function test_recent_record_webhooks_are_kept_while_unsupported_noise_expires(): void
    {
        $record = DB::table('api_requests')->insertGetId(['created_at' => now()->subDays(9), 'status_code' => 201,
            'route_name' => 'yclients.hook', 'payload' => json_encode(['resource' => 'record', 'resource_id' => 888])]);
        $expired = DB::table('api_requests')->insertGetId(['created_at' => now()->subDays(11), 'status_code' => 201,
            'route_name' => 'yclients.hook', 'payload' => json_encode(['resource' => 'record', 'resource_id' => 888])]);
        $noise = DB::table('api_requests')->insertGetId(['created_at' => now()->subDays(10), 'status_code' => 422,
            'route_name' => 'yclients.hook', 'payload' => json_encode(['resource' => 'goods_operations_consumable'])]);
        $this->assertSame(2, app(ApiRequestRetention::class)->prune(3, 10));
        $this->assertDatabaseHas('api_requests', ['id' => $record]);
        $this->assertDatabaseMissing('api_requests', ['id' => $expired]);
        $this->assertDatabaseMissing('api_requests', ['id' => $noise]);
    }

    public function test_pending_field_replay_keeps_its_input_until_resolved_or_the_maximum_age_is_reached(): void
    {
        DB::table('yclients_records')->insert(['record_id' => 99, 'status' => '1', 'lead_fields_replay_status' => 'pending']);
        $request = $this->request(9, 201, ['resource_id' => '99']);
        $expired = $this->request(11, 201, ['resource_id' => '99']);
        $this->assertSame(1, app(ApiRequestRetention::class)->prune(3, 10));
        $this->assertDatabaseHas('api_requests', ['id' => $request]);
        $this->assertDatabaseMissing('api_requests', ['id' => $expired]);
        DB::table('yclients_records')->update(['lead_fields_replay_status' => 'success']);
        $this->assertSame(1, app(ApiRequestRetention::class)->prune(3, 10));
    }

    private function request(int $days, int $status, array $payload = []): int
    {
        return DB::table('api_requests')->insertGetId(['created_at' => now()->subDays($days),
            'status_code' => $status, 'payload' => json_encode($payload)]);
    }
}
