<?php

namespace App\Services\Workflows;

use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\amoCRM\Client;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Leek\FilamentWorkflows\Context\WorkflowContext;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Enums\TriggerType;
use Throwable;

class WorkflowAmoCrmWebhookService
{
    public function __construct(
        private readonly WorkflowAmoCrmWebhookPayloadNormalizer $normalizer,
        private readonly WorkflowExecutor $executor,
    ) {
    }

    public function signature(Account $account): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [
                $account->getKey(),
                $account->user_id,
                (string)$account->subdomain,
            ]),
            (string)config('app.key'),
        );
    }

    public function signatureIsValid(Account $account, string $signature): bool
    {
        return hash_equals($this->signature($account), $signature);
    }

    public function callbackUrl(Account $account): string
    {
        return route('amocrm.workflows.hook', [
            'account' => $account->getKey(),
            'signature' => $this->signature($account),
        ], true);
    }

    public function synchronizeUser(int $userId): void
    {
        $account = $this->resolvePrimaryAccount($userId);

        if (!$account instanceof Account) {
            return;
        }

        $this->synchronizeAccount($account);
    }

    public function synchronizeAccount(Account $account): void
    {
        if (!$this->accountCanUseWebhooks($account)) {
            return;
        }

        $requiredEvents = $this->requiredEventsForUser((int)$account->user_id);
        $targetUrl = $this->callbackUrl($account);

        try {
            $client = new Client($account);
            $currentHooks = $this->platformHooks($client, $account);

            foreach ($currentHooks as $hook) {
                if (($hook['url'] ?? '') !== $targetUrl) {
                    $this->unsubscribe($client, (string)$hook['url'], $hook['events'] ?? []);
                }
            }

            $currentEvents = $this->eventsForUrl($currentHooks, $targetUrl);

            if ($requiredEvents === []) {
                if ($currentEvents !== []) {
                    $this->unsubscribe($client, $targetUrl, $currentEvents);
                }

                return;
            }

            if ($this->sameEvents($requiredEvents, $currentEvents)) {
                return;
            }

            if ($currentEvents !== []) {
                $this->unsubscribe($client, $targetUrl, $currentEvents);
            }

            $client->service->webhooks()->subscribe($targetUrl, $requiredEvents);

            Log::info('Workflow amoCRM webhooks synchronized', [
                'account_id' => $account->id,
                'user_id' => $account->user_id,
                'events' => $requiredEvents,
            ]);
        } catch (Throwable $e) {
            Log::error('Workflow amoCRM webhook sync failed', [
                'account_id' => $account->id,
                'user_id' => $account->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     * @return array{events: array<int, string>, started: int}
     */
    public function handleIncomingWebhook(Account $account, array $payload, array $headers = []): array
    {
        $normalized = $this->normalizer->normalize($payload);
        $events = array_keys($normalized['events']);
        $started = 0;

        if ($events === []) {
            Log::warning('Workflow amoCRM webhook without supported events', [
                'account_id' => $account->id,
                'payload_keys' => array_keys($payload),
            ]);

            return ['events' => [], 'started' => 0];
        }

        $workflows = $this->matchingWorkflows((int)$account->user_id, $events);

        foreach ($workflows as $workflow) {
            $trigger = $workflow->getTriggerFromDefinition();
            $eventCode = (string)Arr::get($trigger, 'config.event');
            $event = $normalized['events'][$eventCode] ?? null;

            if ($event === null) {
                continue;
            }

            try {
                $this->startWorkflow($workflow, $account, $event, $normalized['payload'], $headers);
                $started++;
            } catch (Throwable $e) {
                Log::error('Workflow amoCRM webhook run failed', [
                    'account_id' => $account->id,
                    'workflow_id' => $workflow->id,
                    'event' => $eventCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'events' => $events,
            'started' => $started,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function requiredEventsForUser(int $userId): array
    {
        $events = $this->activeWorkflowQuery($userId)
            ->get()
            ->map(fn(Workflow $workflow): ?string => $this->workflowAmoCrmEvent($workflow))
            ->filter()
            ->unique()
            ->values()
            ->all();

        sort($events);

        return $events;
    }

    private function resolvePrimaryAccount(int $userId): ?Account
    {
        $account = Account::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where(function ($query): void {
                $query->where('widget', Account::DEFAULT_WIDGET)
                    ->orWhereNull('widget');
            })
            ->latest('id')
            ->first();

        if ($account instanceof Account) {
            return $account;
        }

        return Account::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->latest('id')
            ->first();
    }

    private function accountCanUseWebhooks(Account $account): bool
    {
        return (bool)$account->active
            && filled($account->subdomain)
            && filled($account->refresh_token);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Workflow>
     */
    private function activeWorkflowQuery(int $userId)
    {
        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');

        return Workflow::query()
            ->where((string)$tenantColumn, $userId)
            ->where('is_active', true);
    }

    private function workflowAmoCrmEvent(Workflow $workflow): ?string
    {
        $trigger = $workflow->getTriggerFromDefinition();

        if (!is_array($trigger)) {
            return null;
        }

        if (!str_starts_with((string)($trigger['type'] ?? ''), 'amocrm-')) {
            return null;
        }

        if ((string)Arr::get($trigger, 'config.source') !== 'amocrm') {
            return null;
        }

        $event = (string)Arr::get($trigger, 'config.event');

        return $event !== '' ? $event : null;
    }

    /**
     * @param array<int, string> $events
     * @return \Illuminate\Support\Collection<int, Workflow>
     */
    private function matchingWorkflows(int $userId, array $events)
    {
        return $this->activeWorkflowQuery($userId)
            ->get()
            ->filter(
                fn(Workflow $workflow): bool => in_array((string)$this->workflowAmoCrmEvent($workflow), $events, true)
            )
            ->values();
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     */
    private function startWorkflow(
        Workflow $workflow,
        Account $account,
        array $event,
        array $payload,
        array $headers
    ): void {
        $eventCode = (string)$event['event'];
        $entity = (string)$event['entity'];
        $action = (string)$event['action'];
        $item = is_array($event['item'] ?? null) ? $event['item'] : null;

        $triggerData = [
            'source' => 'amocrm',
            'event' => $eventCode,
            'entity' => $entity,
            'action' => $action,
            'payload' => $payload,
            'account' => [
                'id' => $account->id,
                'user_id' => $account->user_id,
                'subdomain' => $account->subdomain,
            ],
            'webhook' => [
                'headers' => $headers,
                'received_at' => now()->toIso8601String(),
            ],
            'received_at' => now()->toIso8601String(),
        ];

        if ($item !== null) {
            $triggerData['item'] = $item;
            $triggerData[$action] = $item;
            $triggerData[$entity] = $item;
        }

        $run = $this->executor->start(
            workflow: $workflow,
            triggerModel: null,
            triggerSource: TriggerType::WEBHOOK,
            triggeredBy: (int)$account->user_id,
        );

        $context = (new WorkflowContext($triggerData))
            ->setWorkflowId((int)$workflow->id)
            ->setWorkflowRunId((int)$run->id)
            ->setTriggerSource(TriggerType::WEBHOOK->value)
            ->setTriggeredBy((int)$account->user_id);

        $run->update(['context_data' => $context->toArray()]);

        $this->executor->execute($run->fresh() ?? $run);
    }

    /**
     * @return array<int, array{url: string, events: array<int, string>}>
     */
    private function platformHooks(Client $client, Account $account): array
    {
        $hooks = [];
        $pathNeedle = '/amocrm/workflows/hook/' . $account->getKey() . '/';

        foreach ($client->service->webhooks()->webhooks() as $webhook) {
            $hook = $this->webhookToArray($webhook);
            $url = (string)($hook['url'] ?? '');

            if ($url === '' || !Str::contains($url, $pathNeedle)) {
                continue;
            }

            $hooks[] = [
                'url' => $url,
                'events' => array_values(array_filter((array)($hook['events'] ?? []))),
            ];
        }

        return $hooks;
    }

    /**
     * @param array<int, array{url: string, events: array<int, string>}> $hooks
     * @return array<int, string>
     */
    private function eventsForUrl(array $hooks, string $url): array
    {
        foreach ($hooks as $hook) {
            if (($hook['url'] ?? '') === $url) {
                return array_values(array_filter($hook['events'] ?? []));
            }
        }

        return [];
    }

    /**
     * @param array<int, string> $events
     */
    private function unsubscribe(Client $client, string $url, array $events): void
    {
        if ($url === '') {
            return;
        }

        $client->service->webhooks()->unsubscribe($url, array_values(array_filter($events)));
    }

    /**
     * @param array<int, string> $left
     * @param array<int, string> $right
     */
    private function sameEvents(array $left, array $right): bool
    {
        sort($left);
        sort($right);

        return $left === $right;
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookToArray(mixed $webhook): array
    {
        if (is_array($webhook)) {
            return $webhook;
        }

        if (is_object($webhook) && method_exists($webhook, 'toArray')) {
            return $webhook->toArray();
        }

        return [];
    }
}
