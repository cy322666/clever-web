<?php

namespace App\Services\Workflows;

use App\Models\Core\Account;
use Illuminate\Database\Eloquent\Builder;

final class WorkflowConnectionAccess
{
    /** Only the dedicated workflow OAuth connection, never another widget's tokens. */
    public static function accounts(): Builder
    {
        $clientId = trim((string) config('services.amocrm.widgets.workflows.client_id', ''));

        return Account::query()->where('widget', 'workflows')
            ->when($clientId === '', fn (Builder $query) => $query->whereRaw('1 = 0'),
                fn (Builder $query) => $query->where('client_id', $clientId));
    }

    public static function isWidgetAccount(Account $account): bool
    {
        $clientId = trim((string) config('services.amocrm.widgets.workflows.client_id', ''));

        return $account->widget === 'workflows' && $clientId !== ''
            && hash_equals($clientId, (string) $account->client_id);
    }

    public static function prepareForAuthorization(Account $account): void
    {
        if ($account->widget === 'workflows' && !self::isWidgetAccount($account)) {
            // A new client_id must never relabel still-valid tokens from another OAuth app.
            $account->forceFill([
                'access_token' => null, 'refresh_token' => null,
                'expires_in' => null, 'created_at' => null, 'active' => false,
            ]);
        }
    }

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

        return $domains->count() === 1
            && $connected->contains(fn (Account $account): bool => self::isWidgetAccount($account));
    }
}
