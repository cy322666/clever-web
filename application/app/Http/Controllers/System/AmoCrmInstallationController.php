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
            $ownerId = (int) ($status['user_id'] ?? 0);
            $target = app(IntegrationReturnTarget::class)->forUser($ownerId, $widget);
            $browserUser = $request->user();
            if ($target === null) {
                $state = 'unavailable';
            } elseif ($widget !== 'workflows' || (int) $browserUser?->getAuthIdentifier() === $ownerId) {
                return redirect()->to($target)->withHeaders($headers);
            } elseif ($browserUser === null) {
                return redirect()->setIntendedUrl(route('amocrm.installation.status', ['token' => $token]))
                    ->route('filament.app.auth.login')->withHeaders($headers);
            } else {
                $state = 'account_conflict';
            }
        }

        if ($state === 'pending' && now()->timestamp - (int) ($status['started_at'] ?? 0) > 120) {
            $state = 'delayed';
        }

        return response()->view('integrations.amocrm-installation', [
            'state' => $state,
            'widgetLabel' => config('widget_lifecycle.labels.'.$widget, ''),
            'dashboardUrl' => route('filament.app.pages.dashboard'),
            'refreshUrl' => route('amocrm.installation.status', ['token' => $token]),
        ], $state === 'account_conflict' ? 409 : 200, $headers);
    }
}
