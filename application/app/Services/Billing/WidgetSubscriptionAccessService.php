<?php

namespace App\Services\Billing;

use App\Models\App;
use App\Models\Billing\SubscriptionPlan;
use App\Models\Billing\WidgetSubscription;
use App\Models\User;
use App\Services\Core\PlatformTechnicalMonitor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class WidgetSubscriptionAccessService
{
    public function canUse(User|int|null $user, string $widget): bool
    {
        $userId = $this->resolveUserId($user);

        if ($userId === null || $widget === '') {
            return false;
        }

        if ($this->manualSubscriptionsAvailable() && $this->hasManualSubscription($userId, $widget)) {
            if ($this->latestManualSubscription($userId, $widget)?->isCurrentlyUsable() === true) {
                return true;
            }

            $legacyApp = $this->legacyApp($userId, $widget);

            if ($legacyApp !== null && $this->legacyAppRecordIsActive($legacyApp)) {
                $this->syncLegacyAppToManualSubscription($legacyApp);

                return true;
            }

            return false;
        }

        return $this->legacyAppIsActive($userId, $widget);
    }

    public function statusFor(User|int|null $user, string $widget): array
    {
        $userId = $this->resolveUserId($user);

        if ($userId === null) {
            return [
                'active' => false,
                'source' => 'none',
                'label' => 'Пользователь не найден',
            ];
        }

        if ($this->manualSubscriptionsAvailable() && $this->hasManualSubscription($userId, $widget)) {
            $subscription = $this->latestManualSubscription($userId, $widget);

            if ($subscription?->isCurrentlyUsable() !== true) {
                $legacyApp = $this->legacyApp($userId, $widget);

                if ($legacyApp !== null && $this->legacyAppRecordIsActive($legacyApp)) {
                    $subscription = $this->syncLegacyAppToManualSubscription($legacyApp) ?: $subscription;
                }
            }

            return [
                'active' => $subscription?->isCurrentlyUsable() === true,
                'source' => 'manual',
                'label' => $subscription?->statusLabel() ?? 'Нет подписки',
                'ends_at' => $subscription?->ends_at?->toDateString(),
                'grace_until' => $subscription?->grace_until?->toDateString(),
                'subscription_id' => $subscription?->id,
            ];
        }

        $app = $this->legacyApp($userId, $widget);

        return [
            'active' => $app !== null && $this->legacyAppRecordIsActive($app),
            'source' => 'legacy_apps',
            'label' => $app?->getStatusLabel() ?? 'Виджет не найден',
            'ends_at' => $app?->expires_tariff_at,
            'app_id' => $app?->id,
        ];
    }

    public function blockedWidgetsFor(User|int|null $user): array
    {
        $userId = $this->resolveUserId($user);

        if ($userId === null) {
            return [];
        }

        return App::query()
            ->where('user_id', $userId)
            ->where('status', '!=', App::STATE_CREATED)
            ->whereIn('name', App::definitionNames())
            ->latest('id')
            ->get(['id', 'name', 'resource_name', 'expires_tariff_at', 'status'])
            ->unique('name')
            ->map(function (App $app) use ($userId): array {
                $status = $this->statusFor($userId, (string)$app->name);

                return [
                    'widget' => (string)$app->name,
                    'title' => App::getTitle((string)$app->name, $app->resource_name),
                    'active' => (bool)($status['active'] ?? false),
                    'status_label' => (string)($status['label'] ?? 'Недоступна'),
                    'ends_at' => $status['ends_at'] ?? $app->expires_tariff_at,
                    'grace_until' => $status['grace_until'] ?? null,
                    'source' => (string)($status['source'] ?? 'legacy_apps'),
                    'subscription_id' => $status['subscription_id'] ?? null,
                    'app_id' => $status['app_id'] ?? $app->id,
                ];
            })
            ->reject(fn(array $widget): bool => (bool)$widget['active'])
            ->values()
            ->all();
    }

    public function ensureTrialForWidget(User|int|null $user, string $widget, int $days = 7): ?WidgetSubscription
    {
        $userId = $this->resolveUserId($user);
        $widget = trim($widget);
        $days = max(1, $days);

        if ($userId === null || $widget === '') {
            return null;
        }

        $endsAt = now()->addDays($days)->toDateString();

        if (!$this->manualSubscriptionsAvailable()) {
            $app = $this->legacyApp($userId, $widget);

            if ($app && (int)$app->status === App::STATE_CREATED) {
                $app->status = App::STATE_ACTIVE;
                $app->expires_tariff_at = $endsAt;
                $app->installed_at = $app->installed_at ?: now();
                $app->save();
            }

            return null;
        }

        return DB::transaction(function () use ($userId, $widget, $endsAt): ?WidgetSubscription {
            $existing = $this->latestManualSubscription($userId, $widget);

            if ($existing instanceof WidgetSubscription) {
                if ($existing->isCurrentlyUsable()) {
                    $this->syncSubscriptionToLegacyApp($existing);
                }

                return $existing;
            }

            $app = $this->legacyApp($userId, $widget);
            $plan = SubscriptionPlan::query()
                ->where('widget', $widget)
                ->active()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            $subscription = WidgetSubscription::query()->create([
                'user_id' => $userId,
                'app_id' => $app?->id,
                'subscription_plan_id' => $plan?->id,
                'widget' => $widget,
                'status' => WidgetSubscription::STATUS_TRIAL,
                'starts_at' => now()->toDateString(),
                'ends_at' => $endsAt,
                'notes' => 'Автоматический тестовый период при подключении amoCRM.',
            ]);

            $this->syncSubscriptionToLegacyApp($subscription);

            return $subscription;
        });
    }

    public function syncSubscriptionToLegacyApp(WidgetSubscription $subscription): void
    {
        $app = $subscription->app ?: $this->legacyApp((int)$subscription->user_id, (string)$subscription->widget);

        if (!$app) {
            return;
        }

        $isActive = $subscription->isCurrentlyUsable();
        $targetStatus = $isActive ? App::STATE_ACTIVE : App::STATE_EXPIRES;

        $app->status = $targetStatus;
        $app->expires_tariff_at = $subscription->ends_at?->toDateString();
        $app->installed_at = $app->installed_at ?: now();
        $app->save();

        $this->syncSettingActive($app, $isActive);
    }

    public function syncLegacyAppToManualSubscription(App $app): ?WidgetSubscription
    {
        if (!$this->manualSubscriptionsAvailable() || blank($app->name) || blank($app->user_id)) {
            return null;
        }

        $subscription = $this->latestManualSubscription((int)$app->user_id, (string)$app->name);

        if (!$subscription instanceof WidgetSubscription) {
            return null;
        }

        $isActive = $this->legacyAppRecordIsActive($app);
        $expiresAt = $this->parseLegacyExpiresAt($app);

        $subscription->app_id = $app->id;
        $subscription->ends_at = $expiresAt?->toDateString();

        if ($isActive) {
            $subscription->status = WidgetSubscription::STATUS_ACTIVE;
            $subscription->blocked_at = null;
            $subscription->starts_at = $subscription->starts_at ?: now()->toDateString();
        } else {
            $subscription->status = (int)$app->status === App::STATE_INACTIVE
                ? WidgetSubscription::STATUS_BLOCKED
                : WidgetSubscription::STATUS_EXPIRED;
            $subscription->blocked_at = $subscription->blocked_at ?: now();
        }

        $subscription->save();

        return $subscription->refresh();
    }

    public function expireOverdueSubscriptions(bool $dryRun = false): array
    {
        if (!$this->manualSubscriptionsAvailable()) {
            return ['processed' => 0, 'expired' => 0, 'errors' => 0];
        }

        $today = now()->startOfDay()->toDateString();
        $stats = ['processed' => 0, 'expired' => 0, 'errors' => 0];

        WidgetSubscription::query()
            ->whereNull('blocked_at')
            ->whereIn('status', WidgetSubscription::ACTIVE_STATUSES)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $today)
            ->where(function (Builder $query) use ($today): void {
                $query->whereNull('grace_until')->orWhere('grace_until', '<', $today);
            })
            ->orderBy('id')
            ->chunkById(200, function ($subscriptions) use (&$stats, $dryRun): void {
                foreach ($subscriptions as $subscription) {
                    $stats['processed']++;

                    try {
                        if (!$dryRun) {
                            $subscription->status = WidgetSubscription::STATUS_EXPIRED;
                            $subscription->blocked_at = now();
                            $subscription->save();

                            $this->syncSubscriptionToLegacyApp($subscription);
                        }

                        $stats['expired']++;
                    } catch (Throwable $e) {
                        $stats['errors']++;
                        Log::warning('subscription expire failed', [
                            'subscription_id' => $subscription->id,
                            'user_id' => $subscription->user_id,
                            'widget' => $subscription->widget,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $stats;
    }

    public function notifyExpiringSubscriptions(int $days = 7, bool $dryRun = false): array
    {
        if (!$this->manualSubscriptionsAvailable()) {
            return ['processed' => 0, 'notified' => 0, 'errors' => 0];
        }

        $targetDate = now()->addDays(max(0, $days))->toDateString();
        $stats = ['processed' => 0, 'notified' => 0, 'errors' => 0];

        WidgetSubscription::query()
            ->with(['user', 'plan'])
            ->whereNull('blocked_at')
            ->whereIn('status', WidgetSubscription::ACTIVE_STATUSES)
            ->whereDate('ends_at', $targetDate)
            ->where(function (Builder $query) use ($days): void {
                $key = 'expiring_' . max(0, $days) . '_days';

                $query
                    ->whereNull('notification_log')
                    ->orWhereJsonDoesntContain('notification_log->sent', $key);
            })
            ->orderBy('id')
            ->chunkById(200, function ($subscriptions) use (&$stats, $dryRun, $days): void {
                foreach ($subscriptions as $subscription) {
                    $stats['processed']++;

                    try {
                        if (!$dryRun) {
                            $this->sendExpiringAlert($subscription, $days);
                            $this->markNotificationSent($subscription, 'expiring_' . max(0, $days) . '_days');
                        }

                        $stats['notified']++;
                    } catch (Throwable $e) {
                        $stats['errors']++;
                        Log::warning('subscription expiring notification failed', [
                            'subscription_id' => $subscription->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $stats;
    }

    private function manualSubscriptionsAvailable(): bool
    {
        return Schema::hasTable('widget_subscriptions');
    }

    private function hasManualSubscription(int $userId, string $widget): bool
    {
        return WidgetSubscription::query()
            ->where('user_id', $userId)
            ->forWidget($widget)
            ->exists();
    }

    private function latestManualSubscription(int $userId, string $widget): ?WidgetSubscription
    {
        return WidgetSubscription::query()
            ->where('user_id', $userId)
            ->forWidget($widget)
            ->latest('id')
            ->first();
    }

    private function legacyAppIsActive(int $userId, string $widget): bool
    {
        $app = $this->legacyApp($userId, $widget);

        return $app !== null && $this->legacyAppRecordIsActive($app);
    }

    private function legacyApp(int $userId, string $widget): ?App
    {
        return App::query()
            ->where('user_id', $userId)
            ->where('name', $widget)
            ->latest('id')
            ->first();
    }

    private function legacyAppRecordIsActive(App $app): bool
    {
        if ((int)$app->status !== App::STATE_ACTIVE) {
            return false;
        }

        if (blank($app->expires_tariff_at)) {
            return true;
        }

        $expiresAt = $this->parseLegacyExpiresAt($app);

        if ($expiresAt === null) {
            return false;
        }

        return !$expiresAt->startOfDay()->lt(now()->startOfDay());
    }

    private function parseLegacyExpiresAt(App $app): ?Carbon
    {
        if (blank($app->expires_tariff_at)) {
            return null;
        }

        try {
            return Carbon::parse($app->expires_tariff_at);
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveUserId(User|int|null $user): ?int
    {
        if ($user instanceof User) {
            return (int)$user->id;
        }

        if (is_int($user) && $user > 0) {
            return $user;
        }

        return null;
    }

    private function syncSettingActive(App $app, bool $isActive): void
    {
        try {
            $setting = $app->getSettingModel();
        } catch (Throwable) {
            return;
        }

        if (!$setting || !Schema::hasColumn($setting->getTable(), 'active')) {
            return;
        }

        if ((bool)$setting->active === $isActive) {
            return;
        }

        $setting->active = $isActive;
        $setting->save();
    }

    private function sendExpiringAlert(WidgetSubscription $subscription, int $days): void
    {
        app(PlatformTechnicalMonitor::class)->subscriptionExpiring($subscription, $days);
    }

    private function markNotificationSent(WidgetSubscription $subscription, string $key): void
    {
        $log = $subscription->notification_log ?: [];
        $sent = $log['sent'] ?? [];

        if (!is_array($sent)) {
            $sent = [];
        }

        if (!in_array($key, $sent, true)) {
            $sent[] = $key;
        }

        $log['sent'] = $sent;
        $log[$key . '_at'] = now()->toIso8601String();

        $subscription->notification_log = $log;
        $subscription->last_notified_at = now();
        $subscription->save();
    }

}
