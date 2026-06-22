<?php

namespace App\Services\Workflows;

use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\amoCRM\Client;
use App\Workflows\Context\WorkflowContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Leek\FilamentWorkflows\Jobs\ExecuteWorkflowJob;
use Leek\FilamentWorkflows\Engine\WorkflowExecutor;
use Leek\FilamentWorkflows\Enums\TriggerType;
use Throwable;

class WorkflowAmoCrmWebhookService
{
    public function __construct(
        private readonly WorkflowAmoCrmWebhookPayloadNormalizer $normalizer,
        private readonly WorkflowExecutor $executor,
        private readonly WorkflowAmoCrmLoopGuard $loopGuard,
    )
    {
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
        $path = route('amocrm.workflows.hook', [
            'account' => $account->getKey(),
            'signature' => $this->signature($account),
        ], false);

        return rtrim($this->publicBaseUrl(), '/') . $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function statusForUser(int $userId): array
    {
        $account = $this->resolvePrimaryAccount($userId);

        if (!$account instanceof Account) {
            return $this->missingAccountStatus($userId);
        }

        return $this->statusForAccount($account);
    }

    /**
     * @return array<string, mixed>
     */
    public function statusForAccount(Account $account): array
    {
        if (!$this->accountCanUseWebhooks($account)) {
            return $this->baseStatus(
                account: $account,
                requiredEvents: $this->requiredEventsForUser((int)$account->user_id),
                ok: false,
                state: 'disconnected',
                connected: false,
                message: 'Подключение amoCRM не готово к работе с вебхуками.',
            );
        }

        $requiredEvents = $this->requiredEventsForUser((int)$account->user_id);
        $targetUrl = $this->callbackUrl($account);

        try {
            if (!$this->isValidPublicWebhookUrl($targetUrl)) {
                return $this->baseStatus(
                    account: $account,
                    requiredEvents: $requiredEvents,
                    ok: false,
                    state: 'error',
                    message: 'Нельзя установить вебхуки amoCRM: URL приёмника недоступен извне. Укажите публичный HTTPS-домен в WORKFLOW_PUBLIC_URL. Сейчас: ' . $targetUrl,
                );
            }

            $client = new Client($account);
            $currentHooks = $this->platformHooks($client, $account);
            $targetHook = collect($currentHooks)->firstWhere('url', $targetUrl);
            $currentEvents = $targetHook['events'] ?? [];
            $disabled = (bool)($targetHook['disabled'] ?? false);
            $installed = $targetHook !== null;
            $matches = $installed
                && !$disabled
                && $this->sameEvents($requiredEvents, $currentEvents);
            $staleHooks = collect($currentHooks)
                ->where('url', '!=', $targetUrl)
                ->pluck('url')
                ->values()
                ->all();
            $state = $staleHooks === []
                ? $this->statusState($requiredEvents, $installed, $matches, $disabled)
                : 'outdated';
            $message = $this->statusMessage($requiredEvents, $installed, $matches, $disabled);

            if ($staleHooks !== []) {
                $message .= sprintf(' Найдено устаревших вебхуков: %d.', count($staleHooks));
            }

            return [
                ...$this->baseStatus(
                    account: $account,
                    requiredEvents: $requiredEvents,
                    state: $state,
                    message: $message,
                ),
                'installed_events' => $currentEvents,
                'installed' => $installed,
                'matches' => $matches,
                'disabled' => $disabled,
                'stale_hooks' => $staleHooks,
            ];
        } catch (Throwable $e) {
            Log::error('Workflow amoCRM webhook status failed', [
                'account_id' => $account->id,
                'user_id' => $account->user_id,
                'error' => $e->getMessage(),
            ]);

            return $this->baseStatus(
                account: $account,
                requiredEvents: $requiredEvents,
                ok: false,
                state: 'error',
                message: 'Не удалось проверить вебхуки amoCRM: ' . $e->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function synchronizeUser(int $userId): array
    {
        $account = $this->resolvePrimaryAccount($userId);

        if (!$account instanceof Account) {
            return $this->missingAccountStatus($userId);
        }

        return $this->synchronizeAccount($account);
    }

    /**
     * @return array<string, mixed>
     */
    public function synchronizeAccount(Account $account): array
    {
        if (!$this->accountCanUseWebhooks($account)) {
            return $this->statusForAccount($account);
        }

        $requiredEvents = $this->requiredEventsForUser((int)$account->user_id);
        $targetUrl = $this->callbackUrl($account);

        if (!$this->isValidPublicWebhookUrl($targetUrl)) {
            return $this->baseStatus(
                account: $account,
                requiredEvents: $requiredEvents,
                ok: false,
                state: 'error',
                message: 'Нельзя установить вебхуки amoCRM: URL приёмника недоступен извне. Укажите публичный HTTPS-домен в WORKFLOW_PUBLIC_URL. Сейчас: ' . $targetUrl,
            );
        }

        try {
            $client = new Client($account);
            $currentHooks = $this->platformHooks($client, $account);

            foreach ($currentHooks as $hook) {
                if (($hook['url'] ?? '') !== $targetUrl && $this->isOwnedPlatformHookUrl((string)($hook['url'] ?? ''), $account)) {
                    $this->unsubscribe($client, (string)$hook['url']);
                }
            }

            if ($requiredEvents === []) {
                if (collect($currentHooks)->contains('url', $targetUrl)) {
                    $this->unsubscribe($client, $targetUrl);
                }

                return $this->statusForAccount($account);
            }

            $client->requestV4('POST', '/api/v4/webhooks', [
                'destination' => $targetUrl,
                'settings' => $requiredEvents,
            ]);

            Log::info('Workflow amoCRM webhooks synchronized', [
                'account_id' => $account->id,
                'user_id' => $account->user_id,
                'events' => $requiredEvents,
            ]);

            return $this->statusForAccount($account);
        } catch (Throwable $e) {
            Log::error('Workflow amoCRM webhook sync failed', [
                'account_id' => $account->id,
                'user_id' => $account->user_id,
                'error' => $e->getMessage(),
            ]);

            return $this->baseStatus(
                account: $account,
                requiredEvents: $requiredEvents,
                ok: false,
                state: 'error',
                message: 'Не удалось синхронизировать вебхуки amoCRM: ' . $e->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function removeUser(int $userId): array
    {
        $account = $this->resolvePrimaryAccount($userId);

        if (!$account instanceof Account) {
            return $this->missingAccountStatus($userId);
        }

        return $this->removeAccount($account);
    }

    /**
     * @return array<string, mixed>
     */
    public function removeAccount(Account $account): array
    {
        if (!$this->accountCanUseWebhooks($account)) {
            return $this->statusForAccount($account);
        }

        try {
            $client = new Client($account);
            $removed = 0;

            foreach ($this->platformHooks($client, $account) as $hook) {
                $this->unsubscribe($client, (string)($hook['url'] ?? ''));
                $removed++;
            }

            return [
                ...$this->baseStatus(
                    account: $account,
                    requiredEvents: $this->requiredEventsForUser((int)$account->user_id),
                    state: 'missing',
                    message: $removed > 0
                        ? 'Вебхуки процессов удалены из amoCRM.'
                        : 'Вебхуки процессов в amoCRM не найдены.',
                ),
                'removed' => $removed,
            ];
        } catch (Throwable $e) {
            Log::error('Workflow amoCRM webhook removal failed', [
                'account_id' => $account->id,
                'user_id' => $account->user_id,
                'error' => $e->getMessage(),
            ]);

            return $this->baseStatus(
                account: $account,
                requiredEvents: $this->requiredEventsForUser((int)$account->user_id),
                ok: false,
                state: 'error',
                message: 'Не удалось удалить вебхуки amoCRM: ' . $e->getMessage(),
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $headers
     * @return array{events: array<int, string>, started: int, skipped: int}
     */
    public function handleIncomingWebhook(Account $account, array $payload, array $headers = []): array
    {
        $normalized = $this->normalizer->normalize($payload);
        $events = array_keys($normalized['events']);
        $started = 0;
        $skipped = 0;

        if ($events === []) {
            Log::warning('Workflow amoCRM webhook without supported events', [
                'account_id' => $account->id,
                'payload_keys' => array_keys($payload),
            ]);

            return ['events' => [], 'started' => 0, 'skipped' => 0];
        }

        $workflows = $this->matchingWorkflows((int)$account->user_id, $events);

        foreach ($workflows as $workflow) {
            $trigger = $workflow->getTriggerFromDefinition();
            $eventCode = (string)Arr::get($trigger, 'config.event');
            $event = $normalized['events'][$eventCode] ?? null;

            if ($event === null) {
                continue;
            }

            $recentMutation = $this->loopGuard->matchingRecentMutation($workflow, $account, $event);

            if ($recentMutation !== null) {
                $skipped++;

                Log::info('Workflow amoCRM webhook skipped to prevent self loop', [
                    'account_id' => $account->id,
                    'workflow_id' => $workflow->id,
                    'workflow_run_id' => $recentMutation['workflow_run_id'] ?? null,
                    'event' => $eventCode,
                    'entity' => $event['entity'] ?? null,
                    'entity_id' => $recentMutation['entity_id'] ?? null,
                    'action_type' => $recentMutation['action_type'] ?? null,
                ]);

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
            'skipped' => $skipped,
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
        $workflowAccount = Account::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('widget', 'workflows')
            ->latest('id')
            ->first();

        if ($workflowAccount instanceof Account || $userId !== 1) {
            return $workflowAccount;
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

        ExecuteWorkflowJob::dispatch((int)$run->id);
    }

    /**
     * @return array<int, array{url: string, events: array<int, string>}>
     */
    private function platformHooks(Client $client, Account $account): array
    {
        $hooks = [];
        $pathNeedle = '/amocrm/workflows/hook/' . $account->getKey() . '/';
        $response = $client->requestV4('GET', '/api/v4/webhooks');

        foreach ((array)data_get($response, '_embedded.webhooks', []) as $webhook) {
            $hook = $this->webhookToArray($webhook);
            $url = (string)($hook['destination'] ?? $hook['url'] ?? '');

            if ($url === '' || !Str::contains($url, $pathNeedle) || !$this->isOwnedPlatformHookUrl($url, $account)) {
                continue;
            }

            $hooks[] = [
                'url' => $url,
                'events' => array_values(array_filter((array)($hook['settings'] ?? $hook['events'] ?? []))),
                'disabled' => (bool)($hook['disabled'] ?? false),
            ];
        }

        return $hooks;
    }

    private function unsubscribe(Client $client, string $url): void
    {
        if ($url === '') {
            return;
        }

        $client->requestV4('DELETE', '/api/v4/webhooks', [
            'destination' => $url,
        ]);
    }

    private function isOwnedPlatformHookUrl(string $url, Account $account): bool
    {
        $parts = parse_url($url);
        $path = '/' . ltrim((string)($parts['path'] ?? ''), '/');
        $prefix = '/api/amocrm/workflows/hook/' . $account->getKey() . '/';

        if (!str_starts_with($path, $prefix)) {
            return false;
        }

        $signature = trim(Str::after($path, $prefix), '/');

        return $signature !== '' && $this->signatureIsValid($account, $signature);
    }

    private function publicBaseUrl(): string
    {
        return rtrim((string)(config('workflow-webhooks.public_url') ?: config('app.url')), '/');
    }

    private function isValidPublicWebhookUrl(string $url): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));

        if (!in_array($scheme, ['https', 'http'], true) || $host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            return false;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
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

    /**
     * @return array<string, mixed>
     */
    private function missingAccountStatus(int $userId): array
    {
        return [
            'ok' => false,
            'state' => 'disconnected',
            'account_id' => null,
            'user_id' => $userId,
            'connected' => false,
            'message' => 'Активное подключение amoCRM не найдено.',
            'required_events' => $this->requiredEventsForUser($userId),
            'installed_events' => [],
            'installed' => false,
            'matches' => false,
            'disabled' => false,
            'callback_url' => null,
            'stale_hooks' => [],
        ];
    }

    /**
     * @param array<int, string> $requiredEvents
     * @return array<string, mixed>
     */
    private function baseStatus(
        Account $account,
        array $requiredEvents,
        bool $ok = true,
        string $state = 'missing',
        bool $connected = true,
        string $message = '',
    ): array {
        return [
            'ok' => $ok,
            'state' => $state,
            'account_id' => $account->id,
            'user_id' => $account->user_id,
            'connected' => $connected,
            'message' => $message,
            'required_events' => $requiredEvents,
            'installed_events' => [],
            'installed' => false,
            'matches' => false,
            'disabled' => false,
            'callback_url' => $this->callbackUrl($account),
            'stale_hooks' => [],
        ];
    }

    /**
     * @param array<int, string> $requiredEvents
     */
    private function statusState(array $requiredEvents, bool $installed, bool $matches, bool $disabled): string
    {
        if ($requiredEvents === []) {
            return $installed ? 'outdated' : 'not_required';
        }

        if (!$installed) {
            return 'missing';
        }

        if ($disabled || !$matches) {
            return 'outdated';
        }

        return 'installed';
    }

    /**
     * @param array<int, string> $requiredEvents
     */
    private function statusMessage(array $requiredEvents, bool $installed, bool $matches, bool $disabled): string
    {
        if ($requiredEvents === []) {
            return $installed
                ? 'Webhook установлен, но активным процессам события amoCRM не требуются.'
                : 'Активным процессам события amoCRM не требуются.';
        }

        if (!$installed) {
            return 'Webhook не установлен.';
        }

        if ($disabled) {
            return 'Webhook установлен, но отключён в amoCRM.';
        }

        if (!$matches) {
            return 'Webhook установлен, но список событий отличается от активных процессов.';
        }

        return 'Webhook установлен и соответствует активным процессам.';
    }
}
