<?php

namespace App\Http\Middleware;

use App\Filament\App\Pages\Onboarding;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingCompleted
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user instanceof User
            && $user->needsOnboarding()
            && ! $request->routeIs(Onboarding::getRouteName())
            && ! $request->routeIs('filament.app.auth.logout')
        ) {
            return redirect()->to(Onboarding::getUrl());
        }

        return $next($request);
    }
}
