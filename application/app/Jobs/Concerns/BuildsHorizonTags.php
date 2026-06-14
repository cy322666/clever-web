<?php

namespace App\Jobs\Concerns;

use App\Models\Core\Account;
use Illuminate\Database\Eloquent\Model;

trait BuildsHorizonTags
{
    /**
     * @param array<int, mixed> $tags
     * @return array<int, string>
     */
    protected function horizonTags(array $tags): array
    {
        return collect($tags)
            ->flatten()
            ->filter(static fn($tag): bool => is_scalar($tag) && trim((string)$tag) !== '')
            ->map(static fn($tag): string => trim((string)$tag))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function accountHorizonTags(?Account $account): array
    {
        if (!$account) {
            return ['account:unknown', 'client:unknown'];
        }

        return $this->horizonTags([
            'account:' . $account->id,
            'client:' . ($account->subdomain ?? 'unknown'),
            'amo:' . ($account->subdomain ?? 'unknown'),
            $account->user_id ? 'user:' . $account->user_id : null,
            'widget:' . Account::normalizeWidget($account->widget ?? Account::DEFAULT_WIDGET),
        ]);
    }

    protected function modelHorizonTag(string $type, Model|int|string|null $model): ?string
    {
        $id = $model instanceof Model ? $model->getKey() : $model;

        return filled($id) ? "{$type}:{$id}" : null;
    }
}
