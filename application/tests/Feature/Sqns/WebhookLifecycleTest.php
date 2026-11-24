<?php

namespace Tests\Feature\Sqns;

use App\Filament\Resources\Integrations\Sqns\SqnsResource;
use App\Jobs\Sqns\DisconnectWebhook;
use App\Jobs\Sqns\ProcessWebhook;
use App\Jobs\Sqns\SyncVisitToAmo;
use App\Models\Core\Account;
use App\Models\Integrations\Sqns\Setting;
use App\Models\User;
use App\Services\Billing\WidgetSubscriptionAccessService;
use App\Services\Sqns\AmoSync;
use App\Services\Sqns\VisitIngestor;
use App\Services\Sqns\WebhookLifecycle;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class WebhookLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://clever.example',
            'alerts.enabled' => false,
            'cache.default' => 'array',
            'cache.yclients_lock_store' => 'array',
            'database.default' => 'sqns_webhook_lifecycle_test',
            'database.connections.sqns_webhook_lifecycle_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'services.amocrm.widgets.sqns.client_id' => 'sqns-client-id',
            'services.amocrm.widgets.sqns.client_secret' => 'sqns-client-secret',
        ]);
        DB::purge('sqns_webhook_lifecycle_test');
        Queue::fake();
        Http::preventStrayRequests();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
            $table->string('name');
            $table->string('email');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('widget')->nullable();
            $table->unsignedBigInteger('amo_account_id')->nullable();
            $table->string('subdomain')->nullable();
            $table->string('zone')->nullable();
            $table->text('code')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('client_id')->nullable();
            $table->string('client_secret')->nullable();
            $table->string('oauth_connector')->nullable();
            $table->boolean('active')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('expires_in')->nullable();
        });
        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('resource_name');
            $table->unsignedBigInteger('setting_id')->nullable();
            $table->integer('status');
            $table->date('expires_tariff_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('workflows', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_active')->default(false);
            $table->string('trigger_type')->nullable();
            $table->string('trigger_model_type')->nullable();
            $table->string('trigger_event')->nullable();
            $table->softDeletes();
        });

        (require database_path('migrations/2026_06_14_020000_create_manual_subscription_tables.php'))->up();
        Schema::table('subscription_plans', fn (Blueprint $table) => $table->string('widget')->nullable());
        (require database_path('migrations/2026_09_07_120000_create_sqns_integration_tables.php'))->up();
        (require database_path('migrations/2026_11_24_120000_add_registered_webhook_url_to_sqns_settings.php'))->up();

        DB::table('users')->insert([
            'id' => 1,
            'uuid' => 'f213e18b-4fc4-466c-9274-232ce1e7d627',
            'name' => 'SQNS owner',
            'email' => 'owner@example.test',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('accounts')->insert([
            'id' => 10,
            'user_id' => 1,
            'widget' => 'sqns',
            'amo_account_id' => 33098322,
            'subdomain' => 'widgetscenario',
            'zone' => 'ru',
            'access_token' => 'amo-access',
            'refresh_token' => 'amo-refresh',
            'client_id' => 'sqns-client-id',
            'client_secret' => 'sqns-client-secret',
            'oauth_connector' => Account::CONNECTOR_WIDGET,
            'active' => true,
        ]);

        $setting = Setting::query()->create([
            'user_id' => 1,
            'account_id' => 10,
            'active' => true,
            'api_base_url' => 'https://crm3.sqns.ru',
            'email' => 'owner@example.test',
            'password' => 'secret',
            'token' => 'sqns-token',
            'webhook_secret' => 'hook-secret',
            'organization_id' => 77,
            'connected_at' => now(),
        ]);
        $setting->forceFill(['registered_webhook_url' => $setting->webhookUrl()])->save();
        DB::table('apps')->insert([
            'id' => 20,
            'user_id' => 1,
            'name' => 'sqns',
            'resource_name' => SqnsResource::class,
            'setting_id' => $setting->id,
            'status' => 1,
            'expires_tariff_at' => now()->addMonth()->toDateString(),
            'installed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('widget_subscriptions')->insert([
            'id' => 30,
            'user_id' => 1,
            'app_id' => 20,
            'widget' => 'sqns',
            'status' => 'active',
            'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_setting_deactivation_queues_webhook_removal(): void
    {
        $setting = Setting::query()->firstOrFail();
        $setting->update(['active' => false]);

        Queue::assertPushed(DisconnectWebhook::class, fn (DisconnectWebhook $job): bool => $job->settingId === $setting->id && $job->reason === 'setting_inactive'
        );
    }

    public function test_amocrm_disconnect_queues_webhook_removal(): void
    {
        $account = Account::query()->findOrFail(10);
        $account->forceFill([
            'active' => false,
            'access_token' => null,
            'refresh_token' => null,
            'subdomain' => null,
        ])->save();

        Queue::assertPushed(DisconnectWebhook::class, fn (DisconnectWebhook $job): bool => $job->settingId === 1 && $job->reason === 'amocrm_disconnected'
        );
    }

    public function test_user_deactivation_queues_webhook_removal(): void
    {
        User::query()->findOrFail(1)->update(['active' => false]);

        Queue::assertPushed(DisconnectWebhook::class, fn (DisconnectWebhook $job): bool => $job->settingId === 1 && $job->reason === 'user_inactive'
        );
    }

    public function test_job_keeps_webhook_when_connection_became_valid_again(): void
    {
        (new DisconnectWebhook(1, 'setting_inactive'))->handle(app(WebhookLifecycle::class));

        Http::assertNothingSent();
        $this->assertTrue(Setting::query()->findOrFail(1)->isConnected());
    }

    public function test_connect_is_rejected_when_integration_is_inactive(): void
    {
        $setting = Setting::query()->firstOrFail();
        DB::table('sqns_settings')->where('id', $setting->id)->update(['active' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Сначала включите интеграцию и подключите amoCRM.');

        app(WebhookLifecycle::class)->connect($setting);
    }

    public function test_job_removes_webhook_for_an_expired_subscription(): void
    {
        DB::table('widget_subscriptions')->where('id', 30)->update([
            'status' => 'expired',
            'blocked_at' => now(),
        ]);
        Http::fake([
            'https://crm3.sqns.ru/api/v2/hook_settings' => Http::response([], 200),
        ]);

        (new DisconnectWebhook(1, 'subscription_expired'))->handle(app(WebhookLifecycle::class));

        $setting = Setting::query()->findOrFail(1);
        $this->assertFalse($setting->isConnected());
        $this->assertNull($setting->connected_at);
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://crm3.sqns.ru/api/v2/hook_settings'
            && $request['urls'] === [$setting->webhookUrl()]
        );
    }

    public function test_subscription_expiry_queues_webhook_removal(): void
    {
        DB::table('widget_subscriptions')->where('id', 30)->update([
            'ends_at' => now()->subDay()->toDateString(),
        ]);

        $stats = app(WidgetSubscriptionAccessService::class)->expireOverdueSubscriptions();

        $this->assertSame(1, $stats['expired']);
        $this->assertFalse(Setting::query()->findOrFail(1)->active);
        Queue::assertPushed(DisconnectWebhook::class, fn (DisconnectWebhook $job): bool => $job->settingId === 1 && $job->reason === 'subscription_inactive'
        );
    }

    public function test_direct_subscription_block_queues_webhook_removal(): void
    {
        $subscription = \App\Models\Billing\WidgetSubscription::query()->findOrFail(30);
        $subscription->forceFill([
            'status' => 'blocked',
            'blocked_at' => now(),
        ])->save();

        Queue::assertPushed(DisconnectWebhook::class, fn (DisconnectWebhook $job): bool => $job->settingId === 1 && $job->reason === 'subscription_inactive'
        );
    }

    public function test_queued_webhook_is_ignored_after_integration_was_disabled(): void
    {
        DB::table('sqns_settings')->where('id', 1)->update(['active' => false]);
        $ingestor = Mockery::mock(VisitIngestor::class);
        $ingestor->shouldNotReceive('ingest');

        (new ProcessWebhook(1, 10, ['visitId' => 123]))
            ->handle($ingestor, app(WebhookLifecycle::class));

        $this->assertDatabaseMissing('sqns_visits', ['visit_id' => 123]);
    }

    public function test_queued_amocrm_sync_is_ignored_after_integration_was_disabled(): void
    {
        DB::table('sqns_settings')->where('id', 1)->update(['active' => false]);
        DB::table('sqns_visits')->insert([
            'id' => 50,
            'user_id' => 1,
            'account_id' => 10,
            'setting_id' => 1,
            'visit_id' => 123,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sync = Mockery::mock(AmoSync::class);
        $sync->shouldNotReceive('sync');

        (new SyncVisitToAmo(50))->handle($sync, app(WebhookLifecycle::class));

        $this->assertDatabaseHas('sqns_visits', ['id' => 50, 'status' => 'pending']);
    }

    public function test_cleanup_command_finds_stale_webhook_without_changing_it_in_dry_run(): void
    {
        DB::table('sqns_settings')->where('id', 1)->update(['active' => false]);

        $this->artisan('sqns:webhooks:cleanup --dry-run')
            ->expectsOutput('Проверено: 1')
            ->expectsOutput('Кандидатов: 1')
            ->expectsOutput('Поставлено в очередь: 0')
            ->assertSuccessful();

        Queue::assertNotPushed(DisconnectWebhook::class);
    }

    public function test_cleanup_detects_an_amocrm_account_without_tokens(): void
    {
        DB::table('accounts')->where('id', 10)->update([
            'access_token' => null,
            'refresh_token' => null,
        ]);

        $this->artisan('sqns:webhooks:cleanup --dry-run')
            ->expectsOutput('Кандидатов: 1')
            ->assertSuccessful();
    }

    public function test_failed_delete_keeps_connection_state_and_records_error(): void
    {
        DB::table('widget_subscriptions')->where('id', 30)->update([
            'status' => 'expired',
            'blocked_at' => now(),
        ]);
        Http::fake([
            'https://crm3.sqns.ru/api/v2/hook_settings' => Http::response(['message' => 'invalid hook'], 422),
        ]);

        try {
            (new DisconnectWebhook(1, 'subscription_expired'))->handle(app(WebhookLifecycle::class));
            $this->fail('SQNS delete failure was not propagated.');
        } catch (Throwable) {
            $setting = Setting::query()->findOrFail(1);
            $this->assertTrue($setting->isConnected());
            $this->assertNotNull($setting->connected_at);
            $this->assertStringContainsString('Не удалось удалить webhook SQNS', (string) $setting->last_error);
        }
    }
}
