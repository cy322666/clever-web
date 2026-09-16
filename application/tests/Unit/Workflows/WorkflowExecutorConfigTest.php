<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Tests\TestCase;

class WorkflowExecutorConfigTest extends TestCase
{
    public function test_retries_use_the_saved_definition_without_mutating_the_workflow_record(): void
    {
        $executor = new class($this->createMock(ActionRegistry::class)) extends WorkflowExecutor {
            public function context(\Leek\FilamentWorkflows\Models\WorkflowRun $run): \Leek\FilamentWorkflows\Context\WorkflowContext
            {
                return $this->buildContext($run);
            }
        };
        $definition = ['trigger' => ['type' => 'manual'], 'actions' => [['id' => 'first', 'type' => 'control-condition', 'name' => 'Проверка', 'config' => []]]];
        $workflow = (new \App\Models\Workflows\Workflow)->forceFill(['id' => 7, 'definition' => $definition]);
        $run = $this->getMockBuilder(\Leek\FilamentWorkflows\Models\WorkflowRun::class)->onlyMethods(['update'])->getMock();
        $run->forceFill(['id' => 10, 'workflow_id' => 7, 'trigger_source' => 'manual']);
        $run->setRelation('workflow', $workflow);
        $run->expects($this->once())->method('update')->willReturnCallback(function (array $data) use ($run): bool {
            $run->forceFill($data);
            return true;
        });

        $context = $executor->context($run);
        $this->assertSame($definition, $context->getVariable('_definition_snapshot'));
        $this->assertSame(['first'], $context->getVariable('_node_names.Проверка'));
        $workflow->definition = ['trigger' => ['type' => 'manual'], 'actions' => []];
        $run->setRelation('workflow', $workflow);
        $executor->context($run);
        $this->assertSame($definition, $run->workflow->definition);
        $this->assertSame([], $workflow->definition['actions']);
    }

    public function test_it_normalizes_delay_config_to_seconds(): void
    {
        $executor = new class($this->createMock(ActionRegistry::class)) extends WorkflowExecutor {
            public function normalize(array $config): array
            {
                return $this->normalizeStepDelayConfig($config);
            }
        };

        $this->assertSame([
            'delay' => [
                'mode' => 'after_seconds',
                'seconds' => 1,
            ],
        ], $executor->normalize([
            'delay' => [
                'mode' => 'after_seconds',
                'seconds' => 0,
            ],
        ]));

        $this->assertSame([
            'delay' => [
                'mode' => 'after_seconds',
                'seconds' => 30,
            ],
        ], $executor->normalize([
            'delay' => [
                'mode' => 'after_seconds',
                'seconds' => 999,
            ],
        ]));

        $this->assertSame([
            'delay' => [
                'mode' => 'immediate',
            ],
        ], $executor->normalize([
            'delay' => [
                'mode' => 'after_minutes',
                'minutes' => 5,
            ],
        ]));
    }
}
