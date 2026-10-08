<?php

namespace Tests\Feature\Integrations;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Jobs\Integrations\CompleteAmoCrmWidgetInstallation;
use App\Models\Core\Account;
use App\Models\User;
use App\Services\Integrations\AmoCrmWidgetInstallationService;
use App\Services\Integrations\AmoCrmWidgetLifecycleTelegramNotifier;
use App\Services\Workflows\WorkflowInstallationStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowInstallationReturnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        config(['widget_lifecycle.install_status_store' => 'array']);
        Http::preventStrayRequests();
        Queue::fake();
        Log::spy();
        $this->mock(AmoCrmWidgetLifecycleTelegramNotifier::class)->shouldReceive('notify')->zeroOrMoreTimes();
    }

    public function test_browser_waits_for_completed_job_before_returning_to_workflows(): void
    {
        $this->actingAs(User::findOrFail(1));
        $response = $this->get('/api/amocrm/install/flow?code=test-code&referer=workflow-test.amocrm.ru', ['Accept' => 'text/html'])
            ->assertStatus(303)->assertHeader('Referrer-Policy', 'no-referrer');
        $location = $response->headers->get('Location');
        $this->assertStringNotContainsString('test-code', $location);
        $token = basename(parse_url($location, PHP_URL_PATH));
        $this->assertSame('pending', app(WorkflowInstallationStatus::class)->get($token)['status']);
        $this->get($location)->assertOk()->assertSee('Подключаем amoCRM')
            ->assertSee('http-equiv="refresh"', false)->assertDontSee('amoCRM подключена');

        $job = Queue::pushed(CompleteAmoCrmWidgetInstallation::class)->first();
        $this->assertSame($token, $job->completionToken);
        $this->assertSame('workflows', $job->widget);
        $installation = Mockery::mock(AmoCrmWidgetInstallationService::class);
        $installation->shouldReceive('install')->once()->with('test-code', 'workflow-test.amocrm.ru', 'workflows')->andReturn([
            'user' => User::findOrFail(1),
            'account' => new Account(['subdomain' => 'workflow-test', 'zone' => 'ru', 'amo_account_id' => 123, 'client_id' => 'own-workflow-id']),
        ]);
        $job->handle($installation);

        $this->get($location)->assertRedirect(WorkflowResource::getUrl('index', panel: 'app'));
        $this->assertSame(1, Auth::id());
        $this->assertNull(app(\App\Services\Finder\InstallationStatus::class)->get($token));
    }

    public function test_json_get_and_server_post_keep_async_api_contract(): void
    {
        $this->getJson('/api/amocrm/install/flow?code=test-code&referer=workflow-test.amocrm.ru')
            ->assertStatus(202)->assertExactJson(['ok' => true, 'status' => 'queued']);
        $this->post('/api/amocrm/install/flow', ['code' => 'test-code', 'referer' => 'workflow-test.amocrm.ru'])
            ->assertStatus(202)->assertExactJson(['ok' => true, 'status' => 'queued']);
        Queue::assertPushed(CompleteAmoCrmWidgetInstallation::class, 2);
        foreach (Queue::pushed(CompleteAmoCrmWidgetInstallation::class) as $job) {
            $this->assertNull($job->completionToken);
        }
    }

    public function test_missing_oauth_data_shows_failure_without_dispatching_job(): void
    {
        $response = $this->get('/api/amocrm/install/flow', ['Accept' => 'text/html'])->assertStatus(303);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Не удалось подключить amoCRM')
            ->assertDontSee('http-equiv="refresh"', false);
        Queue::assertNothingPushed();
    }

    public function test_failed_job_shows_safe_error_and_does_not_claim_success(): void
    {
        $token = app(WorkflowInstallationStatus::class)->start();
        $job = new CompleteAmoCrmWidgetInstallation('encrypted', 'workflow-test.amocrm.ru', 'workflows', $token);
        $job->failed(new \RuntimeException('private-token-and-error'));
        $this->get(route('workflows.installation.status', ['token' => $token]))
            ->assertOk()->assertSee('Не удалось подключить amoCRM')
            ->assertDontSee('private-token-and-error')->assertDontSee('amoCRM подключена');
    }

    public function test_queue_dispatch_failure_also_returns_a_safe_browser_error(): void
    {
        Bus::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('private-queue-error'));
        $response = $this->get('/api/amocrm/install/flow?code=test-code&referer=workflow-test.amocrm.ru', ['Accept' => 'text/html'])
            ->assertStatus(303);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Не удалось подключить amoCRM')
            ->assertDontSee('private-queue-error')->assertDontSee('test-code');
    }

    public function test_status_never_authenticates_guest_or_switches_another_user(): void
    {
        $statuses = app(WorkflowInstallationStatus::class);
        $token = $statuses->start();
        $statuses->put($token, ['status' => 'completed', 'user_id' => 1]);
        $url = route('workflows.installation.status', ['token' => $token]);

        $this->get($url)->assertRedirect(WorkflowResource::getUrl('index', panel: 'app'));
        $this->assertGuest();
        $this->get(WorkflowResource::getUrl('index', panel: 'app'))->assertRedirect(route('filament.app.auth.login'));
        $this->actingAs(User::findOrFail(2))->get($url)->assertOk()->assertSee('другой аккаунт платформы');
        $this->assertSame(2, Auth::id());
    }

    public function test_delayed_and_expired_states_do_not_claim_success_or_poll_forever(): void
    {
        $statuses = app(WorkflowInstallationStatus::class);
        $token = $statuses->start();
        $this->travel(121)->seconds();
        $this->get(route('workflows.installation.status', ['token' => $token]))
            ->assertOk()->assertSee('больше времени')->assertSee('Проверить статус')->assertDontSee('http-equiv="refresh"', false);
        $this->travel(31)->minutes();
        $this->get(route('workflows.installation.status', ['token' => $token]))
            ->assertOk()->assertSee('Ссылка проверки устарела')->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_redirect_target_cannot_be_overridden_by_callback_parameters(): void
    {
        $statuses = app(WorkflowInstallationStatus::class);
        $token = $statuses->start();
        $statuses->put($token, ['status' => 'completed', 'user_id' => 1]);
        $this->actingAs(User::findOrFail(1));
        $this->get(route('workflows.installation.status', ['token' => $token, 'uri' => 'https://example.invalid', 'redirect' => '//example.invalid']))
            ->assertRedirect(WorkflowResource::getUrl('index', panel: 'app'));
    }
}
