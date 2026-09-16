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
}
