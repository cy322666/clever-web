<?php

namespace Tests\Unit\Workflows\Testing;

use App\Console\Commands\Workflows\RunWorkflowAcceptance;
use App\Listeners\AdminErrorSubscriber;
use App\Services\Workflows\Testing\WorkflowAcceptanceDiagnostics;
use App\Services\Workflows\Testing\WorkflowAcceptanceTelegramReporter;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowAcceptanceDiagnosticsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        $this->directory = sys_get_temp_dir().'/acceptance-diagnostics-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        DB::table('workflows')->insert(['id' => 15, 'user_id' => 1, 'name' => 'QA', 'trigger_type' => 'manual']);
        DB::table('accounts')->insert(['id' => 134, 'user_id' => 1, 'widget' => 'workflows', 'active' => false]);
        config([
            'workflow_acceptance.enabled' => true, 'workflow_acceptance.workflow_id' => 15,
            'workflow_acceptance.domain' => 'qa-account', 'workflow_acceptance.report_directory' => $this->directory,
            'workflow_acceptance.telegram.token' => '12345:synthetic-token', 'workflow_acceptance.telegram.chat_id' => '1',
            'widget_lifecycle.telegram.token' => '12345:synthetic-token', 'widget_lifecycle.telegram.chat_id' => '1',
            'alerts.enabled' => true, 'alerts.integration_errors.enabled' => true, 'alerts.cache_store' => 'array',
            'cache.default' => 'array',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            if (is_dir($file)) {
                foreach (glob($file.'/*') ?: [] as $fixture) unlink($fixture);
                rmdir($file);
            } else {
                unlink($file);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_disconnected_slot_retains_exact_owner_without_borrowing_another_widget(): void
    {
        DB::table('accounts')->insert(['id' => 135, 'user_id' => 2, 'widget' => 'workflows', 'active' => true,
            'subdomain' => 'another-account', 'refresh_token' => 'private-foreign-value']);
        $context = app(WorkflowAcceptanceDiagnostics::class)->scheduledFailure();
        $this->assertSame(1, $context['user_id']);
        $this->assertSame('workflow@example.test', $context['owner_email']);
        $this->assertSame(134, $context['account']['platform_account_id']);
        $this->assertSame('amo_disconnected', $context['reason']);
        $this->assertStringNotContainsString('private-foreign-value', json_encode($context));
        Http::assertNothingSent();
    }

    public function test_real_command_rejects_disconnected_account_before_spawning_runner_and_reports_owner(): void
    {
        $before = DB::table('workflows')->where('id', 15)->first();
        $command = new RunWorkflowAcceptance;
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        $this->assertSame(1, $command->handle(new WorkflowAcceptanceTelegramReporter));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Подключение amoCRM отключено')
            && str_contains($request['text'], 'Клиент: workflow@example.test'));
        $report = json_decode(file_get_contents(glob($this->directory.'/scheduled-*.json')[0]), true);
        $this->assertSame([], $report['cases']);
        $this->assertSame('amoCRM connection is disconnected', $report['fatal_error']);
        $this->assertEquals($before, DB::table('workflows')->where('id', 15)->first());
    }

    public function test_scheduler_alert_contains_specific_error_email_widget_and_configured_crm(): void
    {
        $event = app(Schedule::class)->command('workflows:acceptance');
        $event->exitCode = 1;
        (new AdminErrorSubscriber)->scheduled(new ScheduledBackgroundTaskFinished($event));
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $text = $request['text'];
            return str_contains($text, 'Клиент: workflow@example.test')
                && str_contains($text, 'Виджет: Потоки')
                && str_contains($text, 'CRM: qa-account.amocrm.ru')
                && str_contains($text, 'Подключение amoCRM отключено')
                && ! str_contains($text, 'не определ') && ! str_contains($text, 'Ошибка платформы');
        });
    }

    public function test_missing_workflow_slot_does_not_label_another_widgets_crm_as_affected(): void
    {
        DB::table('accounts')->where('id', 134)->delete();
        DB::table('accounts')->insert(['id' => 135, 'user_id' => 1, 'widget' => 'finder', 'active' => true,
            'subdomain' => 'unrelated-crm', 'refresh_token' => 'private-foreign-value']);
        $event = app(Schedule::class)->command('workflows:acceptance');
        $event->exitCode = 1;
        (new AdminErrorSubscriber)->scheduled(new ScheduledBackgroundTaskFinished($event));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Клиент: workflow@example.test')
            && str_contains($request['text'], 'CRM: qa-account.amocrm.ru')
            && ! str_contains($request['text'], 'unrelated-crm'));
    }

    #[DataProvider('errors')]
    public function test_causes_are_distinct_and_do_not_include_raw_exception_secrets(string $error, string $reason): void
    {
        $this->assertSame($reason, WorkflowAcceptanceDiagnostics::reason($error));
        $this->assertStringNotContainsString('private-value', WorkflowAcceptanceDiagnostics::REASONS[$reason]);
    }

    public static function errors(): array
    {
        return [
            ['amoCRM connection is disconnected', 'amo_disconnected'],
            ['401 invalid_token Bearer private-value', 'amo_auth'],
            ['invalid_grant: private-value', 'amo_auth'],
            ['403 Forbidden: private-value', 'amo_forbidden'],
            ['429 Too Many Requests', 'amo_rate_limit'],
            ['500 server error', 'amo_server'],
            ['cURL error 28: timeout', 'amo_connection'],
            ['Redis connection refused', 'acceptance_cache'],
            ['SQLSTATE connection refused', 'acceptance_database'],
            ['Explicit account scope does not match', 'amo_scope'],
            ['Workflow OAuth credentials do not match', 'amo_config'],
            ['Permission denied: private-value', 'acceptance_storage'],
            ['Превышено время прогона', 'acceptance_timeout'],
        ];
    }

    public function test_fresh_report_preserves_actual_api_auth_failure_in_scheduler_alert(): void
    {
        $this->writeReport(['fatal_error' => 'amoCRM GET returned 401: Bearer private-value']);
        $context = app(WorkflowAcceptanceDiagnostics::class)->scheduledFailure();
        $this->assertSame('amo_auth', $context['reason']);
        $event = app(Schedule::class)->command('workflows:acceptance');
        $event->exitCode = 1;
        (new AdminErrorSubscriber)->scheduled(new ScheduledBackgroundTaskFinished($event));
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Ошибка авторизации в amoCRM')
            && str_contains($request['text'], 'workflow@example.test') && ! str_contains($request['text'], 'private-value'));
    }

    public function test_stale_and_foreign_reports_cannot_supply_a_failure_for_this_run(): void
    {
        $this->writeReport(['finished_at' => now()->subDay()->toIso8601String(), 'fatal_error' => '401 unauthorized']);
        $this->assertSame('amo_disconnected', app(WorkflowAcceptanceDiagnostics::class)->scheduledFailure()['reason']);
        $this->writeReport(['source_workflow_id' => 999, 'fatal_error' => '401 unauthorized']);
        $this->assertSame('amo_disconnected', app(WorkflowAcceptanceDiagnostics::class)->scheduledFailure()['reason']);
    }

    public function test_report_shows_only_owner_email_and_keeps_customer_details_hidden(): void
    {
        $message = (new WorkflowAcceptanceTelegramReporter)->format([
            'owner_email' => 'workflow@example.test', 'account' => ['subdomain' => 'qa-account'],
            'fatal_error' => '401: customer@example.test Bearer private-value',
            'cases' => [], 'finished_at' => now()->toIso8601String(),
        ], 'report.json');
        $this->assertStringContainsString('Клиент: workflow@example.test', $message);
        $this->assertStringContainsString('Ошибка авторизации в amoCRM', $message);
        $this->assertStringNotContainsString('customer@example.test', $message);
        $this->assertStringNotContainsString('private-value', $message);
    }

    public function test_successful_schedule_does_not_emit_error(): void
    {
        $event = app(Schedule::class)->command('workflows:acceptance');
        $event->exitCode = 0;
        (new AdminErrorSubscriber)->scheduled(new ScheduledBackgroundTaskFinished($event));
        Http::assertNothingSent();
    }

    public function test_cli_preserves_early_api_failure_in_private_report_without_running_crm_actions(): void
    {
        $stubs = $this->directory.'/stubs';
        mkdir($stubs, 0700);
        file_put_contents($stubs.'/WorkflowReportSanitizer.php', '<?php require_once '.var_export(app_path('Services/Workflows/Testing/WorkflowReportSanitizer.php'), true).';');
        file_put_contents($stubs.'/WorkflowLiveAcceptance.php', <<<'PHP'
<?php
namespace App\Services\Workflows\Testing;
class WorkflowLiveAcceptance {
    public function runRecurring(...$arguments) {
        throw new \RuntimeException('amoCRM API returned 401: Bearer private-test-value');
    }
}
PHP);
        $reportPath = $this->directory.'/child.json';
        $process = new Process([PHP_BINARY, base_path('scripts/workflow-acceptance.php'),
            '--workflow=15', '--expected-domain=qa-account', '--report='.$reportPath,
            '--recurring-state='.$this->directory.'/checkpoint.json', '--execute',
        ], base_path(), [
            'WORKFLOW_TESTING_SOURCE' => $stubs, 'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
            'LOG_CHANNEL' => 'stderr', 'TELESCOPE_ENABLED' => 'false',
        ]);
        $process->setTimeout(20);
        $this->assertSame(1, $process->run(), $process->getErrorOutput());
        $this->assertFileExists($reportPath);
        $report = json_decode(file_get_contents($reportPath), true);
        $this->assertStringContainsString('401', $report['fatal_error']);
        $this->assertStringNotContainsString('private-test-value', file_get_contents($reportPath));
        $this->assertSame([], $report['cases']);
        $this->assertSame(0600, fileperms($reportPath) & 0777);
        $this->assertFileDoesNotExist($this->directory.'/checkpoint.json');
    }

    private function writeReport(array $overrides): void
    {
        file_put_contents($this->directory.'/scheduled-test.json', json_encode(array_replace([
            'source_workflow_id' => 15, 'account' => ['subdomain' => 'qa-account'],
            'finished_at' => now()->toIso8601String(), 'cases' => [],
        ], $overrides)));
    }
}
