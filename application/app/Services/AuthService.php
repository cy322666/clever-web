<?php

namespace App\Services;

use Filament\Http\Middleware\Authenticate;
use Filament\Models\Contracts\FilamentUser;

class AuthService extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $guardName = config('filament.auth.guard');
        $guard = $this->auth->guard($guardName);

        if (! $guard->check()) {
            $this->unauthenticated($request, $guards);

            return;
        }

        $this->auth->shouldUse($guardName);

        $user = $guard->user();

        if ($user instanceof FilamentUser) {
            abort_if(! $user->canAccessFilament(), 403);

            return;
        }
    }

    protected function redirectTo($request): string
    {
        return route('filament.auth.login');
    }
}
