<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Actions\RunWorkflowAction;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use PHPUnit\Framework\TestCase;

class RunWorkflowActionTest extends TestCase
{
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
}
