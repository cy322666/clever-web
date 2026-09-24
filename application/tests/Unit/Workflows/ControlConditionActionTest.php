<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Actions\ControlConditionAction;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Actions\WorkflowConditionPreview;
use Livewire\Livewire;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\Support\WorkflowCanvasFixture;
use Tests\TestCase;

class ControlConditionActionTest extends TestCase
{
    public function test_flag_operators_are_selectable_and_save_without_a_second_value(): void
    {
        WorkflowCanvasDatabase::prepare();

        foreach (['is_true' => 'Да', 'is_false' => 'Нет'] as $operator => $label) {
            $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [[
                'id' => 'flag-condition', 'type' => 'control-condition',
                'config' => ['logic' => 'and', 'conditions' => [[
                    'left' => '{{lead.cf(123)}}', 'operator' => 'equals', 'right' => '1',
                ]]],
            ]]])->call('openWorkflowActionEditor', 'flag-condition');

            $rules = $page->get('mountedActions.0.data.conditions');
            $key = array_key_first($rules);
            $field = function (string $name) use (&$page) {
                $schema = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName());
                return collect($schema->getFlatFields(withHidden: true))
                    ->first(fn ($component) => $component->getName() === $name);
            };
            $this->assertSame($label, $field('operator')->getOptions()[$operator]);
            $this->assertTrue($field('right')->isVisible());
            $page->set("mountedActions.0.data.conditions.$key.operator", $operator);
            $this->assertFalse($field('right')->isVisible());
            $page->call('callMountedAction')->assertHasNoErrors();

            $config = $page->get('workflowActions.0.config');
            $this->assertSame($operator, array_values($config['conditions'])[0]['operator']);
            $preview = WorkflowConditionPreview::rows($config);
            $this->assertSame($label, $preview[0]['operator']);
            $this->assertNull($preview[0]['right']);

            $page = Livewire::test(WorkflowCanvasFixture::class, ['workflowActions' => [[
                'id' => 'flag-condition', 'type' => 'control-condition', 'config' => $config,
            ]]])->call('openWorkflowActionEditor', 'flag-condition');
            $this->assertSame($operator, $field('operator')->getState());
            $this->assertFalse($field('right')->isVisible());
        }
    }

    public function test_flags_accept_amocrm_boolean_and_webhook_values_for_all_entities(): void
    {
        $action = new ControlConditionAction();
        foreach (['lead', 'contact', 'company', 'customer'] as $entity) {
            foreach ([true, false, 1, 0, '1', '0', 'true', 'false', null] as $value) {
                $checked = in_array($value, [true, 1, '1', 'true'], true);
                $context = new WorkflowContext([$entity => ['custom_fields_values' => $value === null ? null : [[
                    'field_id' => 123, 'field_type' => 'checkbox', 'values' => [['value' => $value]],
                ]]]]);
                foreach (['is_true' => $checked, 'is_false' => !$checked] as $operator => $expected) {
                    $condition = ['left' => '{{'.$entity.'.cf(123)}}', 'operator' => $operator];
                    $config = ['conditions' => [$condition]];
                    $resolved = ['conditions' => [[...$condition, 'left' => $context->resolve($condition['left'])]]];
                    $this->assertTrue($action->validateResolvedConfig($config, $resolved)['valid']);
                    $result = $action->handle($config, $context);
                    $this->assertTrue($result['success']);
                    $this->assertSame($expected, $result['output']['passed'], $entity.' '.$operator.' '.var_export($value, true));
                }
            }
        }
    }

    public function test_it_evaluates_condition_and_stores_result_in_context(): void
    {
        $context = new WorkflowContext([
            'lead' => [
                'price' => 15000,
                'source' => 'telegram',
            ],
        ]);

        $result = (new ControlConditionAction())->handle([
            'logic' => 'and',
            'conditions' => [
                [
                    'left' => '{{lead.price}}',
                    'operator' => 'gt',
                    'right' => '10000',
                ],
                [
                    'left' => '{{lead.source}}',
                    'operator' => 'equals',
                    'right' => 'telegram',
                ],
            ],
            'store_result' => true,
            'context_key' => 'price_check',
            'true_actions' => [
                ['id' => 'true-step', 'type' => 'amocrm_create_task'],
            ],
            'false_actions' => [
                ['id' => 'false-step', 'type' => 'amocrm_add_note'],
            ],
        ], $context);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['output']['passed']);
        $this->assertSame('true', $result['output']['branch']);
        $this->assertSame('15000', $result['output']['condition_results'][0]['left_value']);
        $this->assertSame('telegram', $result['output']['condition_results'][1]['right_value']);
        $this->assertSame('true', $context->resolve('{{var.price_check.branch}}'));
    }

    public function test_it_supports_or_logic_lists_and_regex(): void
    {
        $context = new WorkflowContext([
            'lead' => [
                'status_id' => 142,
                'name' => 'Заявка с сайта',
            ],
        ]);

        $result = (new ControlConditionAction())->handle([
            'logic' => 'or',
            'conditions' => [
                [
                    'left' => '{{lead.status_id}}',
                    'operator' => 'in',
                    'right' => '100, 101, 142',
                ],
                [
                    'left' => '{{lead.name}}',
                    'operator' => 'matches',
                    'right' => '/не совпадет/u',
                ],
            ],
            'store_result' => false,
        ], $context);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['output']['passed']);
        $this->assertSame([true, false], array_column($result['output']['condition_results'], 'passed'));
    }

    public function test_it_supports_contains_and_not_contains(): void
    {
        $context = new WorkflowContext(['lead' => ['name' => 'Заявка с сайта']]);

        $result = (new ControlConditionAction())->handle([
            'logic' => 'and',
            'conditions' => [
                ['left' => '{{lead.name}}', 'operator' => 'contains', 'right' => 'с сайта'],
                ['left' => '{{lead.name}}', 'operator' => 'not_contains', 'right' => 'спам'],
            ],
        ], $context);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['output']['passed']);
        $this->assertSame([true, true], array_column($result['output']['condition_results'], 'passed'));
    }

    public function test_it_returns_error_when_conditions_are_empty(): void
    {
        $result = (new ControlConditionAction())->handle([
            'conditions' => [],
        ], new WorkflowContext());

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }
}
