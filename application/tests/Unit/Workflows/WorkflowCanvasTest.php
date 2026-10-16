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

    public function test_grid_uses_a_shared_vector_pattern_outside_the_node_stage(): void
    {
        $html = Livewire::test(WorkflowCanvasFixture::class)->assertStatus(200)->html();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $grid = $xpath->query('//*[@x-ref="viewport"]/svg[@class="workflow-canvas-grid"]');
        $this->assertCount(1, $grid);
        $this->assertSame('true', $grid[0]->getAttribute('aria-hidden'));
        $this->assertTrue($grid[0]->hasAttribute('wire:ignore'));
        $this->assertStringContainsString('x-bind:pattern-transform.camel="gridTransform()"', $html);
        $this->assertStringNotContainsString('gridStyle()', $html);
    }

    public function test_it_renders_hover_controls_only_for_existing_connections(): void
    {
        Livewire::test(WorkflowCanvasFixture::class)
            ->assertStatus(200)
            ->assertSee('canvasEdges(', false)
            ->assertSee('x-bind:data-workflow-edge-target="edge.targetId"', false)
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
            ->assertSee('canvasEdges(', false)
            ->assertSee('x-bind:data-workflow-edge-port="edge.sourcePort"', false)
            ->assertSee('Добавить действие');
    }

    public function test_action_hover_tools_have_four_direct_icons_without_an_overflow_menu(): void
    {
        $html = Livewire::test(WorkflowCanvasFixture::class)->assertStatus(200)->html();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $toolbars = $xpath->query('//div[@class="workflow-node-card__tools" and not(ancestor::template) and button[starts-with(@aria-label, "Скопировать ноду:")]]');
        $this->assertCount(3, $toolbars);
        foreach ($toolbars as $toolbar) {
            $buttons = $xpath->query('./button', $toolbar);
            $this->assertCount(4, $buttons);
            $this->assertStringContainsString('runWorkflowCanvasNode', $buttons[0]->getAttribute('wire:click.stop'));
            $this->assertStringContainsString('workflowNodeIsRunning($wire)', $buttons[0]->getAttribute('x-bind:disabled'));
            $this->assertStringContainsString('removeCanvasAction', $buttons[1]->getAttribute('x-on:click.stop'));
            $this->assertStringContainsString('toggleCanvasAction', $buttons[2]->getAttribute('x-on:click.stop'));
            $this->assertFalse($buttons[1]->hasAttribute('wire:click.stop'));
            $this->assertFalse($buttons[2]->hasAttribute('wire:click.stop'));
            $this->assertStringContainsString('duplicateWorkflowActionStep', $buttons[3]->getAttribute('x-on:click.stop'));
            $this->assertStringContainsString('Скопировать ноду', $buttons[3]->getAttribute('aria-label'));
            $this->assertCount(0, $xpath->query('./div[@role="menu"]', $toolbar));
        }
    }

    public function test_read_only_cards_do_not_render_the_hover_menu(): void
    {
        $html = \Illuminate\Support\Facades\Blade::render('<x-filament-workflows::workflows.action-card :action="$action" :read-only="true" />', [
            'action' => ['id' => 'readonly', 'type' => 'http_request', 'name' => 'Запрос'],
        ]);
        $this->assertStringNotContainsString('workflowNodeMenu()', $html);
        $this->assertStringNotContainsString('role="menu"', $html);
    }

    public function test_editor_keeps_one_reference_button_and_moves_run_to_canvas(): void
    {
        $html = Livewire::test(WorkflowCanvasFixture::class)->assertStatus(200)->html();
        $this->assertSame(1, substr_count($html, 'data-workflow-reference-toggle'));
        $this->assertStringContainsString('class="workflow-canvas-run"', $html);
        $this->assertStringNotContainsString('Выровнять блоки', $html);
        $this->assertStringNotContainsString('aria-label="Подключения"', $html);

        $settings = Livewire::test(WorkflowCanvasFixture::class)->call('openWorkflowActionEditor', 'task')->html();
        $this->assertStringNotContainsString('wire:click="runEditingWorkflowNode"', $settings);
        $this->assertStringNotContainsString('wire:click="refreshEditingNodeReferences"', $settings);
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
            ->assertSee('canvasEdges(', false)
            ->assertSee('Добавить действие');
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
            ->assertSee('aria-label="Справочники"', false)
            ->assertSee('x-bind:aria-label="mode === \'variables\' ? \'Справочники\' : \'Каталог нод\'"', false)
            ->assertDontSee('workflow-node-library__rail', false)
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
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $this->assertCount(4, (new \DOMXPath($document))->query('//div[@class="workflow-node-card__body workflow-node-card__caption" and not(ancestor::template)]'));
    }
}
