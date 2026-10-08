<?php

namespace App\Http\Controllers\System;

use App\Filament\WorkflowBuilder\Resources\WorkflowResource;
use App\Http\Controllers\Controller;
use App\Services\Workflows\WorkflowInstallationStatus;
use Illuminate\Http\Request;

class WorkflowInstallationController extends Controller
{
    public function __invoke(Request $request, string $token, WorkflowInstallationStatus $statuses)
    {
        $status = $statuses->get($token);
        $state = $status['status'] ?? 'expired';
        $workflowsUrl = WorkflowResource::getUrl('index', panel: 'app');
        $headers = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];

        // Completion tokens report progress only. The panel still requires its
        // normal authentication; this callback never signs in or switches users.
        if ($state === 'completed') {
            if (! $request->user() || (int) $request->user()->id === (int) ($status['user_id'] ?? 0)) {
                return redirect($workflowsUrl)->withHeaders($headers);
            }

            $state = 'different_account';
        }

        if ($state === 'pending' && now()->timestamp - (int) ($status['started_at'] ?? 0) > 120) {
            $state = 'delayed';
        }

        return response()->view('integrations.workflows-installation', [
            'state' => $state,
            'workflowsUrl' => $workflowsUrl,
            'statusUrl' => route('workflows.installation.status', ['token' => $token]),
        ])->withHeaders($headers);
    }
}
