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
        if ($subscription->status === WidgetSubscription::STATUS_EXPIRED) {
            if ($subscription->wasChanged('status')) {
                app(PlatformTechnicalMonitor::class)->subscriptionExpired($subscription);
            }

            return;
        }

        // Manual widget deactivation has its own notification with the operator.
        // A block or cancellation is not an expiry, nor a renewal.
        if ($subscription->blocked_at !== null
            || !in_array($subscription->status, WidgetSubscription::ACTIVE_STATUSES, true)) {
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

    private function looksRenewed(WidgetSubscription $subscription): bool
    {
        $becameActive = $subscription->wasChanged('status')
            && in_array($subscription->status, WidgetSubscription::ACTIVE_STATUSES, true);

        $endDateChanged = $subscription->wasChanged('ends_at');
        $unblocked = $subscription->wasChanged('blocked_at') && $subscription->blocked_at === null;

        return $becameActive || $endDateChanged || $unblocked;
    }
}
