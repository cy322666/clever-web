<?php

namespace Tests\Feature\Integrations;

use App\Http\Controllers\Api\AuthController;
use App\Jobs\Integrations\CompleteAmoCrmWidgetInstallation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AmoCrmSqnsLifecycleRoutesTest extends TestCase
{
    public function test_sqns_lifecycle_routes_use_sqns_handlers(): void
    {
        $routes = app('router')->getRoutes();
        $install = $routes->getByName('amocrm.sqns.install');
        $off = $routes->getByName('amocrm.sqns.off');

        $this->assertNotNull($install);
        $this->assertSame('api/amocrm/install/sqns', $install->uri());
        $this->assertSame(AuthController::class.'@installSqns', $install->getActionName());
        $this->assertContains('GET', $install->methods());
        $this->assertContains('POST', $install->methods());

        $this->assertNotNull($off);
        $this->assertSame('api/amocrm/off/sqns', $off->uri());
        $this->assertSame(AuthController::class.'@offSqns', $off->getActionName());
        $this->assertContains('GET', $off->methods());
        $this->assertContains('POST', $off->methods());
    }

    public function test_install_hook_queues_sqns_widget_installation(): void
    {
        Log::spy();
        Queue::fake();

        $response = (new AuthController)->installSqns(Request::create(
            '/api/amocrm/install/sqns',
            'POST',
            [
                'code' => 'one-time-code',
                'referer' => 'https://widgetscenario.amocrm.ru',
                'account' => ['id' => 33098322],
            ],
        ));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(['ok' => true, 'status' => 'queued'], $response->getData(true));
        Queue::assertPushed(CompleteAmoCrmWidgetInstallation::class, function ($job): bool {
            return Crypt::decryptString($job->encryptedAuthorizationCode) === 'one-time-code'
                && $job->referer === 'https://widgetscenario.amocrm.ru'
                && $job->widget === 'sqns';
        });
        Log::shouldHaveReceived('info')->once()->with(
            'amocrm.sqns.install received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.code') === '[received]'
                && data_get($context, 'payload.account.id') === 33098322
                && $context['code_received'] === true
                && $context['code_sha256'] === hash('sha256', 'one-time-code')
            ),
        );
    }

    public function test_install_hook_rejects_incomplete_oauth_payload(): void
    {
        Log::spy();
        Queue::fake();

        $response = (new AuthController)->installSqns(Request::create(
            '/api/amocrm/install/sqns',
            'POST',
            ['referer' => 'https://widgetscenario.amocrm.ru'],
        ));

        $this->assertSame(422, $response->getStatusCode());
        Queue::assertNothingPushed();
    }

    public function test_off_hook_disconnects_only_the_sqns_widget(): void
    {
        Log::spy();

        $controller = new class extends AuthController
        {
            public ?string $forcedWidget = null;

            public function off(Request $request, ?string $forcedWidget = null)
            {
                $this->forcedWidget = $forcedWidget;

                return response()->json(['ok' => true, 'updated' => 1]);
            }
        };

        $response = $controller->offSqns(Request::create('/api/amocrm/off/sqns', 'POST', [
            'account' => [
                'id' => 33098322,
                'subdomain' => 'widgetscenario',
            ],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('sqns', $controller->forcedWidget);
        Log::shouldHaveReceived('info')->once()->with(
            'amocrm.sqns.off received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.account.id') === 33098322
                && data_get($context, 'payload.account.subdomain') === 'widgetscenario'
            ),
        );
    }

    public function test_off_hook_keeps_other_widget_accounts_connected(): void
    {
        config([
            'database.default' => 'sqns_off_test',
            'database.connections.sqns_off_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]);
        DB::purge('sqns_off_test');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('widget');
            $table->string('subdomain')->nullable();
            $table->text('code')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('client_id')->nullable();
            $table->boolean('active')->default(false);
        });

        $userId = DB::table('users')->insertGetId([
            'name' => 'SQNS owner',
            'email' => null,
        ]);
        DB::table('accounts')->insert([
            [
                'user_id' => $userId,
                'widget' => 'sqns',
                'subdomain' => 'widgetscenario',
                'access_token' => 'sqns-access',
                'refresh_token' => 'sqns-refresh',
                'active' => true,
            ],
            [
                'user_id' => $userId,
                'widget' => 'workflows',
                'subdomain' => 'widgetscenario',
                'access_token' => 'flow-access',
                'refresh_token' => 'flow-refresh',
                'active' => true,
            ],
        ]);
        Mail::fake();

        $response = (new AuthController)->offSqns(Request::create(
            '/api/amocrm/off/sqns',
            'POST',
            ['account' => ['subdomain' => 'widgetscenario']],
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $response->getData(true)['updated']);
        $this->assertDatabaseHas('accounts', [
            'widget' => 'sqns',
            'subdomain' => null,
            'access_token' => null,
            'refresh_token' => null,
            'active' => false,
        ]);
        $this->assertDatabaseHas('accounts', [
            'widget' => 'workflows',
            'subdomain' => 'widgetscenario',
            'access_token' => 'flow-access',
            'refresh_token' => 'flow-refresh',
            'active' => true,
        ]);
    }

    public function test_sqns_oauth_uses_the_sqns_callback_by_default(): void
    {
        $this->assertSame(
            rtrim((string) config('app.url'), '/').'/api/amocrm/install/sqns',
            config('services.amocrm.widgets.sqns.redirect_uri'),
        );
    }
}
