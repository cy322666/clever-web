<?php

namespace Tests\Unit\Workflows;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowNodeLibraryTest extends TestCase
{
    public function test_query_menu_and_search_only_render_available_operations(): void
    {
        $html = $this->renderNodeLibrary([], false);
        foreach (\App\Services\Workflows\WorkflowAmoReadCatalog::operations() as $key => $item) {
            $available = isset(\App\Services\Workflows\WorkflowAmoReadCatalog::availableOperations()[$key]);
            $this->assertSame($available, str_contains($html, 'selectReadOperation('.\Illuminate\Support\Js::from($key)->toHtml().')'), $key);
        }
        $this->assertStringContainsString('<strong>Примечания</strong>', $html);
        $this->assertStringContainsString('<strong>Теги</strong>', $html);
        $this->assertStringContainsString('<small>Контакты</small>', $html);
        $this->assertStringNotContainsString(' · GET', $html);
        $this->assertStringNotContainsString('GET-запрос', $html);
        $this->assertStringContainsString('<strong>Запрос amoCRM</strong>', $html);
    }

    public function test_it_renders_actions_from_all_entity_groups_and_in_search(): void
    {
        $actions = [
            ['type' => 'control-condition', 'name' => 'Проверить условие'],
            ['type' => 'amocrm_update_lead_fields', 'name' => 'Обновить поля сделки'],
            ['type' => 'amocrm_change_lead_status', 'name' => 'Изменить этап сделки'],
            ['type' => 'amocrm_create_task', 'name' => 'Создать задачу'],
            ['type' => 'amocrm_add_note', 'name' => 'Добавить примечание'],
            ['type' => 'amocrm_change_tags', 'name' => 'Сменить теги'],
        ];

        $html = $this->renderNodeLibrary($actions, true);

        foreach ($actions as $action) {
            $this->assertSame(2, substr_count($html, '<strong>' . $action['name'] . '</strong>'));
        }

        $this->assertStringContainsString('Сделка · действие', $html);
        $this->assertStringContainsString('Задача · действие', $html);
        $this->assertStringContainsString('Примечание · действие', $html);
        $this->assertStringContainsString('Теги · действие', $html);
        $this->assertStringContainsString('<strong>Теги</strong>', $html);
    }

    public function test_tags_action_can_be_added_from_the_real_catalog_and_configured(): void
    {
        \Tests\Support\WorkflowListDatabase::prepare();
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        $page = Livewire::test(\Tests\Support\WorkflowCanvasFixture::class)
            ->assertSee('Теги · действие')
            ->call('openDetachedActionPalette')
            ->call('selectActionType', 'amocrm_change_tags');
        $nodes = \App\Services\Workflows\WorkflowGraph::nodes($page->get('workflowActions'));
        $action = end($nodes)['step'];
        $this->assertSame('amocrm_change_tags', $action['type']);
        $page->call('openWorkflowActionEditor', $action['id'])
            ->assertStatus(200)
            ->assertSet('mountedActions.0.name', 'configureWorkflowAction')
            ->set('mountedActions.0.data.entity_source', 'manual')
            ->set('mountedActions.0.data.target_entity', 'lead')
            ->set('mountedActions.0.data.target_entity_id', '123')
            ->set('mountedActions.0.data.tags_to_add', 'VIP, Новый')
            ->set('mountedActions.0.data.tags_to_remove', 'Старый')
            ->set('mountedActions.0.data.remove_all', false)
            ->call('callMountedAction')->assertHasNoErrors();
        $config = \App\Services\Workflows\WorkflowGraph::nodes($page->get('workflowActions'))['action:'.$action['id']]['step']['config'];
        $this->assertSame('VIP, Новый', $config['tags_to_add']);
        $this->assertSame('Старый', $config['tags_to_remove']);
        $this->assertFalse($config['remove_all']);
        $page->call('openWorkflowActionEditor', $action['id'])
            ->set('mountedActions.0.data.remove_all', true)
            ->call('callMountedAction')->assertHasNoErrors();
        $config = \App\Services\Workflows\WorkflowGraph::nodes($page->get('workflowActions'))['action:'.$action['id']]['step']['config'];
        $this->assertTrue($config['remove_all']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_contact_get_and_update_nodes_are_available_from_the_real_catalog(): void
    {
        \Tests\Support\WorkflowListDatabase::prepare();
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        $page = Livewire::test(\Tests\Support\WorkflowCanvasFixture::class)
            ->assertSee('Получить контакт')
            ->assertSee('Обновить контакт')
            ->call('openDetachedActionPalette', 'query')
            ->call('selectActionType', 'amocrm_get_contact');

        $nodes = \App\Services\Workflows\WorkflowGraph::nodes($page->get('workflowActions'));
        $action = end($nodes)['step'];
        $this->assertSame('amocrm_get_contact', $action['type']);
        $this->assertSame('contact', $action['config']['target_entity']);

        $page->call('openWorkflowActionEditor', $action['id'])
            ->set('mountedActions.0.data.entity_source', 'manual')
            ->set('mountedActions.0.data.target_entity_id', '77')
            ->call('callMountedAction')
            ->assertHasNoErrors();

        $config = \App\Services\Workflows\WorkflowGraph::nodes($page->get('workflowActions'))['action:'.$action['id']]['step']['config'];
        $this->assertSame('77', $config['target_entity_id']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_it_renders_with_an_empty_action_catalog(): void
    {
        $html = $this->renderNodeLibrary([], false);

        $this->assertStringNotContainsString('Добавить узел', $html);
        $this->assertStringContainsString('Сервисы', $html);
        $this->assertStringContainsString('Поиск узлов', $html);
    }

    public function test_removed_process_launch_nodes_are_not_offered(): void
    {
        $html = $this->renderNodeLibrary([
            ['type'=>'run_workflow','name'=>'Запустить процесс'],
            ['type'=>'workflow_call','name'=>'Запустить другую ветку'],
        ], true);
        $this->assertStringNotContainsString('<strong>Запустить процесс</strong>', $html);
        $this->assertStringNotContainsString('<strong>Запустить другую ветку</strong>', $html);
        $this->assertContains('run_workflow', \App\Workflows\Actions\WorkflowAmoCrmActionCatalog::unsupportedWorkflowTypes());
    }

    private function renderNodeLibrary(array $actions, bool $hasTrigger): string
    {
        return Livewire::test(new class extends Component
        {
            public array $actions = [];

            public bool $hasTrigger = false;

            public function render(): View
            {
                return view('filament-workflows::components.workflows.node-library');
            }
        }, compact('actions', 'hasTrigger'))->assertStatus(200)->html();
    }
}
