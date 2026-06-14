<?php

namespace App\Services\Workflows;

use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Workflows\Context\WorkflowContext;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Enums\TriggerType;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;

class WorkflowManualAmoCrmRunService
{
    public function __construct(
        private readonly WorkflowExecutor $executor,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{run_id: int, run_ulid: string|null}
     */
    public function startForLead(Workflow $workflow, Account $account, int $leadId, array $input = []): array
    {
        $triggerData = $this->triggerData($workflow, $account, $leadId, $input);

        $run = $this->executor->start(
            workflow: $workflow,
            triggerModel: null,
            triggerSource: TriggerType::MANUAL,
            triggeredBy: (int)$account->user_id,
        );

        $context = (new WorkflowContext($triggerData))
            ->setWorkflowId((int)$workflow->id)
            ->setWorkflowRunId((int)$run->id)
            ->setTriggerSource(TriggerType::MANUAL->value)
            ->setTriggeredBy((int)$account->user_id);

        $run->update(['context_data' => $context->toArray()]);

        ExecuteWorkflowJob::dispatch((int)$run->id);

        return [
            'run_id' => (int)$run->id,
            'run_ulid' => $run->ulid,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function triggerData(Workflow $workflow, Account $account, int $leadId, array $input): array
    {
        $receivedAt = now()->toIso8601String();
        $lead = [
            'id' => $leadId,
            'name' => (string)($input['lead_name'] ?? ''),
        ];

        return [
            'source' => (string)($input['source'] ?? 'amocrm-widget'),
            'event' => 'manual',
            'entity' => 'lead',
            'action' => 'manual',
            'workflow_id' => (int)$workflow->id,
            'is_manual' => true,
            'item' => $lead,
            'lead' => $lead,
            'payload' => [
                'lead_id' => $leadId,
                'lead_name' => $lead['name'],
                'pipeline_id' => $input['pipeline_id'] ?? null,
                'status_id' => $input['status_id'] ?? null,
            ],
            'account' => [
                'id' => (int)$account->id,
                'user_id' => (int)$account->user_id,
                'subdomain' => (string)$account->subdomain,
                'zone' => (string)($account->zone ?: 'amocrm.ru'),
            ],
            'widget' => [
                'source' => (string)($input['widget_source'] ?? 'amocrm-card'),
                'entity' => 'lead',
                'entity_id' => $leadId,
                'pipeline_id' => $input['pipeline_id'] ?? null,
                'status_id' => $input['status_id'] ?? null,
            ],
            'received_at' => $receivedAt,
            'triggered_at' => $receivedAt,
        ];
    }
}
