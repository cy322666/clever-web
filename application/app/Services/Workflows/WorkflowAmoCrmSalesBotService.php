<?php

declare(strict_types=1);

namespace App\Services\Workflows;

use App\Models\Core\Account;
use App\Services\amoCRM\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class WorkflowAmoCrmSalesBotService
{
    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $account = Auth::user()?->resolveAmoAccountForWidget(null, false);

        if (!$account instanceof Account) {
            return [];
        }

        try {
            return Cache::remember(
                $this->cacheKey($account),
                now()->addMinutes(5),
                fn(): array => $this->loadOptions($account),
            );
        } catch (Throwable $exception) {
            Log::warning('Workflow amoCRM SalesBot list loading failed', [
                'account_id' => $account->getKey(),
                'user_id' => $account->user_id,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function cacheKey(Account $account): string
    {
        return 'workflow:amocrm:salesbots:' . $account->getKey();
    }

    /**
     * @return array<string, string>
     */
    private function loadOptions(Account $account): array
    {
        $response = (new Client($account))->requestV4('GET', '/api/v4/bots', query: [
            'limit' => 250,
        ]);

        return collect(data_get($response, '_embedded.bots', []))
            ->filter(fn(mixed $bot): bool => is_array($bot) && filled($bot['id'] ?? null))
            ->sortBy([
                fn(array $bot): int => ($bot['is_active'] ?? true) ? 0 : 1,
                fn(array $bot): string => mb_strtolower((string)($bot['name'] ?? '')),
            ])
            ->mapWithKeys(function (array $bot): array {
                $id = (string)$bot['id'];
                $name = filled($bot['name'] ?? null) ? (string)$bot['name'] : "SalesBot #{$id}";
                $label = ($bot['is_active'] ?? true) ? $name : "{$name} · неактивен";

                return [$id => $label];
            })
            ->all();
    }
}
