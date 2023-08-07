<?php

namespace App\Http\Middleware\Api;

use App\Models\Webhook;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Exceptions\DdException;

class GetCourseAuthenticate
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
        Log::info($request->path(), $request->toArray());

        if ($request->webhook->active === true &&
            $request->webhook
                ->getcourseSetting
                ->active == true) {

            return $next($request);
        } else
            Log::alert(__METHOD__.' неактивный статус для вебхука');
        //TODO убить редирект
        //TODO уведомление?
    }
}
