<?php

namespace Tests\Unit\Workflows;

use App\Workflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Tests\TestCase;

class WorkflowExecutorConfigTest extends TestCase
{
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
