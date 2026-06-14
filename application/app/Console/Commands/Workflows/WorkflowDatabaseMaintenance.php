<?php

namespace App\Console\Commands\Workflows;

use App\Models\Workflows\WorkflowRun;
use App\Services\Workflows\WorkflowRunEntityIndexService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Leek\FilamentWorkflows\Models\WorkflowRunStep;
use Throwable;

class WorkflowDatabaseMaintenance extends Command
{
    protected $signature = 'workflows:db-maintenance
        {--days=45 : За сколько последних дней обновлять служебные индексы}
        {--raw-days= : Сколько дней хранить тяжелые JSON-данные запусков}
        {--run-days= : Сколько дней хранить детальные строки запусков и шагов}
        {--chunk=500 : Размер пачки}
        {--skip-vacuum : Не выполнять VACUUM, только ANALYZE/служебные индексы}';

    protected $description = 'Ночное обслуживание таблиц процессов: индексы, статистика, очистка тяжелых данных.';

    public function handle(WorkflowRunEntityIndexService $entityIndexer): int
    {
        if (!Schema::hasTable('workflow_runs')) {
            $this->warn('Таблица workflow_runs не найдена.');

            return self::SUCCESS;
        }

        $days = max(1, (int)$this->option('days'));
        $rawDays = max(1, (int)($this->option('raw-days') ?: config('filament-workflows.execution.raw_retention_days', 7)));
        $runDays = max($rawDays, (int)($this->option('run-days') ?: config('filament-workflows.execution.run_retention_days', 31)));
        $chunk = max(50, (int)$this->option('chunk'));

        [$runsIndexed, $stepsIndexed] = $this->refreshEntityIndex($entityIndexer, $days, $chunk);
        $usageAggregated = $this->aggregateMonthlyUsage($rawDays, $chunk);
        [$runsScrubbed, $stepsScrubbed] = $this->scrubRawWorkflowData($rawDays, $chunk);
        $runsPruned = $this->pruneDetailedWorkflowRuns($runDays, $chunk);
        $mutationsPruned = $this->pruneExpiredMutations();
        $tables = $this->workflowTables();
        $optimizedTables = $this->refreshDatabaseStatistics($tables, (bool)$this->option('skip-vacuum'));

        $this->info(sprintf(
            'Workflow DB maintenance complete. Entity index: runs=%d, steps=%d. Usage aggregated=%d. Raw scrubbed: runs=%d, steps=%d. Detailed runs pruned=%d. Mutations pruned=%d. DB tables refreshed: %d.',
            $runsIndexed,
            $stepsIndexed,
            $usageAggregated,
            $runsScrubbed,
            $stepsScrubbed,
            $runsPruned,
            $mutationsPruned,
            $optimizedTables,
        ));

        return self::SUCCESS;
    }

    private function aggregateMonthlyUsage(int $rawDays, int $chunk): int
    {
        if (!Schema::hasTable('workflow_usage_months') || !Schema::hasColumn('workflow_runs', 'usage_recorded_at')) {
            return 0;
        }

        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

        if (!is_string($tenantColumn) || !Schema::hasColumn('workflow_runs', $tenantColumn)) {
            return 0;
        }

        $cutoff = now()->subDays($rawDays);
        $aggregated = 0;

        WorkflowRun::query()
            ->select(['id', 'workflow_id', 'status', 'created_at', $tenantColumn])
            ->whereNull('usage_recorded_at')
            ->where('created_at', '<', $cutoff)
            ->chunkById($chunk, function ($runs) use (&$aggregated, $tenantColumn): void {
                $ids = $runs->pluck('id')->map(fn($id): int => (int)$id)->all();

                if ($ids === []) {
                    return;
                }

                $stepCounts = DB::table('workflow_run_steps')
                    ->select('workflow_run_id')
                    ->selectRaw('count(*) as aggregate')
                    ->whereIn('workflow_run_id', $ids)
                    ->groupBy('workflow_run_id')
                    ->pluck('aggregate', 'workflow_run_id');

                $groups = [];

                foreach ($runs as $run) {
                    $userId = (int)$run->getAttribute($tenantColumn);
                    $workflowId = (int)$run->getAttribute('workflow_id');

                    if ($userId <= 0 || $workflowId <= 0) {
                        continue;
                    }

                    $periodMonth = $run->created_at->copy()->startOfMonth()->toDateString();
                    $key = implode('|', [$userId, $workflowId, $periodMonth]);
                    $status = $run->status instanceof \BackedEnum ? $run->status->value : (string)$run->status;
                    $steps = (int)($stepCounts[(int)$run->id] ?? 0);

                    $groups[$key] ??= [
                        'user_id' => $userId,
                        'workflow_id' => $workflowId,
                        'period_month' => $periodMonth,
                        'runs_count' => 0,
                        'steps_count' => 0,
                        'actions_count' => 0,
                        'completed_runs_count' => 0,
                        'failed_runs_count' => 0,
                    ];

                    $groups[$key]['runs_count']++;
                    $groups[$key]['steps_count'] += $steps;
                    $groups[$key]['actions_count'] += $steps;
                    $groups[$key]['completed_runs_count'] += $status === 'completed' ? 1 : 0;
                    $groups[$key]['failed_runs_count'] += $status === 'failed' ? 1 : 0;
                }

                foreach ($groups as $group) {
                    $this->incrementUsageMonth($group);
                    $aggregated += (int)$group['runs_count'];
                }

                WorkflowRun::query()
                    ->whereKey($ids)
                    ->update(['usage_recorded_at' => now()]);
            });

        return $aggregated;
    }

    /**
     * @param array{
     *     user_id: int,
     *     workflow_id: int,
     *     period_month: string,
     *     runs_count: int,
     *     steps_count: int,
     *     actions_count: int,
     *     completed_runs_count: int,
     *     failed_runs_count: int
     * } $group
     */
    private function incrementUsageMonth(array $group): void
    {
        $key = [
            'user_id' => $group['user_id'],
            'workflow_id' => $group['workflow_id'],
            'period_month' => $group['period_month'],
        ];

        if (!DB::table('workflow_usage_months')->where($key)->exists()) {
            DB::table('workflow_usage_months')->insert([
                ...$key,
                'runs_count' => 0,
                'steps_count' => 0,
                'actions_count' => 0,
                'completed_runs_count' => 0,
                'failed_runs_count' => 0,
                'last_aggregated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('workflow_usage_months')
            ->where($key)
            ->update([
                'runs_count' => DB::raw('runs_count + ' . (int)$group['runs_count']),
                'steps_count' => DB::raw('steps_count + ' . (int)$group['steps_count']),
                'actions_count' => DB::raw('actions_count + ' . (int)$group['actions_count']),
                'completed_runs_count' => DB::raw('completed_runs_count + ' . (int)$group['completed_runs_count']),
                'failed_runs_count' => DB::raw('failed_runs_count + ' . (int)$group['failed_runs_count']),
                'last_aggregated_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function scrubRawWorkflowData(int $rawDays, int $chunk): array
    {
        $cutoff = now()->subDays($rawDays);
        $runsScrubbed = 0;
        $stepsScrubbed = 0;

        DB::table('workflow_runs')
            ->select('id')
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('context_data')
            ->orderBy('id')
            ->chunkById($chunk, function ($runs) use (&$runsScrubbed): void {
                $ids = $runs->pluck('id')->map(fn($id): int => (int)$id)->all();

                if ($ids === []) {
                    return;
                }

                $runsScrubbed += DB::table('workflow_runs')
                    ->whereIn('id', $ids)
                    ->update([
                        'context_data' => null,
                        'updated_at' => now(),
                    ]);
            });

        if (Schema::hasTable('workflow_run_steps')) {
            DB::table('workflow_run_steps')
                ->select('id')
                ->where('created_at', '<', $cutoff)
                ->where(fn($query) => $query
                    ->whereNotNull('input_data')
                    ->orWhereNotNull('output_data'))
                ->orderBy('id')
                ->chunkById($chunk, function ($steps) use (&$stepsScrubbed): void {
                    $ids = $steps->pluck('id')->map(fn($id): int => (int)$id)->all();

                    if ($ids === []) {
                        return;
                    }

                    $stepsScrubbed += DB::table('workflow_run_steps')
                        ->whereIn('id', $ids)
                        ->update([
                            'input_data' => null,
                            'output_data' => null,
                            'updated_at' => now(),
                        ]);
                });
        }

        return [$runsScrubbed, $stepsScrubbed];
    }

    private function pruneDetailedWorkflowRuns(int $runDays, int $chunk): int
    {
        if (!Schema::hasColumn('workflow_runs', 'usage_recorded_at')) {
            return 0;
        }

        $cutoff = now()->subDays($runDays);
        $pruned = 0;

        DB::table('workflow_runs')
            ->select('id')
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('usage_recorded_at')
            ->whereNotIn('status', ['running', 'pending', 'paused'])
            ->orderBy('id')
            ->chunkById($chunk, function ($runs) use (&$pruned): void {
                $ids = $runs->pluck('id')->map(fn($id): int => (int)$id)->all();

                if ($ids === []) {
                    return;
                }

                $pruned += DB::table('workflow_runs')
                    ->whereIn('id', $ids)
                    ->delete();
            });

        return $pruned;
    }

    private function pruneExpiredMutations(): int
    {
        if (!Schema::hasTable('workflow_amo_crm_mutations')) {
            return 0;
        }

        return DB::table('workflow_amo_crm_mutations')
            ->where('expires_at', '<', now())
            ->delete();
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function refreshEntityIndex(WorkflowRunEntityIndexService $indexer, int $days, int $chunk): array
    {
        if (!Schema::hasTable('workflow_run_entities')) {
            $this->warn('workflow_run_entities отсутствует, индекс сущностей пропущен.');

            return [0, 0];
        }

        $since = now()->subDays($days);
        $runsIndexed = 0;
        $stepsIndexed = 0;
        $runColumns = $this->workflowRunSelectColumns();

        WorkflowRun::query()
            ->select($runColumns)
            ->where('updated_at', '>=', $since)
            ->whereNotNull('context_data')
            ->chunkById($chunk, function ($runs) use ($indexer, &$runsIndexed): void {
                foreach ($runs as $run) {
                    $indexer->indexRun($run);
                    $runsIndexed++;
                }
            });

        $stepModelClass = config('filament-workflows.models.workflow_run_step', WorkflowRunStep::class);

        if (!is_string($stepModelClass) || !is_subclass_of($stepModelClass, Model::class)) {
            $this->warn('Модель шагов процесса не найдена, индекс шагов пропущен.');

            return [$runsIndexed, $stepsIndexed];
        }

        $stepModelClass::query()
            ->with(['workflowRun:' . implode(',', $runColumns)])
            ->where('updated_at', '>=', $since)
            ->where(fn($query) => $query
                ->whereNotNull('input_data')
                ->orWhereNotNull('output_data'))
            ->chunkById($chunk, function ($steps) use ($indexer, &$stepsIndexed): void {
                foreach ($steps as $step) {
                    $indexer->indexStep($step);
                    $stepsIndexed++;
                }
            });

        return [$runsIndexed, $stepsIndexed];
    }

    /**
     * @return array<int, string>
     */
    private function workflowRunSelectColumns(): array
    {
        $columns = ['id', 'workflow_id', 'context_data', 'updated_at'];
        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

        if (is_string($tenantColumn) && Schema::hasColumn('workflow_runs', $tenantColumn)) {
            $columns[] = $tenantColumn;
        }

        return array_values(array_unique($columns));
    }

    /**
     * @return array<int, string>
     */
    private function workflowTables(): array
    {
        return array_values(array_filter([
            'workflows',
            'workflow_runs',
            'workflow_run_steps',
            'workflow_metrics',
            'workflow_run_entities',
            'workflow_amo_crm_mutations',
            'workflow_usage_months',
        ], static fn(string $table): bool => Schema::hasTable($table)));
    }

    /**
     * @param array<int, string> $tables
     */
    private function refreshDatabaseStatistics(array $tables, bool $skipVacuum): int
    {
        if ($tables === []) {
            return 0;
        }

        $driver = DB::connection()->getDriverName();
        $refreshed = 0;

        foreach ($tables as $table) {
            try {
                match ($driver) {
                    'pgsql' => DB::statement(sprintf(
                        '%s %s',
                        $skipVacuum ? 'ANALYZE' : 'VACUUM (ANALYZE)',
                        $this->quotePgIdentifier($table),
                    )),
                    'mysql', 'mariadb' => DB::statement('ANALYZE TABLE `' . str_replace('`', '``', $table) . '`'),
                    default => null,
                };

                $refreshed++;
            } catch (Throwable $exception) {
                $this->warn(sprintf('Не удалось обновить статистику %s: %s', $table, $exception->getMessage()));
            }
        }

        return $refreshed;
    }

    private function quotePgIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
