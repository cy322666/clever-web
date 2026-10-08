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

class AmoCrmDistributionLifecycleRoutesTest extends TestCase
{
    public function test_distribution_lifecycle_routes_use_distribution_handlers(): void
    {
        $routes = app('router')->getRoutes();
        $install = $routes->getByName('amocrm.distribution.install');
        $off = $routes->getByName('amocrm.distribution.off');

        $this->assertNotNull($install);
        $this->assertSame('api/amocrm/install/distribution', $install->uri());
        $this->assertSame(AuthController::class.'@installDistribution', $install->getActionName());
        $this->assertContains('GET', $install->methods());
        $this->assertContains('POST', $install->methods());

        $this->assertNotNull($off);
        $this->assertSame('api/amocrm/off/distribution', $off->uri());
        $this->assertSame(AuthController::class.'@offDistribution', $off->getActionName());
        $this->assertContains('GET', $off->methods());
        $this->assertContains('POST', $off->methods());
    }

    public function test_install_hook_queues_distribution_widget_installation(): void
    {
        Log::spy();
        Queue::fake();

        $response = (new AuthController)->installDistribution(Request::create(
            '/api/amocrm/install/distribution',
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
                && $job->widget === 'distribution';
        });
        Log::shouldHaveReceived('info')->once()->with(
            'amocrm.distribution.install received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.code') === '[received]'
                && data_get($context, 'payload.account.id') === 33098322
                && $context['code_received'] === true
                && $context['code_sha256'] === hash('sha256', 'one-time-code')
            ),
        );
    }

    public function test_off_hook_logs_distribution_callback(): void
    {
        Log::spy();

        $response = (new AuthController)->offDistribution(Request::create(
            '/api/amocrm/off/distribution',
            'POST',
            ['account' => ['id' => 33098322, 'subdomain' => 'widgetscenario']],
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true], $response->getData(true));
        Log::shouldHaveReceived('info')->once()->with(
            'amocrm.distribution.off received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.account.id') === 33098322
                && data_get($context, 'payload.account.subdomain') === 'widgetscenario'
            ),
        );
    }

    public function test_distribution_oauth_uses_distribution_callback_by_default(): void
    {
        $this->assertSame(
            rtrim((string) config('app.url'), '/').'/api/amocrm/install/distribution',
            config('services.amocrm.widgets.distribution.redirect_uri'),
        );
    }
}
