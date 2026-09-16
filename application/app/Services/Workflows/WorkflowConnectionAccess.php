<?php

namespace App\Services\Workflows;

use App\Models\Core\Account;

final class WorkflowConnectionAccess
{
    public static function hasActiveConnection(int $userId): bool
    {
        if ($userId <= 0) return false;

        $connected = Account::query()->where('user_id', $userId)->get()
            ->filter(fn (Account $account): bool =>
                (bool) $account->active
                && filled($account->subdomain)
                && filled($account->refresh_token)
        );

        $domains = $connected->map(fn (Account $account): string =>
            strtolower(trim($account->subdomain)).'.'.strtolower(trim($account->zone ?: 'ru'))
        )->unique();

        return $domains->count() === 1;
    }
}
