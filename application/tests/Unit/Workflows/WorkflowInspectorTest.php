<?php

namespace Tests\Unit\Workflows;

use Livewire\Livewire;
use Tests\Support\{WorkflowCanvasDatabase, WorkflowCanvasFixture};
use Tests\TestCase;

class WorkflowInspectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
    }

    private function editor()
    {
        return Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [
            ['id'=>'a','name'=>'Первый','type'=>'workflow_delay','config'=>['seconds'=>5]],
            ['id'=>'b','name'=>'Второй','type'=>'workflow_delay','config'=>['seconds'=>8]],
        ]]);
    }

    public function test_dock_visibility_is_owned_by_the_panel_not_a_mirrored_canvas_flag(): void
    {
        $this->editor()
            ->assertSeeHtml('x-bind:hidden="!opened" hidden')
            ->assertDontSeeHtml('has-inspector')
            ->assertDontSeeHtml('inspectorOpen');
    }

    public function test_dock_renders_real_fields_without_a_modal_or_focus_trap(): void
    {
        $page = $this->editor()->call('openWorkflowInspector','action:a')
            ->assertSet('mountedActions.0.data.seconds',5)
            ->assertSet('workflowInspectorInitialData.seconds',5)
            ->assertSee('Настройки')->assertSee('Вход')->assertSee('Выход');
        $html = implode('', $page->effects['partials'] ?? []);
        $this->assertArrayNotHasKey('html', $page->effects);
        $this->assertStringContainsString('workflow-inspector__form',$html);
        $this->assertStringContainsString('wire:submit.prevent="callMountedAction"',$html);
        $this->assertStringNotContainsString('x-trap',$html);
        $this->assertStringNotContainsString('fi-modal-close-overlay',$html);
    }

    public function test_filter_rule_field_is_bound_to_its_collection_for_key_completion(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [[
            'id' => 'filter', 'type' => 'workflow_filter_list', 'config' => [
                'items' => '{{ $json.items }}', 'match' => 'all',
                'rules' => [['field' => '', 'operator' => 'eq', 'value' => '']],
            ],
        ]]])->call('openWorkflowInspector', 'action:filter')->assertHasNoErrors();
        $html = implode('', $page->effects['partials'] ?? []);
        $this->assertStringContainsString('data-workflow-filter-item-field', $html);
        $this->assertStringContainsString('data.items', $html);
        $this->assertStringContainsString('Поле первого уровня каждого элемента, без вложенности.', $html);
        $this->assertStringContainsString('data.rules', $html);
    }

    public function test_a_selected_collection_key_prefills_a_new_rule_without_persisting_the_ui_hint(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [[
            'id' => 'filter', 'type' => 'workflow_filter_list', 'config' => [
                'items' => '{{ $("Сделки") }}', 'match' => 'all', 'rules' => [],
            ],
        ]]])->call('openWorkflowInspector', 'action:filter')
            ->set('mountedActions.0.data.__filter_item_field', 'status_id')
            ->call('mountAction', 'add', [], ['schemaComponent' => 'mountedActionSchema0.rules'])
            ->assertHasNoErrors();
        $rules = $page->get('mountedActions.0.data.rules');
        $this->assertCount(1, $rules);
        $this->assertSame('status_id', reset($rules)['field']);
        $page->set('mountedActions.0.data.rules.'.array_key_first($rules).'.value', '142')
            ->call('callMountedAction')->assertHasNoErrors();
        $config = $page->get('workflowActions.0.config');
        $this->assertSame('{{ $("Сделки") }}', $config['items']);
        $this->assertSame('status_id', $config['rules'][0]['field']);
        $this->assertArrayNotHasKey('__filter_item_field', $config);
    }

    public function test_switching_saves_the_previous_draft_and_refills_only_the_new_node(): void
    {
        $page = $this->editor()->call('openWorkflowInspector','action:a')->set('mountedActions.0.data.seconds',12)
            ->call('openWorkflowInspector','action:b')->assertHasNoErrors()
            ->assertSet('workflowActions.0.config.seconds',12)
            ->assertSet('editingActionId','b')->assertSet('mountedActions.0.data.seconds',8);
        $this->assertArrayNotHasKey('html', $page->effects);
        $page->set('mountedActions.0.data.seconds',15)->call('callMountedAction')
            ->assertSet('workflowActions.1.config.seconds',15)->assertSet('mountedActions',[]);
    }

    public function test_invalid_draft_does_not_block_navigation_or_get_discarded(): void
    {
        $this->editor()->call('openWorkflowInspector','action:a')->set('mountedActions.0.data.seconds','')
            ->call('openWorkflowInspector','action:b')->assertHasNoErrors()
            ->assertSet('editingActionId','b')->assertSet('mountedActions.0.data.seconds',8)
            ->assertSet('workflowInspectorDrafts.action:a.data.seconds','')
            ->assertSet('workflowActions.0.config.seconds',5)
            ->call('openWorkflowInspector','action:a')->assertSet('mountedActions.0.data.seconds','')
            ->assertSet('workflowInspectorInitialData.seconds',5)
            ->set('mountedActions.0.data.seconds',12)
            ->call('openWorkflowInspector','action:b')->assertHasNoErrors()
            ->assertSet('workflowActions.0.config.seconds',12)
            ->assertSet('workflowInspectorDrafts',[]);
    }

    public function test_query_selection_with_an_incomplete_inspector_opens_the_new_query(): void
    {
        $page = $this->editor()->call('openWorkflowInspector','action:a')
            ->set('mountedActions.0.data.seconds','')
            ->call('selectReadOperation','tasks.list')->assertHasNoErrors()
            ->assertSet('mountedActions.0.data.operation','tasks.list')
            ->assertSet('workflowInspectorDrafts.action:a.data.seconds','');
        $newNode = array_values(array_filter($page->get('workflowActions'), fn ($action) => $action['type'] === 'amocrm_read'))[0];
        $page->assertSet('editingActionId',$newNode['id'])
            ->call('openWorkflowInspector','action:a')->assertSet('mountedActions.0.data.seconds','');
    }

    public function test_catalog_selection_replaces_the_contact_leads_settings_shown_in_the_report(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [
            ['id'=>'contact-leads','name'=>'Сделки контакта','type'=>'amocrm_contact_leads','config'=>['source'=>'lead','lead_id'=>null]],
        ]])->call('openWorkflowInspector','action:contact-leads')
            ->call('selectActionType','amocrm_query_leads')->assertHasNoErrors()
            ->assertSet('mountedActions.0.name','configureWorkflowAction')
            ->assertSet('workflowInspectorDrafts',[]);
        $newNode = $page->get('workflowActions')[1];
        $page->assertSet('editingActionId',$newNode['id']);
        $this->assertSame('amocrm_query_leads',$newNode['type']);
    }

    public function test_committing_all_drafts_returns_to_an_invalid_node(): void
    {
        Livewire::test(WorkflowInspectorCommitFixture::class, ['workflowActions' => [
            ['id'=>'a','name'=>'Первый','type'=>'workflow_delay','config'=>['seconds'=>5]],
            ['id'=>'b','name'=>'Второй','type'=>'workflow_delay','config'=>['seconds'=>8]],
        ]])->call('openWorkflowInspector','action:a')->set('mountedActions.0.data.seconds','')
            ->call('openWorkflowInspector','action:b')->set('mountedActions.0.data.seconds',12)
            ->call('commitInspectorDrafts')->assertHasErrors()
            ->assertSet('editingActionId','a')->assertSet('mountedActions.0.data.seconds','')
            ->assertSet('workflowActions.1.config.seconds',12)
            ->set('mountedActions.0.data.seconds',19)
            ->call('commitInspectorDrafts')->assertHasNoErrors()
            ->assertSet('workflowActions.0.config.seconds',19)
            ->assertSet('workflowInspectorDrafts',[]);
    }

    public function test_unknown_node_cannot_mount_or_discard_current_form(): void
    {
        $this->editor()->call('openWorkflowInspector','action:a')->set('mountedActions.0.data.seconds',12)
            ->call('openWorkflowInspector','action:missing')->assertStatus(422);
    }

    public function test_reopening_current_node_preserves_its_unsaved_fields(): void
    {
        $this->editor()->call('openWorkflowInspector','action:a')->set('mountedActions.0.data.seconds',12)
            ->call('openWorkflowInspector','action:a')->assertSet('mountedActions.0.data.seconds',12);
    }

    public function test_native_dialogs_are_not_replaced_by_the_node_inspector(): void
    {
        $page = $this->editor()->call('mountAction','renameWorkflowNode',['id'=>'a']);
        $html = implode('', $page->effects['partials'] ?? []);
        $this->assertStringContainsString('fi-modal-close-overlay',$html);
        $this->assertStringNotContainsString('data-inspector-node="action:a"',$html);
        $this->assertStringNotContainsString('data-inspector-node="action:b"',$html);
    }

    public function test_inline_rename_also_remaps_unsaved_expressions_in_open_form(): void
    {
        $this->editor()->call('openWorkflowInspector','action:b')
            ->set('mountedActions.0.data.seconds','{{ $("Первый").seconds }}')
            ->call('renameWorkflowNode','a','Источник')
            ->assertSet('mountedActions.0.data.seconds','{{ $("Источник").seconds }}');
    }

    public function test_inline_rename_also_remaps_expressions_in_deferred_drafts(): void
    {
        Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [
            ['id'=>'a','name'=>'Первый','type'=>'workflow_delay','config'=>['seconds'=>5]],
            ['id'=>'b','name'=>'Сделки контакта','type'=>'amocrm_contact_leads','config'=>['source'=>'lead','lead_id'=>null]],
        ]])->call('openWorkflowInspector','action:b')
            ->set('mountedActions.0.data.exclude_lead_id','{{ $("Первый").seconds }}')
            ->call('openWorkflowInspector','action:a')
            ->call('renameWorkflowNode','a','Источник')
            ->assertSet('workflowInspectorDrafts.action:b.data.exclude_lead_id','{{ $("Источник").seconds }}')
            ->call('openWorkflowInspector','action:b')
            ->assertSet('mountedActions.0.data.exclude_lead_id','{{ $("Источник").seconds }}');
    }

    public function test_trigger_settings_do_not_block_returning_to_previously_opened_action(): void
    {
        $page=$this->editor()->call('selectTriggerType','generic-webhook',true);
        $id=$page->get('definition')['additional_triggers'][0]['id'];
        $page->call('openWorkflowInspector','action:a')->call('openWorkflowInspector',$id)
            ->assertSet('mountedActions.0.name','configureTrigger')
            ->call('openWorkflowInspector','action:a')
            ->assertSet('mountedActions.0.name','configureWorkflowAction')->assertSet('mountedActions.0.data.seconds',5);
    }
}

class WorkflowInspectorCommitFixture extends WorkflowCanvasFixture
{
    public function commitInspectorDrafts(): bool
    {
        return $this->applyWorkflowInspectorDraft();
    }
}
