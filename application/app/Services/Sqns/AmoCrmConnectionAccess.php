<?php

namespace App\Services\Sqns;

use App\Models\Core\Account;

final class AmoCrmConnectionAccess
{
    public static function isWidgetAccount(Account $account): bool
    {
        if (Account::normalizeWidget($account->widget) !== 'sqns') {
            return false;
        }

        foreach (['client_id', 'client_secret'] as $key) {
            $expected = trim((string) config('services.amocrm.widgets.sqns.'.$key, ''));
            if ($expected === '' || ! hash_equals($expected, trim((string) $account->{$key}))) {
                return false;
            }
        }

        return true;
    }

    public static function prepareForAuthorization(Account $account): void
    {
        if (self::isWidgetAccount($account)) {
            return;
        }

        $account->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'expires_in' => null,
            'created_at' => null,
            'active' => false,
        ]);
    }
}
