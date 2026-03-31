<?php

namespace App\Http\Middleware\Api;

use App\Models\Integrations\Assistant\Setting;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckAssistantToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->route('user');

        if (is_string($user)) {
            $user = User::query()
                ->where('uuid', $user)
                ->first();
        }

        if (!$user instanceof User) {
            return new Response('user no found', 403);
        }

        /** @var Setting|null $setting */
        $setting = Setting::query()
            ->where('user_id', $user->id)
            ->first();

        if (!$setting || !$setting->active || !$setting->service_token) {
            return new Response('assistant no active', 403);
        }

        $token = $request->header('X-Assistant-Token') ?: $request->bearerToken();

        if (!$token || !hash_equals((string)$setting->service_token, (string)$token)) {
            return new Response('assistant token invalid', 403);
        }

        $request->attributes->set('assistant_setting', $setting);

        return $next($request);
    }
}
