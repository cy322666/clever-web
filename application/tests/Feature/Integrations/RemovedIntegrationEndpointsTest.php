<?php

namespace Tests\Feature\Integrations;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\System\IntegrationOpenController;
use App\Models\App;
use App\Models\User;
use App\Services\Core\PlatformTechnicalMonitor;
use App\Services\Integrations\IntegrationProvisioningService;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class RemovedIntegrationEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'queue.default' => 'sync',
        ]);
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
    }

    public function test_retired_webhooks_and_filament_pages_are_absent_from_the_loaded_routes(): void
    {
        $routes = app('router')->getRoutes();
        $retiredRoutes = collect($routes->getRoutes())
            ->filter(fn ($route): bool => preg_match(
                '/alfacrm|calculator/i',
                $route->uri().' '.$route->getName(),
            ) === 1)
            ->map(fn ($route): string => $route->uri().' '.$route->getName())
            ->values()
            ->all();

        $this->assertSame([], $retiredRoutes);

        foreach ([
            'tilda.hook',
            'getcourse.order',
            'distribution.hook',
            'yclients.hook',
            'sqns.hook',
            'vetmanager.hook',
            'workflows.webhook',
        ] as $routeName) {
            $this->assertNotNull($routes->getByName($routeName), $routeName);
        }
    }

    public function test_catalog_and_panel_resources_exclude_retired_integrations_and_keep_supported_ones(): void
    {
        $definitions = App::definitionNames();
        $resources = Filament::getPanel('app')->getResources();

        foreach (['alfacrm', 'calculator'] as $name) {
            $this->assertArrayNotHasKey($name, config('integrations.definitions'));
            $this->assertNotContains($name, $definitions);
        }

        $this->assertSame([], array_values(array_filter(
            $resources,
            fn (string $resource): bool => preg_match('/\\\\Alfa(?:\\\\|Resource)|Calculator/i', $resource) === 1,
        )));

        foreach (['tilda', 'getcourse', 'distribution', 'yclients', 'sqns', 'vetmanager', 'workflows'] as $name) {
            $this->assertContains($name, $definitions, $name);
            $this->assertContains(config("integrations.definitions.{$name}.resource"), $resources, $name);
        }
    }

    public function test_workflow_calculator_cannot_be_resolved_but_field_updates_remain_available(): void
    {
        $registry = app(ActionRegistry::class);

        $this->assertFalse($registry->has('amocrm_calculate_field'));
        $this->assertTrue($registry->has('amocrm_update_lead_fields'));
        $this->assertTrue($registry->has('amocrm_update_contact_fields'));
        $this->assertTrue($registry->has('amocrm_update_company_fields'));

        $this->expectException(InvalidArgumentException::class);
        $registry->resolve('amocrm_calculate_field');
    }

    public function test_existing_retired_app_records_cannot_be_opened_or_provisioned(): void
    {
        $user = (new User)->forceFill(['id' => 42, 'is_root' => false]);
        Auth::shouldReceive('user')->twice()->andReturn($user);
        $provisioning = $this->createMock(IntegrationProvisioningService::class);
        $provisioning->expects($this->never())->method('ensureSettingForApp');

        foreach (['alfacrm', 'calculator'] as $name) {
            $app = (new App)->forceFill(['user_id' => 42, 'name' => $name]);

            try {
                (new IntegrationOpenController)($app, $provisioning);
                $this->fail("Retired integration {$name} was opened.");
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(404, $exception->getStatusCode(), $name);
                $this->assertSame('Integration is not supported.', $exception->getMessage(), $name);
            }
        }
    }

    public function test_retired_oauth_callbacks_are_rejected_before_creating_accounts_or_exchanging_tokens(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('widget');
        });
        $uuid = 'f8ef49da-8954-4e24-bb30-7de8338c8dc9';
        DB::table('users')->insert(['id' => 42, 'uuid' => $uuid]);

        Http::fake();
        Mail::fake();
        Bus::fake();
        Notification::fake();
        $monitor = $this->createMock(PlatformTechnicalMonitor::class);
        $monitor->expects($this->exactly(2))
            ->method('amoConnectionFailed')
            ->with(
                $this->callback(fn (User $user): bool => $user->id === 42),
                $this->callback(fn (string $widget): bool => in_array($widget, ['alfacrm', 'calculator'], true)),
                'Интеграция больше не поддерживается.',
            );
        $this->app->instance(PlatformTechnicalMonitor::class, $monitor);

        foreach (['alfacrm', 'calculator'] as $widget) {
            $state = base64_encode(json_encode(['user_uuid' => $uuid, 'widget' => $widget], JSON_THROW_ON_ERROR));
            $response = (new AuthController)->redirect(Request::create('/api/amocrm/redirect', 'GET', [
                'state' => $state,
                'code' => 'retired-oauth-code',
                'uri' => '/panel',
            ]));
            parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);

            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame('error', $query['amocrm_auth']);
            $this->assertSame('404', $query['amocrm_auth_status']);
            $this->assertSame('Интеграция больше не поддерживается.', $query['amocrm_auth_message']);
        }

        $this->assertSame(0, DB::table('accounts')->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
        Bus::assertNothingDispatched();
    }
}
