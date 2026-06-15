<?php

namespace App\Observers;

use App\Models\Billing\WidgetSubscription;
use App\Services\Core\PlatformTechnicalMonitor;

class WidgetSubscriptionObserver
{
    public bool $afterCommit = true;

    public function created(WidgetSubscription $subscription): void
    {
        app(PlatformTechnicalMonitor::class)->subscriptionCreated($subscription);
    }

    public function updated(WidgetSubscription $subscription): void
    {
        if ($this->becameInactive($subscription)) {
            app(PlatformTechnicalMonitor::class)->subscriptionExpired($subscription);

            return;
        }

        if ($this->looksRenewed($subscription)) {
            $beforeEndsAt = $subscription->getOriginal('ends_at');

            app(PlatformTechnicalMonitor::class)->subscriptionRenewed(
                $subscription,
                $beforeEndsAt ? (string)$beforeEndsAt : null,
            );
        }
    }

    private function becameInactive(WidgetSubscription $subscription): bool
    {
        if (!$subscription->wasChanged(['status', 'blocked_at'])) {
            return false;
        }

        return in_array($subscription->status, [
            WidgetSubscription::STATUS_EXPIRED,
            WidgetSubscription::STATUS_BLOCKED,
            WidgetSubscription::STATUS_CANCELLED,
        ], true) || $subscription->blocked_at !== null;
    }

    private function looksRenewed(WidgetSubscription $subscription): bool
    {
        $becameActive = $subscription->wasChanged('status')
            && in_array($subscription->status, WidgetSubscription::ACTIVE_STATUSES, true);

        $endDateChanged = $subscription->wasChanged('ends_at');
        $unblocked = $subscription->wasChanged('blocked_at') && $subscription->blocked_at === null;

        return $becameActive || $endDateChanged || $unblocked;
    }
}
