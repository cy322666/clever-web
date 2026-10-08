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

class AmoCrmTildaLifecycleRoutesTest extends TestCase
{
    public function test_tilda_lifecycle_routes_use_tilda_handlers(): void
    {
        $routes = app('router')->getRoutes();
        $install = $routes->getByName('amocrm.tilda.install');
        $off = $routes->getByName('amocrm.tilda.off');

        $this->assertNotNull($install);
        $this->assertSame('api/amocrm/install/tilda', $install->uri());
        $this->assertSame(AuthController::class.'@installTilda', $install->getActionName());
        $this->assertContains('GET', $install->methods());
        $this->assertContains('POST', $install->methods());

        $this->assertNotNull($off);
        $this->assertSame('api/amocrm/off/tilda', $off->uri());
        $this->assertSame(AuthController::class.'@offTilda', $off->getActionName());
        $this->assertContains('GET', $off->methods());
        $this->assertContains('POST', $off->methods());
    }

    public function test_install_hook_queues_tilda_widget_installation(): void
    {
        Log::spy();
        Queue::fake();

        $response = (new AuthController)->installTilda(Request::create(
            '/api/amocrm/install/tilda',
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
                && $job->widget === 'tilda';
        });
        Log::shouldHaveReceived('info')->once()->with(
            'amocrm.tilda.install received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.code') === '[received]'
                && data_get($context, 'payload.account.id') === 33098322
                && $context['code_received'] === true
                && $context['code_sha256'] === hash('sha256', 'one-time-code')
            ),
        );
    }

    public function test_off_hook_uses_common_widget_off_logic(): void
    {
        Log::spy();

        $request = Request::create(
            '/api/amocrm/off/tilda',
            'POST',
            ['account' => ['id' => 33098322, 'subdomain' => 'widgetscenario']],
        );
        $controller = Mockery::mock(AuthController::class)->makePartial();
        $controller->shouldReceive('off')
            ->once()
            ->with($request, 'tilda')
            ->andReturn(response()->json(['ok' => true, 'updated' => 1]));

        $response = $controller->offTilda($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true, 'updated' => 1], $response->getData(true));
        Log::shouldHaveReceived('info')->once()->with(
            'amocrm.tilda.off received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.account.id') === 33098322
                && data_get($context, 'payload.account.subdomain') === 'widgetscenario'
            ),
        );
    }

    public function test_tilda_oauth_uses_tilda_callback_by_default(): void
    {
        $this->assertSame(
            rtrim((string) config('app.url'), '/').'/api/amocrm/install/tilda',
            config('services.amocrm.widgets.tilda.redirect_uri'),
        );
    }
}
