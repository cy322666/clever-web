<?php

namespace App\Http\Middleware\Api;

use App\Models\User;
use App\Services\Billing\WidgetSubscriptionAccessService;
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
        if (!array_key_exists($appName, config('integrations.definitions', []))) {
            return new Response('app no found', 403);
        }

        $user = $request->route('user') ?? $request->user;

        if (is_string($user)) {
            $user = User::query()
                ->where('uuid', $user)
                ->first();
        }

        if (!$user) {
            return new Response('user no found', 403);
        }

        if (!app(WidgetSubscriptionAccessService::class)->canUse($user, $appName)) {
            return new Response('app no active', 403);
        }

        return $next($request);
    }
}
