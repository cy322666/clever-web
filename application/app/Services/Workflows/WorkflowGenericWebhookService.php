<?php

namespace App\Services\Workflows;

use App\Models\Workflows\Workflow;
use App\Models\Workflows\WorkflowRun;
use App\Workflows\Context\WorkflowContext;
use App\Workflows\Triggers\GenericWebhookTrigger;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Enums\TriggerType;

class WorkflowGenericWebhookService
{
    private const PREVIEW_TTL_SECONDS = 3600;

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

    public function canCapture(Workflow $workflow): bool
    {
        return data_get($workflow->definition, 'trigger.type') === GenericWebhookTrigger::type();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestPreview(Workflow $workflow): ?array
    {
        $preview = Cache::get($this->previewCacheKey($workflow));

        if (is_array($preview)) {
            return $preview;
        }

        $run = WorkflowRun::query()
            ->where('workflow_id', $workflow->getKey())
            ->where('trigger_source', TriggerType::WEBHOOK->value)
            ->whereNotNull('context_data')
            ->latest('created_at')
            ->first(['id', 'workflow_id', 'context_data']);

        $triggerData = (array)data_get($run?->context_data, 'trigger_data', []);

        if ($triggerData === []) {
            return null;
        }

        return $this->previewFromTriggerData($workflow, $triggerData, 'run-' . $run->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function captureIncomingWebhook(Workflow $workflow, Request $request): array
    {
        $triggerData = $this->triggerData($workflow, $request);
        $preview = $this->previewFromTriggerData($workflow, $triggerData);

        Cache::put($this->previewCacheKey($workflow), $preview, now()->addSeconds(self::PREVIEW_TTL_SECONDS));

        return $preview;
    }

    /**
     * @param array<string, mixed> $triggerData
     * @return array<string, mixed>
     */
    private function previewFromTriggerData(Workflow $workflow, array $triggerData, ?string $id = null): array
    {
        return [
            'id' => $id ?: (string)Str::ulid(),
            'workflow_id' => (int)$workflow->id,
            'received_at' => $triggerData['received_at'] ?? null,
            'method' => $triggerData['method'] ?? 'REQUEST',
            'url' => $triggerData['url'] ?? null,
            'path' => $triggerData['path'] ?? null,
            'ip' => $triggerData['ip'] ?? null,
            'payload' => (array)($triggerData['payload'] ?? []),
            'query' => (array)($triggerData['query'] ?? []),
            'headers' => $this->maskSensitiveHeaders((array)($triggerData['headers'] ?? [])),
            'raw_body' => Str::limit((string)($triggerData['raw_body'] ?? ''), 200_000, ''),
            'variables' => $this->variablesFromTriggerData($triggerData),
        ];
    }

    /**
     * @return array{run_id: int|null, run_ulid: string|null}
     */
    public function handleIncomingWebhook(Workflow $workflow, Request $request): array
    {
        $preview = $this->captureIncomingWebhook($workflow, $request);
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
            'preview_id' => $preview['id'],
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
        if ($request->isJson()) {
            $payload = $request->json()->all();

            if (is_array($payload)) {
                return $payload;
            }
        }

        $payload = $request->request->all();

        if ($payload !== []) {
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

    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    private function maskSensitiveHeaders(array $headers): array
    {
        foreach (['authorization', 'cookie', 'set_cookie', 'x_api_key', 'x_auth_token'] as $key) {
            if (array_key_exists($key, $headers)) {
                $headers[$key] = '***';
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{mask: string, path: string, value: string}>
     */
    private function variablesFromTriggerData(array $data): array
    {
        $paths = [
            'method' => $data['method'] ?? null,
            'url' => $data['url'] ?? null,
            'path' => $data['path'] ?? null,
            'ip' => $data['ip'] ?? null,
            'received_at' => $data['received_at'] ?? null,
            'raw_body' => $data['raw_body'] ?? null,
        ];

        foreach (['payload', 'body', 'query', 'headers'] as $root) {
            $this->flattenVariables((array)($data[$root] ?? []), $root, $paths);
        }

        return collect($paths)
            ->filter(fn(mixed $value, string $path): bool => $path !== '' && !is_array($value) && $value !== null && $value !== '')
            ->map(fn(mixed $value, string $path): array => [
                'mask' => '{{' . $path . '}}',
                'path' => $path,
                'value' => Str::limit((string)$value, 120),
            ])
            ->values()
            ->take(120)
            ->all();
    }

    /**
     * @param array<string|int, mixed> $value
     * @param array<string, mixed> $paths
     */
    private function flattenVariables(array $value, string $prefix, array &$paths): void
    {
        foreach ($value as $key => $item) {
            $path = $prefix . '.' . $key;

            if (is_array($item)) {
                $this->flattenVariables($item, $path, $paths);
                continue;
            }

            $paths[$path] = $item;
        }
    }

    private function previewCacheKey(Workflow $workflow): string
    {
        return 'workflow-generic-webhook-preview:' . $workflow->getKey();
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
