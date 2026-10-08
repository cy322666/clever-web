<?php

namespace Tests\Feature\Integrations;

use App\Http\Controllers\Api\AuthController;
use App\Jobs\Integrations\CompleteAmoCrmWidgetInstallation;
use App\Models\Core\Account;
use App\Services\Integrations\AmoCrmWidgetLifecycleTelegramNotifier;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AmoCrmFinderLifecycleRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('f', 32))]);
        Http::preventStrayRequests();
        Queue::fake();
        Log::spy();
        $this->mock(AmoCrmWidgetLifecycleTelegramNotifier::class);
    }

    public function test_routes_use_finder_handlers_with_rate_limits(): void
    {
        foreach (['install' => ['installFinder', '30'], 'off' => ['offFinder', '60']] as $event => [$handler, $limit]) {
            $route = app('router')->getRoutes()->getByName('amocrm.finder.'.$event);
            $this->assertNotNull($route);
            $this->assertSame('api/amocrm/'.$event.'/finder', $route->uri());
            $this->assertSame(AuthController::class.'@'.$handler, $route->getActionName());
            $this->assertContains('GET', $route->methods());
            $this->assertContains('POST', $route->methods());
            $this->assertContains('throttle:'.$limit.',1', $route->middleware());
        }
    }

    public function test_get_and_post_install_queue_encrypted_finder_codes(): void
    {
        $this->mock(AmoCrmWidgetLifecycleTelegramNotifier::class)
            ->shouldReceive('notify')->twice()
            ->with('install', 'finder', Mockery::type('array'), Mockery::type('array'));

        foreach (['GET', 'POST'] as $method) {
            $this->json($method, '/api/amocrm/install/finder', [
                'code' => 'one-time-code',
                'referer' => 'https://widgetscenario.amocrm.ru',
            ])->assertStatus(202)->assertExactJson(['ok' => true, 'status' => 'queued']);
        }

        Queue::assertPushed(CompleteAmoCrmWidgetInstallation::class, 2);
        Queue::assertPushed(CompleteAmoCrmWidgetInstallation::class, fn ($job): bool =>
            $job->widget === 'finder'
            && $job->referer === 'https://widgetscenario.amocrm.ru'
            && $job->encryptedAuthorizationCode !== 'one-time-code'
            && Crypt::decryptString($job->encryptedAuthorizationCode) === 'one-time-code'
        );
        Log::shouldHaveReceived('info')->twice()->with(
            'amocrm.finder.install received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.code') === '[received]'),
        );
    }

    public function test_incomplete_install_is_rejected_without_side_effects(): void
    {
        foreach ([[], ['code' => 'code'], ['referer' => 'https://widgetscenario.amocrm.ru']] as $payload) {
            $this->postJson('/api/amocrm/install/finder', $payload)
                ->assertStatus(422)->assertJson(['ok' => false]);
        }

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_off_uses_notification_flow_without_touching_shared_authorization(): void
    {
        DB::shouldReceive('connection')->never();
        $this->mock(AmoCrmWidgetLifecycleTelegramNotifier::class)
            ->shouldReceive('notify')->twice()
            ->with('off', 'finder', Mockery::type('array'), Mockery::type('array'));

        foreach (['GET', 'POST'] as $method) {
            $this->json($method, '/api/amocrm/off/finder', [
                'account_id' => 33098322,
                'signature' => 'callback-signature',
            ])->assertOk()->assertExactJson(['ok' => true]);
        }

        Log::shouldHaveReceived('info')->twice()->with(
            'amocrm.finder.off received',
            Mockery::on(fn (array $context): bool => data_get($context, 'payload.signature') === '[received]'),
        );
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_oauth_callback_is_separate_while_monitoring_uses_shared_account(): void
    {
        $this->assertSame(
            rtrim((string) config('app.url'), '/').'/api/amocrm/install/finder',
            config('services.amocrm.widgets.finder.redirect_uri'),
        );
        $this->assertFalse(config('services.amocrm.widgets.finder.fallback_to_platform_credentials'));
        $this->assertSame(Account::DEFAULT_WIDGET, config('integrations.definitions.finder.amo_widget'));
    }
}
