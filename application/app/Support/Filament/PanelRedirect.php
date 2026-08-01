<?php

namespace App\Support\Filament;

use Illuminate\Http\Request;

class PanelRedirect
{
    public static function dashboardUrl(): string
    {
        return route('filament.app.pages.dashboard');
    }

    public static function intendedOrDashboard(Request $request): string
    {
        $fallback = self::dashboardUrl();
        $intended = (string)$request->session()->pull('url.intended', '');

        if ($intended === '') {
            return $fallback;
        }

        $path = (string)(parse_url($intended, PHP_URL_PATH) ?: '');
        $normalizedPath = '/' . trim($path, '/');

        if (in_array($normalizedPath, ['/panel', '/panel/login', '/panel/register'], true)) {
            return $fallback;
        }

        if (str_starts_with($normalizedPath, '/panel/password-reset')) {
            return $fallback;
        }

        if (str_starts_with($intended, '/')) {
            return str_starts_with($intended, '//') ? $fallback : $intended;
        }

        $host = (string)(parse_url($intended, PHP_URL_HOST) ?: '');
        $appHost = (string)(parse_url(config('app.url'), PHP_URL_HOST) ?: $request->getHost());

        if ($host !== '' && $host !== $appHost && $host !== $request->getHost()) {
            return $fallback;
        }

        return $intended;
    }
}
