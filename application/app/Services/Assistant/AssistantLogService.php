<?php

namespace App\Services\Assistant;

use App\Models\Integrations\Assistant\AssistantLog;
use App\Models\Integrations\Assistant\Setting;
use App\Models\User;
use Throwable;

class AssistantLogService
{
    public function logEndpoint(
        Setting $setting,
        User $user,
        string $endpoint,
        array $requestPayload,
        array $responsePayload = [],
        array $context = [],
        ?float $startedAt = null
    ): AssistantLog {
        return $this->store(
            $setting,
            $user,
            array_merge($context, [
                'endpoint' => $endpoint,
                'request_payload' => $requestPayload,
                'response_payload' => $responsePayload,
                'latency_ms' => $this->resolveLatency($startedAt),
                'status' => $context['status'] ?? 'success',
            ])
        );
    }

    public function store(Setting $setting, User $user, array $payload): AssistantLog
    {
        $account = $setting->amoAccount(false, 'assistant');

        return AssistantLog::query()->create([
            'assistant_setting_id' => $setting->id,
            'user_id' => $user->id,
            'account_id' => $account?->id,
            'source' => $payload['source'] ?? 'api',
            'status' => $payload['status'] ?? 'success',
            'endpoint' => $payload['endpoint'] ?? null,
            'tool' => $payload['tool'] ?? null,
            'model' => $payload['model'] ?? null,
            'prompt_version' => $payload['prompt_version'] ?? null,
            'latency_ms' => $payload['latency_ms'] ?? null,
            'input_tokens' => $payload['input_tokens'] ?? null,
            'output_tokens' => $payload['output_tokens'] ?? null,
            'total_tokens' => $payload['total_tokens'] ?? null,
            'request_payload' => $payload['request_payload'] ?? null,
            'response_payload' => $payload['response_payload'] ?? null,
            'error' => $payload['error'] ?? null,
        ]);
    }

    private function resolveLatency(?float $startedAt): ?int
    {
        if ($startedAt === null) {
            return null;
        }

        return (int)round((microtime(true) - $startedAt) * 1000);
    }

    public function logError(
        Setting $setting,
        User $user,
        string $endpoint,
        array $requestPayload,
        Throwable $exception,
        array $context = [],
        ?float $startedAt = null
    ): AssistantLog {
        return $this->store(
            $setting,
            $user,
            array_merge($context, [
                'endpoint' => $endpoint,
                'request_payload' => $requestPayload,
                'response_payload' => [],
                'latency_ms' => $this->resolveLatency($startedAt),
                'status' => 'error',
                'error' => $exception->getMessage(),
            ])
        );
    }
}
