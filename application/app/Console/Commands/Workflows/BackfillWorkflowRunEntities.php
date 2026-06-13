<?php

namespace App\Console\Commands\Workflows;

use App\Models\Workflows\WorkflowRun;
use App\Services\Workflows\WorkflowRunEntityIndexService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Leek\FilamentWorkflows\Models\WorkflowRunStep;

class BackfillWorkflowRunEntities extends Command
{
    protected $signature = 'workflows:runs:index-entities {--chunk=500 : Размер пачки}';

    protected $description = 'Заполнить индекс сущностей для поиска по исполнениям процессов.';

    public function handle(WorkflowRunEntityIndexService $indexer): int
    {
        if (!Schema::hasTable('workflow_run_entities')) {
            $this->error('Таблица workflow_run_entities не найдена. Сначала выполните миграции.');

            return self::FAILURE;
        }

        $chunk = max(50, (int)$this->option('chunk'));
        $runCount = 0;
        $stepCount = 0;
        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');
        $stepModelClass = config('filament-workflows.models.workflow_run_step', WorkflowRunStep::class);

        WorkflowRun::query()
            ->select(['id', 'workflow_id', $tenantColumn, 'context_data'])
            ->whereNotNull('context_data')
            ->chunkById($chunk, function ($runs) use ($indexer, &$runCount): void {
                foreach ($runs as $run) {
                    $indexer->indexRun($run);
                    $runCount++;
                }
            });

        if (!is_string($stepModelClass) || !is_subclass_of($stepModelClass, Model::class)) {
            $this->warn('Модель шагов процесса не найдена, проиндексированы только данные запусков.');
            $this->info(sprintf('Запусков обработано: %d.', $runCount));

            return self::SUCCESS;
        }

        $stepModelClass::query()
            ->with(['workflowRun:id,workflow_id,' . $tenantColumn . ',context_data'])
            ->where(fn($query) => $query
                ->whereNotNull('input_data')
                ->orWhereNotNull('output_data'))
            ->chunkById($chunk, function ($steps) use ($indexer, &$stepCount): void {
                foreach ($steps as $step) {
                    $indexer->indexStep($step);
                    $stepCount++;
                }
            });

        $this->info(sprintf('Индекс сущностей обновлён. Запусков: %d, шагов: %d.', $runCount, $stepCount));

        return self::SUCCESS;
    }
}
