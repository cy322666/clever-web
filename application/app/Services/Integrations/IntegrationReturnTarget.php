<?php

namespace App\Services\Integrations;

use App\Models\App;

class IntegrationReturnTarget
{
    public function forUser(int $userId, string $widget): ?string
    {
        if ($userId <= 0 || ! is_array(config('integrations.definitions.'.$widget))) {
            return null;
        }

        $integration = App::query()->where('user_id', $userId)->where('name', $widget)->first();

        // The destination keeps the existing authentication and tenant checks.
        return $integration ? route('integrations.open', ['app' => $integration->id]) : null;
    }
}
