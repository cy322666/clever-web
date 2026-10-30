<?php

namespace Tests\Unit\Workflows;

use App\Services\Workflows\WorkflowDefinitionValidator;
use App\Services\Workflows\WorkflowHttpHeaders;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class WorkflowCompactControlsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        Http::preventStrayRequests();
    }

    private function editor(string $type, array $config = [])
    {
        return Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [['id' => 'node', 'type' => $type, 'config' => $config]]])
            ->call('openWorkflowActionEditor', 'node');
    }

    private function form($page): string
    {
        // Livewire 4 updates the inspector without rendering the canvas again.
        return implode('', $page->effects['partials'] ?? []);
    }

    public function test_assignment_form_has_no_redundant_heading_or_annotation(): void
    {
        $html = $this->form($this->editor('workflow_set_variable'));
        $this->assertStringNotContainsString('В каждой ветке', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*Переменные\s*</u', $html);
        $this->assertStringContainsString('Добавить переменную', $html);
    }

    public function test_condition_logic_uses_separate_buttons_and_both_values_save(): void
    {
        foreach (['and', 'or'] as $logic) {
            $page = $this->editor('control-condition', ['logic' => $logic, 'conditions' => [['left' => 1, 'operator' => 'equals', 'right' => 1]]]);
            $html = $this->form($page);
            $this->assertStringContainsString('fi-fo-toggle-buttons', $html);
            // Count bound controls in the mounted PHP form, excluding inert local templates.
            $this->assertSame(2, preg_match_all('/<input\b(?=[^>]*fi-fo-toggle-buttons-input)(?=[^>]*wire:model)[^>]*>/s', $html));
            $this->assertDoesNotMatchRegularExpression('/class="[^"]*fi-fo-toggle-buttons[^\"]*fi-btn-group/', $html);
            $this->assertStringNotContainsString('Все условия (И)', $html);
            $this->assertStringContainsString('workflow-value-field__header--unlabeled', $html);
            $this->assertDoesNotMatchRegularExpression('/<label\b[^>]*>\s*Значение [12]/u', $html);
            $this->assertStringContainsString('aria-label="Значение 1"', $html);
            $this->assertStringContainsString('aria-label="Значение 2"', $html);
            $page->call('callMountedAction')->assertHasNoErrors();
            $this->assertSame($logic, $page->get('workflowActions')[0]['config']['logic']);
        }
    }

    public function test_loop_form_has_no_annotations_and_preserves_input_and_mode(): void
    {
        foreach (['all', 'once'] as $mode) {
            $items = '[{"id":17},{"id":18}]';
            $page = $this->editor('workflow_loop', ['items' => $items, 'mode' => $mode, 'require_return' => true]);
            $html = $this->form($page);
            $this->assertStringContainsString('Что подать в цикл', $html);
            $this->assertStringContainsString('Сколько элементов обработать', $html);
            $this->assertStringNotContainsString('Выберите список из предыдущей ноды', $html);
            $this->assertStringNotContainsString('Соедините конец ветки', $html);
            $page->call('callMountedAction')->assertHasNoErrors();
            $config = $page->get('workflowActions')[0]['config'];
            $this->assertSame($items, $config['items']);
            $this->assertSame($mode, $config['mode']);
            $this->assertTrue($config['require_return']);
        }
    }

    public function test_contact_source_uses_separate_buttons_and_preserves_source_and_id(): void
    {
        foreach (['contact' => 'contact_id', 'lead' => 'lead_id'] as $source => $id) {
            $page = $this->editor('amocrm_contact_leads', ['source' => $source, $id => '{{ $json.id }}']);
            $html = $this->form($page);
            $this->assertStringContainsString('fi-fo-toggle-buttons', $html);
            $this->assertSame(2, preg_match_all('/<input\b(?=[^>]*fi-fo-toggle-buttons-input)(?=[^>]*wire:model)[^>]*>/s', $html));
            $this->assertDoesNotMatchRegularExpression('/class="[^"]*fi-fo-toggle-buttons[^"]*fi-btn-group/', $html);
            $this->assertStringContainsString('Контакт сделки', $html);
            $this->assertStringNotContainsString('Указать ID контакта', $html);
            $page->call('callMountedAction')->assertHasNoErrors();
            $config = $page->get('workflowActions')[0]['config'];
            $this->assertSame($source, $config['source']);
            $this->assertSame('{{ $json.id }}', $config[$id]);
        }
    }

    public function test_query_filters_have_no_annotations_and_preserve_filter_and_pagination(): void
    {
        foreach ([
            ['amocrm_query_leads', []],
            ['amocrm_read', ['operation' => 'contacts.list', 'body_mode' => 'builder']],
            ['amocrm_read', ['operation' => 'tasks.list', 'body_mode' => 'builder']],
        ] as [$type, $config]) {
            $page = $this->editor($type, $config + [
                'filters' => [['field' => 'id', 'operator' => 'eq', 'value' => '42']],
                'limit' => '1',
                'page' => '2',
            ]);
            $html = $this->form($page);
            $this->assertStringContainsString('Фильтры', $html);
            $this->assertStringContainsString('Добавить фильтр', $html);
            foreach (['Разные поля', 'Повторные значения', 'Расширенные фильтры', 'Для ID сущности укажите её тип', 'API-фильтры должны быть доступны'] as $annotation) {
                $this->assertStringNotContainsString($annotation, $html);
            }
            $page->call('callMountedAction')->assertHasNoErrors();
            $saved = $page->get('workflowActions')[0]['config'];
            $this->assertCount(1, $saved['filters']);
            $this->assertSame('id', $saved['filters'][0]['field']);
            $this->assertSame('eq', $saved['filters'][0]['operator']);
            $this->assertSame('42', $saved['filters'][0]['value']);
            $this->assertSame('1', $saved['limit']);
            $this->assertSame('2', $saved['page']);
        }
    }

    public function test_entity_forms_remove_summary_panels_but_keep_manual_and_expression_values(): void
    {
        foreach (['amocrm_create_lead', 'amocrm_create_contact', 'amocrm_create_company', 'amocrm_update_contact_fields'] as $type) {
            $page = $this->editor($type, ['name' => '{{ $json.name }}', 'entity_source' => 'manual', 'target_entity' => 'lead', 'target_entity_id' => '{{ $vars.contact_id }}']);
            $html = $this->form($page);
            foreach (['workflow-entity-context', '>Основное<', '>Сущность<', 'ID появится из входа ноды', 'Новая сущность будет сразу'] as $removed) {
                $this->assertStringNotContainsString($removed, $html, $type);
            }
            $this->assertStringContainsString('Вручную', $html);
            $this->assertStringContainsString('Переменная', $html);
            if ($type === 'amocrm_create_lead') $this->assertStringContainsString('Поля сделки', $html);
            if ($type === 'amocrm_create_contact') {
                $page->call('callMountedAction')->assertHasNoErrors();
                $this->assertSame('{{ $json.name }}', $page->get('workflowActions')[0]['config']['name']);
                $this->assertSame('{{ $vars.contact_id }}', $page->get('workflowActions')[0]['config']['target_entity_id']);
            }
        }
    }

    public function test_header_form_keeps_legacy_config_and_saves_rows_with_expressions(): void
    {
        foreach (['{"ACCESS_TOKEN":"test"}', ['X-Test' => 0], '{{ $json.headers }}'] as $headers) {
            $page = $this->editor('http_request', ['url' => 'https://example.test', 'method' => 'GET', 'headers' => $headers]);
            $html = $this->form($page);
            $this->assertStringContainsString('Добавить заголовок', $html);
            $this->assertStringNotContainsString('Заголовки · JSON', $html);
            $page->call('callMountedAction')->assertHasNoErrors();
            $this->assertSame($headers, $page->get('workflowActions')[0]['config']['headers']);
        }
        $rows = [['name' => 'X-User', 'value' => '{{ $json.id }}'], ['name' => 'ACCESS_TOKEN', 'value' => 'test']];
        $page = $this->editor('http_request', ['url' => 'https://example.test', 'method' => 'GET'])
            ->set('mountedActions.0.data.headers', $rows)->call('callMountedAction')->assertHasNoErrors();
        $this->assertSame($rows, $page->get('workflowActions')[0]['config']['headers']);
        $this->assertSame([], WorkflowDefinitionValidator::configIssues('http_request', ['url' => 'https://example.test', 'headers' => $rows]));
    }

    public function test_invalid_header_rows_cannot_be_saved_or_activated(): void
    {
        foreach ([
            [['name' => '', 'value' => 'test']],
            [['name' => 'Host', 'value' => 'internal']],
            [['name' => 'X-Test', 'value' => 'a'], ['name' => 'x-test', 'value' => 'b']],
            [['name' => 'X-Test', 'value' => "value\r\nInjected: yes"]],
        ] as $rows) {
            $this->editor('http_request', ['url' => 'https://example.test', 'method' => 'GET', 'headers' => $rows])
                ->call('callMountedAction')->assertHasErrors(['mountedActions.0.data.headers']);
            $this->assertNotEmpty(WorkflowDefinitionValidator::configIssues('http_request', ['url' => 'https://example.test', 'headers' => $rows]));
        }
        Http::assertNothingSent();
        $this->assertSame(['X-Zero' => 0, 'X-False' => false], WorkflowHttpHeaders::parse([['name' => 'X-Zero', 'value' => 0], ['name' => 'X-False', 'value' => false]]));
    }
}
