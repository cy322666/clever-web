<?php

namespace App\Http\Middleware;

use App\Models\User;
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
        if ($user->expires_tariff_at === null) {

            return $next($request);
        }
        //else deactivation
    }
}
