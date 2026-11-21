<?php

namespace App\Http\Middleware\Api;

use App\Models\Integrations\YClients\MarketplaceInstallation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveYClientsWebhookOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = filter_var($request->input('company_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $applicationId = (int) config('services.yclients.marketplace_application_id');

        if (!$companyId || $applicationId < 1) {
            return $next($request);
        }

        // Only explicitly transferred installations may retain a previous webhook address.
        $installation = MarketplaceInstallation::query()
            ->where('application_id', $applicationId)
            ->where('salon_id', $companyId)
            ->whereNotNull('webhook_user_id')
            ->first();

        if (!$installation) {
            return $next($request);
        }

        $source = $request->route('user');
        if (is_string($source)) {
            $source = User::query()->where('uuid', $source)->first();
        }

        if (!$source instanceof User
            || !in_array((int) $source->id, [(int) $installation->webhook_user_id, (int) $installation->user_id], true)
            || $installation->status !== MarketplaceInstallation::STATUS_ACTIVE) {
            return new Response('yclients webhook binding is not active', 403);
        }

        $owner = $installation->user;
        $setting = $owner?->yclientsSetting;
        if (!$owner || !$setting || !$setting->active
            || (int) $setting->id !== (int) $installation->setting_id) {
            return new Response('yclients webhook owner is not configured', 403);
        }

        $request->route()->setParameter('user', $owner);

        return $next($request);
    }
}
