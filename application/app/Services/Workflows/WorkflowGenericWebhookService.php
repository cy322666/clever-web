<?php

namespace App\Services\Workflows;

use App\Models\Workflows\Workflow;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Triggers\GenericWebhookTrigger;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Enums\TriggerType;

class WorkflowGenericWebhookService
{
    public function __construct(
        private readonly WorkflowExecutor $executor,
    ) {
    }

    public function callbackUrl(Workflow $workflow): string
    {
        return route('workflows.webhook', [
            'workflow' => $workflow->getKey(),
            'signature' => $this->signature($workflow),
        ], true);
    }

    public function signatureIsValid(Workflow $workflow, string $signature): bool
    {
        return hash_equals($this->signature($workflow), $signature);
    }

    public function canReceive(Workflow $workflow): bool
    {
        return (bool)$workflow->is_active
            && data_get($workflow->definition, 'trigger.type') === GenericWebhookTrigger::type();
    }

    /**
     * @return array{run_id: int|null, run_ulid: string|null}
     */
    public function handleIncomingWebhook(Workflow $workflow, Request $request): array
    {
        $triggerData = $this->triggerData($workflow, $request);
        $userId = (int)($workflow->{config('filament-workflows.tenancy.column', 'user_id')} ?? 0);

        $run = $this->executor->start(
            workflow: $workflow,
            triggerModel: null,
            triggerSource: TriggerType::WEBHOOK,
            triggeredBy: $userId > 0 ? $userId : null,
        );

        $context = (new WorkflowContext($triggerData))
            ->setWorkflowId((int)$workflow->id)
            ->setWorkflowRunId((int)$run->id)
            ->setTriggerSource(TriggerType::WEBHOOK->value)
            ->setTriggeredBy($userId > 0 ? $userId : null);

        $run->update(['context_data' => $context->toArray()]);

        ExecuteWorkflowJob::dispatch((int)$run->id);

        return [
            'run_id' => (int)$run->id,
            'run_ulid' => $run->ulid,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function triggerData(Workflow $workflow, Request $request): array
    {
        $payload = $this->payload($request);
        $query = $request->query->all();
        $headers = $this->headers($request);
        $receivedAt = now()->toIso8601String();

        return [
            'source' => 'webhook',
            'event' => GenericWebhookTrigger::type(),
            'workflow_id' => (int)$workflow->id,
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => '/' . ltrim($request->path(), '/'),
            'ip' => $request->ip(),
            'payload' => $payload,
            'body' => $payload,
            'query' => $query,
            'headers' => $headers,
            'raw_body' => $request->getContent(),
            'received_at' => $receivedAt,
            'webhook' => [
                'source' => 'generic',
                'payload' => $payload,
                'body' => $payload,
                'query' => $query,
                'headers' => $headers,
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'path' => '/' . ltrim($request->path(), '/'),
                'ip' => $request->ip(),
                'received_at' => $receivedAt,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $payload = $request->all();

        if (is_array($payload) && $payload !== []) {
            return $payload;
        }

        $json = json_decode($request->getContent(), true);

        return is_array($json) ? $json : [];
    }

    /**
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[Str::of((string)$name)->lower()->replace('-', '_')->toString()] = implode(', ', array_map(
                static fn(mixed $value): string => (string)$value,
                Arr::wrap($values),
            ));
        }

        return $headers;
    }

    private function signature(Workflow $workflow): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [
                $workflow->getKey(),
                (int)($workflow->{config('filament-workflows.tenancy.column', 'user_id')} ?? 0),
                (string)$workflow->created_at,
            ]),
            (string)config('app.key'),
        );
    }
}
