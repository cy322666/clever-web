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

class AmoCrmExcelLifecycleRoutesTest extends TestCase
{
    public function test_excel_lifecycle_routes_use_excel_handlers(): void
    {
        $routes = app('router')->getRoutes();
        $install = $routes->getByName('amocrm.excel.install');
        $off = $routes->getByName('amocrm.excel.off');

        $this->assertNotNull($install);
        $this->assertSame('api/amocrm/install/excel', $install->uri());
        $this->assertSame(AuthController::class.'@installExcel', $install->getActionName());
        $this->assertContains('GET', $install->methods());
        $this->assertContains('POST', $install->methods());

        $this->assertNotNull($off);
        $this->assertSame('api/amocrm/off/excel', $off->uri());
        $this->assertSame(AuthController::class.'@offExcel', $off->getActionName());
        $this->assertContains('GET', $off->methods());
        $this->assertContains('POST', $off->methods());
    }

    public function test_install_hook_queues_excel_widget_installation(): void
    {
        Log::spy();
        Queue::fake();

        $response = (new AuthController)->installExcel(Request::create(
            '/api/amocrm/install/excel',
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
                && $job->widget === 'import-excel';
        });
        Log::shouldHaveReceived('info')->once()->with(
            'amocrm.excel.install received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.code') === '[received]'
                && data_get($context, 'payload.account.id') === 33098322
                && $context['code_received'] === true
                && $context['code_sha256'] === hash('sha256', 'one-time-code')
            ),
        );
    }

    public function test_off_hook_logs_excel_callback(): void
    {
        Log::spy();

        $response = (new AuthController)->offExcel(Request::create(
            '/api/amocrm/off/excel',
            'POST',
            ['account' => ['id' => 33098322, 'subdomain' => 'widgetscenario']],
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true], $response->getData(true));
        Log::shouldHaveReceived('info')->once()->with(
            'amocrm.excel.off received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.account.id') === 33098322
                && data_get($context, 'payload.account.subdomain') === 'widgetscenario'
            ),
        );
    }

    public function test_excel_oauth_uses_excel_callback_by_default(): void
    {
        $this->assertSame(
            rtrim((string) config('app.url'), '/').'/api/amocrm/install/excel',
            config('services.amocrm.widgets.import-excel.redirect_uri'),
        );
    }
}
