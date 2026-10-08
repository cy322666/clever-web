<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\Integrations\AmoCrmInstallationStatus;
use App\Services\Integrations\IntegrationReturnTarget;
use Illuminate\Http\Request;

class AmoCrmInstallationController extends Controller
{
    public function __invoke(Request $request, string $token, AmoCrmInstallationStatus $statuses)
    {
        $status = $statuses->get($token);
        $state = $status['status'] ?? 'expired';
        $widget = (string) ($status['widget'] ?? '');
        $headers = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow'];

        if ($state === 'completed') {
            $target = app(IntegrationReturnTarget::class)->forUser((int) ($status['user_id'] ?? 0), $widget);
            if ($target !== null) {
                return redirect()->to($target)->withHeaders($headers);
            }
            $state = 'unavailable';
        }

        if ($state === 'pending' && now()->timestamp - (int) ($status['started_at'] ?? 0) > 120) {
            $state = 'delayed';
        }

        return response()->view('integrations.amocrm-installation', [
            'state' => $state,
            'widgetLabel' => config('widget_lifecycle.labels.'.$widget, ''),
            'dashboardUrl' => route('filament.app.pages.dashboard'),
            'refreshUrl' => route('amocrm.installation.status', ['token' => $token]),
        ])->withHeaders($headers);
    }
}
