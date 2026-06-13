<?php

namespace Tests\Unit\Workflows;

use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowAmoCrmLoopGuard;
use Illuminate\Support\Facades\Cache;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Tests\TestCase;

class WorkflowAmoCrmLoopGuardTest extends TestCase
{
    public function test_it_matches_recent_self_mutation_for_same_workflow_event_and_entity(): void
    {
        config(['cache.default' => 'array']);
        Cache::flush();

        $account = new Account([
            'user_id' => 77,
            'subdomain' => 'example',
        ]);
        $account->id = 12;

        $workflow = new Workflow();
        $workflow->id = 34;

        $context = (new WorkflowContext())
            ->setWorkflowId(34)
            ->setWorkflowRunId(56);

        $guard = app(WorkflowAmoCrmLoopGuard::class);
        $guard->rememberMutation($account, $context, 'amocrm_update_fields', 'lead', 987, ['update_lead']);

        $match = $guard->matchingRecentMutation($workflow, $account, [
            'event' => 'update_lead',
            'item' => ['id' => 987],
        ]);

        $this->assertIsArray($match);
        $this->assertSame(34, $match['workflow_id']);
        $this->assertSame(56, $match['workflow_run_id']);
        $this->assertSame('amocrm_update_fields', $match['action_type']);

        $this->assertNull($guard->matchingRecentMutation($workflow, $account, [
            'event' => 'update_lead',
            'item' => ['id' => 654],
        ]));
    }
}
