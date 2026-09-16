<?php
namespace Tests\Unit\Workflows;

use App\Workflows\Engine\WorkflowExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Leek\FilamentWorkflows\Actions\ActionRegistry;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Leek\FilamentWorkflows\Exceptions\NonRetryableWorkflowException;
use Leek\FilamentWorkflows\Models\WorkflowRun;
use Tests\Support\WorkflowCanvasDatabase;
use Tests\TestCase;

class WorkflowPermanentFailureTest extends TestCase
{
    public function test_configuration_error_is_logged_but_never_retries_the_whole_flow(): void
    {
        WorkflowCanvasDatabase::prepare();
        Schema::create('workflow_run_steps', function(Blueprint $t) {
            $t->id(); $t->integer('workflow_run_id'); $t->string('step_id'); $t->string('step_type');
            $t->string('action_type')->nullable(); $t->string('status'); $t->integer('attempt_number');
            $t->text('input_data')->nullable(); $t->text('output_data')->nullable(); $t->text('error_message')->nullable();
            $t->timestamp('started_at')->nullable(); $t->timestamp('completed_at')->nullable(); $t->integer('duration_ms')->nullable(); $t->timestamps();
        });
        $registry=$this->createMock(ActionRegistry::class);
        $executor=new class($registry) extends WorkflowExecutor {
            protected function runStep(array $step, WorkflowContext $context): array { return ['success'=>false,'retryable'=>false,'error'=>'Неверный ID']; }
            public function one(WorkflowRun $run): array { return $this->executeStep(['id'=>'bot','type'=>'amocrm_start_salesbot'],new WorkflowContext,$run); }
        };
        $run=$this->getMockBuilder(WorkflowRun::class)->onlyMethods(['update'])->getMock();
        $run->id=1; $run->method('update')->willReturn(true);
        $run->setRelation('workflow',(new \App\Models\Workflows\Workflow)->forceFill(['failure_strategy'=>'stop']));
        try { $executor->one($run); $this->fail('A configuration error must not be retryable.'); }
        catch(NonRetryableWorkflowException $e) { $this->assertSame('Неверный ID',$e->getMessage()); }
        $this->assertDatabaseHas('workflow_run_steps',['step_id'=>'bot','status'=>'failed','error_message'=>'Неверный ID']);
        $run->workflow->failure_strategy='continue';
        $this->expectException(NonRetryableWorkflowException::class);
        $executor->one($run);
    }
}
