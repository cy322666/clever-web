<?php

namespace Tests\Unit\Workflows;

use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowMermaidMap;
use App\Workflows\Triggers\WorkflowCompletedTrigger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Tests\TestCase;

class WorkflowMermaidMapTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_renders_actions_conditions_and_child_workflow_actions(): void
    {
        $user = User::factory()->create();

        $child = $this->createWorkflow($user->id, 'Дочерний сценарий', [
            'trigger' => [
                'type' => WorkflowCompletedTrigger::type(),
                'config' => [],
            ],
            'actions' => [
                [
                    'id' => 'child-note',
                    'type' => 'amocrm_add_note',
                    'name' => 'Действие внутри дочернего',
                    'config' => [
                        'target_entity' => 'lead',
                        'text' => 'Привет из дочернего',
                    ],
                ],
            ],
        ]);

        $parent = $this->createWorkflow($user->id, 'Родительский сценарий', [
            'trigger' => [
                'type' => 'manual',
                'config' => [],
            ],
            'actions' => [
                [
                    'id' => 'create-lead',
                    'type' => 'amocrm_create_lead',
                    'name' => 'Создать сделку',
                    'config' => ['name' => 'Новая сделка'],
                ],
                [
                    'id' => 'run-child',
                    'type' => 'run_workflow',
                    'name' => 'Запустить дочерний',
                    'config' => ['workflow_id' => $child->id],
                ],
                [
                    'id' => 'condition',
                    'type' => 'control-condition',
                    'componentType' => 'control-condition',
                    'name' => 'Проверить источник',
                    'config' => [
                        'conditions' => [
                            [
                                'left' => '{{lead.source}}',
                                'operator' => 'equals',
                                'right' => 'site',
                            ],
                        ],
                        'true_actions' => [
                            [
                                'id' => 'true-task',
                                'type' => 'amocrm_create_task',
                                'name' => 'Поставить задачу',
                                'config' => ['target_entity' => 'lead'],
                            ],
                        ],
                        'false_actions' => [
                            [
                                'id' => 'false-update',
                                'type' => 'amocrm_update_lead_fields',
                                'name' => 'Изменить сделку',
                                'config' => ['target_entity' => 'lead'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $map = (new WorkflowMermaidMap($this->createMock(ActionRegistry::class)))->render($parent);

        $this->assertStringStartsWith('flowchart LR', $map);
        $this->assertStringContainsString('Родительский сценарий', $map);
        $this->assertStringContainsString('Создать сделку', $map);
        $this->assertStringContainsString('Запустить дочерний', $map);
        $this->assertStringContainsString('Дочерний сценарий', $map);
        $this->assertStringContainsString('Действие внутри дочернего', $map);
        $this->assertStringContainsString('◇ Проверить источник', $map);
        $this->assertStringContainsString('-- "Да" -->', $map);
        $this->assertStringContainsString('-- "Нет" -->', $map);
        $this->assertStringContainsString('Поставить задачу', $map);
        $this->assertStringContainsString('Изменить сделку', $map);
        $this->assertStringContainsString('classDef childNode', $map);
    }

    private function createWorkflow(int $userId, string $name, array $definition): Workflow
    {
        return Workflow::query()->create([
            'user_id' => $userId,
            'name' => $name,
            'description' => null,
            'is_active' => true,
            'trigger_type' => 'manual',
            'trigger_model_type' => null,
            'trigger_event' => null,
            'trigger_conditions' => null,
            'trigger_schedule' => null,
            'definition' => $definition,
            'max_retries' => 3,
            'failure_strategy' => 'stop',
        ]);
    }
}
