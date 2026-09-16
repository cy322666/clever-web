<?php

namespace App\Services\Workflows;

use App\Jobs\Workflows\SynchronizeAmoCrmWebhooks;
use App\Models\Workflows\Workflow;
use App\Services\Billing\WidgetSubscriptionAccessService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Leek\FilamentWorkflows\Exceptions\NonRetryableWorkflowException;

final class WorkflowSubscriptionAccess
{
    public const BLOCKED_MESSAGE = 'Оплаченный или тестовый период «Потоков» закончился. Продлите подписку, чтобы включать и запускать сценарии.';

    public function canUse(int $userId): bool
    {
        return $userId > 0
            && app(WidgetSubscriptionAccessService::class)->canUse($userId, 'workflows');
    }

    public function activationIssue(int $userId): ?string
    {
        return $this->canUse($userId) ? null : self::BLOCKED_MESSAGE;
    }

    public function assertCanExecute(int $userId): void
    {
        if (! $this->canUse($userId)) {
            $this->deactivateForUser($userId);

            throw new NonRetryableWorkflowException(self::BLOCKED_MESSAGE);
        }
    }

    public function deactivateForUser(int $userId): int
    {
        if ($userId <= 0 || ! Schema::hasTable('workflows')) {
            return 0;
        }

        $tenantColumn = config('filament-workflows.tenancy.column', 'user_id');
        $count = Workflow::query()
            ->where($tenantColumn, $userId)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        if ($count > 0 && ! app()->environment('testing')) {
            try {
                SynchronizeAmoCrmWebhooks::dispatch($userId);
            } catch (\Throwable $exception) {
                Log::warning('workflow subscription webhook cleanup dispatch failed', [
                    'user_id' => $userId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $count;
    }
}
