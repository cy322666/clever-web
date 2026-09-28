<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = (string) ($request->user()?->locale ?: config('app.locale', 'ru'));

        app()->setLocale(in_array($locale, ['ru', 'en'], true) ? $locale : 'ru');

        return $next($request);
    }
}
