<?php

namespace Tests\Unit\Workflows;

use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;
use App\Services\Workflows\WorkflowExpressionCatalog;
use App\Services\Workflows\WorkflowGraph;

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

    public function test_duplicate_names_are_rejected_across_actions_and_start_nodes(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)
            ->call('renameWorkflowNode', 'task', 'Контакт')
            ->call('renameWorkflowNode', 'note', ' Контакт ')->assertHasErrors('name')
            ->call('renameWorkflowNode', 'trigger', 'Контакт')->assertHasErrors('name');
        $this->assertNotSame('Контакт', $page->get('trigger.name'));
        $page->call('renameWorkflowNode', 'task', 'Контакт')->assertHasNoErrors();
    }

    public function test_old_duplicate_labels_become_the_existing_reference_aliases_without_changing_targets(): void
    {
        $definition = ['trigger'=>['type'=>'manual','name'=>'Контакт'], 'actions'=>[
            ['id'=>'a','type'=>'control-condition','name'=>'Контакт','config'=>['true_actions'=>[
                ['id'=>'b','type'=>'amocrm_get_contact','name'=>'Контакт','config'=>[]],
            ]]],
            ['id'=>'c','type'=>'amocrm_get_contact','name'=>'Контакт (2)','config'=>['id'=>'{{ $("Контакт (3)").id }}']],
        ]];
        $before = WorkflowExpressionCatalog::referenceNames($definition['actions'], $definition);
        $normalized = WorkflowExpressionCatalog::normalizeNames($definition);
        $this->assertSame($before, WorkflowExpressionCatalog::referenceNames($normalized['actions'], $normalized));
        $this->assertSame('Контакт (2)', $normalized['actions'][0]['name']);
        $this->assertSame('Контакт (3)', $normalized['actions'][0]['config']['true_actions'][0]['name']);
        $this->assertSame('Контакт (2) (2)', $normalized['actions'][1]['name']);
        $this->assertSame($definition['actions'][1]['config'], $normalized['actions'][1]['config']);
        $this->assertSame($normalized, WorkflowExpressionCatalog::normalizeNames($normalized));
    }

    public function test_created_and_inserted_nodes_have_unique_visible_names_without_renaming_existing_nodes(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions'=>[]])
            ->call('selectActionType', 'amocrm_get_contact')
            ->call('selectActionType', 'amocrm_get_contact');
        $actions = $page->get('workflowActions');
        $first = $actions[0]['name'];
        $this->assertSame($first.' (2)', $actions[1]['name']);
        $page->call('openAddActionAtPath', '', 0)->call('selectActionType', 'amocrm_get_contact');
        $this->assertSame($first.' (3)', $page->get('workflowActions.0.name'));
        $this->assertSame($first, $page->get('workflowActions.1.name'));
        $this->assertSame($first.' (2)', $page->get('workflowActions.2.name'));
    }

    public function test_read_operations_and_multiple_triggers_also_get_unique_names(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions'=>[]])
            ->call('selectReadOperation', 'contacts.list')->call('selectReadOperation', 'contacts.list');
        $this->assertSame($page->get('workflowActions.0.name').' (2)', $page->get('workflowActions.1.name'));
        $page->call('selectTriggerType', 'manual')->call('selectTriggerType', 'manual');
        $starts = $page->get('definition.additional_triggers');
        $this->assertSame('Ручной запуск (2)', $starts[0]['name']);
        $this->assertSame('Ручной запуск (3)', $starts[1]['name']);
    }

    public function test_copy_button_copies_subtree_connections_references_and_names_without_touching_originals_or_clipboard(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)
            ->call('renameWorkflowNode', 'task', 'Задача')
            ->call('renameWorkflowNode', 'note', 'Заметка');
        $actions = $page->get('workflowActions');
        $actions[0]['config']['false_actions'][0]['config']['text'] = '{{ $("Задача").id }} {{ $("task").id }}';
        $page->set('workflowActions', $actions)->call('copyWorkflowNodes', ['action:task']);
        $clipboard = $page->get('workflowClipboard');
        $page->call('duplicateWorkflowActionStep', 'condition')->assertHasNoErrors()->assertDispatched('workflow-nodes-copied');
        $this->assertSame($clipboard, $page->get('workflowClipboard'));
        $nodes = WorkflowGraph::nodes($page->get('workflowActions'));
        $this->assertCount(6, $nodes);
        $this->assertSame($actions[0], $page->get('workflowActions.0'));
        $copiedTask = collect($nodes)->first(fn ($n) => ($n['step']['name'] ?? '') === 'Задача (копия)')['step'];
        $copiedNote = collect($nodes)->first(fn ($n) => ($n['step']['name'] ?? '') === 'Заметка (копия)')['step'];
        $this->assertSame('{{ $("Задача (копия)").id }} {{ $("'.$copiedTask['id'].'").id }}', $copiedNote['config']['text']);
        $this->assertCount(5, $page->get('definition.connections'));
        $page->call('duplicateWorkflowActionStep', 'task');
        $names = WorkflowExpressionCatalog::referenceNames($page->get('workflowActions'), $page->get('definition'));
        $this->assertContains('Задача (копия) 2', $names);
    }
}
