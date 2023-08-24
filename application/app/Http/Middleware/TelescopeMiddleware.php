<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TelescopeMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->environment('local')) {

            return $next($request);
        }

        $user = $request->user();

        if ($user) {

            if ($user->is_root ||
            in_array($user->email, [])) {

                return $next($request);
            }
        }

        return abort(403);
    }
}
