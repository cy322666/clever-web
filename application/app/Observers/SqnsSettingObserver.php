<?php

namespace App\Observers;

use App\Jobs\Sqns\DisconnectWebhook;
use App\Models\Integrations\Sqns\Setting;

final class SqnsSettingObserver
{
    public bool $afterCommit = true;

    public function updated(Setting $setting): void
    {
        if (! $setting->isConnected()) {
            return;
        }

        $becameInactive = $setting->wasChanged('active') && ! $setting->active;
        $connectedWhileInactive = $setting->wasChanged('token') && ! $setting->active;

        if ($becameInactive || $connectedWhileInactive) {
            DisconnectWebhook::dispatch(
                (int) $setting->id,
                $becameInactive ? 'setting_inactive' : 'connected_while_inactive',
            );
        }
    }
}
