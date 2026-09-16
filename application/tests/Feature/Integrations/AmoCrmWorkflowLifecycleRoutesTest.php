<?php

namespace Tests\Feature\Integrations;

use App\Http\Controllers\Api\AuthController;
use App\Jobs\Integrations\CompleteAmoCrmWidgetInstallation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AmoCrmWorkflowLifecycleRoutesTest extends TestCase
{
    public function test_flow_lifecycle_routes_use_logging_handlers(): void
    {
        $routes = app('router')->getRoutes();
        $install = $routes->getByName('amocrm.flow.install');
        $off = $routes->getByName('amocrm.flow.off');

        $this->assertNotNull($install);
        $this->assertSame('api/amocrm/install/flow', $install->uri());
        $this->assertSame(AuthController::class.'@installFlow', $install->getActionName());
        $this->assertContains('GET', $install->methods());
        $this->assertContains('POST', $install->methods());

        $this->assertNotNull($off);
        $this->assertSame('api/amocrm/off/flow', $off->uri());
        $this->assertSame(AuthController::class.'@offFlow', $off->getActionName());
        $this->assertContains('GET', $off->methods());
        $this->assertContains('POST', $off->methods());
    }

    public function test_install_hook_logs_the_payload_without_exposing_the_authorization_code(): void
    {
        Log::spy();
        Queue::fake();

        $response = (new AuthController)->installFlow(Request::create(
            '/api/amocrm/install/flow',
            'POST',
            [
                'code' => 'one-time-code',
                'referer' => 'https://widgetscenario.amocrm.ru',
                'from_widget' => 'Y',
                'account' => ['id' => 33098322],
            ],
        ));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(['ok' => true, 'status' => 'queued'], $response->getData(true));
        Queue::assertPushed(CompleteAmoCrmWidgetInstallation::class, function ($job): bool {
            return Crypt::decryptString($job->encryptedAuthorizationCode) === 'one-time-code'
                && $job->referer === 'https://widgetscenario.amocrm.ru'
                && $job->widget === 'workflows';
        });
        Log::shouldHaveReceived('info')->once()->with(
            'amocrm.flow.install received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.code') === '[received]'
                && data_get($context, 'payload.account.id') === 33098322
                && $context['code_received'] === true
                && $context['code_sha256'] === hash('sha256', 'one-time-code')
                && $context['referer'] === 'https://widgetscenario.amocrm.ru'
            ),
        );
    }

    public function test_install_hook_rejects_incomplete_oauth_payload(): void
    {
        Log::spy();
        Queue::fake();

        $response = (new AuthController)->installFlow(Request::create(
            '/api/amocrm/install/flow',
            'POST',
            ['referer' => 'https://widgetscenario.amocrm.ru'],
        ));

        $this->assertSame(422, $response->getStatusCode());
        Queue::assertNothingPushed();
    }

    public function test_off_hook_only_logs_and_does_not_call_the_disconnect_handler(): void
    {
        Log::spy();

        $controller = new class extends AuthController
        {
            public bool $disconnectCalled = false;

            public function off(Request $request, ?string $forcedWidget = null)
            {
                $this->disconnectCalled = true;

                return response()->json(['ok' => false]);
            }
        };

        $response = $controller->offFlow(Request::create('/api/amocrm/off/flow', 'POST', [
            'account' => [
                'id' => 33098322,
                'subdomain' => 'widgetscenario',
            ],
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($controller->disconnectCalled);
        Log::shouldHaveReceived('info')->once()->with(
            'amocrm.flow.off received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.account.id') === 33098322
                && data_get($context, 'payload.account.subdomain') === 'widgetscenario'
            ),
        );
    }

    public function test_workflow_oauth_uses_the_flow_callback_by_default(): void
    {
        $this->assertSame(
            rtrim((string) config('app.url'), '/').'/api/amocrm/install/flow',
            config('services.amocrm.widgets.workflows.redirect_uri'),
        );
    }
}
