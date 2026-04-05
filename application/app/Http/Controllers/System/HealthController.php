<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (!$request->user() || !(bool)$request->user()->is_root) {
            abort(403);
        }

        $checks = [
            'app' => 'ok',
            'db' => 'ok',
            'scheduler' => 'unknown',
        ];

        $dbError = null;
        $schedulerAgeSeconds = null;

        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            $checks['db'] = 'fail';
            $dbError = $e->getMessage();
        }

        $heartbeatTs = (int)Cache::get('monitoring:scheduler:last_heartbeat', 0);

        if ($heartbeatTs > 0) {
            $schedulerAgeSeconds = max(0, now()->timestamp - $heartbeatTs);
            $checks['scheduler'] = $schedulerAgeSeconds <= 180 ? 'ok' : 'fail';
        }

        $ok = $checks['app'] === 'ok'
            && $checks['db'] === 'ok'
            && $checks['scheduler'] !== 'fail';

        $payload = [
            'status' => $ok ? 'ok' : 'fail',
            'time' => now()->toIso8601String(),
            'checks' => $checks,
            'scheduler_age_seconds' => $schedulerAgeSeconds,
            'app_env' => app()->environment(),
        ];

        if ($dbError) {
            $payload['db_error'] = $dbError;
        }

        return response()->json($payload, $ok ? 200 : 503);
    }
}
