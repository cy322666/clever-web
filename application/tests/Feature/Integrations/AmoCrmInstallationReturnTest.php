<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\CompleteAmoCrmWidgetInstallation;
use App\Models\App;
use App\Models\Core\Account;
use App\Models\User;
use App\Services\Integrations\AmoCrmInstallationStatus;
use App\Services\Integrations\AmoCrmWidgetInstallationService;
use App\Services\Integrations\AmoCrmWidgetLifecycleTelegramNotifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinderDatabase;
use Tests\TestCase;

class AmoCrmInstallationReturnTest extends TestCase
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

        foreach (self::widgets() as [$callback, $widget]) {
            if (DB::table('apps')->where('user_id', 1)->where('name', $widget)->exists()) {
                continue;
            }
            DB::table('apps')->insert([
                'user_id' => 1, 'name' => $widget,
                'resource_name' => config('integrations.definitions.'.$widget.'.resource'),
                'setting_id' => 55, 'status' => App::STATE_ACTIVE,
            ]);
        }
        Schema::create('sqns_settings', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->integer('account_id');
        });
        DB::table('sqns_settings')->insert(['id' => 55, 'user_id' => 1, 'account_id' => 1]);
    }

    public static function widgets(): array
    {
        return [['sqns', 'sqns'], ['excel', 'import-excel'], ['yclients', 'yclients'], ['finder', 'finder'], ['flow', 'workflows']];
    }

    #[DataProvider('widgets')]
    public function test_browser_waits_and_returns_owner_to_the_correct_widget(string $callback, string $widget): void
    {
        $this->actingAs(User::findOrFail(1));
        $response = $this->get('/api/amocrm/install/'.$callback.'?code=test-code&referer=tenant.amocrm.ru', ['Accept' => 'text/html'])
            ->assertStatus(303)->assertHeader('Referrer-Policy', 'no-referrer');
        $location = $response->headers->get('Location');
        $token = basename(parse_url($location, PHP_URL_PATH));
        $this->assertStringNotContainsString('test-code', $location);
        $this->assertStringNotContainsString('tenant.amocrm.ru', $location);
        $this->assertSame('pending', app(AmoCrmInstallationStatus::class)->get($token)['status']);
        $this->get($location)->assertOk()->assertSee('Подключаем amoCRM')
            ->assertDontSee('amoCRM подключена')->assertSee('http-equiv="refresh"', false);
        $this->get($location)->assertOk();
        Queue::assertPushed(CompleteAmoCrmWidgetInstallation::class, 1);
        $job = Queue::pushed(CompleteAmoCrmWidgetInstallation::class)->first();
        $this->assertSame($widget, $job->widget);
        $this->assertSame($token, $job->completionToken);
        $this->assertNull($job->platformUserId);
        $installation = $this->mock(AmoCrmWidgetInstallationService::class);
        $installation->shouldReceive('install')->once()->with('test-code', 'tenant.amocrm.ru', $widget)->andReturn([
            'user' => User::findOrFail(1), 'account' => Account::findOrFail(1), 'created_user' => false,
        ]);
        $job->handle($installation);
        $appId = App::where('user_id', 1)->where('name', $widget)->value('id');
        $this->get($location)->assertRedirect(route('integrations.open', ['app' => $appId]));
        if ($widget === 'sqns') {
            $this->get(route('integrations.open', ['app' => $appId]))
                ->assertRedirect(route('filament.app.resources.integrations.sqns.edit', ['record' => 55]));
        }
        $this->assertSame(1, Auth::id());
    }

    #[DataProvider('widgets')]
    public function test_machine_callbacks_keep_json_without_a_browser_status(string $callback, string $widget): void
    {
        $payload = ['code' => 'test-code', 'referer' => 'tenant.amocrm.ru'];
        $this->postJson('/api/amocrm/install/'.$callback, $payload)
            ->assertStatus(202)->assertExactJson(['ok' => true, 'status' => 'queued']);
        $this->getJson('/api/amocrm/install/'.$callback.'?'.http_build_query($payload))
            ->assertStatus(202)->assertExactJson(['ok' => true, 'status' => 'queued']);
        $this->post('/api/amocrm/install/'.$callback, $payload, ['Accept' => 'text/html'])
            ->assertStatus(202)->assertExactJson(['ok' => true, 'status' => 'queued']);
        Queue::assertPushed(CompleteAmoCrmWidgetInstallation::class, 3);
        foreach (Queue::pushed(CompleteAmoCrmWidgetInstallation::class) as $job) {
            $this->assertSame($widget, $job->widget);
            $this->assertNull($job->completionToken);
        }
    }

    #[DataProvider('widgets')]
    public function test_missing_data_and_failed_jobs_never_show_success(string $callback, string $widget): void
    {
        $response = $this->get('/api/amocrm/install/'.$callback, ['Accept' => 'text/html'])->assertStatus(303);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Не удалось завершить подключение');
        Queue::assertNothingPushed();
        $this->getJson('/api/amocrm/install/'.$callback)->assertStatus(422);

        $token = app(AmoCrmInstallationStatus::class)->start($widget);
        $job = new CompleteAmoCrmWidgetInstallation('encrypted', 'tenant.amocrm.ru', $widget, $token);
        $job->failed(new \RuntimeException('private error details'));
        $this->get(route('amocrm.installation.status', ['token' => $token]))
            ->assertOk()->assertSee('Не удалось завершить подключение')->assertSee('https://button.amocrm.ru/ddrllz', false)
            ->assertDontSee('private error details')->assertDontSee('amoCRM подключена');
    }

    public function test_status_token_does_not_authenticate_guests_or_switch_tenants(): void
    {
        $statuses = app(AmoCrmInstallationStatus::class);
        $token = $statuses->start('sqns');
        $statuses->put($token, ['status' => 'completed', 'widget' => 'sqns', 'user_id' => 1]);
        $url = route('amocrm.installation.status', ['token' => $token]);
        $target = route('integrations.open', ['app' => App::where('user_id', 1)->where('name', 'sqns')->value('id')]);
        $response = $this->get($url)->assertRedirect($target)->assertDontSee('amoCRM подключена');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertGuest();
        $this->get($target)->assertRedirect(route('filament.app.auth.login'))->assertSessionHas('url.intended', $target);
        $this->actingAs(User::findOrFail(2))->get($url)->assertRedirect($target);
        $this->get($target)->assertForbidden();
        $this->assertSame(2, Auth::id());
    }

    public function test_owner_without_a_catalog_entry_gets_a_page_not_a_redirect_loop(): void
    {
        $token = app(AmoCrmInstallationStatus::class)->start('sqns');
        app(AmoCrmInstallationStatus::class)->put($token, ['status' => 'completed', 'widget' => 'sqns', 'user_id' => 2]);
        $this->actingAs(User::findOrFail(2))->get(route('amocrm.installation.status', ['token' => $token]))
            ->assertOk()->assertSee('Не удалось открыть настройки')->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_delayed_and_expired_statuses_stop_refreshing_without_replaying_oauth(): void
    {
        $statuses = app(AmoCrmInstallationStatus::class);
        $token = $statuses->start('sqns');
        $url = route('amocrm.installation.status', ['token' => $token]);
        $this->travel(121)->seconds();
        $this->get($url)->assertOk()->assertSee('Нужно чуть больше времени')
            ->assertSee('Проверить снова')->assertDontSee('http-equiv="refresh"', false);
        $this->travel(31)->minutes();
        $this->get($url)->assertOk()->assertSee('Ссылка проверки устарела')->assertDontSee('http-equiv="refresh"', false);
        $this->get(route('amocrm.installation.status', ['token' => (string) Str::uuid()]))->assertOk()->assertSee('Ссылка проверки устарела');
        $this->get('/amocrm/installation/not-a-token')->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_queue_dispatch_failure_is_shown_as_a_safe_error(): void
    {
        $this->mock(\Illuminate\Contracts\Bus\Dispatcher::class)->shouldReceive('dispatch')->once()
            ->andThrow(new \RuntimeException('private queue error'));
        $response = $this->get('/api/amocrm/install/sqns?code=test-code&referer=tenant.amocrm.ru', ['Accept' => 'text/html'])
            ->assertStatus(303);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Не удалось завершить подключение')
            ->assertDontSee('private queue error')->assertDontSee('test-code');
    }

    public function test_root_can_return_to_the_connected_clients_settings(): void
    {
        DB::table('users')->where('id', 2)->update(['is_root' => true]);
        $token = app(AmoCrmInstallationStatus::class)->start('sqns');
        app(AmoCrmInstallationStatus::class)->put($token, ['status' => 'completed', 'widget' => 'sqns', 'user_id' => 1]);
        $this->actingAs(User::findOrFail(2));
        $response = $this->get(route('amocrm.installation.status', ['token' => $token]))->assertRedirect();
        $this->get($response->headers->get('Location'))
            ->assertRedirect(route('filament.app.resources.integrations.sqns.edit', ['record' => 55]));
        $this->assertSame(2, Auth::id());
    }

    public function test_legacy_finder_and_workflow_links_use_the_shared_return(): void
    {
        foreach (['finder', 'workflows'] as $widget) {
            $token = (string) Str::uuid();
            \Illuminate\Support\Facades\Cache::store('array')->put($widget.'-installation:'.$token,
                ['status' => 'completed', 'user_id' => 1], now()->addMinutes(30));
            $this->get(route($widget.'.installation.status', ['token' => $token]))
                ->assertRedirect(route('integrations.open', ['app' => App::where('user_id', 1)->where('name', $widget)->value('id')]));
        }
    }

    public function test_unavailable_status_storage_never_returns_raw_json_to_a_browser(): void
    {
        config(['widget_lifecycle.install_status_store' => 'nonexistent']);
        $this->get('/api/amocrm/install/sqns?code=test-code&referer=tenant.amocrm.ru', ['Accept' => 'text/html'])
            ->assertStatus(503)->assertSee('Возникла ошибка')->assertSee('alt="Clever"', false)->assertDontSee('CleverCRM');
        Queue::assertNothingPushed();
    }

    public function test_wait_screen_uses_platform_logo_color_and_no_success_landing(): void
    {
        $token = app(AmoCrmInstallationStatus::class)->start('sqns');
        $this->get(route('amocrm.installation.status', ['token' => $token]))->assertOk()
            ->assertSee('/logo/full_logo.png', false)->assertSee('#ff6a00', false)->assertSee('alt="Clever"', false)
            ->assertDontSee('CleverCRM')->assertDontSee('amoCRM подключена')->assertDontSee('#bd4a06', false);
    }
}
