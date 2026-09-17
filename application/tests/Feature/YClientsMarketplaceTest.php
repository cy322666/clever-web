<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\Integrations\YClients\MarketplaceInstallation;
use App\Models\Integrations\YClients\Setting;
use App\Models\User;
use App\Services\Billing\WidgetSubscriptionAccessService;
use App\Services\Integrations\IntegrationProvisioningService;
use App\Services\YClients\YClientsMarketplaceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class YClientsMarketplaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'http://localhost',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'services.yclients.marketplace_activation_url' => 'https://app.alteg.io/marketplace/partner/callback',
            'services.yclients.marketplace_application_id' => '123',
            'services.yclients.marketplace_partner_token' => 'partner-secret',
            'services.yclients.marketplace_user_token' => 'system-user-secret',
            'services.yclients.marketplace_api_key' => null,
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
    }

    public function test_registration_redirect_keeps_salon_context_until_login_or_registration(): void
    {
        $response = $this->get(route('yclients.marketplace.register', ['salon_id' => 4564]));

        $response->assertRedirect(url('/panel/register'));
        $this->assertSame(4564, session(YClientsMarketplaceService::SESSION_KEY.'.salon_id'));
        $this->assertSame([], session(YClientsMarketplaceService::SESSION_KEY.'.user_data'));
    }

    public function test_marketplace_callback_rejects_an_invalid_partner_token(): void
    {
        $response = $this->postJson(route('yclients.marketplace.callback'), [
            'salon_id' => 4564,
            'application_id' => 123,
            'event' => 'uninstall',
            'partner_token' => 'wrong-token',
        ]);

        $response->assertUnauthorized();
    }

    public function test_uninstall_callback_marks_installation_and_deactivates_local_widget(): void
    {
        $this->createMarketplaceTables();

        DB::table('users')->insert(['id' => 7, 'uuid' => 'user-7']);
        DB::table('apps')->insert([
            'id' => 20,
            'user_id' => 7,
            'name' => 'yclients',
            'status' => App::STATE_ACTIVE,
        ]);
        DB::table('yclients_settings')->insert([
            'id' => 30,
            'user_id' => 7,
            'active' => true,
        ]);
        DB::table('yclients_marketplace_installations')->insert([
            'id' => 40,
            'user_id' => 7,
            'setting_id' => 30,
            'salon_id' => 4564,
            'application_id' => 123,
            'status' => MarketplaceInstallation::STATUS_ACTIVE,
            'connected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson(route('yclients.marketplace.callback'), [
            'salon_id' => 4564,
            'application_id' => 123,
            'event' => 'uninstall',
            'partner_token' => 'partner-secret',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('yclients_marketplace_installations', [
            'id' => 40,
            'status' => MarketplaceInstallation::STATUS_UNINSTALLED,
        ]);
        $this->assertDatabaseHas('yclients_settings', [
            'id' => 30,
            'active' => 0,
        ]);
        $this->assertDatabaseHas('apps', [
            'id' => 20,
            'status' => App::STATE_INACTIVE,
        ]);

        $payload = json_decode(
            (string)DB::table('yclients_marketplace_installations')->where('id', 40)->value('last_payload'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame('[redacted]', $payload['partner_token']);
    }

    public function test_activation_confirms_marketplace_installation_with_the_supported_payload(): void
    {
        $this->createMarketplaceTables();

        DB::table('users')->insert([
            'id' => 7,
            'uuid' => 'user-7',
            'email' => 'user@example.com',
        ]);
        DB::table('apps')->insert([
            'id' => 20,
            'user_id' => 7,
            'setting_id' => 30,
            'name' => 'yclients',
            'status' => App::STATE_CREATED,
        ]);
        DB::table('yclients_settings')->insert([
            'id' => 30,
            'user_id' => 7,
            'active' => false,
        ]);

        $user = User::query()->findOrFail(7);
        $app = App::query()->findOrFail(20);

        $provisioning = $this->createMock(IntegrationProvisioningService::class);
        $provisioning->expects($this->once())
            ->method('syncCatalogForUser')
            ->with($this->callback(fn (User $value): bool => $value->is($user)));
        $provisioning->expects($this->once())
            ->method('ensureSettingForApp')
            ->with($this->callback(fn (App $value): bool => $value->is($app)))
            ->willReturn($app);
        $this->app->instance(IntegrationProvisioningService::class, $provisioning);

        $access = $this->createMock(WidgetSubscriptionAccessService::class);
        $access->expects($this->exactly(2))
            ->method('canUse')
            ->with($this->callback(fn (User $value): bool => $value->is($user)), 'yclients')
            ->willReturnOnConsecutiveCalls(false, true);
        $access->expects($this->once())
            ->method('ensureTrialForWidget')
            ->with($this->callback(fn (User $value): bool => $value->is($user)), 'yclients', 7);
        $this->app->instance(WidgetSubscriptionAccessService::class, $access);

        Http::fake([
            'https://app.alteg.io/*' => Http::response([], 201),
        ]);

        $installation = app(YClientsMarketplaceService::class)->activateForUser($user, [
            'salon_id' => 4564,
            'application_id' => 123,
        ]);

        $this->assertSame(4564, (int)$installation->salon_id);
        $this->assertSame(MarketplaceInstallation::STATUS_ACTIVE, $installation->status);
        $this->assertDatabaseHas('yclients_settings', [
            'id' => 30,
            'partner_token' => 'partner-secret',
            'user_token' => 'system-user-secret',
            'active' => 1,
        ]);

        Http::assertSent(function (HttpRequest $request): bool {
            return $request->url() === 'https://app.alteg.io/marketplace/partner/callback'
                && $request->data() === [
                    'salon_id' => 4564,
                    'application_id' => 123,
                ];
        });
    }

    private function createMarketplaceTables(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        Schema::create('apps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('setting_id')->nullable();
            $table->string('name');
            $table->string('resource_name')->nullable();
            $table->unsignedTinyInteger('status')->default(App::STATE_CREATED);
            $table->date('expires_tariff_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('yclients_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->boolean('active')->default(false);
            $table->text('partner_token')->nullable();
            $table->text('user_token')->nullable();
            $table->timestamps();
        });

        Schema::create('yclients_marketplace_installations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('setting_id')->nullable();
            $table->unsignedBigInteger('salon_id');
            $table->unsignedBigInteger('application_id');
            $table->string('status', 32)->default(MarketplaceInstallation::STATUS_ACTIVE);
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->json('last_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }
}
