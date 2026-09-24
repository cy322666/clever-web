<?php

namespace Tests\Unit\Integrations;

use App\Models\Core\Account;
use App\Models\User;
use App\Services\Billing\WidgetSubscriptionAccessService;
use App\Services\Integrations\AmoCrmWidgetInstallationService;
use App\Services\Integrations\IntegrationProvisioningService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AmoCrmWidgetInstallationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'amo_install_test',
            'database.connections.amo_install_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'services.amocrm.widgets.workflows.client_id' => 'workflow-client-id',
            'services.amocrm.widgets.workflows.client_secret' => 'workflow-client-secret',
            'services.amocrm.widgets.workflows.redirect_uri' => 'https://platform.example/api/amocrm/install/flow',
        ]);
        DB::purge('amo_install_test');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('widget')->default(Account::DEFAULT_WIDGET);
            $table->unsignedBigInteger('amo_account_id')->nullable();
            $table->string('subdomain')->nullable();
            $table->string('zone')->nullable();
            $table->text('code')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('client_id')->nullable();
            $table->string('client_secret')->nullable();
            $table->boolean('active')->default(false);
            $table->string('redirect_uri')->nullable();
            $table->integer('expires_in')->nullable();
            $table->integer('created_at')->nullable();
            $table->unique(['user_id', 'widget']);
            $table->unique(['amo_account_id', 'widget']);
        });
        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('resource_name')->nullable();
            $table->unsignedBigInteger('setting_id')->nullable();
            $table->integer('status')->default(0);
            $table->date('expires_tariff_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_install_creates_and_then_reuses_the_same_platform_account(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://widgetscenario.amocrm.ru/oauth2/access_token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 86400,
                'created_at' => 1789570000,
            ]),
            'https://widgetscenario.amocrm.ru/api/v4/account' => Http::response([
                'id' => 33098322,
                'name' => 'Widget Scenario',
                'current_user_id' => 778899,
            ]),
            'https://widgetscenario.amocrm.ru/api/v4/users/778899' => Http::response([
                'id' => 778899,
                'name' => 'Installer',
                'email' => 'Installer@Example.com',
            ]),
        ]);

        $provisioning = Mockery::mock(IntegrationProvisioningService::class);
        $provisioning->shouldReceive('syncCatalogForUser')->twice();
        $this->app->instance(IntegrationProvisioningService::class, $provisioning);

        $subscriptions = Mockery::mock(WidgetSubscriptionAccessService::class);
        $subscriptions->shouldReceive('ensureTrialForWidget')
            ->twice()
            ->with(Mockery::type(\App\Models\User::class), 'workflows', 7)
            ->andReturnNull();
        $this->app->instance(WidgetSubscriptionAccessService::class, $subscriptions);

        Password::shouldReceive('sendResetLink')
            ->once()
            ->with(['email' => 'installer@example.com'])
            ->andReturn(Password::RESET_LINK_SENT);
        Artisan::shouldReceive('call')->twice()->andReturn(0);

        $service = app(AmoCrmWidgetInstallationService::class);
        $first = $service->install('first-code', 'https://widgetscenario.amocrm.ru', 'workflows');
        $second = $service->install('second-code', 'widgetscenario.amocrm.ru', 'workflows');

        $this->assertTrue($first['created_user']);
        $this->assertFalse($second['created_user']);
        $this->assertSame($first['user']->id, $second['user']->id);
        $this->assertSame($first['account']->id, $second['account']->id);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(2, DB::table('accounts')->count());

        $account = Account::query()->where('widget', 'workflows')->firstOrFail();
        $this->assertSame(33098322, $account->amo_account_id);
        $this->assertSame('widgetscenario', $account->subdomain);
        $this->assertSame('ru', $account->zone);
        $this->assertSame('access-token', $account->access_token);
        $this->assertSame('refresh-token', $account->refresh_token);
        $this->assertNull($account->code);
        $this->assertTrue($account->active);
        Http::assertSentCount(6);
    }

    public function test_email_failure_does_not_turn_a_completed_installation_into_a_failed_job(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://widgetscenario.amocrm.ru/oauth2/access_token' => Http::response([
                'access_token' => 'access-token', 'refresh_token' => 'refresh-token',
            ]),
            'https://widgetscenario.amocrm.ru/api/v4/account' => Http::response([
                'id' => 33098322, 'current_user_id' => 778899,
            ]),
            'https://widgetscenario.amocrm.ru/api/v4/users/778899' => Http::response([
                'id' => 778899, 'email' => 'installer@example.com',
            ]),
        ]);
        $this->mock(IntegrationProvisioningService::class)->shouldReceive('syncCatalogForUser')->once();
        $this->mock(WidgetSubscriptionAccessService::class)->shouldReceive('ensureTrialForWidget')->once()->andReturnNull();
        Password::shouldReceive('sendResetLink')->once()->andThrow(new \RuntimeException('Mail transport unavailable'));
        Artisan::shouldReceive('call')->once()->andReturn(0);

        $result = app(AmoCrmWidgetInstallationService::class)->install('test-code', 'widgetscenario.amocrm.ru', 'workflows');
        $this->assertTrue($result['created_user']);
        $this->assertTrue($result['account']->active);
        $this->assertSame('access-token', $result['account']->access_token);
    }

    public function test_install_reuses_the_platform_owner_of_an_existing_amo_account(): void
    {
        $owner = User::withoutEvents(function (): User {
            $user = new User;
            $user->forceFill([
                'uuid' => '79ecdf68-dc06-4ea9-b340-cbb61c03e04e',
                'name' => 'Existing owner',
                'email' => 'owner@example.com',
                'password' => bcrypt('password'),
                'active' => true,
            ])->save();

            return $user;
        });
        (new Account)->forceFill([
            'user_id' => $owner->id,
            'widget' => 'tilda',
            'subdomain' => 'widgetscenario',
            'zone' => 'ru',
            'active' => true,
            'access_token' => 'old-access-token',
        ])->save();

        Http::preventStrayRequests();
        Http::fake([
            'https://widgetscenario.amocrm.ru/oauth2/access_token' => Http::response([
                'access_token' => 'access-token',
                'refresh_token' => 'refresh-token',
                'expires_in' => 86400,
                'created_at' => 1789570000,
            ]),
            'https://widgetscenario.amocrm.ru/api/v4/account' => Http::response([
                'id' => 33098322,
                'name' => 'Widget Scenario',
                'current_user_id' => 778899,
            ]),
            'https://widgetscenario.amocrm.ru/api/v4/users/778899' => Http::response([
                'id' => 778899,
                'name' => 'Another administrator',
                'email' => 'another-admin@example.com',
            ]),
        ]);

        $provisioning = Mockery::mock(IntegrationProvisioningService::class);
        $provisioning->shouldReceive('syncCatalogForUser')->once();
        $this->app->instance(IntegrationProvisioningService::class, $provisioning);

        $subscriptions = Mockery::mock(WidgetSubscriptionAccessService::class);
        $subscriptions->shouldReceive('ensureTrialForWidget')->once()->andReturnNull();
        $this->app->instance(WidgetSubscriptionAccessService::class, $subscriptions);

        Password::shouldReceive('sendResetLink')->never();
        Artisan::shouldReceive('call')->once()->andReturn(0);

        $result = app(AmoCrmWidgetInstallationService::class)
            ->install('one-time-code', 'widgetscenario.amocrm.ru', 'workflows');

        $this->assertFalse($result['created_user']);
        $this->assertSame($owner->id, $result['user']->id);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(2, DB::table('accounts')->count());
        $this->assertDatabaseHas('accounts', [
            'user_id' => $owner->id,
            'widget' => 'workflows',
            'amo_account_id' => 33098322,
        ]);
    }

    public function test_finder_install_uses_its_own_keys_and_preserves_the_shared_connection(): void
    {
        config([
            'services.amocrm.widgets.finder.client_id' => 'finder-client',
            'services.amocrm.widgets.finder.client_secret' => 'finder-secret',
            'services.amocrm.client_id' => 'platform-client',
            'services.amocrm.client_secret' => 'platform-secret',
        ]);
        $owner = User::withoutEvents(fn () => User::query()->create([
            'name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('password'),
        ]));
        $shared = (new Account)->forceFill([
            'user_id' => $owner->id,
            'widget' => Account::DEFAULT_WIDGET,
            'amo_account_id' => 33098322,
            'subdomain' => 'widgetscenario',
            'zone' => 'ru',
            'active' => true,
            'access_token' => 'platform-access',
            'refresh_token' => 'platform-refresh',
        ]);
        $shared->save();
        $sharedAttributes = $shared->fresh()->getAttributes();

        Http::preventStrayRequests();
        Http::fake([
            'https://widgetscenario.amocrm.ru/oauth2/access_token' => Http::response([
                'access_token' => 'finder-access', 'refresh_token' => 'finder-refresh',
            ]),
            'https://widgetscenario.amocrm.ru/api/v4/account' => Http::response([
                'id' => 33098322, 'current_user_id' => 778899,
            ]),
            'https://widgetscenario.amocrm.ru/api/v4/users/778899' => Http::response([
                'id' => 778899, 'email' => 'installer@example.com',
            ]),
        ]);
        $this->mock(IntegrationProvisioningService::class)
            ->shouldReceive('syncCatalogForUser')->once();
        $this->mock(WidgetSubscriptionAccessService::class)
            ->shouldReceive('ensureTrialForWidget')->once()
            ->with(Mockery::type(User::class), 'finder', 7)->andReturnNull();
        Password::shouldReceive('sendResetLink')->never();
        Artisan::shouldReceive('call')->once()->andReturn(0);

        $result = app(AmoCrmWidgetInstallationService::class)
            ->install('finder-code', 'widgetscenario.amocrm.ru', 'finder');

        $this->assertFalse($result['created_user']);
        $this->assertSame($owner->id, $result['user']->id);
        $this->assertSame('finder', $result['account']->widget);
        $this->assertSame('finder-access', $result['account']->access_token);
        $this->assertSame($sharedAttributes, $shared->fresh()->getAttributes());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/oauth2/access_token')
            && $request['client_id'] === 'finder-client'
            && $request['client_secret'] === 'finder-secret'
            && $request['redirect_uri'] === config('services.amocrm.widgets.finder.redirect_uri')
            && $request['code'] === 'finder-code');
    }

    public function test_finder_install_does_not_exchange_its_code_using_platform_credentials(): void
    {
        config([
            'services.amocrm.widgets.finder.client_id' => null,
            'services.amocrm.widgets.finder.client_secret' => null,
            'services.amocrm.client_id' => 'platform-client',
            'services.amocrm.client_secret' => 'platform-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake();

        try {
            app(AmoCrmWidgetInstallationService::class)->install('finder-code', 'widgetscenario.amocrm.ru', 'finder');
            $this->fail('Finder installation must require its own credentials.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('amoCRM finder client_id is not configured.', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_finder_platform_install_keeps_the_initiating_owner_and_automatically_queues_hooks(): void
    {
        $owner = $this->prepareFinderPlatformInstall();
        (require database_path('migrations/2026_09_24_130000_create_finder_tables.php'))->up();
        $setting = \App\Models\Integrations\Finder\Setting::create(['user_id' => $owner->id]);
        $app = \App\Models\App::create([
            'user_id' => $owner->id, 'name' => 'finder', 'setting_id' => $setting->id,
            'resource_name' => \App\Filament\Resources\Integrations\Finder\FinderResource::class,
        ]);
        $provisioning = $this->mock(IntegrationProvisioningService::class);
        $provisioning->shouldReceive('syncCatalogForUser')->once();
        $provisioning->shouldReceive('ensureSettingForApp')->once()->andReturn($app);
        $this->mock(WidgetSubscriptionAccessService::class)->shouldReceive('ensureTrialForWidget')->once();
        Password::shouldReceive('sendResetLink')->never();
        Artisan::shouldReceive('call')->once()->andReturn(0);

        $result = app(AmoCrmWidgetInstallationService::class)->install('test-code', 'widgetscenario.amocrm.ru', 'finder', $owner->id);

        $this->assertSame($owner->id, $result['user']->id);
        $this->assertFalse($result['created_user']);
        $this->assertSame(1, User::count());
        $this->assertSame($result['account']->id, $setting->refresh()->account_id);
        Queue::assertPushed(\App\Jobs\Integrations\SynchronizeFinderWebhooks::class, fn ($job) => $job->settingId === $setting->id);
        $connection = $this->mock(\App\Services\Finder\WebhookConnection::class);
        $connection->shouldReceive('connect')->once()->with(Mockery::on(fn ($value) => $value->account_id === $result['account']->id));
        Queue::pushed(\App\Jobs\Integrations\SynchronizeFinderWebhooks::class)->first()->handle($connection);
    }

    public function test_finder_context_cannot_take_an_account_from_another_owner(): void
    {
        $owner = $this->prepareFinderPlatformInstall();
        $other = User::withoutEvents(fn () => User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'test']));
        $existing = (new Account)->forceFill(['user_id' => $other->id, 'widget' => 'finder', 'amo_account_id' => 33098322, 'subdomain' => 'widgetscenario', 'active' => true, 'access_token' => 'unchanged']);
        $existing->save();
        try {
            app(AmoCrmWidgetInstallationService::class)->install('test-code', 'widgetscenario.amocrm.ru', 'finder', $owner->id);
            $this->fail('An existing foreign account must not be reassigned.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('amoCRM account is already linked to another platform user.', $exception->getMessage());
        }
        $this->assertSame($other->id, $existing->fresh()->user_id);
        $this->assertSame('unchanged', $existing->fresh()->access_token);
        Queue::assertNothingPushed();
    }

    private function prepareFinderPlatformInstall(): User
    {
        Queue::fake();
        config(['services.amocrm.widgets.finder.client_id' => 'finder-client', 'services.amocrm.widgets.finder.client_secret' => 'finder-secret']);
        Http::preventStrayRequests();
        Http::fake([
            'https://widgetscenario.amocrm.ru/oauth2/access_token' => Http::response(['access_token' => 'finder-access', 'refresh_token' => 'finder-refresh']),
            'https://widgetscenario.amocrm.ru/api/v4/account' => Http::response(['id' => 33098322, 'current_user_id' => 778899]),
            'https://widgetscenario.amocrm.ru/api/v4/users/778899' => Http::response(['id' => 778899, 'email' => 'different-installer@example.test']),
        ]);

        return User::withoutEvents(fn () => User::create(['name' => 'Platform owner', 'email' => 'platform@example.test', 'password' => 'test']));
    }
}
