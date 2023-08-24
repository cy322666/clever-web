<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TelescopeMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param \Closure(Request): (Response) $next
     * @param User $user
     * @return bool
     */
    public function handle(Request $request, Closure $next, User $user): bool
    {
        if (app()->environment('local') ||
            $user->is_root ||
            in_array($user->email, [])) {

            return true;
        } else
            return false;
    }
}
