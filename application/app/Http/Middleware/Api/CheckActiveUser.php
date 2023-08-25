<?php

namespace App\Http\Middleware;

use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckActiveUser
{
    /**
     * Handle an incoming request.
     *
     * @param \App\Models\User $user
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     * @return \Symfony\Component\HttpFoundation\Response|null
     */
    public function handle(User $user, Request $request, Closure $next): null|Response
    {
        if ($user->active) {

            if ($user->expires_tariff_at !== null && !$user->is_root) {

                if (Carbon::parse($user->expires_tariff_at)->format('Y-m-d H') >
                    Carbon::now()->format('Y-m-d H')) {

                    return $next($request);
                } else {
                    $user->active = false;
                    $user->save();

                    //TODO push + set webhook
                }
            } else
                return $next($request);
        }
    }
}
