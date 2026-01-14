<?php

namespace App\Http\Middleware\Api;

use App\Models\App;
use App\Models\User;
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
        $user = $request->user ?? $request->route('user');

        if (is_string($user)) {
            $user = User::query()
                ->where('uuid', $user)
                ->first();
        }

        if (!$user) {
            return new Response(null, 403);
        }

        $app = $user->apps()
            ->where('name', $appName)
            ->first();

        if (!$app || $app->status !== App::STATE_ACTIVE) {
            return new Response(null, 403);
        }

        return $next($request);
    }
}
