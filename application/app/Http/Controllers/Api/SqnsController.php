<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\Sqns\ProcessWebhook;
use App\Models\User;
use App\Services\Sqns\VisitPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SqnsController extends Controller
{
    public function hook(User $user, string $key, Request $request): JsonResponse
    {
        $setting = $user->sqnsSetting()->first();

        if (! $setting || ! hash_equals((string) $setting->webhook_key, $key)) {
            return response()->json(['ok' => false, 'message' => 'invalid webhook key'], 403);
        }

        $account = $setting->amoAccount(false, 'sqns');

        if (! $account) {
            return response()->json(['ok' => false, 'message' => 'amoCRM account is not configured'], 422);
        }

        $payload = $request->all();
        $visitId = VisitPayload::id($payload);

        if (! $visitId) {
            return response()->json(['ok' => false, 'message' => 'visit id is required'], 422);
        }

        Log::info('SQNS webhook accepted.', [
            'user_id' => $user->id,
            'setting_id' => $setting->id,
            'visit_id' => $visitId,
            'payload_keys' => array_keys($payload),
        ]);

        ProcessWebhook::dispatch($setting->id, $account->id, $payload);

        return response()->json(['ok' => true, 'visit_id' => $visitId], 202);
    }
}
