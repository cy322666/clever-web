<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Services\Integrations\IntegrationProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class IntegrationOpenController extends Controller
{
    public function __invoke(App $app, IntegrationProvisioningService $provisioning): RedirectResponse
    {
        $user = Auth::user();

        if (!$user || (!$user->is_root && $app->user_id !== $user->id)) {
            abort(403);
        }

        $definition = config("integrations.definitions.{$app->name}");

        if (!is_array($definition)) {
            abort(404, 'Integration is not supported.');
        }

        return $this->open($app, $definition, $provisioning);
    }

    public function connect(string $widget, IntegrationProvisioningService $provisioning): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $definition = App::definitionsForCrmProvider(
            $user->crm_provider,
            app()->environment('production') ? true : null,
        )->get($widget);
        abort_unless(is_array($definition), 404, 'Integration is not supported.');

        if (!($definition['requires_setting'] ?? true)) {
            return redirect()->to($definition['resource']::getUrl((string)($definition['open_page'] ?? 'index')));
        }

        return DB::transaction(function () use ($user, $widget, $definition, $provisioning): RedirectResponse {
            $app = $provisioning->provisionAppForUser($user, $widget);
            abort_unless($app, 404, 'Integration is not supported.');

            return $this->open(App::query()->lockForUpdate()->findOrFail($app->id), $definition, $provisioning);
        });
    }

    private function open(App $app, array $definition, IntegrationProvisioningService $provisioning): RedirectResponse
    {
        $resourceClass = (string)($definition['resource'] ?? $app->resource_name);
        if (!App::classAvailable($resourceClass)) {
            abort(404, 'Integration resource is not available.');
        }

        if ((bool)($definition['requires_setting'] ?? true) === false) {
            return redirect()->to($resourceClass::getUrl((string)($definition['open_page'] ?? 'index')));
        }

        $app->resource_name = $resourceClass;
        if ($app->isDirty('resource_name')) {
            $app->save();
        }
        $app = $provisioning->ensureSettingForApp($app);

        if (!$app->setting_id || !App::classAvailable((string)$app->resource_name)) {
            abort(404, 'Integration setting is not available.');
        }

        /** @var class-string $resourceClass */
        $resourceClass = $app->resource_name;

        return redirect()->to($resourceClass::getUrl('edit', ['record' => $app->setting_id]));
    }
}
