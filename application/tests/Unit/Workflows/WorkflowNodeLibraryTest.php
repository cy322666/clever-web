<?php

namespace Tests\Unit\Workflows;

use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowNodeLibraryTest extends TestCase
{
    public function test_create_contact_is_visible_in_contact_group_and_search_and_can_be_configured(): void
    {
        $html = $this->renderNodeLibrary([
            ['type' => 'amocrm_create_contact', 'name' => 'Создать контакт'],
            ['type' => 'amocrm_update_contact_fields', 'name' => 'Обновить контакт'],
        ], true);
        $this->assertSame(1, substr_count($html, '<strong>Создать</strong>'));
        $this->assertSame(1, substr_count($html, '<strong>Обновить</strong>'));
        $this->assertStringNotContainsString('Создание и поля контакта', $html);
        $this->assertStringContainsString('selectEntity(', $html);

        \Tests\Support\WorkflowListDatabase::prepare();
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $page = Livewire::test(\Tests\Support\WorkflowCanvasFixture::class)
            ->assertSeeHtml('<strong>Создать</strong>')
            ->call('openDetachedActionPalette')
            ->call('selectActionType', 'amocrm_create_contact');
        $nodes = \App\Services\Workflows\WorkflowGraph::nodes($page->get('workflowActions'));
        $action = end($nodes)['step'];
        $this->assertSame('amocrm_create_contact', $action['type']);
        $page->call('openWorkflowActionEditor', $action['id'])
            ->assertStatus(200)
            ->set('mountedActions.0.data.name', 'Тестовый контакт')
            ->set('mountedActions.0.data.target_entity', 'lead')
            ->set('mountedActions.0.data.target_entity_id', '42')
            ->call('callMountedAction')->assertHasNoErrors();
        $config = \App\Services\Workflows\WorkflowGraph::nodes($page->get('workflowActions'))['action:'.$action['id']]['step']['config'];
        $this->assertSame('Тестовый контакт', $config['name']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_query_menu_and_search_only_render_available_operations(): void
    {
        $html = $this->renderNodeLibrary([], false);
        foreach (\App\Services\Workflows\WorkflowAmoReadCatalog::operations() as $key => $item) {
            $available = isset(\App\Services\Workflows\WorkflowNodeCatalog::readOperations()[$key]);
            $this->assertSame($available, str_contains($html, "'selectReadOperation', ".\Illuminate\Support\Js::from($key)->toHtml().')'), $key);
        }
        $this->assertStringNotContainsString('<strong>Примечания</strong>', $html);
        $this->assertStringNotContainsString('<strong>Теги</strong>', $html);
        $this->assertStringContainsString('<strong>Контакт</strong>', $html);
        $this->assertStringNotContainsString(' · GET', $html);
        $this->assertStringNotContainsString('GET-запрос', $html);
        $this->assertStringNotContainsString('<strong>Запросы amoCRM</strong>', $html);
        $this->assertStringNotContainsString('Получить сущность или список', $html);
        $this->assertStringContainsString("'selectReadOperation'", $html);
    }

    public function test_context_catalog_contains_every_category_without_a_second_sidebar(): void
    {
        $html = $this->renderNodeLibrary([['type' => 'control-condition', 'name' => 'Условие']], false);
        $this->assertStringContainsString('aria-label="Категории нод"', $html);
        $this->assertStringNotContainsString('workflow-node-library__rail', $html);
        foreach (['amoCRM', 'Сервисы'] as $category) {
            $this->assertStringContainsString('<span>'.$category.'</span>', $html);
        }
        $this->assertStringNotContainsString('<span>Запросы</span>', $html);
        $this->assertStringContainsString('<span>Инструменты</span>', $html);
        $this->assertStringContainsString('<span>Сервисы</span><small>1</small>', $html);
        $this->assertStringContainsString('selectCategory(', $html);
        $this->assertStringContainsString('selectEntity(', $html);
        $this->assertStringContainsString('workflow-masks-open', $html);
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
            ['type' => 'amocrm_create_company', 'name' => 'Создать компанию'],
            ['type' => 'amocrm_link_entity', 'name' => 'Связать сущности'],
        ];

        $html = $this->renderNodeLibrary($actions, true);

        foreach ([
            'Проверить условие' => 1, 'Обновить' => 1, 'Изменить этап' => 1, 'Создать' => 2,
            'Добавить примечание' => 1, 'Сменить теги' => 1, 'Связать сущности' => 1,
        ] as $label => $count) {
            $this->assertSame($count, substr_count($html, '<strong>'.$label.'</strong>'));
        }

        foreach (['Сделка', 'Задача', 'Компания', 'Общие'] as $group) {
            $this->assertStringContainsString('<span><strong>'.$group.'</strong></span>', $html);
        }
    }

    public function test_company_creation_node_can_be_added_from_the_real_catalog(): void
    {
        \Tests\Support\WorkflowListDatabase::prepare();
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        $page = Livewire::test(\Tests\Support\WorkflowCanvasFixture::class)
            ->assertSeeHtml('<strong>Создать</strong>')
            ->call('openDetachedActionPalette')
            ->call('selectActionType', 'amocrm_create_company');

        $nodes = \App\Services\Workflows\WorkflowGraph::nodes($page->get('workflowActions'));
        $action = end($nodes)['step'];
        $this->assertSame('amocrm_create_company', $action['type']);

        $page->call('openWorkflowActionEditor', $action['id'])
            ->assertStatus(200)
            ->set('mountedActions.0.data.name', 'ООО Тест')
            ->set('mountedActions.0.data.target_entity', 'lead')
            ->set('mountedActions.0.data.target_entity_id', '42')
            ->call('callMountedAction')
            ->assertHasNoErrors();

        $nodes = \App\Services\Workflows\WorkflowGraph::nodes($page->get('workflowActions'));
        $this->assertSame('ООО Тест', $nodes['action:'.$action['id']]['step']['config']['name']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_universal_entity_link_node_can_be_added_and_configured(): void
    {
        \Tests\Support\WorkflowListDatabase::prepare();
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        $page = Livewire::test(\Tests\Support\WorkflowCanvasFixture::class)
            ->assertSee('Связать сущности')
            ->call('openDetachedActionPalette')
            ->call('selectActionType', 'amocrm_link_entity');

        $nodes = \App\Services\Workflows\WorkflowGraph::nodes($page->get('workflowActions'));
        $action = end($nodes)['step'];
        $this->assertSame('amocrm_link_entity', $action['type']);

        $page->call('openWorkflowActionEditor', $action['id'])
            ->set('mountedActions.0.data.entity_source', 'manual')
            ->set('mountedActions.0.data.target_entity', 'lead')
            ->set('mountedActions.0.data.target_entity_id', '42')
            ->set('mountedActions.0.data.linked_entity', 'company')
            ->set('mountedActions.0.data.linked_entity_id', '77')
            ->call('callMountedAction')
            ->assertHasNoErrors();

        $nodes = \App\Services\Workflows\WorkflowGraph::nodes($page->get('workflowActions'));
        $config = $nodes['action:'.$action['id']]['step']['config'];
        $this->assertSame('lead', $config['target_entity']);
        $this->assertSame('42', $config['target_entity_id']);
        $this->assertSame('company', $config['linked_entity']);
        $this->assertSame('77', $config['linked_entity_id']);
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    public function test_tags_action_can_be_added_from_the_real_catalog_and_configured(): void
    {
        \Tests\Support\WorkflowListDatabase::prepare();
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        $page = Livewire::test(\Tests\Support\WorkflowCanvasFixture::class)
            ->assertSee('Сменить теги')
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

    public function test_existing_contact_lookup_stays_editable_after_its_duplicate_menu_entry_is_removed(): void
    {
        \Tests\Support\WorkflowListDatabase::prepare();
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        $page = Livewire::test(\Tests\Support\WorkflowCanvasFixture::class, ['workflowActions' => [[
            'id' => 'legacy-contact', 'type' => 'amocrm_get_contact', 'name' => 'Старый поиск',
            'config' => ['target_entity' => 'contact', 'entity_source' => 'manual', 'target_entity_id' => '42'],
        ]]])->assertSee('Старый поиск')->assertSeeHtml('<strong>Обновить</strong>');

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
        $this->assertStringContainsString('Найти действие или сервис', $html);
    }

    public function test_removed_nodes_are_not_offered(): void
    {
        $html = $this->renderNodeLibrary([
            ['type'=>'workflow_filter_list','name'=>'Фильтр списка'],
            ['type'=>'run_workflow','name'=>'Запустить процесс'],
            ['type'=>'workflow_call','name'=>'Запустить другую ветку'],
        ], true);
        $this->assertStringNotContainsString('Фильтр списка', $html);
        $this->assertStringNotContainsString('<strong>Запустить процесс</strong>', $html);
        $this->assertStringNotContainsString('<strong>Запустить другую ветку</strong>', $html);
        $this->assertContains('run_workflow', \App\Workflows\Actions\WorkflowAmoCrmActionCatalog::unsupportedWorkflowTypes());
    }

    public function test_start_menu_only_offers_core_events_and_keeps_rare_triggers_registered(): void
    {
        $registry = app(\Leek\FilamentWorkflows\Triggers\TriggerRegistry::class);
        $triggers = $registry->getAllWithMetadata();
        $html = $this->renderNodeLibrary([], false, $triggers);
        $offered = [];
        foreach ($triggers as $trigger) {
            $type = $trigger['type'];
            $visible = str_contains($html, "'selectTriggerType', ".\Illuminate\Support\Js::from($type)->toHtml().',');
            $this->assertSame(\App\Services\Workflows\WorkflowNodeCatalog::includesTrigger($type), $visible, $type);
            if ($visible && str_starts_with($type, 'amocrm-')) $offered[] = $type;
        }
        $this->assertCount(9, $offered);
        $this->assertNotNull($registry->get('amocrm-delete-contact'));
        $this->assertNotNull($registry->get('amocrm-add-customer'));
        $this->assertArrayHasKey('customers.list', \App\Services\Workflows\WorkflowAmoReadCatalog::operations());
        $this->assertSame(['leads.list', 'contacts.list', 'companies.list', 'tasks.list'], array_keys(\App\Services\Workflows\WorkflowNodeCatalog::readOperations()));
    }

    private function renderNodeLibrary(array $actions, bool $hasTrigger, array $triggers = []): string
    {
        return Livewire::test(new class extends Component
        {
            public array $actions = [];

            public array $triggers = [];

            public bool $hasTrigger = false;

            public function render(): View
            {
                return view('filament-workflows::components.workflows.node-library');
            }
        }, compact('actions', 'hasTrigger', 'triggers'))->assertStatus(200)->html();
    }
}
