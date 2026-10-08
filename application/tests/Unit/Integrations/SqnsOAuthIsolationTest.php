<?php

namespace Tests\Unit\Integrations;

use App\Helpers\Traits\SyncAmoCRMPage;
use App\Http\Controllers\Api\AuthController;
use App\Models\Core\Account;
use App\Models\User;
use App\Services\Integrations\AmoCrmWidgetInstallationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SqnsOAuthIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqns_oauth_test',
            'database.connections.sqns_oauth_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'services.amocrm.client_id' => 'platform-client',
            'services.amocrm.client_secret' => 'platform-secret',
            'services.amocrm.redirect_uri' => 'https://platform.example/api/amocrm/redirect',
            'services.amocrm.widgets.sqns.client_id' => 'sqns-client',
            'services.amocrm.widgets.sqns.client_secret' => 'sqns-secret',
            'services.amocrm.widgets.sqns.redirect_uri' => 'https://platform.example/api/amocrm/install/sqns',
        ]);
        DB::purge('sqns_oauth_test');
        Http::preventStrayRequests();
        Http::fake();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('widget')->nullable();
            $table->string('oauth_connector')->nullable();
            $table->boolean('active')->default(false);
            $table->integer('expires_in')->nullable();
            $table->integer('created_at')->nullable();
            foreach (['subdomain', 'zone', 'client_id', 'client_secret', 'access_token', 'refresh_token', 'code', 'redirect_uri'] as $field) {
                $table->string($field)->nullable();
            }
        });
        DB::table('users')->insert([
            ['id' => 1, 'uuid' => 'ba2c3f10-3250-4d86-b36a-8f924fdaaa01'],
            ['id' => 2, 'uuid' => 'ba2c3f10-3250-4d86-b36a-8f924fdaaa02'],
        ]);
    }

    public function test_sqns_fallback_to_platform_credentials_is_disabled(): void
    {
        $this->assertFalse(config('services.amocrm.widgets.sqns.fallback_to_platform_credentials'));
    }

    public function test_sqns_never_reuses_platform_or_another_users_connection(): void
    {
        $shared = $this->account('default', 'platform-client', ['client_secret' => 'platform-secret']);
        $foreign = $this->account('sqns', 'sqns-client', ['user_id' => 2]);
        $before = [$shared->getAttributes(), $foreign->getAttributes()];
        $user = User::findOrFail(1);

        $this->assertNull($user->resolveAmoAccountForWidget('sqns'));
        $slot = $user->resolveAmoAccountForWidget('sqns', true);

        $this->assertSame('sqns', $slot->widget);
        $this->assertSame(1, $slot->user_id);
        $this->assertFalse($slot->fresh()->active);
        foreach (['subdomain', 'client_id', 'client_secret', 'access_token', 'refresh_token'] as $key) {
            $this->assertNull($slot->{$key});
        }
        $this->assertSame($before, [$shared->fresh()->getAttributes(), $foreign->fresh()->getAttributes()]);
    }

    public function test_old_platform_tokens_are_not_used_as_sqns_tokens(): void
    {
        $sqns = $this->account('sqns', 'platform-client', ['client_secret' => 'platform-secret']);
        $before = $sqns->getAttributes();
        $user = User::findOrFail(1);

        $this->assertNull($user->resolveAmoAccountForWidget('sqns'));
        $prepared = $user->resolveAmoAccountForWidget('sqns', true);

        $this->assertSame($sqns->id, $prepared->id);
        $this->assertFalse($prepared->active);
        $this->assertNull($prepared->access_token);
        $this->assertNull($prepared->refresh_token);
        $this->assertSame($before, $sqns->fresh()->getAttributes());
    }

    public function test_sqns_cannot_be_selected_as_a_shared_connection(): void
    {
        $this->account('sqns', 'sqns-client', ['client_secret' => 'sqns-secret']);
        $user = User::findOrFail(1);

        $this->assertNull($user->resolveAmoAccountForWidget('tilda'));
        $method = new \ReflectionMethod(new AuthController, 'resolveSharedSourceAccount');
        $this->assertNull($method->invoke(new AuthController, $user));
    }

    public function test_panel_and_callback_use_only_sqns_widget_credentials(): void
    {
        $account = $this->account('sqns', 'platform-client', ['client_secret' => 'platform-secret']);

        $this->assertSame('sqns-client', $this->page()->clientId($account));
        $this->assertSame([
            'client_secret' => 'sqns-secret',
            'redirect_uri' => 'https://platform.example/api/amocrm/install/sqns',
        ], $this->callbackConfig($account));

        config([
            'services.amocrm.widgets.sqns.client_id' => null,
            'services.amocrm.widgets.sqns.client_secret' => null,
            'services.amocrm.widgets.sqns.redirect_uri' => null,
        ]);

        $this->assertSame('', $this->page()->clientId($account));
        $this->assertSame(['client_secret' => '', 'redirect_uri' => ''], $this->callbackConfig($account));
    }

    public static function credentials(): array
    {
        return ['client ID' => ['client_id'], 'client secret' => ['client_secret'], 'redirect URI' => ['redirect_uri']];
    }

    #[DataProvider('credentials')]
    public function test_public_installer_never_falls_back_to_platform_credentials(string $key): void
    {
        config([
            'services.amocrm.widgets.sqns.'.$key => ' ',
            'services.amocrm.widgets.sqns.fallback_to_platform_credentials' => true,
        ]);

        try {
            app(AmoCrmWidgetInstallationService::class)->install('test-code', 'client.amocrm.ru', 'sqns');
            $this->fail('SQNS widget codes require dedicated credentials.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('amoCRM sqns '.$key.' is not configured.', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, Account::count());
    }

    private function account(string $widget, string $clientId, array $extra = []): Account
    {
        $account = (new Account)->forceFill(array_merge([
            'user_id' => 1,
            'widget' => $widget,
            'subdomain' => 'client',
            'zone' => 'ru',
            'client_id' => $clientId,
            'client_secret' => 'stored-secret',
            'active' => true,
            'access_token' => 'test-access',
            'refresh_token' => 'test-refresh',
            'redirect_uri' => 'https://stored.example/callback',
        ], $extra));
        $account->save();

        return $account->fresh();
    }

    private function callbackConfig(Account $account): array
    {
        return (new \ReflectionMethod(new AuthController, 'resolveOauthConfigForWidget'))
            ->invoke(new AuthController, 'sqns', User::findOrFail(1), $account);
    }

    private function page(): object
    {
        return new class
        {
            use SyncAmoCRMPage;

            public function clientId(Account $account): string
            {
                return $this->resolveOauthClientId('sqns', $account);
            }
        };
    }
}
