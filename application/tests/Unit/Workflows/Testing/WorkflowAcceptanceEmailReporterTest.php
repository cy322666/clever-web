<?php

declare(strict_types=1);

namespace Tests\Unit\Workflows\Testing;

use App\Services\Workflows\Testing\WorkflowAcceptanceEmailReporter;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as TransportSentMessage;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WorkflowAcceptanceEmailReporterTest extends TestCase
{
    private WorkflowAcceptanceEmailReporter $reporter;
    private Mailer $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('workflow_acceptance.telegram', [
            'token' => '12345:synthetic-test-token-abcdefghijklmnop',
            'chat_id' => '1234567',
            'message_thread_id' => '',
        ]);
        config()->set('workflow_acceptance.email', ['to' => 'reports@example.test', 'mailer' => 'smtp']);
        config()->set('mail.mailers.smtp.transport', 'smtp');
        Http::preventStrayRequests();
        $this->mailer = \Mockery::mock(Mailer::class);
        Mail::shouldReceive('mailer')->with('smtp')->andReturn($this->mailer);
        $this->reporter = new WorkflowAcceptanceEmailReporter;
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

    public function test_explicit_capability_skip_keeps_failed_evidence_without_becoming_a_failure(): void
    {
        $message = $this->reporter->format($this->report(['cases' => [
            ['id' => 'query_leads', 'status' => 'passed'],
            [
                'id' => 'read:segments.list', 'status' => 'skipped',
                'verification' => ['passed' => false],
                'result' => ['status' => 'error', 'error' => 'Customers disabled'],
                'reason' => 'Покупатели отключены в тестовом аккаунте.',
            ],
        ]]), 'report.json');

        $this->assertStringStartsWith('🟡', $message);
        $this->assertStringContainsString('Успешно: 1 · Ошибок: 0 · Пропущено: 1', $message);
        $this->assertStringContainsString('Пропуски не подтверждают работоспособность этих нод.', $message);
        $this->assertStringNotContainsString('Где сломалось:', $message);
    }

    public function test_prerequisite_failure_shows_actual_diagnostic_without_token_or_personal_data(): void
    {
        $token = (string) config('workflow_acceptance.telegram.token');
        $message = $this->reporter->format($this->report(['cases' => [
            [
                'id' => 'read:contacts.notes.one', 'status' => 'failed',
                'prerequisite_error' => 'HTTP 404: prerequisite note not found; token='.$token.'; email=client@example.test',
            ],
            [
                'id' => 'remove_lead_tags', 'status' => 'failed',
                'verification' => ['passed' => false, 'reason' => 'Тег остался в сделке после удаления.'],
            ],
        ]]), 'report.json');

        $this->assertStringStartsWith('🔴', $message);
        $this->assertStringContainsString('HTTP 404: prerequisite note not found', $message);
        $this->assertStringContainsString('Удаление тега сделки · remove_lead_tags', $message);
        $this->assertStringContainsString('Тег остался в сделке после удаления.', $message);
        $this->assertStringNotContainsString($token, $message);
        $this->assertStringNotContainsString('client@example.test', $message);
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

    public function test_long_errors_stay_bounded_with_report_reference(): void
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

    private function receipt(): SentMessage
    {
        $email = (new Email)->from('sender@example.test')->to('reports@example.test')->text('report');
        return new SentMessage(new TransportSentMessage($email, Envelope::create($email)));
    }

    public function test_notification_sends_only_plain_text_to_configured_email_without_telegram(): void
    {
        config()->set('workflow_acceptance.telegram', []);
        $receipt = $this->receipt();
        $this->mailer->shouldReceive('raw')->once()->withArgs(function (string $text, callable $callback): bool {
            $message = new Message(new Email);
            $callback($message);
            $email = $message->getSymfonyMessage();
            $this->assertSame(['reports@example.test'], array_map(fn ($address) => $address->getAddress(), $email->getTo()));
            $this->assertSame('CleverCRM: отчёт автотестов «Потоков»', $email->getSubject());
            $this->assertSame([], $email->getAttachments());
            $this->assertStringContainsString('Автотесты amoCRM', $text);
            return true;
        })->andReturn($receipt);

        $result = $this->reporter->notify($this->report(), 'report.json');

        $this->assertSame(['ok' => true, 'channel' => 'email', 'message_id' => $receipt->getMessageId(), 'error' => null], $result);
        Http::assertNothingSent();
    }

    public function test_missing_transport_receipt_is_not_reported_as_delivery(): void
    {
        $this->mailer->shouldReceive('raw')->once()->andReturnNull();

        $result = $this->reporter->notify($this->report(), 'report.json');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['message_id']);
        $this->assertStringContainsString('не подтвердил приём', $result['error']);
        Http::assertNothingSent();
    }

    public function test_transport_error_is_not_retried_or_exposed(): void
    {
        $secret = 'synthetic-smtp-password';
        $this->mailer->shouldReceive('raw')->once()->andThrow(new \RuntimeException('SMTP auth failed: '.$secret));

        $result = $this->reporter->notify($this->report(), 'report.json');

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString($secret, $result['error']);
        $this->assertStringContainsString('ошибка почтового транспорта', $result['error']);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidMailSettings')]
    public function test_invalid_settings_do_not_send_or_fall_back_to_telegram(string $recipient, string $transport): void
    {
        config()->set('workflow_acceptance.email.to', $recipient);
        config()->set('mail.mailers.smtp.transport', $transport);
        $this->mailer->shouldNotReceive('raw');

        $result = $this->reporter->notify($this->report(), 'report.json');

        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['error']);
        Http::assertNothingSent();
    }

    public static function invalidMailSettings(): array
    {
        return [
            'missing email' => ['', 'smtp'],
            'invalid email' => ['not-an-address', 'smtp'],
            'multiple recipients are not implicit' => ['a@example.test,b@example.test', 'smtp'],
            'header injection' => ["reports@example.test\\r\\nBcc: other@example.test", 'smtp'],
            'log is not delivery' => ['reports@example.test', 'log'],
            'array is not delivery' => ['reports@example.test', 'array'],
            'failover may silently log' => ['reports@example.test', 'failover'],
        ];
    }
}
