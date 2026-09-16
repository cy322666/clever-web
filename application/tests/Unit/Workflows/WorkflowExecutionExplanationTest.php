<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowExecutionExplanation;
use App\Services\Workflows\WorkflowExecutionGraph;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowHistoryFixture;
use Tests\TestCase;

class WorkflowExecutionExplanationTest extends TestCase
{
    public function test_a_false_condition_is_explained_using_recorded_values_without_calling_it_an_error(): void
    {
        WorkflowCanvasDatabase::prepare();
        $run = WorkflowHistoryFixture::sampleRun();
        $run->steps[1]->input_data = ['conditions' => [['left' => '{{lead.status_id}}', 'right' => '20']]];
        $run->steps[1]->output_data = ['passed' => false, 'condition_results' => [['passed' => false, 'left_value' => 10, 'operator' => 'equals', 'right_value' => 20]]];
        $summary = WorkflowExecutionGraph::fromRun($run)['results'][1]['explanation'];
        $this->assertSame('Условие не выполнено → ветка «Нет»', $summary['title']);
        $this->assertSame('info', $summary['tone']);
        $this->assertSame('Статус сделки', $summary['details'][0]['label']);
        $this->assertSame('10', $summary['details'][0]['actual']);
        $this->assertSame('20', $summary['details'][0]['expected']);
    }

    public function test_missing_values_and_failed_or_disabled_nodes_are_not_reported_as_false_conditions(): void
    {
        $condition = ['type' => 'control-condition', 'status' => 'completed', 'output' => []];
        $this->assertStringContainsString('не записан', WorkflowExecutionExplanation::forResult($condition)['note']);
        $this->assertSame('Шаг пропущен', WorkflowExecutionExplanation::forResult(array_replace($condition, ['status' => 'skipped']))['title']);
        $summary = WorkflowExecutionExplanation::forResult(array_replace($condition, ['status' => 'failed', 'error' => 'Недоступно']));
        $this->assertSame('danger', $summary['tone']);
        $this->assertSame('Недоступно', $summary['note']);
        $this->assertSame('Сделка #12 · Заказ', WorkflowExecutionExplanation::entity(['trigger_data' => ['entity' => 'lead', 'lead' => ['id' => 12, 'name' => 'Заказ']]]));
        $this->assertSame('', WorkflowExecutionExplanation::entity(['trigger_data' => 'legacy']));
        $this->assertSame([], WorkflowExecutionExplanation::forResult(array_replace($condition, ['output' => ['passed' => false, 'condition_results' => 'legacy']]))['details']);
    }
}
