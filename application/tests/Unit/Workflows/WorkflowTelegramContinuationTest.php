<?php

namespace Tests\Unit\Workflows;

use App\Models\Workflows\Workflow;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Enums\StepStatus;
use Leek\FilamentWorkflows\Exceptions\NonRetryableWorkflowException;
use Leek\FilamentWorkflows\Models\WorkflowRun;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowTelegramContinuationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowListDatabase::prepare();
        (require database_path('migrations/2026_06_09_160734_create_workflow_run_steps_table.php'))->up();
    }

    public function test_enabled_option_continues_graph_but_keeps_telegram_step_failed(): void
    {
        [$executor, $run] = $this->harness(true);

        $executor->graph(new WorkflowContext, $run);

        $this->assertSame(['telegram', 'next'], $executor->calls);
        $this->assertSame(StepStatus::FAILED, $run->steps()->where('step_id', 'telegram')->first()->status);
        $this->assertSame('Telegram не подтвердил отправку.', $run->steps()->where('step_id', 'telegram')->first()->error_message);
        $this->assertSame(StepStatus::COMPLETED, $run->steps()->where('step_id', 'next')->first()->status);
    }

    public function test_disabled_option_stops_before_the_next_node(): void
    {
        [$executor, $run] = $this->harness(false);

        try {
            $executor->graph(new WorkflowContext, $run);
            $this->fail('Ожидалась остановка сценария.');
        } catch (NonRetryableWorkflowException $exception) {
            $this->assertSame('Telegram не подтвердил отправку.', $exception->getMessage());
        }

        $this->assertSame(['telegram'], $executor->calls);
        $this->assertSame(StepStatus::FAILED, $run->steps()->where('step_id', 'telegram')->first()->status);
        $this->assertNull($run->steps()->where('step_id', 'next')->first());
    }

    public function test_invalid_settings_still_stop_the_graph_with_option_enabled(): void
    {
        [$executor, $run] = $this->harness(true);
        $executor->validationFailure = true;

        $this->expectException(NonRetryableWorkflowException::class);
        try {
            $executor->graph(new WorkflowContext, $run);
        } finally {
            $this->assertSame(['telegram'], $executor->calls);
            $this->assertNull($run->steps()->where('step_id', 'next')->first());
        }
    }

    private function harness(bool $continue): array
    {
        $definition = [
            'trigger' => ['type' => 'manual'],
            'actions' => [
                ['id' => 'telegram', 'type' => 'telegram_send_message', 'config' => ['continue_on_error' => $continue]],
                ['id' => 'next', 'type' => 'test-echo', 'config' => []],
            ],
            'connections' => [
                ['sourceId' => 'trigger', 'sourcePort' => 'output', 'targetId' => 'action:telegram'],
                ['sourceId' => 'action:telegram', 'sourcePort' => 'output', 'targetId' => 'action:next'],
            ],
        ];
        $run = $this->getMockBuilder(WorkflowRun::class)->onlyMethods(['update'])->getMock();
        $run->id = 20;
        $run->method('update')->willReturn(true);
        $run->setRelation('workflow', (new Workflow)->forceFill(['definition' => $definition]));
        $executor = new class(app(ActionRegistry::class)) extends WorkflowExecutor
        {
            public array $calls = [];
            public bool $validationFailure = false;

            public function graph(WorkflowContext $context, WorkflowRun $run): void
            {
                $this->executeGraph($context, $run);
            }

            protected function runStep(array $step, \Leek\FilamentWorkflows\Context\WorkflowContext $context): array
            {
                $this->calls[] = $step['id'];
                if ($step['id'] === 'telegram') {
                    return ['success' => false, 'error' => 'Telegram не подтвердил отправку.']
                        + ($this->validationFailure ? ['retryable' => false] : []);
                }

                return ['success' => true, 'output' => ['reached' => true]];
            }
        };

        return [$executor, $run];
    }
}
