<?php

namespace Tests\Feature\Integrations;

use App\Exceptions\WorkflowAmoCrmAccountAlreadyLinked;
use App\Http\Controllers\Api\AuthController;
use App\Jobs\Integrations\CompleteAmoCrmWidgetInstallation;
use App\Models\User;
use App\Services\Integrations\AmoCrmInstallationStatus;
use App\Services\Integrations\AmoCrmWidgetInstallationService;
use App\Services\Workflows\WorkflowAmoCrmInstallationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\FinderDatabase;
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
                && $job->widget === 'workflows'
                && $job->platformUserId === null;
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

    public function test_browser_oauth_reports_owner_conflict_without_redirecting_to_the_other_account(): void
    {
        FinderDatabase::prepare();
        config(['widget_lifecycle.install_status_store' => 'array']);
        Queue::fake();
        Log::spy();
        $user = User::findOrFail(2);
        $this->actingAs($user);

        $state = app(WorkflowAmoCrmInstallationContext::class)->issue($user);
        $callback = route('amocrm.flow.install', ['code' => 'test-code', 'referer' => 'workflow-test.amocrm.ru', 'state' => $state]);
        $location = $this->get($callback, ['Accept' => 'text/html'])->assertStatus(303)->headers->get('Location');
        $this->get($location)->assertOk()->assertSee('Подключаем amoCRM');

        $job = Queue::pushed(CompleteAmoCrmWidgetInstallation::class)->sole();
        $this->assertSame(2, $job->platformUserId);
        $installation = Mockery::mock(AmoCrmWidgetInstallationService::class);
        $installation->shouldReceive('install')->once()
            ->with('test-code', 'workflow-test.amocrm.ru', 'workflows', 2)
            ->andThrow(new WorkflowAmoCrmAccountAlreadyLinked('private details'));
        $job->handle($installation);

        $this->get($location)->assertStatus(409)
            ->assertSee('Аккаунт amoCRM уже привязан к другому аккаунту Clever')
            ->assertDontSee('private details')
            ->assertHeaderMissing('Location');
        $this->assertAuthenticatedAs($user);
        $this->getJson($callback)->assertUnprocessable();
        Queue::assertPushed(CompleteAmoCrmWidgetInstallation::class, 1);
        Log::shouldHaveReceived('info')->twice()->with('amocrm.flow.install received', Mockery::on(
            fn (array $context): bool => data_get($context, 'payload.state') === '[received]',
        ));
    }

    public function test_callback_rejects_expired_unknown_and_legacy_workflow_contexts(): void
    {
        config(['widget_lifecycle.install_status_store' => 'array']);
        Queue::fake();
        $state = app(WorkflowAmoCrmInstallationContext::class)->issue((new User)->forceFill(['id' => 2]));
        $this->travel(31)->minutes();

        foreach ([$state, 'workflows.'.str_repeat('x', 48), 'user-uuid|workflows'] as $invalid) {
            $this->postJson('/api/amocrm/install/flow', [
                'code' => 'test-code', 'referer' => 'workflow-test.amocrm.ru', 'state' => $invalid,
            ])->assertUnprocessable();
        }

        Queue::assertNothingPushed();
    }

    public function test_marketplace_return_checks_the_account_even_after_sign_in(): void
    {
        FinderDatabase::prepare();
        config(['widget_lifecycle.install_status_store' => 'array']);
        $statuses = app(AmoCrmInstallationStatus::class);
        $token = $statuses->start('workflows');
        $statuses->put($token, ['status' => 'completed', 'widget' => 'workflows', 'user_id' => 1]);
        $url = route('amocrm.installation.status', ['token' => $token]);
        $target = route('integrations.open', ['app' => 2]);

        $this->get($url)->assertRedirect(route('filament.app.auth.login'))->assertSessionHas('url.intended', $url);
        $this->assertGuest();
        $this->actingAs(User::findOrFail(2))->get($url)->assertStatus(409)
            ->assertSee('Аккаунт amoCRM уже привязан к другому аккаунту Clever')->assertHeaderMissing('Location');
        $this->get($target)->assertForbidden();
        $this->actingAs(User::findOrFail(1))->get($url)->assertRedirect($target);
    }

    public function test_off_hook_delegates_to_the_workflow_scoped_disconnect_handler(): void
    {
        Log::spy();

        $controller = new class extends AuthController
        {
            public bool $disconnectCalled = false;
            public ?string $disconnectedWidget = null;

            public function off(Request $request, ?string $forcedWidget = null)
            {
                $this->disconnectCalled = true;
                $this->disconnectedWidget = $forcedWidget;

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
        $this->assertTrue($controller->disconnectCalled);
        $this->assertSame('workflows', $controller->disconnectedWidget);
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
