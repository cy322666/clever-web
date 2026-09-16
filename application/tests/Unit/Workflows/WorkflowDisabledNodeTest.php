<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Actions\ControlConditionAction;
use App\Services\Workflows\WorkflowRunEntityIndexService;
use App\Workflows\Engine\WorkflowExecutor;
use App\Workflows\Engine\WorkflowTestRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Leek\FilamentWorkflows\Enums\StepStatus;
use Leek\FilamentWorkflows\Models\WorkflowRun;
use Leek\FilamentWorkflows\Models\WorkflowRunStep;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\TestCase;

class WorkflowDisabledNodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCanvasDatabase::prepare();
        config(['filament-workflows.models.workflow_run_step' => WorkflowRunStep::class]);
        $this->app->instance(WorkflowRunEntityIndexService::class, $this->createMock(WorkflowRunEntityIndexService::class));
        Schema::create('workflow_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->integer('workflow_run_id');
            $table->string('step_id');
            $table->string('step_type');
            $table->string('action_type')->nullable();
            $table->string('status');
            $table->integer('attempt_number');
            $table->json('input_data')->nullable();
            $table->json('output_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function test_runtime_skips_disabled_nodes_before_validation_and_continues(): void
    {
        $spy = $this->spyAction();
        $registry = $this->registry();
        $registry->expects($this->once())->method('resolve')->with('test-action')->willReturn($spy);

        $context = new WorkflowContext(['original' => 'unchanged']);
        $this->execute([
            ['id' => 'off', 'type' => 'missing-action', 'disabled' => true, 'config' => ['delay' => ['mode' => 'after_seconds', 'seconds' => 30]]],
            $this->action('next'),
        ], $registry, $context);

        $this->assertSame(['next'], $spy->calls);
        $this->assertSame(StepStatus::SKIPPED, WorkflowRunStep::where('step_id', 'off')->firstOrFail()->status);
        $this->assertSame(StepStatus::COMPLETED, WorkflowRunStep::where('step_id', 'next')->firstOrFail()->status);
        $this->assertNull($context->getStepOutput('off'));
        $this->assertSame(['label' => 'next'], $context->getStepOutput('next'));
    }

    public function test_runtime_bypasses_a_disabled_condition_and_skips_disabled_branch_actions(): void
    {
        $spy = $this->spyAction();
        $registry = $this->registry();
        $registry->expects($this->exactly(2))->method('resolve')->with('test-action')->willReturn($spy);

        $this->execute([$this->condition(true), $this->action('next')], $registry, new WorkflowContext);

        $this->assertSame(['yes', 'next'], $spy->calls);
        $this->assertSame(['condition', 'branch-off'], WorkflowRunStep::where('status', StepStatus::SKIPPED)->pluck('step_id')->all());
        $this->assertFalse(WorkflowRunStep::where('step_id', 'no')->exists());
    }

    public function test_reenabled_condition_evaluates_normally(): void
    {
        $spy = $this->spyAction();
        $registry = $this->registry();
        $registry->method('resolve')->willReturnCallback(fn(string $type): object => $type === 'control-condition' ? new ControlConditionAction : $spy);

        $this->execute([$this->condition(false)], $registry, new WorkflowContext);

        $this->assertSame(['no'], $spy->calls);
        $this->assertSame(StepStatus::COMPLETED, WorkflowRunStep::where('step_id', 'condition')->firstOrFail()->status);
    }

    public function test_test_runner_uses_the_same_disabled_behavior(): void
    {
        $spy = $this->spyAction();
        $registry = $this->registry();
        $registry->expects($this->exactly(2))->method('resolve')->with('test-action')->willReturn($spy);
        $result = (new WorkflowTestRunner($registry))->test([
            'trigger' => ['type' => 'manual', 'config' => []],
            'actions' => [$this->condition(true), $this->action('next')],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(['yes', 'next'], $spy->calls);
        $condition = $result['steps'][0];
        $this->assertSame('skipped', $condition['status']);
        $this->assertNull($condition['condition_result']);
        $this->assertSame('true', $condition['executed_branch']);
        $this->assertSame('skipped', $condition['true_branch'][0]['status']);
        $this->assertSame([], $condition['false_branch']);

        $html = view('filament-workflows::filament.partials.test-step-result', ['step' => $condition, 'index' => 0, 'depth' => 0])->render();
        $this->assertStringContainsString('Пропущено', $html);
        $this->assertStringContainsString('без проверки → ветка «Да»', $html);
        $this->assertStringNotContainsString('пойдёт ветка «Нет»', $html);
    }

    private function execute(array $steps, ActionRegistry $registry, WorkflowContext $context): void
    {
        $executor = new class($registry) extends WorkflowExecutor {
            public function runSteps(array $steps, WorkflowContext $context, WorkflowRun $run): void
            {
                $this->executeSteps($steps, $context, $run);
            }
        };
        $run = $this->getMockBuilder(WorkflowRun::class)->onlyMethods(['update'])->getMock();
        $run->id = 1;
        $run->method('update')->willReturn(true);
        $this->app->instance(ActionRegistry::class, $registry);
        $run->setRelation('workflow', (new \App\Models\Workflows\Workflow)->forceFill(['definition' => [
            'trigger' => ['type' => 'manual'], 'actions' => $steps,
        ]]));
        $executor->runSteps($steps, $context, $run);
    }

    private function registry(): ActionRegistry
    {
        $registry = $this->createMock(ActionRegistry::class);
        $registry->method('has')->willReturn(true);

        return $registry;
    }

    private function spyAction(): object
    {
        return new class {
            public array $calls = [];

            public function handle(array $config): array
            {
                $this->calls[] = $config['label'];

                return ['success' => true, 'output' => ['label' => $config['label']]];
            }
        };
    }

    private function action(string $id): array
    {
        return ['id' => $id, 'type' => 'test-action', 'config' => ['label' => $id]];
    }

    private function condition(bool $disabled): array
    {
        return ['id' => 'condition', 'type' => 'control-condition', 'componentType' => 'control-condition', 'disabled' => $disabled, 'config' => [
            'conditions' => [['left' => '1', 'operator' => 'equals', 'right' => '2']],
            'has_true_branch' => true,
            'has_false_branch' => true,
            'true_actions' => [array_merge($this->action('branch-off'), ['disabled' => true]), $this->action('yes')],
            'false_actions' => [$this->action('no')],
        ]];
    }
}
