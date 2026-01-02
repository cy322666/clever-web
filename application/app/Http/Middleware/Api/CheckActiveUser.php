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
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user;

        if (is_string($user)) {

            $user = User::query()
                ->where('uuid', $user)
                ->first();
        }

        if ($user && $user->active) {

            return $next($request);
        }

        return (new Response(null, 403));
    }
}
