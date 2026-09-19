<?php

namespace Tests\Unit\Workflows;

use App\Models\Workflows\Workflow;
use App\Models\Workflows\WorkflowRun;
use App\Workflows\Engine\WorkflowExecutor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Leek\FilamentWorkflows\Exceptions\NonRetryableWorkflowException;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use Tests\Support\WorkflowListDatabase;
use Tests\TestCase;

class WorkflowFatalJobTest extends TestCase
{
    public function test_old_three_attempt_job_fails_once_and_duplicate_delivery_cannot_repeat_writes(): void
    {
        WorkflowListDatabase::prepare();
        Http::preventStrayRequests();
        Schema::table('workflow_runs', function ($t) {
            $t->string('ulid')->nullable();
            $t->string('trigger_source')->default('manual'); $t->integer('current_step_index')->default(0);
            $t->text('context_data')->nullable(); $t->text('error_message')->nullable();
            $t->timestamp('started_at')->nullable(); $t->timestamp('completed_at')->nullable();
        });
        (require database_path('migrations/2026_06_09_160734_create_workflow_run_steps_table.php'))->up();
        $flow = (new Workflow)->forceFill(['user_id' => 1, 'name' => 'Fatal error test', 'is_active' => false,
            'failure_strategy' => 'continue', 'definition' => ['trigger' => ['type' => 'manual'], 'actions' => array_map(
                fn ($id) => ['id' => $id, 'type' => 'amocrm_add_note', 'config' => ['text' => 'Test']], ['first', 'broken', 'after'])]]);
        $flow->save();
        $run = (new WorkflowRun)->forceFill(['workflow_id' => $flow->id, 'user_id' => 1, 'status' => 'pending', 'trigger_source' => 'manual']);
        $run->save();
        $executor = new class(app(ActionRegistry::class)) extends WorkflowExecutor {
            public array $calls = [];
            protected function runStep(array $step, WorkflowContext $context): array
            {
                $this->calls[] = $step['id'];
                if ($step['id'] === 'broken') throw new \RuntimeException('HTTP 503');
                return ['success' => true, 'output' => ['id' => 123]];
            }
        };
        $job = new ExecuteWorkflowJob($run->id);
        $this->assertSame(1, $job->tries);
        $job->tries = 3; // Jobs already in Redis keep their serialized settings.
        $queueJob = $this->createMock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->method('attempts')->willReturn(1);
        $queueJob->expects($this->once())->method('fail')->with($this->isInstanceOf(NonRetryableWorkflowException::class));
        $job->setJob($queueJob);
        $job->handle($executor);
        $this->assertSame('failed', $run->fresh()->status->value);
        $job->handle($executor);
        $this->assertSame(['first', 'broken'], $executor->calls);
        $this->assertSame(2, $run->steps()->count());
        $this->assertSame('HTTP 503', $run->fresh()->error_message);
        Http::assertNothingSent();
    }
}
