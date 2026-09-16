<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowExecutionGraph;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowHistoryFixture;
use Tests\TestCase;

class WorkflowExecutionGraphTest extends TestCase
{
    public function test_history_polls_even_when_completed_and_keeps_the_viewer_key_stable(): void
    {
        $view = file_get_contents(resource_path('views/filament/workflow-builder/workflow-history-page.blade.php'));
        $this->assertStringContainsString('class="workflow-history-page" wire:poll.5s.visible',$view);
        $this->assertSame(1,substr_count($view,'wire:poll'));
        $canvas = file_get_contents(resource_path('views/filament/workflow-builder/workflow-execution-canvas.blade.php'));
        $this->assertStringContainsString('wire:key="execution-{{ $runId }}"',$canvas);
        $this->assertStringContainsString('Запрос в amoCRM',$canvas);
    }
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
    }

    public function test_history_uses_the_saved_graph_and_marks_only_the_taken_branch(): void
    {
        $run = WorkflowHistoryFixture::sampleRun();
        $run->workflow->definition = ['trigger' => ['type' => 'manual'], 'actions' => []];
        $graph = WorkflowExecutionGraph::fromRun($run);
        $this->assertFalse($graph['historical_fallback']);
        $this->assertCount(5, $graph['nodes']);
        $branches = collect($graph['edges'])->where('sourceId', 'action:if')->keyBy('sourcePort');
        $this->assertTrue($branches['yes']['active']);
        $this->assertFalse($branches['no']['active']);
        $this->assertSame(['fetch', 'if', 'yes'], array_column($graph['results'], 'id'));
        $this->assertSame('Запрос сделок', $graph['results'][0]['name']);
    }

    public function test_old_runs_show_the_known_execution_path_without_inventing_a_definition(): void
    {
        $graph = WorkflowExecutionGraph::fromRun(WorkflowHistoryFixture::sampleRun(false));
        $this->assertTrue($graph['historical_fallback']);
        $this->assertSame('Запуск', $graph['nodes'][0]['name']);
        $this->assertSame(['trigger', 'action:fetch', 'action:if', 'action:yes'], array_column($graph['nodes'], 'id'));
        $this->assertSame('yes', $graph['edges'][2]['sourcePort']);
    }

    public function test_badges_count_output_entities_instead_of_step_order(): void
    {
        $run = WorkflowHistoryFixture::sampleRun();
        $run->steps[0]->output_data = ['items'=>[['id'=>1],['id'=>2],['id'=>3]]];
        $run->steps[2]->status = 'failed';
        $graph = WorkflowExecutionGraph::fromRun($run);
        $nodes = collect($graph['nodes'])->keyBy('id');
        $this->assertSame(3,$nodes['action:fetch']['item_count']);
        $this->assertSame(1,$nodes['action:if']['item_count']);
        $this->assertSame(0,$nodes['action:yes']['item_count']);
        $this->assertSame(1,$graph['results'][0]['execution_id']);
        $this->assertStringContainsString('workflow-execution-inspector" wire:ignore',file_get_contents(resource_path('views/filament/workflow-builder/workflow-execution-canvas.blade.php')));
    }

    public function test_the_history_page_renders_a_canvas_and_node_data_instead_of_row_modals(): void
    {
        Livewire::test(WorkflowHistoryFixture::class)->assertStatus(200)
            ->assertSee('workflow-execution-viewer', false)->assertSee('Показать весь запуск')
            ->assertSee('Данные ноды: Запрос сделок')->assertSee('Следующий шаг')
            ->assertDontSee('workflow-run-steps');
    }
}
