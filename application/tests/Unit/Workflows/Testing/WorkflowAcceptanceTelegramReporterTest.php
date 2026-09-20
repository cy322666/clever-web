<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows\Testing;

use App\Services\Workflows\Testing\WorkflowAcceptanceTelegramReporter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WorkflowAcceptanceTelegramReporterTest extends TestCase
{
    private WorkflowAcceptanceTelegramReporter $reporter;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('workflow_acceptance.telegram', [
            'token' => '12345:synthetic-test-token-abcdefghijklmnop',
            'chat_id' => '1234567',
            'message_thread_id' => '',
        ]);
        Http::preventStrayRequests();
        $this->reporter = new WorkflowAcceptanceTelegramReporter;
    }

    private function report(array $overrides = []): array
    {
        return array_replace([
            'suite' => 'live_acceptance',
            'account' => ['subdomain' => 'contract-account'],
            'started_at' => '2026-09-20T01:00:00+00:00',
            'finished_at' => '2026-09-20T01:02:05+00:00',
            'cases' => [['id' => 'query_leads', 'status' => 'passed']],
            'cleanup' => ['restore_workflow_activation' => ['ok' => true]],
        ], $overrides);
    }

    public function test_success_has_account_local_time_duration_and_recomputed_counts(): void
    {
        $message = $this->reporter->format($this->report(['counts' => ['failed' => 99]]), '/private/reports/acceptance-20260920.json');

        $this->assertStringStartsWith('🟢', $message);
        $this->assertStringContainsString('Аккаунт: contract-account', $message);
        $this->assertStringContainsString('20.09.2026 03:00 (Калининград) · 2 мин 5 с', $message);
        $this->assertStringContainsString('Успешно: 1 · Ошибок: 0 · Пропущено: 0', $message);
        $this->assertStringContainsString('acceptance-20260920.json', $message);
        $this->assertStringNotContainsString('/private/reports', $message);
        Http::assertNothingSent();
    }

    #[DataProvider('incompleteReports')]
    public function test_incomplete_or_cleanup_failure_never_looks_successful(array $overrides, string $expected): void
    {
        $message = $this->reporter->format($this->report($overrides), 'report.json');

        $this->assertStringStartsWith('🔴', $message);
        $this->assertStringContainsString($expected, $message);
    }

    public static function incompleteReports(): array
    {
        return [
            'zero cases' => [['cases' => []], 'Ни одна проверка не выполнена'],
            'only skipped' => [['cases' => [['id' => 'company', 'status' => 'skipped']]], 'Ни одна проверка не выполнена'],
            'fatal' => [['fatal_error' => 'Account mismatch'], 'Прогон остановлен: Account mismatch'],
            'cleanup failed' => [['cleanup' => ['restore_workflow_activation' => ['ok' => false, 'error' => 'Database unavailable']]], 'Не восстановлено: restore_workflow_activation'],
            'legacy cleanup false' => [['cleanup' => ['restore_workflow_activation' => false]], 'Не восстановлено: restore_workflow_activation'],
            'unfinished' => [['finished_at' => null], 'Завершение прогона не подтверждено'],
            'report missing' => [['report_write_errors' => ['permission denied']], 'Не удалось полностью сохранить'],
            'verification contradicts status' => [['cases' => [['id' => 'contact_update', 'status' => 'passed', 'verification' => ['passed' => false]]]], 'Ошибок: 1'],
            'bad result format' => [['cases' => ['invalid']], 'Повреждён результат проверки'],
        ];
    }

    public function test_skipped_cases_are_not_reported_as_verified(): void
    {
        $message = $this->reporter->format($this->report(['cases' => [
            ['id' => 'query_leads', 'status' => 'passed'],
            ['id' => 'customers', 'status' => 'skipped'],
        ]]), 'report.json');

        $this->assertStringStartsWith('🟡', $message);
        $this->assertStringContainsString('Пропуски не подтверждают работоспособность этих нод.', $message);
        $this->assertStringContainsString('Успешно: 1 · Ошибок: 0 · Пропущено: 1', $message);
    }

    public function test_failure_identifies_case_and_actual_api_error(): void
    {
        $message = $this->reporter->format($this->report(['cases' => [
            ['id' => 'read:segments.list', 'status' => 'failed', 'error' => 'HTTP 422: Customers disabled'],
            ['id' => 'get_contact', 'status' => 'failed', 'result' => ['error' => 'Контакт не найден.']],
        ]]), 'report.json');

        $this->assertStringContainsString('read:segments.list: HTTP 422: Customers disabled', $message);
        $this->assertStringContainsString('get_contact: Контакт не найден.', $message);
        $this->assertStringStartsWith('🔴', $message);
    }

    public function test_verification_mismatch_reports_field_without_personal_text(): void
    {
        $message = $this->reporter->format($this->report(['cases' => [[
            'id' => 'update_contact', 'status' => 'failed', 'verification' => [
                'passed' => false, 'differences' => [
                    'name' => ['expected' => 'Ivan Example', 'actual' => 'Petr Example'],
                    'price' => ['expected' => 123, 'actual' => 0],
                ],
            ],
        ]]]), 'report.json');

        $this->assertStringContainsString('После запроса данные не совпали', $message);
        $this->assertStringContainsString('price — ожидалось 123, получено 0', $message);
        $this->assertStringNotContainsString('Ivan Example', $message);
        $this->assertStringNotContainsString('Petr Example', $message);
    }

    public function test_secrets_and_personal_history_are_never_attached_or_forwarded(): void
    {
        $secret = (string) config('workflow_acceptance.telegram.token');
        $message = $this->reporter->format($this->report([
            'fatal_error' => 'Failure '.$secret.' https://api.telegram.org/bot'.$secret.'/sendMessage john@example.test +7 (999) 222-33-44',
            'historical_runs' => [['text' => 'Private client message', 'email' => 'client@example.test', 'name' => 'Private Person']],
            'cases' => [['id' => 'contact_update', 'status' => 'failed', 'error' => 'Invalid name: Private Person']],
        ]), 'report.json');

        foreach ([$secret, 'john@example.test', '+7 (999)', 'Private client message', 'client@example.test', 'Private Person', 'api.telegram.org'] as $value) {
            $this->assertStringNotContainsString($value, $message);
        }
    }

    public function test_long_errors_fit_telegram_limit_with_report_reference(): void
    {
        $cases = [];
        for ($index = 0; $index < 100; $index++) {
            $cases[] = ['id' => 'case-'.$index, 'status' => 'failed', 'error' => str_repeat('Ошибка данных ', 500)];
        }
        $message = $this->reporter->format($this->report(['cases' => $cases]), 'report.json');

        $this->assertLessThanOrEqual(3900, mb_strlen($message));
        $this->assertStringContainsString('Ещё ошибок: 93', $message);
        $this->assertStringEndsWith('Отчёт на сервере: report.json', $message);
    }

    public function test_expected_negative_validation_case_is_success_not_failure(): void
    {
        $message = $this->reporter->format($this->report(['cases' => [[
            'id' => 'missing_note_text', 'status' => 'passed', 'expected_outcome' => 'validation_error',
            'result' => ['status' => 'error', 'error' => 'Не заполнен текст примечания.'],
            'verification' => ['passed' => true],
        ]]]), 'report.json');

        $this->assertStringStartsWith('🟢', $message);
    }

    public function test_notification_sends_only_plain_text_summary_to_configured_chat_and_topic(): void
    {
        config()->set('workflow_acceptance.telegram.message_thread_id', '17');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);

        $result = $this->reporter->notify($this->report(), 'report.json');

        $this->assertSame(['ok' => true, 'message_id' => 123, 'error' => null], $result);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['chat_id'] === '1234567'
            && $request['message_thread_id'] === '17'
            && ! isset($request['document'])
            && ! isset($request['parse_mode'])
            && str_contains($request['text'], 'Автотесты amoCRM'));
    }

    #[DataProvider('telegramFailures')]
    public function test_api_failure_is_returned_not_silently_ignored(int $status, array $body): void
    {
        Http::fake(['api.telegram.org/*' => Http::response($body, $status)]);

        $result = $this->reporter->notify($this->report(), 'report.json');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['message_id']);
        $this->assertStringContainsString('не подтвердил доставку', $result['error']);
        $this->assertStringNotContainsString('SECRET', $result['error']);
        Http::assertSentCount(1);
    }

    public static function telegramFailures(): array
    {
        return [
            'forbidden' => [403, ['ok' => false, 'description' => 'SECRET']],
            'error with http 200' => [200, ['ok' => false, 'description' => 'SECRET']],
            'missing message id' => [200, ['ok' => true]],
            'server unavailable' => [503, []],
        ];
    }

    public function test_connection_error_does_not_leak_token_url_or_retry(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('https://api.telegram.org/bot'.config('workflow_acceptance.telegram.token').'/sendMessage failed');
        });

        $result = $this->reporter->notify($this->report(), 'report.json');

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $attempts);
        $this->assertStringNotContainsString((string) config('workflow_acceptance.telegram.token'), $result['error']);
        $this->assertStringNotContainsString('api.telegram.org', $result['error']);
    }

    public function test_missing_settings_are_reported_without_network_call(): void
    {
        config()->set('workflow_acceptance.telegram.chat_id', '');

        $result = $this->reporter->notify($this->report(), 'report.json');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Не настроен Telegram', $result['error']);
        Http::assertNothingSent();
    }
}
