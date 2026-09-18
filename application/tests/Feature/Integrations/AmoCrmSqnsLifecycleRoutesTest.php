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
    private const SQNS_CLIENT_ID = 'sqns-client-id';

    private const SQNS_CLIENT_SECRET = 'sqns-client-secret';

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
        $this->configureSqnsOauth();

        $controller = new class extends AuthController
        {
            public ?string $forcedWidget = null;

            public function off(Request $request, ?string $forcedWidget = null)
            {
                $this->forcedWidget = $forcedWidget;

                return response()->json(['ok' => true, 'updated' => 1]);
            }
        };

        $response = $controller->offSqns(Request::create(
            '/api/amocrm/off/sqns',
            'GET',
            $this->signedOffPayload(33098322),
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('sqns', $controller->forcedWidget);
        Log::shouldHaveReceived('info')->once()->with(
            'amocrm.sqns.off received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.account_id') === 33098322
                && data_get($context, 'payload.signature') === '[received]'
            ),
        );
    }

    public function test_off_hook_rejects_an_invalid_signature(): void
    {
        Log::spy();
        $this->configureSqnsOauth();

        $controller = new class extends AuthController
        {
            public bool $disconnectCalled = false;

            public function off(Request $request, ?string $forcedWidget = null)
            {
                $this->disconnectCalled = true;

                return response()->json(['ok' => true]);
            }
        };

        $payload = $this->signedOffPayload(33098322);
        $payload['signature'] = 'invalid-signature';
        $response = $controller->offSqns(Request::create('/api/amocrm/off/sqns', 'GET', $payload));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($controller->disconnectCalled);
        Log::shouldHaveReceived('warning')->once()->with(
            'amocrm.sqns.off rejected',
            Mockery::on(fn (array $context): bool => $context['account_id'] === 33098322
                && $context['client_id_matches'] === true
                && $context['signature_received'] === true
            ),
        );
    }

    public function test_off_hook_keeps_other_accounts_and_widgets_connected(): void
    {
        $this->configureSqnsOauth();
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
            $table->unsignedBigInteger('amo_account_id')->nullable();
            $table->string('subdomain')->nullable();
            $table->text('code')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->string('client_id')->nullable();
            $table->boolean('active')->default(false);
        });

        $targetUserId = DB::table('users')->insertGetId([
            'name' => 'SQNS owner',
            'email' => null,
        ]);
        $otherUserId = DB::table('users')->insertGetId([
            'name' => 'Other SQNS owner',
            'email' => null,
        ]);
        DB::table('accounts')->insert([
            [
                'user_id' => $targetUserId,
                'widget' => 'sqns',
                'amo_account_id' => 33098322,
                'subdomain' => 'widgetscenario',
                'access_token' => 'sqns-access',
                'refresh_token' => 'sqns-refresh',
                'client_id' => self::SQNS_CLIENT_ID,
                'active' => true,
            ],
            [
                'user_id' => $targetUserId,
                'widget' => 'workflows',
                'amo_account_id' => 33098322,
                'subdomain' => 'widgetscenario',
                'access_token' => 'flow-access',
                'refresh_token' => 'flow-refresh',
                'client_id' => 'workflow-client-id',
                'active' => true,
            ],
            [
                'user_id' => $otherUserId,
                'widget' => 'sqns',
                'amo_account_id' => 44098322,
                'subdomain' => 'otherscenario',
                'access_token' => 'other-sqns-access',
                'refresh_token' => 'other-sqns-refresh',
                'client_id' => self::SQNS_CLIENT_ID,
                'active' => true,
            ],
        ]);
        Mail::fake();

        $response = (new AuthController)->offSqns(Request::create(
            '/api/amocrm/off/sqns',
            'GET',
            $this->signedOffPayload(33098322),
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
        $this->assertDatabaseHas('accounts', [
            'widget' => 'sqns',
            'amo_account_id' => 44098322,
            'subdomain' => 'otherscenario',
            'access_token' => 'other-sqns-access',
            'refresh_token' => 'other-sqns-refresh',
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

    private function configureSqnsOauth(): void
    {
        config([
            'services.amocrm.widgets.sqns.client_id' => self::SQNS_CLIENT_ID,
            'services.amocrm.widgets.sqns.client_secret' => self::SQNS_CLIENT_SECRET,
        ]);
    }

    /** @return array{client_uuid: string, account_id: int, signature: string} */
    private function signedOffPayload(int $accountId): array
    {
        return [
            'client_uuid' => self::SQNS_CLIENT_ID,
            'account_id' => $accountId,
            'signature' => hash_hmac(
                'sha256',
                self::SQNS_CLIENT_ID.'|'.$accountId,
                self::SQNS_CLIENT_SECRET,
            ),
        ];
    }
}
