<?php

namespace Tests\Support;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource\Pages\WorkflowHistory;
use App\Models\Workflows\Workflow;
use App\Models\Workflows\WorkflowRun;
use Illuminate\Support\Collection;
use Leek\FilamentWorkflows\Models\WorkflowRunStep;

class WorkflowHistoryFixture extends WorkflowHistory
{
    public function mount($record = null): void
    {
        $this->record = (new Workflow)->forceFill(['id' => 13, 'name' => 'Основная воронка']);
    }

    public function getHistoryBackUrl(): string
    {
        return '/';
    }

    public function getHistoryActionUrl(): string
    {
        return '/';
    }

    public function getHistoryRunUrl(WorkflowRun $run): string
    {
        return '/history?run='.$run->id;
    }

    public function getWorkflowRuns(): Collection
    {
        return collect([self::sampleRun()]);
    }

    public static function sampleRun(bool $snapshot = true): WorkflowRun
    {
        $run = (new WorkflowRun)->forceFill(['id' => 9, 'workflow_id' => 13, 'ulid' => 'TEST-EXECUTION', 'status' => 'completed',
            'started_at' => now()->subMinute(), 'created_at' => now()->subMinute(), 'steps_count' => 3,
            'context_data' => ['trigger_data' => [], 'variables' => $snapshot ? ['_definition_snapshot' => WorkflowDebugFixture::sampleDefinition()] : []],
        ]);
        $run->setRelation('workflow', (new Workflow)->forceFill(['id' => 13, 'name' => 'Основная воронка']));
        $run->setRelation('steps', collect([
            (new WorkflowRunStep)->forceFill(['id' => 1, 'step_id' => 'fetch', 'action_type' => 'amocrm_query_leads', 'status' => 'completed', 'duration_ms' => 124, 'input_data' => ['limit' => 50], 'output_data' => ['count' => 1, 'items' => [['id' => 123, 'name' => 'Тестовая сделка', 'price' => 15000]]]]),
            (new WorkflowRunStep)->forceFill(['id' => 2, 'step_id' => 'if', 'action_type' => 'control-condition', 'status' => 'completed', 'duration_ms' => 2, 'output_data' => ['passed' => true]]),
            (new WorkflowRunStep)->forceFill(['id' => 3, 'step_id' => 'yes', 'action_type' => 'amocrm_add_note', 'status' => 'completed', 'duration_ms' => 83, 'input_data' => ['text' => 'Найдено: 1'], 'output_data' => ['id' => 789]]),
        ]));

        return $run;
    }
}
