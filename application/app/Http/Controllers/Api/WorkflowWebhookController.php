<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Core\Account;
use App\Models\Workflows\Workflow;
use App\Services\Billing\WidgetSubscriptionAccessService;
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

        if (!app(WidgetSubscriptionAccessService::class)->canUse((int)$account->user_id, 'workflows')) {
            return response()->json([
                'ok' => false,
                'message' => 'Workflow widget access is not active.',
            ], 403);
        }

        $result = $webhooks->handleIncomingWebhook($account, $request->all(), $request->headers->all());

        return response()->json([
            'ok' => true,
            'events' => $result['events'],
            'started' => $result['started'],
            'queued' => $result['started'],
        ], 202);
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

        if (!$webhooks->canCapture($workflow)) {
            return response()->json([
                'ok' => false,
                'message' => 'Workflow webhook trigger is not active.',
            ], 404);
        }

        if (!app(WidgetSubscriptionAccessService::class)->canUse((int)$workflow->user_id, 'workflows')) {
            $preview = $webhooks->captureIncomingWebhook($workflow, $request);

            return response()->json([
                'ok' => true,
                'started' => false,
                'queued' => false,
                'preview_id' => $preview['id'],
                'message' => 'Webhook received. Workflow widget access is not active, so workflow was not started.',
            ], 202);
        }

        if (!$webhooks->canReceive($workflow)) {
            $preview = $webhooks->captureIncomingWebhook($workflow, $request);

            return response()->json([
                'ok' => true,
                'started' => false,
                'queued' => false,
                'preview_id' => $preview['id'],
                'message' => 'Webhook received. Workflow is inactive, so it was not started.',
            ], 202);
        }

        $result = $webhooks->handleIncomingWebhook($workflow, $request);

        return response()->json([
            'ok' => true,
            'started' => true,
            'queued' => true,
            'run_id' => $result['run_id'],
            'run_ulid' => $result['run_ulid'],
            'preview_id' => $result['preview_id'],
        ], 202);
    }
}
