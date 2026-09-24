<?php

namespace App\Services\Finder;

use App\Models\Integrations\Finder\Action;
use App\Models\Integrations\Finder\Conversation;
use App\Models\Integrations\Finder\Setting;
use App\Models\Workflows\Workflow;
use App\Services\amoCRM\Client;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Enums\TriggerType;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use RuntimeException;
use Throwable;

class ActionExecutor
{
    public function __construct(private FinderAccess $access) {}

    public function deliver(int $id): void
    {
        $candidate = Action::query()->find($id);
        if (! $candidate) {
            return;
        }
        $action = DB::transaction(function () use ($candidate): ?Action {
            $setting = Setting::query()->lockForUpdate()->find($candidate->setting_id);
            $action = Action::query()->lockForUpdate()->find($candidate->id);
            if (! $action || $action->status !== 'pending') {
                return null;
            }
            $conversation = Conversation::query()->find($action->conversation_id);
            if (! $setting || ! $this->access->canRun($setting)
                || (int) $setting->account_id !== (int) $action->payload['account_id']
                || ($action->event === 'overdue' && (! $conversation?->pending_since || $conversation->cycle !== $action->cycle))) {
                $action->update(['status' => 'cancelled']);

                return null;
            }
            if ($action->event === 'overdue' && app(WorkingTime::class)->deadline(\Carbon\CarbonImmutable::now(), 0, $setting->options())->isFuture()) {
                return null;
            }
            $action->update(['status' => 'processing']);

            return $action;
        });
        if (! $action) {
            return;
        }
        try {
            $resultId = $this->perform($action);
            $action->update(['status' => 'succeeded', 'result_id' => $resultId]);
        } catch (Throwable $exception) {
            // A timed-out POST may already have created a task. Never retry writes
            // automatically: keep the attempt visible for manual reconciliation.
            $action->update(['status' => 'failed', 'error' => 'Не удалось подтвердить действие. Проверьте amoCRM и историю сценария перед повторным запуском.']);
            Log::warning('Finder action failed', ['action_id' => $action->id, 'exception_class' => $exception::class]);
        }
    }

    protected function perform(Action $action): int
    {
        $setting = $action->setting;
        $client = $this->client($setting);
        $payload = $action->payload;
        $leadId = (int) ($payload['lead_id'] ?? 0);
        $contactId = (int) ($payload['contact_id'] ?? 0);
        if (! empty($payload['talk_id'])) {
            $talk = $client->requestV4('GET', '/api/v4/talks/'.(int) $payload['talk_id']);
            if (in_array((string) ($talk['entity_type'] ?? ''), ['lead', 'leads', '2'], true)) {
                $leadId = (int) ($talk['entity_id'] ?? $leadId);
            } elseif (in_array((string) ($talk['entity_type'] ?? ''), ['contact', 'contacts', '1'], true)) {
                $leadId = 0;
                $contactId = (int) ($talk['entity_id'] ?? $contactId);
            }
            $contactId = (int) ($talk['contact_id'] ?? $contactId);
        }
        $entityType = $leadId > 0 ? 'leads' : 'contacts';
        $entityId = $leadId ?: $contactId;
        if ($entityId <= 0) {
            throw new RuntimeException('Сообщение не связано со сделкой или контактом.');
        }
        $entity = $client->requestV4('GET', '/api/v4/'.$entityType.'/'.$entityId);
        if ($action->kind === 'task') {
            $responsible = (int) ($payload['responsible_user_id'] ?: ($entity['responsible_user_id'] ?? 0));
            if ($responsible <= 0) {
                throw new RuntimeException('Не определён ответственный за задачу.');
            }
            $response = $client->requestV4('POST', '/api/v4/tasks', [[
                'entity_id' => $entityId, 'entity_type' => $entityType,
                'responsible_user_id' => $responsible, 'task_type_id' => (int) $payload['task_type_id'],
                'text' => $payload['task_text'],
                'complete_till' => now()->addMinutes((int) $payload['task_due_minutes'])->timestamp,
                'request_id' => 'finder-'.$action->id,
            ]]);
            $id = (int) data_get($response, '_embedded.tasks.0.id');
            if (! $id) {
                throw new RuntimeException('amoCRM не вернул ID задачи.');
            }

            return $id;
        }

        $workflow = Workflow::query()->where('user_id', $setting->user_id)->where('is_active', true)->findOrFail($payload['workflow_id']);
        $trigger = [
            '_workflow_start_node_id' => 'trigger', 'source' => 'finder', 'event' => 'finder.'.$action->event,
            'entity' => $entityType === 'leads' ? 'lead' : 'contact',
            'lead' => $leadId ? $entity : null, 'contact' => ['id' => $contactId],
            'account' => ['id' => $setting->account_id, 'user_id' => $setting->user_id, 'subdomain' => $setting->account->subdomain],
            'finder' => [
                'action_id' => $action->id, 'conversation_id' => $action->conversation_id,
                'chat_id' => $payload['chat_id'], 'talk_id' => $payload['talk_id'],
                'attempt' => $action->attempt, 'cycle' => $action->cycle,
                'pending_since' => $payload['pending_since'], 'replied_at' => $payload['replied_at'],
            ],
        ];
        $trigger[$trigger['entity']] = $entity;
        $run = DB::transaction(function () use ($workflow, $trigger, $setting, $action) {
            $run = app(WorkflowExecutor::class)->start($workflow, null, TriggerType::WEBHOOK, (int) $setting->user_id);
            $context = (new WorkflowContext($trigger))->setWorkflowId((int) $workflow->id)
                ->setWorkflowRunId((int) $run->id)->setTriggerSource(TriggerType::WEBHOOK->value)->setTriggeredBy((int) $setting->user_id);
            $run->update(['context_data' => $context->toArray()]);
            $action->update(['result_id' => $run->id]);

            return $run;
        });
        ExecuteWorkflowJob::dispatch((int) $run->id);

        return (int) $run->id;
    }

    protected function client(Setting $setting): Client
    {
        return new Client($setting->account);
    }
}
