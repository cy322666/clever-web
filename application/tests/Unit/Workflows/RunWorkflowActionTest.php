<?php

namespace Tests\Unit\Workflows;

use App\Models\User;
use App\Models\Workflows\Workflow;
use App\Workflows\Actions\RunWorkflowAction;
use App\Workflows\Triggers\WorkflowCompletedTrigger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Tests\TestCase;

class RunWorkflowActionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_blocks_running_current_workflow_from_itself(): void
    {
        $context = (new WorkflowContext())
            ->setWorkflowId(10)
            ->setWorkflowRunId(100);

        $result = (new RunWorkflowAction())->handle([
            'workflow_id' => 10,
        ], $context);

        $this->assertFalse($result['success']);
        $this->assertSame('Нельзя запускать текущий процесс из самого себя.', $result['error']);
    }

    public function test_it_blocks_workflow_already_present_in_current_chain(): void
    {
        $context = (new WorkflowContext())
            ->setWorkflowId(10)
            ->setWorkflowRunId(100)
            ->setVariable('_workflow_chain_ids', [3, 5, 20]);

        $result = (new RunWorkflowAction())->handle([
            'workflow_id' => 5,
        ], $context);

        $this->assertFalse($result['success']);
        $this->assertSame('Запуск остановлен: процесс уже есть в текущей цепочке выполнения.', $result['error']);
    }

    public function test_it_validates_that_child_workflow_has_workflow_trigger(): void
    {
        $user = User::factory()->create();

        $callableWorkflow = $this->createWorkflow($user->id, [
            'trigger' => [
                'type' => WorkflowCompletedTrigger::type(),
                'config' => [],
            ],
            'actions' => [
                ['id' => 'child-step', 'type' => 'amocrm_add_note'],
            ],
        ]);

        $manualWorkflow = $this->createWorkflow($user->id, [
            'trigger' => [
                'type' => 'manual',
                'config' => [],
            ],
            'actions' => [
                ['id' => 'manual-step', 'type' => 'amocrm_add_note'],
            ],
        ]);

        $action = new RunWorkflowAction();

        $this->assertTrue($action->validateWorkflowConfig([
            'workflow_id' => $callableWorkflow->id,
        ])['valid']);

        $invalid = $action->validateWorkflowConfig([
            'workflow_id' => $manualWorkflow->id,
        ]);

        $this->assertFalse($invalid['valid']);
        $this->assertContains('Выбранный процесс должен иметь триггер «Запуск из другого процесса».', $invalid['errors']);
    }

    public function test_it_blocks_child_workflow_cycle_before_execution(): void
    {
        $user = User::factory()->create();

        $parent = $this->createWorkflow($user->id, [
            'trigger' => [
                'type' => 'manual',
                'config' => [],
            ],
            'actions' => [],
        ]);

        $child = $this->createWorkflow($user->id, [
            'trigger' => [
                'type' => WorkflowCompletedTrigger::type(),
                'config' => [],
            ],
            'actions' => [
                [
                    'id' => 'run-parent',
                    'type' => 'run_workflow',
                    'config' => [
                        'workflow_id' => $parent->id,
                    ],
                ],
            ],
        ]);

        $context = (new WorkflowContext())
            ->setWorkflowId($parent->id)
            ->setWorkflowRunId(100);

        $result = (new RunWorkflowAction())->handle([
            'workflow_id' => $child->id,
        ], $context);

        $this->assertFalse($result['success']);
        $this->assertSame('Запуск остановлен: связка процессов создаёт цикл.', $result['error']);
    }

    private function createWorkflow(int $userId, array $definition): Workflow
    {
        return Workflow::query()->create([
            'user_id' => $userId,
            'name' => 'Тестовый процесс ' . uniqid(),
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
