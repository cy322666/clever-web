<?php

namespace App\Http\Middleware\Api;

use App\Models\App;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckActiveIntegration
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     * @param string $appName
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string $appName): Response
    {
        $user = $request->route('user') ?? $request->user;

        if (is_string($user)) {
            $user = User::query()
                ->where('uuid', $user)
                ->first();
        }

        if (!$user) {
            return new Response('user no found', 403);
        }

        $app = $user->apps()
            ->where('name', $appName)
            ->first();

        if (!$app) {
            return new Response('app no found', 403);
        }

        if (!$this->isAppActiveAndPaid($app)) {
            return new Response('app no active', 403);
        }

        return $next($request);
    }

    private function isAppActiveAndPaid(App $app): bool
    {
        if ((int)$app->status !== App::STATE_ACTIVE) {
            return false;
        }

        if (blank($app->expires_tariff_at)) {
            return true;
        }

        try {
            $expiresAt = Carbon::parse($app->expires_tariff_at)->startOfDay();
        } catch (\Throwable) {
            return false;
        }

        return !$expiresAt->lt(now()->startOfDay());
    }
}
