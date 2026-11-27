<?php

namespace App\Services\Workflows;

use App\Models\User;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WorkflowAmoCrmInstallationContext
{
    public function issue(User $user): string
    {
        $state = 'workflows.'.Str::random(48);
        $this->store()->put($state, (int) $user->getKey(), now()->addMinutes(30));

        return $state;
    }

    public function consume(string $state): ?int
    {
        // A marketplace callback has no context issued by the Clever cabinet.
        if (!str_starts_with($state, 'workflows.') && !str_ends_with($state, '|workflows')) {
            return null;
        }

        if (preg_match('/\Aworkflows\.[a-zA-Z0-9]{48}\z/', $state)) {
            $store = $this->store();
            $userId = $store->lock($state.':consume', 5)->get(fn () => $store->pull($state));
            if (is_int($userId) && $userId > 0) {
                return $userId;
            }
        }

        throw ValidationException::withMessages([
            'connection' => 'Ссылка подключения устарела. Откройте подключение amoCRM из «Потоков» ещё раз.',
        ]);
    }

    private function store(): Repository
    {
        return Cache::store(config('widget_lifecycle.install_status_store', 'database'));
    }
}
