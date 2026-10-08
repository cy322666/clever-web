<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AmoCrmInstallationStatus
{
    public function start(string $widget): string
    {
        $token = (string) Str::uuid();
        $this->put($token, ['status' => 'pending', 'widget' => $widget, 'started_at' => now()->timestamp]);

        return $token;
    }

    public function put(string $token, array $status): void
    {
        Cache::store(config('widget_lifecycle.install_status_store', 'database'))
            ->put('amocrm-installation:'.$token, $status, now()->addMinutes(30));
    }

    public function get(string $token): ?array
    {
        $store = Cache::store(config('widget_lifecycle.install_status_store', 'database'));
        $status = $store->get('amocrm-installation:'.$token);

        // Keep links and jobs created before the shared return flow working.
        foreach (['finder' => 'finder-installation:', 'workflows' => 'workflows-installation:'] as $widget => $prefix) {
            $legacy = $store->get($prefix.$token);
            if (is_array($legacy) && ($status === null
                || (($status['status'] ?? '') === 'pending' && ($legacy['status'] ?? '') !== 'pending'))) {
                return array_merge($legacy, ['widget' => $widget]);
            }
        }

        return $status;
    }
}
