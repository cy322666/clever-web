<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Actions\WorkflowDelayAction;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class WorkflowDelayTest extends TestCase
{
    public function test_delay_form_accepts_zero_and_fifteen_and_rejects_values_outside_the_range(): void
    {
        \Tests\Support\WorkflowCanvasDatabase::prepare();
        $page = \Livewire\Livewire::test(\Tests\Support\WorkflowCanvasFixture::class, [
            'workflowActions' => [['id' => 'pause', 'type' => 'workflow_delay', 'config' => ['seconds' => 5]]],
        ])->call('openWorkflowActionEditor', 'pause');
        $page->assertSet('mountedActions.0.data.seconds', 5)
            ->set('mountedActions.0.data.seconds', '0')->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame('0', $page->get('workflowActions')[0]['config']['seconds']);
        $page->call('openWorkflowActionEditor', 'pause')->set('mountedActions.0.data.seconds', '15')
            ->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame('15', $page->get('workflowActions')[0]['config']['seconds']);
        foreach (['16', '-1'] as $seconds) {
            if (!$page->get('mountedActions')) $page->call('openWorkflowActionEditor', 'pause');
            $page->set('mountedActions.0.data.seconds', $seconds)->call('callMountedAction')->assertHasErrors(['mountedActions.0.data.seconds']);
            $this->assertSame('15', $page->get('workflowActions')[0]['config']['seconds']);
        }
    }
    public function test_delay_can_be_added_from_the_catalog_and_configured_with_a_variable(): void
    {
        \Tests\Support\WorkflowCanvasDatabase::prepare();
        $page = \Livewire\Livewire::test(\Tests\Support\WorkflowCanvasFixture::class)
            ->assertSee('Задержка')->call('selectActionType', 'workflow_delay');
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
        $context = (new WorkflowContext)->setTriggerData(['seconds' => 15]);
        $context->setStepOutput('read', ['items' => [['id' => 123]]]);
        $result = (new WorkflowDelayAction)->handle(['seconds' => '{{ $node["trigger"].json.seconds }}'], $context);
        $this->assertTrue($result['success']);
        $this->assertSame(['items' => [['id' => 123]]], $result['output']);
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 15.0);
    }

    public function test_zero_delay_passes_through_without_sleep_and_activation_uses_the_same_limits(): void
    {
        $context = (new WorkflowContext)->setTriggerData(['seconds' => 0, 'id' => 42]);
        $result = (new WorkflowDelayAction)->handle(['seconds' => '{{ $json.seconds }}'], $context);
        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['delay_seconds']);
        Sleep::assertNeverSlept();
        foreach ([0, '0', 15, '15', '{{ $json.seconds }}'] as $seconds) {
            $this->assertSame([], \App\Services\Workflows\WorkflowDefinitionValidator::configIssues('workflow_delay', ['seconds' => $seconds]));
        }
        foreach ([-1, 16, 1.5, null, 'abc'] as $seconds) {
            $this->assertNotEmpty(\App\Services\Workflows\WorkflowDefinitionValidator::configIssues('workflow_delay', ['seconds' => $seconds]));
        }
    }

    public function test_test_run_does_not_wait_and_invalid_delays_are_rejected(): void
    {
        $action = new WorkflowDelayAction;
        $context = (new WorkflowContext)->setVariable('_test_mode', true);
        $this->assertTrue($action->handle(['seconds' => 15], $context)['simulated']);
        foreach ([16, 30, -1, 1.5, 'abc', [], null] as $seconds) {
            $this->assertFalse($action->handle(['seconds' => $seconds])['success']);
        }
        Sleep::assertNeverSlept();
    }
}
