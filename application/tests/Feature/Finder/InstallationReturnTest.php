<?php

namespace Tests\Feature\Finder;

use App\Jobs\Integrations\CompleteAmoCrmWidgetInstallation;
use App\Models\Core\Account;
use App\Models\User;
use App\Services\Finder\InstallationStatus;
use App\Services\Integrations\AmoCrmWidgetInstallationService;
use App\Services\Integrations\AmoCrmWidgetLifecycleTelegramNotifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\FinderDatabase;
use Tests\TestCase;

class InstallationReturnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FinderDatabase::prepare();
        config(['widget_lifecycle.install_status_store' => 'array']);
        Http::preventStrayRequests();
        Queue::fake();
        Log::spy();
        $this->mock(AmoCrmWidgetLifecycleTelegramNotifier::class)->shouldReceive('notify')->zeroOrMoreTimes();
    }

    public function test_browser_callback_waits_for_job_and_returns_owner_to_settings(): void
    {
        $this->actingAs(User::findOrFail(1));
        $response = $this->get('/api/amocrm/install/finder?code=test-code&referer=finder-test.amocrm.ru', ['Accept' => 'text/html'])
            ->assertStatus(303)->assertHeader('Referrer-Policy', 'no-referrer');
        $location = $response->headers->get('Location');
        $this->assertStringNotContainsString('test-code', $location);
        $token = basename(parse_url($location, PHP_URL_PATH));
        $this->assertSame('pending', app(InstallationStatus::class)->get($token)['status']);
        $this->get($location)->assertOk()->assertSee('Подключаем amoCRM')->assertDontSee('amoCRM подключена');

        $job = Queue::pushed(CompleteAmoCrmWidgetInstallation::class)->first();
        $this->assertSame($token, $job->completionToken);
        $installation = Mockery::mock(AmoCrmWidgetInstallationService::class);
        $installation->shouldReceive('install')->once()->with('test-code', 'finder-test.amocrm.ru', 'finder')->andReturn([
            'user' => User::findOrFail(1), 'account' => Account::findOrFail(1), 'created_user' => false,
        ]);
        $job->handle($installation);

        $this->get($location)->assertRedirect(route('filament.app.resources.integrations.finder.edit', ['record' => 1]));
        $this->assertSame(1, Auth::id());
    }

    public function test_status_never_authenticates_guest_or_another_platform_user(): void
    {
        $statuses = app(InstallationStatus::class);
        $token = $statuses->start();
        $statuses->put($token, ['status' => 'completed', 'user_id' => 1, 'domain' => 'finder-test.amocrm.ru']);
        $url = route('finder.installation.status', ['token' => $token]);

        $this->get($url)->assertOk()->assertSee('amoCRM подключена')->assertSee('Войти в платформу');
        $this->assertGuest();
        $this->actingAs(User::findOrFail(2))->get($url)->assertOk()->assertSee('Войти в платформу');
        $this->assertSame(2, Auth::id());
    }

    public function test_only_valid_server_issued_context_selects_the_platform_owner(): void
    {
        $contexts = app(\App\Services\Finder\InstallationContext::class);
        $state = $contexts->encode(\App\Models\Integrations\Finder\Setting::findOrFail(1));
        $this->postJson('/api/amocrm/install/finder', ['code' => 'test', 'referer' => 'finder-test.amocrm.ru', 'state' => $state])->assertStatus(202);
        Queue::assertPushed(CompleteAmoCrmWidgetInstallation::class, fn ($job) => $job->platformUserId === 1);

        $this->postJson('/api/amocrm/install/finder', ['code' => 'test', 'referer' => 'finder-test.amocrm.ru', 'state' => $state.'tampered'])->assertStatus(422);
        $this->travel(31)->minutes();
        $this->postJson('/api/amocrm/install/finder', ['code' => 'test', 'referer' => 'finder-test.amocrm.ru', 'state' => $state])->assertStatus(422);
        Queue::assertPushed(CompleteAmoCrmWidgetInstallation::class, 1);
        $this->assertNull($contexts->userId(base64_encode(json_encode(['user_uuid' => 'untrusted']))));
    }

    public function test_missing_callback_data_and_failed_jobs_show_error_without_false_success(): void
    {
        $response = $this->get('/api/amocrm/install/finder', ['Accept' => 'text/html'])->assertStatus(303);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Не удалось завершить подключение');
        Queue::assertNothingPushed();

        $token = app(InstallationStatus::class)->start();
        $job = new CompleteAmoCrmWidgetInstallation('encrypted', 'finder-test.amocrm.ru', 'finder', $token);
        $job->failed(new \RuntimeException('private error details'));
        $this->get(route('finder.installation.status', ['token' => $token]))
            ->assertOk()->assertSee('Не удалось завершить подключение')->assertDontSee('private error details');
    }

    public function test_expired_and_delayed_status_stop_auto_refresh(): void
    {
        $statuses = app(InstallationStatus::class);
        $token = $statuses->start();
        $statuses->put($token, ['status' => 'pending', 'started_at' => time() - 121]);
        $this->get(route('finder.installation.status', ['token' => $token]))
            ->assertOk()->assertSee('больше времени')->assertDontSee('http-equiv="refresh"', false);
        $this->get(route('finder.installation.status', ['token' => 'c8754d3e-c31d-4bdd-a498-033cc63a5b63']))
            ->assertOk()->assertSee('Ссылка проверки устарела')->assertDontSee('http-equiv="refresh"', false);
    }
}
