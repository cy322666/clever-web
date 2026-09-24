<?php

namespace App\Services\Finder;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class InstallationStatus
{
    public function start(): string
    {
        $token = (string) Str::uuid();
        $this->put($token, ['status' => 'pending', 'started_at' => time()]);

        return $token;
    }

    public function put(string $token, array $status): void
    {
        Cache::store(config('widget_lifecycle.install_status_store', 'database'))
            ->put('finder-installation:'.$token, $status, now()->addMinutes(30));
    }

    public function get(string $token): ?array
    {
        return Cache::store(config('widget_lifecycle.install_status_store', 'database'))
            ->get('finder-installation:'.$token);
    }
}
