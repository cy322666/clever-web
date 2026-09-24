<?php

namespace App\Services\Finder;

use App\Models\Integrations\Finder\Setting;
use App\Services\Billing\WidgetSubscriptionAccessService;

class FinderAccess
{
    public function canRun(Setting $setting): bool
    {
        return $setting->isMonitoringEnabled()
            && $setting->user?->active
            && $setting->account?->active
            && (int) $setting->account->user_id === (int) $setting->user_id
            && app(WidgetSubscriptionAccessService::class)->canUse((int) $setting->user_id, 'finder');
    }
}
