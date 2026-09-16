<?php

namespace Tests\Unit\Workflows;

use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowNodeNamesTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); WorkflowCanvasDatabase::prepare(); }

    public function test_renaming_a_nested_node_preserves_connections_and_updates_expressions(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class);
        $actions = $page->get('workflowActions');
        $actions[0]['config']['true_actions'][0]['name'] = 'Задача';
        $actions[0]['config']['false_actions'][0]['config']['text'] = '{{ $node["Задача"].json.id }}';
        $definition = ['trigger' => ['type' => 'manual'], 'actions' => $actions, 'connections' => [], 'description' => 'Не потерять'];
        $page->set('workflowActions', $actions)->set('definition', $definition)
            ->call('mountAction', 'renameWorkflowNode', ['id' => 'task'])
            ->set('mountedActions.0.data.name', 'Позвонить клиенту')
            ->call('callMountedAction')->assertHasNoErrors()
            ->assertSet('workflowActions.0.config.true_actions.0.name', 'Позвонить клиенту')
            ->assertSet('definition.description', 'Не потерять')->assertSet('definition.connections', [])
            ->assertSet('workflowActions.0.config.false_actions.0.config.text', '{{ $node["Позвонить клиенту"].json.id }}');
    }

    public function test_start_node_names_survive_configuration_and_are_remapped(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class);
        $page->set('trigger', ['type' => 'generic-webhook', 'config' => []])
            ->call('renameWorkflowNode', 'trigger', 'Входящий заказ')
            ->assertSet('trigger.name', 'Входящий заказ')
            ->call('saveTriggerNodeConfig', ['method' => 'POST'])
            ->assertSet('trigger.name', 'Входящий заказ')->assertSet('trigger.config.method', 'POST');
        $page->call('renameWorkflowNode', 'task', '   ')->assertHasErrors('name');
    }
}
