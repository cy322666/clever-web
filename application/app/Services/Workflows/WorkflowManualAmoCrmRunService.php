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
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{run_id: int, run_ulid: string|null}
     */
    public function startForLead(Workflow $workflow, Account $account, int $leadId, array $input = []): array
    {
        return $this->startForEntry($workflow, $account, $leadId, $input, 'manual');
    }

    public function startDigitalPipelineForLead(Workflow $workflow, Account $account, int $leadId, array $input = []): array
    {
        $input['source'] = $input['widget_source'] = 'amocrm-digital-pipeline';
        return $this->startForEntry($workflow, $account, $leadId, $input, 'digital-pipeline');
    }

    public function startButtonForLead(Workflow $workflow, Account $account, int $leadId, array $input = []): array
    {
        $input['source'] = $input['widget_source'] = 'amocrm-button';
        return $this->startForEntry($workflow, $account, $leadId, $input, 'amo-button');
    }

    private function startForEntry(Workflow $workflow, Account $account, int $leadId, array $input, string $startType): array
    {
        $triggerData = $this->triggerData($workflow, $account, $leadId, $input);
        $triggerData['event'] = $triggerData['action'] = $startType;
        $triggerData['is_manual'] = $startType === 'manual';
        $triggerSource = $startType === 'manual' ? TriggerType::MANUAL : TriggerType::WEBHOOK;
        $runs = [];
        foreach (WorkflowStartNodes::ofType($workflow->definition, $startType) as $startId => $start) {
            $triggerData['_workflow_start_node_id'] = $startId;
            $run = $this->executor->start(
                workflow: $workflow,
                triggerModel: null,
                triggerSource: $triggerSource,
                triggeredBy: (int) $account->user_id,
            );

            $context = (new WorkflowContext($triggerData))
                ->setWorkflowId((int) $workflow->id)
                ->setWorkflowRunId((int) $run->id)
                ->setTriggerSource($triggerSource->value)
                ->setTriggeredBy((int) $account->user_id);

            $run->update(['context_data' => $context->toArray()]);

            ExecuteWorkflowJob::dispatch((int) $run->id);

            $runs[] = ['run_id' => (int) $run->id, 'run_ulid' => $run->ulid];
        }

        if ($runs === []) {
            throw new \InvalidArgumentException(match ($startType) {
                'manual' => 'В сценарии нет ручного запуска.',
                'amo-button' => 'В сценарии нет запуска кнопкой.',
                default => 'В сценарии нет запуска Digital Pipeline.',
            });
        }

        return $runs[0] + ['runs' => $runs];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function triggerData(Workflow $workflow, Account $account, int $leadId, array $input): array
    {
        $receivedAt = now()->toIso8601String();
        $lead = [
            'id' => $leadId,
            'name' => (string) ($input['lead_name'] ?? ''),
        ];

        return [
            'source' => (string) ($input['source'] ?? 'amocrm-widget'),
            'event' => 'manual',
            'entity' => 'lead',
            'action' => 'manual',
            'workflow_id' => (int) $workflow->id,
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
                'id' => (int) $account->id,
                'user_id' => (int) $account->user_id,
                'subdomain' => (string) $account->subdomain,
                'zone' => (string) ($account->zone ?: 'amocrm.ru'),
            ],
            'widget' => [
                'source' => (string) ($input['widget_source'] ?? 'amocrm-card'),
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
