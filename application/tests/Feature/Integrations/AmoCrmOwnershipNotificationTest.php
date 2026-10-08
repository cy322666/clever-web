<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\SendAmoCrmOwnershipAlert;
use App\Services\Core\MonitoringCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AmoCrmOwnershipNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'ownership_alert_test',
            'database.connections.ownership_alert_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'cache.default' => 'array', 'alerts.cache_store' => 'array',
            'widget_lifecycle.telegram.token' => 'test-token',
            'widget_lifecycle.telegram.chat_id' => 'test-chat',
        ]);
        DB::purge('ownership_alert_test');
        Cache::store('array')->flush();
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true])]);
        Http::preventStrayRequests();
        Log::spy();
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('amo_account_id')->nullable();
            $table->string('subdomain');
            $table->string('zone')->default('ru');
            $table->boolean('active')->default(true);
            $table->string('widget')->default('default');
            $table->string('access_token')->default('preserve-access');
            $table->string('refresh_token')->default('preserve-refresh');
        });
        DB::table('accounts')->insert([
            ['id' => 11, 'user_id' => 1, 'subdomain' => 'first-client'],
            ['id' => 22, 'user_id' => 2, 'subdomain' => 'second-client'],
        ]);
    }

    public function test_unchanged_legacy_connections_do_not_page_or_change_customer_data(): void
    {
        $before = DB::table('accounts')->orderBy('id')->get()->toJson();
        $this->artisan('app:check-amo-ownership')->assertSuccessful();
        $this->artisan('app:check-amo-ownership')->assertSuccessful();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame(2, MonitoringCache::get('monitoring:amo:ownership:missing_count'));
        $this->assertSame($before, DB::table('accounts')->orderBy('id')->get()->toJson());
        Log::shouldHaveReceived('info')->once()->withArgs(fn ($message, $context): bool =>
            $message === 'amocrm.ownership.missing_ids' && $context['account_ids'] === [11, 22]);
    }

    public function test_resolved_metadata_updates_diagnostics_without_a_telegram_push(): void
    {
        $this->artisan('app:check-amo-ownership')->assertSuccessful();
        DB::table('accounts')->update(['amo_account_id' => DB::raw('id')]);
        $this->artisan('app:check-amo-ownership')->assertSuccessful();
        $this->assertSame(0, MonitoringCache::get('monitoring:amo:ownership:missing_count'));
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context): bool =>
            $message === 'amocrm.ownership.missing_ids' && $context['count'] === 0);
    }

    public function test_real_ownership_conflicts_still_queue_an_alert(): void
    {
        DB::table('accounts')->where('id', 22)->update(['subdomain' => 'first-client']);
        $this->artisan('app:check-amo-ownership')->assertSuccessful();
        Queue::assertPushed(SendAmoCrmOwnershipAlert::class, 1);
        Queue::assertPushed(SendAmoCrmOwnershipAlert::class, fn ($job): bool => $job->kind === 'conflict'
            && $job->domain === 'first-client.amocrm.ru' && $job->accountIds === [11, 22]);
    }

    public function test_previously_queued_missing_id_alerts_are_silent_even_without_cache_or_channel(): void
    {
        config(['alerts.cache_store' => 'nonexistent', 'widget_lifecycle.telegram.token' => null]);
        (new SendAmoCrmOwnershipAlert('missing_id', '', [1, 2], [11, 22]))->handle();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_real_conflict_delivery_and_deduplication_are_preserved(): void
    {
        $job = new SendAmoCrmOwnershipAlert('conflict', 'first-client.amocrm.ru', [1, 2], [11, 22]);
        $job->handle();
        $job->handle();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Конфликт владельцев amoCRM')
            && str_contains($request['text'], 'first-client.amocrm.ru'));
    }
}
