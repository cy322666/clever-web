<?php

namespace App\Http\Middleware\Api;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InputCountUser
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
        $user = $request->route('user') ?? $request->user;

        if (is_string($user)) {
            $user = User::query()
                ->where('uuid', $user)
                ->first();
        }

        $response = $next($request);

        if (!$user instanceof User) {
            return $response;
        }

        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        $user->increment('count_inputs');

        return $response;
    }
}
