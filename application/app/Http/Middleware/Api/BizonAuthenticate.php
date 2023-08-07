<?php

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Exceptions\DdException;

class BizonAuthenticate
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return mixed|void
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->webhook->active === true &&
            $request->webhook
                ->bizonSetting
                ->first()
                ->active == true) {

            return $next($request);
        } else
            Log::alert(__METHOD__.' неактивный статус для вебхука');
        //TODO убить редирект
        //TODO уведомление?
    }
}
