<?php

namespace App\Observers;

use App\Jobs\Sqns\DisconnectWebhook;
use App\Models\Billing\WidgetSubscription;
use App\Models\Integrations\Sqns\Setting as SqnsSetting;
use App\Services\Core\PlatformTechnicalMonitor;
use Illuminate\Support\Facades\Schema;

class WidgetSubscriptionObserver
{
    public bool $afterCommit = true;

    public function created(WidgetSubscription $subscription): void
    {
        app(PlatformTechnicalMonitor::class)->subscriptionCreated($subscription);
        $this->disconnectSqnsWhenAccessStops($subscription);
    }

    public function updated(WidgetSubscription $subscription): void
    {
        $this->disconnectSqnsWhenAccessStops($subscription);

        if ($subscription->status === WidgetSubscription::STATUS_EXPIRED) {
            if ($subscription->wasChanged('status')) {
                app(PlatformTechnicalMonitor::class)->subscriptionExpired($subscription);
            }

            return;
        }

        // Manual widget deactivation has its own notification with the operator.
        // A block or cancellation is not an expiry, nor a renewal.
        if ($subscription->blocked_at !== null
            || ! in_array($subscription->status, WidgetSubscription::ACTIVE_STATUSES, true)) {
            return;
        }

        if ($this->looksRenewed($subscription)) {
            $beforeEndsAt = $subscription->getOriginal('ends_at');

            app(PlatformTechnicalMonitor::class)->subscriptionRenewed(
                $subscription,
                $beforeEndsAt ? (string) $beforeEndsAt : null,
            );
        }
    }

    private function disconnectSqnsWhenAccessStops(WidgetSubscription $subscription): void
    {
        if ((string) $subscription->widget !== 'sqns'
            || ! $subscription->wasChanged(['status', 'blocked_at', 'ends_at', 'grace_until'])
            || $subscription->isCurrentlyUsable()
            || ! Schema::hasTable('sqns_settings')) {
            return;
        }

        SqnsSetting::query()
            ->where('user_id', $subscription->user_id)
            ->whereNotNull('token')
            ->pluck('id')
            ->each(fn (int $settingId) => DisconnectWebhook::dispatch($settingId, 'subscription_inactive'));
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
