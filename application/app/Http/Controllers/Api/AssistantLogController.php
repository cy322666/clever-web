<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Assistant\StoreAssistantLogRequest;
use App\Models\Integrations\Assistant\Setting;
use App\Models\User;
use App\Services\Assistant\AssistantLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AssistantLogController extends Controller
{
    public function __construct(private readonly AssistantLogService $logService)
    {
    }

    public function store(User $user, StoreAssistantLogRequest $request): JsonResponse
    {
        $setting = $this->resolveSetting($user, $request);

        if (!$setting) {
            return response()->json([
                'message' => 'Assistant setting not found',
            ], 403);
        }

        try {
            $log = $this->logService->store($setting, $user, $request->validated());

            return response()->json([
                'data' => [
                    'id' => $log->id,
                    'status' => $log->status,
                    'endpoint' => $log->endpoint,
                    'tool' => $log->tool,
                    'created_at' => $log->created_at?->toDateTimeString(),
                ],
                'meta' => [
                    'generated_at' => now()->toDateTimeString(),
                    'user_id' => $user->id,
                    'account_id' => $user->account?->id,
                    'contract' => 'assistant.v1',
                ],
            ], 201);
        } catch (Throwable $exception) {
            return response()->json([
                'message' => 'Assistant log store failed',
            ], 500);
        }
    }

    private function resolveSetting(User $user, Request $request): ?Setting
    {
        $setting = $request->attributes->get('assistant_setting');

        if ($setting instanceof Setting) {
            return $setting;
        }

        return Setting::query()
            ->where('user_id', $user->id)
            ->first();
    }
}
