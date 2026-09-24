<?php

namespace Tests\Unit\Workflows;

use App\Models\User;
use App\Workflows\Actions\ControlConditionAction;
use App\Workflows\Actions\WorkflowTriggerConditionVariableCatalog as Catalog;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasFixture;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowConditionFieldOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        Http::preventStrayRequests();
        (require database_path('migrations/2023_09_01_120654_create_fields_table.php'))->up();
        Schema::table('amocrm_fields', fn (Blueprint $table) => $table->boolean('active')->default(true));
        $this->actingAs(User::findOrFail(1));
    }

    public function test_variants_are_scoped_to_the_owner_entity_and_active_field(): void
    {
        foreach (['lead' => 'leads', 'contact' => 'contacts', 'company' => 'companies', 'customer' => 'customers'] as $mask => $entity) {
            foreach (['select', 'radiobutton', 'multiselect'] as $index => $type) {
                $id = 100 + $index;
                $label = $entity.' '.$type;
                $this->field($entity, $id, $type, [['id' => 7, 'value' => $label]]);
                $this->field($entity, $id, $type, [['id' => 8, 'value' => 'Чужой вариант']], user: 2);
                $this->assertSame([$label => $label], Catalog::conditionFieldValueOptions('{{ '.$mask.'.cf( '.$id.' ) }}'));
            }
        }
        $this->field('leads', 200, 'select', [], active: false);
        $this->field('leads', 201, 'text', [['id' => 9, 'value' => 'Не список']]);
        $this->field('leads', 202, 'select', []);
        foreach (['{{lead.cf(200)}}', '{{lead.cf(201)}}', '{{lead.cf(999)}}', '{{lead.name}}', '{{lead.cf(100)|lower}}', 'Текст {{lead.cf(100)}}', '{{ $json.name }}'] as $expression) {
            $this->assertNull(Catalog::conditionFieldValueOptions($expression));
        }
        $this->assertSame([], Catalog::conditionFieldValueOptions('{{lead.cf(202)}}'));
        Http::assertNothingSent();
    }

    public function test_the_form_switches_between_field_variants_free_input_and_expressions(): void
    {
        $this->field('leads', 100, 'select', [['id' => 9, 'value' => 'VIP'], ['id' => 10, 'value' => 'Обычный']]);
        $this->field('contacts', 100, 'radiobutton', [['id' => 11, 'value' => 'Партнёр']]);
        $page = $this->editor('{{lead.cf(100)}}', 'VIP');
        $field = function (string $name) use (&$page) {
            $schema = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName());
            return collect($schema->getFlatFields(withHidden: true))->first(fn ($component) => $component->getName() === $name);
        };
        $key = array_key_first($page->get('mountedActions.0.data.conditions'));
        $this->assertTrue($field('left')->isLive());
        $this->assertTrue($field('right')->hasValueOptions());
        $this->assertSame(['VIP' => 'VIP', 'Обычный' => 'Обычный'], $field('right')->getValueOptions());
        $this->assertStringContainsString('<option value="VIP">VIP</option>', $this->fieldHtml($field('right'), $page));
        $this->assertStringContainsString('>Переменная</button>', $this->fieldHtml($field('right'), $page));

        $page->set("mountedActions.0.data.conditions.$key.right", '{{var.expected}}')
            ->set("mountedActions.0.data.conditions.$key.left", '{{contact.cf(100)}}');
        $this->assertSame(['Партнёр' => 'Партнёр'], $field('right')->getValueOptions());
        $this->assertSame('{{var.expected}}', $field('right')->getState());
        $page->call('callMountedAction')->assertHasNoErrors();
        $config = $page->get('workflowActions.0.config');
        $condition = array_values($config['conditions'])[0];
        $this->assertSame('radiobutton', $condition['left_field_type']);
        $this->assertSame('{{var.expected}}', $condition['right']);

        $page = $this->editor($condition['left'], $condition['right']);
        $this->assertSame('{{var.expected}}', $field('right')->getState());
        $key = array_key_first($page->get('mountedActions.0.data.conditions'));
        $page->set("mountedActions.0.data.conditions.$key.left", '{{lead.name}}');
        $this->assertFalse($field('right')->hasValueOptions());
        $this->assertSame('{{var.expected}}', $field('right')->getState());
    }

    public function test_selected_labels_are_saved_and_compare_with_actual_amocrm_values(): void
    {
        $label = 'VIP "Партнёр"';
        $this->field('companies', 100, 'select', [['id' => 777, 'value' => $label]]);
        $page = $this->editor('{{company.cf(100)}}', $label)->call('callMountedAction')->assertHasNoErrors();
        $config = $page->get('workflowActions.0.config');
        $condition = array_values($config['conditions'])[0];
        $this->assertSame($label, $condition['right']);
        $this->assertSame('select', $condition['left_field_type']);
        $context = new WorkflowContext(['company' => ['custom_fields_values' => [[
            'field_id' => 100, 'values' => [['value' => $label, 'enum_id' => 777]],
        ]]]]);
        $result = (new ControlConditionAction)->handle($context->resolve($config), $context);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['output']['passed']);
        $context->setVariable('expected', $label);
        $config['conditions'] = [[...$condition, 'right' => '{{var.expected}}']];
        $this->assertTrue((new ControlConditionAction)->handle($context->resolve($config), $context)['output']['passed']);
    }

    public function test_multiselect_contains_matches_whole_variants_with_one_or_many_selected(): void
    {
        $this->field('leads', 100, 'multiselect', [['id' => 7, 'value' => 'VIP'], ['id' => 8, 'value' => 'VIP Plus']]);
        $page = $this->editor('{{lead.cf(100)}}', 'VIP', 'contains')->call('callMountedAction')->assertHasNoErrors();
        $config = $page->get('workflowActions.0.config');
        $this->assertSame('multiselect', array_values($config['conditions'])[0]['left_field_type']);
        foreach ([[['VIP Plus'], false], [['VIP', 'VIP Plus'], true], [['VIP'], true], [[], false]] as [$values, $expected]) {
            $context = new WorkflowContext(['lead' => ['custom_fields_values' => [[
                'field_id' => 100, 'values' => array_map(fn ($value) => ['value' => $value], $values),
            ]]]]);
            foreach (['contains' => $expected, 'not_contains' => !$expected] as $operator => $passed) {
                $rules = array_values($config['conditions']);
                $rules[0]['operator'] = $operator;
                $result = (new ControlConditionAction)->handle($context->resolve(['conditions' => $rules]), $context);
                $this->assertTrue($result['success']);
                $this->assertSame($passed, $result['output']['passed']);
            }
        }
    }

    private function editor(string $left, string $right, string $operator = 'equals')
    {
        return Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [[
            'id' => 'enum-condition', 'type' => 'control-condition',
            'config' => ['logic' => 'and', 'conditions' => [compact('left', 'right', 'operator')]],
        ]]])->call('openWorkflowActionEditor', 'enum-condition');
    }

    private function field(string $entity, int $id, string $type, array $enums, int $user = 1, bool $active = true): void
    {
        DB::table('amocrm_fields')->insert(['user_id' => $user, 'entity_type' => $entity, 'field_id' => $id,
            'type' => $type, 'name' => 'Поле '.$id, 'enums' => json_encode($enums, JSON_UNESCAPED_UNICODE), 'active' => $active]);
    }

    private function fieldHtml($field, $page): string
    {
        $previous = view()->shared('errors');
        view()->share('errors', (new \Illuminate\Support\ViewErrorBag)->put('default', $page->instance()->getErrorBag()));
        try {
            return $field->toHtml();
        } finally {
            view()->share('errors', $previous);
        }
    }
}
