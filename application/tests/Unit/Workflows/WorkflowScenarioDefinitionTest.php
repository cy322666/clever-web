<?php

namespace Tests\Unit\Workflows;

use App\Models\Workflows\Workflow;
use PHPUnit\Framework\TestCase;

class WorkflowScenarioDefinitionTest extends TestCase
{
    public function test_it_detects_configured_actions_in_workflow_definition(): void
    {
        $this->assertFalse(Workflow::definitionHasConfiguredActions(null));
        $this->assertFalse(Workflow::definitionHasConfiguredActions([
            'trigger' => ['type' => 'manual'],
            'actions' => [],
        ]));
        $this->assertFalse(Workflow::definitionHasConfiguredActions([
            'actions' => [
                ['id' => 'empty-step', 'type' => null],
                ['id' => 'blank-step', 'type' => ''],
            ],
        ]));

        $this->assertTrue(Workflow::definitionHasConfiguredActions([
            'actions' => [
                [
                    'id' => 'create-lead',
                    'type' => 'amocrm_create_lead',
                    'config' => ['name' => 'Тест'],
                ],
            ],
        ]));
    }

    public function test_it_detects_configured_actions_inside_condition_branches(): void
    {
        $definition = [
            'actions' => [
                [
                    'id' => 'condition-1',
                    'type' => '',
                    'componentType' => 'control-condition',
                    'config' => [
                        'conditions' => [
                            [
                                'left' => '{{lead.price}}',
                                'operator' => 'gt',
                                'right' => '0',
                            ],
                        ],
                        'true_actions' => [
                            [
                                'id' => 'task-1',
                                'type' => 'amocrm_create_task',
                                'config' => ['target_entity' => 'lead'],
                            ],
                        ],
                        'false_actions' => [],
                    ],
                ],
            ],
        ];

        $this->assertTrue(Workflow::definitionHasConfiguredActions($definition));
    }
}
