<?php

namespace Tests\Feature\Sqns;

use App\Filament\Resources\Integrations\Sqns\SqnsResource;
use App\Jobs\Sqns\DisconnectWebhook;
use App\Jobs\Sqns\ProcessWebhook;
use App\Jobs\Sqns\SyncVisits;
use App\Jobs\Sqns\SyncVisitToAmo;
use App\Models\Core\Account;
use App\Models\Integrations\Sqns\Setting;
use App\Models\Integrations\Sqns\Visit;
use App\Models\User;
use App\Services\Billing\WidgetSubscriptionAccessService;
use App\Services\Sqns\AmoContacts;
use App\Services\Sqns\AmoLeads;
use App\Services\Sqns\AmoSync;
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
use Ufee\Amo\Models\Contact;
use Ufee\Amo\Models\Lead;

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
        $before = Setting::query()->findOrFail(1);
        $registeredWebhookUrl = $before->webhookUrl();
        $webhookKey = $before->webhook_key;

        (new DisconnectWebhook(1, 'subscription_expired'))->handle(app(WebhookLifecycle::class));

        $setting = Setting::query()->findOrFail(1);
        $this->assertFalse($setting->isConnected());
        $this->assertNull($setting->connected_at);
        $this->assertNotSame($webhookKey, $setting->webhook_key);
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://crm3.sqns.ru/api/v2/hook_settings'
            && $request['urls'] === [$registeredWebhookUrl]
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

        (new ProcessWebhook(1, 10, ['visitId' => 123, 'attendance' => 2]))
            ->handle(app(WebhookLifecycle::class));

        $this->assertDatabaseMissing('sqns_visits', ['visit_id' => 123]);
        Queue::assertNotPushed(SyncVisitToAmo::class);
    }

    public function test_webhooks_update_and_delete_an_existing_visit_without_requesting_sqns(): void
    {
        Http::fake(['*' => Http::response([], 404)]);
        $visit = Visit::query()->create([
            'user_id' => 1,
            'account_id' => 10,
            'setting_id' => 1,
            'visit_id' => 123,
            'lead_id' => 456,
            'datetime' => '2026-09-10 12:00:00',
            'cost' => 2000,
            'body' => ['id' => 123, 'datetime' => '2026-09-10 12:00:00', 'totalCost' => 2000],
        ]);
        $lifecycle = app(WebhookLifecycle::class);

        (new ProcessWebhook(1, 10, ['visitId' => 123, 'totalCost' => 2750]))
            ->handle($lifecycle);
        (new ProcessWebhook(1, 10, ['visitId' => 123, 'event' => 'visit.deleted']))
            ->handle($lifecycle);

        $visit->refresh();
        $this->assertTrue($visit->deleted);
        $this->assertSame(456, (int) $visit->lead_id);
        $this->assertEquals(2750, $visit->cost);
        $this->assertSame('2026-09-10 12:00:00', $visit->datetime->toDateTimeString());
        $this->assertDatabaseCount('sqns_visits', 1);
        Queue::assertPushed(SyncVisitToAmo::class, fn (SyncVisitToAmo $job): bool => $job->visitId === $visit->id && ! $job->createNote);
        Queue::assertPushed(SyncVisitToAmo::class, 2);
        Http::assertNothingSent();
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

    public function test_manual_import_saves_visits_and_queues_amocrm_sync(): void
    {
        Setting::query()->findOrFail(1)->update([
            'status_id_wait' => '100.200',
            'status_id_confirm' => '100.201',
            'status_id_came' => '100.202',
            'status_id_cancel' => '100.203',
            'status_id_delete' => '100.204',
        ]);
        Http::fake([
            'https://crm3.sqns.ru/api/v2/visit*' => Http::response([
                'data' => [['id' => 123, 'attendance' => 2]],
                'meta' => ['lastPage' => 1],
            ]),
        ]);

        (new SyncVisits(1, '2026-09-01', '2026-09-30'))->handle(app(WebhookLifecycle::class));

        $visit = Visit::query()->sole();
        $this->assertSame(123, (int) $visit->visit_id);
        $this->assertSame(2, (int) $visit->attendance);
        Queue::assertPushed(SyncVisitToAmo::class, fn (SyncVisitToAmo $job): bool => $job->visitId === $visit->id);
        Http::assertSentCount(1);
    }

    public function test_deleted_visit_uses_normal_contact_and_lead_sync_with_its_configured_stage(): void
    {
        $setting = Setting::query()->findOrFail(1);
        $setting->update(['pipelines' => [100], 'status_id_delete' => '100.204']);
        $visit = Visit::saveFromSqns($setting, Account::query()->findOrFail(10), [
            'id' => 123,
            'clientId' => 55,
            'deleted' => true,
        ]);

        $contact = $this->createMock(Contact::class);
        $contact->method('__get')->with('id')->willReturn(321);
        $lead = $this->createMock(Lead::class);
        $lead->method('__get')->with('id')->willReturn(456);
        $contacts = Mockery::mock(AmoContacts::class);
        $contacts->shouldReceive('resolve')->once()->andReturn($contact);
        $leads = Mockery::mock(AmoLeads::class);
        $leads->shouldReceive('openForContact')->once()->with($contact, [100])->andReturn([]);
        $leads->shouldReceive('create')->once()->with(
            $contact,
            Mockery::any(),
            Mockery::on(fn (object $status): bool => $status->pipeline_id === '100' && $status->status_id === '204'),
            Mockery::on(fn (Visit $candidate): bool => $candidate->is($visit)),
            null,
        )->andReturn($lead);

        (new SyncVisitToAmo($visit->id, false))
            ->handle(new AmoSync($contacts, $leads), app(WebhookLifecycle::class));

        $this->assertDatabaseHas('sqns_visits', [
            'id' => $visit->id,
            'lead_id' => 456,
            'status' => Visit::STATUS_SUCCESS,
        ]);
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
