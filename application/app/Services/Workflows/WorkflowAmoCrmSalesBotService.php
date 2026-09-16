<?php

declare(strict_types=1);

namespace App\Services\Workflows;

use App\Models\Core\Account;
use App\Models\User;
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
        $account = $this->resolveAccount();

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

    private function resolveAccount(): ?Account
    {
        $userId = Auth::id();

        if (!$userId || !WorkflowConnectionAccess::hasActiveConnection((int) $userId)) {
            return null;
        }

        $account = User::query()->find((int) $userId)?->resolveAmoAccountForWidget('workflows');

        return $account instanceof Account && filled($account->refresh_token) ? $account : null;
    }

    private function cacheKey(Account $account): string
    {
        return 'workflow:amocrm:salesbots:v4:' . $account->getKey();
    }

    /**
     * @return array<string, string>
     */
    private function loadOptions(Account $account): array
    {
        $client = $this->client($account);
        $bots = [];
        for ($page = 1; $page <= 20; $page++) {
            $response = $client->requestV4('GET', '/api/v4/bots', query: ['limit' => 250, 'page' => $page]);
            $items = data_get($response, '_embedded.items', []);
            if (!is_array($items)) throw new \RuntimeException('Некорректный ответ списка Salesbot.');
            $bots = array_merge($bots, $items);
            $hasNext = filled(data_get($response, '_links.next.href'))
                || (int) ($response['_page_count'] ?? 0) > $page
                || (!isset($response['_page_count']) && count($items) === 250);
            if (!$hasNext) break;
            if ($page === 20) throw new \RuntimeException('Список Salesbot превышает 5000 ботов. Укажите ID бота вручную.');
        }

        return $this->labels($bots);
    }

    public function replaceOptions(Account $account, array $bots): void
    {
        abort_unless((int) $account->user_id === (int) Auth::id(), 403);
        Cache::put($this->cacheKey($account), $this->labels($bots), now()->addMinutes(5));
    }

    private function labels(array $bots): array
    {
        return collect($bots)
            ->filter(fn(mixed $bot): bool => is_array($bot) && filled($bot['id'] ?? null))
            ->sortBy(fn(array $bot): string => sprintf(
                '%d:%s',
                data_get($bot, 'settings.active', true) ? 0 : 1,
                mb_strtolower((string)($bot['name'] ?? '')),
            ))
            ->mapWithKeys(function (array $bot): array {
                $id = (string)$bot['id'];
                $name = filled($bot['name'] ?? null)
                    ? html_entity_decode((string)$bot['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    : "SalesBot #{$id}";
                $label = data_get($bot, 'settings.active', true) ? $name : "{$name} · неактивен";

                return [$id => $label];
            })
            ->all();
    }

    protected function client(Account $account): Client
    {
        return new Client($account);
    }
}
