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
        {--chunk=500 : Размер пачки}
        {--skip-vacuum : Не выполнять VACUUM, только ANALYZE/служебные индексы}';

    protected $description = 'Ночное обслуживание таблиц процессов: индекс сущностей и статистика планировщика БД.';

    public function handle(WorkflowRunEntityIndexService $entityIndexer): int
    {
        if (!Schema::hasTable('workflow_runs')) {
            $this->warn('Таблица workflow_runs не найдена.');

            return self::SUCCESS;
        }

        $days = max(1, (int)$this->option('days'));
        $chunk = max(50, (int)$this->option('chunk'));

        [$runsIndexed, $stepsIndexed] = $this->refreshEntityIndex($entityIndexer, $days, $chunk);
        $tables = $this->workflowTables();
        $optimizedTables = $this->refreshDatabaseStatistics($tables, (bool)$this->option('skip-vacuum'));

        $this->info(sprintf(
            'Workflow DB maintenance complete. Entity index: runs=%d, steps=%d. DB tables refreshed: %d.',
            $runsIndexed,
            $stepsIndexed,
            $optimizedTables,
        ));

        return self::SUCCESS;
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
