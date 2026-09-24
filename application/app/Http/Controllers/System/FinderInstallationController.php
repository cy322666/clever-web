<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\Integrations\Finder\Setting;
use App\Services\Finder\InstallationStatus;
use Illuminate\Http\Request;

class FinderInstallationController extends Controller
{
    public function __invoke(Request $request, string $token, InstallationStatus $statuses)
    {
        $status = $statuses->get($token);
        $state = $status['status'] ?? 'expired';

        // This page only reports progress. Existing platform authentication and
        // tenant checks still control access; the status token never signs in a user.
        if ($state === 'completed' && $request->user()
            && (int) $request->user()->id === (int) $status['user_id']) {
            $setting = Setting::where('user_id', $request->user()->id)->first();
            if ($setting) {
                return redirect()->route('filament.app.resources.integrations.finder.edit', ['record' => $setting->id])
                    ->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
            }
        }

        if ($state === 'pending' && time() - (int) ($status['started_at'] ?? 0) > 120) {
            $state = 'delayed';
        }

        return response()->view('integrations.finder-installation', [
            'state' => $state, 'domain' => $status['domain'] ?? null,
            'loginUrl' => route('filament.app.auth.login'),
            'dashboardUrl' => route('filament.app.pages.dashboard'),
        ])->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
