<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Actions\ControlConditionAction;
use App\Workflows\Context\WorkflowContext;
use Tests\TestCase;

class ControlConditionActionTest extends TestCase
{
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

    public function test_it_returns_error_when_conditions_are_empty(): void
    {
        $result = (new ControlConditionAction())->handle([
            'conditions' => [],
        ], new WorkflowContext());

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }
}
