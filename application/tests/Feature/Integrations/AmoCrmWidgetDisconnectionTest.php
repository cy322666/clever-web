<?php

namespace Tests\Feature\Integrations;

use App\Mail\AmoDisconnected;
use App\Models\Core\Account;
use App\Services\Integrations\AmoCrmWidgetLifecycleTelegramNotifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AmoCrmWidgetDisconnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'disconnect_test',
            'database.connections.disconnect_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'cache.default' => 'array',
            'services.amocrm.client_id' => 'shared-client',
            'services.amocrm.client_secret' => 'shared-secret',
            'services.amocrm.widgets.yclients.use_shared_connector' => true,
        ]);
        foreach (['workflows', 'import-excel', 'yclients', 'finder', 'sqns'] as $widget) {
            config([
                "services.amocrm.widgets.$widget.client_id" => "$widget-client",
                "services.amocrm.widgets.$widget.client_secret" => "$widget-secret",
            ]);
        }
        DB::purge('disconnect_test');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('widget');
            $table->unsignedBigInteger('amo_account_id')->nullable();
            $table->string('subdomain')->nullable();
            $table->string('zone')->default('ru');
            $table->string('client_id');
            $table->string('client_secret');
            $table->string('oauth_connector')->nullable();
            $table->string('redirect_uri');
            $table->text('code')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->boolean('active')->default(true);
            $table->string('expires_tariff')->nullable();
        });
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'First', 'email' => 'first@example.test'],
            ['id' => 2, 'name' => 'Second', 'email' => 'second@example.test'],
        ]);
        (require database_path('migrations/2026_09_24_130000_create_finder_tables.php'))->up();
        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();
        $this->mock(AmoCrmWidgetLifecycleTelegramNotifier::class)->shouldReceive('notify')->andReturnNull();
    }

    public static function callbacks(): array
    {
        $cases = [];
        foreach (['flow' => 'workflows', 'excel' => 'import-excel', 'yclients' => 'yclients', 'finder' => 'finder'] as $route => $widget) {
            foreach (['GET', 'POST'] as $method) {
                $cases["$route $method"] = [$route, $widget, $method];
            }
        }

        return $cases;
    }

    #[DataProvider('callbacks')]
    public function test_signed_uninstall_disconnects_only_its_widget_and_tenant(string $route, string $widget, string $method): void
    {
        $target = $this->connection($widget);
        $neighbor = $this->connection('default', ['client_id' => "$widget-client"]);
        $other = $this->connection($widget, ['user_id' => 2, 'amo_account_id' => 202, 'subdomain' => 'other']);
        $targetBefore = $target->getAttributes();
        $neighborBefore = $neighbor->getAttributes();
        $otherBefore = $other->getAttributes();
        $this->mock(AmoCrmWidgetLifecycleTelegramNotifier::class)->shouldReceive('notify')->once()
            ->with('off', $widget, Mockery::type('array'), Mockery::on(fn (array $context): bool =>
                $context['account_id'] === 101 && $context['updated'] === 1
                && $context['referer'] === 'tenant.amocrm.ru'));

        $this->json($method, '/api/amocrm/off/'.$route, $this->payload($widget) + [
            'subdomain' => 'other', 'widget' => 'default', 'account' => ['id' => 202],
        ])->assertOk()->assertExactJson(['ok' => true, 'updated' => 1, 'mail_queued' => 1]);

        $this->assertSame(array_replace($targetBefore, [
            'active' => 0, 'code' => null, 'access_token' => null, 'refresh_token' => null, 'subdomain' => null,
        ]), $target->fresh()->getAttributes());
        $this->assertSame($neighborBefore, $neighbor->fresh()->getAttributes());
        $this->assertSame($otherBefore, $other->fresh()->getAttributes());
        Mail::assertQueued(AmoDisconnected::class, fn ($mail): bool => $mail->hasTo('first@example.test'));
        Mail::assertQueuedCount(1);
        Http::assertNothingSent();
    }

    #[DataProvider('callbacks')]
    public function test_unverified_or_wrong_widget_callbacks_cannot_disconnect_or_notify(string $route, string $widget, string $method): void
    {
        $target = $this->connection($widget);
        $before = $target->getAttributes();
        $this->mock(AmoCrmWidgetLifecycleTelegramNotifier::class)->shouldNotReceive('notify');
        $wrongWidget = $widget === 'finder' ? 'workflows' : 'finder';
        foreach ([
            ['subdomain' => 'tenant'],
            array_replace($this->payload($widget), ['signature' => str_repeat('a', 64)]),
            array_replace($this->payload($widget), ['account_id' => 202]),
            $this->payload($wrongWidget),
            ['client_id' => 'shared-client', 'account_id' => 101, 'signature' => hash_hmac('sha256', 'shared-client|101', 'shared-secret')],
        ] as $payload) {
            $this->json($method, '/api/amocrm/off/'.$route, $payload)->assertForbidden();
        }
        $this->assertSame($before, $target->fresh()->getAttributes());
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
    }

    #[DataProvider('callbacks')]
    public function test_legacy_identity_comes_only_from_the_previously_stored_token(string $route, string $widget, string $method): void
    {
        $target = $this->connection($widget, ['amo_account_id' => null, 'access_token' => $this->storedToken("$widget-client", 101)]);
        $other = $this->connection($widget, ['user_id' => 2, 'subdomain' => 'other', 'amo_account_id' => null,
            'access_token' => $this->storedToken("$widget-client", 202)]);
        $before = $other->getAttributes();
        $this->json($method, '/api/amocrm/off/'.$route, $this->payload($widget) + [
            'access_token' => $this->storedToken("$widget-client", 202), 'subdomain' => 'other',
        ])->assertOk()->assertJsonPath('updated', 1);
        $this->assertFalse($target->fresh()->active);
        $this->assertNull($target->fresh()->amo_account_id);
        $this->assertSame($before, $other->fresh()->getAttributes());
    }

    public function test_unmatched_or_unusable_legacy_identity_cannot_revoke_by_domain(): void
    {
        $target = $this->connection('import-excel', ['amo_account_id' => null]);
        foreach (['opaque-token', '..', $this->storedToken('wrong-client', 101), $this->storedToken('import-excel-client', 202)] as $token) {
            DB::table('accounts')->where('id', $target->id)->update(['access_token' => $token]);
            $before = $target->fresh()->getAttributes();
            $this->postJson('/api/amocrm/off/excel', $this->payload('import-excel') + ['subdomain' => 'tenant'])
                ->assertOk()->assertJsonPath('updated', 0);
            $this->assertSame($before, $target->fresh()->getAttributes());
        }
        Mail::assertNothingQueued();
    }

    public function test_known_identity_takes_precedence_over_old_token_metadata(): void
    {
        $target = $this->connection('import-excel', ['amo_account_id' => 202, 'access_token' => $this->storedToken('import-excel-client', 101)]);
        $before = $target->getAttributes();
        $this->postJson('/api/amocrm/off/excel', $this->payload('import-excel'))->assertOk()->assertJsonPath('updated', 0);
        $this->assertSame($before, $target->fresh()->getAttributes());
    }

    public function test_yclients_shared_connector_is_disabled_without_disabling_other_shared_connections(): void
    {
        foreach ([null, Account::CONNECTOR_SHARED] as $connector) {
            DB::table('accounts')->delete();
            $target = $this->connection('yclients', ['client_id' => 'shared-client', 'oauth_connector' => $connector,
                'amo_account_id' => null, 'access_token' => $this->storedToken('shared-client', 101)]);
            $shared = $this->connection('default', ['client_id' => 'shared-client']);
            $before = $shared->getAttributes();
            $this->postJson('/api/amocrm/off/yclients', $this->payload('yclients'))->assertOk()->assertJsonPath('updated', 1);
            $this->assertFalse($target->fresh()->active);
            $this->assertSame($before, $shared->fresh()->getAttributes());
        }
    }

    public function test_shared_credentials_cannot_be_used_as_a_cross_widget_fallback(): void
    {
        $target = $this->connection('import-excel', ['client_id' => 'shared-client']);
        $this->postJson('/api/amocrm/off/excel', $this->payload('import-excel'))->assertOk()->assertJsonPath('updated', 0);
        $this->assertTrue($target->fresh()->active);
        $target = $this->connection('yclients', ['client_id' => 'shared-client', 'oauth_connector' => Account::CONNECTOR_WIDGET]);
        $this->postJson('/api/amocrm/off/yclients', $this->payload('yclients'))->assertOk()->assertJsonPath('updated', 0);
        $this->assertTrue($target->fresh()->active);
    }

    public function test_repeated_uninstall_keeps_notifications_but_does_not_requeue_disconnect_email(): void
    {
        $this->connection('workflows');
        $this->mock(AmoCrmWidgetLifecycleTelegramNotifier::class)->shouldReceive('notify')->twice();
        $this->postJson('/api/amocrm/off/flow', $this->payload('workflows'))->assertOk()->assertJsonPath('updated', 1);
        $this->postJson('/api/amocrm/off/flow', $this->payload('workflows'))->assertOk()
            ->assertExactJson(['ok' => true, 'updated' => 0, 'mail_queued' => 0]);
        Mail::assertQueuedCount(1);
    }

    public function test_finder_uninstall_stops_monitoring_and_pending_actions_but_preserves_shared_authorization_and_tariff(): void
    {
        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('resource_name');
            $table->unsignedBigInteger('setting_id');
            $table->integer('status');
            $table->date('expires_tariff_at');
            $table->timestamps();
        });
        $this->connection('finder');
        $shared = $this->connection('default');
        $sharedBefore = $shared->getAttributes();
        foreach ([1, 2] as $userId) {
            DB::table('finder_settings')->insert([
                'id' => $userId, 'user_id' => $userId, 'account_id' => $userId === 1 ? $shared->id : null,
                'active' => true, 'enabled' => true, 'settings' => json_encode(['minutes' => 5, 'create_task' => true]),
            ]);
            DB::table('apps')->insert([
                'user_id' => $userId, 'name' => 'finder',
                'resource_name' => \App\Filament\Resources\Integrations\Finder\FinderResource::class,
                'setting_id' => $userId, 'status' => \App\Models\App::STATE_ACTIVE, 'expires_tariff_at' => '2027-12-31',
            ]);
            DB::table('finder_conversations')->insert([
                'id' => $userId, 'setting_id' => $userId, 'chat_id' => 'chat-'.$userId,
                'pending_since' => now(), 'next_check_at' => now()->addMinutes(5),
            ]);
            DB::table('finder_actions')->insert([
                'setting_id' => $userId, 'conversation_id' => $userId, 'cycle' => 1, 'attempt' => 1,
                'event' => 'overdue', 'kind' => 'task', 'status' => 'pending', 'payload' => '{}',
            ]);
        }
        $options = DB::table('finder_settings')->where('id', 1)->value('settings');
        $this->postJson('/api/amocrm/off/finder', $this->payload('finder'))->assertOk()->assertJsonPath('updated', 1);
        $this->assertSame($sharedBefore, $shared->fresh()->getAttributes());
        $this->assertDatabaseHas('finder_settings', ['id' => 1, 'active' => false, 'enabled' => false, 'settings' => $options]);
        $this->assertDatabaseHas('finder_settings', ['id' => 2, 'active' => true, 'enabled' => true]);
        $this->assertDatabaseHas('finder_actions', ['setting_id' => 1, 'status' => 'cancelled']);
        $this->assertDatabaseHas('finder_actions', ['setting_id' => 2, 'status' => 'pending']);
        $this->assertDatabaseHas('finder_conversations', ['id' => 1, 'pending_since' => null, 'next_check_at' => null]);
        $this->assertNotNull(DB::table('finder_conversations')->where('id', 2)->value('next_check_at'));
        $this->assertDatabaseHas('apps', ['user_id' => 1, 'status' => \App\Models\App::STATE_INACTIVE, 'expires_tariff_at' => '2027-12-31']);
        $this->assertDatabaseHas('apps', ['user_id' => 2, 'status' => \App\Models\App::STATE_ACTIVE]);
        $this->assertDatabaseCount('finder_actions', 2);
    }

    private function connection(string $widget, array $overrides = []): Account
    {
        $id = DB::table('accounts')->insertGetId(array_replace([
            'user_id' => 1, 'widget' => $widget, 'amo_account_id' => 101, 'subdomain' => 'tenant',
            'zone' => 'ru', 'client_id' => "$widget-client", 'client_secret' => "$widget-secret",
            'redirect_uri' => 'https://platform.example/callback', 'code' => 'old-code',
            'access_token' => 'access', 'refresh_token' => 'refresh', 'active' => true, 'expires_tariff' => '2027-12-31',
        ], $overrides));

        return Account::findOrFail($id);
    }

    private function payload(string $widget): array
    {
        return ['client_uuid' => "$widget-client", 'account_id' => 101,
            'signature' => hash_hmac('sha256', "$widget-client|101", "$widget-secret")];
    }

    private function storedToken(string $audience, int $accountId): string
    {
        $encode = fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $encode(['alg' => 'RS256']).'.'.$encode(['aud' => $audience, 'account_id' => $accountId, 'exp' => 1]).'.stored-signature';
    }
}
