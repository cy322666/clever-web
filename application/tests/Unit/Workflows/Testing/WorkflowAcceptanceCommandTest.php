<?php

namespace Tests\Unit\Workflows\Testing;

use App\Console\Commands\Workflows\RunWorkflowAcceptance;
use App\Services\Workflows\Testing\WorkflowAcceptanceTelegramReporter;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class WorkflowAcceptanceCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/acceptance-command-'.bin2hex(random_bytes(6));
        config([
            'cache.default' => 'array', 'cache.schedule_store' => 'array',
            'workflow_acceptance.enabled' => true, 'workflow_acceptance.workflow_id' => 15,
            'workflow_acceptance.domain' => 'contract-only',
            'workflow_acceptance.report_directory' => $this->directory,
            'workflow_acceptance.telegram.token' => '123:contract-only-secret',
            'workflow_acceptance.telegram.chat_id' => '1',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 123]])]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*.json') ?: [] as $file) unlink($file);
        if (is_dir($this->directory)) rmdir($this->directory);
        parent::tearDown();
    }

    #[DataProvider('outcomes')]
    public function test_reports_actual_outcome_without_any_live_crm_calls(array $payload, int $exit, int $expected): void
    {
        $command = $this->command();
        $command->expects($this->once())->method('runProcess')->willReturnCallback(function ($workflow, $domain, $path, $state) use ($payload, $exit) {
            $this->assertSame(15, $workflow);
            $this->assertSame('contract-only', $domain);
            $this->assertSame($this->directory.'/state-15.json', $state);
            file_put_contents($path, json_encode($payload));
            return $exit;
        });
        $this->assertSame($expected, $command->handle(new WorkflowAcceptanceTelegramReporter));
        Http::assertSentCount(1);
        $report = json_decode(file_get_contents(glob($this->directory.'/*.json')[0]), true);
        $this->assertTrue($report['notification']['ok']);
        $this->assertSame(0600, fileperms(glob($this->directory.'/*.json')[0]) & 0777);
    }

    public static function outcomes(): array
    {
        $base = ['run_id' => 'test-run', 'account' => ['subdomain' => 'contract-only'],
            'started_at' => '2026-09-20T01:00:00Z', 'finished_at' => '2026-09-20T01:01:00Z',
            'cases' => [['id' => 'read', 'status' => 'passed']], 'cleanup' => []];
        $incomplete = $base;
        unset($incomplete['finished_at']);
        return [
            'pass' => [$base, 0, 0],
            'known capability skip retains raw failed verification' => [array_replace($base, ['cases' => [['id' => 'read', 'status' => 'passed'], ['id' => 'disabled', 'status' => 'skipped', 'verification' => ['passed' => false]]]]), 0, 0],
            'node failed' => [array_replace($base, ['cases' => [['id' => 'update', 'status' => 'failed', 'error' => 'HTTP 500']]]), 1, 1],
            'cleanup failed' => [array_replace($base, ['cleanup' => ['restore_contact' => ['ok' => false]]]), 1, 1],
            'partial' => [$incomplete, 0, 1],
            'zero cases' => [array_replace($base, ['cases' => []]), 0, 1],
            'only skips' => [array_replace($base, ['cases' => [['id' => 'missing', 'status' => 'skipped']]]), 0, 1],
            'unknown case status' => [array_replace($base, ['cases' => [['id' => 'missing', 'status' => 'pending']]]), 0, 1],
            'raw false cleanup' => [array_replace($base, ['cleanup' => ['restore' => false]]), 0, 1],
            'unexplained process exit' => [$base, 7, 1],
            'fatal' => [$base + ['fatal_error' => 'OAuth unavailable'], 1, 1],
        ];
    }

    public function test_disabled_or_missing_telegram_does_not_start_mutations(): void
    {
        $command = $this->command();
        $command->expects($this->never())->method('runProcess');
        config(['workflow_acceptance.enabled' => false]);
        $this->assertSame(0, $command->handle(new WorkflowAcceptanceTelegramReporter));
        config(['workflow_acceptance.enabled' => true, 'workflow_acceptance.telegram.token' => '']);
        $this->assertSame(1, $command->handle(new WorkflowAcceptanceTelegramReporter));
        Http::assertNothingSent();
    }

    public function test_process_exception_is_reported_in_telegram_not_silently_lost(): void
    {
        $command = $this->command();
        $command->method('runProcess')->willThrowException(new \RuntimeException('Test process could not start'));
        $this->assertSame(1, $command->handle(new WorkflowAcceptanceTelegramReporter));
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Test process could not start'));
    }

    #[DataProvider('cacheFailureStages')]
    public function test_cache_failure_reports_error_without_starting_child_or_releasing_unacquired_lock(string $stage): void
    {
        $command = $this->command();
        $command->expects($this->never())->method('runProcess');
        $lockCall = Cache::shouldReceive('lock')->once()->with('workflow-acceptance-notified:contract-only', 1800);
        if ($stage === 'create') {
            $lockCall->andThrow(new \RuntimeException('Redis connection unavailable'));
        } else {
            $lock = \Mockery::mock();
            $lock->shouldReceive('get')->once()->andThrow(new \RuntimeException('Redis connection unavailable'));
            $lock->shouldNotReceive('release');
            $lockCall->andReturn($lock);
        }

        $this->assertSame(1, $command->handle(new WorkflowAcceptanceTelegramReporter));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_starts_with($request['text'], '🔴') && str_contains($request['text'], 'Redis connection unavailable'));
        $files = glob($this->directory.'/*.json');
        $this->assertCount(1, $files);
        $report = json_decode(file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Redis connection unavailable', $report['fatal_error']);
        $this->assertSame([], $report['cases']);
        $this->assertTrue($report['notification']['ok']);
        $this->assertSame(0600, fileperms($files[0]) & 0777);
    }

    public static function cacheFailureStages(): array
    {
        return [['create'], ['acquire']];
    }

    public function test_busy_lock_is_never_released_and_does_not_start_child(): void
    {
        $command = $this->command();
        $command->expects($this->never())->method('runProcess');
        $lock = \Mockery::mock();
        $lock->shouldReceive('get')->once()->andReturn(false);
        $lock->shouldNotReceive('release');
        Cache::shouldReceive('lock')->once()->andReturn($lock);

        $this->assertSame(0, $command->handle(new WorkflowAcceptanceTelegramReporter));
        Http::assertNothingSent();
    }

    #[DataProvider('releaseOutcomes')]
    public function test_release_exception_never_prevents_delivery_or_changes_run_result(string $status, int $exitCode): void
    {
        Log::spy();
        $command = $this->command();
        $lock = \Mockery::mock();
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('release')->once()->andThrow(new \RuntimeException('Redis release secret must not be logged'));
        Cache::shouldReceive('lock')->once()->andReturn($lock);
        $command->expects($this->once())->method('runProcess')->willReturnCallback(function ($workflow, $domain, $path) use ($status, $exitCode) {
            file_put_contents($path, json_encode([
                'started_at' => '2026-09-20T01:00:00Z', 'finished_at' => '2026-09-20T01:01:00Z',
                'cases' => [['id' => 'query_leads', 'status' => $status]], 'cleanup' => [],
            ]));
            return $exitCode;
        });

        $this->assertSame($exitCode, $command->handle(new WorkflowAcceptanceTelegramReporter));
        Http::assertSentCount(1);
        Log::shouldHaveReceived('error')->once()->with('workflow.acceptance.lock_release_failed', \Mockery::on(
            fn ($context) => $context['exception'] === \RuntimeException::class
                && !str_contains(json_encode($context), 'secret must not be logged')
        ));
    }

    public static function releaseOutcomes(): array
    {
        return [['passed', 0], ['failed', 1]];
    }

    public function test_daily_schedule_is_opt_in_kaliningrad_and_non_overlapping(): void
    {
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $schedule = new Schedule;
        (new \ReflectionMethod($kernel, 'schedule'))->invoke($kernel, $schedule);
        $events = array_values(array_filter($schedule->events(), fn ($e) => str_contains($e->command ?? '', 'workflows:acceptance')));
        $this->assertCount(1, $events);
        $this->assertSame('0 3 * * *', $events[0]->expression);
        $this->assertSame('Europe/Kaliningrad', $events[0]->timezone);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertTrue($events[0]->runInBackground);
        config(['workflow_acceptance.enabled' => false]);
        $disabled = new Schedule;
        (new \ReflectionMethod($kernel, 'schedule'))->invoke($kernel, $disabled);
        $this->assertCount(0, array_filter($disabled->events(), fn ($e) => str_contains($e->command ?? '', 'workflows:acceptance')));
    }

    private function command(): RunWorkflowAcceptance
    {
        $command = $this->getMockBuilder(RunWorkflowAcceptance::class)->onlyMethods(['runProcess'])->getMock();
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        return $command;
    }
}
