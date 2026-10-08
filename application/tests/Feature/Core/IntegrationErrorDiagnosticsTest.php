<?php

namespace Tests\Feature\Core;

use App\Jobs\Integrations\SendIntegrationErrorAlert;
use App\Listeners\AdminErrorSubscriber;
use App\Services\Integrations\IntegrationErrorDiagnostic;
use App\Services\Integrations\IntegrationErrorNotifier;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Psy\Exception\ParseErrorException;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class IntegrationErrorDiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'error_diagnostics_test',
            'database.connections.error_diagnostics_test' => ['driver' => 'sqlite', 'database' => ':memory:'],
            'alerts.enabled' => true,
            'alerts.integration_errors.enabled' => true,
            'alerts.cache_store' => 'array',
            'widget_lifecycle.telegram.token' => 'test-token',
            'widget_lifecycle.telegram.chat_id' => 'test-chat',
        ]);
        DB::purge('error_diagnostics_test');
        Cache::store('array')->flush();
        Queue::fake();
        Http::preventStrayRequests();
        Log::spy();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
        });
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id');
            $table->string('widget');
            $table->string('subdomain');
            $table->string('zone');
            $table->boolean('active');
        });
    }

    public function test_console_parse_error_retains_command_after_failed_exit_without_exposing_arguments(): void
    {
        $subscriber = app(AdminErrorSubscriber::class);
        $input = new ArrayInput(['--execute' => 'private-source secret=secret-argument']);
        $output = new BufferedOutput();
        $subscriber->commandStarting(new CommandStarting('tinker', $input, $output));
        $subscriber->commandFinished(new CommandFinished('tinker', $input, $output, 1));
        $exception = new ParseErrorException("Syntax error, unexpected '='", ['startLine' => 1]);
        $subscriber->logged(new MessageLogged('error', $exception->getMessage(), ['exception' => $exception]));

        Queue::assertPushed(SendIntegrationErrorAlert::class, function ($job): bool {
            foreach (['Команда: tinker', 'Ошибка синтаксиса PHP', '«=»', 'строка 1', 'Тип: ParseErrorException', 'Код:', 'ID ошибки:'] as $text) {
                $this->assertStringContainsString($text, $job->message);
            }
            foreach (['private-source', 'secret-argument', 'не определён', 'не определена', 'queue-monitors', 'Ошибка обработки данных'] as $text) {
                $this->assertStringNotContainsString($text, $job->message);
            }
            preg_match('/ID ошибки: ([A-F0-9]{12})/', $job->message, $match);
            Log::shouldHaveReceived('info')->withArgs(fn ($message, $context): bool => $message === 'Admin error diagnostic'
                && $context['alert_id'] === $match[1] && $context['operation'] === 'Команда: tinker');

            return true;
        });
        Http::assertNothingSent();
    }

    public function test_unknown_error_has_type_and_location_but_never_forwards_raw_payload(): void
    {
        $error = 'private-response access_token=secret-access email=customer@example.test';
        $exception = new RuntimeException($error);
        app(IntegrationErrorNotifier::class)->report('platform', $error, operation: 'Команда: app:check', exception: $exception);
        Queue::assertPushed(SendIntegrationErrorAlert::class, function ($job) use ($exception): bool {
            $this->assertStringContainsString('Тип: RuntimeException', $job->message);
            $this->assertStringContainsString('IntegrationErrorDiagnosticsTest.php:'.$exception->getLine(), $job->message);
            foreach (['private-response', 'secret-access', 'customer@example.test'] as $value) {
                $this->assertStringNotContainsString($value, serialize($job));
            }

            return true;
        });
    }

    public function test_amo_validation_error_explains_field_and_preserves_client_identity(): void
    {
        DB::table('users')->insert(['id' => 1, 'email' => 'owner@example.test']);
        DB::table('accounts')->insert(['id' => 11, 'user_id' => 1, 'widget' => 'sqns', 'subdomain' => 'test-client', 'zone' => 'ru', 'active' => true]);
        $error = 'amoCRM API v4 error: PATCH /api/v4/leads/123 returned 400: {"validation-errors":[{"request_id":"0","errors":[{"code":"InvalidType","path":"price","detail":"This value should be of type int."}]}],"private":"secret-body"}';
        app(IntegrationErrorNotifier::class)->report('sqns', $error, 1, 11, 'Обработка записи #140');
        Queue::assertPushed(SendIntegrationErrorAlert::class, function ($job): bool {
            foreach (['owner@example.test', 'test-client.amocrm.ru', 'записи #140', 'поле price: ожидает целое число', 'PATCH /api/v4/leads/123'] as $text) {
                $this->assertStringContainsString($text, $job->message);
            }
            $this->assertStringNotContainsString('secret-body', serialize($job));

            return true;
        });
    }

    public function test_different_processing_errors_are_not_merged_but_same_failure_is_deduplicated(): void
    {
        $notifier = app(IntegrationErrorNotifier::class);
        $notifier->report('platform', "Syntax error, unexpected '=' on line 1", operation: 'Команда: app:test');
        $notifier->report('platform', "Syntax error, unexpected '=' on line 1", operation: 'Команда: app:test');
        $notifier->report('platform', 'Call to undefined method App\\Services\\Test::missing()', operation: 'Команда: app:test');
        Queue::assertPushed(SendIntegrationErrorAlert::class, 2);
    }

    public function test_command_context_is_cleared_and_parent_command_is_restored(): void
    {
        $subscriber = app(AdminErrorSubscriber::class);
        $input = new ArrayInput([]);
        $output = new BufferedOutput();
        $subscriber->commandStarting(new CommandStarting('outer:run', $input, $output));
        $subscriber->commandStarting(new CommandStarting('inner:run', $input, $output));
        $subscriber->commandFinished(new CommandFinished('inner:run', $input, $output, 0));
        $subscriber->logged(new MessageLogged('error', 'Undefined array key private-key'));
        Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'Команда: outer:run'));
        $subscriber->commandFinished(new CommandFinished('outer:run', $input, $output, 0));
        $subscriber->logged(new MessageLogged('error', 'Undefined array key private-key'));
        Queue::assertPushed(SendIntegrationErrorAlert::class, fn ($job): bool => str_contains($job->message, 'Журнал приложения')
            && !str_contains($job->message, 'outer:run') && !str_contains($job->message, 'Тип: RuntimeException'));
    }

    #[DataProvider('safeSummaries')]
    public function test_diagnostic_only_extracts_safe_technical_facts(string $error, string $expected): void
    {
        $result = IntegrationErrorDiagnostic::describe($error);
        $this->assertSame($expected, $result['summary']);
        $this->assertStringNotContainsString('private-value', json_encode($result));
    }

    public static function safeSummaries(): array
    {
        return [
            'syntax identifier is secret' => ["Syntax error, unexpected 'private-value' on line 4", 'Ошибка синтаксиса PHP, строка 4.'],
            'array key is secret' => ['Undefined array key "private-value"', 'Обращение к отсутствующему ключу массива.'],
            'database query is secret' => ["SQLSTATE[23505] duplicate key (SQL: secret='private-value')", 'Ошибка базы данных, SQLSTATE 23505.'],
            'file path is secret' => ['Permission denied /private-value/customer.doc', 'Операция отклонена: недостаточно прав доступа.'],
            'command exit' => ['Command failed (exit 2)', 'Команда завершилась с кодом 2.'],
        ];
    }
}
