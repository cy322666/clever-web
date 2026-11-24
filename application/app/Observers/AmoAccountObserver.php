<?php

namespace App\Observers;

use App\Jobs\Sqns\DisconnectWebhook;
use App\Models\Core\Account;
use App\Models\Integrations\Sqns\Setting;
use Illuminate\Support\Facades\Schema;

final class AmoAccountObserver
{
    public bool $afterCommit = true;

    public function updated(Account $account): void
    {
        if (Account::normalizeWidget($account->widget) !== 'sqns') {
            return;
        }

        if (! $account->wasChanged(['active', 'access_token', 'refresh_token', 'subdomain'])) {
            return;
        }

        if ($account->active && filled($account->access_token) && filled($account->refresh_token) && filled($account->subdomain)) {
            return;
        }

        if (! Schema::hasTable('sqns_settings')) {
            return;
        }

        Setting::query()
            ->where('user_id', $account->user_id)
            ->whereNotNull('token')
            ->pluck('id')
            ->each(fn (int $settingId) => DisconnectWebhook::dispatch($settingId, 'amocrm_disconnected'));
    }
}
