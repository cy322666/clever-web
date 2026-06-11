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

            $result = $webhooks->synchronizeUser((int)$user->id);
            $this->line((string)($result['message'] ?? 'Синхронизация завершена.'));

            return ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $failed = 0;

        Account::query()
            ->where('active', true)
            ->whereNotNull('refresh_token')
            ->select('user_id')
            ->distinct()
            ->pluck('user_id')
            ->each(function ($id) use ($webhooks, &$failed): void {
                $result = $webhooks->synchronizeUser((int)$id);

                if (!($result['ok'] ?? false)) {
                    $failed++;
                    $this->warn(sprintf('Пользователь #%d: %s', $id, $result['message'] ?? 'ошибка'));
                }
            });

        $this->info(sprintf('Синхронизация завершена. Ошибок: %d.', $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
