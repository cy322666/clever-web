<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Actions\WorkflowDelayAction;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class WorkflowDelayTest extends TestCase
{
    public function test_delay_accepts_an_arbitrary_integer_instead_of_a_fixed_preset(): void
    {
        \Tests\Support\WorkflowCanvasDatabase::prepare();
        $page = \Livewire\Livewire::test(\Tests\Support\WorkflowCanvasFixture::class, [
            'workflowActions' => [['id' => 'pause', 'type' => 'workflow_delay', 'config' => ['seconds' => 20]]],
        ])->call('openWorkflowActionEditor', 'pause');
        $page->assertSet('mountedActions.0.data.seconds', 20)
            ->set('mountedActions.0.data.seconds', '17')->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame('17', $page->get('workflowActions')[0]['config']['seconds']);
        $page->call('openWorkflowActionEditor', 'pause')->set('mountedActions.0.data.seconds', '31')
            ->call('callMountedAction')->assertHasErrors(['mountedActions.0.data.seconds']);
        $this->assertSame('17', $page->get('workflowActions')[0]['config']['seconds']);
    }
    public function test_delay_can_be_added_from_the_catalog_and_configured_with_a_variable(): void
    {
        \Tests\Support\WorkflowCanvasDatabase::prepare();
        $page = \Livewire\Livewire::test(\Tests\Support\WorkflowCanvasFixture::class)
            ->assertSee('Пауза до 30 секунд')->call('selectActionType', 'workflow_delay');
        $steps = $page->get('workflowActions');
        $id = end($steps)['id'];
        $page->call('openWorkflowActionEditor', $id)->set('mountedActions.0.data.seconds', '{{ $json.seconds }}')->call('callMountedAction')->assertHasNoErrors();
        $steps = $page->get('workflowActions');
        $this->assertSame('{{ $json.seconds }}', end($steps)['config']['seconds']);
    }
    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    public function test_delay_waits_and_passes_the_previous_data_unchanged(): void
    {
        $context = (new WorkflowContext)->setTriggerData(['seconds' => 30]);
        $context->setStepOutput('read', ['items' => [['id' => 123]]]);
        $result = (new WorkflowDelayAction)->handle(['seconds' => '{{ $node["trigger"].json.seconds }}'], $context);
        $this->assertTrue($result['success']);
        $this->assertSame(['items' => [['id' => 123]]], $result['output']);
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 30.0);
    }

    public function test_test_run_does_not_wait_and_invalid_delays_are_rejected(): void
    {
        $action = new WorkflowDelayAction;
        $context = (new WorkflowContext)->setVariable('_test_mode', true);
        $this->assertTrue($action->handle(['seconds' => 30], $context)['simulated']);
        foreach ([0, 31, -1, 1.5, 'abc', [], null] as $seconds) {
            $this->assertFalse($action->handle(['seconds' => $seconds])['success']);
        }
        Sleep::assertNeverSlept();
    }
}
