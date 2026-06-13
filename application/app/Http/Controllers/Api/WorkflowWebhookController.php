<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\Workflows\WorkflowAmoCrmWebhookService;
use App\Services\Workflows\WorkflowGenericWebhookService;
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

    public function generic(
        Request $request,
        Workflow $workflow,
        string $signature,
        WorkflowGenericWebhookService $webhooks,
    ): JsonResponse {
        if (!$webhooks->signatureIsValid($workflow, $signature)) {
            return response()->json([
                'ok' => false,
                'message' => 'Invalid webhook signature.',
            ], 403);
        }

        if (!$webhooks->canReceive($workflow)) {
            return response()->json([
                'ok' => false,
                'message' => 'Workflow webhook trigger is not active.',
            ], 404);
        }

        $result = $webhooks->handleIncomingWebhook($workflow, $request);

        return response()->json([
            'ok' => true,
            'started' => true,
            'run_id' => $result['run_id'],
            'run_ulid' => $result['run_ulid'],
        ]);
    }
}
