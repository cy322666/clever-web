<?php

namespace App\Console\Commands\Workflows;

use App\Models\Workflows\WorkflowRun;
use App\Services\Core\AlertService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Leek\FilamentWorkflows\Enums\RunStatus;
use Leek\FilamentWorkflows\Enums\StepStatus;
use Leek\FilamentWorkflows\Models\WorkflowRunStep;
use Throwable;

class FailStuckWorkflowRuns extends Command
{
    protected $signature = 'workflows:fail-stuck-runs
        {--running-after= : Сколько секунд ждать running без обновлений}
        {--pending-after= : Сколько секунд ждать pending без старта}
        {--paused-after= : Сколько секунд ждать overdue paused после scheduled_resume_at}
        {--limit=100 : Максимум запусков за один проход}
        {--dry-run : Только показать, ничего не менять}';

    protected $description = 'Закрывает зависшие выполнения процессов, которые остались активными после смерти воркера.';

    private bool $queueLookupWarningShown = false;

    public function handle(): int
    {
        if (!Schema::hasTable('workflow_runs')) {
            $this->warn('Таблица workflow_runs не найдена.');

            return self::SUCCESS;
        }

        $dryRun = (bool)$this->option('dry-run');
        $limit = max(1, (int)$this->option('limit'));
        $runningAfter = $this->secondsOption('running-after', 'stuck_running_after_seconds', 120);
        $pendingAfter = $this->secondsOption('pending-after', 'stuck_pending_after_seconds', 120);
        $pausedAfter = $this->secondsOption('paused-after', 'stuck_paused_after_seconds', 120);

        $candidates = $this->candidateRuns($runningAfter, $pendingAfter, $pausedAfter, $limit);

        if ($candidates->isEmpty()) {
            $this->info('Зависших выполнений не найдено.');

            return self::SUCCESS;
        }

        $failed = 0;
        $skippedQueued = 0;
        $failedCandidates = collect();

        foreach ($candidates as $candidate) {
            $reason = (string)$candidate->stuck_reason;
            $message = $this->failureMessage($reason, (int)$candidate->stuck_after_seconds);

            if ($this->hasQueuedWorkflowJob((int)$candidate->id)) {
                $skippedQueued++;

                if ($dryRun) {
                    $this->line(sprintf(
                        '#%d workflow=%d status=%s skipped=queued_job_exists',
                        $candidate->id,
                        $candidate->workflow_id,
                        $candidate->status,
                    ));
                }

                continue;
            }

            if ($dryRun) {
                $this->line(sprintf(
                    '#%d workflow=%d status=%s reason=%s threshold=%s сек.',
                    $candidate->id,
                    $candidate->workflow_id,
                    $candidate->status,
                    $reason,
                    $candidate->stuck_after_seconds,
                ));

                continue;
            }

            if ($this->failRun((int)$candidate->id, $message, $reason)) {
                $failed++;
                $failedCandidates->push($candidate);
            }
        }

        if (!$dryRun && $failed > 0) {
            $this->sendAlert($failed, $failedCandidates);
        }

        $stuckCount = max(0, $candidates->count() - $skippedQueued);

        $this->info(sprintf(
            '%s: %d.',
            $dryRun ? 'Найдено зависших выполнений' : 'Закрыто зависших выполнений',
            $dryRun ? $stuckCount : $failed,
        ));

        if ($skippedQueued > 0) {
            $this->info("Пропущено выполнений с живой задачей в очереди: {$skippedQueued}.");
        }

        return self::SUCCESS;
    }

    private function secondsOption(string $option, string $configKey, int $default): int
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            $value = config("filament-workflows.execution.{$configKey}", $default);
        }

        return max(30, (int)$value);
    }

    /**
     * @return Collection<int, object{id: int, workflow_id: int, status: string, stuck_reason: string, stuck_after_seconds: int}>
     */
    private function candidateRuns(int $runningAfter, int $pendingAfter, int $pausedAfter, int $limit): Collection
    {
        $runningThreshold = now()->subSeconds($runningAfter);
        $pendingThreshold = now()->subSeconds($pendingAfter);
        $pausedThreshold = now()->subSeconds($pausedAfter);

        return DB::table('workflow_runs')
            ->select(['id', 'workflow_id', 'status'])
            ->selectRaw(
                "case
                    when status = ? then ?
                    when status = ? then ?
                    else ?
                end as stuck_reason",
                [
                    RunStatus::RUNNING->value,
                    'running_timeout',
                    RunStatus::PENDING->value,
                    'pending_timeout',
                    'paused_resume_timeout',
                ],
            )
            ->selectRaw(
                "case
                    when status = ? then ?
                    when status = ? then ?
                    else ?
                end as stuck_after_seconds",
                [
                    RunStatus::RUNNING->value,
                    $runningAfter,
                    RunStatus::PENDING->value,
                    $pendingAfter,
                    $pausedAfter,
                ],
            )
            ->where(function ($query) use ($runningThreshold, $pendingThreshold, $pausedThreshold): void {
                $query
                    ->where(function ($query) use ($runningThreshold): void {
                        $query
                            ->where('status', RunStatus::RUNNING->value)
                            ->where('updated_at', '<', $runningThreshold);
                    })
                    ->orWhere(function ($query) use ($pendingThreshold): void {
                        $query
                            ->where('status', RunStatus::PENDING->value)
                            ->where('updated_at', '<', $pendingThreshold);
                    })
                    ->orWhere(function ($query) use ($pausedThreshold): void {
                        $query
                            ->where('status', RunStatus::PAUSED->value)
                            ->whereNotNull('scheduled_resume_at')
                            ->where('scheduled_resume_at', '<', $pausedThreshold);
                    });
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();
    }

    private function hasQueuedWorkflowJob(int $runId): bool
    {
        $queueConnection = $this->workflowQueueConnection();

        if ((string)config("queue.connections.{$queueConnection}.driver") !== 'redis') {
            return false;
        }

        $queue = config('filament-workflows.queue.name', 'workflows');

        if (!is_string($queue) || $queue === '') {
            $queue = 'workflows';
        }

        $needles = [
            "workflow_run:{$runId}",
            'workflowRunId";i:' . $runId,
            'workflowRunId";s:',
        ];

        try {
            $connectionName = config("queue.connections.{$queueConnection}.connection", 'default');
            $redis = Redis::connection(is_string($connectionName) ? $connectionName : 'default');

            foreach ($this->workflowQueueKeys($queue) as $key => $type) {
                $payloads = $type === 'zset'
                    ? $redis->zrange($key, 0, -1)
                    : $redis->lrange($key, 0, -1);

                foreach ((array)$payloads as $payload) {
                    if (!is_string($payload)) {
                        continue;
                    }

                    if (str_contains($payload, $needles[0]) || str_contains($payload, $needles[1])) {
                        return true;
                    }

                    if (str_contains($payload, $needles[2]) && str_contains($payload, ':' . strlen((string)$runId) . ':"' . $runId . '";')) {
                        return true;
                    }
                }
            }
        } catch (Throwable $exception) {
            if (!$this->queueLookupWarningShown) {
                $this->warn('Не удалось проверить Redis-очередь workflows: ' . $exception->getMessage());
                $this->queueLookupWarningShown = true;
            }
        }

        return false;
    }

    private function workflowQueueConnection(): string
    {
        $connection = config('filament-workflows.queue.connection') ?: config('queue.default', 'redis');

        return is_string($connection) && $connection !== '' ? $connection : 'redis';
    }

    /**
     * @return array<string, string>
     */
    private function workflowQueueKeys(string $queue): array
    {
        return [
            "queues:{$queue}" => 'list',
            "queues:{$queue}:delayed" => 'zset',
            "queues:{$queue}:reserved" => 'zset',
        ];
    }

    private function failRun(int $runId, string $message, string $reason): bool
    {
        return DB::transaction(function () use ($runId, $message, $reason): bool {
            /** @var WorkflowRun|null $run */
            $run = WorkflowRun::query()
                ->lockForUpdate()
                ->find($runId);

            if (!$run || $run->isTerminal()) {
                return false;
            }

            $run->markFailed($message);
            $this->failActiveSteps($run->id, $message, $reason);

            return true;
        });
    }

    private function failActiveSteps(int $runId, string $message, string $reason): void
    {
        if (!Schema::hasTable('workflow_run_steps')) {
            return;
        }

        $stepModelClass = config('filament-workflows.models.workflow_run_step', WorkflowRunStep::class);

        if (!is_string($stepModelClass) || !is_subclass_of($stepModelClass, Model::class)) {
            return;
        }

        $stepModelClass::query()
            ->where('workflow_run_id', $runId)
            ->whereIn('status', [
                StepStatus::PENDING->value,
                StepStatus::RUNNING->value,
            ])
            ->update([
                'status' => StepStatus::FAILED->value,
                'error_message' => $message,
                'output_data' => DB::raw($this->jsonObject([
                    'failed_by' => 'workflow_watchdog',
                    'reason' => $reason,
                ])),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param array<string, string> $payload
     */
    private function jsonObject(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (DB::connection()->getDriverName() === 'pgsql') {
            return "'" . str_replace("'", "''", (string)$json) . "'::json";
        }

        return "'" . str_replace("'", "''", (string)$json) . "'";
    }

    private function failureMessage(string $reason, int $seconds): string
    {
        return match ($reason) {
            'pending_timeout' => "Выполнение зависло в очереди и не стартовало за {$seconds} сек.",
            'paused_resume_timeout' => "Выполнение не возобновилось после паузы за {$seconds} сек.",
            default => "Выполнение зависло: не было обновлений {$seconds} сек.",
        };
    }

    /**
     * @param Collection<int, object> $candidates
     */
    private function sendAlert(int $failed, Collection $candidates): void
    {
        try {
            AlertService::warning(
                title: 'Процессы: зависшие выполнения закрыты',
                message: "Watchdog закрыл {$failed} зависших выполнений процессов.",
                context: [
                    'count' => $failed,
                    'sample_run_ids' => $candidates->pluck('id')->take(10)->values()->all(),
                ],
                dedupeKey: 'workflow:stuck-runs:' . intdiv(now()->timestamp, 300),
                ttlSeconds: 300,
            );
        } catch (Throwable $exception) {
            $this->warn('Не удалось отправить уведомление: ' . $exception->getMessage());
        }
    }
}
