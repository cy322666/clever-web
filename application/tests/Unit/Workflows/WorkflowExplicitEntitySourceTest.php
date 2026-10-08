<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowAmoCrmActionExecutor;
use App\Workflows\Context\WorkflowContext;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowExplicitEntitySourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
    }

    private function id(WorkflowContext $context, string $source, string $entity = 'lead'): int
    {
        return (new \ReflectionMethod(WorkflowAmoCrmActionExecutor::class, 'currentEntityId'))
            ->invoke(app(WorkflowAmoCrmActionExecutor::class), $entity, $context, ['entity_source' => $source]);
    }

    public function test_trigger_and_input_choose_different_entities_without_implicit_fallback(): void
    {
        $context = (new WorkflowContext(['entity' => 'lead', 'lead' => ['id' => 42]]))->setStepOutput('a', ['entity_type' => 'lead', 'entity_id' => 77]);
        $this->assertSame(42, $this->id($context, 'trigger'));
        $this->assertSame(77, $this->id($context, 'input'));
        $this->assertSame(42, $this->id($context, 'context')); // Saved workflows keep existing behavior.
        $this->assertSame(0, $this->id($context, 'input', 'contact'));
        $context->setTriggerData([]);
        $this->assertSame(0, $this->id($context, 'trigger'));
        $this->assertSame(77, $this->id($context, 'context'));
    }

    public function test_input_does_not_take_a_sibling_or_silently_choose_from_a_list(): void
    {
        $context = (new WorkflowContext)->setStepOutput('a', ['entity_type' => 'lead', 'id' => 77])->setStepOutput('b', ['entity_type' => 'lead', 'id' => 99]);
        $context->scopeToNode(['trigger' => ['type' => 'manual'], 'actions' => [
            ['id' => 'a', 'type' => 'a'], ['id' => 'b', 'type' => 'b'], ['id' => 'c', 'type' => 'c']],
            'connections' => [['sourceId' => 'action:a', 'sourcePort' => 'output', 'targetId' => 'action:c']]], 'action:c');
        $this->assertSame(77, $this->id($context, 'input'));
        $context->clearNodeScope();
        $context->setStepOutput('c', ['items' => [['id' => 11], ['id' => 22]]]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('список');
        $this->id($context, 'input');
    }

    public function test_explicit_modes_survive_open_and_save_and_do_not_infer_another_entity_type(): void
    {
        foreach (['trigger', 'input'] as $source) {
            $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [['id' => 'a', 'type' => 'amocrm_add_note',
                'config' => ['entity_source' => $source, 'target_entity' => 'company', 'text' => 'Note']]]])
                ->call('openWorkflowActionEditor', 'a')->assertSet('mountedActions.0.data.entity_source', $source)
                ->call('callMountedAction')->assertHasNoErrors();
            $this->assertSame($source, $page->get('workflowActions')[0]['config']['entity_source']);
            $this->assertSame('company',$page->get('workflowActions')[0]['config']['target_entity']);
        }
    }
}
