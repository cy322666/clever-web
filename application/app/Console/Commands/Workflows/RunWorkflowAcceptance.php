<?php

namespace App\Console\Commands\Workflows;

use App\Services\Workflows\Testing\WorkflowAcceptanceTelegramReporter;
use App\Services\Workflows\Testing\WorkflowReportSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class RunWorkflowAcceptance extends Command
{
    protected $signature = 'workflows:acceptance';

    protected $description = 'Реальные проверки технического amoCRM-аккаунта с отчётом в Telegram';

    public function handle(WorkflowAcceptanceTelegramReporter $reporter): int
    {
        if (!config('workflow_acceptance.enabled')) {
            $this->warn('Реальный прогон отключён: WORKFLOW_ACCEPTANCE_ENABLED=false.');
            return self::SUCCESS;
        }
        if (blank(config('workflow_acceptance.telegram.token')) || blank(config('workflow_acceptance.telegram.chat_id'))) {
            $this->error('Не настроен Telegram для результатов; реальные изменения не запускаются.');
            return self::FAILURE;
        }

        $workflowId = (int) config('workflow_acceptance.workflow_id');
        $domain = (string) config('workflow_acceptance.domain');
        if ($workflowId <= 0 || !preg_match('/^[a-z0-9][a-z0-9-]*$/D', $domain)) {
            $this->error('Не настроен точный workflow/domain технического аккаунта.');
            return self::FAILURE;
        }

        $lock = null;
        $lockAcquired = false;
        $report = [
            'schema_version' => 1, 'suite' => 'recurring_live_acceptance',
            'run_id' => 'scheduled-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)),
            'started_at' => now()->toIso8601String(), 'source_workflow_id' => $workflowId,
            'account' => ['subdomain' => $domain], 'cases' => [], 'cleanup' => [],
        ];
        $directory = rtrim((string) config('workflow_acceptance.report_directory'), '/');
        $reportPath = $directory.'/'.$report['run_id'].'.json';
        try {
            try {
                if ($directory === '' || is_link($directory)) throw new RuntimeException('Unsafe report directory');
                if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new RuntimeException('Cannot create private report directory');
                }
                chmod($directory, 0700);
                // Redis failure must still reach the private report and Telegram path.
                $lock = Cache::lock('workflow-acceptance-notified:'.$domain, 1800);
                $lockAcquired = (bool) $lock->get();
                if (!$lockAcquired) {
                    $this->warn('Предыдущий прогон ещё выполняется; второй не запущен.');
                    return self::SUCCESS;
                }
                $exitCode = $this->runProcess($workflowId, $domain, $reportPath, $directory.'/state-'.$workflowId.'.json');
                $child = is_file($reportPath) ? json_decode(file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR) : null;
                if (!is_array($child)) throw new RuntimeException('Процесс не сохранил отчёт; проверьте checkpoint перед повторным запуском.');
                $report = $child;
                if (empty($report['finished_at'])) $report['fatal_error'] = 'Прогон прерван до завершения очистки; требуется проверка checkpoint.';
                if ($exitCode !== 0 && empty($report['fatal_error']) && !$this->hasFailures($report)) {
                    $report['fatal_error'] = 'Процесс проверки завершился с кодом '.$exitCode.'.';
                }
            } catch (Throwable $error) {
                // Recover any partial report, but never turn a killed/incomplete run green.
                if (is_file($reportPath)) {
                    $partial = json_decode(file_get_contents($reportPath), true);
                    if (is_array($partial)) $report = $partial;
                }
                $report['fatal_error'] = $error->getMessage();
                $report['finished_at'] = now()->toIso8601String();
            }
            $report['counts'] = array_count_values(array_column($report['cases'] ?? [], 'status'));
            if (empty($report['cases']) && empty($report['fatal_error'])) $report['fatal_error'] = 'Ни одна проверка не выполнена.';
            $report['_redaction_secrets'] = [(string) config('workflow_acceptance.telegram.token')];
            $report = WorkflowReportSanitizer::sanitize($report);
            unset($report['_redaction_secrets']);
            if (!$this->persistReport($reportPath, $report)) $report['report_write_errors'][] = 'Не удалось сохранить отчёт на сервере.';
            $delivery = $reporter->notify($report, $reportPath);
            $report['notification'] = $delivery;
            $deliverySaved = $this->persistReport($reportPath, $report);
            $this->line('Отчёт: '.$reportPath);
            if (!($delivery['ok'] ?? false)) {
                $this->safeLog('workflow.acceptance.telegram_failed', ['report' => basename($reportPath), 'error' => $delivery['error'] ?? 'unknown']);
                $this->error('Telegram не принял отчёт. Результаты сохранены на сервере.');
                return self::FAILURE;
            }
            $this->info('Результаты реальных проверок отправлены в Telegram.');
            return !$deliverySaved || $this->hasFailures($report) ? self::FAILURE : self::SUCCESS;
        } finally {
            if ($lockAcquired) {
                try {
                    $lock->release();
                } catch (Throwable $error) {
                    // Preserve the delivered result. The lock has a bounded TTL.
                    $this->safeLog('workflow.acceptance.lock_release_failed', [
                        'report' => basename($reportPath), 'exception' => $error::class,
                    ]);
                }
            }
        }
    }

    protected function runProcess(int $workflowId, string $domain, string $reportPath, string $statePath): int
    {
        // Isolate CRM HTTP guards, auth state and signal handlers from Telegram delivery.
        $process = new Process([
            PHP_BINARY, base_path('scripts/workflow-acceptance.php'),
            '--workflow='.$workflowId, '--expected-domain='.$domain,
            '--expected-account-id='.(int) config('workflow_acceptance.amo_account_id'),
            '--report='.$reportPath, '--recurring-state='.$statePath, '--execute',
        ], base_path());
        $process->setTimeout(null);
        $process->disableOutput();
        $process->start();
        $started = microtime(true);
        $timeout = max(60, (int) config('workflow_acceptance.timeout_seconds', 900));
        while ($process->isRunning()) {
            if (microtime(true) - $started > $timeout) {
                // Give the runner's SIGTERM handler time to restore the account in finally.
                $process->signal(15);
                $grace = microtime(true) + 180;
                while ($process->isRunning() && microtime(true) < $grace) usleep(200000);
                if ($process->isRunning()) $process->stop(0, 9);
                throw new RuntimeException('Превышено время прогона; проверьте восстановление аккаунта в checkpoint.');
            }
            usleep(200000);
        }
        return $process->getExitCode() ?? 1;
    }

    private function persistReport(string $path, array $report): bool
    {
        $temporary = null;
        try {
            $json = json_encode(WorkflowReportSanitizer::sanitize($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $temporary = tempnam(dirname($path), '.report-');
            if ($temporary === false || realpath(dirname($temporary)) !== realpath(dirname($path))) throw new RuntimeException('temporary report failed');
            if (!chmod($temporary, 0600) || file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) throw new RuntimeException('write failed');
            if (!rename($temporary, $path)) throw new RuntimeException('atomic rename failed');
            $temporary = null;
            return true;
        } catch (Throwable $error) {
            // Still send failure feedback even when disk/report storage is unavailable.
            $this->safeLog('workflow.acceptance.report_write_failed', ['report' => basename($path), 'exception' => $error::class]);
            return false;
        } finally {
            if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
        }
    }

    private function hasFailures(array $report): bool
    {
        if (!empty($report['fatal_error']) || !empty($report['report_write_errors']) || empty($report['cases'])) return true;
        $executed = 0;
        foreach ($report['cases'] as $case) {
            if (!is_array($case) || !in_array($case['status'] ?? '', ['passed', 'skipped'], true)) return true;
            if ($case['status'] === 'passed' && ($case['verification']['passed'] ?? null) === false) return true;
            if ($case['status'] === 'passed') $executed++;
        }
        foreach ($report['cleanup'] ?? [] as $check) if ($check === false || (is_array($check) && ($check['ok'] ?? null) === false)) return true;
        return $executed === 0;
    }

    private function safeLog(string $message, array $context): void
    {
        try { Log::error($message, $context); } catch (Throwable) { /* Still attempt Telegram if the log disk is full. */ }
    }
}
