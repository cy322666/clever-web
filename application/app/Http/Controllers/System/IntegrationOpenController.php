<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Services\Integrations\IntegrationProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class IntegrationOpenController extends Controller
{
    public function __invoke(App $app, IntegrationProvisioningService $provisioning): RedirectResponse
    {
        $user = Auth::user();

        if (!$user || (!$user->is_root && $app->user_id !== $user->id)) {
            abort(403);
        }

        if (!array_key_exists($app->name, config('integrations.definitions', []))) {
            abort(404, 'Integration is not supported.');
        }

        $app = $provisioning->ensureSettingForApp($app);

        if (!$app->setting_id || !class_exists((string)$app->resource_name)) {
            abort(404, 'Integration setting is not available.');
        }

        /** @var class-string $resourceClass */
        $resourceClass = $app->resource_name;

        return redirect()->to($resourceClass::getUrl('edit', ['record' => $app->setting_id]));
    }
}
