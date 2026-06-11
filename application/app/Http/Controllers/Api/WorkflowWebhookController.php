<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Core\Account;
use App\Services\Workflows\WorkflowAmoCrmWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkflowWebhookController extends Controller
{
    public function amoCrm(
        Request $request,
        Account $account,
        string $signature,
        WorkflowAmoCrmWebhookService $webhooks,
    ): JsonResponse {
        if (!$webhooks->signatureIsValid($account, $signature)) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid webhook signature.',
            ], 403);
        }

        $result = $webhooks->handleIncomingWebhook($account, $request->all(), $request->headers->all());

        return response()->json([
            'ok' => true,
            'events' => $result['events'],
            'started' => $result['started'],
        ]);
    }
}
