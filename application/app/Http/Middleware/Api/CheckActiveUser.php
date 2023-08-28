<?php

namespace App\Http\Middleware\Api;

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
     * @param \Illuminate\Http\Request $request
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user;

        if ($user && $user->active) {

            if (Carbon::parse($user->expires_tariff_at)->format('Y-m-d H') >
                Carbon::now()->format('Y-m-d H')) {

                return $next($request);
            } else {
                $user->active = false;
                $user->save();

                return (new Response('tariff expired', 403));
                //TODO push + set webhook
            }
        }

        return (new Response());
    }
}
