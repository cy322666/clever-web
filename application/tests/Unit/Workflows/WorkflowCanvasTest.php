<?php

namespace Tests\Unit\Workflows;

use Livewire\Livewire;
use Tests\Support\WorkflowCanvasFixture;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\TestCase;

class WorkflowCanvasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
    }

    public function test_it_renders_hover_controls_only_for_existing_connections(): void
    {
        Livewire::test(WorkflowCanvasFixture::class)
            ->assertStatus(200)
            ->assertSee('data-workflow-edge-target="action:task"', false)
            ->assertSee('data-workflow-edge-target="action:note"', false)
            ->assertSee("startConnection('action:' + 'condition', 'yes', \$event)", false)
            ->assertSee('aria-label="Выход Да:', false)
            ->assertSee('aria-label="Выход Нет:', false)
            ->assertSee('workflow-node-edge-add', false)
            ->assertSee('data-workflow-edge-delete', false)
            ->assertSee('Добавить промежуточную ноду')
            ->assertSee('Удалить связь')
            ->assertSee('showEdgeControls($el)', false)
            ->assertDontSee('data-workflow-edge-branch', false)
            ->assertDontSee('Добавить ещё одну ветку')
            ->assertDontSee('Добавить действие в ветку')
            ->call('openAddActionAtPath', '0.config.false_actions', 1)
            ->assertSet('insertActionPath', '0.config.false_actions')
            ->assertSet('insertActionIndex', 1)
            ->assertDispatched('workflow-node-library-open', mode: 'action')
            ->assertDontSee('workflow-node-library__context', false);
    }

    public function test_empty_condition_branches_offer_pluses_and_keep_their_output_handles(): void
    {
        Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [
            ['id' => 'empty-condition', 'type' => 'control-condition', 'config' => []],
        ]])
            ->assertStatus(200)
            ->assertSee('aria-label="Выход Да:', false)
            ->assertSee('aria-label="Выход Нет:', false)
            ->assertSee('workflow-edge-add-action:empty-condition-yes-end', false)
            ->assertSee('workflow-edge-add-action:empty-condition-no-end', false)
            ->assertSee('Добавить действие в ветку «Да»')
            ->assertSee('Добавить действие в ветку «Нет»');
    }

    public function test_it_toggles_a_nested_node_without_changing_its_configuration(): void
    {
        $component = Livewire::test(WorkflowCanvasFixture::class);
        $originalConfig = $component->get('workflowActions')[0]['config']['true_actions'][0]['config'];

        $component->call('toggleWorkflowActionDisabled', 'task')
            ->assertSet('workflowActions.0.config.true_actions.0.disabled', true)
            ->assertSet('definition.actions.0.config.true_actions.0.disabled', true)
            ->assertSet('workflowActions.0.config.true_actions.0.config', $originalConfig)
            ->assertSee('workflow-node-card--disabled', false)
            ->assertSee('Выключено')
            ->call('toggleWorkflowActionDisabled', 'task')
            ->assertSet('definition.actions.0.config.true_actions.0.disabled', false);
    }

    public function test_new_condition_has_two_enabled_branches_and_pluses_even_before_selecting_a_trigger(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [], 'trigger' => null])
            ->call('selectActionType', 'control-condition')
            ->assertSet('workflowActions.0.config.has_true_branch', true)
            ->assertSet('workflowActions.0.config.has_false_branch', true)
            ->assertSet('workflowActions.0.config.true_actions', [])
            ->assertSet('workflowActions.0.config.false_actions', [])
            ->assertSee('Добавить действие в ветку «Да»')
            ->assertSee('Добавить действие в ветку «Нет»');
        $nodeId = 'action:'.$page->get('workflowActions')[0]['id'];
        $page->call('openAddActionOnConnection', $nodeId, 'no')
            ->assertDispatched('workflow-node-library-open', mode: 'action')
            ->call('selectActionType', 'amocrm_add_note');
        $this->assertSame('no', $page->get('definition.connections')[0]['sourcePort']);
        $this->assertSame($nodeId, $page->get('definition.connections')[0]['sourceId']);
    }

    public function test_it_uses_centered_modals_and_removes_duplicate_navigation(): void
    {
        $component = Livewire::test(WorkflowCanvasFixture::class)
            ->assertDontSee('workflow-order-handle', false)
            ->assertDontSee('workflow-node-library__tabs', false)
            ->assertDontSee('workflow-mask-dock', false)
            ->assertSee('workflow-node-library__variables', false)
            ->assertDontSee('Назад к процессам')
            ->assertDontSee('История запусков')
            ->assertSee('aria-label="Переменные"', false)
            ->assertDontSee('Связаться с клиентом');

        $this->assertFalse($component->instance()->configureWorkflowActionAction()->isModalSlideOver());
        $this->assertFalse($component->instance()->configureTriggerAction()->isModalSlideOver());

        $component->call('openWorkflowActionEditor', 'task')
            ->assertSet('editingActionId', 'task')
            ->assertSet('mountedActions.0.name', 'configureWorkflowAction')
            ->assertSet('mountedActions.0.data.text', 'Связаться с клиентом');
    }

    public function test_adding_an_action_keeps_the_canvas_open_and_resets_the_catalog_to_detached_mode(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)
            ->call('openAddActionAtPath', '0.config.false_actions', 1)
            ->call('selectActionType', 'amocrm_add_note')
            ->assertSet('mountedActions', [])
            ->assertSet('editingActionId', null)
            ->assertSet('isNewAction', false)
            ->assertSet('insertActionPath', null)
            ->assertDispatched('workflow-node-library-open', mode: 'action')
            ->assertDontSee('Отдельная нода');
        $this->assertCount(2, $page->get('workflowActions')[0]['config']['false_actions']);
    }

    public function test_manual_and_button_triggers_have_no_configuration_popup(): void
    {
        $page = Livewire::test(WorkflowCanvasFixture::class)
            ->call('selectTriggerType', 'manual')
            ->assertSet('trigger', ['type' => 'manual', 'config' => []])
            ->assertSet('mountedActions', [])
            ->assertDontSee('Настроить запуск: Ручной запуск');
        $this->assertTrue($page->instance()->configureTriggerAction()->isHidden());

        $page->call('beginTriggerReplace', 'trigger')->call('selectTriggerType', 'amo-button')
            ->assertSet('trigger', ['type' => 'amo-button', 'config' => []])
            ->assertSet('mountedActions', [])
            ->assertSee('Кнопка')->assertSee('amoCRM · Кнопка в сделке');
        $this->assertTrue($page->instance()->configureTriggerAction()->isHidden());
    }

    public function test_the_date_trigger_is_not_offered_or_accepted_as_a_new_selection(): void
    {
        Livewire::test(WorkflowCanvasFixture::class)
            ->assertDontSee('date-condition', false)
            ->call('selectTriggerType', 'date-condition')
            ->assertSet('trigger.type', 'manual');
    }

    public function test_titles_use_below_tile_captions(): void
    {
        $html = Livewire::test(WorkflowCanvasFixture::class)->html();
        $this->assertSame(4, substr_count($html, 'class="workflow-node-card__body workflow-node-card__caption"'));
    }
}
