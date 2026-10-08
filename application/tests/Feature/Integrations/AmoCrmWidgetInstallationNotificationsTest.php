<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\CompleteAmoCrmWidgetInstallation;
use App\Models\Core\Account;
use App\Models\User;
use App\Services\Finder\InstallationStatus;
use App\Services\Integrations\AmoCrmWidgetInstallationService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AmoCrmWidgetInstallationNotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('n', 32)),
            'cache.default' => 'array',
            'widget_lifecycle.install_status_store' => 'array',
            'widget_lifecycle.telegram.token' => 'test-bot-token',
            'widget_lifecycle.telegram.chat_id' => '-100123',
            'services.amocrm.widgets.finder.client_id' => 'finder-client-id',
        ]);
        Queue::fake();
        Log::spy();
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 701]])]);
    }

    public static function widgets(): array
    {
        return [
            'flows' => ['flow', 'workflows'],
            'excel' => ['excel', 'import-excel'],
            'finder' => ['finder', 'finder'],
            'sqns' => ['sqns', 'sqns'],
            'yclients' => ['yclients', 'yclients'],
        ];
    }

    #[DataProvider('widgets')]
    public function test_success_is_sent_only_after_installation_with_verified_account_identity(string $route, string $widget): void
    {
        $this->postJson('/api/amocrm/install/'.$route, [
            'code' => 'one-time-code',
            'referer' => 'https://verified.amocrm.ru',
            'account_id' => 999,
            'client_id' => 'untrusted-callback-client-id',
        ])->assertStatus(202);

        Http::assertNothingSent();
        Queue::assertPushed(CompleteAmoCrmWidgetInstallation::class, 1);
        $job = Queue::pushed(CompleteAmoCrmWidgetInstallation::class)->first();
        $installation = $this->mock(AmoCrmWidgetInstallationService::class);
        $installation->shouldReceive('install')->once()
            ->with('one-time-code', 'https://verified.amocrm.ru', $widget)
            ->andReturnUsing(function (): array {
                Http::assertNothingSent();

                return $this->installationResult();
            });

        $job->handle($installation);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Виджет установлен')
            && str_contains($request['text'], 'Аккаунт: 33098322')
            && str_contains($request['text'], 'Домен: verified.amocrm.ru')
            && str_contains($request['text'], 'ID интеграции: verified-client-id')
            && ! str_contains($request['text'], '999')
            && ! str_contains($request['text'], 'untrusted-callback-client-id')
            && ! str_contains($request['text'], 'one-time-code'));
    }

    public function test_callback_without_account_id_still_reports_the_verified_id(): void
    {
        $this->postJson('/api/amocrm/install/finder', [
            'code' => 'one-time-code', 'referer' => 'verified.amocrm.ru',
        ])->assertStatus(202);
        Http::assertNothingSent();

        $job = Queue::pushed(CompleteAmoCrmWidgetInstallation::class)->first();
        $installation = $this->mock(AmoCrmWidgetInstallationService::class);
        $installation->shouldReceive('install')->once()->andReturn($this->installationResult());
        $job->handle($installation);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Аккаунт: 33098322')
            && ! str_contains($request['text'], 'не передан'));
    }

    public function test_failed_installation_sends_only_failure_and_preserves_finder_status(): void
    {
        $token = app(InstallationStatus::class)->start();
        $job = $this->job($token);
        $exception = ValidationException::withMessages([
            'subdomain' => 'Все виджеты аккаунта платформы должны подключаться к одному amoCRM: other-account.',
        ]);
        $installation = $this->mock(AmoCrmWidgetInstallationService::class);
        $installation->shouldReceive('install')->once()->andThrow($exception);

        try {
            $job->handle($installation);
            $this->fail('Installation must propagate the original error to the queue.');
        } catch (ValidationException $caught) {
            $this->assertSame($exception, $caught);
            Http::assertNothingSent();
            $job->failed($caught);
        }

        $this->assertSame('failed', app(InstallationStatus::class)->get($token)['status']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Не удалось установить виджет')
            && str_contains($request['text'], 'Аккаунт платформы уже подключён к другой amoCRM.')
            && str_contains($request['text'], 'Домен: verified.amocrm.ru')
            && str_contains($request['text'], 'ID интеграции: finder-client-id')
            && ! str_contains($request['text'], 'Виджет установлен')
            && ! str_contains($request['text'], 'Виджет отключён')
            && ! str_contains($request['text'], 'Аккаунт: не передан'));
    }

    #[DataProvider('telegramFailures')]
    public function test_telegram_failure_does_not_fail_completed_installation(bool $connectionFailure): void
    {
        Http::fake(['api.telegram.org/*' => $connectionFailure
            ? fn () => throw new ConnectionException('Telegram unavailable')
            : Http::response(['ok' => false], 503)]);
        $token = app(InstallationStatus::class)->start();
        $installation = $this->mock(AmoCrmWidgetInstallationService::class);
        $installation->shouldReceive('install')->once()->andReturn($this->installationResult());

        $job = $this->job($token);
        $job->handle($installation);

        $status = app(InstallationStatus::class)->get($token);
        $this->assertSame('completed', $status['status']);
        $this->assertSame(42, $status['user_id']);
        $this->assertSame(1, $job->tries);
        Log::shouldNotHaveReceived('critical');
    }

    public static function telegramFailures(): array
    {
        return ['http error' => [false], 'connection error' => [true]];
    }

    public function test_jobs_queued_before_optional_fields_were_added_still_complete(): void
    {
        $job = $this->job();
        unset($job->platformUserId, $job->completionToken);
        $installation = $this->mock(AmoCrmWidgetInstallationService::class);
        $installation->shouldReceive('install')->once()
            ->with('one-time-code', 'verified.amocrm.ru', 'finder')
            ->andReturn($this->installationResult());

        $job->handle($installation);
        Http::assertSentCount(1);
    }

    public function test_missing_yclients_client_id_does_not_use_unrelated_platform_client(): void
    {
        config(['services.amocrm.widgets.yclients.client_id' => '', 'services.amocrm.client_id' => 'unrelated-client']);
        $job = new CompleteAmoCrmWidgetInstallation('encrypted', 'verified.amocrm.ru', 'yclients');
        $job->failed(new \RuntimeException('amoCRM yclients client_id is not configured.'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Не настроены параметры OAuth для виджета.')
            && ! str_contains($request['text'], 'unrelated-client'));
    }

    private function job(?string $token = null): CompleteAmoCrmWidgetInstallation
    {
        return new CompleteAmoCrmWidgetInstallation(
            Crypt::encryptString('one-time-code'), 'verified.amocrm.ru', 'finder', $token,
        );
    }

    private function installationResult(): array
    {
        return [
            'user' => (new User)->forceFill(['id' => 42]),
            'account' => (new Account)->forceFill([
                'id' => 7, 'amo_account_id' => 33098322, 'subdomain' => 'verified',
                'zone' => 'ru', 'client_id' => 'verified-client-id',
            ]),
            'created_user' => false,
        ];
    }
}
