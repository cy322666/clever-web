<?php

namespace App\Console\Commands\Workflows;

use App\Models\Core\Account;
use App\Models\User;
use App\Services\Workflows\WorkflowAmoCrmWebhookService;
use Illuminate\Console\Command;

class SyncAmoCrmWebhooks extends Command
{
    protected $signature = 'workflows:amocrm-webhooks:sync {user_id? : ID пользователя}';

    protected $description = 'Синхронизировать webhook-подписки amoCRM для активных процессов.';

    public function handle(WorkflowAmoCrmWebhookService $webhooks): int
    {
        $userId = $this->argument('user_id');

        if ($userId !== null) {
            $user = User::query()->find((int)$userId);

            if (!$user instanceof User) {
                $this->error('Пользователь не найден.');

                return self::FAILURE;
            }

            $webhooks->synchronizeUser((int)$user->id);
            $this->info('Webhook-подписки amoCRM пересинхронизированы.');

            return self::SUCCESS;
        }

        Account::query()
            ->where('active', true)
            ->whereNotNull('refresh_token')
            ->select('user_id')
            ->distinct()
            ->pluck('user_id')
            ->each(fn($id) => $webhooks->synchronizeUser((int)$id));

        $this->info('Webhook-подписки amoCRM пересинхронизированы для всех активных аккаунтов.');

        return self::SUCCESS;
    }
}
